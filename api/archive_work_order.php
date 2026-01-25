<?php
header('Content-Type: application/json; charset=utf-8');
require_once dirname(__DIR__) . '/config.php';
require_once BASE_DIR . 'partials/session_admin.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$id = isset($data['id']) ? (int)$data['id'] : 0;
$archive = isset($data['archive']) ? (int)$data['archive'] : 1; // 1 for archive, 0 for unarchive

if (!$id) {
    echo json_encode(['success' => false, 'message' => 'معرف الشغلانة مطلوب']);
    exit;
}

try {
    // التحقق من الرصيد قبل الأرشفة
    if ($archive === 1) {
        $checkSql = "SELECT SUM(remaining_amount) as total_rem 
                     FROM invoices_out 
                     WHERE work_order_id = ? AND delivered NOT IN ('canceled', 'reverted')";
        $checkStmt = $conn->prepare($checkSql);
        $checkStmt->bind_param("i", $id);
        $checkStmt->execute();
        $res = $checkStmt->get_result()->fetch_assoc();
        
        if (($res['total_rem'] ?? 0) > 0) {
            echo json_encode(['success' => false, 'message' => 'لا يمكن أرشفة شغلانة متبقي عليها مبالغ مسحوبة']);
            exit;
        }
    }

    $sql = "UPDATE work_orders SET is_archived = ? WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $archive, $id);
    
    if ($stmt->execute()) {
        echo json_encode([
            'success' => true, 
            'message' => $archive ? 'تمت أرشفة الشغلانة بنجاح' : 'تم إلغاء أرشيف الشغلانة بنجاح'
        ]);
    } else {
        throw new Exception($conn->error);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'خطأ: ' . $e->getMessage()]);
}
