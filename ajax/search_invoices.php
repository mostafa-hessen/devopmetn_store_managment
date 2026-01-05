<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/partials/session_admin.php';

header('Content-Type: application/json');

$query = isset($_GET['q']) ? trim($_GET['q']) : '';

if (strlen($query) < 2) {
    echo json_encode([]);
    exit;
}

$sql = "SELECT 
            io.id,
            io.created_at,
            c.name as customer_name,
            io.total_after_discount
        FROM invoices_out io
        LEFT JOIN customers c ON io.customer_id = c.id
        WHERE io.id LIKE ? 
           OR c.name LIKE ?
        AND io.delivered NOT IN ('canceled', 'reverted')
        ORDER BY io.created_at DESC
        LIMIT 10";

$search_term = "%{$query}%";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ss", $search_term, $search_term);
$stmt->execute();
$result = $stmt->get_result();

$invoices = [];
while ($row = $result->fetch_assoc()) {
    $invoices[] = $row;
}

echo json_encode($invoices);
$stmt->close();
?>