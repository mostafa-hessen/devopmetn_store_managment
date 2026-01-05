<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/partials/session_admin.php';

header('Content-Type: application/json');

if (!isset($_GET['invoice_id']) || !is_numeric($_GET['invoice_id'])) {
    echo json_encode([]);
    exit;
}

$invoice_id = intval($_GET['invoice_id']);

$sql = "SELECT 
            r.id, 
            r.return_date, 
            r.total_amount,
            r.return_type,
            r.status,
            r.reason,
            r.notes,
            COUNT(ri.id) as items_count
        FROM returns r
        LEFT JOIN return_items ri ON r.id = ri.return_id
        WHERE r.invoice_id = ?
        GROUP BY r.id
        ORDER BY r.return_date DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $invoice_id);
$stmt->execute();
$result = $stmt->get_result();

$returns = [];
while ($row = $result->fetch_assoc()) {
    $returns[] = $row;
}

echo json_encode($returns);
$stmt->close();
?>