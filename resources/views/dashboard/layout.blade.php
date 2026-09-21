<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>لوحة التحكم</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: #f4f6f9; }
        .sidebar {
            min-height: 100vh;
            background: #2c3e50;
            width: 250px;
            position: fixed;
            top: 0;
            right: 0;
        }
        .sidebar a {
            color: #bdc3c7;
            text-decoration: none;
            display: block;
            padding: 12px 20px;
            transition: 0.2s;
        }
        .sidebar a:hover, .sidebar a.active {
            background: #34495e;
            color: #fff;
        }
        .sidebar .brand {
            color: #fff;
            font-size: 20px;
            padding: 20px;
            border-bottom: 1px solid #34495e;
        }
        .main-content {
            margin-right: 250px;
            padding: 20px;
        }
        .topbar {
            background: #fff;
            padding: 15px 20px;
            margin-bottom: 20px;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar">
        <div class="brand">
            <i class="fas fa-store me-2"></i> لوحة التحكم
        </div>
        <a href="{{ asset('dashboard/home') }}" class="{{ request()->is('dashboard/home') ? 'active' : '' }}">
            <i class="fas fa-chart-pie me-2"></i> الرئيسية
        </a>
        <a href="{{ asset('dashboard/orders') }}" class="{{ request()->is('dashboard/orders*') ? 'active' : '' }}">
            <i class="fas fa-box me-2"></i> الأوردرات
        </a>
        <a href="{{ asset('dashboard/images') }}" class="{{ request()->is('dashboard/images*') ? 'active' : '' }}">
            <i class="fas fa-image me-2"></i> الصور
        </a>
        <a href="{{ asset('dashboard/sections') }}" class="{{ request()->is('dashboard/sections*') ? 'active' : '' }}">
            <i class="fas fa-th-large me-2"></i> الاعلانات
        </a>
        <a href="{{ asset('dashboard/visibility') }}" class="{{ request()->is('dashboard/visibility*') ? 'active' : '' }}">
            <i class="fas fa-eye me-2"></i> إظهار / إخفاء
        </a>
        <a href="{{ asset('dashboard/latestOffers') }}" class="{{ request()->is('dashboard/latestOffers*') ? 'active' : '' }}">
            <i class="fas fa-bolt me-2"></i> أحدث العروض
        </a>
        <a href="{{ asset('dashboard/notifications') }}" class="{{ request()->is('dashboard/notifications*') ? 'active' : '' }}">
            <i class="fas fa-bell me-2"></i> الإشعارات
        </a>
        <a href="{{ asset('dashboard/stock') }}" class="{{ request()->is('dashboard/stock*') ? 'active' : '' }}">
            <i class="fas fa-boxes me-2"></i> الستوك
        </a>
        <a href="{{ asset('dashboard/users') }}" class="{{ request()->is('dashboard/users*') ? 'active' : '' }}">
            <i class="fas fa-users me-2"></i> العملاء
        </a>
        <a href="{{ asset('dashboard/points') }}" class="{{ request()->is('dashboard/points*') ? 'active' : '' }}">
            <i class="fas fa-coins me-2"></i> نظام النقاط
        </a>
        @if(session('admin')->role == 'super_admin')
        <a href="{{ asset('dashboard/admins') }}" class="{{ request()->is('dashboard/admins*') ? 'active' : '' }}">
            <i class="fas fa-user-shield me-2"></i> الأدمنز
        </a>
        @endif
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <div class="topbar">
            <span>أهلاً {{ session('admin')->mobile }}</span>
            <form method="POST" action="{{ asset('dashboard/logout') }}">
                @csrf
                <button type="submit" class="btn btn-sm btn-danger">
                    <i class="fas fa-sign-out-alt me-1"></i> خروج
                </button>
            </form>
        </div>

        @yield('content')
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>