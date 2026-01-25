<?php
header('Content-Type: application/json; charset=utf-8');
require_once dirname(__DIR__) . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$id = isset($data['id']) ? (int)$data['id'] : 0;

if (!$id) {
    echo json_encode(['success' => false, 'message' => 'معرف الشغلانة مطلوب']);
    exit;
}

try {
    // التحقق من وجود فواتير مرتبطة
    $checkSql = "SELECT COUNT(*) as cnt FROM invoices_out WHERE work_order_id = ?";
    $checkStmt = $conn->prepare($checkSql);
    $checkStmt->bind_param("i", $id);
    $checkStmt->execute();
    $res = $checkStmt->get_result()->fetch_assoc();
    
    if ($res['cnt'] > 0) {
        echo json_encode(['success' => false, 'message' => 'لا يمكن حذف شغلانة مرتبطة بفواتير']);
        exit;
    }

    $sql = "DELETE FROM work_orders WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $id);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'تم حذف الشغلانة بنجاح']);
    } else {
        throw new Exception($conn->error);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'خطأ: ' . $e->getMessage()]);
}
