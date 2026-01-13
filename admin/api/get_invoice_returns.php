<?php
require_once dirname(__DIR__,2) . '/config.php';

$invoiceId = intval($_GET['id']);
if(!isset($_GET['id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invoice ID required']);
    exit;
}

$sql = "SELECT r.*, u.username as approved_by_name
        FROM returns r
        LEFT JOIN users u ON r.approved_by = u.id
        WHERE r.invoice_id = ? 
        ORDER BY r.return_date DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $invoiceId);
$stmt->execute();
$result = $stmt->get_result();

$returns = [];
while ($row = $result->fetch_assoc()) {
    $returns[] = $row;
}

header('Content-Type: application/json');
echo json_encode($returns);
?>