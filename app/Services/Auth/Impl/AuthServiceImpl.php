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
    public function registrationCustomers(Request $request)
    {
        $request->validate(['mobile' => 'required|string|max:20']);

        if (!DB::connection('oracle_lmidc')->table('pos_inf')->where('mobile', $request->mobile)->exists()) {
            return response()->json(['message' => 'عذرا انت لست عميل لدى منصور'], 403);
        }

        $customers = $this->posCustomersForMobile($request->mobile);
        if ($customers->isEmpty()) {
            return response()->json(['message' => 'لا يوجد عميل صالح مرتبط برقم الموبايل'], 403);
        }

        $registeredPosCodes = $this->registeredPosCodesForMobile($request->mobile, $customers);
        $customers = $customers->map(function ($customer) use ($registeredPosCodes) {
            $customer['already_registered'] = $registeredPosCodes->contains($customer['pos_code']);
            return $customer;
        });

        return response()->json(['data' => $customers], 200);
    }

    public function register(Request $request)
    {
        $request->validate([
            'mobile'   => 'required|string|max:20',
            'password' => 'required|min:6|confirmed',
            'pos_code' => 'nullable|string|max:80',
        ]);

        if (!DB::connection('oracle_lmidc')->table('pos_inf')->where('mobile', $request->mobile)->exists()) {
            return response()->json([
                'message' => 'عذرا انت لست عميل لدى منصور',
            ], 403);
        }

        $customers = $this->posCustomersForMobile($request->mobile);
        if ($customers->isEmpty()) {
            return response()->json(['message' => 'لا يوجد عميل صالح مرتبط برقم الموبايل'], 403);
        }

        $selectedPosCode = $request->input('pos_code');
        if ($selectedPosCode === null && $customers->count() === 1) {
            $selectedPosCode = $customers->first()['pos_code'];
        }
        if ($selectedPosCode === null) {
            return response()->json([
                'message' => 'اختار العميل المرتبط برقم الموبايل',
                'customers' => $customers,
            ], 422);
        }
        if (!$customers->contains(fn ($customer) => $customer['pos_code'] === $selectedPosCode)) {
            return response()->json(['message' => 'العميل المختار غير مرتبط برقم الموبايل'], 422);
        }

        // A mobile number can belong to multiple POS customers, but each POS
        // customer may only have one app account.
        if ($this->registeredPosCodesForMobile($request->mobile, $customers)->contains($selectedPosCode)) {
            return response()->json([
                'message' => 'العميل المختار لديه حساب بالفعل',
            ], 422);
        }

        $samePasswordExists = DB::connection('oracle_sales')
            ->table('online_app_users')
            ->where('mobile', $request->mobile)
            ->pluck('password')
            ->contains(fn ($passwordHash) =>
                is_string($passwordHash) && Hash::check($request->password, $passwordHash)
            );

        if ($samePasswordExists) {
            return response()->json([
                'message' => 'كلمة السر مستخدمة بالفعل لحساب آخر على رقم الموبايل ده. اختار كلمة سر مختلفة.',
            ], 422);
        }

        DB::connection('oracle_sales')->table('online_app_users')->insert([
            'mobile'     => $request->mobile,
            'password'   => Hash::make($request->password),
            'selected_pos_code' => $selectedPosCode,
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

    private function registeredPosCodesForMobile(string $mobile, $customers)
    {
        $users = DB::connection('oracle_sales')->table('online_app_users')
            ->where('mobile', $mobile)
            ->select('selected_pos_code')
            ->get();

        $registeredCodes = $users->pluck('selected_pos_code')
            ->filter(fn ($code) => is_string($code) && preg_match('/^\d+_\d+$/', $code))
            ->unique()
            ->values();

        // Older accounts have no selected POS code and login used the first
        // POS record. Keep that customer marked as registered after upgrade.
        if ($users->contains(fn ($user) => empty($user->selected_pos_code)) && $customers->isNotEmpty()) {
            $legacyPosCode = DB::connection('oracle_lmidc')->table('pos')
                ->where('mobile', $mobile)
                ->orderBy('ter_id')
                ->orderBy('pos_id')
                ->first(['ter_id', 'pos_id']);

            if ($legacyPosCode && is_numeric($legacyPosCode->ter_id) && is_numeric($legacyPosCode->pos_id)) {
                $legacyCode = $legacyPosCode->ter_id . '_' . $legacyPosCode->pos_id;
                if ($customers->contains(fn ($customer) => $customer['pos_code'] === $legacyCode)) {
                    $registeredCodes->push($legacyCode);
                }
            }
        }

        return $registeredCodes->unique()->values();
    }

    public function login(Request $request)
    {
        $request->validate([
            'mobile'   => 'required',
            'password' => 'required',
        ]);

        $users = DB::connection('oracle_sales')
            ->table('online_app_users')
            ->where('mobile', $request->mobile)
            ->get();

        // A phone may now have separate accounts for several POS customers.
        // Authenticate against all of them instead of blindly choosing the
        // first row for that phone.
        $user = $users->first(fn ($candidate) => Hash::check($request->password, $candidate->password));

        if (!$user) {
            return response()->json([
                'message' => 'موبايل أو باسورد غلط',
            ], 401);
        }

        if ($user->is_blocked) {
            return response()->json([
                'message' => 'تم حظر حسابك',
            ], 403);
        }

        // Use the customer chosen during registration; old accounts retain the legacy fallback.
        $pos = null;
        $selectedPosCode = trim((string) ($user->selected_pos_code ?? ''));
        if (preg_match('/^(\d+)_(\d+)$/', $selectedPosCode, $matches)) {
            $pos = DB::connection('oracle_lmidc')->table('pos')
                ->where('ter_id', $matches[1])
                ->where('pos_id', $matches[2])
                ->where('mobile', $request->mobile)
                ->first();
            if (!$pos) {
                return response()->json(['message' => 'تعذر العثور على العميل المختار لهذا الحساب'], 403);
            }
        } else {
            $pos = DB::connection('oracle_lmidc')->table('pos')
                ->where('mobile', $request->mobile)
                ->orderBy('ter_id')
                ->orderBy('pos_id')
                ->first();
        }

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
            'user' => [
                'mobile' => $user->mobile,
                'customer_name' => $pos ? (trim((string) $pos->name) ?: null) : null,
                'pos_code' => $pos ? $pos->ter_id . '_' . $pos->pos_id : ($selectedPosCode ?: null),
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
