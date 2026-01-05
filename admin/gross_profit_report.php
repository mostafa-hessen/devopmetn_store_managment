<?php
// reports/profit_report_invoices_summary.responsive.php
$page_title = "تقرير الربح - ملخص الفواتير (محدث)";
require_once dirname(__DIR__) . '/config.php';
require_once BASE_DIR . 'partials/session_admin.php';

if (!isset($conn) || !$conn) { echo "DB connection error"; exit; }
function e($s){ return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

// === AJAX endpoint: جلب بنود فاتورة معينة ===
if (isset($_GET['action']) && $_GET['action'] === 'get_invoice_items' && isset($_GET['id'])) {
    header('Content-Type: application/json; charset=utf-8');
    $inv_id = intval($_GET['id']);
    if ($inv_id <= 0) { echo json_encode(['ok'=>false,'msg'=>'معرف فاتورة غير صالح']); exit; }

    // جلب بيانات الفاتورة الرئيسية
    $sql_invoice = "SELECT io.*, c.name as customer_name, wo.title as work_order_title 
                    FROM invoices_out io
                    LEFT JOIN customers c ON c.id = io.customer_id
                    LEFT JOIN work_orders wo ON wo.id = io.work_order_id
                    WHERE io.id = ?";
    
    $invoice_data = null;
    if ($stmt_inv = $conn->prepare($sql_invoice)) {
        $stmt_inv->bind_param("i", $inv_id);
        $stmt_inv->execute();
        $invoice_data = $stmt_inv->get_result()->fetch_assoc();
        $stmt_inv->close();
    }

    // جلب بنود الفاتورة مع الحسابات الصحيحة للمرتجع
    $sql_items = "
        SELECT 
            ioi.id,
            ioi.product_id,
            COALESCE(p.name,'') AS product_name,
            ioi.quantity,
            ioi.returned_quantity,
            ioi.available_for_return,
            ioi.return_flag,
            ioi.selling_price,
            ioi.total_before_discount,
            ioi.cost_price_per_unit,
            ioi.discount_type,
            ioi.discount_value,
            ioi.discount_amount,
            ioi.total_after_discount,
            ioi.unit_price_after_discount,
            CASE 
                WHEN ioi.discount_type = 'percent' 
                THEN CONCAT(ioi.discount_value, '%')
                WHEN ioi.discount_type = 'amount' 
                THEN CONCAT(ioi.discount_value, ' ج.م')
                ELSE 'لا يوجد'
            END AS discount_display
        FROM invoice_out_items ioi
        LEFT JOIN products p ON p.id = ioi.product_id
        WHERE ioi.invoice_out_id = ? 
        ORDER BY ioi.id ASC
    ";
    
    $items = [];
    if ($stmt = $conn->prepare($sql_items)) {
        $stmt->bind_param("i", $inv_id);
        if ($stmt->execute()) {
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) {
                // تخطي البنود المرتجعة بالكامل
                if (isset($r['return_flag']) && $r['return_flag'] == 1) {
                    continue;
                }
                
                // تحويل القيم العددية
                $r['quantity'] = floatval($r['quantity'] ?? 0);
                $r['returned_quantity'] = floatval($r['returned_quantity'] ?? 0);
                $r['available_for_return'] = floatval($r['available_for_return'] ?? 0);
                $r['selling_price'] = floatval($r['selling_price'] ?? 0);
                $r['total_before_discount'] = floatval($r['total_before_discount'] ?? 0);
                $r['cost_price_per_unit'] = floatval($r['cost_price_per_unit'] ?? 0);
                $r['discount_value'] = floatval($r['discount_value'] ?? 0);
                $r['discount_amount'] = floatval($r['discount_amount'] ?? 0);
                $r['total_after_discount'] = floatval($r['total_after_discount'] ?? 0);
                $r['unit_price_after_discount'] = floatval($r['unit_price_after_discount'] ?? 0);
                
                // حساب القيم بناءً على الكمية المتبقية بعد المرتجع
                $effective_quantity = $r['available_for_return'];
                $effective_unit_price = ($r['unit_price_after_discount'] > 0) ? $r['unit_price_after_discount'] : $r['selling_price'];
                
                // إذا كان هناك خصم على البند
                if ($r['discount_type'] && $r['discount_amount'] > 0) {
                    if ($r['discount_type'] == 'percent') {
                        $effective_unit_price = $r['selling_price'] * (1 - ($r['discount_value']/100));
                    } else {
                        $effective_unit_price = $r['selling_price'] - ($r['discount_value'] / $r['quantity']);
                    }
                }
                
                // حساب الإجمالي الفعلي بعد المرتجع
                $r['effective_total'] = $effective_quantity * $effective_unit_price;
                $r['line_cogs'] = $effective_quantity * $r['cost_price_per_unit'];
                $r['line_profit'] = $r['effective_total'] - $r['line_cogs'];
                $r['effective_unit_price'] = $effective_unit_price;
                
                $items[] = $r;
            }
            echo json_encode(['ok'=>true, 'invoice'=>$invoice_data, 'items'=>$items], JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode(['ok'=>false,'msg'=>'فشل تنفيذ الاستعلام: '.$stmt->error], JSON_UNESCAPED_UNICODE);
        }
        $stmt->close();
    } else {
        echo json_encode(['ok'=>false,'msg'=>'فشل تحضير الاستعلام: '.$conn->error], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// === AJAX endpoint: البحث عن العملاء ===
if (isset($_GET['action']) && $_GET['action'] === 'search_customers' && isset($_GET['term'])) {
    header('Content-Type: application/json; charset=utf-8');
    $term = "%" . trim($_GET['term']) . "%";
    $customers = [];
    
    $sql = "SELECT id, name, mobile FROM customers 
            WHERE (name LIKE ? OR mobile LIKE ?) 
            AND deleted = 0 
            ORDER BY name 
            LIMIT 20";
    
    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param("ss", $term, $term);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $customers[] = [
                'id' => $row['id'],
                'label' => $row['name'] . ' - ' . $row['mobile'],
                'value' => $row['id']
            ];
        }
        $stmt->close();
    }
    
    echo json_encode($customers, JSON_UNESCAPED_UNICODE);
    exit;
}

// === AJAX endpoint: البحث عن الشغلانات ===
if (isset($_GET['action']) && $_GET['action'] === 'search_work_orders' && isset($_GET['term'])) {
    header('Content-Type: application/json; charset=utf-8');
    $term = "%" . trim($_GET['term']) . "%";
    $work_orders = [];
    
    $sql = "SELECT wo.id, wo.title, c.name as customer_name 
            FROM work_orders wo
            LEFT JOIN customers c ON c.id = wo.customer_id
            WHERE (wo.title LIKE ? OR c.name LIKE ?)
            ORDER BY wo.title 
            LIMIT 20";
    
    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param("ss", $term, $term);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $work_orders[] = [
                'id' => $row['id'],
                'label' => $row['title'] . ' (' . $row['customer_name'] . ')',
                'value' => $row['id']
            ];
        }
        $stmt->close();
    }
    
    echo json_encode($work_orders, JSON_UNESCAPED_UNICODE);
    exit;
}

// === Main report ===
$message = '';
$summaries = [];
$invoice_details = [];

// فلترة حسب حالة الفاتورة
$status_filter = $_GET['status'] ?? '';
$customer_filter = $_GET['customer_id'] ?? 0;
$work_order_filter = $_GET['work_order_id'] ?? 0;
$invoice_id_filter = $_GET['invoice_id'] ?? '';
$notes_filter = $_GET['notes'] ?? '';

// الافتراضي: اليوم
$start_date_filter = !empty($_GET['start_date']) ? trim($_GET['start_date']) : date('Y-m-d');
$end_date_filter   = !empty($_GET['end_date']) ? trim($_GET['end_date']) : date('Y-m-d');

// زر من أول المدة
if (isset($_GET['from_beginning']) && $_GET['from_beginning'] == '1') {
    $start_date_filter = '2022-01-01';
    $end_date_filter = date('Y-m-d');
}

$report_generated = false;

