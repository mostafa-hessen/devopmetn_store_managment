<?php
header('Content-Type: application/json; charset=utf-8');
require_once dirname(__DIR__) . '/config.php';

// التحقق من الجلسة والصلاحيات
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'ليس لديك صلاحية للوصول إلى هذه الصفحة'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// التحقق من معرف الفاتورة أو العميل
$invoice_id = isset($_GET['invoice_id']) ? intval($_GET['invoice_id']) : 0;
$customer_id = isset($_GET['customer_id']) ? intval($_GET['customer_id']) : 0;

if ($invoice_id <= 0 && $customer_id <= 0) {
    echo json_encode([
        'success' => false,
        'message' => 'يجب تحديد معرف الفاتورة أو العميل'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // التحقق من وجود جدول invoice_adjustments
    $checkTable = $conn->query("SHOW TABLES LIKE 'invoice_adjustments'");
    if (!$checkTable || $checkTable->num_rows == 0) {
        echo json_encode([
            'success' => true,
            'adjustments' => []
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // جلب التعديلات
    if ($invoice_id > 0) {
        // جلب تعديلات فاتورة محددة
        $stmt = $conn->prepare("
            SELECT 
                ia.*,
                u.username AS created_by_name,
                i.id AS invoice_id,
                i.customer_id,
                c.name AS customer_name
            FROM invoice_adjustments ia
            JOIN invoices_out i ON ia.invoice_id = i.id
            LEFT JOIN customers c ON i.customer_id = c.id
            LEFT JOIN users u ON ia.created_by = u.id
            WHERE ia.invoice_id = ?
            ORDER BY ia.created_at DESC
        ");
        $stmt->bind_param("i", $invoice_id);
    } else {
        // جلب تعديلات جميع فواتير العميل
     $stmt = $conn->prepare("
    SELECT 
        ia.*,
        u.username AS created_by_name,
        i.id AS invoice_id,
        i.customer_id,
        c.name AS customer_name
    FROM invoice_adjustments ia
    JOIN invoices_out i ON ia.invoice_id = i.id
    LEFT JOIN customers c ON i.customer_id = c.id
    LEFT JOIN users u ON ia.created_by = u.id
    WHERE i.customer_id = ?
    ORDER BY ia.created_at DESC
");

if (!$stmt) {
    die("خطأ في SQL: " . $conn->error);
}

$stmt->bind_param("i", $customer_id);
$stmt->execute();
$result = $stmt->get_result();

    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    $adjustments = [];
    while ($row = $result->fetch_assoc()) {
        $adjustments[] = [
            'id' => intval($row['id']),
            'invoice_id' => intval($row['invoice_id']),
            'customer_id' => intval($row['customer_id']),
            'customer_name' => $row['customer_name'],
            'adjustment_type' => $row['adjustment_type'],
            'discount_type' => $row['discount_type'],
            'discount_value' => floatval($row['discount_value'] ?? 0),
            'discount_amount' => floatval($row['discount_amount'] ?? 0),
            'old_total_after_discount' => floatval($row['old_total_after_discount'] ?? 0),
            'new_total_after_discount' => floatval($row['new_total_after_discount'] ?? 0),
            'old_remaining_amount' => floatval($row['old_remaining_amount'] ?? 0),
            'new_remaining_amount' => floatval($row['new_remaining_amount'] ?? 0),
            'old_profit_amount' => floatval($row['old_profit_amount'] ?? 0),
            'new_profit_amount' => floatval($row['new_profit_amount'] ?? 0),
            'refund_method' => $row['refund_method'],
            'refund_amount' => floatval($row['refund_amount'] ?? 0),
            'reason' => $row['reason'],
            'items_data' => !empty($row['items_data']) ? (is_string($row['items_data']) ? json_decode($row['items_data'], true) : $row['items_data']) : [],
            'customer_balance_before' => floatval($row['customer_balance_before'] ?? 0),
            'customer_balance_after' => floatval($row['customer_balance_after'] ?? 0),
            'customer_wallet_before' => floatval($row['customer_wallet_before'] ?? 0),
            'customer_wallet_after' => floatval($row['customer_wallet_after'] ?? 0),
            'work_order_id' => $row['work_order_id'] ? intval($row['work_order_id']) : null,
            'created_by' => intval($row['created_by']),
            'created_by_name' => $row['created_by_name'],
            'created_at' => $row['created_at']
        ];
    }
    $stmt->close();
    
    echo json_encode([
        'success' => true,
        'adjustments' => $adjustments,
        'count' => count($adjustments)
    ], JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'خطأ في جلب البيانات: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>

