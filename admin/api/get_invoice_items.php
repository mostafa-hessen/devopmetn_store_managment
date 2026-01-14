<?php
require_once dirname(__DIR__,2) . '/config.php';

$invoiceId = intval($_GET['id']);

$sql = "SELECT ioi.*, p.name as product_name, p.product_code as product_code,
       (ioi.quantity - ioi.returned_quantity) as available_for_return,
               (ioi.total_after_discount ) as item_net_total,
               wo.title as work_order_title
        FROM invoice_out_items ioi
        JOIN products p ON ioi.product_id = p.id
        LEFT JOIN invoices_out io ON ioi.invoice_out_id = io.id
        LEFT JOIN work_orders wo ON io.work_order_id = wo.id
        WHERE ioi.invoice_out_id = ? 
        ORDER BY ioi.id";


$stmt = $conn->prepare($sql);
if (!$stmt) {
    die("SQL Error: " . $conn->error);
}

$stmt->bind_param("i", $invoiceId);
$stmt->execute();
$result = $stmt->get_result();

$items = [];
while ($row = $result->fetch_assoc()) {
    $items[] = $row;
}

header('Content-Type: application/json');
echo json_encode($items);
?>