if (!empty($start_date_filter) && !empty($end_date_filter)) {
    if (DateTime::createFromFormat('Y-m-d', $start_date_filter) === false || 
        DateTime::createFromFormat('Y-m-d', $end_date_filter) === false) {
        $message = "<div class='alert alert-danger'>صيغة التاريخ غير صحيحة. استخدم YYYY-MM-DD.</div>";
    } elseif ($start_date_filter > $end_date_filter) {
        $message = "<div class='alert alert-danger'>تاريخ البدء لا يمكن أن يكون بعد تاريخ الانتهاء.</div>";
    } else {
        $report_generated = true;
        $start_sql = $start_date_filter . " 00:00:00";
        $end_sql   = $end_date_filter . " 23:59:59";

        // ====== بناء شروط WHERE ======
        $where_conditions = ["io.created_at BETWEEN ? AND ?"];
        $params = [$start_sql, $end_sql];
        $param_types = "ss";
        
        // استبعاد الفواتير الملغاة والمرتجعة
        $where_conditions[] = "io.delivered NOT IN ('canceled', 'reverted')";
        
        // فلتر حسب حالة الدفع
        if ($status_filter) {
            switch($status_filter) {
                case 'paid':
                    $where_conditions[] = "io.remaining_amount = 0";
                    break;
                case 'partial':
                    $where_conditions[] = "io.paid_amount > 0 AND io.remaining_amount > 0";
                    break;
                case 'pending':
                    $where_conditions[] = "io.paid_amount = 0 AND io.remaining_amount > 0";
                    break;
                case 'returned':
                    $where_conditions[] = "io.delivered = 'reverted'";
                    break;
                case 'delivered':
                    $where_conditions[] = "io.delivered IN ('yes', 'partial')";
                    break;
            }
        }
        
        // فلتر حسب رقم الفاتورة
        if (!empty($invoice_id_filter)) {
            $where_conditions[] = "io.id = ?";
            $params[] = intval($invoice_id_filter);
            $param_types .= "i";
        }
        
        // فلتر حسب العميل
        if ($customer_filter > 0) {
            $where_conditions[] = "io.customer_id = ?";
            $params[] = intval($customer_filter);
            $param_types .= "i";
        }
        
        // فلتر حسب الشغلانة
        if ($work_order_filter > 0) {
            $where_conditions[] = "io.work_order_id = ?";
            $params[] = intval($work_order_filter);
            $param_types .= "i";
        }
        
        // فلتر حسب الملاحظات
        if (!empty($notes_filter)) {
            $where_conditions[] = "(io.notes LIKE ? OR io.cancel_reason LIKE ? OR io.revert_reason LIKE ?)";
            $search_term = "%" . $notes_filter . "%";
            $params[] = $search_term;
            $params[] = $search_term;
            $params[] = $search_term;
            $param_types .= "sss";
        }
        
        $where_clause = implode(' AND ', $where_conditions);

        // ====== حساب إجماليات البطاقات ======
        $totals_sql = "
            SELECT
                COUNT(DISTINCT io.id) AS invoice_count,
                COALESCE(SUM(io.total_before_discount),0) AS total_revenue_before_discount,
                COALESCE(SUM(io.total_after_discount),0) AS total_revenue_after_discount,
                COALESCE(SUM(io.discount_amount),0) AS total_discount,
                COALESCE(SUM(io.total_cost),0) AS total_cost,
                COALESCE(SUM(io.profit_amount),0) AS total_profit,
                COALESCE(SUM(io.paid_amount),0) AS total_paid,
                COALESCE(SUM(io.remaining_amount),0) AS total_remaining
            FROM invoices_out io
            WHERE {$where_clause}
        ";
        
        if ($stt = $conn->prepare($totals_sql)) {
            $stt->bind_param($param_types, ...$params);
            if ($stt->execute()) {
                $r = $stt->get_result()->fetch_assoc();
                $invoice_count = intval($r['invoice_count'] ?? 0);
                $total_revenue_before_discount = floatval($r['total_revenue_before_discount'] ?? 0);
                $total_revenue_after_discount = floatval($r['total_revenue_after_discount'] ?? 0);
                $total_discount = floatval($r['total_discount'] ?? 0);
                $total_cost = floatval($r['total_cost'] ?? 0);
                $total_profit = floatval($r['total_profit'] ?? 0);
                $total_paid = floatval($r['total_paid'] ?? 0);
                $total_remaining = floatval($r['total_remaining'] ?? 0);
                
                // حساب النسب
                $profit_margin = ($total_revenue_after_discount > 0) ? ($total_profit / $total_revenue_after_discount) * 100 : 0;
                $discount_percent = ($total_revenue_before_discount > 0) ? ($total_discount / $total_revenue_before_discount) * 100 : 0;
                
            } else {
                $message = "<div class='alert alert-danger'>فشل حساب الإجماليات: " . e($stt->error) . "</div>";
            }
            $stt->close();
        } else {
            $message = "<div class='alert alert-danger'>فشل تحضير استعلام الإجماليات: " . e($conn->error) . "</div>";
        }

        // ====== جلب قائمة الفواتير مع التفاصيل ======
        $sql = "
            SELECT
                io.id AS invoice_id,
                io.created_at AS invoice_created_at,
                COALESCE(c.name, '') AS customer_name,
                c.id AS customer_id,
                c.mobile AS customer_mobile,
                io.delivered,
                io.total_before_discount,
                io.discount_type,
                io.discount_value,
                io.discount_amount,
                io.total_after_discount,
                io.total_cost,
                io.profit_amount,
                io.paid_amount,
                io.remaining_amount,
                io.invoice_group,
                io.discount_scope,
                io.work_order_id,
                COALESCE(wo.title, '') AS work_order_title,
                io.notes,
                CASE 
                    WHEN io.delivered = 'reverted' THEN 'returned'
                    WHEN io.remaining_amount = 0 THEN 'paid'
                    WHEN io.paid_amount > 0 AND io.remaining_amount > 0 THEN 'partial'
                    ELSE 'pending'
                END AS payment_status,
                CASE 
                    WHEN io.discount_scope = 'invoice' AND io.discount_amount > 0 THEN 'نعم'
                    WHEN io.discount_scope = 'items' THEN 'على البنود'
                    WHEN io.discount_scope = 'mixed' THEN 'مختلط'
                    ELSE 'لا'
                END AS has_discount,
                (SELECT COUNT(*) FROM invoice_out_items ioi WHERE ioi.invoice_out_id = io.id AND ioi.return_flag = 0) AS active_items_count,
                (SELECT COUNT(*) FROM invoice_out_items ioi WHERE ioi.invoice_out_id = io.id AND ioi.return_flag = 1) AS returned_items_count
            FROM invoices_out io
            LEFT JOIN customers c ON c.id = io.customer_id
            LEFT JOIN work_orders wo ON wo.id = io.work_order_id
            WHERE {$where_clause}
            ORDER BY io.created_at DESC, io.id DESC
            LIMIT 500
        ";

        if ($stmt = $conn->prepare($sql)) {
            $stmt->bind_param($param_types, ...$params);
            if ($stmt->execute()) {
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    // تحويل القيم العددية
                    $row['invoice_id'] = intval($row['invoice_id']);
                    $row['customer_id'] = intval($row['customer_id'] ?? 0);
                    $row['work_order_id'] = intval($row['work_order_id'] ?? 0);
                    $row['total_before_discount'] = floatval($row['total_before_discount'] ?? 0);
                    $row['discount_value'] = floatval($row['discount_value'] ?? 0);
                    $row['discount_amount'] = floatval($row['discount_amount'] ?? 0);
                    $row['total_after_discount'] = floatval($row['total_after_discount'] ?? 0);
                    $row['total_cost'] = floatval($row['total_cost'] ?? 0);
                    $row['profit_amount'] = floatval($row['profit_amount'] ?? 0);
                    $row['paid_amount'] = floatval($row['paid_amount'] ?? 0);
                    $row['remaining_amount'] = floatval($row['remaining_amount'] ?? 0);
                    $row['active_items_count'] = intval($row['active_items_count'] ?? 0);
                    $row['returned_items_count'] = intval($row['returned_items_count'] ?? 0);
                    
                    // حساب هامش الربح
                    $row['profit_margin'] = ($row['total_after_discount'] > 0) 
                        ? round(($row['profit_amount'] / $row['total_after_discount']) * 100, 2)
                        : 0;
                    
                    $summaries[] = $row;
                }
            } else {
                $message = "<div class='alert alert-danger'>خطأ في تنفيذ الاستعلام: " . e($stmt->error) . "</div>";
            }
            $stmt->close();
        } else {
            $message = "<div class='alert alert-danger'>خطأ في تحضير الاستعلام: " . e($conn->error) . "</div>";
        }

        // ====== جلب قائمة العملاء للفلتر ======
        $customers = [];
        $cust_sql = "
            SELECT DISTINCT c.id, c.name, c.mobile
            FROM invoices_out io
            JOIN customers c ON c.id = io.customer_id
            WHERE io.created_at BETWEEN ? AND ?
            ORDER BY c.name
        ";
        if ($cust_stmt = $conn->prepare($cust_sql)) {
            $cust_stmt->bind_param("ss", $start_sql, $end_sql);
            if ($cust_stmt->execute()) {
                $cust_res = $cust_stmt->get_result();
                while ($cust = $cust_res->fetch_assoc()) {
                    $customers[] = $cust;
                }
            }
            $cust_stmt->close();
        }
    }
}

