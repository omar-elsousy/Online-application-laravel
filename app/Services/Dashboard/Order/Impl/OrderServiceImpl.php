<?php

namespace App\Services\Dashboard\Order\Impl;

use App\Services\Dashboard\Order\OrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderServiceImpl implements OrderService
{
    public function orders(Request $request)
    {
        $query = DB::connection('oracle_sales')
                    ->table('orders_online_app')
                    ->orderBy('created_at', 'desc');

        if ($request->search) {
            $query->where('id', $request->search)
                  ->orWhere('user_id', $request->search);
        }

        $orders = $query->get();

        return view('dashboard.orders', compact('orders'));
    }

    public function orderDetails(Request $request, $order_id)
    {
        $order = DB::connection('oracle_sales')
                    ->table('orders_online_app')
                    ->where('id', $order_id)
                    ->first();

        if (!$order) {
            return redirect(asset('dashboard/orders'));
        }

        $items = DB::connection('oracle_sales')
                    ->table('order_items_online_app')
                    ->where('order_id', $order_id)
                    ->get()
                    ->map(function($item) {
                        $product = DB::connection('oracle_lmidc')
                                        ->table('to_sfa_products_android')
                                        ->where('product_id', $item->product_id)
                                        ->first();

                        return [
                            'product_id'           => $item->product_id,
                            'name'                 => $product ? $product->product_ename : 'منتج محذوف',
                            'quantity'             => $item->quantity,
                            'unit_price'           => $item->unit_price,
                            'unit_tax'             => $item->unit_tax,
                            'unit_price_after_tax' => $item->unit_price_after_tax,
                            'total_price'          => $item->total_price,
                        ];
                    });

        return view('dashboard.orderDetails', compact('order', 'items'));
    }

    public function cancelOrder(Request $request, $order_id)
    {
        $order = DB::connection('oracle_sales')
                    ->table('orders_online_app')
                    ->where('id', $order_id)
                    ->first();

        if (!$order || $order->status != 1) {
            return redirect(asset('dashboard/orders'));
        }

        DB::connection('oracle_sales')
            ->table('orders_online_app')
            ->where('id', $order_id)
            ->update(['status' => 6]);

        try {
            $earnedPoints = DB::connection('oracle_sales')
                ->table('online_app_points_history')
                ->where('order_id', $order_id)
                ->where('user_id', $order->user_id)
                ->where('type', 'earned_order')
                ->sum('points');

            if ($earnedPoints > 0) {
                DB::connection('oracle_sales')
                    ->table('online_app_users')
                    ->where('id', $order->user_id)
                    ->decrement('points', $earnedPoints);

                DB::connection('oracle_sales')
                    ->table('online_app_points_history')
                    ->insert([
                        'user_id'     => $order->user_id,
                        'order_id'    => $order_id,
                        'gift_id'     => null,
                        'points'      => -$earnedPoints,
                        'type'        => 'order_canceled',
                        'description' => "خصم نقاط لإلغاء الطلب من لوحة التحكم رقم #{$order_id}",
                        'created_at'  => now(),
                    ]);
            }
        } catch (\Throwable $e) {
            \Log::error('Dashboard cancel order points rollback error: ' . $e->getMessage());
        }

        return redirect(asset('dashboard/orders'));

    }
}