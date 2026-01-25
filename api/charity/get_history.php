<?php
header('Content-Type: application/json; charset=utf-8');
require_once dirname(dirname(__DIR__)) . '/config.php';

try {
    $sql = "SELECT c.*, u.username as created_by_name 
            FROM charity_records c 
            LEFT JOIN users u ON c.created_by = u.id 
            ORDER BY c.created_at DESC LIMIT 100";
    $result = $conn->query($sql);

    if (!$result) {
        throw new Exception("خطأ في الاستعلام: " . $conn->error);
    }

    $records = [];
    while ($row = $result->fetch_assoc()) {
        $records[] = [
            'id' => (int)$row['id'],
            'amount' => (float)$row['amount'],
            'percentage' => (float)$row['percentage'],
            'charityValue' => (float)$row['charity_value'], // matching frontend expectation slightly
            'note' => $row['notes'],
            'created_by_name' => $row['created_by_name'] ?? 'غير معروف',
            'date' => date('d/m/Y h:i A', strtotime($row['created_at']))
        ];
    }

    echo json_encode(['status' => 'success', 'data' => $records]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

$conn->close();
