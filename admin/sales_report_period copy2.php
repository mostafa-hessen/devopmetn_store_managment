<?php
// report_day.php — تقرير مبيعات شامل مع فلاتر ذكية
$page_title = "تقرير المبيعات المتقدم";
$class_dashboard = "active";

require_once dirname(__DIR__) . '/config.php';
require_once BASE_DIR . 'partials/session_admin.php';

date_default_timezone_set('Africa/Cairo');

$message = "";
$sales_data = [];
$total_invoices_period = 0;
$total_sales_amount_period = 0;
$net_sales_after_returns = 0;

// فلتر الحالات
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$customer_filter = isset($_GET['customer']) ? trim($_GET['customer']) : '';
$work_order_filter = isset($_GET['work_order']) ? trim($_GET['work_order']) : '';
$notes_filter = isset($_GET['notes']) ? trim($_GET['notes']) : '';
$invoice_filter = isset($_GET['invoice_id']) ? trim($_GET['invoice_id']) : '';
$advanced_search = isset($_GET['advanced_search']) ? trim($_GET['advanced_search']) : '';

// إذا لم يرسل المستخدم تواريخ، اجعل الافتراضي هو اليوم
$today = date('Y-m-d');
$start_date_filter = isset($_GET['start_date']) && trim($_GET['start_date']) !== '' ? trim($_GET['start_date']) : $today;
$end_date_filter   = isset($_GET['end_date'])   && trim($_GET['end_date'])   !== '' ? trim($_GET['end_date'])   : $today;

// تحقق من صحة التاريخ
$start_ok = DateTime::createFromFormat('Y-m-d', $start_date_filter) !== false;
$end_ok   = DateTime::createFromFormat('Y-m-d', $end_date_filter) !== false;

if (!$start_ok || !$end_ok) {
    $message = "<div class='alert alert-danger'>صيغة التاريخ غير صحيحة. الرجاء استخدام YYYY-MM-DD.</div>";
} else {
    if ($start_date_filter > $end_date_filter) {
        $message = "<div class='alert alert-danger'>تاريخ البدء لا يمكن أن يكون بعد تاريخ الانتهاء.</div>";
    } else {
        $start_date_sql = $start_date_filter . " 00:00:00";
        $end_date_sql   = $end_date_filter . " 23:59:59";

        // بناء الاستعلام الديناميكي
        $sql_where = " WHERE io.delivered NOT IN ('reverted', 'canceled') 
                        AND io.created_at BETWEEN ? AND ?";
        
        $params = [$start_date_sql, $end_date_sql];
        $param_types = "ss";
        
        // فلتر الحالة
        if (!empty($status_filter)) {
            switch($status_filter) {
                case 'paid':
                    $sql_where .= " AND io.remaining_amount = 0";
                    break;
                case 'partial':
                    $sql_where .= " AND io.paid_amount > 0 AND io.remaining_amount > 0";
                    break;
                case 'pending':
                    $sql_where .= " AND io.remaining_amount > 0 AND io.paid_amount = 0";
                    break;
            }
        }
        
        // فلتر العميل (بحث نصي في الاسم)
        if (!empty($customer_filter)) {
            $sql_where .= " AND (c.name LIKE ? OR c.mobile LIKE ?)";
            $customer_like = "%{$customer_filter}%";
            $params[] = $customer_like;
            $params[] = $customer_like;
            $param_types .= "ss";
        }
        
        // فلتر الشغلانة (رقم أو اسم)
        if (!empty($work_order_filter)) {
            $sql_where .= " AND (wo.id = ? OR wo.title LIKE ?)";
            $params[] = $work_order_filter;
            $params[] = "%{$work_order_filter}%";
            $param_types .= "is";
        }
        
        // فلتر الملاحظات
        if (!empty($notes_filter)) {
            $sql_where .= " AND io.notes LIKE ?";
            $params[] = "%{$notes_filter}%";
            $param_types .= "s";
        }
        
        // فلتر رقم الفاتورة
        if (!empty($invoice_filter)) {
            $sql_where .= " AND io.id = ?";
            $params[] = $invoice_filter;
            $param_types .= "i";
        }
        
        // البحث المتقدم (يشمل كل شيء)
        if (!empty($advanced_search)) {
            $sql_where .= " AND (io.id = ? OR c.name LIKE ? OR wo.title LIKE ? OR io.notes LIKE ?)";
            $advanced_like = "%{$advanced_search}%";
            $params[] = $advanced_search;
            $params[] = $advanced_like;
            $params[] = $advanced_like;
            $params[] = $advanced_like;
            $param_types .= "isss";
        }

        // استعلام للحصول على إجمالي المرتجعات لكل فاتورة
        $sql = "SELECT
                    io.id as invoice_id,
                    io.created_at as invoice_date,
                    COALESCE(c.name, '—') as customer_name,
                    c.mobile as customer_phone,
                    io.total_before_discount,
                    io.discount_type,
                    io.discount_value,
                    io.discount_amount,
                    io.total_after_discount,
                    io.paid_amount,
                    io.remaining_amount,
                    io.notes,
                    io.delivered,
                    wo.id as work_order_id,
                    wo.title as work_order_title,
                    wo.status as work_order_status,
                    CASE 
                        WHEN io.remaining_amount = 0 THEN 'paid'
                        WHEN io.paid_amount > 0 AND io.remaining_amount > 0 THEN 'partial'
                        ELSE 'pending'
                    END AS payment_status,
                    COALESCE(SUM(r.total_amount), 0) as total_returns_amount
                FROM invoices_out io
                LEFT JOIN customers c ON io.customer_id = c.id
                LEFT JOIN work_orders wo ON io.work_order_id = wo.id
                LEFT JOIN returns r ON r.invoice_id = io.id AND r.status IN ('approved', 'completed')
                $sql_where
                GROUP BY io.id
                ORDER BY io.created_at DESC";

        if ($stmt = $conn->prepare($sql)) {
            if ($param_types !== "") {
                $stmt->bind_param($param_types, ...$params);
            }
            
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    // احسب صافي المبيعات بعد المرتجعات
                    $net_amount = floatval($row['total_after_discount']) - floatval($row['total_returns_amount']);
                    $row['net_amount'] = $net_amount;
                    
                    // احصل على تفاصيل البنود
                    $items_sql = "SELECT 
                                    ioi.*,
                                    p.name as product_name,
                                    p.code as product_code,
                                    (ioi.quantity - ioi.returned_quantity) as remaining_quantity,
                                    (ioi.total_before_discount - ioi.discount_amount) as item_net_total
                                  FROM invoice_out_items ioi
                                  JOIN products p ON ioi.product_id = p.id
                                  WHERE ioi.invoice_out_id = ? 
                                  AND ioi.returned_quantity < ioi.quantity";
                    if ($items_stmt = $conn->prepare($items_sql)) {
                        $items_stmt->bind_param("i", $row['invoice_id']);
                        $items_stmt->execute();
                        $items_result = $items_stmt->get_result();
                        $items = [];
                        $items_total = 0;
                        while ($item = $items_result->fetch_assoc()) {
                            $items[] = $item;
                            $items_total += floatval($item['item_net_total']);
                        }
                        $row['items'] = $items;
                        $row['items_total'] = $items_total;
                        $items_stmt->close();
                    }
                    
                    $sales_data[] = $row;
                    $total_sales_amount_period += floatval($row['total_after_discount']);
                    $net_sales_after_returns += $net_amount;
                }
                
                $total_invoices_period = count($sales_data);
                if ($total_invoices_period == 0) {
                    $message = "<div class='alert alert-info'>لا توجد فواتير في الفترة المحددة.</div>";
                }
            } else {
                $message = "<div class='alert alert-danger'>خطأ أثناء تنفيذ استعلام المبيعات: " . htmlspecialchars($stmt->error) . "</div>";
            }
            $stmt->close();
        } else {
            $message = "<div class='alert alert-danger'>خطأ في تحضير استعلام المبيعات: " . htmlspecialchars($conn->error) . "</div>";
        }
    }
}

