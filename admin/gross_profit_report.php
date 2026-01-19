<?php
// admin/gross_profit_report.php
// تقرير الأرباح المتقدم - النسخة النهائية مع AJAX والفلاتر المتقدمة والسايدبار الذكي

$page_title = "تقرير الأرباح التفصيلي";
$class_reports = "active";
$class_gross_profit = "active"; // For sidebar highlight

require_once dirname(__DIR__) . '/config.php';
require_once BASE_DIR . 'partials/session_admin.php';

date_default_timezone_set('Africa/Cairo');

// =========================================================================
// 1. AJAX Handler: Filter Results (Statistics & Table)
// =========================================================================
if (isset($_GET['action']) && $_GET['action'] === 'filter_results') {
    header('Content-Type: application/json');

    // --- Filters ---
    $status_filter     = isset($_GET['status']) ? $_GET['status'] : '';
    $customer_filter   = isset($_GET['customer']) ? trim($_GET['customer']) : '';
    $work_order_filter = isset($_GET['work_order']) ? trim($_GET['work_order']) : '';
    $notes_filter      = isset($_GET['notes']) ? trim($_GET['notes']) : '';
    $invoice_filter    = isset($_GET['invoice_id']) ? trim($_GET['invoice_id']) : '';
    $advanced_search   = isset($_GET['advanced_search']) ? trim($_GET['advanced_search']) : '';
    
    // Dates
    $start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d');
    $end_date   = isset($_GET['end_date'])   ? $_GET['end_date']   : date('Y-m-d');

    // --- SQL Construction ---
    $sql_where = " WHERE io.created_at BETWEEN ? AND ? 
                   AND io.delivered NOT IN ('canceled', 'reverted')";
    $params = [$start_date . " 00:00:00", $end_date . " 23:59:59"];
    $param_types = "ss";

    // Status Filter
    if (!empty($status_filter)) {
        if ($status_filter === 'paid') {
            $sql_where .= " AND io.remaining_amount = 0";
        } elseif ($status_filter === 'partial') {
            $sql_where .= " AND io.paid_amount > 0 AND io.remaining_amount > 0";
        } elseif ($status_filter === 'pending') {
            $sql_where .= " AND io.remaining_amount > 0 AND io.paid_amount = 0";
        }
    }

    // Advanced Search (Global)
    if (!empty($advanced_search)) {
        $sql_where .= " AND (io.id LIKE ? OR c.name LIKE ? OR io.notes LIKE ?)";
        $term = "%$advanced_search%";
        $params[] = $term; $params[] = $term; $params[] = $term;
        $param_types .= "sss";
    }

    // Specific Filters
    if (!empty($customer_filter)) {
        $sql_where .= " AND (c.name LIKE ? OR c.mobile LIKE ?)";
        $params[] = "%$customer_filter%"; $params[] = "%$customer_filter%";
        $param_types .= "ss";
    }
    if (!empty($work_order_filter)) {
        // Can be ID or Title
        if (is_numeric($work_order_filter)) {
             $sql_where .= " AND (wo.id = ? OR wo.title LIKE ?)";
             $params[] = $work_order_filter;
             $params[] = "%$work_order_filter%";
             $param_types .= "is";
        } else {
             $sql_where .= " AND wo.title LIKE ?";
             $params[] = "%$work_order_filter%";
             $param_types .= "s";
        }
    }
    if (!empty($notes_filter)) {
        $sql_where .= " AND io.notes LIKE ?";
        $params[] = "%$notes_filter%";
        $param_types .= "s";
    }
    if (!empty($invoice_filter)) {
        $sql_where .= " AND io.id = ?";
        $params[] = $invoice_filter;
        $param_types .= "i";
    }

    // Basic Query
    // Note: We need to calculate totals from Items table properly
    // Requirement 3: "Net Final Sales like sales page" (i.e. Invoice Total - Returns)
    // Requirement 3: "Active Cost based on available_for_return"
    
    $sql = "SELECT 
                io.id, 
                io.created_at, 
                io.customer_id, 
                COALESCE(c.name, 'عميل نقدي') as customer_name, 
                c.mobile,
                io.paid_amount, 
                io.remaining_amount, 
                io.notes,
                io.total_after_discount as invoice_total_after,
                io.delivered,
                wo.title as work_order_title,
                
                -- Aggregates from Items
                SUM(CASE WHEN ioi.return_flag != 1 THEN (ioi.available_for_return * ioi.cost_price_per_unit) ELSE 0 END) as total_active_cost,
                SUM(ioi.returned_quantity * ioi.unit_price_after_discount) as total_returns_value,
                SUM(ioi.total_after_discount) as total_items_sales,
                SUM(ioi.discount_amount) as total_items_discount,

                -- Calculated fields
                CASE 
                    WHEN io.remaining_amount = 0 THEN 'paid'
                    WHEN io.paid_amount > 0 AND io.remaining_amount > 0 THEN 'partial'
                    ELSE 'pending'
                END AS payment_status

            FROM invoices_out io
            LEFT JOIN customers c ON io.customer_id = c.id
            LEFT JOIN work_orders wo ON io.work_order_id = wo.id
            LEFT JOIN invoice_out_items ioi ON io.id = ioi.invoice_out_id
            $sql_where
            GROUP BY io.id
            ORDER BY io.created_at DESC
            LIMIT 500"; 

    $invoices = [];
    $stats = [
        'net_revenue' => 0,
        'gross_profit' => 0,
        'total_cost' => 0,
        'total_returns' => 0,
        'total_discounts' => 0,
        'count' => 0
    ];
    $ids = [];

    if ($stmt = $conn->prepare($sql)) {
        if (!empty($param_types)) $stmt->bind_param($param_types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $returns_value = floatval($row['total_returns_value']);
            $items_sales   = floatval($row['total_items_sales']);
            $active_cost   = floatval($row['total_active_cost']);
            $items_disc    = floatval($row['total_items_discount']);
            
            // Net Sales = Total Sales (Items) - Returns Value (Items)
            $net_sales = $items_sales - $returns_value;
            
            // Profit = Net Sales - Active Cost
            $profit = $net_sales - $active_cost;
            
            // Row Data
            $row['net_sales'] = $net_sales;     
            $row['profit'] = $profit;           
            $row['active_cost'] = $active_cost; 
            
            // Accumulate Stats
            $stats['net_revenue'] += $net_sales;
            $stats['total_cost']  += $active_cost;
            $stats['gross_profit']+= $profit;
            $stats['total_returns']+= $returns_value;
            $stats['total_discounts']+= $items_disc;
            $stats['count']++;
            
            $invoices[] = $row;
            $ids[] = $row['id'];
        }
        $stmt->close();
    }

    echo json_encode([
        'ok' => true,
        'invoices' => $invoices,
        'stats' => $stats,
        'ids' => $ids
    ]);
    exit;
}

