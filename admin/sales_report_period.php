<?php
// sales_report_advanced.php — تقرير مبيعات شامل مع فلاتر ذكية وواجهة محسنة
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

// الفلاتر
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$customer_filter = isset($_GET['customer']) ? trim($_GET['customer']) : '';
$work_order_filter = isset($_GET['work_order']) ? trim($_GET['work_order']) : '';
$notes_filter = isset($_GET['notes']) ? trim($_GET['notes']) : '';
$invoice_filter = isset($_GET['invoice_id']) ? trim($_GET['invoice_id']) : '';
$advanced_search = isset($_GET['advanced_search']) ? trim($_GET['advanced_search']) : '';
$delivered_filter = isset($_GET['delivered']) ? $_GET['delivered'] : '';

// التواريخ
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
        $sql_where = " WHERE io.created_at BETWEEN ? AND ?";
        $params = [$start_date_sql, $end_date_sql];
        $param_types = "ss";
        
        // فلتر الحالة المالية
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
        
        // فلتر حالة التسليم
        if (!empty($delivered_filter)) {
            $sql_where .= " AND io.delivered = ?";
            $params[] = $delivered_filter;
            $param_types .= "s";
        }
        
        // فلتر العميل
        if (!empty($customer_filter)) {
            $sql_where .= " AND (c.name LIKE ? OR c.mobile LIKE ?)";
            $customer_like = "%{$customer_filter}%";
            $params[] = $customer_like;
            $params[] = $customer_like;
            $param_types .= "ss";
        }
        
        // فلتر الشغلانة
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
        
        // البحث المتقدم
        if (!empty($advanced_search)) {
            $sql_where .= " AND (io.id = ? OR c.name LIKE ? OR wo.title LIKE ? OR io.notes LIKE ?)";
            $advanced_like = "%{$advanced_search}%";
            $params[] = $advanced_search;
            $params[] = $advanced_like;
            $params[] = $advanced_like;
            $params[] = $advanced_like;
            $param_types .= "isss";
        }

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
                    io.work_order_id,
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

            -- WHERE io.delivered NOT IN ('canceled','reverted')
                LEFT JOIN customers c ON io.customer_id = c.id
                LEFT JOIN work_orders wo ON io.work_order_id = wo.id
                LEFT JOIN returns r ON r.invoice_id = io.id AND r.status IN ('approved', 'completed')
                $sql_where
                        AND io.delivered NOT IN ('canceled','reverted')

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

require_once BASE_DIR . 'partials/header.php';
require_once BASE_DIR . 'partials/sidebar.php';
?>

<style>
/* تنسيق الصفحة الرئيسية */
.sales-report-page {
    padding: 15px 0;
    min-height: calc(100vh - 120px);
}

.sales-report-page .header-section {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 20px;
    border-radius: 12px;
    margin-bottom: 20px;
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
}

.sales-report-page .header-section h1 {
    margin: 0;
    font-size: 1.5rem;
    font-weight: 700;
}

.sales-report-page .header-section .subtitle {
    opacity: 0.9;
    font-size: 0.95rem;
    margin-top: 5px;
}

/* تخطيط الصفحة */
.sales-report-main {
    display: flex;
    gap: 20px;
    flex: 1;
    min-height: 0;
    overflow: hidden;
}

