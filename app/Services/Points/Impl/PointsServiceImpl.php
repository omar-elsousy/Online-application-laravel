<?php

namespace App\Services\Points\Impl;

use App\Services\Points\PointsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PointsServiceImpl implements PointsService
{
    public function getSummary(Request $request)
    {
        $userId = $request->user()->id;

        $user = DB::connection('oracle_sales')
            ->table('online_app_users')
            ->where('id', $userId)
            ->first();

        $points = $user ? (int)($user->points ?? 0) : 0;

        // إعدادات التصفير والسياسة
        $settingsRaw = DB::connection('oracle_sales')
            ->table('online_app_points_settings')
            ->get()
            ->keyBy('key');

        $resetMonths = $settingsRaw['reset_months']->value ?? '6';
        $nextResetDate = $settingsRaw['next_reset_date']->value ?? now()->addMonths(6)->toDateString();
        $policyText = $settingsRaw['policy_text_ar']->value ?? 'يتم تصفير النقاط كل 6 أشهر.';

        // القواعد النشطة لشرحها للعميل
        $categories = DB::connection('oracle_lmidc')
            ->table('prod_family')
            ->select('family_id', 'name')
            ->get()
            ->keyBy('family_id');

        $rules = DB::connection('oracle_sales')
            ->table('online_app_points_rules')
            ->where('is_active', 1)
            ->get()
            ->map(function ($r) use ($categories) {
                $categoryName = 'جميع الأقسام';
                if ($r->family_id && isset($categories[$r->family_id])) {
                    $categoryName = $categories[$r->family_id]->name;
                }
                $unit = $r->rule_type === 'quantity' ? 'كرتونة' : 'جنيه';
                return [
                    'category_name' => $categoryName,
                    'rule_type'     => $r->rule_type,
                    'threshold'     => (float)$r->threshold,
                    'points'        => (int)$r->points,
                    'text'          => "شراء {$r->threshold} {$unit} من [{$categoryName}] = {$r->points} نقطة",
                ];
            });

        return response()->json([
            'data' => [
                'points'          => $points,
                'reset_months'    => (int)$resetMonths,
                'next_reset_date' => $nextResetDate,
                'policy_text'     => $policyText,
                'rules'           => $rules,
            ]
        ], 200);
    }

    public function getGifts(Request $request)
    {
        $userId = $request->user()->id;

        $user = DB::connection('oracle_sales')
            ->table('online_app_users')
            ->where('id', $userId)
            ->first();

        $userPoints = $user ? (int)($user->points ?? 0) : 0;

        $gifts = DB::connection('oracle_sales')
            ->table('online_app_points_gifts')
            ->where('is_active', 1)
            ->orderBy('points_required', 'asc')
            ->get()
            ->map(function ($gift) use ($userPoints) {
                return [
                    'id'              => $gift->id,
                    'title'           => $gift->title,
                    'description'     => $gift->description,
                    'points_required' => (int)$gift->points_required,
                    'image'           => $gift->image ? asset('storage/' . $gift->image) : null,
                    'can_redeem'      => $userPoints >= (int)$gift->points_required,
                ];
            });

        return response()->json([
            'data' => $gifts,
            'user_points' => $userPoints,
        ], 200);
    }

    public function redeemGift(Request $request, $gift_id)
    {
        $userId = $request->user()->id;

        $gift = DB::connection('oracle_sales')
            ->table('online_app_points_gifts')
            ->where('id', $gift_id)
            ->where('is_active', 1)
            ->first();

        if (!$gift) {
            return response()->json([
                'message' => 'الهدية المطلوبة غير متاحة حالياً',
            ], 404);
        }

        $user = DB::connection('oracle_sales')
            ->table('online_app_users')
            ->where('id', $userId)
            ->first();

        $userPoints = $user ? (int)($user->points ?? 0) : 0;

        if ($userPoints < (int)$gift->points_required) {
            return response()->json([
                'message' => 'عفواً، رصيد نقاطك غير كافٍ لاستبدال هذه الهدية',
            ], 400);
        }

        // خصم النقاط
        DB::connection('oracle_sales')
            ->table('online_app_users')
            ->where('id', $userId)
            ->decrement('points', $gift->points_required);

        // إنشاء طلب استبدال
        $redemptionId = DB::connection('oracle_sales')
            ->table('online_app_points_redemptions')
            ->insertGetId([
                'user_id'      => $userId,
                'gift_id'      => $gift->id,
                'points_spent' => $gift->points_required,
                'status'       => 'pending',
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);

        // توثيق في سجل النقاط
        DB::connection('oracle_sales')
            ->table('online_app_points_history')
            ->insert([
                'user_id'     => $userId,
                'order_id'    => null,
                'gift_id'     => $gift->id,
                'points'      => -$gift->points_required,
                'type'        => 'redeemed_gift',
                'description' => "استبدال هدية: {$gift->title}",
                'created_at'  => now(),
            ]);

        // إشعار
        try {
            $notificationService = app(\App\Services\Notification\NotificationService::class);
            $notificationService->sendNotification(
                $userId,
                'طلب استبدال هدية 🎁',
                "تم تسجيل طلب استبدال ({$gift->title}) بنجاح وجاري مراجعته من الإدارة."
            );
        } catch (\Throwable $e) {}

        $newBalance = $userPoints - (int)$gift->points_required;

        return response()->json([
            'message'          => 'تم إرسال طلب استبدال الهدية بنجاح وخصم النقاط',
            'redemption_id'    => $redemptionId,
            'remaining_points' => $newBalance,
        ], 200);
    }

    public function getHistory(Request $request)
    {
        $userId = $request->user()->id;

        $history = DB::connection('oracle_sales')
            ->table('online_app_points_history')
            ->where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($item) {
                return [
                    'id'          => $item->id,
                    'points'      => (int)$item->points,
                    'type'        => $item->type,
                    'description' => $item->description,
                    'created_at'  => $item->created_at,
                ];
            });

        return response()->json([
            'data' => $history,
        ], 200);
    }
}