// =========================================================================
// 2. AJAX Handler: Get Invoice Details (Sidebar)
// =========================================================================
if (isset($_GET['action']) && $_GET['action'] === 'get_invoice_items' && isset($_GET['id'])) {
    header('Content-Type: application/json');
    $inv_id = intval($_GET['id']);
    
    // Invoice Info
    $sql_inv = "SELECT io.*, c.name as customer_name, c.mobile as customer_mobile, wo.title as work_order_title 
                FROM invoices_out io 
                LEFT JOIN customers c ON io.customer_id = c.id 
                LEFT JOIN work_orders wo ON io.work_order_id = wo.id
                WHERE io.id = ?";
    
    $invoice = null;
    if ($stmt = $conn->prepare($sql_inv)) {
        $stmt->bind_param("i", $inv_id);
        $stmt->execute();
        $invoice = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }

    if (!$invoice) {
        echo json_encode(['ok' => false, 'msg' => 'Invoice not found']);
        exit;
    }

    // Items Logic:
    // "Active items only (return_flag != 1)"
    // "Display available_for_return"
    // "Unit Price After Discount"
    // "Total Net = available_for_return * unit_price_after_discount"
    
    $sql_items = "SELECT 
                    ioi.*, 
                    p.name as product_name
                  FROM invoice_out_items ioi
                  LEFT JOIN products p ON ioi.product_id = p.id
                  WHERE ioi.invoice_out_id = ? 
                  AND ioi.return_flag != 1
                  ORDER BY ioi.id ASC";
                  
    $items = [];
    if ($stmt = $conn->prepare($sql_items)) {
        $stmt->bind_param("i", $inv_id);
        $stmt->execute();
        $res = $stmt->get_result();
        while($r = $res->fetch_assoc()) {
            // Format numbers
            $r['available_for_return'] = floatval($r['available_for_return']);
            $r['unit_price_after_discount'] = floatval($r['unit_price_after_discount']);
            $r['discount_value'] = floatval($r['discount_value']);
            
            // Calculate Line Total Net
            $r['line_total_net'] = $r['available_for_return'] * $r['unit_price_after_discount'];
            
            $items[] = $r;
        }
        $stmt->close();
    }
    
    echo json_encode(['ok' => true, 'invoice' => $invoice, 'items' => $items]);
    exit;
}

// =========================================================================
// 3. MAIN PAGE HTML
// =========================================================================
require_once BASE_DIR . 'partials/header.php';
require_once BASE_DIR . 'partials/sidebar.php';
?>

<style>
/* --- Page Layout & General --- */
/* :root { ... } Removed to inherit from index.css */

/* body { background-color: var(--bg); color: var(--text); } */

.page-wrapper {
    display: flex;
    gap: 20px;
    padding: 20px;
    height: calc(100vh - 60px);
    overflow: hidden;
}

/* --- Sidebar Filters --- */
.filters-sidebar {
    width: 300px;
    background: var(--surface);
    border-radius: var(--radius);
    padding: 20px;
    border: 1px solid var(--border);
    display: flex;
    flex-direction: column;
    flex-shrink: 0;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    overflow-y: auto;
}

.filters-sidebar .filter-title {
    font-size: 1.1rem;
    font-weight: 700;
    margin-bottom: 20px;
    color: var(--text);
    display: flex;
    align-items: center;
    gap: 8px;
    border-bottom: 2px solid var(--primary);
    padding-bottom: 10px;
}

.filter-group { margin-bottom: 15px; }
.filter-group label {
    display: block;
    font-size: 0.85rem;
    font-weight: 600;
    color: var(--text-muted);
    margin-bottom: 6px;
}

.filter-form input,
.filter-form select,
.filter-form button.period-btn {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid var(--border);
    border-radius: 6px;
    font-size: 0.9rem;
    background: var(--surface-2);
    color: var(--text);
    transition: all 0.2s;
}

.filter-form input:focus, 
.filter-form select:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
}

/* Quick Periods Grid */
.quick-periods {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 8px;
}

.period-btn {
    background: var(--surface);
    cursor: pointer;
    font-size: 0.8rem;
    text-align: center;
}

.period-btn:hover { background: #e0e7ff; color: var(--primary); border-color: var(--primary); }
.period-btn.active { background: var(--primary); color: white; border-color: var(--primary); }

.filter-actions {
    margin-top: auto;
    display: flex;
    gap: 10px;
    padding-top: 20px;
    border-top: 1px solid var(--border);
}

.btn-apply {
    flex: 1;
    background: var(--primary);
    color: white;
    border: none;
    padding: 10px;
    border-radius: 6px;
    cursor: pointer;
    font-weight: 600;
}
.btn-reset {
    flex: 1;
    background: var(--surface-2);
    color: var(--text);
    border: 1px solid var(--border);
    padding: 10px;
    border-radius: 6px;
    cursor: pointer;
}

/* --- Main Content --- */
.content-area {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 20px;
    min-width: 0;
    overflow: hidden;
}

/* Stats Cards */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 15px;
}

