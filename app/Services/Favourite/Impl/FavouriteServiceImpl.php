<?php

namespace App\Services\Favourite\Impl;

use App\Services\Favourite\FavouriteService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FavouriteServiceImpl implements FavouriteService
{
    public function addToFavourites(Request $request, $product_id)
    {
        $user_id = $request->user()->id;

        $exists = DB::connection('oracle_sales')
                        ->table('favourites_online_app')
                        ->where('user_id', $user_id)
                        ->where('product_id', $product_id)
                        ->first();

        if ($exists) {
            return response()->json([
                'message' => 'المنتج موجود بالفعل في المفضلة',
            ], 422);
        }

        DB::connection('oracle_sales')
            ->table('favourites_online_app')
            ->insert([
                'user_id'    => $user_id,
                'product_id' => $product_id,
                'created_at' => now(),
            ]);

        return response()->json([
            'message' => 'تم الإضافة للمفضلة بنجاح',
        ], 201);
    }

    public function getFavourites(Request $request)
    {
        $user_id = $request->user()->id;

        $favourites = DB::connection('oracle_sales')
                        ->table('favourites_online_app')
                        ->where('user_id', $user_id)
                        ->get()
                        ->map(function($fav) {
                            $product = DB::connection('oracle_lmidc')
                                            ->table('to_sfa_products_android')
                                            ->where('product_id', $fav->product_id)
                                            ->first();

                            $price = DB::connection('oracle_lmidc')
                                        ->table('product_price_list')
                                        ->where('product_id', $fav->product_id)
                                        ->where('line_price_id', 1)
                                        ->first();

                            if (!$product || !$price || $price->pricelist_carton === null || $price->pricelist_carton <= 0) {
                                return null;
                            }

                            $image = DB::connection('oracle_sales')
                                        ->table('online_app_images')
                                        ->where('type', 'product')
                                        ->where('ref_id', $fav->product_id)
                                        ->value('image_path');

                            $tax   = ($price->pricelist_carton * ($price->tax_percentage / 100)) + $price->product_tax;
                            $price = round($price->pricelist_carton + $tax, 1);

                            return [
                                'image'      => $image ? asset('storage/' . $image) : null,
                                'product_id' => $fav->product_id,
                                'name' => $product ? $product->product_ename : 'منتج محذوف',
                                'price'      => $price,
                            ];
                        })->filter()->values();

        return response()->json([
            'data' => $favourites,
        ], 200);
    }

    public function removeFromFavourites(Request $request, $product_id)
    {
        $user_id = $request->user()->id;

        DB::connection('oracle_sales')
            ->table('favourites_online_app')
            ->where('user_id', $user_id)
            ->where('product_id', $product_id)
            ->delete();

        return response()->json([
            'message' => 'تم إزالة المنتج من المفضلة بنجاح',
        ], 200);
    }
}