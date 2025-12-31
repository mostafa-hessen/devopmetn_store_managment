<?php
// api_purchase_returns.php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

require_once dirname(__DIR__, 2) . '/config.php';

if (!isset($conn) || !$conn) {
    echo json_encode([
        "success" => false,
        "message" => "خطأ في الاتصال بقاعدة البيانات"
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// التحقق من المصادقة
$current_user_id = intval($_SESSION['id'] ?? 0);
$current_user_role = $_SESSION['role'] ?? 'user';
if ($current_user_id <= 0) {
    echo json_encode([
        "success" => false,
        "message" => "غير مصرح بالوصول. يرجى تسجيل الدخول."
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// الحصول على طريقة الطلب
$method = $_SERVER['REQUEST_METHOD'];
$action = isset($_GET['action']) ? $_GET['action'] : '';
$return_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// تعريف وظائف المساعدة
function stmt_bind_params($stmt, $types, $params) {
    if (empty($params)) return true;
    $refs = [&$types];
    foreach ($params as $k => $v) $refs[] = &$params[$k];
    return call_user_func_array([$stmt, 'bind_param'], $refs);
}

function generate_return_number($conn) {
    $year = date('Y');
    $month = date('m');
    $prefix = "PR-{$year}-{$month}-";
    
    $sql = "SELECT COUNT(*) as count FROM purchase_returns WHERE return_number LIKE ?";
    $stmt = $conn->prepare($sql);
    $like_pattern = $prefix . '%';
    $stmt->bind_param("s", $like_pattern);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    $next_number = $row['count'] + 1;
    return $prefix . str_pad($next_number, 3, '0', STR_PAD_LEFT);
}

function update_batch_on_return($conn, $batch_id, $return_quantity, $current_user_id, $return_type, $return_reason = null) {
    $sql = "SELECT remaining, product_id FROM batches WHERE id = ? FOR UPDATE";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $batch_id);
    $stmt->execute();
    $batch = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$batch) {
        return false;
    }
    
    $remaining_before = (float)$batch['remaining'];
    $remaining_after = $remaining_before - $return_quantity;
    $product_id = (int)$batch['product_id'];
    
    if ($remaining_after < 0) {
        throw new Exception("الكمية المرتجعة أكبر من الكمية المتبقية في الدفعة");
    }
    
    // تحديث الدفعة
    $update_batch_sql = "UPDATE batches SET 
                         remaining = ?,
                         returned_by = ?,
                         returned_at = NOW(),
                         return_reason = ?
                         WHERE id = ?";
    $stmt = $conn->prepare($update_batch_sql);
    $stmt->bind_param("diss", $remaining_after, $current_user_id, $return_reason, $batch_id);
    $stmt->execute();
    $stmt->close();
    
    // إذا أصبحت الكمية المتبقية صفر، تحديث الحالة إلى returned
    if ($remaining_after == 0) {
        $update_status_sql = "UPDATE batches SET status = 'returned' WHERE id = ?";
        $stmt = $conn->prepare($update_status_sql);
        $stmt->bind_param("i", $batch_id);
        $stmt->execute();
        $stmt->close();
    }
    
    // تحديث المخزون العام
    $update_product_sql = "UPDATE products SET current_stock = current_stock - ? WHERE id = ?";
    $stmt = $conn->prepare($update_product_sql);
    $stmt->bind_param("di", $return_quantity, $product_id);
    $stmt->execute();
    $stmt->close();
    
    return [
        'remaining_before' => $remaining_before,
        'remaining_after' => $remaining_after,
        'product_id' => $product_id
    ];
}

// معالجة الطلبات بناءً على method و action
switch ($method) {
    case 'GET':
        handleGetRequests($action, $return_id, $conn, $current_user_id);
        break;
    case 'POST':
        handlePostRequests($conn, $current_user_id, $current_user_role);
        break;
    case 'PUT':
        handlePutRequests($conn, $current_user_id, $current_user_role);
        break;
    default:
        echo json_encode([
            "success" => false,
            "message" => "طريقة الطلب غير مدعومة"
        ], JSON_UNESCAPED_UNICODE);
        break;
}

// ==================== وظائف معالجة GET ====================
function handleGetRequests($action, $return_id, $conn, $current_user_id) {
    switch ($action) {
        case 'fetch_return':
            fetchReturnJSON($return_id, $conn);
            break;
        case 'list_returns':
            listReturns($conn);
            break;
        case 'invoice_batches':
            getInvoiceBatches($conn);
            break;
        case 'statistics':
            getReturnsStatistics($conn);
            break;
        case 'available_invoices':
            getAvailableInvoices($conn);
            break;
        default:
            echo json_encode([
                "success" => false,
                "message" => "الإجراء غير معروف"
            ], JSON_UNESCAPED_UNICODE);
            break;
    }
}

// جلب بيانات مرتجع معين
function fetchReturnJSON($return_id, $conn) {
    if ($return_id <= 0) {
        echo json_encode([
            "success" => false,
            "message" => "معرف المرتجع غير صالح"
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    // جلب بيانات المرتجع
    $sql = "SELECT pr.*, 
                   s.name AS supplier_name,
                   pi.supplier_invoice_number,
                   uc.username AS creator_name,
                   ua.username AS approver_name
            FROM purchase_returns pr
            JOIN suppliers s ON s.id = pr.supplier_id
            LEFT JOIN purchase_invoices pi ON pi.id = pr.purchase_invoice_id
            LEFT JOIN users uc ON uc.id = pr.created_by
            LEFT JOIN users ua ON ua.id = pr.approved_by
            WHERE pr.id = ? LIMIT 1";
    
    if (!$st = $conn->prepare($sql)) {
        echo json_encode([
            "success" => false,
            "message" => "خطأ في تحضير الاستعلام: " . $conn->error
        ], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    $st->bind_param("i", $return_id);
    $st->execute();
    $return = $st->get_result()->fetch_assoc();
    $st->close();
    
    if (!$return) {
        echo json_encode([
            "success" => false,
            "message" => "المرتجع غير موجود"
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    // جلب بنود المرتجع
    $items = [];
    $sql_items = "SELECT pri.*, 
                         p.name AS product_name,
                         p.product_code,
                         b.expiry,
                         b.qty AS batch_qty,
                         b.remaining AS batch_remaining
                  FROM purchase_return_items pri
                  JOIN products p ON p.id = pri.product_id
                  JOIN batches b ON b.id = pri.batch_id
                  WHERE pri.purchase_return_id = ? 
                  ORDER BY pri.id ASC";
    
    if (!$sti = $conn->prepare($sql_items)) {
        echo json_encode([
            "success" => false,
            "message" => "خطأ في جلب البنود: " . $conn->error
        ], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    $sti->bind_param("i", $return_id);
    $sti->execute();
    $res = $sti->get_result();
    while ($r = $res->fetch_assoc()) {
        $items[] = $r;
    }
    $sti->close();

    // تسميات الحالات
    $status_labels = [
        'pending' => 'قيد الانتظار',
        'approved' => 'معتمد',
        'completed' => 'مكتمل',
        'cancelled' => 'ملغى'
    ];

    // تسميات أنواع المرتجعات
    $return_type_labels = [
        'supplier_return' => 'إرجاع للمورد',
        'damaged' => 'تلف في المخزن',
        'expired' => 'منتهي الصلاحية',
        'other' => 'أخرى'
    ];

    echo json_encode([
        "success" => true,
        "return" => $return,
        "items" => $items,
        "labels" => [
            "status" => $status_labels,
            "return_type" => $return_type_labels
        ]
    ], JSON_UNESCAPED_UNICODE);
}

// قائمة المرتجعات مع الفلترة
function listReturns($conn) {
    $selected_supplier_id = isset($_GET['supplier_filter_val']) ? intval($_GET['supplier_filter_val']) : '';
    $selected_status = isset($_GET['status_filter_val']) ? trim($_GET['status_filter_val']) : '';
    $selected_return_type = isset($_GET['type_filter_val']) ? trim($_GET['type_filter_val']) : '';
    $search_return_id = isset($_GET['return_out_id']) ? intval($_GET['return_out_id']) : 0;
    $start_date = isset($_GET['start_date']) ? trim($_GET['start_date']) : '';
    $end_date = isset($_GET['end_date']) ? trim($_GET['end_date']) : '';

    // بناء الاستعلام مع الفلترة
    $sql = "SELECT pr.id, pr.return_number, pr.supplier_id, pr.purchase_invoice_id, 
                   pr.return_date, pr.return_type, pr.return_reason, 
                   pr.total_amount, pr.status, pr.created_at,
                   s.name as supplier_name,
                   pi.supplier_invoice_number,
                   uc.username as creator_name,
                   ua.username as approver_name
            FROM purchase_returns pr
            JOIN suppliers s ON pr.supplier_id = s.id
            LEFT JOIN purchase_invoices pi ON pi.id = pr.purchase_invoice_id
            LEFT JOIN users uc ON uc.id = pr.created_by
            LEFT JOIN users ua ON ua.id = pr.approved_by
            WHERE 1=1";
    
    $params = [];
    $types = '';
    $conds = [];

    if (!empty($search_return_id)) {
        $conds[] = "pr.id = ?";
        $params[] = $search_return_id;
        $types .= 'i';
    }
    
    if (!empty($selected_supplier_id)) {
        $conds[] = "pr.supplier_id = ?";
        $params[] = $selected_supplier_id;
        $types .= 'i';
    }
    
    if (!empty($selected_status)) {
        $conds[] = "pr.status = ?";
        $params[] = $selected_status;
        $types .= 's';
    }
    
    if (!empty($selected_return_type)) {
        $conds[] = "pr.return_type = ?";
        $params[] = $selected_return_type;
        $types .= 's';
    }
    
    if (!empty($start_date)) {
        $conds[] = "pr.return_date >= ?";
        $params[] = $start_date;
        $types .= 's';
    }
    
    if (!empty($end_date)) {
        $conds[] = "pr.return_date <= ?";
        $params[] = $end_date;
        $types .= 's';
    }

    if (!empty($conds)) {
        $sql .= " AND " . implode(" AND ", $conds);
    }

    $sql .= " ORDER BY pr.return_date DESC, pr.id DESC";

    $returns = [];
    if ($stmt = $conn->prepare($sql)) {
        if (!empty($params)) {
            stmt_bind_params($stmt, $types, $params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($ret = $result->fetch_assoc()) {
            // جلب عدد المنتجات في المرتجع
            $count_sql = "SELECT COUNT(*) as item_count, SUM(quantity) as total_quantity 
                          FROM purchase_return_items 
                          WHERE purchase_return_id = ?";
            $count_stmt = $conn->prepare($count_sql);
            $count_stmt->bind_param("i", $ret['id']);
            $count_stmt->execute();
            $count_result = $count_stmt->get_result()->fetch_assoc();
            $count_stmt->close();
            
            $returns[] = [
                'id' => $ret['id'],
                'return_number' => $ret['return_number'],
                'purchase_invoice_id' => $ret['purchase_invoice_id'],
                'supplier_name' => $ret['supplier_name'],
                'supplier_invoice_number' => $ret['supplier_invoice_number'],
                'return_type' => $ret['return_type'],
                'return_date' => $ret['return_date'],
                'return_reason' => $ret['return_reason'],
                'total_amount' => (float)$ret['total_amount'],
                'status' => $ret['status'],
                'created_at' => $ret['created_at'],
                'creator_name' => $ret['creator_name'],
                'approver_name' => $ret['approver_name'],
                'item_count' => $count_result['item_count'] ?? 0,
                'total_quantity' => (float)($count_result['total_quantity'] ?? 0)
            ];
        }
        $stmt->close();
    }

    // حساب الإحصائيات المعروضة
    $displayed_sum = 0;
    $sql_total = "SELECT COALESCE(SUM(total_amount),0) AS total_displayed 
                  FROM purchase_returns pr 
                  WHERE 1=1";
    if (!empty($conds)) {
        $sql_total .= " AND " . implode(" AND ", $conds);
    }
    
    if ($stmt_total = $conn->prepare($sql_total)) {
        if (!empty($params)) {
            stmt_bind_params($stmt_total, $types, $params);
        }
        $stmt_total->execute();
        $res_t = $stmt_total->get_result();
        $rowt = $res_t->fetch_assoc();
        $displayed_sum = (float)($rowt['total_displayed'] ?? 0);
        $stmt_total->close();
    }

    echo json_encode([
        "success" => true,
        "returns" => $returns,
        "statistics" => [
            "total_returns" => count($returns),
            "displayed_sum" => $displayed_sum
        ],
        "filters" => [
            "supplier_id" => $selected_supplier_id,
            "status" => $selected_status,
            "return_type" => $selected_return_type,
            "search_return_id" => $search_return_id,
            "start_date" => $start_date,
            "end_date" => $end_date
        ]
    ], JSON_UNESCAPED_UNICODE);
}

// جلب الدفعات المتاحة للإرجاع من فاتورة معينة
function getInvoiceBatches($conn) {
    $invoice_id = isset($_GET['invoice_id']) ? intval($_GET['invoice_id']) : 0;
    
    if ($invoice_id <= 0) {
        echo json_encode([
            "success" => false,
            "message" => "معرف الفاتورة غير صالح"
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    // جلب بيانات الفاتورة والمورد
    $sql_invoice = "SELECT pi.*, s.name AS supplier_name 
                    FROM purchase_invoices pi
                    JOIN suppliers s ON s.id = pi.supplier_id
                    WHERE pi.id = ? AND pi.status = 'fully_received'";
    
    $stmt = $conn->prepare($sql_invoice);
    $stmt->bind_param("i", $invoice_id);
    $stmt->execute();
    $invoice = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$invoice) {
        echo json_encode([
            "success" => false,
            "message" => "الفاتورة غير موجودة أو لم يتم استلامها بالكامل"
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    // جلب الدفعات المتاحة للإرجاع
    $sql = "SELECT b.*, 
                   p.name AS product_name,
                   p.product_code,
                   pii.cost_price_per_unit,
                   pii.sale_price AS invoice_sale_price
            FROM batches b
            JOIN products p ON p.id = b.product_id
            JOIN purchase_invoice_items pii ON pii.batch_id = b.id
            WHERE b.source_invoice_id = ? 
              AND b.status = 'active'
              AND b.remaining > 0
            ORDER BY b.product_id, b.expiry ASC";
    
    $batches = [];
    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param("i", $invoice_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($batch = $result->fetch_assoc()) {
            // حساب الكمية التي تم إرجاعها بالفعل من هذه الدفعة
            $sql_returned = "SELECT COALESCE(SUM(quantity), 0) as returned_qty 
                             FROM purchase_return_items 
                             WHERE batch_id = ?";
            $stmt_returned = $conn->prepare($sql_returned);
            $stmt_returned->bind_param("i", $batch['id']);
            $stmt_returned->execute();
            $returned_result = $stmt_returned->get_result()->fetch_assoc();
            $stmt_returned->close();
            
            $returned_qty = (float)($returned_result['returned_qty'] ?? 0);
            $max_returnable = $batch['remaining'] - $returned_qty;
            
            if ($max_returnable > 0) {
                $batches[] = [
                    'id' => $batch['id'],
                    'product_id' => $batch['product_id'],
                    'product_name' => $batch['product_name'],
                    'product_code' => $batch['product_code'],
                    'batch_number' => 'B' . str_pad($batch['id'], 4, '0', STR_PAD_LEFT),
                    'qty' => (float)$batch['qty'],
                    'remaining' => (float)$batch['remaining'],
                    'original_qty' => (float)$batch['original_qty'],
                    'unit_cost' => (float)$batch['unit_cost'],
                    'sale_price' => isset($batch['sale_price']) ? (float)$batch['sale_price'] : null,
                    'invoice_sale_price' => isset($batch['invoice_sale_price']) ? (float)$batch['invoice_sale_price'] : null,
                    'expiry' => $batch['expiry'],
                    'max_returnable' => $max_returnable,
                    'already_returned' => $returned_qty
                ];
            }
        }
        $stmt->close();
    }

    echo json_encode([
        "success" => true,
        "invoice" => $invoice,
        "batches" => $batches
    ], JSON_UNESCAPED_UNICODE);
}

// جلب الفواتير المتاحة لعمل مرتجع
function getAvailableInvoices($conn) {
    $sql = "SELECT pi.id, pi.supplier_invoice_number, pi.purchase_date, 
                   pi.total_amount, s.name AS supplier_name
            FROM purchase_invoices pi
            JOIN suppliers s ON s.id = pi.supplier_id
            WHERE pi.status = 'fully_received'
              AND EXISTS (
                  SELECT 1 FROM batches b 
                  WHERE b.source_invoice_id = pi.id 
                    AND b.status = 'active' 
                    AND b.remaining > 0
              )
            ORDER BY pi.purchase_date DESC, pi.id DESC
            LIMIT 50";
    
    $invoices = [];
    $result = $conn->query($sql);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $invoices[] = $row;
        }
    }

    echo json_encode([
        "success" => true,
        "invoices" => $invoices
    ], JSON_UNESCAPED_UNICODE);
}

// جلب إحصائيات المرتجعات
function getReturnsStatistics($conn) {
    $stats = [];
    
    // إجمالي قيمة المرتجعات
    $sql_total = "SELECT 
                  COALESCE(SUM(total_amount),0) AS total_value,
                  COUNT(*) as total_count
                  FROM purchase_returns 
                  WHERE status != 'cancelled'";
    $rs = $conn->query($sql_total);
    if ($rs) {
        $r = $rs->fetch_assoc();
        $stats['total'] = [
            'value' => (float)$r['total_value'],
            'count' => (int)$r['total_count']
        ];
    }

    // المرتجعات حسب النوع
    $sql_by_type = "SELECT 
                    return_type,
                    COUNT(*) as count,
                    COALESCE(SUM(total_amount),0) as amount,
                    COALESCE(SUM(
                        (SELECT SUM(quantity) FROM purchase_return_items WHERE purchase_return_id = pr.id)
                    ), 0) as total_quantity
                    FROM purchase_returns pr
                    WHERE status != 'cancelled'
                    GROUP BY return_type";
    
    $rs = $conn->query($sql_by_type);
    if ($rs) {
        $stats['by_type'] = [];
        while ($r = $rs->fetch_assoc()) {
            $stats['by_type'][] = [
                'type' => $r['return_type'],
                'count' => (int)$r['count'],
                'amount' => (float)$r['amount'],
                'quantity' => (float)$r['total_quantity']
            ];
        }
    }

    // المرتجعات حسب الحالة
    $sql_by_status = "SELECT 
                      status,
                      COUNT(*) as count,
                      COALESCE(SUM(total_amount),0) as amount
                      FROM purchase_returns 
                      GROUP BY status";
    
    $rs = $conn->query($sql_by_status);
    if ($rs) {
        $stats['by_status'] = [];
        while ($r = $rs->fetch_assoc()) {
            $stats['by_status'][] = [
                'status' => $r['status'],
                'count' => (int)$r['count'],
                'amount' => (float)$r['amount']
            ];
        }
    }

    // المرتجعات حسب المورد (أعلى 10)
    $sql_by_supplier = "SELECT 
                        s.name AS supplier_name,
                        COUNT(pr.id) as count,
                        COALESCE(SUM(pr.total_amount),0) as amount
                        FROM purchase_returns pr
                        JOIN suppliers s ON s.id = pr.supplier_id
                        WHERE pr.status != 'cancelled'
                        GROUP BY pr.supplier_id, s.name
                        ORDER BY amount DESC
                        LIMIT 10";
    
    $rs = $conn->query($sql_by_supplier);
    if ($rs) {
        $stats['by_supplier'] = [];
        while ($r = $rs->fetch_assoc()) {
            $stats['by_supplier'][] = [
                'supplier' => $r['supplier_name'],
                'count' => (int)$r['count'],
                'amount' => (float)$r['amount']
            ];
        }
    }

    // إحصائيات الشهر الحالي
    $current_month = date('Y-m');
    $sql_current_month = "SELECT 
                          COUNT(*) as count,
                          COALESCE(SUM(total_amount),0) as amount
                          FROM purchase_returns 
                          WHERE DATE_FORMAT(return_date, '%Y-%m') = ?
                          AND status != 'cancelled'";
    
    $stmt = $conn->prepare($sql_current_month);
    $stmt->bind_param("s", $current_month);
    $stmt->execute();
    $month_result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $stats['current_month'] = [
        'count' => (int)($month_result['count'] ?? 0),
        'amount' => (float)($month_result['amount'] ?? 0)
    ];

    echo json_encode([
        "success" => true,
        "statistics" => $stats
    ], JSON_UNESCAPED_UNICODE);
}

// ==================== وظائف معالجة POST ====================
function handlePostRequests($conn, $current_user_id, $current_user_role) {
    // الحصول على البيانات المرسلة
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data) || !isset($data['action'])) {
        echo json_encode([
            "success" => false,
            "message" => "بيانات غير صالحة"
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    switch ($data['action']) {
        case 'create_return':
            createPurchaseReturn($conn, $data, $current_user_id, $current_user_role);
            break;
        default:
            echo json_encode([
                "success" => false,
                "message" => "الإجراء غير معروف"
            ], JSON_UNESCAPED_UNICODE);
            break;
    }
}

// إنشاء مرتجع جديد
function createPurchaseReturn($conn, $data, $current_user_id, $current_user_role) {
    $purchase_invoice_id = intval($data['purchase_invoice_id'] ?? 0);
    $return_date = trim($data['return_date'] ?? date('Y-m-d'));
    $return_type = trim($data['return_type'] ?? '');
    $return_reason = trim($data['return_reason'] ?? '');
    $items = $data['items'] ?? [];
    
    if ($purchase_invoice_id <= 0) {
        echo json_encode([
            "success" => false,
            "message" => "معرف الفاتورة غير صالح"
        ], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    if ($return_type === '') {
        echo json_encode([
            "success" => false,
            "message" => "نوع المرتجع مطلوب"
        ], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    if (!in_array($return_type, ['supplier_return', 'damaged', 'expired', 'other'])) {
        echo json_encode([
            "success" => false,
            "message" => "نوع مرتجع غير صالح"
        ], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    if (empty($items) || !is_array($items)) {
        echo json_encode([
            "success" => false,
            "message" => "يجب تحديد منتجات للإرجاع"
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    $conn->begin_transaction();
    try {
        // التحقق من الفاتورة وجلب بياناتها
        $sql_invoice = "SELECT pi.*, s.id as supplier_id, s.name as supplier_name 
                        FROM purchase_invoices pi
                        JOIN suppliers s ON s.id = pi.supplier_id
                        WHERE pi.id = ? AND pi.status = 'fully_received'
                        FOR UPDATE";
        
        $stmt = $conn->prepare($sql_invoice);
        $stmt->bind_param("i", $purchase_invoice_id);
        $stmt->execute();
        $invoice = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if (!$invoice) {
            throw new Exception("الفاتورة غير موجودة أو لم يتم استلامها بالكامل");
        }

        $supplier_id = (int)$invoice['supplier_id'];
        
        // إذا كان المستخدم ليس مدير، يضع المرتجع قيد الانتظار
        $status = ($current_user_role === 'admin') ? 'completed' : 'pending';
        
        // إنشاء رقم المرتجع
        $return_number = generate_return_number($conn);
        
        // إنشاء المرتجع
        $sql_insert_return = "INSERT INTO purchase_returns 
                              (return_number, supplier_id, purchase_invoice_id, return_date, 
                               return_type, return_reason, total_amount, status, created_by, 
                               created_at, updated_at)
                              VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, NOW(), NOW())";
        
        $stmt = $conn->prepare($sql_insert_return);
        $stmt->bind_param("siissssi", 
            $return_number, 
            $supplier_id, 
            $purchase_invoice_id, 
            $return_date,
            $return_type, 
            $return_reason, 
            $status,
            $current_user_id
        );
        
        if (!$stmt->execute()) {
            throw new Exception("فشل إنشاء المرتجع: " . $stmt->error);
        }
        
        $purchase_return_id = $stmt->insert_id;
        $stmt->close();

        $total_amount = 0;
        
        // معالجة البنود
        foreach ($items as $item) {
            $batch_id = intval($item['batch_id'] ?? 0);
            $quantity = (float)($item['quantity'] ?? 0);
            $item_reason = trim($item['reason'] ?? '');
            
            if ($batch_id <= 0 || $quantity <= 0) {
                continue;
            }
            
            // التحقق من الدفعة
            $sql_batch = "SELECT b.*, pii.id as invoice_item_id 
                          FROM batches b
                          JOIN purchase_invoice_items pii ON pii.batch_id = b.id
                          WHERE b.id = ? 
                            AND b.source_invoice_id = ?
                            AND b.status = 'active'
                          FOR UPDATE";
            
            $stmt_batch = $conn->prepare($sql_batch);
            $stmt_batch->bind_param("ii", $batch_id, $purchase_invoice_id);
            $stmt_batch->execute();
            $batch = $stmt_batch->get_result()->fetch_assoc();
            $stmt_batch->close();
            
            if (!$batch) {
                throw new Exception("الدفعة غير موجودة أو غير متاحة للإرجاع");
            }
            
            // التحقق من الكمية المتاحة
            $sql_available = "SELECT 
                              b.remaining - COALESCE(SUM(pri.quantity), 0) as available
                              FROM batches b
                              LEFT JOIN purchase_return_items pri ON pri.batch_id = b.id
                              WHERE b.id = ?";
            
            $stmt_avail = $conn->prepare($sql_available);
            $stmt_avail->bind_param("i", $batch_id);
            $stmt_avail->execute();
            $available_result = $stmt_avail->get_result()->fetch_assoc();
            $stmt_avail->close();
            
            $available = (float)($available_result['available'] ?? 0);
            
            if ($quantity > $available) {
                throw new Exception("الكمية المرتجعة ($quantity) أكبر من الكمية المتاحة ($available) للدفعة #$batch_id");
            }
            
            // تحديث الدفعة والمخزون
            $batch_update = update_batch_on_return($conn, $batch_id, $quantity, $current_user_id, $return_type, $return_reason);
            
            if (!$batch_update) {
                throw new Exception("فشل تحديث الدفعة #$batch_id");
            }
            
            // حساب القيمة
            $unit_cost = (float)$batch['unit_cost'];
            $item_total = $quantity * $unit_cost;
            $total_amount += $item_total;
            
            // إدخال بند المرتجع
            $sql_insert_item = "INSERT INTO purchase_return_items 
                                (purchase_return_id, batch_id, product_id, quantity, 
                                 unit_cost, total_cost, reason, batch_remaining_before, created_at)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())";
            
            $stmt_item = $conn->prepare($sql_insert_item);
            $product_id = (int)$batch['product_id'];
            $batch_remaining_before = $batch_update['remaining_before'];
            
            $stmt_item->bind_param("iiidddsd", 
                $purchase_return_id, 
                $batch_id, 
                $product_id,
                $quantity, 
                $unit_cost, 
                $item_total, 
                $item_reason,
                $batch_remaining_before
            );
            
            if (!$stmt_item->execute()) {
                throw new Exception("فشل إضافة بند المرتجع: " . $stmt_item->error);
            }
            $stmt_item->close();
            
            // تحديث الكمية المرتجعة في بند الفاتورة
            $invoice_item_id = (int)$batch['invoice_item_id'];
            if ($invoice_item_id > 0) {
                $sql_update_invoice_item = "UPDATE purchase_invoice_items 
                                            SET qty_returned = qty_returned + ? 
                                            WHERE id = ?";
                
                $stmt_update_invoice = $conn->prepare($sql_update_invoice_item);
                $stmt_update_invoice->bind_param("di", $quantity, $invoice_item_id);
                $stmt_update_invoice->execute();
                $stmt_update_invoice->close();
            }
        }
        
        // تحديث إجمالي المرتجع
        $sql_update_total = "UPDATE purchase_returns 
                             SET total_amount = ?, updated_at = NOW() 
                             WHERE id = ?";
        
        $stmt_total = $conn->prepare($sql_update_total);
        $stmt_total->bind_param("di", $total_amount, $purchase_return_id);
        $stmt_total->execute();
        $stmt_total->close();
        
        // إذا كان المستخدم مدير، نكمل العملية فورًا
        if ($current_user_role === 'admin') {
            $sql_complete = "UPDATE purchase_returns 
                             SET status = 'completed', 
                                 approved_by = ?,
                                 approved_at = NOW(),
                                 updated_at = NOW()
                             WHERE id = ?";
            
            $stmt_complete = $conn->prepare($sql_complete);
            $stmt_complete->bind_param("ii", $current_user_id, $purchase_return_id);
            $stmt_complete->execute();
            $stmt_complete->close();
        }
        
        $conn->commit();
        
        echo json_encode([
            "success" => true,
            "message" => "تم إنشاء المرتجع بنجاح",
            "return_id" => $purchase_return_id,
            "return_number" => $return_number,
            "status" => $status
        ], JSON_UNESCAPED_UNICODE);
        
    } catch (Exception $e) {
        $conn->rollback();
        error_log('Create purchase return error: ' . $e->getMessage());
        
        echo json_encode([
            "success" => false,
            "message" => "فشل إنشاء المرتجع: " . $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
}

// ==================== وظائف معالجة PUT ====================
function handlePutRequests($conn, $current_user_id, $current_user_role) {
    // الحصول على البيانات المرسلة
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data) || !isset($data['action'])) {
        echo json_encode([
            "success" => false,
            "message" => "بيانات غير صالحة"
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    switch ($data['action']) {
        case 'update_status':
            updateReturnStatus($conn, $data, $current_user_id, $current_user_role);
            break;
        case 'cancel_return':
            cancelPurchaseReturn($conn, $data, $current_user_id);
            break;
        default:
            echo json_encode([
                "success" => false,
                "message" => "الإجراء غير معروف"
            ], JSON_UNESCAPED_UNICODE);
            break;
    }
}

// تحديث حالة المرتجع
function updateReturnStatus($conn, $data, $current_user_id, $current_user_role) {
    $return_id = intval($data['return_id'] ?? 0);
    $new_status = trim($data['new_status'] ?? '');
    $reason = trim($data['reason'] ?? '');
    
    if ($return_id <= 0) {
        echo json_encode([
            "success" => false,
            "message" => "معرف المرتجع غير صالح"
        ], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    if ($new_status === '') {
        echo json_encode([
            "success" => false,
            "message" => "الحالة الجديدة مطلوبة"
        ], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    if (!in_array($new_status, ['approved', 'completed', 'cancelled'])) {
        echo json_encode([
            "success" => false,
            "message" => "حالة غير صالحة"
        ], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    // التحقق من الصلاحيات
    if ($current_user_role !== 'admin') {
        echo json_encode([
            "success" => false,
            "message" => "غير مصرح بتغيير حالة المرتجعات"
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    $conn->begin_transaction();
    try {
        // جلب المرتجع الحالي
        $sql_return = "SELECT * FROM purchase_returns WHERE id = ? FOR UPDATE";
        $stmt = $conn->prepare($sql_return);
        $stmt->bind_param("i", $return_id);
        $stmt->execute();
        $return = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if (!$return) {
            throw new Exception("المرتجع غير موجود");
        }
        
        $current_status = $return['status'];
        
        // التحقق من صحة التحويلات
        $valid_transitions = [
            'pending' => ['approved', 'cancelled'],
            'approved' => ['completed', 'cancelled'],
            'completed' => ['cancelled']
        ];
        
        if (!isset($valid_transitions[$current_status]) || 
            !in_array($new_status, $valid_transitions[$current_status])) {
            throw new Exception("تحويل غير صالح من $current_status إلى $new_status");
        }
        
        // إذا كان الإلغاء، يجب توفير سبب
        if ($new_status === 'cancelled' && $reason === '') {
            throw new Exception("سبب الإلغاء مطلوب");
        }
        
        // إذا كان الإلغاء، نسترد الكميات
        if ($new_status === 'cancelled') {
            reverseReturnQuantities($conn, $return_id, $current_user_id, $reason);
        }
        
        // تحديث حالة المرتجع
        $sql_update = "UPDATE purchase_returns 
                       SET status = ?, 
                           approved_by = ?,
                           approved_at = NOW(),
                           updated_at = NOW()";
        
        if ($new_status === 'cancelled') {
            $sql_update .= ", return_reason = CONCAT(return_reason, ' \\n[تم الإلغاء] ', ?)";
        }
        
        $sql_update .= " WHERE id = ?";
        
        $stmt_update = $conn->prepare($sql_update);
        
        if ($new_status === 'cancelled') {
            $stmt_update->bind_param("sisi", $new_status, $current_user_id, $reason, $return_id);
        } else {
            $stmt_update->bind_param("sii", $new_status, $current_user_id, $return_id);
        }
        
        $stmt_update->execute();
        $stmt_update->close();
        
        $conn->commit();
        
        echo json_encode([
            "success" => true,
            "message" => "تم تحديث حالة المرتجع بنجاح"
        ], JSON_UNESCAPED_UNICODE);
        
    } catch (Exception $e) {
        $conn->rollback();
        error_log('Update return status error: ' . $e->getMessage());
        
        echo json_encode([
            "success" => false,
            "message" => "فشل تحديث الحالة: " . $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
}

// إلغاء مرتجع
function cancelPurchaseReturn($conn, $data, $current_user_id) {
    $return_id = intval($data['return_id'] ?? 0);
    $reason = trim($data['reason'] ?? '');
    
    if ($return_id <= 0) {
        echo json_encode([
            "success" => false,
            "message" => "معرف المرتجع غير صالح"
        ], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    if ($reason === '') {
        echo json_encode([
            "success" => false,
            "message" => "سبب الإلغاء مطلوب"
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    $conn->begin_transaction();
    try {
        // التحقق من حالة المرتجع
        $sql = "SELECT status FROM purchase_returns WHERE id = ? FOR UPDATE";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $return_id);
        $stmt->execute();
        $return = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if (!$return) {
            throw new Exception("المرتجع غير موجود");
        }
        
        if ($return['status'] === 'cancelled') {
            throw new Exception("المرتجع ملغى بالفعل");
        }
        
        // استرجاع الكميات
        reverseReturnQuantities($conn, $return_id, $current_user_id, $reason);
        
        // تحديث حالة المرتجع
        $update_sql = "UPDATE purchase_returns 
                       SET status = 'cancelled', 
                           return_reason = CONCAT(return_reason, ' \\n[تم الإلغاء] ', ?),
                           updated_at = NOW()
                       WHERE id = ?";
        
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param("si", $reason, $return_id);
        $update_stmt->execute();
        $update_stmt->close();
        
        $conn->commit();
        
        echo json_encode([
            "success" => true,
            "message" => "تم إلغاء المرتجع واسترجاع الكميات بنجاح"
        ], JSON_UNESCAPED_UNICODE);
        
    } catch (Exception $e) {
        $conn->rollback();
        error_log('Cancel purchase return error: ' . $e->getMessage());
        
        echo json_encode([
            "success" => false,
            "message" => "فشل إلغاء المرتجع: " . $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
}

// استرجاع الكميات عند الإلغاء
function reverseReturnQuantities($conn, $return_id, $current_user_id, $reason) {
    // جلب بنود المرتجع
    $sql_items = "SELECT * FROM purchase_return_items WHERE purchase_return_id = ?";
    $stmt_items = $conn->prepare($sql_items);
    $stmt_items->bind_param("i", $return_id);
    $stmt_items->execute();
    $items_result = $stmt_items->get_result();
    $items = [];
    while ($item = $items_result->fetch_assoc()) {
        $items[] = $item;
    }
    $stmt_items->close();
    
    // استرجاع كل بند
    foreach ($items as $item) {
        $batch_id = (int)$item['batch_id'];
        $quantity = (float)$item['quantity'];
        $product_id = (int)$item['product_id'];
        
        // تحديث الدفعة
        $sql_batch = "SELECT remaining FROM batches WHERE id = ? FOR UPDATE";
        $stmt_batch = $conn->prepare($sql_batch);
        $stmt_batch->bind_param("i", $batch_id);
        $stmt_batch->execute();
        $batch_result = $stmt_batch->get_result()->fetch_assoc();
        $stmt_batch->close();
        
        if (!$batch_result) continue;
        
        $current_remaining = (float)$batch_result['remaining'];
        $new_remaining = $current_remaining + $quantity;
        
        // تحديث الدفعة
        $update_batch_sql = "UPDATE batches 
                             SET remaining = ?,
                                 status = CASE 
                                     WHEN ? > 0 THEN 'active'
                                     ELSE status
                                 END,
                                 updated_at = NOW()
                             WHERE id = ?";
        
        $update_batch_stmt = $conn->prepare($update_batch_sql);
        $update_batch_stmt->bind_param("ddi", $new_remaining, $new_remaining, $batch_id);
        $update_batch_stmt->execute();
        $update_batch_stmt->close();
        
        // تحديث المخزون
        $update_product_sql = "UPDATE products 
                               SET current_stock = current_stock + ? 
                               WHERE id = ?";
        
        $update_product_stmt = $conn->prepare($update_product_sql);
        $update_product_stmt->bind_param("di", $quantity, $product_id);
        $update_product_stmt->execute();
        $update_product_stmt->close();
        
        // تحديث الكمية المرتجعة في بند الفاتورة
        $sql_invoice_item = "SELECT pii.id 
                             FROM purchase_invoice_items pii
                             JOIN batches b ON b.id = ?
                             WHERE pii.batch_id = b.id 
                               AND pii.purchase_invoice_id = (
                                   SELECT purchase_invoice_id FROM purchase_returns WHERE id = ?
                               )";
        
        $stmt_invoice_item = $conn->prepare($sql_invoice_item);
        $stmt_invoice_item->bind_param("ii", $batch_id, $return_id);
        $stmt_invoice_item->execute();
        $invoice_item_result = $stmt_invoice_item->get_result()->fetch_assoc();
        $stmt_invoice_item->close();
        
        if ($invoice_item_result) {
            $invoice_item_id = (int)$invoice_item_result['id'];
            $update_invoice_item_sql = "UPDATE purchase_invoice_items 
                                        SET qty_returned = GREATEST(qty_returned - ?, 0)
                                        WHERE id = ?";
            
            $update_invoice_item_stmt = $conn->prepare($update_invoice_item_sql);
            $update_invoice_item_stmt->bind_param("di", $quantity, $invoice_item_id);
            $update_invoice_item_stmt->execute();
            $update_invoice_item_stmt->close();
        }
    }
}

$conn->close();
?>