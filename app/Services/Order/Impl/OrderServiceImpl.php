<?php

namespace App\Services\Order\Impl;

use App\Services\Order\OrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\Incentive\IncentiveService;
use App\Support\WarehouseIds;
use App\Support\ActivePosCode;
use App\Support\PosPoints;

class OrderServiceImpl implements OrderService
{
    public function placeOrder(Request $request)
    {
        $user_id = $request->user()->id;
        $posCode = ActivePosCode::forUser($request->user());
        if (!$posCode) return response()->json(['message' => 'تعذر تحديد العميل النشط. سجّل الدخول مرة أخرى.'], 409);

        $cartItems = DB::connection('oracle_sales')
            ->table('cart_online_app')
            ->where('user_id', $user_id)
            ->where('pos_code', $posCode)
            ->get();

        if ($cartItems->isEmpty()) {
            return response()->json([
                'message' => 'الكارت فاضي',
            ], 400);
        }

        // تحقق إن مفيش أوردر شغال
        $activeOrder = DB::connection('oracle_sales')
            ->table('orders_online_app')
            ->where('user_id', $user_id)
            ->where('pos_code', $posCode)
            ->whereIn('status', [1, 2])
            ->first();

        if ($activeOrder) {
            return response()->json([
                'message' => 'لديك طلب قيد المعالجة، يرجى الانتظار حتى يتم تسليمه أو إلغاؤه',
            ], 400);
        }

        // جيب الـ warehouse_id بتاع اليوزر
        $user = DB::connection('oracle_sales')
            ->table('online_app_users')
            ->where('id', $user_id)
            ->first();
        $warehouseIds = WarehouseIds::fromUser($user);

        $incentives = app(IncentiveService::class);
        $requestedCredits = collect($request->input('wallet_credits', []));
        $availableCredits = $incentives->availableWallet($request->user(), $cartItems->pluck('product_id')->all());
        $walletCredits = $availableCredits->filter(function ($credit) use ($requestedCredits) {
            return $requestedCredits->contains(function ($requested) use ($credit) {
                return (int) ($requested['incentive_type_id'] ?? 0) === (int) $credit->incentive_type_id
                    && (string) ($requested['from_date'] ?? '') === (string) $credit->from_date
                    && (string) ($requested['to_date'] ?? '') === (string) $credit->to_date;
            });
        })->values();
        if ($walletCredits->count() !== $requestedCredits->count()) {
            return response()->json(['message' => 'رصيد المحفظة المختار غير متاح حالياً'], 422);
        }
        $preview = $incentives->previewForUser($request->user(), $cartItems, $walletCredits->all(), $request->input('removed_gift_incentive_ids', []));
        if ($walletCredits->isNotEmpty() && $preview['totals']['wallet_used'] <= 0) {
            return response()->json(['message' => 'إجمالي الطلب يجب أن يكون أكبر من رصيد المحفظة المستخدم'], 422);
        }
        // تحقق من الستوك لكل منتج في الكارت
        foreach ($cartItems as $cartItem) {
            $stockRows = DB::connection('oracle_sales')
                ->table('online_app_stock')
                ->where('product_id', $cartItem->product_id)
                ->whereIn('warehouse_id', $warehouseIds)
                ->get();

            if (!WarehouseIds::isAvailableInAnyWarehouse($stockRows, $warehouseIds)) {
                $product = DB::connection('oracle_lmidc')
                    ->table('to_sfa_products_android')
                    ->where('product_id', $cartItem->product_id)
                    ->first();

                return response()->json([
                    'message' => 'المنتج ' . ($product ? $product->product_ename : $cartItem->product_id) . ' out of stock',
                ], 400);
            }
        }

        $total_price = $preview['totals']['grand_total'];
        $items = collect(array_merge($preview['items'], $preview['gift_items']))->map(function ($line) {
            return [
                'product_id' => $line['product_id'], 'quantity' => $line['quantity'],
                'unit_price' => $line['unit_price'], 'unit_tax' => $line['tax'],
                'unit_price_after_tax' => $line['total'] / max(0.000001, (float) $line['quantity']),
                'total_price' => $line['total'], 'discount_applied' => $line['discount'],
                'is_gift' => !empty($line['is_gift']) ? 1 : 0,
                'source_incentive_id' => $line['source_incentive_id'] ?? null,
            ];
        })->all();

        $order_id = DB::connection('oracle_sales')->table('orders_online_app')->insertGetId([
            'user_id' => $user_id,
            'pos_code' => $posCode,
            'total_price' => round($total_price, 2), 'status' => 1, 'created_at' => now(),
        ]);
        try {
            $incentives->redeemForOrder($request->user(), $order_id, $preview, $walletCredits);
        } catch (\Throwable $e) {
            DB::connection('oracle_sales')->table('orders_online_app')->where('id', $order_id)->update(['status' => 6]);
            return response()->json(['message' => 'تعذر استخدام الحافز أو رصيد المحفظة، حاول مرة أخرى'], 409);
        }
        foreach ($items as $item) {
            $item['order_id'] = $order_id;
            DB::connection('oracle_sales')
                ->table('order_items_online_app')
                ->insert($item);
        }

        DB::connection('oracle_sales')
            ->table('cart_online_app')
            ->where('user_id', $user_id)
            ->where('pos_code', $posCode)
            ->delete();

        // احتساب النقاط بناءً على قواعد الأقسام
        $earnedPoints = 0;
        try {
            $activeRules = DB::connection('oracle_sales')
                ->table('online_app_points_rules')
                ->where('is_active', 1)
                ->get();

            if ($activeRules->isNotEmpty()) {
                foreach ($items as $item) {
                    if ((int) ($item['is_gift'] ?? 0) === 1) continue;
                    $prod = DB::connection('oracle_lmidc')
                        ->table('to_sfa_products_android')
                        ->where('product_id', $item['product_id'])
                        ->first();
                    $familyId = $prod ? $prod->family_id : null;

                    // البحث عن قواعد مخصصة لهذا القسم أولاً، وإلا القواعد العامة
                    $matchedRules = $activeRules->filter(function ($r) use ($familyId) {
                        return $r->family_id == $familyId;
                    });

                    if ($matchedRules->isEmpty()) {
                        $matchedRules = $activeRules->filter(function ($r) {
                            return is_null($r->family_id);
                        });
                    }

                    foreach ($matchedRules as $rule) {
                        if ($rule->rule_type === 'quantity' && $rule->threshold > 0) {
                            $times = floor($item['quantity'] / $rule->threshold);
                            if ($times > 0) {
                                $earnedPoints += (int)($times * $rule->points);
                            }
                        } elseif ($rule->rule_type === 'amount' && $rule->threshold > 0) {
                            $times = floor($item['total_price'] / $rule->threshold);
                            if ($times > 0) {
                                $earnedPoints += (int)($times * $rule->points);
                            }
                        }
                    }
                }

                if ($earnedPoints > 0) {
                    PosPoints::change($user_id, $posCode, $earnedPoints);

                    DB::connection('oracle_sales')
                        ->table('online_app_points_history')
                        ->insert([
                            'user_id'     => $user_id,
                            'pos_code'    => $posCode,
                            'order_id'    => $order_id,
                            'gift_id'     => null,
                            'points'      => $earnedPoints,
                            'type'        => 'earned_order',
                            'description' => "مكافأة نقاط من الطلب رقم #{$order_id}",
                            'created_at'  => now(),
                        ]);
                }
            }
        } catch (\Throwable $e) {
            \Log::error('Points calculation error: ' . $e->getMessage());
        }

        // بعت notification
        $notificationService = app(\App\Services\Notification\NotificationService::class);
        $notifBody = $earnedPoints > 0
            ? "تم استلام طلبك بنجاح وجاري تجهيزه. وحصلت على {$earnedPoints} نقطة مكافأة! 🎉"
            : 'تم استلام طلبك بنجاح وجاري تجهيزه';

        $notificationService->sendNotification(
            $user_id,
            'تم تأكيد طلبك ✅',
            $notifBody,
            $posCode
        );

        return response()->json([
            'message'       => 'تم طلب الأوردر بنجاح',
            'order_id'      => $order_id,
            'earned_points' => $earnedPoints,
        ], 201);
    }

