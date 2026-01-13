<?php
require_once '../config.php';
require_once '../partials/session_admin.php';

$invoiceId = intval($_GET['id']);

$sql = "SELECT io.*, c.name as customer_name, c.mobile as customer_phone,
               wo.title as work_order_title, wo.description as work_order_description,
               COALESCE(ioi_sum.total_returns, 0) as total_returns_amount,
               CASE 
                   WHEN io.remaining_amount = 0 THEN 'paid'
                   WHEN io.paid_amount > 0 AND io.remaining_amount > 0 THEN 'partial'
                   ELSE 'pending'
               END AS payment_status
        FROM invoices_out io
        LEFT JOIN customers c ON io.customer_id = c.id
        LEFT JOIN work_orders wo ON io.work_order_id = wo.id
        LEFT JOIN (
            SELECT invoice_out_id, SUM(returned_quantity * unit_price_after_discount) as total_returns
            FROM invoice_out_items
            GROUP BY invoice_out_id
        ) ioi_sum ON io.id = ioi_sum.invoice_out_id
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