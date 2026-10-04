<?php

namespace App\Http\Controllers\Incentive;

use App\Http\Controllers\Controller;
use App\Services\Incentive\IncentiveService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Support\ActivePosCode;

class IncentiveController extends Controller
{
    public function __construct(private IncentiveService $incentives) {}

    public function cartPreview(Request $request)
    {
        $posCode = ActivePosCode::forUser($request->user());
        if (!$posCode) return response()->json(['message' => 'تعذر تحديد العميل النشط. سجّل الدخول مرة أخرى.'], 409);
        $cartItems = DB::connection('oracle_sales')->table('cart_online_app')
            ->where('user_id', $request->user()->id)
            ->where('pos_code', $posCode)
            ->get();
        $requested = collect($request->input('wallet_credits', []));
        $cartProductIds = $cartItems->pluck('product_id')->all();
        $credits = $this->incentives->availableWallet($request->user(), $cartProductIds)->filter(function ($credit) use ($requested) {
            return $requested->contains(function ($item) use ($credit) {
                return (int) ($item['incentive_type_id'] ?? 0) === (int) $credit->incentive_type_id
                    && (string) ($item['from_date'] ?? '') === (string) $credit->from_date
                    && (string) ($item['to_date'] ?? '') === (string) $credit->to_date;
            });
        })->values();
        if ($credits->count() !== $requested->count()) return response()->json(['message' => 'رصيد المحفظة المختار غير متاح'], 422);
        $locale = $request->getPreferredLanguage(['ar', 'en']) ?? 'en';
        return response()->json(['data' => $this->incentives->previewForUser($request->user(), $cartItems, $credits->all(), $request->input('removed_gift_incentive_ids', []), $locale)]);
    }

    public function availableWallet(Request $request)
    {
        return response()->json(['data' => $this->incentives->availableWallet($request->user())]);
    }
}
