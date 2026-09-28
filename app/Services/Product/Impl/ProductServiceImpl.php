<?php

namespace App\Services\Product\Impl;

use App\Services\Product\ProductService;
use Illuminate\Support\Facades\DB;
use App\Services\Incentive\IncentiveService;
use App\Support\WarehouseIds;

class ProductServiceImpl implements ProductService
{
    public function getProductsByFamily($family_id)
    {
        $hiddenProducts = DB::connection('oracle_sales')
            ->table('online_app_hidden_products')
            ->pluck('product_id')
            ->toArray();

        // جيب الـ warehouse_id بتاع اليوزر
        $user = DB::connection('oracle_sales')
            ->table('online_app_users')
            ->where('id', auth()->id())
            ->first();

        $warehouseIds = WarehouseIds::fromUser($user);

        $products = DB::connection('oracle_lmidc')
            ->table('to_sfa_products_android as p')
            ->leftJoin('product_price_list as pr', function ($join) {
                $join->on('p.product_id', '=', 'pr.product_id')
                    ->where('pr.line_price_id', '=', 1);
            })
            ->where('p.family_id', $family_id)
            ->where('pr.pricelist_carton', '>', 0)
            ->whereNotIn('p.product_id', $hiddenProducts)
            ->select(
                'p.product_id',
                'p.product_ename',
                'pr.pricelist_carton',
                'pr.tax_percentage',
                'pr.product_tax'
            )
            ->orderBy('p.product_id')
            ->get();

        $locale = request()->getPreferredLanguage(['ar', 'en']) ?? 'en';
        $productIds = $products->pluck('product_id')->all();
        $teasers = app(IncentiveService::class)->teasersForProducts(auth()->user(), $productIds, $locale);
        $images = $productIds
            ? DB::connection('oracle_sales')->table('online_app_images')
                ->where('type', 'product')->whereIn('ref_id', $productIds)
                ->pluck('image_path', 'ref_id')
            : collect();
        $stocks = $productIds && $warehouseIds
            ? DB::connection('oracle_sales')->table('online_app_stock')
                ->whereIn('product_id', $productIds)->whereIn('warehouse_id', $warehouseIds)
                ->get()->groupBy('product_id')
            : collect();

        $products = $products->map(function ($product) use ($warehouseIds, $teasers, $images, $stocks) {
                $tax = ($product->pricelist_carton * ($product->tax_percentage / 100)) + $product->product_tax;
                $image = $images[$product->product_id] ?? null;
                $stockRows = $stocks->get($product->product_id, collect());

                return [
                    'image'      => $image ? asset('storage/' . $image) : null,
                    'product_id' => $product->product_id,
                    'name'       => $product->product_ename,
                    'price'      => round($product->pricelist_carton + $tax, 1),
                    'incentive_teaser' => $teasers[$product->product_id] ?? null,
                    'status' => WarehouseIds::isAvailableInAnyWarehouse($stockRows, $warehouseIds) ? 'in stock' : 'out of stock',
                ];
            });

        return response()->json([
            'data' => $products,
        ], 200);
    }

    public function getProductDetails($product_id)
    {
        $product = DB::connection('oracle_lmidc')
            ->table('to_sfa_products_android')
            ->where('product_id', $product_id)
            ->first();

        if (!$product) {
            return response()->json([
                'message' => 'المنتج مش موجود',
            ], 404);
        }

        $category = DB::connection('oracle_lmidc')
            ->table('prod_family')
            ->where('family_id', $product->family_id)
            ->first();

        $price = DB::connection('oracle_lmidc')
            ->table('product_price_list')
            ->where('product_id', $product->product_id)
            ->where('line_price_id', 1)
            ->first();

        if (!$price || $price->pricelist_carton === null || $price->pricelist_carton <= 0) {
            return response()->json([
                'message' => 'سعر هذا المنتج غير متاح حالياً',
            ], 422);
        }

        $tax = round(($price->pricelist_carton * ($price->tax_percentage / 100)) + $price->product_tax, 1);
        $locale = request()->getPreferredLanguage(['ar', 'en']) ?? 'en';
        $teaser = app(IncentiveService::class)->teasersForProducts(auth()->user(), [$product->product_id], $locale, true);

        $image = DB::connection('oracle_sales')
            ->table('online_app_images')
            ->where('type', 'product')
            ->where('ref_id', $product->product_id)
            ->value('image_path');

        // جيب الـ warehouse_id بتاع اليوزر
        $user = DB::connection('oracle_sales')
            ->table('online_app_users')
            ->where('id', auth()->id())
            ->first();

        $warehouseIds = WarehouseIds::fromUser($user);
        $stockRows = DB::connection('oracle_sales')
            ->table('online_app_stock')
            ->where('product_id', $product_id)
            ->whereIn('warehouse_id', $warehouseIds)
            ->get();

        return response()->json([
            'data' => [
                'image'    => $image ? asset('storage/' . $image) : null,
                'name'     => $product->product_ename,
                'code'     => $product->product_id,
                'category' => $category->name,
                'price'    => $price->pricelist_carton,
                'incentive_teaser' => $teaser[$product->product_id] ?? null,
                'tax'      => $tax,
                'status' => WarehouseIds::isAvailableInAnyWarehouse($stockRows, $warehouseIds) ? 'in stock' : 'out of stock',
            ],
        ], 200);
    }

