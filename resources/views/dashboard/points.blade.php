@extends('dashboard.layout')

@section('content')

@if(session('success'))
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fas fa-check-circle me-1"></i> {{ session('success') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif

@if(session('error'))
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-circle me-1"></i> {{ session('error') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif

@if($errors->any())
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <ul class="mb-0">
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="fas fa-coins text-warning me-2"></i> إدارة نظام النقاط والمكافآت</h4>
</div>

<!-- Nav Tabs -->
<ul class="nav nav-pills mb-4 bg-white p-2 rounded shadow-sm" id="pointsTabs" role="tablist">
    <li class="nav-item">
        <button class="nav-link active fw-bold" id="rules-tab" data-bs-toggle="pill" data-bs-target="#rules" type="button">
            <i class="fas fa-sliders-h me-1"></i> قواعد احتساب النقاط
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link fw-bold" id="settings-tab" data-bs-toggle="pill" data-bs-target="#settings" type="button">
            <i class="fas fa-history me-1"></i> دورة التصفير وسياسة النقاط
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link fw-bold" id="gifts-tab" data-bs-toggle="pill" data-bs-target="#gifts" type="button">
            <i class="fas fa-gift me-1"></i> الهدايا والمكافآت
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link fw-bold" id="redemptions-tab" data-bs-toggle="pill" data-bs-target="#redemptions" type="button">
            <i class="fas fa-clipboard-check me-1"></i> طلبات استبدال الهدايا
            @php
                $pendingCount = $redemptions->where('status', 'pending')->count();
            @endphp
            @if($pendingCount > 0)
                <span class="badge bg-danger ms-1">{{ $pendingCount }}</span>
            @endif
        </button>
    </li>
</ul>

