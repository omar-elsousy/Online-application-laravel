<?php

namespace App\Services\Cart\Impl;

use App\Services\Cart\CartService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CartServiceImpl implements CartService
{
    public function addToCart(Request $request, $product_id)
    {
        $request->validate([
            'quantity' => 'required|numeric|min:1',
        ]);

        $user_id = $request->user()->id;

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

        $stock = DB::connection('oracle_sales')
            ->table('online_app_stock')
            ->where('product_id', $product_id)
            ->where('warehouse_id', $user->warehouse_id)
            ->first();

        if ($stock && !$stock->in_stock) {
            return response()->json([
                'message' => 'المنتج ده out of stock',
            ], 400);
        }

        $cartItem = DB::connection('oracle_sales')
            ->table('cart_online_app')
            ->where('user_id', $user_id)
            ->where('product_id', $product_id)
            ->first();

        if ($cartItem) {
            DB::connection('oracle_sales')
                ->table('cart_online_app')
                ->where('user_id', $user_id)
                ->where('product_id', $product_id)
                ->update([
                    'quantity' => $cartItem->quantity + $request->quantity,
                ]);
        } else {
            DB::connection('oracle_sales')
                ->table('cart_online_app')
                ->insert([
                    'user_id'    => $user_id,
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

        $cartItems = DB::connection('oracle_sales')
            ->table('cart_online_app')
            ->where('user_id', $user_id)
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

        $items = $cartItems->map(function ($cartItem) use ($user_id) {
            // جيب بيانات المنتج
            $product = DB::connection('oracle_lmidc')
                ->table('to_sfa_products_android')
                ->where('product_id', $cartItem->product_id)
                ->first();

            // جيب السعر والضريبة
            $price = DB::connection('oracle_lmidc')
                ->table('product_price_list')
                ->where('product_id', $cartItem->product_id)
                ->where('line_price_id', 1)
                ->first();

            // تجاهل أي عنصر قديم لم يعد له منتج أو سعر صالح.
            if (!$product || !$price || $price->pricelist_carton === null || $price->pricelist_carton <= 0) {
                DB::connection('oracle_sales')
                    ->table('cart_online_app')
                    ->where('id', $cartItem->id)
                    ->delete();

                return null;
            }
            // جيب الصورة
            $image = DB::connection('oracle_sales')
                ->table('online_app_images')
                ->where('type', 'product')
                ->where('ref_id', $cartItem->product_id)
                ->value('image_path');

            // احسب السعر
            $unit_price          = round($price->pricelist_carton, 1);
            $unit_tax            = round(($price->pricelist_carton * ($price->tax_percentage / 100)) + $price->product_tax, 1);
            $unit_price_after_tax = round($unit_price + $unit_tax, 1);
            $total_price         = round($unit_price_after_tax * $cartItem->quantity, 1);

            return [
                'image'                => $image ? asset('storage/' . $image) : null,
                'product_id'           => $cartItem->product_id,
                'name' => $product ? $product->product_ename : 'منتج محذوف',
                'quantity'             => $cartItem->quantity,
                'unit_price'           => $unit_price,
                'unit_tax'             => $unit_tax,
                'unit_price_after_tax' => $unit_price_after_tax,
                'total_price'          => $total_price,
            ];
        });

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

        $cartItem = DB::connection('oracle_sales')
            ->table('cart_online_app')
            ->where('user_id', $user_id)
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
            ->where('product_id', $product_id)
            ->delete();

        return response()->json([
            'message' => 'تم مسح المنتج من الكارت بنجاح',
        ], 200);
    }
}
