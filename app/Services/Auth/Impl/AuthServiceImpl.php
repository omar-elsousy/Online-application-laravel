<?php

namespace App\Services\Auth\Impl;

use App\Services\Auth\AuthService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use App\Services\OTP\SMSService;
use App\Support\WarehouseIds;

class AuthServiceImpl implements AuthService
{
    public function register(Request $request)
    {
        $request->validate([
            'mobile'   => 'required|string|max:20',
            'password' => 'required|min:6|confirmed',
        ]);

        $accountExists = DB::connection('oracle_sales')
            ->table('online_app_users')
            ->where('mobile', $request->mobile)
            ->exists();

        if ($accountExists) {
            return response()->json([
                'message' => 'رقم الموبايل مسجل بالفعل',
            ], 422);
        }

        if (!DB::connection('oracle_lmidc')->table('pos_inf')->where('mobile', $request->mobile)->exists()) {
            return response()->json([
                'message' => 'عذرا انت لست عميل لدى منصور',
            ], 403);
        }

        DB::connection('oracle_sales')->table('online_app_users')->insert([
            'mobile'     => $request->mobile,
            'password'   => Hash::make($request->password),
            'selected_pos_code' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'message' => 'تم التسجيل بنجاح',
        ], 201);
    }

    private function posCustomersForMobile(string $mobile)
    {
        return DB::connection('oracle_lmidc')->table('pos')
            ->where('mobile', $mobile)
            ->select('ter_id', 'pos_id', 'name')
            ->orderBy('name')
            ->get()
            ->map(function ($pos) {
                if (!is_numeric($pos->ter_id) || !is_numeric($pos->pos_id)) return null;
                return [
                    'pos_code' => $pos->ter_id . '_' . $pos->pos_id,
                    'name' => trim((string) $pos->name) ?: 'عميل بدون اسم',
                ];
            })
            ->filter()
            ->unique('pos_code')
            ->values();
    }

    public function login(Request $request)
    {
        $request->validate([
            'mobile'   => 'required|string|max:20',
            'password' => 'required',
            'pos_code' => 'nullable|string|max:80',
        ]);

        $user = DB::connection('oracle_sales')
            ->table('online_app_users')
            ->where('mobile', $request->mobile)
            ->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json([
                'message' => 'موبايل أو باسورد غلط',
            ], 401);
        }

        if ($user->is_blocked) {
            return response()->json([
                'message' => 'تم حظر حسابك',
            ], 403);
        }

        $customers = $this->posCustomersForMobile($request->mobile);
        if ($customers->isEmpty()) {
            return response()->json(['message' => 'لا يوجد عميل صالح مرتبط برقم الموبايل'], 403);
        }

        $selectedPosCode = $request->input('pos_code');
        if ($selectedPosCode === null) {
            return response()->json([
                'selection_required' => true,
                'customers' => $customers,
            ], 200);
        }

        $selectedCustomer = $customers->first(
            fn ($customer) => $customer['pos_code'] === $selectedPosCode,
        );
        if (!$selectedCustomer) {
            return response()->json(['message' => 'العميل المختار غير مرتبط برقم الموبايل'], 422);
        }

        [$terId, $posId] = explode('_', $selectedPosCode, 2);
        $pos = DB::connection('oracle_lmidc')->table('pos')
            ->where('ter_id', $terId)
            ->where('pos_id', $posId)
            ->where('mobile', $request->mobile)
            ->first();
        if (!$pos) {
            return response()->json(['message' => 'تعذر العثور على العميل المختار'], 403);
        }

        $userCode = $pos->ter_id . '_' . $pos->pos_id;
        $ws = DB::connection('oracle_sales')->table('v_to_online_users_ws')
            ->where('user_code', $userCode)
            ->first();
        $warehouseIds = WarehouseIds::parse($ws->warehouse_id ?? null);

        if (!$warehouseIds) {
            \Log::warning('No valid warehouse IDs returned for online user during login.', [
                'user_id' => $user->id,
                'pos_code' => $userCode,
            ]);
        }

