<?php

namespace App\Services\Order\Impl;

use App\Services\Order\OrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderServiceImpl implements OrderService
{
    public function placeOrder(Request $request)
    {
        $user_id = $request->user()->id;

        $cartItems = DB::connection('oracle_sales')
            ->table('cart_online_app')
            ->where('user_id', $user_id)
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

        // تحقق من الستوك لكل منتج في الكارت
        foreach ($cartItems as $cartItem) {
            $stock = DB::connection('oracle_sales')
                ->table('online_app_stock')
                ->where('product_id', $cartItem->product_id)
                ->where('warehouse_id', $user->warehouse_id)
                ->first();

            if ($stock && !$stock->in_stock) {
                $product = DB::connection('oracle_lmidc')
                    ->table('to_sfa_products_android')
                    ->where('product_id', $cartItem->product_id)
                    ->first();

                return response()->json([
                    'message' => 'المنتج ' . ($product ? $product->product_ename : $cartItem->product_id) . ' out of stock',
                ], 400);
            }
        }

        $total_price = 0;
        $items = [];

        foreach ($cartItems as $cartItem) {
            $price = DB::connection('oracle_lmidc')
                ->table('product_price_list')
                ->where('product_id', $cartItem->product_id)
                ->where('line_price_id', 1)
                ->first();

            $unit_price           = round($price->pricelist_carton, 1);
            $unit_tax             = round(($price->pricelist_carton * ($price->tax_percentage / 100)) + $price->product_tax, 1);
            $unit_price_after_tax = round($unit_price + $unit_tax, 1);
            $item_total           = round($unit_price_after_tax * $cartItem->quantity, 1);
            $total_price         += $item_total;

            $items[] = [
                'product_id'           => $cartItem->product_id,
                'quantity'             => $cartItem->quantity,
                'unit_price'           => $unit_price,
                'unit_tax'             => $unit_tax,
                'unit_price_after_tax' => $unit_price_after_tax,
                'total_price'          => $item_total,
            ];
        }

        $order_id = DB::connection('oracle_sales')
            ->table('orders_online_app')
            ->insertGetId([
                'user_id'     => $user_id,
                'total_price' => round($total_price, 1),
                'status'      => 1,
                'created_at'  => now(),
            ]);

        foreach ($items as $item) {
            $item['order_id'] = $order_id;
            DB::connection('oracle_sales')
                ->table('order_items_online_app')
                ->insert($item);
        }

        DB::connection('oracle_sales')
            ->table('cart_online_app')
            ->where('user_id', $user_id)
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
                    DB::connection('oracle_sales')
                        ->table('online_app_users')
                        ->where('id', $user_id)
                        ->increment('points', $earnedPoints);

                    DB::connection('oracle_sales')
                        ->table('online_app_points_history')
                        ->insert([
                            'user_id'     => $user_id,
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
            $notifBody
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

        $orders = DB::connection('oracle_sales')
            ->table('orders_online_app')
            ->where('user_id', $user_id)
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

        $order = DB::connection('oracle_sales')
            ->table('orders_online_app')
            ->where('id', $order_id)
            ->where('user_id', $user_id)
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
                ];
            });

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
            ],
        ], 200);
    }

    public function getUserOrdersHistory(Request $request)
    {
        $user_id = $request->user()->id;

        $orders = DB::connection('oracle_sales')
            ->table('orders_online_app')
            ->where('user_id', $user_id)
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

        $order = DB::connection('oracle_sales')
            ->table('orders_online_app')
            ->where('id', $order_id)
            ->where('user_id', $user_id)
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
            ->update(['status' => 6]);

        // استرجاع وخصم النقاط المكتسبة من هذا الطلب إن وجدت
        try {
            $earnedPoints = DB::connection('oracle_sales')
                ->table('online_app_points_history')
                ->where('order_id', $order_id)
                ->where('user_id', $user_id)
                ->where('type', 'earned_order')
                ->sum('points');

            if ($earnedPoints > 0) {
                DB::connection('oracle_sales')
                    ->table('online_app_users')
                    ->where('id', $user_id)
                    ->decrement('points', $earnedPoints);

                DB::connection('oracle_sales')
                    ->table('online_app_points_history')
                    ->insert([
                        'user_id'     => $user_id,
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
            'تم إلغاء طلبك بنجاح'
        );

        return response()->json([
            'message' => 'تم إلغاء الأوردر بنجاح',
        ], 200);
    }
}