<div class="tab-content" id="pointsTabsContent">
    <!-- 1. تبويب القواعد -->
    <div class="tab-pane fade show active" id="rules">
        <div class="row">
            <!-- إضافة قاعدة جديدة -->
            <div class="col-md-4 mb-4">
                <div class="card shadow-sm border-0">
                    <div class="card-header bg-primary text-white">
                        <h6 class="mb-0"><i class="fas fa-plus-circle me-1"></i> إضافة شرط ونقاط للقسم</h6>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="{{ asset('dashboard/points/addRule') }}">
                            @csrf
                            <div class="mb-3">
                                <label class="form-label fw-bold">القسم / التصنيف</label>
                                <select name="family_id" class="form-select" required>
                                    <option value="all">-- كل الأقسام (عام) --</option>
                                    @foreach($categories as $cat)
                                        <option value="{{ $cat->family_id }}">{{ $cat->name }} (#{{ $cat->family_id }})</option>
                                    @endforeach
                                </select>
                                <small class="text-muted">اختر قسماً معيناً أو اتركه عاماً لكل الأقسام</small>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-bold">نوع الشرط</label>
                                <select name="rule_type" id="ruleTypeSelect" class="form-select" required>
                                    <option value="quantity">حسب الكمية (عدد الكراتين / القطع)</option>
                                    <option value="amount">حسب القيمة الشرائية (المبلغ بالجنيه)</option>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-bold" id="thresholdLabel">الحد الأدنى للكمية</label>
                                <div class="input-group">
                                    <input type="number" step="0.1" name="threshold" class="form-control" placeholder="مثلاً: 1" required>
                                    <span class="input-group-text" id="thresholdUnit">كرتونة</span>
                                </div>
                                <small class="text-muted" id="thresholdHelp">مثال: لو اشترى 1 كرتونة من هذا القسم</small>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-bold">النقاط الممنوحة</label>
                                <div class="input-group">
                                    <input type="number" name="points" class="form-control" placeholder="مثلاً: 10" required min="1">
                                    <span class="input-group-text">نقطة</span>
                                </div>
                                <small class="text-muted">النقاط المكتسبة لكل تكرار للمعيار</small>
                            </div>

                            <button type="submit" class="btn btn-primary w-100">
                                <i class="fas fa-save me-1"></i> حفظ القاعدة
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- جدول القواعد الحالية -->
            <div class="col-md-8">
                <div class="card shadow-sm border-0">
                    <div class="card-header bg-white">
                        <h6 class="mb-0 fw-bold"><i class="fas fa-list me-1"></i> القواعد النشطة والمطبقة</h6>
                    </div>
                    <div class="card-body p-0">
                        @if($rules->isEmpty())
                            <div class="p-4 text-center text-muted">
                                <i class="fas fa-info-circle fa-2x mb-2 d-block"></i>
                                لا توجد قواعد نقاط مسجلة حتى الآن. أضف أول قاعدة من النموذج المجاور.
                            </div>
                        @else
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>القسم</th>
                                            <th>نوع الشرط</th>
                                            <th>المعيار</th>
                                            <th>النقاط</th>
                                            <th>الحالة</th>
                                            <th>إجراءات</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($rules as $rule)
                                        <tr>
                                            <td class="fw-bold">
                                                {{ $rule->category_name }}
                                            </td>
                                            <td>
                                                @if($rule->rule_type == 'quantity')
                                                    <span class="badge bg-info text-dark"><i class="fas fa-box"></i> كمية</span>
                                                @else
                                                    <span class="badge bg-success"><i class="fas fa-money-bill-wave"></i> مبلغ إجمالي</span>
                                                @endif
                                            </td>
                                            <td>
                                                كل <strong>{{ (float)$rule->threshold }}</strong> 
                                                {{ $rule->rule_type == 'quantity' ? 'كرتونة' : 'جنيه' }}
                                            </td>
                                            <td>
                                                <span class="badge bg-warning text-dark fs-6">+{{ $rule->points }} نقطة</span>
                                            </td>
                                            <td>
                                                @if($rule->is_active)
                                                    <span class="badge bg-success">نشطة</span>
                                                @else
                                                    <span class="badge bg-secondary">معطلة</span>
                                                @endif
                                            </td>
                                            <td>
                                                <div class="d-flex gap-1">
                                                    <form method="POST" action="{{ asset('dashboard/points/toggleRule/' . $rule->id) }}">
                                                        @csrf
                                                        <button type="submit" class="btn btn-sm {{ $rule->is_active ? 'btn-outline-secondary' : 'btn-outline-success' }}" title="تغيير الحالة">
                                                            <i class="fas {{ $rule->is_active ? 'fa-pause' : 'fa-play' }}"></i>
                                                        </button>
                                                    </form>
                                                    <form method="POST" action="{{ asset('dashboard/points/deleteRule/' . $rule->id) }}">
                                                        @csrf
                                                        <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('هل أنت متأكد من حذف هذه القاعدة؟')" title="حذف">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 2. تبويب دورة التصفير وسياسة النقاط -->
    <div class="tab-pane fade" id="settings">
        <div class="row">
            <div class="col-md-7 mb-4">
                <div class="card shadow-sm border-0">
                    <div class="card-header bg-white">
                        <h6 class="mb-0 fw-bold"><i class="fas fa-clock me-1"></i> إعدادات مدة صلاحية النقاط والدورة</h6>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="{{ asset('dashboard/points/updateSettings') }}">
                            @csrf
                            <div class="mb-3">
                                <label class="form-label fw-bold">فترة صلاحية وتصفير النقاط (بالأشهر)</label>
                                <div class="input-group">
                                    <input type="number" name="reset_months" class="form-control" value="{{ $settings['reset_months'] }}" min="1" max="36" required>
                                    <span class="input-group-text">أشهر</span>
                                </div>
                                <small class="text-muted">المدة التي تنتهي بعدها نقاط التاجر وتبدأ دورة جديدة (مثلاً: 6 شهور).</small>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-bold">تاريخ التصفير القادم للدورة الحالية</label>
                                <input type="date" name="next_reset_date" class="form-control" value="{{ $settings['next_reset_date'] }}" required>
                                <small class="text-muted">هذا التاريخ سيظهر للتجار في تطبيق الموبايل لتنبيههم بموعد انتهاء صلاحية النقاط الحالية.</small>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-bold">نص سياسة وشروط النقاط (يظهر في التطبيق)</label>
                                <textarea name="policy_text_ar" class="form-control" rows="4" placeholder="اكتب هنا تفاصيل الشروط وسياسة النقاط...">{{ $settings['policy_text_ar'] }}</textarea>
                            </div>

                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save me-1"></i> حفظ إعدادات الدورة
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-md-5">
                <div class="card shadow-sm border-0 border-danger">
                    <div class="card-header bg-danger text-white">
                        <h6 class="mb-0"><i class="fas fa-exclamation-triangle me-1"></i> الإجراءات الحرجة (تصفير يدوي)</h6>
                    </div>
                    <div class="card-body">
                        <p class="text-muted">
                            يمكنك في أي وقت تصفير نقاط جميع العملاء يدوياً لإنهاء الدورة الحالية فوراً وبدء دورة جديدة.
                        </p>
                        <div class="alert alert-warning">
                            <small>
                                <strong>تنبيه:</strong> سيتم تصفير رصيد جميع التجار في التطبيق وتسجيل حركة تصفير لانتهاء الدورة في سجلاتهم.
                            </small>
                        </div>
                        <form method="POST" action="{{ asset('dashboard/points/resetAllPoints') }}">
                            @csrf
                            <button type="submit" class="btn btn-outline-danger w-100 fw-bold" onclick="return confirm('⚠️ هل أنت متأكد تماماً من تصفير رصيد جميع العملاء الآن؟ لا يمكن التراجع عن هذا الإجراء!')">
                                <i class="fas fa-bomb me-1"></i> تصفير جميع النقاط الآن
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 3. تبويب الهدايا والمكافآت -->
    <div class="tab-pane fade" id="gifts">
        <div class="row">
            <!-- إضافة هدية -->
            <div class="col-md-4 mb-4">
                <div class="card shadow-sm border-0">
                    <div class="card-header bg-success text-white">
                        <h6 class="mb-0"><i class="fas fa-plus-circle me-1"></i> إضافة هدية ومكافأة جديدة</h6>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="{{ asset('dashboard/points/addGift') }}" enctype="multipart/form-data">
                            @csrf
                            <div class="mb-3">
                                <label class="form-label fw-bold">اسم الهدية</label>
                                <input type="text" name="title" class="form-control" placeholder="مثلاً: قسيمة شراء 500 جنيه أو شاشة 32 بوصة" required>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-bold">النقاط المطلوبة للاستبدال</label>
                                <div class="input-group">
                                    <input type="number" name="points_required" class="form-control" placeholder="مثلاً: 500" min="1" required>
                                    <span class="input-group-text">نقطة</span>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-bold">الوصف والتفاصيل</label>
                                <textarea name="description" class="form-control" rows="3" placeholder="وصف للهدية وكيفية استلامها..."></textarea>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-bold">صورة الهدية</label>
                                <input type="file" name="image" class="form-control" accept="image/*">
                            </div>

                            <button type="submit" class="btn btn-success w-100">
                                <i class="fas fa-plus me-1"></i> إضافة الهدية
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- قائمة الهدايا -->
            <div class="col-md-8">
                <div class="card shadow-sm border-0">
                    <div class="card-header bg-white">
                        <h6 class="mb-0 fw-bold"><i class="fas fa-gifts me-1"></i> الهدايا المتاحة في التطبيق</h6>
                    </div>
                    <div class="card-body">
                        @if($gifts->isEmpty())
                            <div class="p-4 text-center text-muted">
                                <i class="fas fa-gift fa-2x mb-2 d-block"></i>
                                لم يتم إضافة أي هدايا حتى الآن.
                            </div>
                        @else
                            <div class="row">
                                @foreach($gifts as $gift)
                                <div class="col-md-6 mb-3">
                                    <div class="card h-100 border {{ $gift->is_active ? 'border-success' : 'border-secondary' }} shadow-none">
                                        <div class="card-body d-flex flex-column">
                                            <div class="d-flex align-items-center mb-3">
                                                @if($gift->image)
                                                    <img src="{{ asset('storage/' . $gift->image) }}" class="rounded me-3" style="width: 70px; height: 70px; object-fit: cover;" onerror="this.onerror=null; this.outerHTML='<div class=\'bg-light rounded me-3 d-flex align-items-center justify-content-center\' style=\'width: 70px; height: 70px;\'><i class=\'fas fa-gift fa-2x text-warning\'></i></div>';">
                                                @else
                                                    <div class="bg-light rounded me-3 d-flex align-items-center justify-content-center" style="width: 70px; height: 70px;">
                                                        <i class="fas fa-gift fa-2x text-muted"></i>
                                                    </div>
                                                @endif
                                                <div>
                                                    <h6 class="fw-bold mb-1">{{ $gift->title }}</h6>
                                                    <span class="badge bg-warning text-dark fs-6">{{ number_format($gift->points_required) }} نقطة</span>
                                                </div>
                                            </div>
                                            <p class="text-muted small flex-grow-1 mb-2">{{ $gift->description ?? 'بدون وصف' }}</p>
                                            <div class="d-flex justify-content-between align-items-center pt-2 border-top">
                                                <span class="badge {{ $gift->is_active ? 'bg-success' : 'bg-secondary' }}">
                                                    {{ $gift->is_active ? 'متاحة للعملاء' : 'معطلة' }}
                                                </span>
                                                <div class="d-flex gap-1">
                                                    <form method="POST" action="{{ asset('dashboard/points/toggleGift/' . $gift->id) }}">
                                                        @csrf
                                                        <button type="submit" class="btn btn-sm btn-outline-secondary">
                                                            <i class="fas {{ $gift->is_active ? 'fa-eye-slash' : 'fa-eye' }}"></i>
                                                        </button>
                                                    </form>
                                                    <form method="POST" action="{{ asset('dashboard/points/deleteGift/' . $gift->id) }}">
                                                        @csrf
                                                        <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('حذف هذه الهدية نهائياً؟')">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 4. تبويب طلبات استبدال الهدايا -->
    <div class="tab-pane fade" id="redemptions">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white">
                <h6 class="mb-0 fw-bold"><i class="fas fa-tasks me-1"></i> طلبات استبدال الهدايا الواردة من التجار</h6>
            </div>
            <div class="card-body p-0">
                @if($redemptions->isEmpty())
                    <div class="p-4 text-center text-muted">
                        <i class="fas fa-inbox fa-2x mb-2 d-block"></i>
                        لا توجد أي طلبات استبدال حتى الآن.
                    </div>
                @else
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>رقم الطلب</th>
                                    <th>موبايل العميل</th>
                                    <th>الهدية المطلوبة</th>
                                    <th>النقاط المخصومة</th>
                                    <th>تاريخ الطلب</th>
                                    <th>الحالة</th>
                                    <th>إجراء الإدارة</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($redemptions as $r)
                                <tr>
                                    <td>#{{ $r->id }}</td>
                                    <td>
                                        <i class="fas fa-phone-alt text-muted me-1"></i> {{ $r->user_mobile ?? 'عميل #'.$r->user_id }}
                                    </td>
                                    <td>
                                        @if($r->gift_image)
                                            <img src="{{ asset('storage/' . $r->gift_image) }}" class="rounded me-1" style="width: 30px; height: 30px; object-fit: cover;" onerror="this.onerror=null; this.style.display='none';">
                                        @endif
                                        <strong>{{ $r->gift_title ?? 'هدية محذوفة' }}</strong>
                                    </td>
                                    <td>
                                        <span class="badge bg-danger">{{ number_format($r->points_spent) }} نقطة</span>
                                    </td>
                                    <td>
                                        {{ \Carbon\Carbon::parse($r->created_at)->format('Y-m-d H:i') }}
                                    </td>
                                    <td>
                                        @if($r->status == 'pending')
                                            <span class="badge bg-warning text-dark"><i class="fas fa-clock"></i> قيد المراجعة</span>
                                        @elseif($r->status == 'approved')
                                            <span class="badge bg-success"><i class="fas fa-check"></i> تم التسليم/الاعتماد</span>
                                        @elseif($r->status == 'rejected')
                                            <span class="badge bg-danger"><i class="fas fa-times"></i> تم الرفض (ورُدّت النقاط)</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if($r->status == 'pending')
                                            <div class="d-flex gap-1">
                                                <form method="POST" action="{{ asset('dashboard/points/updateRedemptionStatus/' . $r->id) }}">
                                                    @csrf
                                                    <input type="hidden" name="status" value="approved">
                                                    <button type="submit" class="btn btn-sm btn-success" onclick="return confirm('اعتماد تسليم هذه الهدية للعميل؟')">
                                                        <i class="fas fa-check me-1"></i> اعتماد
                                                    </button>
                                                </form>
                                                <form method="POST" action="{{ asset('dashboard/points/updateRedemptionStatus/' . $r->id) }}">
                                                    @csrf
                                                    <input type="hidden" name="status" value="rejected">
                                                    <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('رفض الطلب وإعادة {{ $r->points_spent }} نقطة لحساب العميل؟')">
                                                        <i class="fas fa-times me-1"></i> رفض
                                                    </button>
                                                </form>
                                            </div>
                                        @else
                                            <span class="text-muted small">مكتمل</span>
                                        @endif
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const ruleTypeSelect = document.getElementById('ruleTypeSelect');
        const thresholdLabel = document.getElementById('thresholdLabel');
        const thresholdUnit = document.getElementById('thresholdUnit');
        const thresholdHelp = document.getElementById('thresholdHelp');

        ruleTypeSelect.addEventListener('change', function() {
            if (this.value === 'quantity') {
                thresholdLabel.textContent = 'الحد الأدنى للكمية';
                thresholdUnit.textContent = 'كرتونة';
                thresholdHelp.textContent = 'مثال: لو اشترى 1 كرتونة من هذا القسم';
            } else {
                thresholdLabel.textContent = 'الحد الأدنى للقيمة الشرائية';
                thresholdUnit.textContent = 'جنيه';
                thresholdHelp.textContent = 'مثال: لو اشترى بقيمة 2000 جنيه من هذا القسم';
            }
        });
    });
</script>

@endsection
