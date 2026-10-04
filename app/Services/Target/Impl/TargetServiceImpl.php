<?php

namespace App\Services\Target\Impl;

use App\Services\Target\TargetService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\Incentive\IncentiveService;

class TargetServiceImpl implements TargetService
{
    public function getTarget(Request $request)
    {
        $posCode = app(IncentiveService::class)->posCodeForUser($request->user());
        if (!$posCode) {
            return response()->json([
                'message' => 'العميل مش موجود',
            ], 404);
        }

        // السنة والشهر الحالي
        $year  = now()->year;
        $month = now()->month;

        // جيب التارجت
        $target = DB::connection('oracle_lmidc')
                    ->table('target_retail_pos')
                    ->where('year', $year)
                    ->where('month', $month)
                    ->whereRaw("ter_id || '_' || pos_id = ?", [$posCode])
                    ->select('achieved', 'target_sales')
                    ->first();

        if (!$target) {
            return response()->json([
                'message' => 'مفيش تارجت للشهر الحالي',
            ], 404);
        }

        return response()->json([
            'data' => [
                'achieved'     => $target->achieved,
                'target_sales' => $target->target_sales,
            ],
        ], 200);
    }
}
