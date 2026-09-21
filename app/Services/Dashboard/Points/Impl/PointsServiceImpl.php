<?php

namespace App\Services\Dashboard\Points\Impl;

use App\Services\Dashboard\Points\PointsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PointsServiceImpl implements PointsService
{
    public function points(Request $request)
    {
        // جلب الأقسام من oracle_lmidc
        $categories = DB::connection('oracle_lmidc')
            ->table('prod_family')
            ->select('family_id', 'name')
            ->orderBy('name')
            ->get();

        // جلب قواعد النقاط من oracle_sales
        $rules = DB::connection('oracle_sales')
            ->table('online_app_points_rules')
            ->orderBy('id', 'desc')
            ->get()
            ->map(function ($rule) use ($categories) {
                if ($rule->family_id) {
                    $cat = $categories->firstWhere('family_id', $rule->family_id);
                    $rule->category_name = $cat ? $cat->name : 'قسم غير معروف (#'.$rule->family_id.')';
                } else {
                    $rule->category_name = 'جميع الأقسام (عام)';
                }
                return $rule;
            });

        // جلب الإعدادات
        $settingsRaw = DB::connection('oracle_sales')
            ->table('online_app_points_settings')
            ->get()
            ->keyBy('key');

        $settings = [
            'reset_months'    => $settingsRaw['reset_months']->value ?? '6',
            'next_reset_date' => $settingsRaw['next_reset_date']->value ?? now()->addMonths(6)->toDateString(),
            'policy_text_ar'  => $settingsRaw['policy_text_ar']->value ?? '',
        ];

        // جلب الهدايا
        $gifts = DB::connection('oracle_sales')
            ->table('online_app_points_gifts')
            ->orderBy('points_required', 'asc')
            ->get();

        // جلب طلبات الاستبدال
        $redemptions = DB::connection('oracle_sales')
            ->table('online_app_points_redemptions as r')
            ->leftJoin('online_app_users as u', 'r.user_id', '=', 'u.id')
            ->leftJoin('online_app_points_gifts as g', 'r.gift_id', '=', 'g.id')
            ->select(
                'r.*',
                'u.mobile as user_mobile',
                'g.title as gift_title',
                'g.image as gift_image'
            )
            ->orderBy('r.created_at', 'desc')
            ->get();

        return view('dashboard.points', compact('categories', 'rules', 'settings', 'gifts', 'redemptions'));
    }

    public function addRule(Request $request)
    {
        $request->validate([
            'family_id' => 'nullable',
            'rule_type' => 'required|in:quantity,amount',
            'threshold' => 'required|numeric|min:0.1',
            'points'    => 'required|integer|min:1',
        ]);

        $familyId = $request->family_id === 'all' || empty($request->family_id) ? null : $request->family_id;

        DB::connection('oracle_sales')
            ->table('online_app_points_rules')
            ->insert([
                'family_id'  => $familyId,
                'rule_type'  => $request->rule_type,
                'threshold'  => $request->threshold,
                'points'     => $request->points,
                'is_active'  => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        return redirect(asset('dashboard/points'))->with('success', 'تمت إضافة القاعدة بنجاح');
    }

    public function toggleRule(Request $request, $id)
    {
        $rule = DB::connection('oracle_sales')
            ->table('online_app_points_rules')
            ->where('id', $id)
            ->first();

        if ($rule) {
            DB::connection('oracle_sales')
                ->table('online_app_points_rules')
                ->where('id', $id)
                ->update([
                    'is_active'  => $rule->is_active ? 0 : 1,
                    'updated_at' => now(),
                ]);
        }

        return redirect(asset('dashboard/points'))->with('success', 'تم تحديث حالة القاعدة بنجاح');
    }

    public function deleteRule(Request $request, $id)
    {
        DB::connection('oracle_sales')
            ->table('online_app_points_rules')
            ->where('id', $id)
            ->delete();

        return redirect(asset('dashboard/points'))->with('success', 'تم حذف القاعدة بنجاح');
    }

    public function updateSettings(Request $request)
    {
        $request->validate([
            'reset_months'    => 'required|integer|min:1|max:36',
            'next_reset_date' => 'required|date',
            'policy_text_ar'  => 'nullable|string',
        ]);

        $settings = [
            'reset_months'    => $request->reset_months,
            'next_reset_date' => $request->next_reset_date,
            'policy_text_ar'  => $request->policy_text_ar,
        ];

        foreach ($settings as $key => $val) {
            $exists = DB::connection('oracle_sales')
                ->table('online_app_points_settings')
                ->where('key', $key)
                ->first();

            if ($exists) {
                DB::connection('oracle_sales')
                    ->table('online_app_points_settings')
                    ->where('key', $key)
                    ->update([
                        'value'      => $val,
                        'updated_at' => now(),
                    ]);
            } else {
                DB::connection('oracle_sales')
                    ->table('online_app_points_settings')
                    ->insert([
                        'key'        => $key,
                        'value'      => $val,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
            }
        }

        return redirect(asset('dashboard/points'))->with('success', 'تم حفظ إعدادات دورة النقاط بنجاح');
    }

    public function resetAllPoints(Request $request)
    {
        // تصفير النقاط لجميع المستخدمين وتسجيل العملية في السجل
        $usersWithPoints = DB::connection('oracle_sales')
            ->table('online_app_users')
            ->where('points', '>', 0)
            ->get();

        foreach ($usersWithPoints as $u) {
            DB::connection('oracle_sales')
                ->table('online_app_points_history')
                ->insert([
                    'user_id'     => $u->id,
                    'order_id'    => null,
                    'gift_id'     => null,
                    'points'      => -$u->points,
                    'type'        => 'expired',
                    'description' => 'تصفير النقاط لانتهاء الدورة الزمنية',
                    'created_at'  => now(),
                ]);
        }

        DB::connection('oracle_sales')
            ->table('online_app_users')
            ->update(['points' => 0]);

        // تحديث تاريخ التصفير القادم بناءً على عدد الشهور
        $resetMonths = DB::connection('oracle_sales')
            ->table('online_app_points_settings')
            ->where('key', 'reset_months')
            ->value('value') ?? 6;

        DB::connection('oracle_sales')
            ->table('online_app_points_settings')
            ->where('key', 'next_reset_date')
            ->update([
                'value'      => now()->addMonths((int)$resetMonths)->toDateString(),
                'updated_at' => now(),
            ]);

        return redirect(asset('dashboard/points'))->with('success', 'تم تصفير جميع نقاط العملاء بنجاح وبدء دورة جديدة');
    }

    public function addGift(Request $request)
    {
        $request->validate([
            'title'           => 'required|string|max:200',
            'description'     => 'nullable|string',
            'points_required' => 'required|integer|min:1',
            'image'           => 'nullable|image|mimes:jpeg,png,jpg,webp|max:3072',
        ]);

        $imagePath = null;
        if ($request->hasFile('image')) {
            $imagePath = $request->file('image')->store('images/gifts', 'public');
        }

        DB::connection('oracle_sales')
            ->table('online_app_points_gifts')
            ->insert([
                'title'           => $request->title,
                'description'     => $request->description,
                'points_required' => $request->points_required,
                'image'           => $imagePath,
                'is_active'       => 1,
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);

        return redirect(asset('dashboard/points'))->with('success', 'تمت إضافة الهدية بنجاح');
    }

    public function toggleGift(Request $request, $id)
    {
        $gift = DB::connection('oracle_sales')
            ->table('online_app_points_gifts')
            ->where('id', $id)
            ->first();

        if ($gift) {
            DB::connection('oracle_sales')
                ->table('online_app_points_gifts')
                ->where('id', $id)
                ->update([
                    'is_active'  => $gift->is_active ? 0 : 1,
                    'updated_at' => now(),
                ]);
        }

        return redirect(asset('dashboard/points'))->with('success', 'تم تحديث حالة الهدية');
    }

    public function deleteGift(Request $request, $id)
    {
        $gift = DB::connection('oracle_sales')
            ->table('online_app_points_gifts')
            ->where('id', $id)
            ->first();

        if ($gift) {
            if ($gift->image) {
                Storage::disk('public')->delete($gift->image);
            }
            DB::connection('oracle_sales')
                ->table('online_app_points_gifts')
                ->where('id', $id)
                ->delete();
        }

        return redirect(asset('dashboard/points'))->with('success', 'تم حذف الهدية بنجاح');
    }

    public function updateRedemptionStatus(Request $request, $id)
    {
        $request->validate([
            'status'      => 'required|in:approved,rejected',
            'admin_notes' => 'nullable|string',
        ]);

        $redemption = DB::connection('oracle_sales')
            ->table('online_app_points_redemptions')
            ->where('id', $id)
            ->first();

        if (!$redemption) {
            return redirect(asset('dashboard/points'))->with('error', 'الطلب غير موجود');
        }

        if ($redemption->status !== 'pending') {
            return redirect(asset('dashboard/points'))->with('error', 'تم البت في هذا الطلب مسبقاً');
        }

        // إذا تم رفض الطلب، نرد النقاط للمستخدم
        if ($request->status === 'rejected') {
            DB::connection('oracle_sales')
                ->table('online_app_users')
                ->where('id', $redemption->user_id)
                ->increment('points', $redemption->points_spent);

            DB::connection('oracle_sales')
                ->table('online_app_points_history')
                ->insert([
                    'user_id'     => $redemption->user_id,
                    'gift_id'     => $redemption->gift_id,
                    'points'      => $redemption->points_spent,
                    'type'        => 'admin_adjustment',
                    'description' => 'استرجاع نقاط لرفض طلب استبدال الهدية رقم #' . $id,
                    'created_at'  => now(),
                ]);
        }

        DB::connection('oracle_sales')
            ->table('online_app_points_redemptions')
            ->where('id', $id)
            ->update([
                'status'      => $request->status,
                'admin_notes' => $request->admin_notes,
                'updated_at'  => now(),
            ]);

        return redirect(asset('dashboard/points'))->with('success', 'تم تحديث حالة طلب الاستبدال بنجاح');
    }
}
