<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\Auth\AuthService;

class AuthController extends Controller
{
    protected $authService;

    public function __construct(AuthService $authService)
    {
        $this->authService = $authService;
    }

    /**
     * @OA\Post(
     *     path="/register",
     *     summary="تسجيل مستخدم جديد",
     *     tags={"Auth"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"mobile","password","password_confirmation"},
     *             @OA\Property(property="mobile", type="string", example="01012345678"),
     *             @OA\Property(property="password", type="string", example="123456"),
     *             @OA\Property(property="password_confirmation", type="string", example="123456")
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="تم التسجيل بنجاح",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="تم التسجيل بنجاح")
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="رقم الموبايل مسجل بالفعل",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="رقم الموبايل مسجل بالفعل")
     *         )
     *     )
     * )
     */
    public function register(Request $request)
    {
        return $this->authService->register($request);
    }

    public function registrationCustomers(Request $request)
    {
        return $this->authService->registrationCustomers($request);
    }

    /**
     * @OA\Post(
     *     path="/login",
     *     summary="تسجيل الدخول",
     *     tags={"Auth"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"mobile","password"},
     *             @OA\Property(property="mobile", type="string", example="01012345678"),
     *             @OA\Property(property="password", type="string", example="123456")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="تم تسجيل الدخول بنجاح",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="تم تسجيل الدخول بنجاح"),
     *             @OA\Property(property="token", type="string", example="1|abc123...")
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="موبايل أو باسورد غلط",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="موبايل أو باسورد غلط")
     *         )
     *     ),
     *     @OA\Response(
     *         response=403,
     *         description="تم حظر الحساب",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="تم حظر حسابك")
     *         )
     *     )
     * )
     */
    public function login(Request $request)
    {
        return $this->authService->login($request);
    }

    /**
     * @OA\Post(
     *     path="/logout",
     *     summary="تسجيل الخروج",
     *     tags={"Auth"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="تم تسجيل الخروج بنجاح",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="تم تسجيل الخروج بنجاح")
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated")
     * )
     */
    public function logout(Request $request)
    {
        return $this->authService->logout($request);
    }

    /**
     * @OA\Post(
     *     path="/changePassword",
     *     summary="تغيير الباسورد",
     *     tags={"Auth"},
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"current_password","new_password","new_password_confirmation"},
     *             @OA\Property(property="current_password", type="string", example="123456"),
     *             @OA\Property(property="new_password", type="string", example="newpass123"),
     *             @OA\Property(property="new_password_confirmation", type="string", example="newpass123")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="تم تغيير الباسورد بنجاح",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="تم تغيير الباسورد بنجاح")
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="الباسورد الحالي غلط",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="الباسورد الحالي غلط")
     *         )
     *     )
     * )
     */
    public function changePassword(Request $request)
    {
        return $this->authService->changePassword($request);
    }

    /**
     * @OA\Post(
     *     path="/sendOtp",
     *     summary="إرسال كود OTP لإعادة تعيين الباسورد",
     *     tags={"Auth"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"mobile"},
     *             @OA\Property(property="mobile", type="string", example="01012345678")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="تم إرسال الكود",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="تم إرسال الكود على موبايلك"),
     *             @OA\Property(property="otp", type="integer", example=123456)
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="رقم الموبايل مش موجود",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="رقم الموبايل مش موجود")
     *         )
     *     )
     * )
     */
    public function sendOtp(Request $request)
    {
        return $this->authService->sendOtp($request);
    }

    /**
     * @OA\Post(
     *     path="/resetPassword",
     *     summary="إعادة تعيين الباسورد عن طريق OTP",
     *     tags={"Auth"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"otp","new_password","new_password_confirmation"},
     *             @OA\Property(property="otp", type="integer", example=123456),
     *             @OA\Property(property="new_password", type="string", example="newpass123"),
     *             @OA\Property(property="new_password_confirmation", type="string", example="newpass123")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="تم تغيير الباسورد بنجاح",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="تم تغيير الباسورد بنجاح")
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="الكود غلط أو منتهي",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="الكود غلط")
     *         )
     *     )
     * )
     */
    public function resetPassword(Request $request)
    {
        return $this->authService->resetPassword($request);
    }
}
