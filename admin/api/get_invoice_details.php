<?php
require_once dirname(__DIR__,2) . '/config.php';

$invoiceId = intval($_GET['id']);

$sql = "SELECT io.*, c.name as customer_name, c.mobile as customer_phone,
               wo.title as work_order_title, wo.description as work_order_description,
               SUM(r.total_amount) as total_returns_amount,
               CASE 
                   WHEN io.remaining_amount = 0 THEN 'paid'
                   WHEN io.paid_amount > 0 AND io.remaining_amount > 0 THEN 'partial'
                   ELSE 'pending'
               END AS payment_status
        FROM invoices_out io
        LEFT JOIN customers c ON io.customer_id = c.id
        LEFT JOIN work_orders wo ON io.work_order_id = wo.id
        LEFT JOIN returns r ON r.invoice_id = io.id AND r.status IN ('approved', 'completed')
        WHERE io.id = ?
        GROUP BY io.id";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $invoiceId);
$stmt->execute();
$result = $stmt->get_result();

header('Content-Type: application/json');
if ($row = $result->fetch_assoc()) {
    // حساب الصافي
    $row['net_amount'] = floatval($row['total_after_discount']) - floatval($row['total_returns_amount']);
    echo json_encode($row);
} else {
    echo json_encode(['error' => 'Invoice not found']);
}
?>