<?php

namespace App\Http\Controllers\Points;

use App\Http\Controllers\Controller;
use App\Services\Points\PointsService;
use Illuminate\Http\Request;

class PointsController extends Controller
{
    protected $pointsService;

    public function __construct(PointsService $pointsService)
    {
        $this->pointsService = $pointsService;
    }

    /**
     * @OA\Get(
     *     path="/points/summary",
     *     summary="جلب ملخص نقاط العميل وسياسة التصفير والقواعد",
     *     tags={"Points"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="بيانات النقاط",
     *         @OA\JsonContent(
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(property="points", type="integer", example=150),
     *                 @OA\Property(property="reset_months", type="integer", example=6),
     *                 @OA\Property(property="next_reset_date", type="string", example="2026-12-31"),
     *                 @OA\Property(property="policy_text", type="string", example="يتم تصفير النقاط كل 6 أشهر."),
     *                 @OA\Property(property="rules", type="array", @OA\Items(type="object"))
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated")
     * )
     */
    public function summary(Request $request)
    {
        return $this->pointsService->getSummary($request);
    }

    /**
     * @OA\Get(
     *     path="/points/gifts",
     *     summary="جلب الهدايا والمكافآت المتاحة للاستبدال",
     *     tags={"Points"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="قائمة الهدايا",
     *         @OA\JsonContent(
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="title", type="string", example="شاشة 32 بوصة"),
     *                     @OA\Property(property="description", type="string", example="شاشة سمارت"),
     *                     @OA\Property(property="points_required", type="integer", example=1000),
     *                     @OA\Property(property="image", type="string", nullable=true),
     *                     @OA\Property(property="can_redeem", type="boolean", example=true)
     *                 )
     *             ),
     *             @OA\Property(property="user_points", type="integer", example=1200)
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated")
     * )
     */
    public function gifts(Request $request)
    {
        return $this->pointsService->getGifts($request);
    }

    /**
     * @OA\Post(
     *     path="/points/redeem/{gift_id}",
     *     summary="استبدال هدية بالنقاط",
     *     tags={"Points"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="gift_id",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="integer"),
     *         example=1
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="تم إرسال طلب استبدال الهدية بنجاح",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="تم إرسال طلب استبدال الهدية بنجاح وخصم النقاط"),
     *             @OA\Property(property="redemption_id", type="integer", example=1),
     *             @OA\Property(property="remaining_points", type="integer", example=200)
     *         )
     *     ),
     *     @OA\Response(response=400, description="النقاط غير كافية"),
     *     @OA\Response(response=404, description="الهدية غير موجودة"),
     *     @OA\Response(response=401, description="Unauthenticated")
     * )
     */
    public function redeem(Request $request, $gift_id)
    {
        return $this->pointsService->redeemGift($request, $gift_id);
    }

    /**
     * @OA\Get(
     *     path="/points/history",
     *     summary="سجل حركات النقاط للعميل",
     *     tags={"Points"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="سجل النقاط",
     *         @OA\JsonContent(
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="points", type="integer", example=50),
     *                     @OA\Property(property="type", type="string", example="earned_order"),
     *                     @OA\Property(property="description", type="string", example="مكافأة نقاط من الطلب رقم #12"),
     *                     @OA\Property(property="created_at", type="string", example="2026-09-15 12:00:00")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated")
     * )
     */
    public function history(Request $request)
    {
        return $this->pointsService->getHistory($request);
    }
}
