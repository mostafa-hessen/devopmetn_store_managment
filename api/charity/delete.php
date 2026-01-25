<?php
header('Content-Type: application/json; charset=utf-8');
require_once dirname(dirname(__DIR__)) . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed']);
    exit;
}

try {
    $data = json_decode(file_get_contents('php://input'), true);

    if (!isset($data['id'])) {
        throw new Exception('مطلوب معرف العملية');
    }

    $id = (int)$data['id'];

    $sql = "DELETE FROM charity_records WHERE id = ?";
    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        throw new Exception("خطأ في التحضير: " . $conn->error);
    }

    $stmt->bind_param("i", $id);

    if ($stmt->execute()) {
        echo json_encode(['status' => 'success', 'message' => 'تم حذف السجل بنجاح']);
    } else {
        throw new Exception("خطأ في التنفيذ: " . $stmt->error);
    }

    $stmt->close();

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

$conn->close();
