<?php
// get_work_orders.php
header('Content-Type: application/json; charset=utf-8');
require_once dirname(__DIR__) . '/config.php';

try {
    $params = [];
    $paramTypes = "";
    $conditions = [];
    
    // فلتر حسب العميل
    if (isset($_GET['customer_id']) && is_numeric($_GET['customer_id'])) {
        $conditions[] = "wo.customer_id = ?";
        $params[] = (int)$_GET['customer_id'];
        $paramTypes .= "i";
    }

    // فلتر حسب الأرشفة
    $showArchived = isset($_GET['show_archived']) && $_GET['show_archived'] === '1' ? 1 : 0;
    $conditions[] = "wo.is_archived = ?";
    $params[] = $showArchived;
    $paramTypes .= "i";
    
    // الاستعلام الأساسي
    $sql = "
        SELECT 
            wo.id, wo.customer_id, wo.title, wo.description, wo.status, wo.start_date, wo.notes, wo.created_at, wo.updated_at, wo.is_archived,
            c.name as customer_name,
            c.mobile as customer_mobile,
            u.username as created_by_name,
            (SELECT COUNT(*) FROM invoices_out WHERE work_order_id = wo.id) as invoices_count,
            (SELECT SUM(total_after_discount) FROM invoices_out WHERE work_order_id = wo.id AND delivered NOT IN ('canceled', 'reverted')) as calc_total_amount,
            (SELECT SUM(paid_amount) FROM invoices_out WHERE work_order_id = wo.id AND delivered NOT IN ('canceled', 'reverted')) as calc_total_paid,
            (SELECT SUM(remaining_amount) FROM invoices_out WHERE work_order_id = wo.id AND delivered NOT IN ('canceled', 'reverted')) as calc_total_remaining
        FROM work_orders wo
        LEFT JOIN customers c ON wo.customer_id = c.id
        LEFT JOIN users u ON wo.created_by = u.id
    ";
    
    if (!empty($conditions)) {
        $sql .= " WHERE " . implode(" AND ", $conditions);
    }
    
    $sql .= " ORDER BY wo.id DESC";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("خطأ في إعداد الاستعلام: " . $conn->error);
    }
    
    if (!empty($params)) {
        $stmt->bind_param($paramTypes, ...$params);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    $workOrders = [];
    
    while ($row = $result->fetch_assoc()) {
        // جلب الفواتير المرتبطة
        $invoices = [];
        $invStmt = $conn->prepare("
            SELECT 
                id, total_after_discount AS total, paid_amount AS paid, remaining_amount AS remaining,
                created_at, delivered, total_before_discount, discount_type, discount_value, discount_amount,
                CASE 
                    WHEN delivered = 'reverted' THEN 'returned'
                    WHEN remaining_amount = 0 THEN 'paid'
                    WHEN paid_amount > 0 AND remaining_amount > 0 THEN 'partial'
                    ELSE 'pending'
                END AS status
            FROM invoices_out
            WHERE work_order_id = ?
            ORDER BY id DESC
        ");

        $invId = (int)$row['id'];
        $invStmt->bind_param("i", $invId);
        $invStmt->execute();
        $invResult = $invStmt->get_result();

        while ($inv = $invResult->fetch_assoc()) {
            $invoices[] = [
                'id' => (int)$inv['id'],
                'total' => (float)$inv['total'],
                'paid' => (float)$inv['paid'],
                'remaining' => (float)$inv['remaining'],
                'status'    => $inv['status'],
                'created_at' => $inv['created_at'],
                'total_before_discount' => (float)$inv['total_before_discount'],
                'discount_type' => $inv['discount_type'],
                'discount_value' => (float)$inv['discount_value'],
                'discount_amount' => (float)$inv['discount_amount']
            ];
        }
        $invStmt->close();

        $totalAmt = (float)($row['calc_total_amount'] ?? 0);
        $totalPaid = (float)($row['calc_total_paid'] ?? 0);
        $totalRem = (float)($row['calc_total_remaining'] ?? 0);

        $workOrders[] = [
            'id' => (int)$row['id'],
            'customer_id' => (int)$row['customer_id'],
            'customer_name' => $row['customer_name'],
            'title' => $row['title'],
            'description' => $row['description'],
            'status' => $row['status'],
            'status_text' => $row['status'] == 'pending' ? 'قيد التنفيذ' : 
                           ($row['status'] == 'in_progress' ? 'جاري العمل' : 
                           ($row['status'] == 'completed' ? 'مكتمل' : 'ملغي')),
            'start_date' => $row['start_date'],
            'total_invoice_amount' => $totalAmt,
            'total_paid' => $totalPaid,
            'total_remaining' => $totalRem,
            'is_archived' => (int)$row['is_archived'],
            'progress_percent' => $totalAmt > 0 ? round(($totalPaid / $totalAmt) * 100, 2) : 0,
            'invoices_count' => (int)$row['invoices_count'],
            'invoices' => $invoices,
            'created_at' => $row['created_at']
        ];
    }
    $stmt->close();

    echo json_encode([
        'success' => true,
        'work_orders' => $workOrders,
        'count' => count($workOrders)
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'خطأ في الخادم: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
// Clean connection close
$conn->close();