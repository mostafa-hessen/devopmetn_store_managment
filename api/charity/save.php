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

    if (!isset($data['amount']) || !isset($data['charity_value'])) {
        throw new Exception('بيانات ناقصة');
    }

    $amount = (float)$data['amount'];
    $percentage = isset($data['percentage']) ? (float)$data['percentage'] : 0;
    $charity_value = (float)$data['charity_value'];
    $notes = isset($data['notes']) ? trim($data['notes']) : '';
    $created_by = isset($_SESSION['id']) ? $_SESSION['id'] : null; // Assuming id is in session

    $sql = "INSERT INTO charity_records (amount, percentage, charity_value, notes, created_by) VALUES (?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        throw new Exception("خطأ في التحضير: " . $conn->error);
    }

    $stmt->bind_param("dddsi", $amount, $percentage, $charity_value, $notes, $created_by);

    if ($stmt->execute()) {
        echo json_encode(['status' => 'success', 'message' => 'تم حفظ العملية بنجاح', 'id' => $stmt->insert_id]);
    } else {
        throw new Exception("خطأ في التنفيذ: " . $stmt->error);
    }

    $stmt->close();

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

$conn->close();
