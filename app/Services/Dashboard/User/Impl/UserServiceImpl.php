<?php

namespace App\Services\Dashboard\User\Impl;

use App\Services\Dashboard\User\UserService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserServiceImpl implements UserService
{
    public function users(Request $request)
    {
        $query = DB::connection('oracle_sales')
                    ->table('online_app_users')
                    ->orderBy('created_at', 'desc');

        if ($request->search) {
            $query->where('mobile', 'like', '%' . $request->search . '%');
        }

        $users = $query->get()->map(function($user) {
            $placedOrders = DB::connection('oracle_sales')
                                ->table('orders_online_app')
                                ->where('user_id', $user->id)
                                ->where('status', 1)
                                ->count();

            $canceledOrders = DB::connection('oracle_sales')
                                ->table('orders_online_app')
                                ->where('user_id', $user->id)
                                ->where('status', 6)
                                ->count();

            $pos = DB::connection('oracle_lmidc')
                        ->table('pos')
                        ->where('mobile', $user->mobile)
                        ->first();

            return [
                'id'              => $user->id,
                'mobile'          => $user->mobile,
                'name'            => $pos ? $pos->name : '-',
                'pos_code'        => $pos ? $pos->ter_id . '_' . $pos->pos_id : '-',
                'is_blocked'      => $user->is_blocked,
                'created_at'      => $user->created_at,
                'placed_orders'   => $placedOrders,
                'canceled_orders' => $canceledOrders,
            ];
        });

        return view('dashboard.users', compact('users'));
    }

    public function deleteUser(Request $request, $user_id)
    {
        DB::connection('oracle_sales')
            ->table('online_app_users')
            ->where('id', $user_id)
            ->delete();

        return redirect(asset('dashboard/users'));
    }

    public function blockUser(Request $request, $user_id)
    {
        DB::connection('oracle_sales')
            ->table('online_app_users')
            ->where('id', $user_id)
            ->update(['is_blocked' => 1]);

        return redirect(asset('dashboard/users'));
    }

    public function unblockUser(Request $request, $user_id)
    {
        DB::connection('oracle_sales')
            ->table('online_app_users')
            ->where('id', $user_id)
            ->update(['is_blocked' => 0]);

        return redirect(asset('dashboard/users'));
    }
}