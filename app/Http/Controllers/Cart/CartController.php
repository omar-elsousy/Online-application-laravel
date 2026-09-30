<?php

namespace App\Http\Controllers\Cart;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\Cart\CartService;

class CartController extends Controller
{
    protected $cartService;

    public function __construct(CartService $cartService)
    {
        $this->cartService = $cartService;
    }

    /**
     * @OA\Post(
     *     path="/addToCart/{product_id}",
     *     summary="إضافة منتج للكارت",
     *     tags={"Cart"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="product_id",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="integer"),
     *         example=101
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"quantity"},
     *             @OA\Property(property="quantity", type="number", minimum=0.01, example=0.5)
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="تم الإضافة للكارت بنجاح",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="تم الإضافة للكارت بنجاح")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="المنتج out of stock",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="المنتج ده out of stock")
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated")
     * )
     */
    public function addToCart(Request $request, $product_id)
    {
        return $this->cartService->addToCart($request, $product_id);
    }

    /**
     * @OA\Get(
     *     path="/getCart",
     *     summary="جلب محتويات الكارت",
     *     tags={"Cart"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="بيانات الكارت",
     *         @OA\JsonContent(
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(
     *                     property="items",
     *                     type="array",
     *                     @OA\Items(
     *                         type="object",
     *                         @OA\Property(property="image", type="string", nullable=true, example="http://example.com/storage/products/img.jpg"),
     *                         @OA\Property(property="product_id", type="integer", example=101),
     *                         @OA\Property(property="name", type="string", example="منتج A"),
     *                         @OA\Property(property="quantity", type="number", example=0.5),
     *                         @OA\Property(property="unit_price", type="number", example=50.0),
     *                         @OA\Property(property="unit_tax", type="number", example=7.5),
     *                         @OA\Property(property="unit_price_after_tax", type="number", example=57.5),
     *                         @OA\Property(property="total_price", type="number", example=115.0)
     *                     )
     *                 ),
     *                 @OA\Property(property="number_of_products", type="integer", example=3),
     *                 @OA\Property(property="final_price", type="number", example=345.0)
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated")
     * )
     */
    public function getCart(Request $request)
    {
        return $this->cartService->getCart($request);
    }

    /**
     * @OA\Delete(
     *     path="/removeFromCart/{product_id}",
     *     summary="حذف منتج من الكارت",
     *     tags={"Cart"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="product_id",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="integer"),
     *         example=101
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="تم مسح المنتج من الكارت بنجاح",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="تم مسح المنتج من الكارت بنجاح")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="المنتج مش موجود في الكارت",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="المنتج مش موجود في الكارت")
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated")
     * )
     */
    public function removeFromCart(Request $request, $product_id)
    {
        return $this->cartService->removeFromCart($request, $product_id);
    }
}
