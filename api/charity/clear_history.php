<?php
header('Content-Type: application/json; charset=utf-8');
require_once dirname(dirname(__DIR__)) . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed']);
    exit;
}

try {
    // Optional: Add safety check or password here if needed
    
    $sql = "TRUNCATE TABLE charity_records";
    if ($conn->query($sql)) {
        echo json_encode(['status' => 'success', 'message' => 'تم مسح جميع السجلات بنجاح']);
    } else {
        throw new Exception("خطأ في التنفيذ: " . $conn->error);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

$conn->close();
