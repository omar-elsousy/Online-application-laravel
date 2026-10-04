@extends('dashboard.layout')

@section('content')
<div class="card shadow-sm border-0">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h5 class="mb-0">الأوردرات</h5>
            <form method="GET" action="{{ asset('dashboard/orders') }}" class="d-flex gap-2">
                <input type="text" name="search" class="form-control" placeholder="ابحث برقم الأوردر أو العميل" value="{{ request('search') }}">
                <button type="submit" class="btn btn-primary">بحث</button>
            </form>
        </div>

        <table class="table table-hover">
            <thead class="table-light">
                <tr>
                    <th>رقم الأوردر</th>
                    <th>رقم العميل</th>
                    <th>كود العميل</th>
                    <th>السعر الكلي</th>
                    <th>الحالة</th>
                    <th>التاريخ</th>
                    <th>تفاصيل</th>
                </tr>
            </thead>
            <tbody>
                @foreach($orders as $order)
                <tr>
                    <td>{{ $order->id }}</td>
                    <td>{{ $order->user_id }}</td>
                    <td>{{ $order->pos_code ?? 'قديم / غير محدد' }}</td>
                    <td>{{ number_format($order->total_price, 1) }}</td>
                    <td>
                        @if($order->status == 'placed')
                            <span class="badge bg-warning">placed</span>
                        @elseif($order->status == 'canceled')
                            <span class="badge bg-danger">canceled</span>
                        @endif
                    </td>
                    <td>{{ $order->created_at }}</td>
                    <td>
                        <a href="{{ asset('dashboard/orderDetails') }}/{{ $order->id }}" class="btn btn-sm btn-info">تفاصيل</a>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endsection