    public function getLatestOffers()
    {
        $user = DB::connection('oracle_sales')
            ->table('online_app_users')
            ->where('id', auth()->id())
            ->first();

        $warehouseIds = $user ? WarehouseIds::fromUser($user) : [];

        // 2. جيب كل العروض المتاحة
        $offers = DB::connection('oracle_sales')
            ->table('online_app_offers_products')
            ->orderBy('product_id')
            ->get();

        if ($offers->isEmpty()) {
            return collect();
        }

        // 3. اجمع كل الـ product_ids في مصفوفة عشان تستعلم عنهم مرة واحدة
        $productIds = $offers->pluck('product_id')->unique()->toArray();

        // 4. جيب كل المنتجات دفعة واحدة (من الداتابيز التانية) وحولها لـ Key-Value Map للوصول السريع
        $products = DB::connection('oracle_lmidc')
            ->table('to_sfa_products_android')
            ->whereIn('product_id', $productIds)
            ->get()
            ->keyBy('product_id');

        // 5. جيب كل الصور الخاصة بالمنتجات دي دفعة واحدة
        $images = DB::connection('oracle_sales')
            ->table('online_app_images')
            ->where('type', 'product')
            ->whereIn('ref_id', $productIds)
            ->pluck('image_path', 'ref_id'); // هيرجع مصفوفة [product_id => image_path]

        // 6. جيب المخزون لكل المنتجات دي في مخزن المستخدم الحالي دفعة واحدة
        $stocks = collect();
        if ($warehouseIds) {
            $stocks = DB::connection('oracle_sales')
                ->table('online_app_stock')
                ->whereIn('product_id', $productIds)
                ->whereIn('warehouse_id', $warehouseIds)
                ->get()
                ->groupBy('product_id');
        }

        $prices = DB::connection('oracle_lmidc')
            ->table('product_price_list')
            ->whereIn('product_id', $productIds)
            ->where('line_price_id', 1)
            ->where('pricelist_carton', '>', 0)
            ->get()
            ->keyBy('product_id');

        $validProductIds = $products->keys()
            ->filter(fn ($productId) => $prices->has($productId))
            ->values()->all();
        $locale = request()->getPreferredLanguage(['ar', 'en']) ?? 'en';
        $teasers = app(IncentiveService::class)->teasersForProducts(
            auth()->user(), $validProductIds, $locale
        );

        // Build offer responses from the batched queries above (no per-offer SQL).
        return $offers->map(function ($offer) use ($products, $images, $stocks, $prices, $teasers, $warehouseIds) {
            // ابحث عن المنتج في الميموري بدل الداتابيز
            $product = $products->get($offer->product_id);

            if (!$product) {
                return null;
            }

            // جيب الصورة والمخزون من الميموري
            $imagePath = $images->get($offer->product_id);
            $stockRows = $stocks->get($offer->product_id, collect());

            $price = $prices->get($product->product_id);

            if (!$price || $price->pricelist_carton === null || $price->pricelist_carton <= 0) {
                return null;
            }

            $tax = round(($price->pricelist_carton * ($price->tax_percentage / 100)) + $price->product_tax, 1);
            return [
                'image'      => $imagePath ? asset('storage/' . $imagePath) : null,
                'product_id' => $product->product_id,
                'name'       => $product->product_ename,
                'price'      => round($price->pricelist_carton + $tax, 1),
                'incentive_teaser' => $teasers[$product->product_id] ?? null,
                'status'     => WarehouseIds::isAvailableInAnyWarehouse($stockRows, $warehouseIds) ? 'in stock' : 'out of stock',
            ];
        })->filter()->values();
    }
}