// جلب قائمة العملاء للاقتراحات
$customers = [];
$customers_sql = "SELECT id, name, phone FROM customers ORDER BY name LIMIT 100";
if ($customers_result = $conn->query($customers_sql)) {
    while ($customer = $customers_result->fetch_assoc()) {
        $customers[] = $customer;
    }
}

require_once BASE_DIR . 'partials/header.php';
require_once BASE_DIR . 'partials/sidebar.php';
?>

<style>
/* تحسينات إضافية */
.report-wrap { padding: 18px 0; }

.hero {
  display:flex; justify-content:space-between; align-items:center; gap:12px;
  background: linear-gradient(90deg, rgba(11,132,255,0.04), rgba(99,102,241,0.02));
  padding:14px; border-radius:12px; box-shadow:var(--shadow-1);
}
.hero .title { font-weight:700; color:var(--text); font-size:1.1rem; }
.hero .subtitle { color:var(--muted); font-size:0.95rem; }

.toolbar { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
.btn-smth {
  border-radius:10px; padding:8px 12px; border:1px solid var(--border);
  background:transparent; color:var(--text); cursor:pointer; display:inline-flex; gap:8px; align-items:center;
  transition: transform .12s ease, box-shadow .12s ease;
}
.btn-smth.primary { background: linear-gradient(90deg,var(--primary), #5b9aff); color:#fff; border:none; box-shadow:0 8px 22px rgba(59,130,246,0.12); }
.btn-smth:active { transform: translateY(1px); }

.periods { display:flex; gap:8px; align-items:center; }
.periods button {
  background:transparent; border:1px solid var(--border); padding:8px 10px; border-radius:8px; cursor:pointer; color:var(--text);
}
.periods button.active { background:var(--primary); color:#fff; box-shadow:0 6px 18px rgba(59,130,246,0.12); transform:translateY(-2px); }

/* كروت الإحصائيات */
.kpis-wrap { display:flex; gap:12px; margin:14px 0; flex-wrap:wrap; }
.summary-card {
  position:relative;
  flex:1 1 260px;
  border-radius:12px;
  padding:18px;
  overflow:hidden;
  background:var(--surface);
  box-shadow:var(--shadow-1);
}
.summary-card .title { font-size:0.95rem; color:var(--muted); margin-bottom:6px; }
.summary-card .value { font-size:1.7rem; font-weight:700; color:var(--text); display:flex; align-items:baseline; gap:8px; }
.summary-card .sub { color:var(--muted); margin-top:6px; font-size:0.95rem; }

.summary-card::before {
    content: '';
    position: absolute;
    right: -30px;
    top: -30px;
    width: 160px;
    height: 160px;
    opacity: 0.12;
    transform: rotate(20deg);
}

.card-invoices::before { background: linear-gradient(135deg, rgba(11, 132, 255, 0.9), rgba(124, 58, 237, 0.9)); }
.card-sales::before { background: linear-gradient(135deg, rgba(16, 185, 129, 0.9), rgba(34, 197, 94, 0.9)); }
.card-net::before { background: linear-gradient(135deg, rgba(245, 158, 11, 0.9), rgba(251, 191, 36, 0.9)); }

/* البادجات */
.badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}
.badge-paid { background: rgba(16, 185, 129, 0.15); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.3); }
.badge-partial { background: rgba(124, 58, 237, 0.15); color: #7c3aed; border: 1px solid rgba(124, 58, 237, 0.3); }
.badge-pending { background: rgba(245, 158, 11, 0.15); color: #f59e0b; border: 1px solid rgba(245, 158, 11, 0.3); }
.badge-returned { background: rgba(239, 68, 68, 0.15); color: #ef4444; border: 1px solid rgba(239, 68, 68, 0.3); }

/* زر عرض المزيد للفلاتر */
.filter-toggle {
    background: transparent;
    border: 1px solid var(--border);
    color: var(--muted);
    padding: 6px 12px;
    border-radius: 8px;
    cursor: pointer;
    font-size: 13px;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.2s ease;
}
.filter-toggle:hover {
    border-color: var(--primary);
    color: var(--primary);
}
.filter-toggle i {
    transition: transform 0.3s ease;
}
.filter-toggle.active i {
    transform: rotate(180deg);
}

/* قسم الفلاتر المتقدمة */
.advanced-filters {
    background: var(--surface-2);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 20px;
    margin-top: 15px;
    display: none;
    animation: slideDown 0.3s ease;
}
.advanced-filters.active {
    display: block;
}
@keyframes slideDown {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}

/* حقل البحث مع اقتراحات */
.search-suggestions {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 8px;
    box-shadow: var(--shadow-2);
    z-index: 1000;
    max-height: 300px;
    overflow-y: auto;
    display: none;
}
.search-suggestions.active {
    display: block;
}
.suggestion-item {
    padding: 10px 15px;
    cursor: pointer;
    border-bottom: 1px solid var(--border);
    transition: background 0.2s ease;
}
.suggestion-item:hover {
    background: var(--primary-weak);
}
.suggestion-item:last-child {
    border-bottom: none;
}

/* جدول التقرير */
.table-card { 
    border-radius:12px; 
    overflow:hidden; 
    box-shadow:0 10px 28px rgba(2,6,23,0.04); 
    background:var(--surface); 
    margin-top: 20px;
}
.table-card .table thead th { 
    background: linear-gradient(90deg, rgba(11,132,255,0.03), rgba(99,102,241,0.01)); 
    border-bottom:none; 
    color:var(--text); 
    font-weight: 600;
    font-size: 14px;
    padding: 15px;
}
.table-card tbody tr { 
    transition: all 0.2s ease;
    border-bottom: 1px solid var(--border);
}
.table-card tbody tr:hover { 
    background: rgba(11,132,255,0.03); 
    transform: translateX(2px); 
}
.table-card tbody td {
    padding: 12px 15px;
    vertical-align: middle;
}
.table-card .invoice-actions {
    display: flex;
    gap: 8px;
    justify-content: center;
}

/* المودال */
.modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.5);
    z-index: 9999;
    display: none;
    opacity: 0;
    transition: opacity 0.3s ease;
}
.modal-overlay.active {
    display: flex;
    justify-content: center;
    align-items: center;
    opacity: 1;
}
.modal-content {
    background: var(--surface);
    border-radius: 16px;
    box-shadow: var(--shadow-2);
    max-width: 800px;
    width: 90%;
    max-height: 90vh;
    overflow-y: auto;
    animation: modalSlide 0.3s ease;
}
@keyframes modalSlide {
    from { opacity: 0; transform: translateY(-20px); }
    to { opacity: 1; transform: translateY(0); }
}
.modal-header {
    padding: 20px 25px;
    border-bottom: 1px solid var(--border);
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.modal-header h4 {
    margin: 0;
    font-size: 1.3rem;
    font-weight: 600;
    color: var(--text);
}
.modal-close {
    background: none;
    border: none;
    color: var(--muted);
    font-size: 20px;
    cursor: pointer;
    padding: 5px;
    border-radius: 8px;
    transition: all 0.2s ease;
}
.modal-close:hover {
    background: var(--border);
    color: var(--text);
}
.modal-body {
    padding: 25px;
}
.modal-footer {
    padding: 20px 25px;
    border-top: 1px solid var(--border);
    display: flex;
    justify-content: flex-end;
    gap: 10px;
}

/* تفاصيل البنود في المودال */
.invoice-details-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}
.detail-card {
    background: var(--surface-2);
    border-radius: 12px;
    padding: 20px;
    border: 1px solid var(--border);
}
.detail-card h5 {
    margin-top: 0;
    margin-bottom: 15px;
    color: var(--text-soft);
    font-size: 16px;
    font-weight: 600;
}
.detail-item {
    display: flex;
    justify-content: space-between;
    margin-bottom: 10px;
    padding-bottom: 10px;
    border-bottom: 1px solid var(--border);
}
.detail-item:last-child {
    border-bottom: none;
    margin-bottom: 0;
    padding-bottom: 0;
}
.detail-label {
    color: var(--muted);
    font-size: 14px;
}
.detail-value {
    color: var(--text);
    font-weight: 500;
    font-size: 14px;
}
.detail-value.big {
    font-size: 18px;
    font-weight: 600;
    color: var(--primary);
}

/* جدول البنود */
.items-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 20px;
}
.items-table th {
    background: var(--surface-2);
    padding: 12px;
    text-align: right;
    font-weight: 600;
    color: var(--text-soft);
    border-bottom: 2px solid var(--border);
}
.items-table td {
    padding: 12px;
    border-bottom: 1px solid var(--border);
    color: var(--text);
}
.items-table tr:last-child td {
    border-bottom: none;
}
.items-table .text-end { text-align: left; }
.items-table .text-center { text-align: center; }

/* الأقسام في المودال */
.modal-section {
    margin-bottom: 30px;
}
.modal-section:last-child {
    margin-bottom: 0;
}
.section-title {
    font-size: 18px;
    font-weight: 600;
    color: var(--text);
    margin-bottom: 15px;
    padding-bottom: 10px;
    border-bottom: 2px solid var(--primary);
    display: flex;
    align-items: center;
    gap: 10px;
}

/* أزرار المودال */
.btn-modal {
    padding: 8px 16px;
    border-radius: 8px;
    border: none;
    font-weight: 500;
    font-size: 14px;
    cursor: pointer;
    transition: all 0.2s ease;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}
.btn-modal-primary {
    background: var(--primary);
    color: white;
}
.btn-modal-primary:hover {
    background: var(--primary-600);
    transform: translateY(-1px);
}
.btn-modal-secondary {
    background: var(--surface-2);
    color: var(--text);
    border: 1px solid var(--border);
}
.btn-modal-secondary:hover {
    background: var(--border);
}

/* المرتجعات */
.returns-list {
    background: var(--surface-2);
    border-radius: 12px;
    padding: 15px;
    margin-top: 15px;
}
.return-item {
    padding: 12px;
    background: var(--surface);
    border-radius: 8px;
    margin-bottom: 10px;
    border: 1px solid var(--border);
}
.return-item:last-child {
    margin-bottom: 0;
}
.return-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 10px;
}
.return-amount {
    font-weight: 600;
    color: var(--text);
}
.return-date {
    color: var(--muted);
    font-size: 13px;
}
.return-reason {
    color: var(--text-soft);
    font-size: 14px;
    margin-top: 5px;
}

@media (max-width: 768px) {
    .hero { flex-direction: column; align-items: flex-start; gap: 10px; }
    .kpis-wrap { flex-direction: column; }
    .invoice-details-grid { grid-template-columns: 1fr; }
    .table-card { overflow-x: auto; }
    .modal-content { width: 95%; margin: 10px; }
}
</style>

<div class="container report-wrap">
    <!-- العنوان الرئيسي -->
    <div class="hero" role="banner" aria-label="تقرير المبيعات المتقدم">
        <div>
            <div class="title">تقرير المبيعات المتقدم</div>
            <div class="subtitle">الفترة: <strong id="periodText"><?php echo htmlspecialchars($start_date_filter); ?> → <?php echo htmlspecialchars($end_date_filter); ?></strong></div>
        </div>
        <div class="toolbar" role="toolbar" aria-label="أدوات">
            <a href="<?php echo htmlspecialchars(BASE_URL); ?>" class="btn-smth" title="العودة لصفحة الترحيب">
                <i class="fas fa-home" aria-hidden="true"></i> <span class="d-none d-sm-inline">العودة</span>
            </a>
            <button id="refreshBtn" class="btn-smth" title="تحديث">
                <i class="fas fa-sync-alt"></i>
            </button>
            <button id="printBtn" class="btn-smth primary" title="طباعة التقرير">
                <i class="fas fa-print"></i> طباعة
            </button>
        </div>
    </div>

    <!-- الفلاتر الأساسية -->
    <div class="card mt-3 mb-3">
        <div class="card-body">
            <form id="filterForm" method="get" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" class="row gy-3 gx-3 align-items-end">
                
                <!-- فلتر المدة السريعة -->
                <div class="col-12">
                    <label class="form-label small-muted">المدة السريعة</label>
                    <div class="periods" role="tablist" aria-label="اختر المدة">
                        <button type="button" data-period="day" id="pDay">يوم</button>
                        <button type="button" data-period="week" id="pWeek">أسبوع</button>
                        <button type="button" data-period="month" id="pMonth">شهر</button>
                        <button type="button" data-period="custom" id="pCustom">مخصص</button>
                        <button type="button" data-period="all" id="pAll">من 2020</button>
                    </div>
                </div>

                <!-- التواريخ -->
                <div class="col-md-3 col-sm-6">
                    <label class="form-label small-muted">من تاريخ</label>
                    <input type="date" id="start_date" name="start_date" class="form-control" value="<?php echo htmlspecialchars($start_date_filter); ?>" required>
                </div>

                <div class="col-md-3 col-sm-6">
                    <label class="form-label small-muted">إلى تاريخ</label>
                    <input type="date" id="end_date" name="end_date" class="form-control" value="<?php echo htmlspecialchars($end_date_filter); ?>" required>
                </div>

                <!-- فلتر الحالة -->
                <div class="col-md-2 col-sm-4">
                    <label class="form-label small-muted">حالة الدفع</label>
                    <select name="status" id="statusFilter" class="form-control">
                        <option value="">كل الحالات</option>
                        <option value="paid" <?php echo $status_filter == 'paid' ? 'selected' : ''; ?>>مدفوع</option>
                        <option value="partial" <?php echo $status_filter == 'partial' ? 'selected' : ''; ?>>جزئي</option>
                        <option value="pending" <?php echo $status_filter == 'pending' ? 'selected' : ''; ?>>مؤجل</option>
                    </select>
                </div>

                <!-- البحث المتقدم -->
                <div class="col-md-4 col-sm-8">
                    <label class="form-label small-muted">بحث سريع (رقم/اسم/ملاحظات)</label>
                    <div style="position: relative;">
                        <input type="text" name="advanced_search" class="form-control" placeholder="ابحث بأي شيء..." value="<?php echo htmlspecialchars($advanced_search); ?>">
                        <div id="searchSuggestions" class="search-suggestions"></div>
                    </div>
                </div>

                <!-- زر عرض المزيد من الفلاتر -->
                <div class="col-12">
                    <button type="button" id="toggleFilters" class="filter-toggle">
                        <i class="fas fa-sliders-h"></i> فلاتر متقدمة
                    </button>
                </div>

                <!-- الفلاتر المتقدمة (مخفية افتراضياً) -->
                <div id="advancedFilters" class="advanced-filters">
                    <div class="row gy-3 gx-3">
                        <!-- فلتر العميل -->
                        <div class="col-md-6">
                            <label class="form-label small-muted">بحث بالعميل</label>
                            <input type="text" name="customer" class="form-control" placeholder="اسم أو رقم العميل..." value="<?php echo htmlspecialchars($customer_filter); ?>">
                        </div>

                        <!-- فلتر الشغلانة -->
                        <div class="col-md-6">
                            <label class="form-label small-muted">بحث بالشغلانة</label>
                            <input type="text" name="work_order" class="form-control" placeholder="رقم أو اسم الشغلانة..." value="<?php echo htmlspecialchars($work_order_filter); ?>">
                        </div>

                        <!-- فلتر الملاحظات -->
                        <div class="col-md-6">
                            <label class="form-label small-muted">بحث في الملاحظات</label>
                            <input type="text" name="notes" class="form-control" placeholder="كلمات في الملاحظات..." value="<?php echo htmlspecialchars($notes_filter); ?>">
                        </div>

                        <!-- فلتر رقم الفاتورة -->
                        <div class="col-md-6">
                            <label class="form-label small-muted">رقم الفاتورة</label>
                            <input type="number" name="invoice_id" class="form-control" placeholder="رقم الفاتورة..." value="<?php echo htmlspecialchars($invoice_filter); ?>">
                        </div>
                    </div>
                </div>

                <!-- أزرار الإجراءات -->
                <div class="col-12 mt-3">
                    <div class="d-flex gap-2 justify-content-end">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i> عرض النتائج
                        </button>
                        <button type="button" id="resetBtn" class="btn btn-outline-secondary">
                            <i class="fas fa-redo"></i> إعادة تعيين
                        </button>
                        <button type="button" id="todayBtn" class="btn btn-outline-primary">
                            <i class="fas fa-calendar-day"></i> اليوم
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- عرض الرسائل -->
    <?php if (!empty($message)): ?>
        <div class="mb-3"><?php echo $message; ?></div>
    <?php endif; ?>

    <!-- كروت الإحصائيات -->
    <div class="kpis-wrap">
        <div class="summary-card card-invoices">
            <div class="title">عدد الفواتير</div>
            <div class="value"><?php echo intval($total_invoices_period); ?></div>
            <div class="sub">الفواتير النشطة في الفترة</div>
        </div>

        <div class="summary-card card-sales">
            <div class="title">إجمالي المبيعات</div>
            <div class="value"><?php echo number_format($total_sales_amount_period, 2); ?> <span class="currency-badge">ج.م</span></div>
            <div class="sub">قبل الخصومات والمرتجعات</div>
        </div>

        <div class="summary-card card-net">
            <div class="title">صافي المبيعات</div>
            <div class="value"><?php echo number_format($net_sales_after_returns, 2); ?> <span class="currency-badge">ج.م</span></div>
            <div class="sub">بعد الخصومات والمرتجعات</div>
        </div>
    </div>

    <!-- جدول الفواتير -->
    <div class="table-card">
        <div class="table-responsive custom-table-wrapper">
            <table id="reportTable" class="custom-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>رقم الفاتورة</th>
                        <th>التاريخ</th>
                        <th>العميل</th>
                        <th>حالة الدفع</th>
                        <th class="text-end">الإجمالي</th>
                        <th class="text-end">الخصم</th>
                        <th class="text-end">الصافي</th>
                        <th class="text-center">الإجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($sales_data)): $counter = 1; foreach($sales_data as $invoice): ?>
                        <?php
                        // تحديد لون البادج بناءً على الحالة
                        $status_class = 'badge-' . $invoice['payment_status'];
                        $status_text = $invoice['payment_status'] == 'paid' ? 'مدفوع' : 
                                     ($invoice['payment_status'] == 'partial' ? 'جزئي' : 'مؤجل');
                        ?>
                        <tr data-invoice-id="<?php echo $invoice['invoice_id']; ?>">
                            <td><?php echo $counter++; ?></td>
                            <td>
                                <div class="fw-bold">#<?php echo intval($invoice['invoice_id']); ?></div>
                                <?php if (!empty($invoice['work_order_id'])): ?>
                                    <small class="text-muted d-block">
                                        <i class="fas fa-project-diagram"></i> 
                                        <?php echo htmlspecialchars($invoice['work_order_title'] ?? 'شغلانة #' . $invoice['work_order_id']); ?>
                                    </small>
                                <?php endif; ?>
                            </td>
                            <td><?php echo date('Y-m-d H:i', strtotime($invoice['invoice_date'])); ?></td>
                            <td>
                                <div><?php echo htmlspecialchars($invoice['customer_name']); ?></div>
                                <?php if (!empty($invoice['customer_phone'])): ?>
                                    <small class="text-muted"><?php echo htmlspecialchars($invoice['customer_phone']); ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge <?php echo $status_class; ?>"><?php echo $status_text; ?></span>
                                <?php if (floatval($invoice['total_returns_amount']) > 0): ?>
                                    <small class="d-block text-danger mt-1">
                                        <i class="fas fa-undo"></i> مرتجع: <?php echo number_format($invoice['total_returns_amount'], 2); ?> ج.م
                                    </small>
                                <?php endif; ?>
                            </td>
                            <td class="text-end"><?php echo number_format(floatval($invoice['total_before_discount']), 2); ?> ج.م</td>
                            <td class="text-end text-danger">
                                <?php if (floatval($invoice['discount_amount']) > 0): ?>
                                    -<?php echo number_format(floatval($invoice['discount_amount']), 2); ?> ج.م
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end fw-bold">
                                <?php echo number_format($invoice['net_amount'], 2); ?> ج.م
                            </td>
                            <td class="text-center">
                                <div class="invoice-actions">
                                    <button type="button" class="btn btn-sm btn-outline-info view-details" 
                                            data-invoice-id="<?php echo $invoice['invoice_id']; ?>"
                                            title="عرض التفاصيل">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <?php if (floatval($invoice['total_returns_amount']) > 0): ?>
                                        <button type="button" class="btn btn-sm btn-outline-warning view-returns" 
                                                data-invoice-id="<?php echo $invoice['invoice_id']; ?>"
                                                title="عرض المرتجعات">
                                            <i class="fas fa-undo"></i>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr>
                            <td colspan="9" class="text-center small-muted p-4">
                                <i class="fas fa-inbox fa-2x mb-3 d-block"></i>
                                لا توجد فواتير لعرضها في هذه الفترة
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="5" class="text-end"><strong>الإجمالي الكلي:</strong></td>
                        <td class="text-end fw-bold"><?php echo number_format($total_sales_amount_period, 2); ?> ج.م</td>
                        <td class="text-end text-danger fw-bold">-<?php echo number_format(array_sum(array_column($sales_data, 'discount_amount')), 2); ?> ج.م</td>
                        <td class="text-end fw-bold" id="table_total"><?php echo number_format($net_sales_after_returns, 2); ?> ج.م</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <div class="small-muted mt-3">
        <i class="fas fa-info-circle"></i> تاريخ التحديث: <?php echo date('Y-m-d H:i'); ?>
        <?php if (!empty($sales_data)): ?>
            | إجمالي الفواتير المعروضة: <?php echo count($sales_data); ?>
        <?php endif; ?>
    </div>
