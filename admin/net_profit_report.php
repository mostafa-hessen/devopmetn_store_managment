<?php
// reports/net_profit_summary.responsive.php
$page_title = "تقرير صافي الربح — ملخص تفصيلي";
require_once dirname(__DIR__) . '/config.php';
require_once BASE_DIR . 'partials/session_admin.php';

if (!isset($conn) || !$conn) {
    die("DB connection error");
}

// =========================================================================
// 1. AJAX Handler
// =========================================================================
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'];

    $start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d');
    $end_date   = isset($_GET['end_date'])   ? $_GET['end_date']   : date('Y-m-d');
    $start_sql = $start_date . " 00:00:00";
    $end_sql   = $end_date . " 23:59:59";

    // --- Action: Get Stats ---
    if ($action === 'get_stats') {
        $sql_sales = "
            SELECT 
                COALESCE(SUM(ioi.total_after_discount), 0) as total_items_sales,
                COALESCE(SUM(ioi.returned_quantity * ioi.unit_price_after_discount), 0) as total_items_returns_value,
                COALESCE(SUM(CASE WHEN ioi.return_flag != 1 THEN (ioi.available_for_return * ioi.cost_price_per_unit) ELSE 0 END), 0) as total_active_cost
            FROM invoices_out io
            LEFT JOIN invoice_out_items ioi ON io.id = ioi.invoice_out_id
            WHERE io.delivered NOT IN ('canceled', 'reverted')
            AND io.created_at BETWEEN ? AND ?
        ";
        
        $stats = [
            'gross_sales' => 0,
            'sales_returns' => 0,
            'net_sales' => 0,
            'active_cost' => 0,
            'gross_profit' => 0,
            'expenses' => 0,
            'purchase_returns' => 0,
            'final_net_profit' => 0,
            'profit_margin' => 0
        ];

        if ($stmt = $conn->prepare($sql_sales)) {
            $stmt->bind_param('ss', $start_sql, $end_sql);
            if ($stmt->execute()) {
                $res = $stmt->get_result()->fetch_assoc();
                $stats['sales_returns'] = floatval($res['total_items_returns_value'] ?? 0);
                $total_sales = floatval($res['total_items_sales'] ?? 0);
                $stats['net_sales'] = $total_sales - $stats['sales_returns'];
                $stats['active_cost'] = floatval($res['total_active_cost'] ?? 0);
                $stats['gross_profit'] = $stats['net_sales'] - $stats['active_cost'];
            }
            $stmt->close();
        }

        // Expenses
        $sql_exp = "SELECT COALESCE(SUM(amount), 0) as total_expenses FROM expenses WHERE expense_date BETWEEN ? AND ?";
        if ($stmt = $conn->prepare($sql_exp)) {
            $stmt->bind_param('ss', $start_date, $end_date);
            if ($stmt->execute()) {
                $stats['expenses'] = floatval($stmt->get_result()->fetch_assoc()['total_expenses'] ?? 0);
            }
            $stmt->close();
        }

        // Purchase Returns (Losses)
        $sql_pr = "
            SELECT COALESCE(SUM(total_amount), 0) as total_pr
            FROM purchase_returns
            WHERE return_type IN ('damaged', 'expired', 'other')
            AND status != 'cancelled'
            AND return_date BETWEEN ? AND ?
        ";
        if ($stmt = $conn->prepare($sql_pr)) {
            $stmt->bind_param('ss', $start_date, $end_date);
            if ($stmt->execute()) {
                $stats['purchase_returns'] = floatval($stmt->get_result()->fetch_assoc()['total_pr'] ?? 0);
            }
            $stmt->close();
        }

        // Final Calculation
        $stats['final_net_profit'] = $stats['gross_profit'] - $stats['expenses'] - $stats['purchase_returns'];
        
        if ($stats['net_sales'] > 0) {
            $stats['profit_margin'] = ($stats['final_net_profit'] / $stats['net_sales']) * 100;
        }

        echo json_encode(['ok' => true, 'stats' => $stats]);
        exit;
    }

    // --- Action: Get Sales Invoices ---
    if ($action === 'get_sales_invoices') {
        $status_filter = isset($_GET['status']) ? $_GET['status'] : '';
        $notes_search  = isset($_GET['notes']) ? trim($_GET['notes']) : '';
        $work_order_id = isset($_GET['work_order_id']) ? intval($_GET['work_order_id']) : 0;
        $inv_id_search = isset($_GET['invoice_id']) ? intval($_GET['invoice_id']) : 0;

        $sql_where = " WHERE io.created_at BETWEEN ? AND ? 
                       AND io.delivered NOT IN ('canceled', 'reverted')";
        $params = [$start_sql, $end_sql];
        $types = "ss";

        if ($status_filter) {
            if ($status_filter === 'paid') $sql_where .= " AND io.remaining_amount = 0";
            if ($status_filter === 'partial') $sql_where .= " AND io.paid_amount > 0 AND io.remaining_amount > 0";
            if ($status_filter === 'pending') $sql_where .= " AND io.remaining_amount > 0 AND io.paid_amount = 0";
        }

        if (!empty($notes_search)) {
            $sql_where .= " AND io.notes LIKE ?";
            $params[] = "%" . $notes_search . "%";
            $types .= "s";
        }

        if ($work_order_id > 0) {
            $sql_where .= " AND io.work_order_id = ?";
            $params[] = $work_order_id;
            $types .= "i";
        }
        
        if ($inv_id_search > 0) {
            $sql_where .= " AND io.id = ?";
            $params[] = $inv_id_search;
            $types .= "i";
        }

        $sql = "
            SELECT 
                io.id, io.created_at, io.notes,
                COALESCE(c.name, 'عميل نقدي') as customer_name,
                io.paid_amount, io.remaining_amount,
                io.total_after_discount,
                wo.title as work_order_title,
                
                SUM(ioi.total_after_discount) as items_sales,
                SUM(ioi.returned_quantity * ioi.unit_price_after_discount) as returns_value,
                SUM(CASE WHEN ioi.return_flag != 1 THEN (ioi.available_for_return * ioi.cost_price_per_unit) ELSE 0 END) as active_cost

            FROM invoices_out io
            LEFT JOIN customers c ON io.customer_id = c.id
            LEFT JOIN invoice_out_items ioi ON io.id = ioi.invoice_out_id
            LEFT JOIN work_orders wo ON io.work_order_id = wo.id
            $sql_where
            GROUP BY io.id
            ORDER BY io.created_at DESC
        ";

        $invoices = [];
        if ($stmt = $conn->prepare($sql)) {
            if ($types) $stmt->bind_param($types, ...$params);
            if ($stmt->execute()) {
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $net_sales = floatval($row['items_sales'] ?? 0) - floatval($row['returns_value'] ?? 0);
                    $active_cost = floatval($row['active_cost'] ?? 0);
                    $profit = $net_sales - $active_cost;

                    $status = 'pending';
                    if ($row['remaining_amount'] == 0) $status = 'paid';
                    elseif ($row['paid_amount'] > 0) $status = 'partial';

                    $row['net_sales'] = $net_sales;
                    $row['profit'] = $profit;
                    $row['active_cost'] = $active_cost;
                    $row['status'] = $status;
                    $invoices[] = $row;
                }
            }
            $stmt->close();
        }
        echo json_encode(['ok' => true, 'invoices' => $invoices]);
        exit;
    }
    
    // --- Action: Search Work Orders ---
    if ($action === 'search_work_orders') {
        $q = isset($_GET['q']) ? trim($_GET['q']) : '';
        $results = [];
        if ($q !== '') {
            $sql = "SELECT wo.id, wo.title, c.name as customer_name 
                    FROM work_orders wo
                    LEFT JOIN customers c ON wo.customer_id = c.id
                    WHERE wo.title LIKE ? OR wo.id = ?
                    LIMIT 20";
            $like = "%$q%";
            $id_search = is_numeric($q) ? intval($q) : -1;
            if ($stmt = $conn->prepare($sql)) {
                $stmt->bind_param('si', $like, $id_search);
                $stmt->execute();
                $res = $stmt->get_result();
                while($row = $res->fetch_assoc()){ $results[] = $row; }
                $stmt->close();
            }
        }
        echo json_encode(['ok' => true, 'results' => $results]);
        exit;
    }

    // --- Action: Get Expenses ---
    if ($action === 'get_expenses') {
        $sql = "SELECT e.*, ec.name as category_name, u.username as creator_name
                FROM expenses e
                LEFT JOIN expense_categories ec ON e.category_id = ec.id
                LEFT JOIN users u ON e.created_by = u.id
                WHERE e.expense_date BETWEEN ? AND ?
                ORDER BY e.expense_date DESC";
        $expenses = [];
        if ($stmt = $conn->prepare($sql)) {
            $stmt->bind_param("ss", $start_date, $end_date);
            if ($stmt->execute()) {
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) { $expenses[] = $row; }
            }
            $stmt->close();
        }
        echo json_encode(['ok' => true, 'expenses' => $expenses]);
        exit;
    }

    exit;
}

