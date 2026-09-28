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
            'mobile'   => 'required',
            'password' => 'required|min:6|confirmed',
        ]);

        // تحقق إن الموبايل موجود في pos_inf
        $posExists = DB::connection('oracle_lmidc')
                        ->table('pos_inf')
                        ->where('mobile', $request->mobile)
                        ->first();

        if (!$posExists) {
            return response()->json([
                'message' => 'عذرا انت لست عميل لدى منصور',
            ], 403);
        }

        // تحقق إن الموبايل مش مسجل
        $exists = DB::connection('oracle_sales')
                    ->table('online_app_users')
                    ->where('mobile', $request->mobile)
                    ->first();

        if ($exists) {
            return response()->json([
                'message' => 'رقم الموبايل مسجل بالفعل',
            ], 422);
        }

        DB::connection('oracle_sales')->table('online_app_users')->insert([
            'mobile'     => $request->mobile,
            'password'   => Hash::make($request->password),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'message' => 'تم التسجيل بنجاح',
        ], 201);
    }

    public function login(Request $request)
    {
        $request->validate([
            'mobile'   => 'required',
            'password' => 'required',
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

        // جيب الـ warehouse_id
        $pos = DB::connection('oracle_lmidc')
            ->table('pos')
            ->where('mobile', $request->mobile)
            ->first();

        if ($pos) {
            $user_code = $pos->ter_id . '_' . $pos->pos_id;

            $ws = DB::connection('oracle_sales')
                ->table('v_to_online_users_ws')
                ->where('user_code', $user_code)
                ->first();

            if ($ws) {
                $warehouseIds = WarehouseIds::parse($ws->warehouse_id ?? null);

                if (!$warehouseIds) {
                    \Log::warning('No valid warehouse IDs returned for online user during login.', [
                        'user_id' => $user->id,
                    ]);
                } else {
                    DB::connection('oracle_sales')
                        ->table('online_app_users')
                        ->where('id', $user->id)
                        ->update([
                            'warehouse_id' => implode(',', $warehouseIds),
                        ]);
                }
            }
        }

        $userModel = User::find($user->id);
        $token = $userModel->createToken('api-token')->plainTextToken;

        return response()->json([
            'message' => 'تم تسجيل الدخول بنجاح',
            'token'   => $token,
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