</div>

<!-- المودال لعرض التفاصيل -->
<div class="modal-overlay" id="invoiceModal">
    <div class="modal-content">
        <div class="modal-header">
            <h4>تفاصيل الفاتورة <span id="modalInvoiceNumber"></span></h4>
            <button type="button" class="modal-close" id="closeModal">&times;</button>
        </div>
        <div class="modal-body" id="modalBody">
            <!-- سيتم ملء المحتوى بواسطة JavaScript -->
        </div>
        <div class="modal-footer">
            <button type="button" class="btn-modal btn-modal-secondary" id="closeModalBtn">إغلاق</button>
            <button type="button" class="btn-modal btn-modal-primary" id="printInvoiceBtn">
                <i class="fas fa-print"></i> طباعة الفاتورة
            </button>
        </div>
    </div>
</div>

<!-- المودال لعرض المرتجعات -->
<div class="modal-overlay" id="returnsModal">
    <div class="modal-content">
        <div class="modal-header">
            <h4>المرتجعات للفاتورة <span id="returnsInvoiceNumber"></span></h4>
            <button type="button" class="modal-close" id="closeReturnsModal">&times;</button>
        </div>
        <div class="modal-body" id="returnsBody">
            <!-- سيتم ملء المحتوى بواسطة JavaScript -->
        </div>
        <div class="modal-footer">
            <button type="button" class="btn-modal btn-modal-secondary" id="closeReturnsModalBtn">إغلاق</button>
        </div>
    </div>