// =========================================================================
// 2. Main Page Output
// =========================================================================
require_once BASE_DIR . 'partials/header.php';
require_once BASE_DIR . 'partials/sidebar.php';
?>

<!-- Add SweetAlert2 & FontAwesome -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
    :root {
        --grad-1: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%);
        --grad-2: linear-gradient(135deg, #10b981 0%, #059669 100%);
        --grad-3: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
        --grad-4: linear-gradient(135deg, #ef4444 0%, #b91c1c 100%);
        --surface: #ffffff;
        --surface-2: #f8fafc;
        --border: #e2e8f0;
        --text: #1e293b;
        --text-muted: #64748b;
    }
    [data-theme="dark"] {
        --surface: #1e293b;
        --surface-2: #0f172a;
        --border: #334155;
        --text: #f8fafc;
        --text-muted: #94a3b8;
    }

    .page-wrapper { display: flex; gap: 20px; padding: 20px; height: calc(100vh - 70px); overflow: hidden; background: var(--bg); }
    .filters-sidebar { width: 280px; background: var(--surface); border: 1px solid var(--border); border-radius: 12px; padding: 20px; display: flex; flex-direction: column; flex-shrink: 0; overflow-y: auto; }
    .content-area { flex: 1; display: flex; flex-direction: column; gap: 20px; min-width: 0; overflow-y: auto; }

    .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; }
    .stat-card {
        background: var(--surface); border: 1px solid var(--border); border-radius: 12px; padding: 16px;
        position: relative; overflow: hidden; transition: transform 0.2s;
    }
    .stat-card:hover { transform: translateY(-2px); }
    .stat-card .label { font-size: 0.85rem; color: var(--text-muted); font-weight: 600; margin-bottom: 6px; }
    .stat-card .value { font-size: 1.5rem; font-weight: 800; color: var(--text); }
    .stat-card .sub { font-size: 0.75rem; color: var(--text-muted); opacity: 0.8; margin-top: 4px; display: block; }
    
    .stat-card.primary { border-top: 4px solid #6366f1; }
    .stat-card.success { border-top: 4px solid #10b981; }
    .stat-card.warning { border-top: 4px solid #f59e0b; }
    .stat-card.danger { border-top: 4px solid #ef4444; }

    .tabs-nav { display: flex; gap: 10px; margin-top: 10px; }
    .tab-btn {
        padding: 10px 20px; border-radius: 8px; background: var(--surface); border: 1px solid var(--border);
        color: var(--text-muted); cursor: pointer; font-weight: 600; transition: all 0.2s; display: flex; align-items: center; gap: 8px;
    }
    .tab-btn.active { background: #6366f1; color: white; border-color: #6366f1; }
    .tab-btn:hover:not(.active) { background: var(--surface-2); }

    .table-container { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; flex: 1; display: flex; flex-direction: column; overflow: hidden; }
    .table-header { padding: 15px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; background: var(--surface-2); }
    .table-scroll { overflow: auto; flex: 1; }
    .data-table { width: 100%; border-collapse: collapse; }
    .data-table th {
        position: sticky; top: 0; background: var(--surface-2); padding: 12px 16px; text-align: right; font-weight: 700;
        color: var(--text-muted); border-bottom: 1px solid var(--border); z-index: 5;
    }
    .data-table td { padding: 12px 16px; border-bottom: 1px solid var(--border); color: var(--text); font-size: 0.9rem; }
    .data-table tr:hover { background: rgba(99,102,241,0.04); }

    .badge { display: inline-block; padding: 4px 10px; border-radius: 6px; font-size: 0.75rem; font-weight: 700; }
    .badge-success { background: rgba(16,185,129,0.15); color: #059669; }
    .badge-danger { background: rgba(239,68,68,0.15); color: #b91c1c; }
    .badge-warning { background: rgba(245,158,11,0.15); color: #d97706; }
    .badge-info { background: rgba(99,102,241,0.15); color: #4f46e5; }
    .profit-badge { padding: 6px 12px; border-radius: 20px; font-weight: 800; font-size: 0.85rem; }
    .profit-pos { background: #dcfce7; color: #15803d; }
    .profit-neg { background: #fee2e2; color: #b91c1c; }
    .work-order-tag { font-size: 0.7rem; padding: 2px 6px; border-radius: 4px; background: #e0e7ff; color: #4338ca; margin-right: 5px; }

    .filter-group { margin-bottom: 15px; }
    .filter-group label { display: block; margin-bottom: 5px; font-weight: 600; color: var(--text-muted); font-size: 0.85rem; }
    .form-control { width: 100%; padding: 8px 12px; border-radius: 6px; border: 1px solid var(--border); background: var(--bg); color: var(--text); }
    .quick-dates { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; }
    .q-btn { padding: 6px; border: 1px solid var(--border); background: var(--surface-2); border-radius: 4px; cursor: pointer; font-size: 0.8rem; text-align: center; color: var(--text); }
    .q-btn:hover { border-color: #6366f1; color: #6366f1; }
    
    .autocomplete-wrapper { position: relative; }
    .suggestions-list {
        position: absolute; top: 100%; right: 0; left: 0; background: var(--surface); border: 1px solid var(--border);
        border-radius: 6px; z-index: 50; max-height: 200px; overflow-y: auto; display: none; box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    }
    .suggestions-list.active { display: block; }
    .suggestion-item { padding: 8px 12px; cursor: pointer; border-bottom: 1px solid var(--border); font-size: 0.85rem; }
    .suggestion-item:last-child { border-bottom: none; }
    .suggestion-item:hover { background: var(--surface-2); }

    .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 1000; display: none; align-items: center; justify-content: center; }
    .modal-overlay.open { display: flex; }
    .modal-box { background: var(--surface); width: 90%; max-width: 900px; max-height: 85vh; border-radius: 12px; display: flex; flex-direction: column; box-shadow: 0 10px 25px rgba(0,0,0,0.2); }
    .modal-header { padding: 15px 20px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; }
    .modal-body { padding: 20px; overflow-y: auto; }

    /* --- Professional Top Search --- */
    .top-search-section {
        background: var(--surface);
        border-radius: 15px;
        padding: 18px 25px;
        margin-bottom: 25px;
        box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.05);
        border: 1px solid var(--border);
        display: flex;
        align-items: center;
        gap: 20px;
        position: sticky;
        top: 0;
        z-index: 100;
        backdrop-filter: blur(10px);
    }
    .work-order-search-container { flex: 1; position: relative; }
    .work-order-search-container input {
        width: 100%; padding: 12px 45px 12px 45px; border-radius: 12px;
        border: 2px solid var(--border); background: var(--surface-2);
        font-size: 1rem; transition: all 0.3s; font-weight: 600;
    }
    .work-order-search-container input:focus { border-color: var(--primary); box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1); background: var(--surface); }
    .work-order-search-container i.search-icon { position: absolute; right: 15px; top: 50%; transform: translateY(-50%); color: var(--text-muted); font-size: 1.1rem; pointer-events: none; }
    
    .clear-search-btn {
        position: absolute; left: 15px; top: 50%; transform: translateY(-50%);
        color: #ef4444; cursor: pointer; font-size: 1.1rem; display: none;
        transition: all 0.2s; padding: 5px; z-index: 5;
    }
    .clear-search-btn:hover { transform: translateY(-50%) scale(1.1); color: #dc2626; }

    .wo-suggestions {
        position: absolute; top: calc(100% + 5px); right: 0; left: 0;
        background: var(--surface); border: 1px solid var(--border); border-radius: 10px;
        box-shadow: 0 10px 25px rgba(0,0,0,0.1); max-height: 400px;
        overflow-y: auto; z-index: 1000; display: none;
    }
    .wo-item { padding: 12px 15px; border-bottom: 1px solid var(--border); cursor: pointer; transition: background 0.2s; display: flex; justify-content: space-between; align-items: center; }
    .wo-item:hover { background: rgba(99, 102, 241, 0.05); }
    .wo-item:last-child { border-bottom: none; }
    .wo-item-info { display: flex; flex-direction: column; gap: 4px; }
    .wo-item-title { font-weight: 800; color: var(--primary); font-size: 0.95rem; }
    .wo-item-customer { font-size: 0.85rem; color: var(--text-muted); }
    .wo-item-status { padding: 4px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; }
    
    .status-pending-bg { background: #fef3c7; color: #92400e; }
    .status-in_progress-bg { background: #e0f2fe; color: #0369a1; }
    .status-completed-bg { background: #d1fae5; color: #065f46; }
    .status-cancelled-bg { background: #fee2e2; color: #991b1b; }
</style>

<div class="page-wrapper">
    <!-- Sidebar -->
    <aside class="filters-sidebar">
        <div class="d-flex justify-content-between align-items-center mb-4">
             <h3 style="color:var(--text); font-weight:700; margin:0;"><i class="fas fa-filter text-primary"></i> فلاتر</h3>
             <button class="btn btn-sm btn-outline-danger" onclick="resetFilters()" title="إعادة تعيين"><i class="fas fa-undo"></i></button>
        </div>
        
        <div class="filter-group">
            <label>الفترة الزمنية</label>
            <div class="quick-dates mb-2">
                <div class="q-btn" onclick="setDateRange('today')">اليوم</div>
                <div class="q-btn" onclick="setDateRange('week')">أسبوع</div>
                <div class="q-btn" onclick="setDateRange('month')">شهر</div>
                <div class="q-btn" onclick="setDateRange('year')">سنة</div>
            </div>
            <input type="date" id="start_date" class="form-control mb-2" value="<?= date('Y-m-d') ?>">
            <input type="date" id="end_date" class="form-control" value="<?= date('Y-m-d') ?>">
        </div>

        <button class="btn btn-primary w-100" onclick="reloadData()">
            <i class="fas fa-search"></i> تطبيق
        </button>
        
        <hr style="border-color:var(--border); margin: 20px 0;">
        
        <!-- Smart Filters Section -->
        <div id="sales_filters" class="tab-filters">
            <h5 class="text-muted text-sm mb-3">فلاتر المبيعات</h5>
            <div class="filter-group">
                <label>رقم الفاتورة</label>
                <input type="text" id="filter_invoice_id" class="form-control" placeholder="123.." onkeyup="if(event.key === 'Enter') reloadData()">
            </div>
            <div class="filter-group">
                <label>بحث في الملاحظات</label>
                <input type="text" id="filter_notes" class="form-control" placeholder="بحث..." onkeyup="if(event.key === 'Enter') reloadData()">
            </div>
            <div class="filter-group autocomplete-wrapper">
                <label>شغلانة (Work Order)</label>
                <input type="text" id="filter_work_order_input" class="form-control" placeholder="ابحث باسم الشغلانة..." autocomplete="off">
                <input type="hidden" id="filter_work_order_id">
                <div id="wo_suggestions" class="suggestions-list"></div>
                <small class="text-xs text-muted mt-1 cursor-pointer" onclick="clearWorkOrderFilter()" style="display:none" id="btn_clear_wo">
                    <i class="fas fa-times"></i> إلغاء تحديد الشغلانة
                </small>
            </div>
            <div class="filter-group">
                <label>حالة الدفع</label>
                <select id="sales_status" class="form-control">
                    <option value="">الكل</option>
                    <option value="paid">مدفوع بالكامل</option>
                    <option value="partial">مدفوع جزئياً</option>
                    <option value="pending">غير مدفوع (آجل)</option>
                </select>
            </div>
        </div>
        
        <div id="returns_filters" class="tab-filters" style="display:none;">
            <h5 class="text-muted text-sm mb-3">فلاتر المرتجعات</h5>
            <div class="filter-group">
                <label>نوع المرتجع</label>
                <select id="return_type" class="form-control">
                    <option value="">(الكل ما عدا المورد)</option>
                    <option value="damaged">تالف (Damaged)</option>
                    <option value="expired">منتهي الصلاحية (Expired)</option>
                    <option value="other">أخرى (Other)</option>
                </select>
            </div>
            <small class="text-muted" style="font-size:0.75rem;">* مرتجعات الموردين مخفية تلقائياً</small>
        </div>

        <div id="expenses_filters" class="tab-filters" style="display:none;">
             <h5 class="text-muted text-sm mb-3">فلاتر المصروفات</h5>
             <small class="text-muted">عرض المصروفات للفترة المحددة</small>
        </div>
    </aside>

    <!-- Content -->
    <main class="content-area">
        <!-- Header & Stats -->
        <div>
            <div style="background: linear-gradient(135deg, #1e1b4b 0%, #312e81 100%); color: white; padding: 25px; border-radius: 16px; margin-bottom: 25px; display: flex; align-items: center; justify-content: space-between; box-shadow: 0 4px 20px rgba(49, 46, 129, 0.25); border: 1px solid #4338ca;">
                <div>
                    <h2 style="margin: 0; font-weight: 800; font-size: 1.8rem; color: #fff; display: flex; align-items: center; gap: 10px;">
                        <span style="background: rgba(255,255,255,0.1); padding: 8px; border-radius: 10px;"><i class="fas fa-chart-pie text-warning"></i></span>
                        تقرير صافي الربح الشامل
                    </h2>
                    <p style="margin: 8px 0 0 0; opacity: 0.9; font-size: 0.95rem; line-height: 1.5;">
                        <i class="fas fa-info-circle ml-1"></i> لوحة التحكم المالية المركزية: تتبع <strong>صافي المبيعات</strong>، واخصم منها <strong>التكاليف، المصروفات، وخسائر المرتجع</strong> للوصول إلى الربح الحقيقي.
                    </p>
                </div>
                <div style="font-size: 3.5rem; opacity: 0.1; transform: rotate(-10deg);">
                    <i class="fas fa-file-invoice-dollar"></i>
                </div>
            </div>

            <!-- البحث العلوي الذكي الجديد -->
            <div class="top-search-section">
                <div class="work-order-search-container">
                    <i class="fas fa-search search-icon"></i>
                    <input type="text" id="woTopSearch" placeholder="ابحث عن شغلانة بالاسم أو الرقم في هذه الفترة..." autocomplete="off">
                    <i class="fas fa-times-circle clear-search-btn" id="clearTopSearch"></i>
                    <div class="wo-suggestions" id="woTopSuggestions"></div>
                </div>
            </div>
            
            <div class="stats-grid">
                <!-- 1. Net Sales -->
                <div class="stat-card primary">
                    <div class="label">صافي المبيعات (Net Revenue)</div>
                    <div class="value" id="s_net_sales">0.00</div>
                    <div class="sub">بعد الخصم والمرتجع</div>
                </div>
                
                <!-- 2. Cost -->
                <div class="stat-card warning">
                    <div class="label">تكلفة البضاعة (COGS)</div>
                    <div class="value" id="s_cost">0.00</div>
                    <div class="sub">التكلفة الفعلية</div>
                </div>

                <!-- 3. Gross Profit (NEW) -->
                <div class="stat-card success">
                    <div class="label">مجمل الربح (Gross Profit)</div>
                    <div class="value" id="s_gross_profit">0.00</div>
                    <div class="sub">من الفواتير فقط</div>
                </div>

                <!-- 4. Expenses -->
                <div class="stat-card danger">
                    <div class="label">المصروفات (Expenses)</div>
                    <div class="value" id="s_expenses">0.00</div>
                    <div class="sub">إجمالي المصاريف</div>
                </div>

                <!-- 5. Purchase Returns -->
                <div class="stat-card danger">
                    <div class="label">خسائر المرتجع (Losses)</div>
                    <div class="value" id="s_pr">0.00</div>
                    <div class="sub">تالف / إكسبير / أخرى</div>
                </div>

                <!-- 6. Final Net Profit -->
                <div class="stat-card" id="card_final_profit" style="grid-column: span 3; border-top-width: 4px;">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="label" style="font-size:1rem;">صافي الربح النهائي (Net Profit)</div>
                            <div class="value" id="s_final" style="font-size:2rem;">0.00</div>
                            <div class="sub" style="font-size:0.9rem;">صافي المبيعات - (التكلفة + المصاريف + خسائر المرتجع (Losses))</div>
                        </div>
                        <div class="text-right">
                            <div id="profit_margin_badge" style="background:rgba(0,0,0,0.1); padding:5px 15px; border-radius:12px; font-weight:bold; font-size:1.5rem;">
                                0%
                            </div>
                            <small class="d-block text-center mt-1 text-muted">هامش الربح</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tabs -->
        <div class="tabs-nav">
            <div class="tab-btn active" onclick="switchTab('sales', this)">
                <i class="fas fa-file-invoice-dollar"></i> فواتير المبيعات
            </div>
            <div class="tab-btn" onclick="switchTab('expenses', this)">
                <i class="fas fa-receipt"></i> سجل المصروفات
            </div>
            <div class="tab-btn" onclick="switchTab('returns', this)">
                <i class="fas fa-undo-alt"></i> سجل خسائر المرتجع
            </div>
        </div>

        <!-- Sales Table -->
        <div id="tab_sales" class="table-container">
            <div class="table-header">
                <span class="font-bold text-muted">سجل المبيعات</span>
                <span class="badge badge-info" id="sales_count">0 فاتورة</span>
            </div>
            <div class="table-scroll">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th># / التفاصيل</th>
                            <th>التاريخ</th>
                            <th>العميل</th>
                            <th>الحالة</th>
                            <th>صافي البيع</th>
                            <th>التكلفة</th>
                            <th>صافي الربح</th>
                        </tr>
                    </thead>
                    <tbody id="sales_tbody"></tbody>
                </table>
            </div>
        </div>
        
        <!-- Expenses Table -->
        <div id="tab_expenses" class="table-container" style="display:none;">
            <div class="table-header">
                <span class="font-bold text-muted">سجل المصروفات</span>
                <span class="badge badge-danger" id="expenses_count">0 مصروف</span>
            </div>
            <div class="table-scroll">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>التاريخ</th>
                            <th>البند / الوصف</th>
                            <th>الفئة</th>
                            <th>بواسطة</th>
                            <th>القيمة</th>
                        </tr>
                    </thead>
                    <tbody id="expenses_tbody"></tbody>
                </table>
            </div>
        </div>

        <!-- Returns Table -->
        <div id="tab_returns" class="table-container" style="display:none;">
            <div class="table-header">
                <span class="font-bold text-muted">سجل مرتجعات المشتريات (تالف/أخرى)</span>
                <span class="badge badge-warning" id="returns_count">0 مرتجع</span>
            </div>
            <div class="table-scroll">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>رقم المرتجع</th>
                            <th>المورد</th>
                            <th>النوع</th>
                            <th>التاريخ</th>
                            <th>الكمية</th> <!-- Added Qty -->
                            <th>القيمة</th>
                            <th>الحالة</th>
                            <th>عرض</th>
                        </tr>
                    </thead>
                    <tbody id="returns_tbody"></tbody>
                </table>
            </div>
        </div>

    </main>
</div>

<!-- Modal for Return Details -->
<div class="modal-overlay" id="returnModal">
    <div class="modal-box">
        <div class="modal-header">
            <h4 style="margin:0">تفاصيل المرتجع</h4>
            <button onclick="closeModal()" class="btn btn-sm btn-light"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body" id="returnModalBody"></div>
    </div>
</div>

<script>
    let activeTab = 'sales';
    const API_RETURNS = '../api/purchase/api_purchase_returns.php';
    let woTimer = null;

    document.addEventListener('DOMContentLoaded', () => {
        reloadData();
        setupWorkOrderSearch();
    });

    function setupWorkOrderSearch() {
        const woInput = document.getElementById('woTopSearch');
        const suggestionsBox = document.getElementById('woTopSuggestions');
        const clearBtn = document.getElementById('clearTopSearch');
        
        // ربط مع الفلاتر الجانبية
        const sideWoInput = document.getElementById('filter_work_order_input');
        const sideWoId = document.getElementById('filter_work_order_id');
        const sideClearBtn = document.getElementById('btn_clear_wo');

        const resetSearch = () => {
            if (woInput) woInput.value = '';
            if (clearBtn) clearBtn.style.display = 'none';
            if (suggestionsBox) suggestionsBox.style.display = 'none';
            
            // مسح قيم الفلترة
            if (sideWoInput) sideWoInput.value = '';
            if (sideWoId) sideWoId.value = '';
            if (sideClearBtn) sideClearBtn.style.display = 'none';
            
            reloadData();
        };

        woInput.addEventListener('input', function(e) {
            const query = e.target.value.trim();
            if (woTimer) clearTimeout(woTimer);

            if (query.length > 0) {
                if (clearBtn) clearBtn.style.display = 'block';
            } else {
                resetSearch();
                return;
            }

            woTimer = setTimeout(async () => {
                try {
                    const start = document.getElementById('start_date').value;
                    const end = document.getElementById('end_date').value;
                    
                    suggestionsBox.innerHTML = '<div class="wo-item" style="justify-content: center; color: var(--text-muted);"><i class="fas fa-spinner fa-spin"></i> جاري البحث...</div>';
                    suggestionsBox.style.display = 'block';

                    const response = await fetch(`../api/search_work_orders.php?query=${encodeURIComponent(query)}&start_date=${start}&end_date=${end}`);
                    const data = await response.json();
                    
                    if (data.success && data.suggestions.length > 0) {
                        suggestionsBox.innerHTML = data.suggestions.map(wo => `
                            <div class="wo-item" data-id="${wo.id}" data-title="${wo.title}">
                                <div class="wo-item-info">
                                    <span class="wo-item-title">#${wo.id} - ${wo.title}</span>
                                    <span class="wo-item-customer"><i class="fas fa-user"></i> ${wo.customer_name}</span>
                                </div>
                                <span class="wo-item-status status-${wo.status}-bg">${wo.status_text}</span>
                            </div>
                        `).join('');

                        suggestionsBox.querySelectorAll('.wo-item').forEach(item => {
                            item.addEventListener('click', () => {
                                const id = item.dataset.id;
                                const title = item.dataset.title;
                                
                                // تحديث الحقول
                                woInput.value = `#${id} - ${title}`;
                                if (sideWoInput) sideWoInput.value = title;
                                if (sideWoId) sideWoId.value = id;
                                if (sideClearBtn) sideClearBtn.style.display = 'block';
                                
                                suggestionsBox.style.display = 'none';
                                reloadData();
                            });
                        });
                    } else {
                        suggestionsBox.innerHTML = '<div class="wo-item" style="justify-content: center; color: var(--text-muted); flex-direction: column; gap: 8px; padding: 20px;">' + 
                                                   '<i class="fas fa-search-minus fa-2x"></i>' +
                                                   '<span>لا توجد نتائج في هذه الفترة</span>' +
                                                   '<small style="font-size: 0.75rem;">تأكد من اختيار الفترة الصحيحة</small>' +
                                                   '</div>';
                    }
                } catch (error) {
                    console.error('Error:', error);
                    suggestionsBox.style.display = 'none';
                }
            }, 300);
        });

        if (clearBtn) clearBtn.addEventListener('click', resetSearch);

        document.addEventListener('click', (e) => {
            if (!woInput.contains(e.target) && !suggestionsBox.contains(e.target) && (!clearBtn || !clearBtn.contains(e.target))) {
                suggestionsBox.style.display = 'none';
            }
        });
    }

    function selectWorkOrder(id, title, customer) {
        document.getElementById('filter_work_order_input').value = title;
        document.getElementById('filter_work_order_id').value = id;
        document.getElementById('wo_suggestions').classList.remove('active');
        document.getElementById('btn_clear_wo').style.display = 'block';
        reloadData();
    }

    function clearWorkOrderFilter() {
        if (document.getElementById('woTopSearch')) document.getElementById('woTopSearch').value = '';
        if (document.getElementById('clearTopSearch')) document.getElementById('clearTopSearch').style.display = 'none';
        document.getElementById('filter_work_order_input').value = '';
        document.getElementById('filter_work_order_id').value = '';
        document.getElementById('btn_clear_wo').style.display = 'none';
        reloadData();
    }
    
    function resetFilters() {
        // Reset Inputs
        document.getElementById('start_date').value = new Date().toISOString().split('T')[0];
        document.getElementById('end_date').value = new Date().toISOString().split('T')[0];
        document.getElementById('filter_invoice_id').value = '';
        document.getElementById('filter_notes').value = '';
        document.getElementById('sales_status').value = '';
        document.getElementById('return_type').value = '';
        clearWorkOrderFilter(); // handles work order clear
        reloadData();
    }

    function setDateRange(type) {
        const today = new Date();
        const startEl = document.getElementById('start_date');
        const endEl = document.getElementById('end_date');
        endEl.value = today.toISOString().split('T')[0];
        let d = new Date();
        if (type === 'week') d.setDate(d.getDate() - 7);
        else if (type === 'month') d.setMonth(d.getMonth() - 1);
        else if (type === 'year') d.setFullYear(d.getFullYear() - 1);
        startEl.value = d.toISOString().split('T')[0];
        reloadData();
    }

    function switchTab(tab, btn) {
        activeTab = tab;
        document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        ['sales', 'returns', 'expenses'].forEach(t => {
            document.getElementById(`tab_${t}`).style.display = (tab === t) ? 'flex' : 'none';
            const f = document.getElementById(`${t}_filters`);
            if(f) f.style.display = (tab === t) ? 'block' : 'none';
        });
        reloadData(); 
    }

    async function reloadData() {
        const start = document.getElementById('start_date').value;
        const end = document.getElementById('end_date').value;
        loadStats(start, end);

        if (activeTab === 'sales') {
            const status = document.getElementById('sales_status').value;
            const notes = document.getElementById('filter_notes').value;
            const woId = document.getElementById('filter_work_order_id').value;
            const invId = document.getElementById('filter_invoice_id').value;
            loadSales(start, end, status, notes, woId, invId);
        } else if (activeTab === 'expenses') {
            loadExpenses(start, end);
        } else {
            const type = document.getElementById('return_type').value;
            loadReturns(start, end, type);
        }
    }

    async function loadStats(start, end) {
        try {
            const res = await fetch(`net_profit_report.php?action=get_stats&start_date=${start}&end_date=${end}`);
            const data = await res.json();
            if (data.ok) {
                const s = data.stats;
                const fmt = (n) => parseFloat(n).toLocaleString('en-US', {minimumFractionDigits: 2});
                document.getElementById('s_net_sales').textContent = fmt(s.net_sales);
                document.getElementById('s_cost').textContent = fmt(s.active_cost);
                document.getElementById('s_gross_profit').textContent = fmt(s.gross_profit); // NEW
                document.getElementById('s_expenses').textContent = fmt(s.expenses);
                document.getElementById('s_pr').textContent = fmt(s.purchase_returns);
                document.getElementById('s_final').textContent = fmt(s.final_net_profit) + ' ج.م';
                
                const card = document.getElementById('card_final_profit');
                const badge = document.getElementById('profit_margin_badge');
                const isPos = s.final_net_profit >= 0;
                card.style.borderColor = isPos ? '#10b981' : '#ef4444';
                badge.style.background = isPos ? '#dcfce7' : '#fee2e2';
                badge.style.color = isPos ? '#15803d' : '#b91c1c';
                badge.textContent = parseFloat(s.profit_margin).toFixed(1) + '%';
            }
        } catch(e) { console.error(e); }
    }

    async function loadSales(start, end, status, notes, woId, invId) {
        const tbody = document.getElementById('sales_tbody');
        tbody.innerHTML = '<tr><td colspan="7" class="text-center p-3 text-muted">جاري التحميل...</td></tr>';
        
        try {
            let u = `net_profit_report.php?action=get_sales_invoices&start_date=${start}&end_date=${end}&status=${status}`;
            if(notes) u += `&notes=${encodeURIComponent(notes)}`;
            if(woId) u += `&work_order_id=${woId}`;
            if(invId) u += `&invoice_id=${invId}`;

            const res = await fetch(u);
            const data = await res.json();
            if (data.ok) {
                const list = data.invoices;
                document.getElementById('sales_count').textContent = list.length + ' فاتورة';
                if (list.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="7" class="text-center p-3 text-muted">لا توجد بيانات</td></tr>';
                    return;
                }
                const fmt = (n) => parseFloat(n).toFixed(2);
                tbody.innerHTML = list.map(inv => {
                    const isProfit = inv.profit >= 0;
                    const profitClass = isProfit ? 'profit-pos' : 'profit-neg';
                    let details = `<span class="font-bold text-primary">#${inv.id}</span>`;
                    if(inv.work_order_title) details += `<div class="mt-1"><span class="work-order-tag"><i class="fas fa-briefcase"></i> ${inv.work_order_title}</span></div>`;
                    if(inv.notes) details += `<div class="mt-1 text-muted text-xs" style="font-size:0.8rem;"><i class="fas fa-sticky-note"></i> ${inv.notes}</div>`;
                    return `<tr>
                        <td>${details}</td>
                        <td style="font-size:0.85rem">${inv.created_at.split(' ')[0]}</td>
                        <td style="font-size:0.9rem">${inv.customer_name}</td>
                        <td><span class="badge ${getStatusBadge(inv.status)}">${getStatusLabel(inv.status)}</span></td>
                        <td class="font-bold text-primary">${fmt(inv.net_sales)}</td>
                        <td class="text-warning">${fmt(inv.active_cost)}</td>
                        <td><span class="profit-badge ${profitClass}">${fmt(inv.profit)}</span></td>
                    </tr>`;
                }).join('');
            }
        } catch(e) { console.error(e); tbody.innerHTML = '<tr><td colspan="7" class="text-center text-danger">خطأ</td></tr>'; }
    }

    async function loadExpenses(start, end) {
        const tbody = document.getElementById('expenses_tbody');
        tbody.innerHTML = '<tr><td colspan="6" class="text-center p-3 text-muted">جاري التحميل...</td></tr>';
        try {
            const res = await fetch(`net_profit_report.php?action=get_expenses&start_date=${start}&end_date=${end}`);
            const data = await res.json();
            if (data.ok) {
                const list = data.expenses;
                document.getElementById('expenses_count').textContent = list.length + ' مصروف';
                if(list.length === 0) { tbody.innerHTML = '<tr><td colspan="6" class="text-center p-3 text-muted">لا توجد مصروفات</td></tr>'; return; }
                tbody.innerHTML = list.map((ex, idx) => `<tr>
                        <td>${idx + 1}</td>
                        <td>${ex.expense_date.split(' ')[0]}</td>
                        <td>${ex.description}</td>
                        <td><span class="badge badge-warning">${ex.category_name||'-'}</span></td>
                        <td><small>${ex.creator_name||'-'}</small></td>
                        <td class="font-bold text-danger">-${parseFloat(ex.amount).toFixed(2)}</td>
                    </tr>`).join('');
            }
        } catch(e) { console.error(e); }
    }

    async function loadReturns(start, end, type) {
        const tbody = document.getElementById('returns_tbody');
        tbody.innerHTML = '<tr><td colspan="9" class="text-center p-3 text-muted">جاري التحميل...</td></tr>'; // updated colspan
        try {
            let url = `${API_RETURNS}?action=list_returns&start_date=${start}&end_date=${end}&exclude_type=supplier_return`;
            if (type) url += `&type_filter_val=${type}`;
            const res = await fetch(url);
            const data = await res.json();
            if (data.success) {
                const list = data.returns;
                document.getElementById('returns_count').textContent = list.length + ' مرتجع';
                if (list.length === 0) { tbody.innerHTML = '<tr><td colspan="9" class="text-center p-3 text-muted">لا توجد مرتجعات (خسائر)</td></tr>'; return; }
                const fmt = (n) => parseFloat(n).toFixed(2);
                tbody.innerHTML = list.map(r => `<tr>
                        <td>#${r.id}</td>
                        <td class="font-bold">${r.return_number}</td>
                        <td>${r.supplier_name}</td>
                        <td><span class="badge badge-info">${getReturnTypeLabel(r.return_type)}</span></td>
                        <td>${r.return_date}</td>
                        <td class="font-bold">-</td> <!-- Qty placeholder since it's total of items -->
                        <td class="font-bold text-danger">${fmt(r.total_amount)}</td>
                        <td>${getReturnStatusBadge(r.status)}</td>
                        <td><button class="btn btn-sm btn-light border" onclick="viewReturn(${r.id})"><i class="fas fa-eye text-primary"></i></button></td>
                    </tr>`).join('');
            }
        } catch(e) { console.error(e); tbody.innerHTML = '<tr><td colspan="9" class="text-center text-danger">خطأ في الاتصال</td></tr>'; }
    }

    function getStatusBadge(s) { 
        if(s==='paid') return 'badge-success'; 
        if(s==='partial') return 'badge-warning'; 
        return 'badge-danger'; 
    }
    function getStatusLabel(s) { 
        if(s==='paid') return 'مدفوع'; 
        if(s==='partial') return 'جزئي'; 
        return 'آجل'; 
    }
    function getReturnTypeLabel(t) { 
        const types = { 'supplier_return':'إرجاع مورد', 'damaged':'تلف', 'expired':'إكسبير', 'other':'أخرى' }; 
        return types[t] || t; 
    }
    function getReturnStatusBadge(s) { 
        if(s==='completed'||s==='approved') return '<span class="badge badge-success">مكتمل</span>';
        if(s==='cancelled') return '<span class="badge badge-danger">ملغي</span>';
        return '<span class="badge badge-warning">انتظار</span>';
    }

    function closeModal() { document.getElementById('returnModal').classList.remove('open'); }
    async function viewReturn(id) {
        document.getElementById('returnModal').classList.add('open');
        const body = document.getElementById('returnModalBody');
        body.innerHTML = '<div class="text-center"><i class="fas fa-spinner fa-spin"></i> تحميل البيانات...</div>';
        try {
            const res = await fetch(`${API_RETURNS}?action=fetch_return&id=${id}`);
            const data = await res.json();
            if (data.success) {
                const r = data.return;
                const items = data.items;
                const fmt = (n) => parseFloat(n).toFixed(2);
                let html = `<div class="row mb-3" style="padding:15px; border-radius:8px;">
                        <div class="col-6 mb-2"><strong>رقم المرتجع:</strong> ${r.return_number}</div>
                        <div class="col-6 mb-2"><strong>المورد:</strong> ${r.supplier_name}</div>
                        <div class="col-6"><strong>التاريخ:</strong> ${r.return_date}</div>
                        <div class="col-6"><strong>النوع:</strong> <span class="badge badge-info">${getReturnTypeLabel(r.return_type)}</span></div>
                        <div class="col-12 mt-2"><strong>السبب العام:</strong> ${r.return_reason || '-'}</div>
                    </div>
                    <h6>البنود المرتجعة</h6>
                    <div class="table-responsive"><table class="table table-bordered table-sm text-center">
                        <thead class="bg-light"><tr><th>المنتج</th><th>كود المنتج</th><th>الدفعة (Batch)</th><th>الكمية</th><th>السعر</th><th>الإجمالي</th></tr></thead><tbody>`;
                items.forEach(i => {
                    const price = i.unit_price ? fmt(i.unit_price) : '0.00';
                    const total = i.line_total ? fmt(i.line_total) : '0.00';
                    let batchDisplay = i.batch_number || `ID:${i.batch_id}`; // Fallback if number is null or missing
                    if(!i.batch_number && i.batch_qty) batchDisplay = 'Batch#' + i.batch_id; // Cleaner fallback

                    let prodCell = i.product_name;
                    if(i.reason) prodCell += `<br><small class='text-muted'>${i.reason}</small>`;
                    html += `<tr><td class="text-start">${prodCell}</td><td>${i.product_code || '-'}</td><td>${batchDisplay}</td><td class="font-bold">${i.quantity}</td><td>${price}</td><td class="font-bold">${total}</td></tr>`;
                });
                html += `</tbody></table></div>`;
                body.innerHTML = html;
            } else { body.innerHTML = `<div class="text-danger">فشل جلب التفاصيل: ${data.message}</div>`; }
        } catch(e) { body.innerHTML = '<div class="text-danger">خطأ في الاتصال</div>'; }
    }
    document.getElementById('returnModal').addEventListener('click', (e) => {
        if (e.target === document.getElementById('returnModal')) closeModal();
    });
</script>

<?php require_once BASE_DIR . 'partials/footer.php'; ?>