    public function getOrders(Request $request)
    {
        $user_id = $request->user()->id;
        $posCode = ActivePosCode::forUser($request->user());
        if (!$posCode) return response()->json(['message' => 'تعذر تحديد العميل النشط. سجّل الدخول مرة أخرى.'], 409);

        $orders = DB::connection('oracle_sales')
            ->table('orders_online_app')
            ->where('user_id', $user_id)
            ->where('pos_code', $posCode)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($order) {
                return [
                    'order_id'    => $order->id,
                    'status'      => match ((int)$order->status) {
                        1 => 'placed',
                        2 => 'confirmed',
                        4 => 'delivered',
                        6 => 'canceled',
                        default => 'unknown',
                    },
                    'final_price' => $order->total_price,
                    'created_at'  => $order->created_at,
                ];
            });

        return response()->json([
            'data' => $orders,
        ], 200);
    }

    public function getOrderDetails(Request $request, $order_id)
    {
        $user_id = $request->user()->id;
        $posCode = ActivePosCode::forUser($request->user());
        if (!$posCode) return response()->json(['message' => 'تعذر تحديد العميل النشط. سجّل الدخول مرة أخرى.'], 409);

        $order = DB::connection('oracle_sales')
            ->table('orders_online_app')
            ->where('id', $order_id)
            ->where('user_id', $user_id)
            ->where('pos_code', $posCode)
            ->first();

        if (!$order) {
            return response()->json([
                'message' => 'الأوردر مش موجود',
            ], 404);
        }

        $items = DB::connection('oracle_sales')
            ->table('order_items_online_app')
            ->where('order_id', $order_id)
            ->get()
            ->map(function ($item) {
                $product = DB::connection('oracle_lmidc')
                    ->table('to_sfa_products_android')
                    ->where('product_id', $item->product_id)
                    ->first();

                $image = DB::connection('oracle_sales')
                    ->table('online_app_images')
                    ->where('type', 'product')
                    ->where('ref_id', $item->product_id)
                    ->value('image_path');

                return [
                    'image'                => $image ? asset('storage/' . $image) : null,
                    'product_id'           => $item->product_id,
                    'name'                 => $product ? $product->product_ename : 'منتج محذوف',
                    'quantity'             => $item->quantity,
                    'unit_price'           => $item->unit_price,
                    'unit_tax'             => $item->unit_tax,
                    'unit_price_after_tax' => $item->unit_price_after_tax,
                    'total_price'          => $item->total_price,
                    'discount_applied'     => $item->discount_applied ?? 0,
                    'is_gift'              => $item->is_gift ?? 0,
                    'source_incentive_id'  => $item->source_incentive_id,
                ];
            });

        $walletCredits = DB::connection('oracle_lmidc')
            ->table('online_app_fix_log')
            ->where('order_id', $order_id)
            ->orderBy('redeemed_at')
            ->get()
            ->map(function ($credit) {
                return [
                    'incentive_type_id' => $credit->incentive_type_id,
                    'incentive_value' => $credit->incentive_value,
                    'from_date' => $credit->assign_from_date,
                    'to_date' => $credit->assign_to_date,
                    'status' => $credit->status,
                    'redeemed_at' => $credit->redeemed_at,
                ];
            });
        $walletUsed = round((float) $walletCredits
            ->where('status', 'USED')
            ->sum('incentive_value'), 2);

        return response()->json([
            'data' => [
                'order_id'    => $order->id,
                'status'      => match ((int)$order->status) {
                    1 => 'placed',
                    2 => 'confirmed',
                    4 => 'delivered',
                    6 => 'canceled',
                    default => 'unknown',
                },
                'final_price' => $order->total_price,
                'created_at'  => $order->created_at,
                'items'       => $items,
                'wallet_used' => $walletUsed,
                'wallet_credits' => $walletCredits,
            ],
        ], 200);
    }

    public function getUserOrdersHistory(Request $request)
    {
        $user_id = $request->user()->id;
        $posCode = ActivePosCode::forUser($request->user());
        if (!$posCode) return response()->json(['message' => 'تعذر تحديد العميل النشط. سجّل الدخول مرة أخرى.'], 409);

        $orders = DB::connection('oracle_sales')
            ->table('orders_online_app')
            ->where('user_id', $user_id)
            ->where('pos_code', $posCode)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($order) {
                $items = DB::connection('oracle_sales')
                    ->table('order_items_online_app')
                    ->where('order_id', $order->id)
                    ->get()
                    ->map(function ($item) {
                        $product = DB::connection('oracle_lmidc')
                            ->table('to_sfa_products_android')
                            ->where('product_id', $item->product_id)
                            ->first();

                        $image = DB::connection('oracle_sales')
                            ->table('online_app_images')
                            ->where('type', 'product')
                            ->where('ref_id', $item->product_id)
                            ->value('image_path');

                        return [
                            'image'                => $image ? asset('storage/' . $image) : null,
                            'product_id'           => $item->product_id,
                            'name'                 => $product ? $product->product_ename : 'منتج محذوف',
                            'quantity'             => $item->quantity,
                            'unit_price'           => $item->unit_price,
                            'unit_tax'             => $item->unit_tax,
                            'unit_price_after_tax' => $item->unit_price_after_tax,
                            'total_price'          => $item->total_price,
                    'discount_applied'     => $item->discount_applied ?? 0,
                    'is_gift'              => $item->is_gift ?? 0,
                    'source_incentive_id'  => $item->source_incentive_id,
                        ];
                    });

                return [
                    'order_id'    => $order->id,
                    'status'      => match ((int)$order->status) {
                        1 => 'placed',
                        2 => 'confirmed',
                        4 => 'delivered',
                        6 => 'canceled',
                        default => 'unknown',
                    },
                    'total_price' => $order->total_price,
                    'created_at'  => $order->created_at,
                    'items'       => $items,
                ];
            });

        return response()->json([
            'data' => $orders,
        ], 200);
    }

    public function cancelOrder(Request $request, $order_id)
    {
        $user_id = $request->user()->id;
        $posCode = ActivePosCode::forUser($request->user());
        if (!$posCode) return response()->json(['message' => 'تعذر تحديد العميل النشط. سجّل الدخول مرة أخرى.'], 409);

        $order = DB::connection('oracle_sales')
            ->table('orders_online_app')
            ->where('id', $order_id)
            ->where('user_id', $user_id)
            ->where('pos_code', $posCode)
            ->first();

        if (!$order) {
            return response()->json([
                'message' => 'الأوردر مش موجود',
            ], 404);
        }

        if ($order->status != 1) {
            return response()->json([
                'message' => 'مش ممكن تلغي الأوردر ده',
            ], 400);
        }

        DB::connection('oracle_sales')
            ->table('orders_online_app')
            ->where('id', $order_id)
            ->where('user_id', $user_id)
            ->where('pos_code', $posCode)
            ->update(['status' => 6]);

        app(IncentiveService::class)->releaseForOrder($request->user(), $order_id, $posCode);

        // استرجاع وخصم النقاط المكتسبة من هذا الطلب إن وجدت
        try {
            $earnedPoints = DB::connection('oracle_sales')
                ->table('online_app_points_history')
                ->where('order_id', $order_id)
                ->where('user_id', $user_id)
                ->where('pos_code', $posCode)
                ->where('type', 'earned_order')
                ->sum('points');

            if ($earnedPoints > 0) {
                PosPoints::change($user_id, $posCode, -$earnedPoints);

                DB::connection('oracle_sales')
                    ->table('online_app_points_history')
                    ->insert([
                        'user_id'     => $user_id,
                        'pos_code'    => $posCode,
                        'order_id'    => $order_id,
                        'gift_id'     => null,
                        'points'      => -$earnedPoints,
                        'type'        => 'order_canceled',
                        'description' => "خصم نقاط لإلغاء الطلب رقم #{$order_id}",
                        'created_at'  => now(),
                    ]);
            }
        } catch (\Throwable $e) {
            \Log::error('Cancel order points rollback error: ' . $e->getMessage());
        }

        // بعت notification
        $notificationService = app(\App\Services\Notification\NotificationService::class);
        $notificationService->sendNotification(
            $user_id,
            'تم إلغاء طلبك ❌',
            'تم إلغاء طلبك بنجاح',
            $posCode
        );

        return response()->json([
            'message' => 'تم إلغاء الأوردر بنجاح',
        ], 200);
    }
}