</div>

<script>
(function(){
    // عناصر DOM
    const qs = s => document.querySelector(s);
    const qsa = s => Array.from(document.querySelectorAll(s));
    
    // عناصر الفلترة
    const pDay = qs('#pDay'), pWeek = qs('#pWeek'), pMonth = qs('#pMonth'), 
          pCustom = qs('#pCustom'), pAll = qs('#pAll');
    const startIn = qs('#start_date'), endIn = qs('#end_date');
    const periodBtns = [pDay, pWeek, pMonth, pCustom, pAll];
    const filterForm = qs('#filterForm');
    const toggleFiltersBtn = qs('#toggleFilters');
    const advancedFilters = qs('#advancedFilters');
    const resetBtn = qs('#resetBtn');
    const todayBtn = qs('#todayBtn');
    const refreshBtn = qs('#refreshBtn');
    const printBtn = qs('#printBtn');
    const searchInput = qs('input[name="advanced_search"]');
    const searchSuggestions = qs('#searchSuggestions');
    
    // عناصر المودال
    const invoiceModal = qs('#invoiceModal');
    const returnsModal = qs('#returnsModal');
    const closeModalBtns = qsa('.modal-close, .btn-modal-secondary');
    const viewDetailsBtns = qsa('.view-details');
    const viewReturnsBtns = qsa('.view-returns');

    // دالة تنسيق التاريخ
    function formatDate(d) { 
        return d.toISOString().slice(0,10); 
    }

    // تعيين الفترة وتقديم النموذج تلقائياً للفترات السريعة
    function setPeriod(period, autoSubmit = false) {
        periodBtns.forEach(b => b.classList.remove('active'));
        
        let start = new Date(), end = new Date();
        const now = new Date();

        if (period === 'day') {
            start = new Date(now);
            end = new Date(now);
            pDay.classList.add('active');
        } else if (period === 'week') {
            const day = now.getDay() || 7;
            start = new Date(now);
            start.setDate(now.getDate() - (day - 1));
            end = new Date(start);
            end.setDate(start.getDate() + 6);
            pWeek.classList.add('active');
        } else if (period === 'month') {
            start = new Date(now.getFullYear(), now.getMonth(), 1);
            end = new Date(now.getFullYear(), now.getMonth() + 1, 0);
            pMonth.classList.add('active');
        } else if (period === 'all') {
            // من أول 2020
            start = new Date('2020-01-01');
            end = new Date();
            pAll.classList.add('active');
        } else { // custom
            pCustom.classList.add('active');
            updatePeriodText();
            return;
        }

        startIn.value = formatDate(start);
        endIn.value = formatDate(end);
        updatePeriodText();

        if (autoSubmit) {
            setTimeout(() => { filterForm.submit(); }, 120);
        }
    }

    // تحديث نص الفترة المعروض
    function updatePeriodText() {
        const st = startIn.value || '';
        const ed = endIn.value || '';
        const el = document.getElementById('periodText');
        if (el) el.textContent = st + ' → ' + ed;
    }

    // التهيئة الأولية
    const initialPeriod = '<?php echo addslashes($_GET['period'] ?? 'day'); ?>' || 'day';
    setPeriod(initialPeriod, false);

    // أحداث الفترات السريعة
    pDay.addEventListener('click', () => setPeriod('day', true));
    pWeek.addEventListener('click', () => setPeriod('week', true));
    pMonth.addEventListener('click', () => setPeriod('month', true));
    pAll.addEventListener('click', () => setPeriod('all', true));
    pCustom.addEventListener('click', () => {
        setPeriod('custom', false);
        startIn.focus();
    });

    // زر اليوم
    todayBtn?.addEventListener('click', () => {
        const t = formatDate(new Date());
        startIn.value = t;
        endIn.value = t;
        setPeriod('day', true);
    });

    // تحديث نص الفترة عند تغيير التواريخ يدوياً
    [startIn, endIn].forEach(el => {
        el.addEventListener('change', function() {
            setPeriod('custom', false);
            updatePeriodText();
        });
    });

    // تبديل عرض الفلاتر المتقدمة
    toggleFiltersBtn?.addEventListener('click', function() {
        advancedFilters.classList.toggle('active');
        this.classList.toggle('active');
        this.querySelector('i').style.transform = this.classList.contains('active') ? 'rotate(180deg)' : 'rotate(0)';
    });

    // إعادة تعيين الفلاتر
    resetBtn?.addEventListener('click', function() {
        // حذف جميع معلمات GET
        const url = new URL(window.location.href);
        url.search = '';
        window.location.href = url.toString();
    });

    // التحديث
    refreshBtn?.addEventListener('click', () => location.reload());

    // اقتراحات البحث
    let searchTimeout;
    searchInput?.addEventListener('input', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => {
            const query = this.value.trim();
            if (query.length < 2) {
                searchSuggestions.classList.remove('active');
                return;
            }
            
            // جلب الاقتراحات من السيرفر
            fetch(`<?php echo BASE_URL; ?>api/search_suggestions.php?q=${encodeURIComponent(query)}`)
                .then(response => response.json())
                .then(data => {
                    if (data.length > 0) {
                        let html = '';
                        data.forEach(item => {
                            html += `<div class="suggestion-item" data-value="${escapeHtml(item.value)}" data-type="${item.type}">
                                        <div><strong>${escapeHtml(item.text)}</strong></div>
                                        <small class="text-muted">${escapeHtml(item.type)}</small>
                                    </div>`;
                        });
                        searchSuggestions.innerHTML = html;
                        searchSuggestions.classList.add('active');
                        
                        // إضافة أحداث النقر على الاقتراحات
                        qsa('.suggestion-item').forEach(item => {
                            item.addEventListener('click', () => {
                                searchInput.value = item.dataset.value;
                                searchSuggestions.classList.remove('active');
                                filterForm.submit();
                            });
                        });
                    } else {
                        searchSuggestions.classList.remove('active');
                    }
                })
                .catch(() => {
                    searchSuggestions.classList.remove('active');
                });
        }, 300);
    });

    // إغلاق اقتراحات البحث عند النقر خارجها
    document.addEventListener('click', (e) => {
        if (!searchInput.contains(e.target) && !searchSuggestions.contains(e.target)) {
            searchSuggestions.classList.remove('active');
        }
    });

    // عرض تفاصيل الفاتورة في المودال
    viewDetailsBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            const invoiceId = this.dataset.invoiceId;
            showInvoiceDetails(invoiceId);
        });
    });

    // عرض المرتجعات في المودال
    viewReturnsBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            const invoiceId = this.dataset.invoiceId;
            showInvoiceReturns(invoiceId);
        });
    });

    // دالة لعرض تفاصيل الفاتورة
    async function showInvoiceDetails(invoiceId) {
        try {
            const response = await fetch(`<?php echo BASE_URL; ?>api/get_invoice_details.php?id=${invoiceId}`);
            const invoice = await response.json();
            
            // تحديث رقم الفاتورة في العنوان
            qs('#modalInvoiceNumber').textContent = `#${invoice.id}`;
            
            // بناء محتوى المودال
            let html = `
                <div class="invoice-details-grid">
                    <div class="detail-card">
                        <h5><i class="fas fa-info-circle"></i> معلومات الفاتورة</h5>
                        <div class="detail-item">
                            <span class="detail-label">رقم الفاتورة:</span>
                            <span class="detail-value">#${invoice.id}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">التاريخ:</span>
                            <span class="detail-value">${formatDateTime(invoice.created_at)}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">الحالة:</span>
                            <span class="detail-value"><span class="badge badge-${invoice.payment_status}">
                                ${invoice.payment_status === 'paid' ? 'مدفوع' : 
                                  invoice.payment_status === 'partial' ? 'جزئي' : 'مؤجل'}
                            </span></span>
                        </div>
                        ${invoice.work_order_id ? `
                        <div class="detail-item">
                            <span class="detail-label">الشغلانة:</span>
                            <span class="detail-value">${escapeHtml(invoice.work_order_title || `شغلانة #${invoice.work_order_id}`)}</span>
                        </div>
                        ` : ''}
                        ${invoice.notes ? `
                        <div class="detail-item">
                            <span class="detail-label">ملاحظات:</span>
                            <span class="detail-value">${escapeHtml(invoice.notes)}</span>
                        </div>
                        ` : ''}
                    </div>

                    <div class="detail-card">
                        <h5><i class="fas fa-user"></i> معلومات العميل</h5>
                        <div class="detail-item">
                            <span class="detail-label">الاسم:</span>
                            <span class="detail-value">${escapeHtml(invoice.customer_name || '—')}</span>
                        </div>
                        ${invoice.customer_phone ? `
                        <div class="detail-item">
                            <span class="detail-label">الهاتف:</span>
                            <span class="detail-value">${escapeHtml(invoice.customer_phone)}</span>
                        </div>
                        ` : ''}
                    </div>

                    <div class="detail-card">
                        <h5><i class="fas fa-calculator"></i> الملخص المالي</h5>
                        <div class="detail-item">
                            <span class="detail-label">الإجمالي قبل الخصم:</span>
                            <span class="detail-value">${formatCurrency(invoice.total_before_discount)}</span>
                        </div>
                        ${invoice.discount_amount > 0 ? `
                        <div class="detail-item">
                            <span class="detail-label">الخصم:</span>
                            <span class="detail-value text-danger">-${formatCurrency(invoice.discount_amount)}</span>
                        </div>
                        ` : ''}
                        ${invoice.total_returns_amount > 0 ? `
                        <div class="detail-item">
                            <span class="detail-label">المرتجعات:</span>
                            <span class="detail-value text-danger">-${formatCurrency(invoice.total_returns_amount)}</span>
                        </div>
                        ` : ''}
                        <div class="detail-item">
                            <span class="detail-label">المدفوع:</span>
                            <span class="detail-value text-success">${formatCurrency(invoice.paid_amount)}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">المتبقي:</span>
                            <span class="detail-value ${invoice.remaining_amount > 0 ? 'text-warning' : ''}">
                                ${formatCurrency(invoice.remaining_amount)}
                            </span>
                        </div>
                        <div class="detail-item" style="border-top: 2px solid var(--primary); padding-top: 12px;">
                            <span class="detail-label" style="font-weight: 600;">الصافي:</span>
                            <span class="detail-value big">${formatCurrency(invoice.net_amount)}</span>
                        </div>
                    </div>
                </div>
            `;

            // إضافة جدول البنود إذا كان موجوداً
            if (invoice.items && invoice.items.length > 0) {
                html += `
                    <div class="modal-section">
                        <h5 class="section-title"><i class="fas fa-list"></i> بنود الفاتورة</h5>
                        <table class="items-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>المنتج</th>
                                    <th class="text-center">الكمية</th>
                                    <th class="text-center">مرتجع</th>
                                    <th class="text-center">المتبقي</th>
                                    <th class="text-end">سعر الوحدة</th>
                                    <th class="text-end">الخصم</th>
                                    <th class="text-end">الإجمالي</th>
                                </tr>
                            </thead>
                            <tbody>
                `;

                invoice.items.forEach((item, index) => {
                    const remainingQty = parseFloat(item.quantity) - parseFloat(item.returned_quantity);
                    const unitPriceAfterDiscount = parseFloat(item.unit_price_after_discount) || 
                                                   (parseFloat(item.selling_price) - (parseFloat(item.discount_amount) / parseFloat(item.quantity)));
                    
                    html += `
                        <tr>
                            <td>${index + 1}</td>
                            <td>
                                <div>${escapeHtml(item.product_name)}</div>
                                <small class="text-muted">${escapeHtml(item.product_code || '')}</small>
                            </td>
                            <td class="text-center">${formatNumber(item.quantity)}</td>
                            <td class="text-center ${item.returned_quantity > 0 ? 'text-danger' : ''}">
                                ${item.returned_quantity > 0 ? formatNumber(item.returned_quantity) : '—'}
                            </td>
                            <td class="text-center ${remainingQty > 0 ? 'text-success' : 'text-danger'}">
                                ${formatNumber(remainingQty)}
                            </td>
                            <td class="text-end">${formatCurrency(unitPriceAfterDiscount)}</td>
                            <td class="text-end ${item.discount_amount > 0 ? 'text-danger' : ''}">
                                ${item.discount_amount > 0 ? `-${formatCurrency(item.discount_amount)}` : '—'}
                            </td>
                            <td class="text-end fw-bold">${formatCurrency(item.total_after_discount || item.item_net_total)}</td>
                        </tr>
                    `;
                });

                html += `
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td colspan="6" class="text-end"><strong>إجمالي البنود:</strong></td>
                                    <td colspan="2" class="text-end fw-bold">
                                        ${formatCurrency(invoice.items_total || invoice.total_after_discount)}
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                `;
            }

            qs('#modalBody').innerHTML = html;
            invoiceModal.classList.add('active');
            document.body.style.overflow = 'hidden';

        } catch (error) {
            console.error('Error loading invoice details:', error);
            alert('حدث خطأ أثناء تحميل تفاصيل الفاتورة');
        }
    }

    // دالة لعرض المرتجعات
    async function showInvoiceReturns(invoiceId) {
        try {
            const response = await fetch(`<?php echo BASE_URL; ?>api/get_invoice_returns.php?id=${invoiceId}`);
            const returns = await response.json();
            
            qs('#returnsInvoiceNumber').textContent = `#${invoiceId}`;
            
            let html = '';
            
            if (returns.length > 0) {
                html += `<div class="returns-list">`;
                
                returns.forEach(ret => {
                    html += `
                        <div class="return-item">
                            <div class="return-header">
                                <span class="return-amount">${formatCurrency(ret.total_amount)}</span>
                                <span class="badge ${ret.status === 'completed' ? 'badge-paid' : 'badge-pending'}">
                                    ${ret.status === 'completed' ? 'مكتمل' : 
                                     ret.status === 'approved' ? 'معتمد' : 'قيد الانتظار'}
                                </span>
                            </div>
                            <div class="return-date">
                                <i class="far fa-calendar"></i> ${formatDateTime(ret.return_date || ret.created_at)}
                            </div>
                            ${ret.reason ? `
                            <div class="return-reason">
                                <strong>السبب:</strong> ${escapeHtml(ret.reason)}
                            </div>
                            ` : ''}
                            ${ret.notes ? `
                            <div class="return-reason">
                                <strong>ملاحظات:</strong> ${escapeHtml(ret.notes)}
                            </div>
                            ` : ''}
                            
                            ${ret.items && ret.items.length > 0 ? `
                            <div style="margin-top: 10px;">
                                <table style="width: 100%; font-size: 13px;">
                                    <thead>
                                        <tr>
                                            <th>المنتج</th>
                                            <th class="text-center">الكمية</th>
                                            <th class="text-end">المبلغ</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                            ` : ''}
                            
                            ${ret.items ? ret.items.map(item => `
                                <tr>
                                    <td>${escapeHtml(item.product_name || `منتج #${item.product_id}`)}</td>
                                    <td class="text-center">${formatNumber(item.quantity)}</td>
                                    <td class="text-end">${formatCurrency(item.total_amount)}</td>
                                </tr>
                            `).join('') : ''}
                            
                            ${ret.items && ret.items.length > 0 ? `
                                    </tbody>
                                </table>
                            </div>
                            ` : ''}
                        </div>
                    `;
                });
                
                html += `</div>`;
            } else {
                html = '<div class="text-center p-4 text-muted">لا توجد مرتجعات مسجلة لهذه الفاتورة</div>';
            }
            
            qs('#returnsBody').innerHTML = html;
            returnsModal.classList.add('active');
            document.body.style.overflow = 'hidden';
            
        } catch (error) {
            console.error('Error loading returns:', error);
            qs('#returnsBody').innerHTML = '<div class="alert alert-danger">حدث خطأ أثناء تحميل المرتجعات</div>';
        }
    }

    // إغلاق المودال
    closeModalBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            invoiceModal.classList.remove('active');
            returnsModal.classList.remove('active');
            document.body.style.overflow = '';
        });
    });

    // إغلاق المودال عند النقر خارج المحتوى
    [invoiceModal, returnsModal].forEach(modal => {
        modal.addEventListener('click', (e) => {
            if (e.target === modal) {
                modal.classList.remove('active');
                document.body.style.overflow = '';
            }
        });
    });

    // زر طباعة الفاتورة في المودال
    qs('#printInvoiceBtn')?.addEventListener('click', function() {
        // هنا يمكن إضافة منطق طباعة الفاتورة
        window.print();
    });

    // دالة الطباعة
    printBtn?.addEventListener('click', function() {
        const periodLabel = qs('#periodText')?.textContent || `${startIn.value} → ${endIn.value}`;
        const rows = [];
        let grand = 0;
        
        qsa('#reportTable tbody tr').forEach(tr => {
            const tds = tr.querySelectorAll('td');
            if (!tds || tds.length < 8) return;
            
            const idx = tds[0].innerText.trim();
            const inv = tds[1].innerText.trim();
            const dt = tds[2].innerText.trim();
            const cust = tds[3].innerText.trim();
            const status = tds[4].innerText.trim();
            const totalTxt = tds[5].innerText.replace(/[^\d.,-]/g,'').replace(/,/g,'').trim();
            const discountTxt = tds[6].innerText.replace(/[^\d.,-]/g,'').replace(/,/g,'').trim();
            const netTxt = tds[7].innerText.replace(/[^\d.,-]/g,'').replace(/,/g,'').trim();
            
            const totalVal = parseFloat(totalTxt) || 0;
            const discountVal = parseFloat(discountTxt) || 0;
            const netVal = parseFloat(netTxt) || 0;
            
            grand += netVal;
            
            rows.push([idx, inv, dt, cust, status, totalVal.toFixed(2), discountVal.toFixed(2), netVal.toFixed(2)]);
        });

        // بناء HTML للطباعة
        let html = `<!doctype html><html lang="ar" dir="rtl"><head>
            <meta charset="utf-8">
            <title>طباعة تقرير المبيعات</title>
            <style>
                body { font-family: Arial, Helvetica, sans-serif; padding: 18px; color: #111; }
                h2 { margin: 0 0 8px; color: #0b84ff; }
                .meta { color: #555; margin-bottom: 12px; padding-bottom: 12px; border-bottom: 2px solid #0b84ff; }
                .summary { display: flex; gap: 20px; margin-bottom: 20px; flex-wrap: wrap; }
                .summary-item { background: #f6f8fc; padding: 10px 15px; border-radius: 8px; min-width: 150px; }
                .summary-label { font-size: 12px; color: #666; }
                .summary-value { font-size: 18px; font-weight: bold; color: #0b84ff; }
                table { width: 100%; border-collapse: collapse; margin-top: 8px; font-size: 12px; }
                th, td { border: 1px solid #ddd; padding: 8px; text-align: right; }
                thead th { background: #f6f8fb; font-weight: 600; }
                tfoot td { font-weight: 700; background: #f0f7ff; }
                .badge { padding: 2px 8px; border-radius: 10px; font-size: 10px; font-weight: 600; }
                .badge-paid { background: #d1fae5; color: #065f46; }
                .badge-partial { background: #ede9fe; color: #5b21b6; }
                .badge-pending { background: #fef3c7; color: #92400e; }
                @media print {
                    body { padding: 10px; }
                    .no-print { display: none; }
                }
            </style>
        </head><body>`;
        
        html += `<h2>تقرير المبيعات المتقدم</h2>
                <div class="meta">الفترة: <strong>${escapeHtml(periodLabel)}</strong></div>`;
        
        if (rows.length === 0) {
            html += '<div>لا توجد فواتير لعرضها.</div>';
        } else {
            html += `<div class="summary">
                        <div class="summary-item">
                            <div class="summary-label">عدد الفواتير</div>
                            <div class="summary-value">${rows.length}</div>
                        </div>
                        <div class="summary-item">
                            <div class="summary-label">إجمالي المبيعات</div>
                            <div class="summary-value">${rows.reduce((sum, row) => sum + parseFloat(row[5]), 0).toFixed(2)} ج.م</div>
                        </div>
                        <div class="summary-item">
                            <div class="summary-label">إجمالي الخصومات</div>
                            <div class="summary-value">${rows.reduce((sum, row) => sum + parseFloat(row[6]), 0).toFixed(2)} ج.م</div>
                        </div>
                        <div class="summary-item">
                            <div class="summary-label">الصافي</div>
                            <div class="summary-value">${grand.toFixed(2)} ج.م</div>
                        </div>
                    </div>`;
            
            html += `<table><thead>
                        <tr>
                            <th>#</th><th>رقم الفاتورة</th><th>التاريخ</th><th>العميل</th>
                            <th>الحالة</th><th>الإجمالي</th><th>الخصم</th><th>الصافي</th>
                        </tr>
                    </thead><tbody>`;
            
            rows.forEach(r => {
                let badgeClass = '';
                if (r[4].includes('مدفوع')) badgeClass = 'badge-paid';
                else if (r[4].includes('جزئي')) badgeClass = 'badge-partial';
                else if (r[4].includes('مؤجل')) badgeClass = 'badge-pending';
                
                html += `<tr>
                            <td>${escapeHtml(r[0])}</td>
                            <td>${escapeHtml(r[1])}</td>
                            <td>${escapeHtml(r[2])}</td>
                            <td>${escapeHtml(r[3])}</td>
                            <td><span class="badge ${badgeClass}">${escapeHtml(r[4])}</span></td>
                            <td style="text-align:right">${escapeHtml(r[5])} ج.م</td>
                            <td style="text-align:right">${parseFloat(r[6]) > 0 ? '-' + escapeHtml(r[6]) : '—'} ج.م</td>
                            <td style="text-align:right">${escapeHtml(r[7])} ج.م</td>
                        </tr>`;
            });
            
            html += `</tbody><tfoot>
                        <tr>
                            <td colspan="5" style="text-align:right"><strong>الإجمالي الكلي:</strong></td>
                            <td style="text-align:right">${rows.reduce((sum, row) => sum + parseFloat(row[5]), 0).toFixed(2)} ج.م</td>
                            <td style="text-align:right">${rows.reduce((sum, row) => sum + parseFloat(row[6]), 0).toFixed(2)} ج.م</td>
                            <td style="text-align:right">${grand.toFixed(2)} ج.م</td>
                        </tr>
                    </tfoot></table>`;
        }
        
        html += `<div style="margin-top:18px;color:#666;font-size:13px">طُبع في: ${new Date().toLocaleString('ar-EG')}</div>`;
        html += `</body></html>`;
        
        const w = window.open('', '_blank', 'toolbar=0,location=0,menubar=0');
        if (!w) { 
            alert('يرجى السماح بفتح النوافذ المنبثقة للطباعة'); 
            return; 
        }
        w.document.open();
        w.document.write(html);
        w.document.close();
        w.focus();
        setTimeout(() => { w.print(); }, 350);
    });

    // دوال مساعدة
    function formatDateTime(dateStr) {
        const date = new Date(dateStr);
        return date.toLocaleString('ar-EG', {
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit'
        });
    }

    function formatCurrency(amount) {
        return parseFloat(amount || 0).toLocaleString('ar-EG', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        }) + ' ج.م';
    }

    function formatNumber(num) {
        return parseFloat(num || 0).toLocaleString('ar-EG', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    function escapeHtml(s) { 
        if (!s && s !== 0) return '';
        return String(s).replace(/[&<>"']/g, function(m) { 
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]; 
        }); 
    }

    // إضافة تأثير عند النقر على الصف
    qsa('#reportTable tbody tr').forEach(tr => {
        tr.addEventListener('click', (e) => {
            if (!e.target.closest('.view-details') && !e.target.closest('.view-returns')) {
                qsa('#reportTable tbody tr').forEach(x => x.style.outline = '');
                tr.style.outline = '2px solid rgba(11,132,255,0.06)';
            }
        });
    });

})();
</script>

<?php
$conn->close();
require_once BASE_DIR . 'partials/footer.php';
?>