require_once BASE_DIR . 'partials/header.php';
require_once BASE_DIR . 'partials/sidebar.php';
?>

<style>
:root {
  --primary: #0b84ff;
  --success: #10b981;
  --warning: #f59e0b;
  --danger: #ef4444;
  --info: #3b82f6;
  --surface: #ffffff;
  --surface-2: #f9fbff;
  --text: #0f172a;
  --text-soft: #334155;
  --muted: #64748b;
  --border: rgba(2,6,23,0.08);
  --radius: 14px;
  --shadow-1: 0 10px 24px rgba(15,23,42,0.06);
  --shadow-2: 0 12px 28px rgba(11,132,255,0.14);
}

.container-fluid { padding: 18px; }
.page-header { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:20px; flex-wrap: wrap; }
.page-header h3 { margin:0; font-size:1.5rem; color:var(--text); font-weight:700; }
.page-header .subtitle { color:var(--text-soft); font-size:0.95rem; }

/* بطاقات الإحصائيات */
.stats-grid { 
    display: grid; 
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); 
    gap: 16px; 
    margin-bottom: 24px; 
}
.stat-card {
    background: var(--surface);
    border-radius: var(--radius);
    padding: 18px;
    box-shadow: var(--shadow-1);
    border-left: 4px solid var(--primary);
    transition: transform 0.2s ease;
}
.stat-card:hover { transform: translateY(-4px); }
.stat-card.revenue { border-left-color: #10b981; }
.stat-card.cost { border-left-color: #f59e0b; }
.stat-card.profit { border-left-color: #8b5cf6; }
.stat-card.discount { border-left-color: #3b82f6; }
.stat-card .stat-title { 
    color: var(--muted); 
    font-size: 0.9rem; 
    margin-bottom: 8px; 
    font-weight: 600; 
}
.stat-card .stat-value { 
    font-size: 1.8rem; 
    font-weight: 800; 
    color: var(--text); 
    margin-bottom: 4px; 
}
.stat-card .stat-sub { 
    color: var(--text-soft); 
    font-size: 0.85rem; 
}
.stat-badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 600;
    margin-left: 8px;
}
.badge-positive { background: rgba(16,185,129,0.12); color: #065f46; }
.badge-negative { background: rgba(239,68,68,0.12); color: #991b1b; }
.badge-neutral { background: rgba(107,114,128,0.12); color: #374151; }

/* بادجات حالة الفاتورة */
.status-badge {
    padding: 6px 12px;
    border-radius: 8px;
    font-size: 0.85rem;
    font-weight: 600;
    display: inline-block;
    min-width: 80px;
    text-align: center;
}
.status-paid { background: rgba(16,185,129,0.12); color: #065f46; border: 1px solid rgba(16,185,129,0.2); }
.status-partial { background: rgba(245,158,11,0.12); color: #92400e; border: 1px solid rgba(245,158,11,0.2); }
.status-pending { background: rgba(239,68,68,0.08); color: #991b1b; border: 1px solid rgba(239,68,68,0.15); }
.status-returned { background: rgba(107,114,128,0.12); color: #374151; border: 1px solid rgba(107,114,128,0.2); }
.status-delivered { background: rgba(59,130,246,0.12); color: #1e40af; border: 1px solid rgba(59,130,246,0.2); }

/* فلاتر */
.filter-section { 
    background: var(--surface-2); 
    border-radius: var(--radius); 
    padding: 20px; 
    margin-bottom: 20px; 
    border: 1px solid var(--border);
}
.filter-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
    gap: 16px;
    margin-bottom: 16px;
}
.filter-group { margin-bottom: 12px; }
.filter-group label { 
    display: block; 
    margin-bottom: 6px; 
    font-weight: 600; 
    color: var(--text-soft); 
    font-size: 0.9rem; 
}
.filter-select, .filter-input, .filter-search { 
    width: 100%; 
    padding: 10px 12px; 
    border-radius: 8px; 
    border: 1px solid var(--border); 
    background: var(--surface);
    color: var(--text);
    font-size: 0.95rem;
    transition: border-color 0.2s ease;
}
.filter-select:focus, .filter-input:focus, .filter-search:focus { 
    outline: none; 
    border-color: var(--primary); 
    box-shadow: 0 0 0 3px rgba(11,132,255,0.1); 
}
.filter-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    margin-top: 15px;
    padding-top: 15px;
    border-top: 1px solid var(--border);
}
.btn { 
    padding: 10px 18px; 
    border-radius: 8px; 
    border: none; 
    font-weight: 600; 
    cursor: pointer; 
    font-size: 0.9rem; 
    transition: all 0.2s ease;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
}
.btn-primary { background: var(--primary); color: white; }
.btn-secondary { background: var(--muted); color: white; }
.btn-success { background: var(--success); color: white; }
.btn-warning { background: var(--warning); color: white; }
.btn-light { background: var(--surface-2); color: var(--text); border: 1px solid var(--border); }
.btn-sm { padding: 6px 12px; font-size: 0.85rem; }
.btn:hover { opacity: 0.9; transform: translateY(-1px); }

/* البحث التلقائي */
.autocomplete-wrapper {
    position: relative;
}
.autocomplete-results {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 8px;
    margin-top: 4px;
    max-height: 300px;
    overflow-y: auto;
    z-index: 1000;
    box-shadow: var(--shadow-1);
    display: none;
}
.autocomplete-result {
    padding: 10px 12px;
    cursor: pointer;
    border-bottom: 1px solid var(--border);
    transition: background 0.2s;
}
.autocomplete-result:hover {
    background: var(--surface-2);
}
.autocomplete-result:last-child {
    border-bottom: none;
}

/* أدوات البحث السريع */
.quick-search-tools {
    display: flex;
    gap: 8px;
    margin-top: 8px;
    flex-wrap: wrap;
}
.quick-search-btn {
    padding: 6px 12px;
    background: var(--surface-2);
    border: 1px solid var(--border);
    border-radius: 6px;
    font-size: 0.85rem;
    color: var(--text-soft);
    cursor: pointer;
    transition: all 0.2s;
}
.quick-search-btn:hover {
    background: var(--primary);
    color: white;
    border-color: var(--primary);
}

/* الجدول */
.table-wrapper { 
    background: var(--surface); 
    border-radius: var(--radius); 
    overflow: hidden; 
    box-shadow: var(--shadow-1);
    margin-bottom: 24px;
}
.table-header { 
    padding: 16px; 
    border-bottom: 1px solid var(--border); 
    background: var(--surface-2);
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 10px;
}
.table-header h5 { margin: 0; color: var(--text); }
.custom-table { 
    width: 100%; 
    border-collapse: collapse; 
    font-size: 0.9rem;
}
.custom-table th { 
    padding: 12px 16px; 
    text-align: right; 
    background: var(--surface-2); 
    color: var(--text-soft); 
    font-weight: 600; 
    border-bottom: 2px solid var(--border);
    white-space: nowrap;
}
.custom-table td { 
    padding: 12px 16px; 
    border-bottom: 1px solid var(--border); 
    color: var(--text);
    vertical-align: middle;
}
.custom-table tbody tr:hover { background: var(--surface-2); }
.text-end { text-align: right; }
.text-center { text-align: center; }

/* خصومات */
.discount-badge {
    display: inline-block;
    padding: 4px 8px;
    border-radius: 6px;
    background: rgba(59,130,246,0.12);
    color: #1e40af;
    font-size: 0.8rem;
    font-weight: 600;
    margin-right: 5px;
}
.work-order-badge {
    display: inline-block;
    padding: 4px 8px;
    border-radius: 6px;
    background: rgba(139,92,246,0.12);
    color: #5b21b6;
    font-size: 0.8rem;
    font-weight: 600;
    margin-top: 4px;
}

/* المودال */
.modal-backdrop-lite { 
    position: fixed; 
    left: 0; top: 0; 
    right: 0; bottom: 0; 
    background: rgba(2,6,23,0.5); 
    display: none; 
    align-items: center; 
    justify-content: center; 
    z-index: 9999; 
    padding: 16px; 
}
.modal-card { 
    background: var(--surface); 
    border-radius: 12px; 
    max-width: 1000px; 
    width: 100%; 
    max-height: 85vh; 
    overflow: auto; 
    padding: 24px; 
    box-shadow: var(--shadow-2);
}
.modal-header { 
    display: flex; 
    justify-content: space-between; 
    align-items: center; 
    margin-bottom: 20px; 
    padding-bottom: 16px; 
    border-bottom: 1px solid var(--border);
}
.modal-header h5 { margin: 0; color: var(--text); font-size: 1.2rem; }
.modal-body { padding: 0; }

/* أدوات الجدول */
.table-tools {
    display: flex;
    gap: 10px;
    align-items: center;
}
.export-options {
    display: flex;
    gap: 8px;
}

@media (max-width: 768px) {
    .stats-grid { grid-template-columns: 1fr; }
    .filter-grid { grid-template-columns: 1fr; }
    .filter-actions { flex-direction: column; }
    .quick-search-tools { justify-content: center; }
    .custom-table { font-size: 0.85rem; }
    .custom-table th, .custom-table td { padding: 8px 12px; }
    .table-header { flex-direction: column; align-items: stretch; }
}
</style>

<div class="container-fluid">
    <div class="page-header">
        <div>
            <h3><i class="fas fa-chart-line me-2"></i> تقرير الربح - ملخص الفواتير</h3>
            <div class="subtitle">عرض الفواتير المسلّمة مع استبعاد المرتجعة والملغاة | حساب الأرباح من بيانات الفاتورة المخزنة</div>
        </div>
        <div class="tools-bar">
            <a href="<?php echo htmlspecialchars(BASE_URL . 'user/welcome.php'); ?>" class="btn btn-light">
                <i class="fas fa-arrow-right me-1"></i> العودة
            </a>
            <div class="export-options">
                <button id="printBtn" class="btn btn-primary">
                    <i class="fas fa-print me-1"></i> طباعة
                </button>
                <button id="exportExcel" class="btn btn-success">
                    <i class="fas fa-file-excel me-1"></i> Excel
                </button>
            </div>
        </div>
    </div>

    <?php echo $message; ?>

    <!-- فلترة -->
    <div class="filter-section">
        <form id="filterForm" method="get" class="filter-form">
            <div class="filter-grid">
                <!-- فلتر رقم الفاتورة -->
                <div class="filter-group">
                    <label><i class="fas fa-file-invoice me-1"></i> رقم الفاتورة:</label>
                    <input type="number" name="invoice_id" class="filter-input" 
                           value="<?php echo e($invoice_id_filter); ?>" 
                           placeholder="أدخل رقم الفاتورة..." 
                           min="1">
                </div>
                
                <!-- فلتر حالة الفاتورة -->
                <div class="filter-group">
                    <label><i class="fas fa-filter me-1"></i> حالة الفاتورة:</label>
                    <select name="status" class="filter-select">
                        <option value="">جميع الحالات</option>
                        <option value="delivered" <?php echo $status_filter == 'delivered' ? 'selected' : ''; ?>>مسلّم</option>
                        <option value="paid" <?php echo $status_filter == 'paid' ? 'selected' : ''; ?>>مدفوع بالكامل</option>
                        <option value="partial" <?php echo $status_filter == 'partial' ? 'selected' : ''; ?>>مدفوع جزئي</option>
                        <option value="pending" <?php echo $status_filter == 'pending' ? 'selected' : ''; ?>>قيد الانتظار</option>
                        <option value="returned" <?php echo $status_filter == 'returned' ? 'selected' : ''; ?>>مرتجع</option>
                    </select>
                </div>
                
                <!-- فلتر العميل مع بحث تفاعلي -->
                <div class="filter-group">
                    <label><i class="fas fa-user me-1"></i> العميل:</label>
                    <div class="autocomplete-wrapper">
                        <input type="text" id="customerSearch" class="filter-search" 
                               placeholder="ابحث عن عميل..." 
                               autocomplete="off">
                        <input type="hidden" name="customer_id" id="selectedCustomerId" 
                               value="<?php echo $customer_filter; ?>">
                        <div id="customerResults" class="autocomplete-results"></div>
                    </div>
                    <?php if ($customer_filter > 0 && !empty($customers)): 
                        $selected_customer = null;
                        foreach ($customers as $cust) {
                            if ($cust['id'] == $customer_filter) {
                                $selected_customer = $cust;
                                break;
                            }
                        }
                    ?>
                    <div class="mt-2">
                        <span class="badge bg-primary">
                            <?php echo e($selected_customer['name'] ?? 'غير معروف'); ?>
                            <button type="button" class="btn-clear-customer" style="background:none; border:none; color:white; margin-right:5px;">×</button>
                        </span>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- فلتر الشغلانة مع بحث تفاعلي -->
                <div class="filter-group">
                    <label><i class="fas fa-briefcase me-1"></i> الشغلانة:</label>
                    <div class="autocomplete-wrapper">
                        <input type="text" id="workOrderSearch" class="filter-search" 
                               placeholder="ابحث عن شغلانة..." 
                               autocomplete="off">
                        <input type="hidden" name="work_order_id" id="selectedWorkOrderId" 
                               value="<?php echo $work_order_filter; ?>">
                        <div id="workOrderResults" class="autocomplete-results"></div>
                    </div>
                    <?php if ($work_order_filter > 0 && !empty($summaries)): 
                        $selected_work_order = null;
                        foreach ($summaries as $inv) {
                            if ($inv['work_order_id'] == $work_order_filter) {
                                $selected_work_order = $inv;
                                break;
                            }
                        }
                    ?>
                    <div class="mt-2">
                        <span class="badge bg-purple">
                            <?php echo e($selected_work_order['work_order_title'] ?? 'غير معروف'); ?>
                            <button type="button" class="btn-clear-workorder" style="background:none; border:none; color:white; margin-right:5px;">×</button>
                        </span>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- فلتر الملاحظات -->
                <div class="filter-group">
                    <label><i class="fas fa-sticky-note me-1"></i> البحث في الملاحظات:</label>
                    <input type="text" name="notes" class="filter-input" 
                           value="<?php echo e($notes_filter); ?>" 
                           placeholder="ابحث في ملاحظات الفواتير...">
                </div>
                
                <!-- فلتر التاريخ -->
                <div class="filter-group">
                    <label><i class="fas fa-calendar me-1"></i> من تاريخ:</label>
                    <input type="date" name="start_date" class="filter-input" 
                           value="<?php echo e($start_date_filter); ?>" required>
                </div>
                
                <div class="filter-group">
                    <label><i class="fas fa-calendar me-1"></i> إلى تاريخ:</label>
                    <input type="date" name="end_date" class="filter-input" 
                           value="<?php echo e($end_date_filter); ?>" required>
                </div>
            </div>
            
            <!-- أدوات البحث السريع -->
            <div class="quick-search-tools">
                <span style="color: var(--muted); font-size: 0.9rem; margin-right: 10px;">بحث سريع:</span>
                <button type="button" class="quick-search-btn" data-days="0">اليوم</button>
                <button type="button" class="quick-search-btn" data-days="7">آخر أسبوع</button>
                <button type="button" class="quick-search-btn" data-days="30">آخر شهر</button>
                <button type="button" class="quick-search-btn" data-days="90">آخر ٣ أشهر</button>
                <button type="button" class="quick-search-btn" data-days="365">آخر سنة</button>
            </div>
            
            <div class="filter-actions">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-search me-1"></i> عرض التقرير
                </button>
                
                <button type="submit" name="from_beginning" value="1" class="btn btn-success">
                    <i class="fas fa-history me-1"></i> من أول المدة (2022)
                </button>
                
                <button type="button" id="clearFilters" class="btn btn-light">
                    <i class="fas fa-redo me-1"></i> إعادة تعيين
                </button>
                
                <button type="button" id="advancedToggle" class="btn btn-warning">
                    <i class="fas fa-cogs me-1"></i> خيارات متقدمة
                </button>
                
                <div style="margin-left: auto; display: flex; gap: 8px;">
                    <span id="resultsCount" style="color: var(--muted); font-size: 0.9rem; align-self: center;">
                        <?php echo $report_generated ? count($summaries) . ' فاتورة' : ''; ?>
                    </span>
                </div>
            </div>
        </form>
    </div>

    <?php if ($report_generated): ?>
        <!-- إحصائيات رئيسية -->
        <div class="stats-grid">
            <div class="stat-card revenue">
                <div class="stat-title">إجمالي الإيرادات</div>
                <div class="stat-value"><?php echo number_format($total_revenue_after_discount ?? 0, 2); ?> <small>ج.م</small></div>
                <div class="stat-sub">
                    قبل الخصم: <?php echo number_format($total_revenue_before_discount ?? 0, 2); ?> ج.م
                    <?php if ($total_discount > 0): ?>
                        <span class="badge-neutral">خصم: <?php echo number_format($total_discount, 2); ?> ج.م (<?php echo round($discount_percent, 2); ?>%)</span>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="stat-card cost">
                <div class="stat-title">تكلفة البضاعة المباعة</div>
                <div class="stat-value"><?php echo number_format($total_cost ?? 0, 2); ?> <small>ج.م</small></div>
                <div class="stat-sub">من <?php echo $invoice_count ?? 0; ?> فاتورة</div>
            </div>
            
            <div class="stat-card profit">
                <div class="stat-title">صافي الربح</div>
                <div class="stat-value">
                    <?php echo number_format($total_profit ?? 0, 2); ?> <small>ج.م</small>
                    <span class="stat-badge <?php echo ($total_profit ?? 0) >= 0 ? 'badge-positive' : 'badge-negative'; ?>">
                        <?php echo round($profit_margin ?? 0, 2); ?>%
                    </span>
                </div>
                <div class="stat-sub">هامش الربح</div>
            </div>
            
            <div class="stat-card discount">
                <div class="stat-title">المدفوعات</div>
                <div class="stat-value"><?php echo number_format($total_paid ?? 0, 2); ?> <small>ج.م</small></div>
                <div class="stat-sub">المتبقي: <?php echo number_format($total_remaining ?? 0, 2); ?> ج.م</div>
            </div>
        </div>

        <!-- جدول الفواتير -->
        <div class="table-wrapper">
            <div class="table-header">
                <h5><i class="fas fa-file-invoice me-2"></i> قائمة الفواتير (<?php echo count($summaries); ?> فاتورة)</h5>
                <div class="table-tools">
                    <button id="selectAll" class="btn btn-sm btn-light">
                        <i class="fas fa-check-square me-1"></i> تحديد الكل
                    </button>
                    <button id="deselectAll" class="btn btn-sm btn-light">
                        <i class="fas fa-square me-1"></i> إلغاء التحديد
                    </button>
                </div>
            </div>
            
            <?php if (empty($summaries)): ?>
                <div style="padding: 40px; text-align: center; color: var(--muted);">
                    <i class="fas fa-inbox fa-2x mb-3"></i>
                    <p>لا توجد فواتير مطابقة للفلاتر المحددة.</p>
                </div>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table class="custom-table" id="reportTable">
                        <thead>
                            <tr>
                                <th style="width: 50px">
                                    <input type="checkbox" id="checkAll">
                                </th>
                                <th style="width: 80px">#</th>
                                <th style="width: 150px">التاريخ</th>
                                <th>العميل</th>
                                <th style="width: 130px" class="text-end">الإيرادات</th>
                                <th style="width: 120px" class="text-end">التكلفة</th>
                                <th style="width: 120px" class="text-end">الربح</th>
                                <th style="width: 100px" class="text-center">هامش</th>
                                <th style="width: 100px" class="text-center">الحالة</th>
                                <th style="width: 100px" class="text-center">الخصم</th>
                                <th style="width: 120px" class="text-center">الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($summaries as $invoice): 
                                $profit_class = ($invoice['profit_amount'] >= 0) ? 'badge-positive' : 'badge-negative';
                                $status_class = 'status-' . $invoice['payment_status'];
                                $status_text = '';
                                switch($invoice['payment_status']) {
                                    case 'paid': $status_text = 'مدفوع'; break;
                                    case 'partial': $status_text = 'جزئي'; break;
                                    case 'pending': $status_text = 'مؤجل'; break;
                                    case 'returned': $status_text = 'مرتجع'; break;
                                    case 'delivered': $status_text = 'مسلّم'; break;
                                    default: $status_text = $invoice['payment_status'];
                                }
                            ?>
                            <tr data-invoice-id="<?php echo $invoice['invoice_id']; ?>">
                                <td>
                                    <input type="checkbox" class="invoice-check" value="<?php echo $invoice['invoice_id']; ?>">
                                </td>
                                <td><strong>#<?php echo $invoice['invoice_id']; ?></strong></td>
                                <td><?php echo date('Y-m-d H:i', strtotime($invoice['invoice_created_at'])); ?></td>
                                <td>
                                    <div><?php echo e($invoice['customer_name']); ?></div>
                                    <?php if (!empty($invoice['customer_mobile'])): ?>
                                        <small class="text-muted d-block"><?php echo e($invoice['customer_mobile']); ?></small>
                                    <?php endif; ?>
                                    <?php if (!empty($invoice['work_order_title'])): ?>
                                        <div class="work-order-badge" title="الشغلانة">
                                            <i class="fas fa-briefcase me-1"></i>
                                            <?php echo e($invoice['work_order_title']); ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($invoice['active_items_count'] > 0): ?>
                                        <small class="text-muted d-block"><?php echo $invoice['active_items_count']; ?> بند نشط</small>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <?php if ($invoice['discount_amount'] > 0): ?>
                                        <div class="text-decoration-line-through text-muted small">
                                            <?php echo number_format($invoice['total_before_discount'], 2); ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php echo number_format($invoice['total_after_discount'], 2); ?> ج.م
                                </td>
                                <td class="text-end"><?php echo number_format($invoice['total_cost'], 2); ?> ج.م</td>
                                <td class="text-end">
                                    <span class="<?php echo $profit_class; ?> stat-badge">
                                        <?php echo number_format($invoice['profit_amount'], 2); ?> ج.م
                                    </span>
                                </td>
                                <td class="text-center">
                                    <span class="<?php echo $invoice['profit_margin'] >= 0 ? 'badge-positive' : 'badge-negative'; ?> stat-badge">
                                        <?php echo $invoice['profit_margin']; ?>%
                                    </span>
                                </td>
                                <td class="text-center">
                                    <span class="status-badge <?php echo $status_class; ?>">
                                        <?php echo $status_text; ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <?php if ($invoice['discount_amount'] > 0): ?>
                                        <span class="discount-badge">
                                            <?php if ($invoice['discount_type'] == 'percent'): ?>
                                                <?php echo $invoice['discount_value']; ?>%
                                            <?php else: ?>
                                                <?php echo number_format($invoice['discount_value'], 2); ?> ج.م
                                            <?php endif; ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <button type="button" class="btn btn-sm btn-primary details-btn" 
                                            data-invoice-id="<?php echo $invoice['invoice_id']; ?>"
                                            title="عرض التفاصيل">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <button type="button" class="btn btn-sm btn-success print-btn" 
                                            data-invoice-id="<?php echo $invoice['invoice_id']; ?>"
                                            title="طباعة الفاتورة">
                                        <i class="fas fa-print"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="4" class="text-end"><strong>المجموع:</strong></td>
                                <td class="text-end"><strong><?php echo number_format(array_sum(array_column($summaries, 'total_after_discount')), 2); ?> ج.م</strong></td>
                                <td class="text-end"><strong><?php echo number_format(array_sum(array_column($summaries, 'total_cost')), 2); ?> ج.م</strong></td>
                                <td class="text-end"><strong><?php echo number_format(array_sum(array_column($summaries, 'profit_amount')), 2); ?> ج.م</strong></td>
                                <td class="text-center">
                                    <?php 
                                        $total_rev = array_sum(array_column($summaries, 'total_after_discount'));
                                        $total_prof = array_sum(array_column($summaries, 'profit_amount'));
                                        $avg_margin = $total_rev > 0 ? ($total_prof / $total_rev) * 100 : 0;
                                    ?>
                                    <strong><?php echo round($avg_margin, 2); ?>%</strong>
                                </td>
                                <td colspan="3"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- ملاحظات -->
        <div class="card" style="background: var(--surface-2); border: none; padding: 16px; border-radius: var(--radius);">
            <div class="card-body">
                <h6><i class="fas fa-info-circle me-2"></i> معلومات حول التقرير:</h6>
                <ul style="margin-bottom: 0; color: var(--text-soft); font-size: 0.9rem;">
                    <li>يتم حساب الأرباح من القيم المخزنة في جدول الفواتير مباشرة (profit_amount).</li>
                    <li>تم استبعاد البنود المرتجعة بالكامل (return_flag = 1).</li>
                    <li>الإيرادات المعروضة هي بعد تطبيق جميع الخصومات.</li>
                    <li>هامش الربح = (الربح ÷ الإيرادات بعد الخصم) × 100.</li>
                    <li>الكمية الفعالة = الكمية الأصلية - الكمية المرتجعة (available_for_return).</li>
                    <li>اضغط زر <i class="fas fa-eye"></i> لعرض تفاصيل بنود الفاتورة مع الخصومات.</li>
                </ul>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Modal لعرض تفاصيل الفاتورة -->
<div id="modalBackdrop" class="modal-backdrop-lite" role="dialog" aria-hidden="true">
    <div class="modal-card" role="document" aria-modal="true">
        <div class="modal-header">
            <h5 id="modalTitle">تفاصيل فاتورة <span id="invoiceNumber"></span></h5>
            <div>
                <button id="printModalBtn" class="btn btn-success btn-sm">
                    <i class="fas fa-print me-1"></i> طباعة
                </button>
                <button id="closeModal" class="btn btn-light btn-sm">
                    <i class="fas fa-times"></i> إغلاق
                </button>
            </div>
        </div>
        <div class="modal-body">
            <div class="mb-3" id="modalInvoiceInfo"></div>
            <div id="modalContent">جارٍ تحميل تفاصيل الفاتورة...</div>
        </div>
    </div>
</div>

<!-- Modal خيارات الطباعة -->
<div id="printModal" class="modal-backdrop-lite" style="display:none;">
    <div class="modal-card" style="max-width: 500px;">
        <div class="modal-header">
            <h5><i class="fas fa-print me-2"></i> خيارات الطباعة</h5>
            <button type="button" class="btn-close" onclick="closePrintModal()">×</button>
        </div>
        <div class="modal-body">
            <div class="mb-3">
                <label class="form-label">نوع الطباعة:</label>
                <select id="printType" class="form-select">
                    <option value="pos">طباعة POS (صغيرة)</option>
                    <option value="a4">طباعة A4 (مفصلة)</option>
                    <option value="summary">ملخص التقرير</option>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label">الفواتير المحددة:</label>
                <div id="selectedInvoicesList" style="max-height: 200px; overflow-y: auto; background: #f8f9fa; padding: 10px; border-radius: 8px;">
                    <!-- سيتم ملؤها بالجافاسكريبت -->
                </div>
            </div>
            <div class="d-flex justify-content-between">
                <button type="button" class="btn btn-light" onclick="closePrintModal()">إلغاء</button>
                <button type="button" class="btn btn-primary" onclick="printSelected()">طباعة</button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function(){
    // عناصر DOM
    const filterForm = document.getElementById('filterForm');
    const startDate = document.querySelector('input[name="start_date"]');
    const endDate = document.querySelector('input[name="end_date"]');
    const printBtn = document.getElementById('printBtn');
    const exportExcel = document.getElementById('exportExcel');
    const clearFilters = document.getElementById('clearFilters');
    const advancedToggle = document.getElementById('advancedToggle');
    const checkAll = document.getElementById('checkAll');
    const selectAllBtn = document.getElementById('selectAll');
    const deselectAllBtn = document.getElementById('deselectAll');
    
    // المودال
    const modal = document.getElementById('modalBackdrop');
    const modalTitle = document.getElementById('modalTitle');
    const modalInvoiceInfo = document.getElementById('modalInvoiceInfo');
    const modalContent = document.getElementById('modalContent');
    const closeModal = document.getElementById('closeModal');
    const invoiceNumber = document.getElementById('invoiceNumber');
    const printModalBtn = document.getElementById('printModalBtn');
    
    // البحث التلقائي للعملاء
    const customerSearch = document.getElementById('customerSearch');
    const customerResults = document.getElementById('customerResults');
    const selectedCustomerId = document.getElementById('selectedCustomerId');
    
    // البحث التلقائي للشغلانات
    const workOrderSearch = document.getElementById('workOrderSearch');
    const workOrderResults = document.getElementById('workOrderResults');
    const selectedWorkOrderId = document.getElementById('selectedWorkOrderId');
    
    // البحث التلقائي للعملاء
    if (customerSearch) {
        let customerSearchTimeout;
        customerSearch.addEventListener('input', function() {
            clearTimeout(customerSearchTimeout);
            customerSearchTimeout = setTimeout(function() {
                const term = customerSearch.value.trim();
                if (term.length < 2) {
                    customerResults.style.display = 'none';
                    return;
                }
                
                fetch(`?action=search_customers&term=${encodeURIComponent(term)}`)
                    .then(response => response.json())
                    .then(data => {
                        customerResults.innerHTML = '';
                        if (data.length === 0) {
                            customerResults.innerHTML = '<div class="autocomplete-result">لا توجد نتائج</div>';
                        } else {
                            data.forEach(customer => {
                                const div = document.createElement('div');
                                div.className = 'autocomplete-result';
                                div.textContent = customer.label;
                                div.dataset.value = customer.value;
                                div.addEventListener('click', function() {
                                    selectedCustomerId.value = customer.value;
                                    customerSearch.value = customer.label;
                                    customerResults.style.display = 'none';
                                });
                                customerResults.appendChild(div);
                            });
                        }
                        customerResults.style.display = 'block';
                    })
                    .catch(error => {
                        console.error('Error:', error);
                    });
            }, 300);
        });
        
        // إغلاق نتائج البحث عند النقر خارجها
        document.addEventListener('click', function(e) {
            if (!customerSearch.contains(e.target) && !customerResults.contains(e.target)) {
                customerResults.style.display = 'none';
            }
        });
    }
    
    // البحث التلقائي للشغلانات
    if (workOrderSearch) {
        let workOrderSearchTimeout;
        workOrderSearch.addEventListener('input', function() {
            clearTimeout(workOrderSearchTimeout);
            workOrderSearchTimeout = setTimeout(function() {
                const term = workOrderSearch.value.trim();
                if (term.length < 2) {
                    workOrderResults.style.display = 'none';
                    return;
                }
                
                fetch(`?action=search_work_orders&term=${encodeURIComponent(term)}`)
                    .then(response => response.json())
                    .then(data => {
                        workOrderResults.innerHTML = '';
                        if (data.length === 0) {
                            workOrderResults.innerHTML = '<div class="autocomplete-result">لا توجد نتائج</div>';
                        } else {
                            data.forEach(workOrder => {
                                const div = document.createElement('div');
                                div.className = 'autocomplete-result';
                                div.textContent = workOrder.label;
                                div.dataset.value = workOrder.value;
                                div.addEventListener('click', function() {
                                    selectedWorkOrderId.value = workOrder.value;
                                    workOrderSearch.value = workOrder.label;
                                    workOrderResults.style.display = 'none';
                                });
                                workOrderResults.appendChild(div);
                            });
                        }
                        workOrderResults.style.display = 'block';
                    })
                    .catch(error => {
                        console.error('Error:', error);
                    });
            }, 300);
        });
        
        // إغلاق نتائج البحث عند النقر خارجها
        document.addEventListener('click', function(e) {
            if (!workOrderSearch.contains(e.target) && !workOrderResults.contains(e.target)) {
                workOrderResults.style.display = 'none';
            }
        });
    }
    
    // مسح فلتر العميل
    document.querySelectorAll('.btn-clear-customer').forEach(btn => {
        btn.addEventListener('click', function() {
            selectedCustomerId.value = '';
            customerSearch.value = '';
            filterForm.submit();
        });
    });
    
    // مسح فلتر الشغلانة
    document.querySelectorAll('.btn-clear-workorder').forEach(btn => {
        btn.addEventListener('click', function() {
            selectedWorkOrderId.value = '';
            workOrderSearch.value = '';
            filterForm.submit();
        });
    });
    
    // البحث السريع
    document.querySelectorAll('.quick-search-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const days = parseInt(this.dataset.days);
            const end = new Date();
            const start = new Date();
            start.setDate(end.getDate() - days);
            
            startDate.value = start.toISOString().split('T')[0];
            endDate.value = end.toISOString().split('T')[0];
            filterForm.submit();
        });
    });
    
    // إعادة تعيين الفلاتر
    clearFilters?.addEventListener('click', function() {
        window.location.href = window.location.pathname;
    });
    
    // تحديد/إلغاء تحديد الكل
    checkAll?.addEventListener('change', function() {
        document.querySelectorAll('.invoice-check').forEach(checkbox => {
            checkbox.checked = this.checked;
        });
    });
    
    selectAllBtn?.addEventListener('click', function() {
        document.querySelectorAll('.invoice-check').forEach(checkbox => {
            checkbox.checked = true;
        });
        checkAll.checked = true;
    });
    
    deselectAllBtn?.addEventListener('click', function() {
        document.querySelectorAll('.invoice-check').forEach(checkbox => {
            checkbox.checked = false;
        });
        checkAll.checked = false;
    });
    
    // طباعة الفواتير المحددة
    printBtn?.addEventListener('click', function() {
        const selectedInvoices = Array.from(document.querySelectorAll('.invoice-check:checked'))
            .map(cb => cb.value);
        
        if (selectedInvoices.length === 0) {
            alert('يرجى تحديد فواتير للطباعة');
            return;
        }
        
        openPrintModal(selectedInvoices);
    });
    
    function openPrintModal(invoiceIds) {
        const printModal = document.getElementById('printModal');
        const listContainer = document.getElementById('selectedInvoicesList');
        
        listContainer.innerHTML = '';
        invoiceIds.forEach(id => {
            const row = document.querySelector(`tr[data-invoice-id="${id}"]`);
            if (row) {
                const invoiceNum = row.querySelector('td:nth-child(2)').textContent.trim();
                const customer = row.querySelector('td:nth-child(4)').textContent.trim().split('\n')[0];
                const total = row.querySelector('td:nth-child(5)').textContent.trim();
                
                const div = document.createElement('div');
                div.style.padding = '5px 0';
                div.style.borderBottom = '1px solid #dee2e6';
                div.innerHTML = `
                    <div><strong>${invoiceNum}</strong> - ${customer}</div>
                    <small class="text-muted">${total}</small>
                `;
                listContainer.appendChild(div);
            }
        });
        
        printModal.style.display = 'flex';
        window.selectedInvoicesForPrint = invoiceIds;
    }
    
    function closePrintModal() {
        document.getElementById('printModal').style.display = 'none';
        window.selectedInvoicesForPrint = null;
    }
    
    function printSelected() {
        const printType = document.getElementById('printType').value;
        const invoiceIds = window.selectedInvoicesForPrint || [];
        
        if (printType === 'summary') {
            printReportSummary();
        } else if (invoiceIds.length > 0) {
            // طباعة الفواتير الفردية
            if (printType === 'pos') {
                // لطباعة POS، نطبع كل فاتورة على حدة
                invoiceIds.forEach(id => {
                    printInvoicePOS(id);
                });
            } else {
                // لطباعة A4، يمكن جمع الفواتير في صفحة واحدة
                printInvoicesA4(invoiceIds);
            }
        }
        closePrintModal();
    }
    
    function printReportSummary() {
        const title = document.querySelector('.page-header h3').innerText;
        const period = `${startDate.value} إلى ${endDate.value}`;
        const selectedCount = window.selectedInvoicesForPrint?.length || <?php echo count($summaries); ?>;
        
        const printWindow = window.open('', '_blank');
        const html = generateSummaryPrintHTML(title, period, selectedCount);
        
        printWindow.document.write(html);
        printWindow.document.close();
        setTimeout(() => {
            printWindow.print();
            printWindow.close();
        }, 500);
    }
    
    function generateSummaryPrintHTML(title, period, count) {
        return `
            <!DOCTYPE html>
            <html dir="rtl" lang="ar">
            <head>
                <meta charset="UTF-8">
                <title>${title}</title>
                <style>
                    body { font-family: 'Arial', sans-serif; padding: 20px; color: #000; }
                    .header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #000; padding-bottom: 20px; }
                    .header h1 { margin: 0; font-size: 24px; }
                    .header .period { color: #666; margin-top: 10px; font-size: 16px; }
                    .stats { display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px; margin: 30px 0; }
                    .stat-box { padding: 15px; border: 1px solid #ddd; border-radius: 8px; text-align: center; }
                    .stat-value { font-size: 24px; font-weight: bold; color: #2c3e50; }
                    .stat-label { color: #7f8c8d; margin-top: 5px; }
                    table { width: 100%; border-collapse: collapse; margin-top: 20px; }
                    th, td { padding: 12px; text-align: right; border: 1px solid #ddd; }
                    th { background-color: #f8f9fa; font-weight: bold; }
                    .footer { margin-top: 40px; text-align: center; color: #666; font-size: 14px; }
                    @media print {
                        body { padding: 10px; }
                        .no-print { display: none; }
                    }
                </style>
            </head>
            <body>
                <div class="header">
                    <h1>${title}</h1>
                    <div class="period">الفترة: ${period}</div>
                    <div class="period">عدد الفواتير: ${count}</div>
                    <div class="period">تاريخ الطباعة: ${new Date().toLocaleDateString('ar-EG')}</div>
                </div>
                
                <div class="stats">
                    <div class="stat-box">
                        <div class="stat-value">${<?php echo number_format($total_revenue_after_discount ?? 0, 2); ?>}</div>
                        <div class="stat-label">إجمالي الإيرادات</div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-value">${<?php echo number_format($total_cost ?? 0, 2); ?>}</div>
                        <div class="stat-label">إجمالي التكلفة</div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-value">${<?php echo number_format($total_profit ?? 0, 2); ?>}</div>
                        <div class="stat-label">صافي الربح</div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-value">${<?php echo round($profit_margin ?? 0, 2); ?>}%</div>
                        <div class="stat-label">هامش الربح</div>
                    </div>
                </div>
                
                <div class="footer">
                    <p>نظام إدارة الفواتير - تم الإنشاء تلقائياً</p>
                </div>
            </body>
            </html>
        `;
    }
    
    // دالة طباعة فاتورة POS
    function printInvoicePOS(invoiceId) {
        const row = document.querySelector(`tr[data-invoice-id="${invoiceId}"]`);
        if (!row) return;
        
        const invoiceNum = row.querySelector('td:nth-child(2)').textContent.trim();
        const date = row.querySelector('td:nth-child(3)').textContent.trim();
        const customer = row.querySelector('td:nth-child(4) div').textContent.trim();
        const total = row.querySelector('td:nth-child(5)').textContent.trim();
        
        const printWindow = window.open('', '_blank', 'width=280,height=400');
        const html = `
            <!DOCTYPE html>
            <html dir="rtl" lang="ar">
            <head>
                <meta charset="UTF-8">
                <title>فاتورة ${invoiceNum}</title>
                <style>
                    body { padding: 10px; font-family: 'Arial', sans-serif; font-size: 12px; }
                    .header { text-align: center; border-bottom: 1px dashed #000; padding-bottom: 10px; margin-bottom: 10px; }
                    .store-name { font-weight: bold; font-size: 14px; }
                    .invoice-info { display: flex; justify-content: space-between; margin: 10px 0; }
                    .customer-info { margin: 10px 0; padding: 8px; background: #f5f5f5; }
                    .items-table { width: 100%; border-collapse: collapse; margin: 10px 0; }
                    .items-table th, .items-table td { padding: 6px; border-bottom: 1px dashed #ddd; }
                    .total-row { display: flex; justify-content: space-between; padding: 5px 0; border-top: 2px dashed #000; }
                    .footer { text-align: center; margin-top: 15px; font-size: 10px; color: #666; }
                </style>
            </head>
            <body>
                <div class="header">
                    <div class="store-name">نظام الفواتير</div>
                    <div>فاتورة مبيعات</div>
                </div>
                
                <div class="invoice-info">
                    <div>
                        <div>رقم: ${invoiceNum}</div>
                        <div>التاريخ: ${date}</div>
                    </div>
                    <div>نوع: POS</div>
                </div>
                
                <div class="customer-info">
                    <div>العميل: ${customer}</div>
                </div>
                
                <div class="footer">
                    <div>المبلغ: ${total}</div>
                    <div>شكراً لتعاملكم</div>
                    <div>${new Date().toLocaleDateString('ar-EG')}</div>
                </div>
                
                <script>
                    window.onload = function() {
                        setTimeout(() => {
                            window.print();
                            setTimeout(() => window.close(), 500);
                        }, 300);
                    };
                </script>
            </body>
            </html>
        `;
        
        printWindow.document.write(html);
        printWindow.document.close();
    }
    
    // دالة طباعة فواتير A4
    function printInvoicesA4(invoiceIds) {
        // هنا يمكن تنفيذ طباعة متعددة الفواتير في صفحة A4 واحدة
        alert('سيتم طباعة ' + invoiceIds.length + ' فاتورة بتنسيق A4');
        // يمكن تنفيذ هذا الجزء حسب احتياجات النظام
    }
    
    // تصدير Excel
    exportExcel?.addEventListener('click', function() {
        const table = document.getElementById('reportTable');
        const rows = table.querySelectorAll('tr');
        let csv = [];
        
        rows.forEach(row => {
            const rowData = [];
            row.querySelectorAll('th, td').forEach((cell, index) => {
                // تخطي عمود الاختيار
                if (index !== 0) {
                    rowData.push(cell.innerText.replace(/,/g, ''));
                }
            });
            csv.push(rowData.join(','));
        });
        
        const csvContent = csv.join('\n');
        const blob = new Blob(['\uFEFF' + csvContent], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = 'تقرير_الارباح_' + new Date().toISOString().slice(0,10) + '.csv';
        link.click();
    });
    
    // عرض تفاصيل الفاتورة
    document.querySelectorAll('.details-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const invoiceId = this.dataset.invoiceId;
            openInvoiceModal(invoiceId);
        });
    });
    
    // طباعة فاتورة فردية
    document.querySelectorAll('.print-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const invoiceId = this.dataset.invoiceId;
            printInvoicePOS(invoiceId);
        });
    });
    
    async function openInvoiceModal(invoiceId) {
        // عرض المودال
        modal.style.display = 'flex';
        modal.setAttribute('aria-hidden', 'false');
        invoiceNumber.textContent = '#' + invoiceId;
        
        // جلب بيانات الفاتورة
        try {
            modalContent.innerHTML = '<div class="text-center p-4"><i class="fas fa-spinner fa-spin fa-2x"></i><div class="mt-2">جارٍ تحميل تفاصيل الفاتورة...</div></div>';
            
            const response = await fetch(`?action=get_invoice_items&id=${invoiceId}`);
            const data = await response.json();
            
            if (!data.ok) {
                modalContent.innerHTML = `
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle"></i> ${data.msg || 'حدث خطأ أثناء جلب البيانات'}
                    </div>
                `;
                return;
            }
            
            const items = data.items || [];
            const invoice = data.invoice || {};
            
            // عرض معلومات الفاتورة
            let invoiceInfoHTML = `
                <div style="background: var(--surface-2); padding: 15px; border-radius: var(--radius); margin-bottom: 20px;">
                    <div class="row">
                        <div class="col-md-6">
                            <div><strong>العميل:</strong> ${escapeHtml(invoice.customer_name || '')}</div>
                            <div><strong>التاريخ:</strong> ${new Date(invoice.created_at).toLocaleString('ar-EG')}</div>
                        </div>
                        <div class="col-md-6">
                            <div><strong>الحالة:</strong> ${getStatusText(invoice.delivered)}</div>
                            ${invoice.work_order_title ? `<div><strong>الشغلانة:</strong> ${escapeHtml(invoice.work_order_title)}</div>` : ''}
                        </div>
                    </div>
                </div>
            `;
            
            modalInvoiceInfo.innerHTML = invoiceInfoHTML;
            
            if (items.length === 0) {
                modalContent.innerHTML = '<div class="alert alert-info">لا توجد بنود نشطة في هذه الفاتورة.</div>';
                return;
            }
            
            // بناء جدول البنود
            let html = `
                <div style="overflow-x: auto;">
                    <table class="custom-table">
                        <thead>
                            <tr>
                                <th>المنتج</th>
                                <th style="width: 80px" class="text-center">الكمية</th>
                                <th style="width: 80px" class="text-center">المُرجَع</th>
                                <th style="width: 80px" class="text-center">المتبقي</th>
                                <th style="width: 100px" class="text-end">سعر الوحدة</th>
                                <th style="width: 100px" class="text-center">خصم البند</th>
                                <th style="width: 100px" class="text-end">الإجمالي</th>
                                <th style="width: 100px" class="text-end">التكلفة</th>
                                <th style="width: 100px" class="text-end">الربح</th>
                            </tr>
                        </thead>
                        <tbody>
            `;
            
            let totalQuantity = 0;
            let totalReturned = 0;
            let totalAvailable = 0;
            let totalRevenue = 0;
            let totalCost = 0;
            let totalProfit = 0;
            let totalDiscount = 0;
            
            items.forEach(item => {
                const quantity = parseFloat(item.quantity) || 0;
                const returned = parseFloat(item.returned_quantity) || 0;
                const available = parseFloat(item.available_for_return) || 0;
                const unitPrice = parseFloat(item.effective_unit_price) || parseFloat(item.selling_price) || 0;
                const costPrice = parseFloat(item.cost_price_per_unit) || 0;
                const itemDiscount = parseFloat(item.discount_amount) || 0;
                const revenue = parseFloat(item.effective_total) || 0;
                const cost = parseFloat(item.line_cogs) || 0;
                const profit = parseFloat(item.line_profit) || 0;
                
                totalQuantity += quantity;
                totalReturned += returned;
                totalAvailable += available;
                totalRevenue += revenue;
                totalCost += cost;
                totalProfit += profit;
                totalDiscount += itemDiscount;
                
                const profitClass = profit >= 0 ? 'badge-positive' : 'badge-negative';
                
                html += `
                    <tr>
                        <td>${escapeHtml(item.product_name || 'منتج #' + item.product_id)}</td>
                        <td class="text-center">${quantity.toFixed(2)}</td>
                        <td class="text-center">${returned.toFixed(2)}</td>
                        <td class="text-center"><strong>${available.toFixed(2)}</strong></td>
                        <td class="text-end">
                            ${item.discount_type ? `
                                <div class="text-decoration-line-through text-muted small">
                                    ${item.selling_price.toFixed(2)}
                                </div>
                            ` : ''}
                            <div>${unitPrice.toFixed(2)}</div>
                        </td>
                        <td class="text-center">
                            ${item.discount_type ? `
                                <span class="discount-badge">
                                    ${item.discount_display}
                                </span>
                            ` : '<span class="text-muted">-</span>'}
                        </td>
                        <td class="text-end">${revenue.toFixed(2)}</td>
                        <td class="text-end">${cost.toFixed(2)}</td>
                        <td class="text-end">
                            <span class="${profitClass} stat-badge">
                                ${profit.toFixed(2)}
                            </span>
                        </td>
                    </tr>
                `;
            });
            
            // إجماليات البنود
            const totalMargin = totalRevenue > 0 ? (totalProfit / totalRevenue) * 100 : 0;
            const marginClass = totalMargin >= 0 ? 'badge-positive' : 'badge-negative';
            
            html += `
                        </tbody>
                        <tfoot>
                            <tr>
                                <th class="text-end">المجموع:</th>
                                <th class="text-center">${totalQuantity.toFixed(2)}</th>
                                <th class="text-center">${totalReturned.toFixed(2)}</th>
                                <th class="text-center">${totalAvailable.toFixed(2)}</th>
                                <th></th>
                                <th class="text-center">${totalDiscount.toFixed(2)} ج.م</th>
                                <th class="text-end">${totalRevenue.toFixed(2)} ج.م</th>
                                <th class="text-end">${totalCost.toFixed(2)} ج.م</th>
                                <th class="text-end">
                                    <span class="${marginClass} stat-badge">
                                        ${totalProfit.toFixed(2)} ج.م
                                    </span>
                                </th>
                            </tr>
                            <tr>
                                <td colspan="9" class="text-end">
                                    <strong>هامش الربح:</strong> 
                                    <span class="${marginClass} stat-badge">
                                        ${totalMargin.toFixed(2)}%
                                    </span>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            `;
            
            modalContent.innerHTML = html;
            
            // إعداد زر طباعة المودال
            printModalBtn.onclick = function() {
                printInvoicePOS(invoiceId);
            };
            
        } catch (error) {
            console.error('Error loading invoice details:', error);
            modalContent.innerHTML = `
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle"></i> حدث خطأ في الاتصال بالخادم.
                </div>
            `;
        }
    }
    
    // إغلاق المودال
    closeModal.addEventListener('click', function() {
        modal.style.display = 'none';
        modal.setAttribute('aria-hidden', 'true');
        modalContent.innerHTML = '';
        modalInvoiceInfo.innerHTML = '';
    });
    
    modal.addEventListener('click', function(e) {
        if (e.target === modal) {
            modal.style.display = 'none';
            modal.setAttribute('aria-hidden', 'true');
            modalContent.innerHTML = '';
            modalInvoiceInfo.innerHTML = '';
        }
    });
    
    // دالة escape HTML
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    // دالة للحصول على نص الحالة
    function getStatusText(status) {
        const statusMap = {
            'yes': 'مسلّم',
            'no': 'غير مسلّم',
            'canceled': 'ملغى',
            'reverted': 'مرتجع',
            'partial': 'جزئي'
        };
        return statusMap[status] || status;
    }
});
</script>

<?php
require_once BASE_DIR . 'partials/footer.php';
$conn->close();
?>