/* الفلاتر الجانبية */
.filters-sidebar {
    background: var(--surface, #fff);
    border-radius: 12px;
    padding: 20px;
    box-shadow: var(--shadow-1, 0 1px 3px rgba(0,0,0,0.1));
    border: 1px solid var(--border, #e5e7eb);
    display: flex;
    flex-direction: column;
    overflow-y: auto;
    flex-shrink: 0;
    width: 320px;
    max-height: calc(100vh - 200px);
}

.filters-sidebar .filter-title {
    font-weight: 800;
    margin-bottom: 15px;
    color: var(--text, #1f2937);
    font-size: 1.1rem;
    padding-bottom: 10px;
    border-bottom: 2px solid var(--primary);
}

.filters-sidebar .filter-group {
    margin-bottom: 20px;
}

.filters-sidebar .filter-group label {
    display: block;
    font-size: 0.9rem;
    color: var(--text-soft, #4b5563);
    font-weight: 600;
    margin-bottom: 8px;
}

.filters-sidebar .filter-group input,
.filters-sidebar .filter-group select {
    width: 100%;
    padding: 10px 12px;
    border-radius: 8px;
    border: 1px solid var(--border, #e5e7eb);
    background: var(--surface-2, #f9fafb);
    font-size: 0.95rem;
    color: var(--text, #1f2937);
}

.filters-sidebar .filter-group input:focus,
.filters-sidebar .filter-group select:focus {
    border-color: var(--primary, #3b82f6);
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
    outline: none;
}

.filters-sidebar .quick-periods {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 8px;
    margin-bottom: 15px;
}

.filters-sidebar .quick-periods button {
    padding: 8px;
    border-radius: 8px;
    border: 1px solid var(--border);
    background: var(--surface-2);
    color: var(--text);
    cursor: pointer;
    font-size: 0.85rem;
    transition: all 0.2s;
}

.filters-sidebar .quick-periods button:hover {
    background: var(--primary-weak);
    border-color: var(--primary);
}

.filters-sidebar .quick-periods button.active {
    background: var(--primary);
    color: white;
    border-color: var(--primary);
}

.filters-sidebar .filter-actions {
    display: flex;
    gap: 10px;
    margin-top: auto;
    padding-top: 20px;
    border-top: 1px solid var(--border);
}

.filters-sidebar .btn-apply {
    flex: 1;
    background: var(--primary);
    color: white;
    border: none;
    padding: 12px;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    transition: transform 0.2s;
}

.filters-sidebar .btn-apply:hover {
    transform: translateY(-1px);
}

.filters-sidebar .btn-reset {
    flex: 1;
    background: var(--surface-2);
    color: var(--text);
    border: 1px solid var(--border);
    padding: 12px;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
}

/* المحتوى الرئيسي */
.content-main {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 20px;
    min-width: 0;
    max-height: calc(100vh - 200px);
    overflow: hidden;
}

/* كروت الإحصائيات */
.stats-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 15px;
    margin-bottom: 10px;
}

.stat-card {
    background: var(--surface);
    border-radius: 12px;
    padding: 20px;
    box-shadow: var(--shadow-1);
    border: 1px solid var(--border);
    text-align: center;
}

.stat-card .stat-icon {
    font-size: 2rem;
    margin-bottom: 10px;
    opacity: 0.8;
}

.stat-card .stat-value {
    font-size: 1.8rem;
    font-weight: 800;
    color: var(--text);
    margin-bottom: 5px;
}

.stat-card .stat-label {
    font-size: 0.9rem;
    color: var(--muted);
    font-weight: 600;
}

.stat-card.card-invoices .stat-icon { color: #3b82f6; }
.stat-card.card-sales .stat-icon { color: #10b981; }
.stat-card.card-net .stat-icon { color: #f59e0b; }
.stat-card.card-discounts .stat-icon { color: #ef4444; }

/* قائمة الفواتير */
.invoices-list-container {
    background: var(--surface);
    border-radius: 12px;
    padding: 20px;
    box-shadow: var(--shadow-1);
    border: 1px solid var(--border);
    flex: 1;
    overflow-y: auto;
}

.invoices-list {
    display: grid;
    gap: 15px;
}

/* كارت الفاتورة */
.invoice-card {
    background: var(--surface);
    border-radius: 10px;
    padding: 18px;
    box-shadow: var(--shadow-1);
    border: 1px solid var(--border);
    transition: all 0.3s ease;
    cursor: pointer;
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 15px;
}

.invoice-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 16px rgba(0,0,0,0.1);
    border-color: var(--primary);
}

.invoice-card.selected {
    background: var(--primary-weak);
    border-color: var(--primary);
}

.invoice-left {
    flex: 1;
    min-width: 0;
}

.invoice-header {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 10px;
}

.invoice-number {
    background: var(--primary);
    color: white;
    padding: 4px 12px;
    border-radius: 20px;
    font-weight: 700;
    font-size: 0.9rem;
}

.invoice-status {
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 600;
}

.status-paid { background: #d1fae5; color: #065f46; }
.status-partial { background: #ede9fe; color: #5b21b6; }
.status-pending { background: #fef3c7; color: #92400e; }

.invoice-customer {
    font-weight: 700;
    color: var(--text);
    margin-bottom: 8px;
    font-size: 1.1rem;
}

.invoice-meta {
    display: flex;
    gap: 15px;
    color: var(--muted);
    font-size: 0.85rem;
    margin-bottom: 8px;
}

.invoice-meta span {
    display: flex;
    align-items: center;
    gap: 4px;
}

.invoice-notes {
    color: var(--muted);
    font-size: 0.9rem;
    line-height: 1.4;
    margin-top: 8px;
    padding-top: 8px;
    border-top: 1px dashed var(--border);
}

.invoice-right {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 8px;
    min-width: 180px;
}

.invoice-amounts {
    text-align: right;
}

.amount-before {
    text-decoration: line-through;
    color: var(--muted);
    font-size: 0.9rem;
}

.amount-after {
    font-weight: 800;
    color: var(--text);
    font-size: 1.2rem;
}

.amount-discount {
    background: linear-gradient(135deg, #fef3c7, #fde68a);
    color: #92400e;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 700;
    border: 1px solid #fbbf24;
}

.amount-returns {
    background: linear-gradient(135deg, #fee2e2, #fecaca);
    color: #991b1b;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 700;
    border: 1px solid #f87171;
}

.invoice-actions {
    display: flex;
    gap: 8px;
    margin-top: 8px;
}

.invoice-actions button {
    padding: 6px 12px;
    border-radius: 8px;
    border: none;
    font-size: 0.8rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    gap: 4px;
}

.btn-view { background: var(--primary); color: white; }
.btn-returns { background: var(--amber); color: white; }
.btn-print { background: var(--teal); color: white; }

/* السايدبار الجانبي لتوضيح التفاصيل */
.details-sidebar {
    position: fixed;
    top: 0;
    left: 0;
    width: 400px;
    height: 100vh;
    background: var(--surface);
    box-shadow: 5px 0 15px rgba(0,0,0,0.1);
    z-index: 1000;
    transform: translateX(-100%);
    transition: transform 0.3s ease;
    overflow-y: auto;
    padding: 20px;
}

.details-sidebar.active {
    transform: translateX(0);
}

.details-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 2px solid var(--border);
}

.details-header h3 {
    margin: 0;
    color: var(--text);
    font-size: 1.3rem;
}

.close-details {
    background: none;
    border: none;
    font-size: 1.5rem;
    color: var(--muted);
    cursor: pointer;
    padding: 5px;
}

.details-content {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.details-section {
    background: var(--surface-2);
    border-radius: 10px;
    padding: 15px;
    border: 1px solid var(--border);
}

.details-section h4 {
    margin: 0 0 15px 0;
    color: var(--text-soft);
    font-size: 1rem;
    padding-bottom: 8px;
    border-bottom: 1px solid var(--border);
}

.detail-row {
    display: flex;
    justify-content: space-between;
    margin-bottom: 10px;
    padding-bottom: 10px;
    border-bottom: 1px dashed var(--border);
}

.detail-row:last-child {
    border-bottom: none;
    margin-bottom: 0;
    padding-bottom: 0;
}

.detail-label {
    color: var(--muted);
    font-size: 0.9rem;
}

.detail-value {
    color: var(--text);
    font-weight: 500;
    font-size: 0.95rem;
}

.detail-value.big {
    font-size: 1.2rem;
    font-weight: 700;
    color: var(--primary);
}

/* جدول البنود */
.items-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 10px;
}

.items-table th {
    background: var(--surface-2);
    padding: 10px;
    text-align: right;
    font-weight: 600;
    color: var(--text-soft);
    border-bottom: 2px solid var(--border);
    font-size: 0.85rem;
}

.items-table td {
    padding: 10px;
    border-bottom: 1px solid var(--border);
    color: var(--text);
    font-size: 0.85rem;
}

.items-table tr:hover {
    background: var(--primary-weak);
}

/* التنقل بين الفواتير */
.details-navigation {
    display: flex;
    justify-content: space-between;
    margin-top: 20px;
    padding-top: 20px;
    border-top: 1px solid var(--border);
}

.details-navigation button {
    padding: 8px 16px;
    border-radius: 8px;
    border: 1px solid var(--border);
    background: var(--surface);
    color: var(--text);
    cursor: pointer;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 8px;
    transition: all 0.2s;
}

.details-navigation button:hover {
    background: var(--primary);
    color: white;
    border-color: var(--primary);
}

/* طباعة متعددة */
.print-actions {
    display: flex;
    gap: 10px;
    align-items: center;
    margin-bottom: 15px;
    padding: 15px;
    background: var(--surface-2);
    border-radius: 10px;
    border: 1px solid var(--border);
}

.select-all {
    display: flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
    user-select: none;
}

.btn-print-multiple {
    background: var(--primary);
    color: white;
    border: none;
    padding: 10px 20px;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 8px;
    transition: all 0.2s;
}

.btn-print-multiple:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(59, 130, 246, 0.2);
}

/* أحجام مختلفة للشاشات */
@media (max-width: 1200px) {
    .sales-report-main {
        flex-direction: column;
    }
    
    .filters-sidebar {
        width: 100%;
        max-height: none;
        margin-bottom: 20px;
    }
    
    .content-main {
        max-height: none;
    }
}

@media (max-width: 768px) {
    .stats-cards {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .invoice-card {
        flex-direction: column;
    }
    
    .invoice-right {
        width: 100%;
        align-items: flex-start;
        margin-top: 15px;
    }
    
    .details-sidebar {
        width: 100%;
    }
}

/* الرسوم المتحركة للتحميل */
.skeleton-loading {
    animation: skeleton-loading 1.5s infinite ease-in-out;
    background: linear-gradient(90deg, var(--surface-2) 25%, var(--border) 50%, var(--surface-2) 75%);
    background-size: 200% 100%;
}

@keyframes skeleton-loading {
    0% { background-position: 200% 0; }
    100% { background-position: -200% 0; }
}

.skeleton-card {
    height: 120px;
    border-radius: 10px;
    margin-bottom: 15px;
}

.skeleton-text {
    height: 20px;
    border-radius: 4px;
    margin-bottom: 10px;
}

.skeleton-text.short {
    width: 60%;
}

/* بحث متقدم مع اقتراحات */
.search-suggestions {
    position: absolute;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 8px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    width: 100%;
    max-height: 300px;
    overflow-y: auto;
    z-index: 1000;
    display: none;
}

.suggestion-item {
    padding: 10px 15px;
    cursor: pointer;
    border-bottom: 1px solid var(--border);
    transition: background 0.2s;
}

.suggestion-item:hover {
    background: var(--primary-weak);
}

.suggestion-item:last-child {
    border-bottom: none;
}

/* نافذة الطباعة */
.print-window {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: white;
    z-index: 9999;
    overflow-y: auto;
    padding: 20px;
}

.print-controls {
    position: fixed;
    bottom: 20px;
    right: 20px;
    display: flex;
    gap: 10px;
    z-index: 10000;
}
</style>

<div class="sales-report-page">
    <div class="header-section">
        <h1><i class="fas fa-chart-line"></i> تقرير المبيعات المتقدم</h1>
        <div class="subtitle">إدارة شاملة للفواتير مع فلترة متقدمة وعرض تفصيلي</div>
    </div>

    <div class="sales-report-main">
        <!-- الفلاتر الجانبية -->
        <aside class="filters-sidebar" id="filtersSidebar">
            <h2 class="filter-title"><i class="fas fa-filter"></i> فلاتر البحث</h2>
            
            <form id="filterForm" method="get" class="filter-form">
                <!-- فترات سريعة -->
                <div class="filter-group">
                    <label>الفترة السريعة</label>
                    <div class="quick-periods">
                        <button type="button" class="period-btn" data-days="1">اليوم</button>
                        <button type="button" class="period-btn" data-days="7">أسبوع</button>
                        <button type="button" class="period-btn" data-days="30">شهر</button>
                        <button type="button" class="period-btn" data-period="2020">من 2020</button>
                        <button type="button" class="period-btn" data-days="90">ربع سنوي</button>
                        <button type="button" class="period-btn" data-days="365">سنة</button>
                    </div>
                </div>

                <!-- التواريخ -->
                <div class="filter-group">
                    <label>من تاريخ</label>
                    <input type="date" name="start_date" id="start_date" value="<?php echo htmlspecialchars($start_date_filter); ?>">
                </div>

                <div class="filter-group">
                    <label>إلى تاريخ</label>
                    <input type="date" name="end_date" id="end_date" value="<?php echo htmlspecialchars($end_date_filter); ?>">
                </div>

                <!-- فلتر الحالة المالية -->
                <div class="filter-group">
                    <label>حالة الدفع</label>
                    <select name="status" id="statusFilter">
                        <option value="">كل الحالات</option>
                        <option value="paid" <?php echo $status_filter == 'paid' ? 'selected' : ''; ?>>مدفوع</option>
                        <option value="partial" <?php echo $status_filter == 'partial' ? 'selected' : ''; ?>>جزئي</option>
                        <option value="pending" <?php echo $status_filter == 'pending' ? 'selected' : ''; ?>>مؤجل</option>
                    </select>
                </div>

                <!-- فلتر التسليم -->
                <div class="filter-group">
                    <label>حالة التسليم</label>
                    <select name="delivered">
                        <option value="">كل الحالات</option>
                        <option value="yes" <?php echo $delivered_filter == 'yes' ? 'selected' : ''; ?>>مسلم</option>
                        <option value="no" <?php echo $delivered_filter == 'no' ? 'selected' : ''; ?>>مؤجل</option>
                        <option value="partial" <?php echo $delivered_filter == 'partial' ? 'selected' : ''; ?>>جزئي</option>
                        <option value="canceled" <?php echo $delivered_filter == 'canceled' ? 'selected' : ''; ?>>ملغى</option>
                    </select>
                </div>

                <!-- البحث المتقدم -->
                <div class="filter-group">
                    <label>بحث شامل</label>
                    <input type="text" name="advanced_search" 
                           placeholder="رقم فاتورة، اسم عميل، ملاحظات..." 
                           value="<?php echo htmlspecialchars($advanced_search); ?>"
                           id="globalSearch">
                    <div class="search-suggestions" id="searchSuggestions"></div>
                </div>

                <!-- الفلاتر المتقدمة (قابلة للطي) -->
                <div class="filter-group">
                    <details>
                        <summary style="cursor: pointer; font-weight: 600; color: var(--text); padding: 8px 0;">
                            <i class="fas fa-sliders-h"></i> فلاتر متقدمة
                        </summary>
                        <div style="margin-top: 10px; display: grid; gap: 10px;">
                            <input type="text" name="customer" placeholder="اسم العميل أو رقم الهاتف" 
                                   value="<?php echo htmlspecialchars($customer_filter); ?>">
                            <input type="text" name="work_order" placeholder="رقم أو عنوان الشغلانة" 
                                   value="<?php echo htmlspecialchars($work_order_filter); ?>">
                            <input type="text" name="notes" placeholder="بحث في الملاحظات" 
                                   value="<?php echo htmlspecialchars($notes_filter); ?>">
                            <input type="number" name="invoice_id" placeholder="رقم الفاتورة" 
                                   value="<?php echo htmlspecialchars($invoice_filter); ?>">
                        </div>
                    </details>
                </div>

                <div class="filter-actions">
                    <button type="button" id="applyFilters" class="btn-apply">
                        <i class="fas fa-search"></i> تطبيق
                    </button>
                    <button type="button" id="resetFilters" class="btn-reset">
                        <i class="fas fa-redo"></i> إعادة تعيين
                    </button>
                </div>
            </form>
        </aside>

        <!-- المحتوى الرئيسي -->
        <main class="content-main">
            <!-- الإحصائيات -->
            <div class="stats-cards">
                <div class="stat-card card-invoices">
                    <div class="stat-icon"><i class="fas fa-file-invoice"></i></div>
                    <div class="stat-value"><?php echo $total_invoices_period; ?></div>
                    <div class="stat-label">عدد الفواتير</div>
                </div>

                <div class="stat-card card-sales">
                    <div class="stat-icon"><i class="fas fa-money-bill-wave"></i></div>
                    <div class="stat-value"><?php echo number_format($total_sales_amount_period, 2); ?></div>
                    <div class="stat-label">إجمالي المبيعات</div>
                </div>

                <div class="stat-card card-net">
                    <div class="stat-icon"><i class="fas fa-chart-bar"></i></div>
                    <div class="stat-value"><?php echo number_format($net_sales_after_returns, 2); ?></div>
                    <div class="stat-label">صافي المبيعات</div>
                </div>

                <div class="stat-card card-discounts">
                    <div class="stat-icon"><i class="fas fa-tag"></i></div>
                    <div class="stat-value">
                        <?php
                        $total_discounts = 0;
                        foreach($sales_data as $invoice) {
                            $total_discounts += floatval($invoice['discount_amount']);
                        }
                        echo number_format($total_discounts, 2);
                        ?>
                    </div>
                    <div class="stat-label">إجمالي الخصومات</div>
                </div>
            </div>

            <!-- إجراءات الطباعة -->
            <div class="print-actions">
                <label class="select-all">
                    <input type="checkbox" id="selectAll">
                    تحديد الكل
                </label>
                <button id="printSelected" class="btn-print-multiple" disabled>
                    <i class="fas fa-print"></i> طباعة الفواتير المحددة (0)
                </button>
            </div>

            <!-- قائمة الفواتير -->
            <div class="invoices-list-container">
                <div class="invoices-list" id="invoicesList">
                    <?php if (!empty($sales_data)): ?>
                        <?php foreach($sales_data as $invoice): ?>
                            <?php
                            $status_class = 'status-' . $invoice['payment_status'];
                            $status_text = $invoice['payment_status'] == 'paid' ? 'مدفوع' : 
                                         ($invoice['payment_status'] == 'partial' ? 'جزئي' : 'مؤجل');
                            
                            $delivered_text = '';
                            if ($invoice['delivered'] == 'yes') $delivered_text = 'مسلم';
                            elseif ($invoice['delivered'] == 'no') $delivered_text = 'مؤجل';
                            elseif ($invoice['delivered'] == 'partial') $delivered_text = 'جزئي';
                            elseif ($invoice['delivered'] == 'canceled') $delivered_text = 'ملغى';
                            
                            $discount_amount = floatval($invoice['discount_amount']);
                            $returns_amount = floatval($invoice['total_returns_amount']);
                            ?>
                            
                            <div class="invoice-card" data-invoice-id="<?php echo $invoice['invoice_id']; ?>">
                                <div class="invoice-left">
                                    <div class="invoice-header">
                                        <span class="invoice-number">#<?php echo $invoice['invoice_id']; ?></span>
                                        <span class="invoice-status <?php echo $status_class; ?>"><?php echo $status_text; ?></span>
                                        <?php if ($delivered_text): ?>
                                            <span class="invoice-status status-partial"><?php echo $delivered_text; ?></span>
                                        <?php endif; ?>
                                        <?php if ($invoice['work_order_id']): ?>
                                            <span style="color: var(--muted); font-size: 0.85rem;">
                                                <i class="fas fa-project-diagram"></i> شغلانة
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="invoice-customer">
                                        <?php echo htmlspecialchars($invoice['customer_name']); ?>
                                    </div>
                                    
                                    <div class="invoice-meta">
                                        <span><i class="far fa-calendar"></i> <?php echo date('Y-m-d', strtotime($invoice['invoice_date'])); ?></span>
                                        <?php if ($invoice['customer_phone']): ?>
                                            <span><i class="fas fa-phone"></i> <?php echo htmlspecialchars($invoice['customer_phone']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <?php if (!empty($invoice['notes'])): ?>
                                        <div class="invoice-notes">
                                            <?php echo htmlspecialchars(substr($invoice['notes'], 0, 100)); ?>
                                            <?php if (strlen($invoice['notes']) > 100): ?>...<?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="invoice-right">
                                    <div class="invoice-amounts">
                                        <?php if ($discount_amount > 0): ?>
                                            <div class="amount-before"><?php echo number_format(floatval($invoice['total_before_discount']), 2); ?> ج.م</div>
                                        <?php endif; ?>
                                        <div class="amount-after"><?php echo number_format($invoice['net_amount'], 2); ?> ج.م</div>
                                        
                                        <?php if ($discount_amount > 0): ?>
                                            <div class="amount-discount">
                                                -<?php echo number_format($discount_amount, 2); ?> ج.م خصم
                                            </div>
                                        <?php endif; ?>
                                        
                                        <?php if ($returns_amount > 0): ?>
                                            <div class="amount-returns">
                                                -<?php echo number_format($returns_amount, 2); ?> ج.م مرتجع
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="invoice-actions">
                                        <button class="btn-view view-details" 
                                                data-invoice-id="<?php echo $invoice['invoice_id']; ?>">
                                            <i class="fas fa-eye"></i> عرض
                                        </button>
                                        <?php if ($returns_amount > 0): ?>
                                            <button class="btn-returns view-returns" 
                                                    data-invoice-id="<?php echo $invoice['invoice_id']; ?>">
                                                <i class="fas fa-undo"></i> مرتجع
                                            </button>
                                        <?php endif; ?>
                                        <button class="btn-print print-invoice" 
                                                data-invoice-id="<?php echo $invoice['invoice_id']; ?>">
                                            <i class="fas fa-print"></i> طباعة
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="text-align: center; padding: 40px; color: var(--muted);">
                            <i class="fas fa-inbox fa-3x mb-3"></i>
                            <div>لا توجد فواتير لعرضها</div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>
</div>

<!-- السايدبار الجانبي للتفاصيل -->
<aside class="details-sidebar " id="detailsSidebar">
      <div class="details-navigation mt-2 " id="detailsNavigation">
        <button id="prevInvoice"><i class="fas fa-chevron-right"></i> السابقة</button>
        <button id="nextInvoice">التالية <i class="fas fa-chevron-left"></i></button>
    </div>
    <div class="details-header">
        <h3 id="detailsTitle">تفاصيل الفاتورة</h3>
        <button class="close-details" id="closeDetails">&times;</button>
    </div>
    
    <div class="details-content" id="detailsContent">
        <!-- سيتم ملء المحتوى بواسطة JavaScript -->
    </div>
    
  
</aside>

<script>
class SalesReport {
    constructor() {
        this.currentInvoiceIndex = 0;
        this.selectedInvoices = new Set();
        this.invoicesData = <?php echo json_encode($sales_data); ?>;
        this.init();
    }

    init() {
        // إضافة أحداث الفلاتر
        this.setupFilters();
        
        // إضافة أحداث الفواتير
        this.setupInvoiceEvents();
        
        // إضافة أحداث الطباعة
        this.setupPrintEvents();
        
        // إضافة أحداث السايدبار
        this.setupSidebarEvents();
        
        // تحميل Skeleton عند البحث
        this.setupSearchLoading();
    }

    setupFilters() {
        // الفترات السريعة
        document.querySelectorAll('.period-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const days = btn.dataset.days;
                const period = btn.dataset.period;
                
                const endDate = new Date();
                const startDate = new Date();
                
                if (period === '2020') {
                    // من أول 2020
                    document.getElementById('start_date').value = '2020-01-01';
                } else if (days) {
                    startDate.setDate(endDate.getDate() - parseInt(days) + 1);
                    document.getElementById('start_date').value = startDate.toISOString().split('T')[0];
                }
                
                document.getElementById('end_date').value = endDate.toISOString().split('T')[0];
                
                // تقديم النموذج بدون إعادة تحميل الصفحة
                this.submitFilters();
            });
        });

        // تطبيق الفلاتر
        document.getElementById('applyFilters').addEventListener('click', () => {
            this.submitFilters();
        });

        // إعادة تعيين الفلاتر
        document.getElementById('resetFilters').addEventListener('click', () => {
            window.location.href = window.location.pathname;
        });

        // البحث الآني
        const globalSearch = document.getElementById('globalSearch');
        const searchSuggestions = document.getElementById('searchSuggestions');
        
        globalSearch.addEventListener('input', (e) => {
            const query = e.target.value.trim();
            if (query.length < 2) {
                searchSuggestions.style.display = 'none';
                return;
            }
            
            // محاكاة اقتراحات البحث
            this.showSearchSuggestions(query);
        });
    }

    submitFilters() {
        const form = document.getElementById('filterForm');
        const formData = new FormData(form);
        
        // عرض مؤشر التحميل
        this.showLoading();
        
        // إرسال طلب AJAX
        fetch(window.location.pathname + '?' + new URLSearchParams(formData), {
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => response.text())
        .then(html => {
            // تحديث المحتوى فقط
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');
            
            // تحديث قائمة الفواتير
            const newList = doc.querySelector('.invoices-list');
            if (newList) {
                document.getElementById('invoicesList').innerHTML = newList.innerHTML;
                this.setupInvoiceEvents(); // إعادة ربط الأحداث
            }
            
            // تحديث الإحصائيات
            const statsCards = doc.querySelector('.stats-cards');
            if (statsCards) {
                document.querySelector('.stats-cards').outerHTML = statsCards.outerHTML;
            }
            
            this.hideLoading();
        })
        .catch(error => {
            console.error('Error:', error);
            this.hideLoading();
            alert('حدث خطأ أثناء التحميل');
        });
    }

    showLoading() {
        const list = document.getElementById('invoicesList');
        list.innerHTML = `
            <div class="skeleton-card skeleton-loading"></div>
            <div class="skeleton-card skeleton-loading"></div>
            <div class="skeleton-card skeleton-loading"></div>
            <div class="skeleton-card skeleton-loading"></div>
        `;
    }

    hideLoading() {
        // يتم التحديث مباشرة من البيانات
    }

    showSearchSuggestions(query) {
        const suggestions = document.getElementById('searchSuggestions');
        
        // محاكاة الاقتراحات
        const mockSuggestions = [
            {type: 'invoice', text: `فاتورة #123`, value: '123'},
            {type: 'customer', text: `محمد أحمد`, value: 'محمد أحمد'},
            {type: 'work_order', text: `شغلانة #456`, value: '456'},
            {type: 'notes', text: `ملاحظات الدفع`, value: 'دفع'},
        ].filter(s => s.text.toLowerCase().includes(query.toLowerCase()));
        
        if (mockSuggestions.length > 0) {
            suggestions.innerHTML = mockSuggestions.map(s => `
                <div class="suggestion-item" data-value="${s.value}" data-type="${s.type}">
                    <div><strong>${s.text}</strong></div>
                    <small style="color: var(--muted);">${s.type}</small>
                </div>
            `).join('');
            
            suggestions.style.display = 'block';
            
            // إضافة أحداث النقر على الاقتراحات
            suggestions.querySelectorAll('.suggestion-item').forEach(item => {
                item.addEventListener('click', () => {
                    document.getElementById('globalSearch').value = item.dataset.value;
                    suggestions.style.display = 'none';
                    this.submitFilters();
                });
            });
        } else {
            suggestions.style.display = 'none';
        }
    }

    setupInvoiceEvents() {
        // عرض التفاصيل
        document.querySelectorAll('.view-details').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const invoiceId = btn.dataset.invoiceId;
                this.showInvoiceDetails(invoiceId);
            });
        });

        // عرض المرتجعات
        document.querySelectorAll('.view-returns').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const invoiceId = btn.dataset.invoiceId;
                this.showReturns(invoiceId);
            });
        });

        // اختيار الفاتورة للطباعة
        document.querySelectorAll('.invoice-card').forEach(card => {
            card.addEventListener('click', (e) => {
                if (e.target.type === 'checkbox' || e.target.closest('button')) {
                    return;
                }
                
                const invoiceId = card.dataset.invoiceId;
                card.classList.toggle('selected');
                
                if (card.classList.contains('selected')) {
                    this.selectedInvoices.add(invoiceId);
                } else {
                    this.selectedInvoices.delete(invoiceId);
                }
                
                this.updatePrintButton();
            });
        });

        // طباعة فاتورة فردية
        document.querySelectorAll('.print-invoice').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const invoiceId = btn.dataset.invoiceId;
                this.printInvoice(invoiceId);
            });
        });
    }

    async  showInvoiceDetails(invoiceId) {
    try {
        // إظهار السايدبار
        document.getElementById('detailsSidebar').classList.add('active');
        
        // تحديث عنوان الفاتورة
        document.getElementById('detailsTitle').textContent = `تفاصيل الفاتورة #${invoiceId}`;
        
        // تحميل تفاصيل الفاتورة من السيرفر
        const response = await fetch(`api/get_invoice_details.php?id=${invoiceId}`);
        const invoice = await response.json();
        
        if (invoice.error) {
            throw new Error(invoice.error);
        }
        
        // تحديث موقع الفاتورة الحالية في المصفوفة
        this.updateCurrentInvoiceIndex(invoiceId);
        
        // بناء محتوى التفاصيل
        const detailsContent = this.buildInvoiceDetails(invoice);
        document.getElementById('detailsContent').innerHTML = detailsContent;
        
        // تحديث أزرار التنقل
        this.updateNavigationButtons();
        
        // تحميل البنود
        await this.loadInvoiceItems(invoiceId);
        
    } catch (error) {
        console.error('Error loading invoice details:', error);
        document.getElementById('detailsContent').innerHTML = `
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-triangle"></i>
                ${error.message || 'حدث خطأ أثناء تحميل التفاصيل'}
            </div>
        `;
    }
}

// دالة جديدة لتحديث موقع الفاتورة الحالية
updateCurrentInvoiceIndex(invoiceId) {
    // تحويل invoiceId إلى رقم للبحث
    const id = parseInt(invoiceId);
    
    // البحث عن الفاتورة في المصفوفة
    for (let i = 0; i < this.invoicesData.length; i++) {
        if (parseInt(this.invoicesData[i].invoice_id) === id) {
            this.currentInvoiceIndex = i;
            return;
        }
    }
    
    // إذا لم يتم العثور عليها، اجعل الفهرس 0
    this.currentInvoiceIndex = 0;
    console.warn('Invoice not found in invoicesData, resetting to index 0');
}

    buildInvoiceDetails(invoice) {
        return `
            <div class="details-section">
                <h4><i class="fas fa-info-circle"></i> معلومات الفاتورة</h4>
                <div class="detail-row">
                    <span class="detail-label">رقم الفاتورة</span>
                    <span class="detail-value">#${invoice.id}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">التاريخ</span>
                    <span class="detail-value">${new Date(invoice.created_at).toLocaleString('ar-EG')}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">الحالة</span>
                    <span class="detail-value">
                        <span class="invoice-status status-${invoice.payment_status}">
                            ${invoice.payment_status === 'paid' ? 'مدفوع' : 
                              invoice.payment_status === 'partial' ? 'جزئي' : 'مؤجل'}
                        </span>
                    </span>
                </div>
                ${invoice.work_order_id ? `
                <div class="detail-row">
                    <span class="detail-label">الشغلانة</span>
                    <span class="detail-value">${invoice.work_order_title || 'شغلانة #' + invoice.work_order_id}</span>
                </div>
                ` : ''}
            </div>

            <div class="details-section">
                <h4><i class="fas fa-user"></i> معلومات العميل</h4>
                <div class="detail-row">
                    <span class="detail-label">الاسم</span>
                    <span class="detail-value">${invoice.customer_name || '—'}</span>
                </div>
                ${invoice.customer_phone ? `
                <div class="detail-row">
                    <span class="detail-label">الهاتف</span>
                    <span class="detail-value">${invoice.customer_phone}</span>
                </div>
                ` : ''}
            </div>

            <div class="details-section">
                <h4><i class="fas fa-calculator"></i> الملخص المالي</h4>
                <div class="detail-row">
                    <span class="detail-label">الإجمالي قبل الخصم</span>
                    <span class="detail-value">${this.formatCurrency(invoice.total_before_discount)}</span>
                </div>
                ${invoice.discount_amount > 0 ? `
                <div class="detail-row">
                    <span class="detail-label">الخصم</span>
                    <span class="detail-value text-danger">-${this.formatCurrency(invoice.discount_amount)}</span>
                </div>
                ` : ''}
                ${invoice.total_returns_amount > 0 ? `
                <div class="detail-row">
                    <span class="detail-label">المرتجعات</span>
                    <span class="detail-value text-danger">-${this.formatCurrency(invoice.total_returns_amount)}</span>
                </div>
                ` : ''}
                <div class="detail-row">
                    <span class="detail-label">المدفوع</span>
                    <span class="detail-value text-success">${this.formatCurrency(invoice.paid_amount)}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">المتبقي</span>
                    <span class="detail-value ${invoice.remaining_amount > 0 ? 'text-warning' : ''}">
                        ${this.formatCurrency(invoice.remaining_amount)}
                    </span>
                </div>
                <div class="detail-row">
                    <span class="detail-label" style="font-weight: 600;">الصافي النهائي</span>
                    <span class="detail-value big">${this.formatCurrency(invoice.net_amount)}</span>
                </div>
            </div>

            <div class="details-section">
                <h4><i class="fas fa-list"></i> البنود</h4>
                <div id="itemsContainer">
                    <div class="skeleton-text skeleton-loading"></div>
                    <div class="skeleton-text skeleton-loading"></div>
                    <div class="skeleton-text skeleton-loading"></div>
                </div>
            </div>
        `;
    }

    async loadInvoiceItems(invoiceId) {
        console.log(invoiceId);
        
        try {
            const response = await fetch(`api/get_invoice_items.php?id=${invoiceId}`);
            const items = await response.json();
            
            let itemsHTML = '';
            if (items && items.length > 0) {
                itemsHTML = `
                    <table class="items-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>المنتج</th>
                                <th>الكمية</th>
                                <th>مرتجع</th>
                                <th>المتبقي</th>
                                <th>سعر الوحدة</th>
                                <th>الإجمالي</th>
                            </tr>
                        </thead>
                        <tbody>
                `;
                
                items.forEach((item, index) => {
                    if (item.return_flag === 1) return; // تخطي البنود المرتجعة كلياً
                    
                    const remainingQty = parseFloat(item.quantity) - parseFloat(item.returned_quantity);
                    const unitPriceAfterDiscount = parseFloat(item.unit_price_after_discount) || 
                                                  (parseFloat(item.selling_price) - (parseFloat(item.discount_amount) / parseFloat(item.quantity)));
                    
                    itemsHTML += `
                        <tr>
                            <td>${index + 1}</td>
                            <td>${item.product_name}</td>
                            <td class="text-center">${this.formatNumber(item.quantity)}</td>
                            <td class="text-center ${item.returned_quantity > 0 ? 'text-danger' : ''}">
                                ${item.returned_quantity > 0 ? this.formatNumber(item.returned_quantity) : '—'}
                            </td>
                            <td class="text-center ${remainingQty > 0 ? 'text-success' : 'text-danger'}">
                                ${this.formatNumber(remainingQty)}
                            </td>
                            <td class="text-end">${this.formatCurrency(unitPriceAfterDiscount)}</td>
                            <td class="text-end fw-bold">
                                ${this.formatCurrency(item.total_after_discount || item.item_net_total)}
                            </td>
                        </tr>
                    `;
                });
                
                itemsHTML += `
                        </tbody>
                    </table>
                `;
            } else {
                itemsHTML = '<p class="text-muted">لا توجد بنود</p>';
            }
            
            document.getElementById('itemsContainer').innerHTML = itemsHTML;
            
        } catch (error) {
            console.error('Error loading items:', error);
            document.getElementById('itemsContainer').innerHTML = `
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-circle"></i>
                    حدث خطأ أثناء تحميل البنود
                </div>
            `;
        }
    }

    async showReturns(invoiceId) {
        try {
            const response = await fetch(`api/get_invoice_returns.php?id=${invoiceId}`);
            const returns = await response.json();
            
            let returnsHTML = '';
            if (returns && returns.length > 0) {
                returnsHTML = returns.map(ret => `
                    <div class="details-section">
                        <h4><i class="fas fa-undo"></i> مرتجع #${ret.id}</h4>
                        <div class="detail-row">
                            <span class="detail-label">التاريخ</span>
                            <span class="detail-value">${new Date(ret.return_date).toLocaleDateString('ar-EG')}</span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">المبلغ</span>
                            <span class="detail-value text-danger">-${this.formatCurrency(ret.total_amount)}</span>
                        </div>
                        ${ret.reason ? `
                        <div class="detail-row">
                            <span class="detail-label">السبب</span>
                            <span class="detail-value">${ret.reason}</span>
                        </div>
                        ` : ''}
                    </div>
                `).join('');
            } else {
                returnsHTML = '<p class="text-muted">لا توجد مرتجعات</p>';
            }
            
            // عرض في نافذة منبثقة
            this.showModal('المرتجعات', returnsHTML);
            
        } catch (error) {
            console.error('Error loading returns:', error);
            this.showModal('المرتجعات', '<p class="text-danger">حدث خطأ أثناء تحميل المرتجعات</p>');
        }
    }

    showModal(title, content) {
        // تنفيذ بسيط للنافذة المنبثقة
        const modalHTML = `
            <div class="modal-overlay" id="customModal">
                <div class="modal-content" style="max-width: 500px;">
                    <div class="modal-header">
                        <h4>${title}</h4>
                        <button class="modal-close" onclick="document.getElementById('customModal').remove()">&times;</button>
                    </div>
                    <div class="modal-body">
                        ${content}
                    </div>
                    <div class="modal-footer">
                        <button class="btn-modal btn-modal-secondary" 
                                onclick="document.getElementById('customModal').remove()">
                            إغلاق
                        </button>
                    </div>
                </div>
            </div>
        `;
        
        document.body.insertAdjacentHTML('beforeend', modalHTML);
    }

    setupPrintEvents() {

        // تحديد الكل
        document.getElementById('selectAll').addEventListener('change', (e) => {
            const checked = e.target.checked;
            document.querySelectorAll('.invoice-card').forEach(card => {
                card.classList.toggle('selected', checked);
                const invoiceId = card.dataset.invoiceId;
                if (checked) {
                    this.selectedInvoices.add(invoiceId);
                } else {
                    this.selectedInvoices.delete(invoiceId);
                }
            });
            this.updatePrintButton();
        });

        // طباعة المحددة
        document.getElementById('printSelected').addEventListener('click', () => {
            if (this.selectedInvoices.size > 0) {
                this.printMultipleInvoices(Array.from(this.selectedInvoices));
            }
        });
    }

    updatePrintButton() {
        const btn = document.getElementById('printSelected');
        const count = this.selectedInvoices.size;
        btn.disabled = count === 0;
        btn.innerHTML = `<i class="fas fa-print"></i> طباعة الفواتير المحددة (${count})`;
    }

    async printInvoice(invoiceId) {
        try {
            const response = await fetch(`api/get_invoice_for_print.php?id=${invoiceId}`);
            const invoice = await response.json();
            
            // إنشاء نافذة طباعة
            const printWindow = window.open('', '_blank');
            const content = this.generateInvoicePrintContent(invoice);
            
            printWindow.document.write(content);
            printWindow.document.close();
            
            setTimeout(() => {
                printWindow.print();
            }, 500);
            
        } catch (error) {
            console.error('Error printing invoice:', error);
            alert('حدث خطأ أثناء الطباعة');
        }
    }

    async printMultipleInvoices(invoiceIds) {
        try {
            // جمع بيانات الفواتير المحددة
            const invoices = [];
            let totalBefore = 0;
            let totalAfter = 0;
            let totalDiscount = 0;
            let totalReturns = 0;
            
            for (const id of invoiceIds) {
                const response = await fetch(`api/get_invoice_for_print.php?id=${id}`);
                const invoice = await response.json();
                invoices.push(invoice);
                
                totalBefore += parseFloat(invoice.total_before_discount || 0);
                totalAfter += parseFloat(invoice.total_after_discount || 0);
                totalDiscount += parseFloat(invoice.discount_amount || 0);
                totalReturns += parseFloat(invoice.total_returns_amount || 0);
            }
            
            // إنشاء تقرير مجمع
            const printWindow = window.open('', '_blank');
            const content = this.generateMultipleInvoicesPrintContent(invoices, {
                totalBefore,
                totalAfter,
                totalDiscount,
                totalReturns,
                count: invoiceIds.length
            });
            
            printWindow.document.write(content);
            printWindow.document.close();
            
            setTimeout(() => {
                printWindow.print();
            }, 500);
            
        } catch (error) {
            console.error('Error printing multiple invoices:', error);
            alert('حدث خطأ أثناء الطباعة');
        }
    }

    generateInvoicePrintContent(invoice) {
        // تنفيذ دالة الطباعة الفردية
        return `
            <!DOCTYPE html>
            <html dir="rtl">
            <head>
                <title>فاتورة #${invoice.id}</title>
                <style>
                    /* أنماط الطباعة */
                </style>
            </head>
            <body>
                <div class="invoice-print">
                    <h2>فاتورة #${invoice.id}</h2>
                    <!-- تفاصيل الفاتورة -->
                </div>
                <script>
                    window.onload = function() {
                        window.print();
                    }
                <\/script>
            </body>
            </html>
        `;
    }

    generateMultipleInvoicesPrintContent(invoices, totals) {
        // تنفيذ دالة الطباعة المجمعة
        return `
            <!DOCTYPE html>
            <html dir="rtl">
            <head>
                <title>تقرير فواتير مجمع</title>
                <style>
                    /* أنماط الطباعة */
                </style>
            </head>
            <body>
                <div class="report-print">
                    <h2>تقرير فواتير مجمع</h2>
                    <p>عدد الفواتير: ${totals.count}</p>
                    <!-- تفاصيل التقرير -->
                </div>
                <script>
                    window.onload = function() {
                        window.print();
                    }
                <\/script>
            </body>
            </html>
        `;
    }

    setupSidebarEvents() {
        // إغلاق السايدبار
        document.getElementById('closeDetails').addEventListener('click', () => {
            document.getElementById('detailsSidebar').classList.remove('active');
        });

        // التنقل بين الفواتير
        document.getElementById('prevInvoice').addEventListener('click', () => {
            if (this.currentInvoiceIndex > 0) {
                this.currentInvoiceIndex--;
                const prevInvoiceId = this.invoicesData[this.currentInvoiceIndex].invoice_id;
                this.showInvoiceDetails(prevInvoiceId);
            }
        });

        document.getElementById('nextInvoice').addEventListener('click', () => {
            console.log(this.currentInvoiceIndex);
            console.log(this.invoicesData.length);

            if (this.currentInvoiceIndex < this.invoicesData.length - 1) {
                this.currentInvoiceIndex++;
                const nextInvoiceId = this.invoicesData[this.currentInvoiceIndex].invoice_id;
                this.showInvoiceDetails(nextInvoiceId);
            }
        });

        // إغلاق السايدبار عند النقر خارجها
        document.getElementById('detailsSidebar').addEventListener('click', (e) => {
            if (e.target === document.getElementById('detailsSidebar')) {
                document.getElementById('detailsSidebar').classList.remove('active');
            }
        });
    }

    updateNavigationButtons() {
        const prevBtn = document.getElementById('prevInvoice');
        const nextBtn = document.getElementById('nextInvoice');
        
        prevBtn.disabled = this.currentInvoiceIndex === 0;
        nextBtn.disabled = this.currentInvoiceIndex === this.invoicesData.length - 1;
    }

    setupSearchLoading() {
        // بحث فوري مع تأثير التحميل
        const searchInput = document.getElementById('globalSearch');
        let searchTimeout;
        
        searchInput.addEventListener('input', (e) => {
            clearTimeout(searchTimeout);
            
            if (e.target.value.length >= 2) {
                searchTimeout = setTimeout(() => {
                    this.submitFilters();
                }, 500);
            }
        });
    }

    // دوال مساعدة
    formatCurrency(amount) {
        return parseFloat(amount || 0).toLocaleString('ar-EG', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        }) + ' ج.م';
    }

    formatNumber(num) {
        return parseFloat(num || 0).toLocaleString('ar-EG', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }
}

// تهيئة التطبيق عند تحميل الصفحة
document.addEventListener('DOMContentLoaded', () => {

    const salesReport = new SalesReport();
    
    // إضافة Skeleton loader للبحث الفوري
    const searchInput = document.getElementById('globalSearch');
    searchInput.addEventListener('input', (e) => {
        if (e.target.value.length >= 3) {
            // إظهار تأثير التحميل
            salesReport.showLoading();
        }
    });
    
    // إغلاق اقتراحات البحث عند النقر خارجها
    document.addEventListener('click', (e) => {
        if (!e.target.closest('#globalSearch') && !e.target.closest('#searchSuggestions')) {
            document.getElementById('searchSuggestions').style.display = 'none';
        }
    });


});
</script>

<?php
$conn->close();
require_once BASE_DIR . 'partials/footer.php';
?>