        // Keep these columns as fallback context for existing API tokens;
        // fresh tokens carry their own POS and warehouse context.
        DB::connection('oracle_sales')->table('online_app_users')
            ->where('id', $user->id)
            ->update([
                'warehouse_id' => implode(',', $warehouseIds),
                'selected_pos_code' => $userCode,
            ]);

        $userModel = User::find($user->id);
        $token = $userModel->createToken('api-token', [
            '*',
            'pos_code:' . $userCode,
            'warehouse_ids:' . implode(',', $warehouseIds),
        ])->plainTextToken;

        return response()->json([
            'message' => 'تم تسجيل الدخول بنجاح',
            'token'   => $token,
            'user' => [
                'mobile' => $user->mobile,
                'customer_name' => trim((string) $pos->name) ?: null,
                'pos_code' => $userCode,
            ],
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'تم تسجيل الخروج بنجاح',
        ]);
    }

    public function changePassword(Request $request)
    {
        $request->validate([
            'current_password'      => 'required',
            'new_password'          => 'required|min:6|confirmed',
        ]);

        $user = DB::connection('oracle_sales')
            ->table('online_app_users')
            ->where('mobile', $request->user()->mobile)
            ->first();

        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'message' => 'الباسورد الحالي غلط',
            ], 401);
        }

        DB::connection('oracle_sales')
            ->table('online_app_users')
            ->where('mobile', $request->user()->mobile)
            ->update([
                'password'   => Hash::make($request->new_password),
                'updated_at' => now(),
            ]);

        return response()->json([
            'message' => 'تم تغيير الباسورد بنجاح',
        ], 200);
    }

    public function sendOtp(Request $request)
    {
        $request->validate([
            'mobile' => 'required',
        ]);

        $user = DB::connection('oracle_sales')
            ->table('online_app_users')
            ->where('mobile', $request->mobile)
            ->first();

        if (!$user) {
            return response()->json([
                'message' => 'رقم الموبايل مش موجود',
            ], 404);
        }

        // عمل OTP عشوائي
        $otp = rand(100000, 999999);

        // خزن الـ OTP في الداتا بيز
        DB::connection('oracle_sales')
            ->table('online_app_password_reset_otp')
            ->where('mobile', $request->mobile)
            ->delete();

        DB::connection('oracle_sales')
            ->table('online_app_password_reset_otp')
            ->insert([
                'mobile'     => $request->mobile,
                'otp'        => $otp,
                'expires_at' => now()->addMinutes(10),
                'created_at' => now(),
            ]);

        // بعت الـ OTP على الموبايل
        try {
            $smsService = new SMSService();
            $smsService->sendSMS($request->mobile, "كود التحقق الخاص بك هو: $otp");
        } catch (\Exception $e) {
        }

        return response()->json([
            'message' => 'تم إرسال الكود على موبايلك',
            'otp'     => $otp, // دي بس للعرض في الـ response، في الحقيقة مش هنبعتها
        ], 200);
    }

    public function resetPassword(Request $request)
    {
        $request->validate([
            'otp'                   => 'required',
            'new_password'          => 'required|min:6|confirmed',
        ]);

        $otpRecord = DB::connection('oracle_sales')
            ->table('online_app_password_reset_otp')
            ->where('otp', $request->otp)
            ->first();

        if (!$otpRecord) {
            return response()->json([
                'message' => 'الكود غلط',
            ], 401);
        }

        if (now()->gt($otpRecord->expires_at)) {
            return response()->json([
                'message' => 'الكود انتهت صلاحيته',
            ], 401);
        }

        // غير الباسورد
        DB::connection('oracle_sales')
            ->table('online_app_users')
            ->where('mobile', $otpRecord->mobile)
            ->update([
                'password'   => Hash::make($request->new_password),
                'updated_at' => now(),
            ]);

        // امسح الـ OTP
        DB::connection('oracle_sales')
            ->table('online_app_password_reset_otp')
            ->where('otp', $request->otp)
            ->delete();

        return response()->json([
            'message' => 'تم تغيير الباسورد بنجاح',
        ], 200);
    }
}