.stat-card {
    background: var(--surface);
    padding: 20px;
    border-radius: var(--radius);
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
    border: 1px solid var(--border);
    position: relative;
    overflow: hidden;
}
.stat-card.blue { border-top: 4px solid #3b82f6; }
.stat-card.green { border-top: 4px solid #10b981; }
.stat-card.amber { border-top: 4px solid #f59e0b; }
.stat-card.indigo { border-top: 4px solid #6366f1; }

.stat-label { font-size: 0.85rem; color: var(--text-muted); font-weight: 600; }
.stat-val { font-size: 1.6rem; font-weight: 800; color: var(--text); margin-top: 5px; }
.stat-sub { font-size: 0.75rem; color: var(--text-muted); display: block; margin-top: 5px; opacity: 0.8; }

/* Table Section */
.results-container {
    flex: 1;
    background: var(--surface);
    border-radius: var(--radius);
    border: 1px solid var(--border);
    display: flex;
    flex-direction: column;
    overflow: hidden; /* For inner scroll */
}

.table-scroll {
    overflow-y: auto;
    flex: 1;
}

.data-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
}

.data-table th {
    position: sticky;
    top: 0;
    background: var(--surface-2);
    padding: 12px 16px;
    text-align: right;
    font-weight: 600;
    color: var(--text-muted);
    border-bottom: 2px solid var(--border);
    z-index: 10;
}

.data-table td {
    padding: 12px 16px;
    border-bottom: 1px solid var(--border);
    vertical-align: middle;
    color: var(--text);
}

.data-table tr:hover td { background: var(--bg); transition: background 0.2s ease; }
/* Dark Mode Enhancements for Main Table Hover */
@media (prefers-color-scheme: dark) {
    .data-table tr:hover td { background: rgba(255, 255, 255, 0.05); }
}

/* Eye Button Styling */
.btn-view-details {
    width: 36px; height: 36px;
    border-radius: 10px;
    background: var(--surface);
    border: 1px solid var(--border);
    color: var(--primary);
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    cursor: pointer;
}
.btn-view-details:hover {
    background: var(--primary);
    color: white;
    transform: scale(1.1);
    box-shadow: 0 4px 12px rgba(79, 70, 229, 0.2);
}

/* Details Sidebar Table */
.ds-info-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 15px;
    margin-bottom: 20px;
}
.ds-box {
    background: var(--surface-2);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 12px;
}
@media (prefers-color-scheme: dark) {
    /* Removed redundant dark media query overrides as variables handle it */
}
.ds-box-title {
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #64748b;
    margin-bottom: 8px;
    border-bottom: 1px solid #e2e8f0;
    padding-bottom: 4px;
}
.ds-row {
    font-size: 0.9rem;
    margin-bottom: 4px;
    display: flex;
    justify-content: space-between;
}
.ds-label { color: #64748b; }
.ds-val { font-weight: 600; color: #1e293b; }

.items-table-container {
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    overflow: hidden;
    margin-top: 10px;
}
@media (prefers-color-scheme: dark) {
    .items-table-container {
        border-color: rgba(71, 85, 105, 0.3);
    }
}
.items-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.85rem;
}
.items-table th {
    background: #f1f5f9;
    padding: 10px;
    text-align: right;
    font-weight: 600;
    color: #475569;
    border-bottom: 1px solid #e2e8f0;
}
@media (prefers-color-scheme: dark) {
    .items-table th {
        background: rgba(30, 41, 59, 0.5);
        color: #94a3b8;
        border-bottom-color: rgba(71, 85, 105, 0.3);
    }
}
.items-table td {
    padding: 10px;
    border-bottom: 1px solid #f1f5f9;
    color: #334155;
    vertical-align: middle;
}
@media (prefers-color-scheme: dark) {
    .items-table td {
        border-bottom-color: rgba(71, 85, 105, 0.2);
        color: #cbd5e1;
    }
}
.items-table tr:last-child td { border-bottom: none; }

.price-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 6px;
    font-weight: 700;
    font-size: 0.8rem;
}
.price-badge.sale {
    background: #dbeafe;
    color: #1e40af;
}
.price-badge.cost {
    background: #fef3c7;
    color: #92400e;
}
@media (prefers-color-scheme: dark) {
    .price-badge.sale {
        background: rgba(30, 64, 175, 0.2);
        color: #93c5fd;
    }
    .price-badge.cost {
        background: rgba(146, 64, 14, 0.2);
        color: #fcd34d;
    }
}

/* Badges */
.badge {
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 700;
}
.badge.paid { background: #dcfce7; color: #15803d; }
.badge.partial { background: #fef9c3; color: #a16207; }
.badge.pending { background: #fee2e2; color: #b91c1c; }
@media (prefers-color-scheme: dark) {
    .badge.paid { background: rgba(21, 128, 61, 0.2); color: #86efac; border: 1px solid rgba(21, 128, 61, 0.3); }
    .badge.partial { background: rgba(161, 98, 7, 0.2); color: #fde047; border: 1px solid rgba(161, 98, 7, 0.3); }
    .badge.pending { background: rgba(185, 28, 28, 0.2); color: #fca5a5; border: 1px solid rgba(185, 28, 28, 0.3); }
    
    /* Stronger backgrounds for dark mode badges in sidebar */
    .report-badge-sales { background-color: rgba(67, 56, 202, 0.25) !important; color: #e0e7ff !important; border-color: rgba(99, 102, 241, 0.4) !important; }
    .report-badge-cost { background-color: rgba(180, 83, 9, 0.25) !important; color: #fef3c7 !important; border-color: rgba(245, 158, 11, 0.4) !important; }
}

/* Skeleton Loading */
.skeleton {
    background: linear-gradient(90deg, #e0e7ff 25%, #f0f4ff 50%, #e0e7ff 75%);
    background-size: 200% 100%;
    animation: loading 1.5s infinite;
    color: transparent !important;
    border-radius: 4px;
    display: inline-block;
}
@keyframes loading { 0% { background-position: 200% 0; } 100% { background-position: -200% 0; } }

.loading-overlay { opacity: 0.6; pointer-events: none; }

/* --- Details Sidebar (Slide-out) --- */
.details-sidebar {
    position: fixed;
    top: 0;
    left: 0;
    width: 500px;
    max-width: 90vw;
    height: 100vh;
    background: var(--surface);
    box-shadow: 0 0 30px rgba(0,0,0,0.2);
    z-index: 2000;
    transform: translateX(-100%);
    transition: transform 0.3s cubic-bezier(0.16, 1, 0.3, 1);
    display: flex;
    flex-direction: column;
}

.details-sidebar.open { transform: translateX(0); }
.details-overlay {
    position: fixed; inset: 0; background: rgba(0,0,0,0.3); z-index: 1999;
    display: none; opacity: 0; transition: opacity 0.3s;
}
.details-overlay.open { display: block; opacity: 1; }

.ds-header {
    padding: 20px;
    background: var(--surface-2);
    border-bottom: 1px solid var(--border);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.nav-buttons {
    display: flex;
    gap: 8px;
}
.nav-btn {
    width: 32px; height: 32px;
    border-radius: 50%;
    border: 1px solid var(--border);
    background: var(--surface);
    cursor: pointer;
    display: grid; place-items: center;
    transition: all 0.2s;
    color: var(--text);
}
.nav-btn:hover:not(:disabled) { background: var(--primary); color: white; border-color: var(--primary); }
.nav-btn:disabled { opacity: 0.5; cursor: not-allowed; }

.ds-body {
    flex: 1;
    overflow-y: auto;
    padding: 20px;
}

.item-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 12px;
    margin-bottom: 10px;
    transition: all 0.2s;
}
.item-card:hover { border-color: var(--primary); box-shadow: 0 4px 6px rgba(0,0,0,0.05); }

/* --- Sidebar Typography & Colors --- */
.ds-val-lg {
    font-size: 1.1rem;
    font-weight: 700;
    color: var(--text);
}

.text-main { color: var(--text); }
.text-sub { color: var(--text-muted); }

/* Forex Styles for Stats */
.fx-up { color: #10b981; }
.fx-down { color: #ef4444; }
/* Dark mode brighter Forex */
@media (prefers-color-scheme: dark) {
    .fx-up { color: #34d399; text-shadow: 0 0 10px rgba(16, 185, 129, 0.2); }
    .fx-down { color: #f87171; text-shadow: 0 0 10px rgba(239, 68, 68, 0.2); }
}

/* --- Semantic Badge Styles (Replacing Tailwind) --- */
.report-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 80px;
    padding: 6px 12px;
    border-radius: 8px;
    font-weight: 700;
    font-size: 0.9rem;
    border: 1px solid transparent;
}

.report-badge-sales {
    background-color: rgba(99, 102, 241, 0.1);
    color: #4338ca;
    border-color: rgba(99, 102, 241, 0.2);
}

.report-badge-cost {
    background-color: rgba(245, 158, 11, 0.1);
    color: #b45309;
    border-color: rgba(245, 158, 11, 0.2);
}

.report-badge-profit-pos {
    background-color: rgba(16, 185, 129, 0.1);
    color: #047857;
    border-color: rgba(16, 185, 129, 0.2);
}

.report-badge-profit-neg {
    background-color: rgba(239, 68, 68, 0.1);
    color: #b91c1c;
    border-color: rgba(239, 68, 68, 0.2);
}

.margin-pill {
    display: inline-block;
    font-size: 0.75rem;
    padding: 2px 8px;
    border-radius: 12px;
    font-weight: bold;
    margin-top: 4px;
}

.pill-pos { background-color: rgba(16, 185, 129, 0.15); color: #047857; }
.pill-neg { background-color: rgba(239, 68, 68, 0.15); color: #b91c1c; }

/* Dark Mode Overrides */
@media (prefers-color-scheme: dark) {
    .report-badge-sales { background-color: rgba(99, 102, 241, 0.15); color: #a5b4fc; border-color: rgba(99, 102, 241, 0.3); }
    .report-badge-cost { background-color: rgba(245, 158, 11, 0.15); color: #fcd34d; border-color: rgba(245, 158, 11, 0.3); }
    .report-badge-profit-pos { background-color: rgba(16, 185, 129, 0.15); color: #6ee7b7; border-color: rgba(16, 185, 129, 0.3); }
    .report-badge-profit-neg { background-color: rgba(239, 68, 68, 0.15); color: #fca5a5; border-color: rgba(239, 68, 68, 0.3); }
    
    .pill-pos { background-color: rgba(16, 185, 129, 0.25); color: #6ee7b7; }
    .pill-neg { background-color: rgba(239, 68, 68, 0.25); color: #fca5a5; }
}

/* Utilities */
.d-flex-center-col { display: flex; flex-direction: column; align-items: center; justify-content: center; }
.d-flex-between { display: flex; justify-content: space-between; align-items: center; }
.font-sm { font-size: 0.875rem; }
.font-xs { font-size: 0.75rem; }
.fw-bold { font-weight: 700; }

/* --- Header Section --- */
.header-section {
    background: linear-gradient(135deg, var(--primary) 0%, #6366f1 100%);
    padding: 24px 30px;
    border-radius: var(--radius);
    margin-bottom: 20px;
    box-shadow: 0 4px 12px rgba(79, 70, 229, 0.15);
    border: 1px solid rgba(99, 102, 241, 0.2);
}

.header-section h1 {
    color: white;
    font-size: 1.75rem;
    font-weight: 800;
    margin: 0 0 8px 0;
    display: flex;
    align-items: center;
    gap: 12px;
    text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
}

.header-section h1 i {
    font-size: 1.5rem;
    opacity: 0.95;
}

.header-section .subtitle {
    color: rgba(255, 255, 255, 0.9);
    font-size: 0.95rem;
    font-weight: 500;
    margin: 0;
    line-height: 1.5;
}

@media (prefers-color-scheme: dark) {
    .header-section {
        background: linear-gradient(135deg, rgba(79, 70, 229, 0.8) 0%, rgba(99, 102, 241, 0.7) 100%);
        box-shadow: 0 4px 12px rgba(79, 70, 229, 0.25);
        border-color: rgba(99, 102, 241, 0.3);
    }
}

</style>

<div class="page-wrapper">
    <!-- 1. Filters Sidebar -->
    <aside class="filters-sidebar" id="filtersSidebar">
        <h2 class="filter-title"><i class="fas fa-filter"></i> فلاتر البحث</h2>
        
        <form id="filterForm" class="filter-form">
            <!-- Quick Periods -->
            <div class="filter-group">
                <label>الفترة السريعة</label>
                <div class="quick-periods">
                    <button type="button" class="period-btn active" data-days="0">اليوم</button>
                    <button type="button" class="period-btn" data-days="7">أسبوع</button>
                    <button type="button" class="period-btn" data-days="30">شهر</button>
                    <button type="button" class="period-btn" data-period="2024">سنة 24</button>
                    <button type="button" class="period-btn" data-days="90">ربع سنوي</button>
                    <button type="button" class="period-btn" data-days="365">سنة</button>
                </div>
            </div>

            <div class="filter-group">
                <label>من تاريخ</label>
                <input type="date" name="start_date" id="start_date" value="<?php echo date('Y-m-d'); ?>">
            </div>

            <div class="filter-group">
                <label>إلى تاريخ</label>
                <input type="date" name="end_date" id="end_date" value="<?php echo date('Y-m-d'); ?>">
            </div>

            <div class="filter-group">
                <label>حالة الدفع</label>
                <select name="status" id="statusFilter">
                    <option value="">كل الحالات</option>
                    <option value="paid">مدفوع بالكامل</option>
                    <option value="partial">مدفوع جزئي</option>
                    <option value="pending">مؤجل (عليه متبقي)</option>
                </select>
            </div>

            <div class="filter-group">
                <label>بحث شامل</label>
                <input type="text" name="advanced_search" placeholder="رقم فاتورة، عميل، ملاحظات..." id="globalSearch">
            </div>

            <div class="filter-group">
                <details>
                    <summary style="cursor: pointer; font-weight: 600; color: var(--text); padding: 8px 0; font-size: 0.9rem;">
                        <i class="fas fa-sliders-h"></i> فلاتر متقدمة
                    </summary>
                    <div style="margin-top: 10px; display: grid; gap: 10px;">
                        <input type="text" name="customer" placeholder="اسم العميل أو رقم الهاتف">
                        <input type="text" name="work_order" placeholder="رقم أو عنوان الشغلانة">
                        <input type="text" name="notes" placeholder="بحث في الملاحظات">
                        <input type="number" name="invoice_id" placeholder="رقم الفاتورة بالتحديد">
                    </div>
                </details>
            </div>

            <div class="filter-actions">
                <button type="submit" id="applyFilters" class="btn-apply">
                    <i class="fas fa-search"></i> تطبيق
                </button>
                <button type="button" id="resetFilters" class="btn-reset">
                    <i class="fas fa-redo"></i> إعادة تعيين
                </button>
            </div>
        </form>
    </aside>

    <!-- 2. Main Content -->
    <main class="content-area">
        <!-- Header Section -->
        <div class="header-section">
            <h1><i class="fas fa-chart-line"></i> تقرير الأرباح التفصيلي</h1>
            <div class="subtitle">تحليل شامل للأرباح مع حساب التكاليف الفعلية وصافي المبيعات بعد الخصومات والمرتجعات</div>
        </div>

        <!-- Stats Cards -->
        <div class="stats-grid">
            <div class="stat-card blue">
                <div class="stat-label">صافي المبيعات (Invoice Net)</div>
                <div class="stat-val" id="stat_net_revenue">0.00</div>
                <span class="stat-sub">قيمة الفواتير بعد الخصومات والمرتجعات</span>
            </div>
            <div class="stat-card amber">
                <div class="stat-label">تكلفة البضاعة (Active Cost)</div>
                <div class="stat-val" id="stat_total_cost">0.00</div>
                <span class="stat-sub">تكلفة الكميات الفعالة (غير المرتجعة)</span>
            </div>
            <div class="stat-card green">
                <div class="stat-label">مجمل الربح (Gross Profit)</div>
                <div class="stat-val" id="stat_gross_profit">0.00</div>
                <span class="stat-sub" id="stat_margin">هامش ربح: 0%</span>
            </div>
            <div class="stat-card indigo">
                <div class="stat-label">عدد الفواتير</div>
                <div class="stat-val" id="stat_count">0</div>
                <span class="stat-sub">فاتورة مطابقة للفلاتر</span>
            </div>
        </div>

        <!-- Table -->
        <div class="results-container">
            <div class="filters-summary p-4  dark:bg-gray-800 border-b border-gray-100 dark:border-gray-700 flex justify-between items-center">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-lg bg-indigo-50 dark:bg-indigo-900/30 flex items-center justify-center text-indigo-600 dark:text-indigo-400">
                        <i class="fas fa-list-ul"></i>
                    </div>
                    <div>
                        <h3 class="font-bold text-gray-800 dark:text-gray-100 m-0">قائمة الفواتير</h3>
                        <div id="activeFiltersDisplay" class="text-[10px] text-gray-400 dark:text-gray-500 mt-1">تظهر النتائج بناءً على الفلاتر المختارة</div>
                    </div>
                </div>
                <div class="text-left">
                    <span class="text-[10px] text-gray-400 dark:text-gray-500 block mb-1" id="lastUpdated"></span>
                    <button onclick="fetchResults()" class="text-xs text-white font-bold hover:opacity-90 px-3 py-1.5 rounded shadow-sm transition-all" style="background-color: #449944;"><i class="fas fa-sync-alt"></i> تحديث</button>
                </div>
            </div>
            <div class="table-scroll">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>التاريخ</th>
                            <th>العميل</th>
                            <th>حالة الدفع</th>
                            <th>ملاحظات</th>
                            <th>صافي البيع</th>
                            <th>التكلفة</th>
                            <th>الربح</th>
                            <th class="text-center">عرض</th>
                        </tr>
                    </thead>
                    <tbody id="invoicesTableBody">
                        <!-- Content via AJAX -->
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</div>

<!-- 3. Details Sidebar -->
<div class="details-overlay" id="detailsOverlay" onclick="closeDetails()"></div>
<div class="details-sidebar" id="detailsSidebar">
    <div class="ds-header">
        <div>
            <div class="flex items-center gap-2">
                <h3 class="font-bold text-lg text-gray-800 m-0">فاتورة #<span id="ds_id">...</span></h3>
            </div>
            <div class="text-xs text-gray-500 mt-1" id="ds_customer">...</div>
            <div class="text-xs text-indigo-600 font-bold mt-1 hidden" id="ds_work_order_wrapper"><i class="fas fa-briefcase"></i> <span id="ds_work_order"></span></div>
        </div>
        <div class="flex items-center gap-4">
            <div class="nav-buttons">
                <button class="nav-btn" id="btnPrev" onclick="navigateDetails(-1)"><i class="fas fa-chevron-right"></i></button>
                <button class="nav-btn" id="btnNext" onclick="navigateDetails(1)"><i class="fas fa-chevron-left"></i></button>
            </div>
            <button onclick="closeDetails()" class="nav-btn hover:bg-red-50 hover:text-red-500 hover:border-red-200 text-gray-400 text-lg transition-colors"><i class="fas fa-times"></i></button>
        </div>
    </div>
    <div class="ds-body" id="ds_content">
        <!-- Details Loaded Here -->
    </div>
</div>

<script>
// State
let currentInvoiceIds = [];
let currentDetailIndex = -1;

// Initialization
document.addEventListener('DOMContentLoaded', () => {
    // Quick Date Buttons
    document.querySelectorAll('.period-btn').forEach(btn => {
        btn.addEventListener('click', (e) => {
            document.querySelectorAll('.period-btn').forEach(b => b.classList.remove('active'));
            e.target.classList.add('active');
            
            const days = e.target.getAttribute('data-days');
            const periodYear = e.target.getAttribute('data-period');
            const today = new Date();
            const endDate = today.toISOString().split('T')[0];
            let startDate;

            if (periodYear) {
                startDate = `${periodYear}-01-01`;
            } else if (days == 0) {
                 startDate = endDate;
            } else {
                const past = new Date(today);
                past.setDate(today.getDate() - days);
                startDate = past.toISOString().split('T')[0];
            }

            document.getElementById('start_date').value = startDate;
            document.getElementById('end_date').value = endDate;
            
            fetchResults(); // Auto trigger
        });
    });

    // Reset Button
    document.getElementById('resetFilters').addEventListener('click', () => {
        document.getElementById('filterForm').reset();
        document.querySelector('.period-btn[data-days="0"]').click();
    });

    // Filter Submit
    document.getElementById('filterForm').addEventListener('submit', (e) => {
        e.preventDefault();
        fetchResults();
    });

    // Initial Fetch
    fetchResults();
});

// Fetch Main Results
async function fetchResults() {
    const tbody = document.getElementById('invoicesTableBody');
    const form = document.getElementById('filterForm');
    const params = new URLSearchParams(new FormData(form));
    
    // Skeleton Effect
    tbody.innerHTML = Array(5).fill(`
        <tr>
            <td colspan="9">
                <div class="flex gap-4 p-2">
                    <div class="skeleton h-6 w-8"></div>
                    <div class="skeleton h-6 w-24"></div>
                    <div class="skeleton h-6 w-32"></div>
                    <div class="skeleton h-6 w-20"></div>
                    <div class="skeleton h-6 w-full"></div>
                </div>
            </td>
        </tr>
    `).join('');
    
    // Stats Skeleton
    ['stat_net_revenue', 'stat_total_cost', 'stat_gross_profit', 'stat_count'].forEach(id => {
        document.getElementById(id).classList.add('skeleton');
    });

    try {
        const response = await fetch(`gross_profit_report.php?action=filter_results&${params.toString()}`);
        const data = await response.json();
        
        if (data.ok) {
            updateStats(data.stats);
            renderTable(data.invoices);
            currentInvoiceIds = data.ids;
            
            // Update Active Filters Display
            const startDate = document.getElementById('start_date').value;
            const endDate = document.getElementById('end_date').value;
            const status = document.getElementById('statusFilter').value;
            const search = document.getElementById('globalSearch').value;
            
            let filterText = `الفترة: من ${startDate} إلى ${endDate}`;
            if (status) filterText += ` | الحالة: ${status === 'paid' ? 'مدفوع' : (status === 'partial' ? 'جزئي' : 'مؤجل')}`;
            if (search) filterText += ` | بحث: "${search}"`;
            
            document.getElementById('activeFiltersDisplay').textContent = filterText;
            document.getElementById('lastUpdated').textContent = 'آخر تحديث: ' + new Date().toLocaleTimeString('ar-EG');
        }
    } catch (e) {
        console.error(e);
        tbody.innerHTML = `<tr><td colspan="9" class="text-center text-red-500 p-4">حدث خطأ أثناء تحميل البيانات</td></tr>`;
    } finally {
        // Remove Skeleton classes
        ['stat_net_revenue', 'stat_total_cost', 'stat_gross_profit', 'stat_count'].forEach(id => {
            document.getElementById(id).classList.remove('skeleton');
        });
    }
}

function updateStats(stats) {
    // Format Currency
    const fmt = (n) => parseFloat(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    
    document.getElementById('stat_net_revenue').textContent = fmt(stats.net_revenue) + ' ج.م';
    // Add subtitle for Discounts/Returns
    const netCard = document.querySelector('#stat_net_revenue').parentElement;
    const sub = netCard.querySelector('.stat-sub') || document.createElement('span');
    sub.className = 'stat-sub';
    sub.innerHTML = `
        <span class="text-xs mr-2"><i class="fas fa-undo text-red-500"></i> مرتجع: ${fmt(stats.total_returns)}</span>
        <span class="text-xs"><i class="fas fa-tag text-orange-500"></i> خصم: ${fmt(stats.total_discounts)}</span>
    `;
    if(!netCard.querySelector('.stat-sub')) netCard.appendChild(sub);

    document.getElementById('stat_total_cost').textContent = fmt(stats.total_cost) + ' ج.م';
    
    // Forex Style Coloring for Main Stats
    const gpEl = document.getElementById('stat_gross_profit');
    gpEl.textContent = fmt(stats.gross_profit) + ' ج.م';
    gpEl.className = 'stat-val ' + (stats.gross_profit >= 0 ? 'fx-up' : 'fx-down');
    
    document.getElementById('stat_count').textContent = parseInt(stats.count).toLocaleString();

    // Margin
    const margin = stats.net_revenue > 0 ? ((stats.gross_profit / stats.net_revenue) * 100).toFixed(1) : 0;
    const marginEl = document.getElementById('stat_margin');
    marginEl.textContent = `هامش ربح: ${margin}%`;
    marginEl.className = 'stat-sub font-bold ' + (margin > 0 ? 'fx-up' : 'fx-down');
}

function renderTable(invoices) {
    const tbody = document.getElementById('invoicesTableBody');
    if (invoices.length === 0) {
        tbody.innerHTML = `<tr><td colspan="9" class="text-center p-8 text-gray-500">لا توجد فواتير تطابق شروط البحث</td></tr>`;
        return;
    }

    tbody.innerHTML = invoices.map(inv => `
        <tr class="group transition-colors hover:bg-[var(--bg)]">
            <td>
                <span class="font-bold text-indigo-700 font-mono text-base">#${inv.id}</span>
                ${inv.work_order_title ? `<div class="text-[10px] mt-1 text-white px-2 py-0.5 rounded inline-block font-medium shadow-sm" style="background-color: #6366f1;"><i class="fas fa-briefcase mr-1"></i>${inv.work_order_title}</div>` : ''}
            </td>
            <td>
                <div class="text-sm font-bold text-gray-800">${inv.created_at.split(' ')[0]}</div>
                <div class="text-xs text-gray-400 font-mono">${inv.created_at.split(' ')[1].substr(0,5)}</div>
            </td>
            <td>
                <div class="font-bold text-gray-800">${inv.customer_name}</div>
                ${inv.mobile ? `<div class="text-xs text-gray-400 flex items-center gap-1"><i class="fas fa-phone-alt text-[10px]"></i> ${inv.mobile}</div>` : ''}
            </td>
            <td>
                <span class="badge ${inv.payment_status} shadow-sm">
                    ${inv.payment_status === 'paid' ? 'مدفوع' : (inv.payment_status === 'partial' ? 'جزئي' : 'مؤجل')}
                </span>
            </td>
            <td class="max-w-[150px]">
                 <div class="truncate text-xs text-gray-500" title="${inv.notes || ''}">${inv.notes || '—'}</div>
            </td>
            <td class="align-middle text-center">
                <span class="report-badge report-badge-sales">
                    ${parseFloat(inv.net_sales).toFixed(2)}
                </span>
            </td>
            <td class="align-middle text-center">
                <span class="report-badge report-badge-cost">
                    ${parseFloat(inv.active_cost).toFixed(2)}
                </span>
            </td>
            <td class="align-middle text-center">
                 <div class="d-flex-center-col">
                    <span class="report-badge ${parseFloat(inv.profit) >= 0 ? 'report-badge-profit-pos' : 'report-badge-profit-neg'}">
                        ${parseFloat(inv.profit).toFixed(2)}
                    </span>
                    <span class="margin-pill ${inv.net_sales > 0 && (inv.profit/inv.net_sales) > 0 ? 'pill-pos' : 'pill-neg'}">
                        %${inv.net_sales > 0 ? ((inv.profit/inv.net_sales)*100).toFixed(0) : 0}
                    </span>
                 </div>
            </td>
            <td class="text-center">
                <button onclick="openDetails(${inv.id})" class="btn-view-details mx-auto" title="عرض التفاصيل">
                    <i class="fas fa-eye"></i>
                </button>
            </td>
        </tr>
    `).join('');
}

// Sidebar Details Logic
async function openDetails(id) {
    const sb = document.getElementById('detailsSidebar');
    const ov = document.getElementById('detailsOverlay');
    const content = document.getElementById('ds_content');
    
    sb.classList.add('open');
    ov.classList.add('open');
    
    // Setup Navigation
    currentDetailIndex = currentInvoiceIds.indexOf(String(id)); // IDs from JSON might be numbers or strings
    if (currentDetailIndex === -1) currentDetailIndex = currentInvoiceIds.indexOf(parseInt(id));
    
    updateNavButtons();

    // Skeleton
    content.innerHTML = `
        <div class="animate-pulse space-y-4">
            <div class="h-20 bg-gray-100 rounded-lg"></div>
            <div class="h-20 bg-gray-100 rounded-lg"></div>
            <div class="h-20 bg-gray-100 rounded-lg"></div>
        </div>
    `;

    try {
        const res = await fetch(`gross_profit_report.php?action=get_invoice_items&id=${id}`);
        const data = await res.json();
        if (data.ok) renderDetails(data.invoice, data.items);
    } catch (err) {
        console.error(err);
        content.innerHTML = '<div class="text-red-500 text-center mt-10">فشل تحميل التفاصيل</div>';
    }
}

function renderDetails(invoice, items) {
    // Update Sidebar Header
    document.getElementById('ds_id').textContent = invoice.id;
    document.getElementById('ds_customer').textContent = invoice.customer_name + (invoice.customer_mobile ? ' - ' + invoice.customer_mobile : '');
    
    if (invoice.work_order_title) {
        document.getElementById('ds_work_order_wrapper').classList.remove('hidden');
        document.getElementById('ds_work_order').textContent = invoice.work_order_title;
    } else {
        document.getElementById('ds_work_order_wrapper').classList.add('hidden');
    }
    
    let html = '';
    
    // Format Helpers
    const fmt = (n) => parseFloat(n || 0).toFixed(2);
    
    // Status Badge
    let statusBadge = '';
    const rem = parseFloat(invoice.remaining_amount);
    const paid = parseFloat(invoice.paid_amount);
    
    if (rem == 0) statusBadge = '<span class="badge paid">مدفوع بالكامل</span>';
    else if (paid > 0) statusBadge = '<span class="badge partial">دفع جزئي</span>';
    else statusBadge = '<span class="badge pending">مؤجل</span>';

    // Info Section
    html += `
    <div class="ds-info-grid">
        <div class="ds-box">
            <div class="ds-box-title" style="color: var(--text-muted);"><i class="fas fa-file-invoice"></i> معلومات الفاتورة</div>
            <div class="ds-row"><span class="ds-label" style="color: var(--text-muted);">رقم الفاتورة</span> <span class="ds-val-lg" style="color: var(--text);">#${invoice.id}</span></div>
            <div class="ds-row"><span class="ds-label" style="color: var(--text-muted);">التاريخ</span> <span class="ds-val font-mono" style="color: var(--text);">${invoice.created_at}</span></div>
            <div class="ds-row"><span class="ds-label" style="color: var(--text-muted);">الحالة</span> <span class="ds-val">${statusBadge}</span></div>
            <div class="ds-row"><span class="ds-label block w-full" style="color: var(--text-muted);">الملاحظات:<br><span style="color: var(--text); font-size: 0.95rem;">${invoice.notes || '—'}</span></span></div>
        </div>
        
        <div class="ds-box">
            <div class="ds-box-title" style="color: var(--text-muted);"><i class="fas fa-user"></i> معلومات العميل</div>
            <div class="ds-row"><span class="ds-label" style="color: var(--text-muted);">الاسم</span> <span class="ds-val-lg" style="color: var(--text);">${invoice.customer_name}</span></div>
            <div class="ds-row"><span class="ds-label" style="color: var(--text-muted);">الهاتف</span> <span class="ds-val-lg font-mono" style="color: var(--primary);">${invoice.customer_mobile || '—'}</span></div>
            ${invoice.work_order_title ? 
                `<div class="ds-row mt-2 pt-2 border-t border-gray-100"><span class="ds-label w-full" style="color: var(--text-muted);"><i class="fas fa-briefcase" style="color: var(--primary);"></i> الشغلانة: <span class="fw-bold" style="color: var(--primary);">${invoice.work_order_title}</span></span></div>` : 
                `<div class="ds-row mt-2 pt-2 border-t border-gray-100"><span class="ds-label w-full" style="color: var(--text-muted);"><i class="fas fa-briefcase"></i> لا يوجد شغلانة مرتبطة</span></div>`}
        </div>
    </div>
    `;

    // Filter Items (available_for_return > 0)
    const activeItems = items.filter(i => parseFloat(i.available_for_return) > 0);
    
    // Calculate Totals for Sidebar
    let totalSales = 0;
    let totalCost = 0;
    
    activeItems.forEach(i => {
        totalSales += (parseFloat(i.available_for_return) * parseFloat(i.unit_price_after_discount));
        totalCost += (parseFloat(i.available_for_return) * parseFloat(i.cost_price_per_unit));
    });
    
    const totalProfit = totalSales - totalCost;

    // Financial Summary
    const profitClass = totalProfit >= 0 ? 'fx-up' : 'fx-down';
    
    html += `
    <div class="ds-box mb-5" style="background: var(--surface-2); border: 2px solid var(--border);">
        <div class="ds-box-title" style="color: var(--text); border-color: var(--border);"><i class="fas fa-chart-line"></i> الملخص المالي (للبنود الفعالة)</div>
        <div class="grid grid-cols-3 gap-3 mt-3">
            <div class="text-center p-3 rounded-lg border border-blue-100 dark:border-blue-800/30" style="background: var(--surface)">
                <div class="text-[10px] text-blue-500 dark:text-blue-400 font-bold mb-1">إجمالي المبيعات</div>
                <div class="text-xl font-black text-blue-700 dark:text-blue-300">${fmt(totalSales)}</div>
            </div>
            <div class="text-center p-3 rounded-lg border border-amber-100 dark:border-amber-800/30" style="background: var(--surface)">
                <div class="text-[10px] text-amber-600 dark:text-amber-400 font-bold mb-1">إجمالي التكلفة</div>
                <div class="text-xl font-black text-amber-700 dark:text-amber-300">${fmt(totalCost)}</div>
            </div>
            <div class="text-center p-3 rounded-lg border ${totalProfit >= 0 ? 'border-emerald-100 dark:border-emerald-800/30' : 'border-red-100 dark:border-red-800/30'}" style="background: var(--surface)">
                <div class="text-[10px] ${totalProfit >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400'} font-bold mb-1">صافي الربح</div>
                <div class="text-xl font-black ${profitClass}">${totalProfit >= 0 ? '+' : ''}${fmt(totalProfit)}</div>
            </div>
        </div>
    </div>
    `;

    // Items Table
    if (activeItems.length === 0) {
        html += '<div class="text-center p-6 text-gray-400 border rounded-lg bg-gray-50">جميع البنود في هذه الفاتورة تم إرجاعها.</div>';
    } else {
        html += `
        <div class="items-table-container">
            <table class="items-table">
                <thead>
                    <tr>
                        <th class="text-right">المنتج / الكمية</th>
                        <th class="text-center">س.البيع / التكلفة</th>
                        <th class="text-left">الاجمالي / الربح</th>
                    </tr>
                </thead>
                <tbody>
        `;
        
        activeItems.forEach(item => {
            const qty = parseFloat(item.available_for_return);
            const price = parseFloat(item.unit_price_after_discount);
            const cost = parseFloat(item.cost_price_per_unit);
            
            const totalItemSales = qty * price;
            const totalItemCost = qty * cost;
            const itemProfit = totalItemSales - totalItemCost;
            
            const profitClass = itemProfit >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400';
            
            html += `
            <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/30 transition-colors border-b last:border-0 border-gray-100 dark:border-gray-700">
                <td class="align-top py-3">
                    <div class="fw-bold text-sm mb-1" style="font-size: 0.95rem; color: var(--text);">${item.product_name}</div>
                    <div class="text-xs">
                        <span style="color: var(--text-muted);">الكمية:</span> 
                        <span class="font-bold px-2 py-0.5 rounded border" style="background: var(--surface-2); color: var(--text); border-color: var(--border); font-size: 0.85rem;">${qty}</span>
                    </div>
                </td>
                <td class="align-top py-3">
                    <div class="d-flex-center-col gap-2">
                         <div class="report-badge report-badge-sales d-flex-between" style="width: 100%; padding: 4px 8px;">
                            <span class="font-xs" style="opacity: 0.8">بيع</span>
                            <span class="font-sm fw-bold">${fmt(price)}</span>
                         </div>
                         <div class="report-badge report-badge-cost d-flex-between" style="width: 100%; padding: 4px 8px;">
                            <span class="font-xs" style="opacity: 0.8">شراء</span>
                            <span class="font-sm fw-bold">${fmt(cost)}</span>
                         </div>
                    </div>
                </td>
                <td class="align-top py-3">
                    <div style="display: flex; flex-direction: column; gap: 8px;">
                        <!-- Total -->
                        <div class="d-flex-between">
                            <span class="font-xs" style="color: var(--text-muted);">الإجمالي:</span>
                            <span class="report-badge report-badge-sales" style="min-width: auto; padding: 4px 8px; font-size: 0.85rem;">
                                ${fmt(totalItemSales)}
                            </span>
                        </div>
                        <!-- Cost -->
                        <div class="d-flex-between">
                             <span class="font-xs" style="color: var(--text-muted);">التكلفة:</span>
                             <span class="report-badge report-badge-cost" style="min-width: auto; padding: 4px 8px; font-size: 0.85rem;">
                                ${fmt(totalItemCost)}
                             </span>
                        </div>
                        <!-- Profit -->
                        <div class="d-flex-between pt-2 mt-1" style="border-top: 1px dashed var(--border);">
                            <span class="font-xs fw-bold" style="color: var(--text-muted);">الربح</span>
                            <span class="report-badge ${itemProfit >= 0 ? 'report-badge-profit-pos' : 'report-badge-profit-neg'}" style="min-width: auto; padding: 4px 8px; font-size: 0.85rem;">
                                ${itemProfit >= 0 ? '+' : ''}${fmt(itemProfit)}
                            </span>
                        </div>
                    </div>
                </td>
            </tr>
            `;
        });
        
        html += `</tbody></table></div>`;
    }
    
    document.getElementById('ds_content').innerHTML = html;
}
                        

function updateNavButtons() {
    document.getElementById('btnPrev').disabled = currentDetailIndex <= 0;
    document.getElementById('btnNext').disabled = currentDetailIndex >= currentInvoiceIds.length - 1;
}

function navigateDetails(dir) {
    const newIndex = currentDetailIndex + dir;
    if (newIndex >= 0 && newIndex < currentInvoiceIds.length) {
        openDetails(currentInvoiceIds[newIndex]);
    }
}

function closeDetails() {
    document.getElementById('detailsSidebar').classList.remove('open');
    document.getElementById('detailsOverlay').classList.remove('open');
}

document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeDetails();
});
</script>

<?php require_once BASE_DIR . 'partials/footer.php'; ?>