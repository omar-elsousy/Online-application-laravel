<?php

namespace App\Services\Cart\Impl;

use App\Services\Cart\CartService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Support\WarehouseIds;
use App\Support\ActivePosCode;

class CartServiceImpl implements CartService
{
    public function addToCart(Request $request, $product_id)
    {
        $request->validate([
            'quantity' => 'required|numeric|gt:0',
        ]);

        $user_id = $request->user()->id;
        $posCode = ActivePosCode::forUser($request->user());
        if (!$posCode) {
            return response()->json(['message' => 'تعذر تحديد العميل النشط. سجّل الدخول مرة أخرى.'], 409);
        }

        $product = DB::connection('oracle_lmidc')
            ->table('to_sfa_products_android')
            ->where('product_id', $product_id)
            ->first();

        if (!$product) {
            return response()->json(['message' => 'المنتج غير موجود'], 404);
        }

        $price = DB::connection('oracle_lmidc')
            ->table('product_price_list')
            ->where('product_id', $product_id)
            ->where('line_price_id', 1)
            ->first();

        if (!$price || $price->pricelist_carton === null || $price->pricelist_carton <= 0) {
            return response()->json([
                'message' => 'لا يمكن إضافة هذا المنتج لأن سعره غير متاح حالياً',
            ], 422);
        }
        // تحقق من الستوك
        $user = DB::connection('oracle_sales')
            ->table('online_app_users')
            ->where('id', $user_id)
            ->first();

        $warehouseIds = WarehouseIds::fromUser($user);
        $stockRows = DB::connection('oracle_sales')
            ->table('online_app_stock')
            ->where('product_id', $product_id)
            ->whereIn('warehouse_id', $warehouseIds)
            ->get();

        if (!WarehouseIds::isAvailableInAnyWarehouse($stockRows, $warehouseIds)) {
            return response()->json([
                'message' => 'المنتج ده out of stock',
            ], 400);
        }

        $cartItem = DB::connection('oracle_sales')
            ->table('cart_online_app')
            ->where('user_id', $user_id)
            ->where('pos_code', $posCode)
            ->where('product_id', $product_id)
            ->first();

        if ($cartItem) {
            DB::connection('oracle_sales')
                ->table('cart_online_app')
                ->where('user_id', $user_id)
                ->where('pos_code', $posCode)
                ->where('product_id', $product_id)
                ->update([
                    'quantity' => $cartItem->quantity + $request->quantity,
                ]);
        } else {
            DB::connection('oracle_sales')
                ->table('cart_online_app')
                ->insert([
                    'user_id'    => $user_id,
                    'pos_code'   => $posCode,
                    'product_id' => $product_id,
                    'quantity'   => $request->quantity,
                    'created_at' => now(),
                ]);
        }

        return response()->json([
            'message' => 'تم الإضافة للكارت بنجاح',
        ], 200);
    }

    public function getCart(Request $request)
    {
        $user_id = $request->user()->id;
        $posCode = ActivePosCode::forUser($request->user());
        if (!$posCode) {
            return response()->json(['message' => 'تعذر تحديد العميل النشط. سجّل الدخول مرة أخرى.'], 409);
        }

        $cartItems = DB::connection('oracle_sales')
            ->table('cart_online_app')
            ->where('user_id', $user_id)
            ->where('pos_code', $posCode)
            ->get();

        if ($cartItems->isEmpty()) {
            return response()->json([
                'data' => [
                    'items'         => [],
                    'number_of_products' => 0,
                    'cart_total'    => 0,
                ],
            ], 200);
        }

        $productIds = $cartItems->pluck('product_id')->map(fn ($id) => (string) $id)->unique()->values();
        $products = DB::connection('oracle_lmidc')
            ->table('to_sfa_products_android')
            ->whereIn('product_id', $productIds)
            ->get(['product_id', 'product_ename'])
            ->keyBy(fn ($product) => (string) $product->product_id);
        $prices = DB::connection('oracle_lmidc')
            ->table('product_price_list')
            ->whereIn('product_id', $productIds)
            ->where('line_price_id', 1)
            ->get(['product_id', 'pricelist_carton', 'tax_percentage', 'product_tax'])
            ->keyBy(fn ($price) => (string) $price->product_id);
        $images = DB::connection('oracle_sales')
            ->table('online_app_images')
            ->where('type', 'product')
            ->whereIn('ref_id', $productIds)
            ->get(['ref_id', 'image_path'])
            ->keyBy(fn ($image) => (string) $image->ref_id);

        $invalidCartIds = [];
        $items = $cartItems->map(function ($cartItem) use ($products, $prices, $images, &$invalidCartIds) {
            $productId = (string) $cartItem->product_id;
            $product = $products->get($productId);
            $price = $prices->get($productId);

            // تجاهل أي عنصر قديم لم يعد له منتج أو سعر صالح.
            if (!$product || !$price || $price->pricelist_carton === null || $price->pricelist_carton <= 0) {
                $invalidCartIds[] = $cartItem->id;
                return null;
            }
            $image = $images->get($productId)?->image_path;

            // احسب السعر
            $unit_price          = round($price->pricelist_carton, 1);
            $unit_tax            = round(($price->pricelist_carton * (($price->tax_percentage ?? 0) / 100)) + ($price->product_tax ?? 0), 1);
            $unit_price_after_tax = round($unit_price + $unit_tax, 1);
            $total_price         = round($unit_price_after_tax * $cartItem->quantity, 1);

            return [
                'image'                => $image ? asset('storage/' . $image) : null,
                'product_id'           => $cartItem->product_id,
                'name' => $product->product_ename,
                'quantity'             => $cartItem->quantity,
                'unit_price'           => $unit_price,
                'unit_tax'             => $unit_tax,
                'unit_price_after_tax' => $unit_price_after_tax,
                'total_price'          => $total_price,
            ];
        });

        if ($invalidCartIds) {
            DB::connection('oracle_sales')
                ->table('cart_online_app')
                ->where('user_id', $user_id)
                ->where('pos_code', $posCode)
                ->whereIn('id', $invalidCartIds)
                ->delete();
        }

        $items = $items->filter()->values();

        $cart_total = round($items->sum('total_price'), 1);

        return response()->json([
            'data' => [
                'items'         => $items,
                'number_of_products' => $items->count(),
                'final_price'    => $cart_total,
            ],
        ], 200);
    }

    public function removeFromCart(Request $request, $product_id)
    {
        $user_id = $request->user()->id;
        $posCode = ActivePosCode::forUser($request->user());
        if (!$posCode) {
            return response()->json(['message' => 'تعذر تحديد العميل النشط. سجّل الدخول مرة أخرى.'], 409);
        }

        $cartItem = DB::connection('oracle_sales')
            ->table('cart_online_app')
            ->where('user_id', $user_id)
            ->where('pos_code', $posCode)
            ->where('product_id', $product_id)
            ->first();

        if (!$cartItem) {
            return response()->json([
                'message' => 'المنتج مش موجود في الكارت',
            ], 404);
        }

        DB::connection('oracle_sales')
            ->table('cart_online_app')
            ->where('user_id', $user_id)
            ->where('pos_code', $posCode)
            ->where('product_id', $product_id)
            ->delete();

        return response()->json([
            'message' => 'تم مسح المنتج من الكارت بنجاح',
        ], 200);
    }
}
