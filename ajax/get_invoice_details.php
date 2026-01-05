<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/partials/session_admin.php';

header('Content-Type: application/json');

if (!isset($_GET['invoice_id']) || !is_numeric($_GET['invoice_id'])) {
    echo json_encode(['error' => 'معرف الفاتورة غير صالح']);
    exit;
}

$invoice_id = intval($_GET['invoice_id']);

// جلب بيانات الفاتورة الرئيسية
$invoice_sql = "SELECT 
                    io.*,
                    c.name as customer_name,
                    c.mobile as customer_phone,
                    w.title as work_order_title,
                    w.status as work_order_status,
                    CASE 
                        WHEN io.remaining_amount = 0 THEN 'paid'
                        WHEN io.paid_amount > 0 AND io.remaining_amount > 0 THEN 'partial'
                        ELSE 'pending'
                    END AS payment_status
                FROM invoices_out io
                LEFT JOIN customers c ON io.customer_id = c.id
                LEFT JOIN work_orders w ON io.work_order_id = w.id
                WHERE io.id = ? 
                AND io.delivered NOT IN ('canceled', 'reverted')";

$invoice_stmt = $conn->prepare($invoice_sql);
$invoice_stmt->bind_param("i", $invoice_id);
$invoice_stmt->execute();
$invoice_result = $invoice_stmt->get_result();

if ($invoice_result->num_rows === 0) {
    echo json_encode(['error' => 'الفاتورة غير موجودة']);
    exit;
}

$invoice = $invoice_result->fetch_assoc();
$invoice_stmt->close();

// جلب بنود الفاتورة
$items_sql = "SELECT 
                ioi.*,
                p.name as product_name,
                p.sku as product_sku,
                (ioi.quantity - ioi.returned_quantity) as remaining_quantity,
                (ioi.unit_price_after_discount * (ioi.quantity - ioi.returned_quantity)) as item_total_after_return
              FROM invoice_out_items ioi
              LEFT JOIN products p ON ioi.product_id = p.id
              WHERE ioi.invoice_out_id = ? 
              AND ioi.returned_quantity < ioi.quantity
              ORDER BY ioi.id";

$items_stmt = $conn->prepare($items_sql);
$items_stmt->bind_param("i", $invoice_id);
$items_stmt->execute();
$items_result = $items_stmt->get_result();

$items = [];
while ($item = $items_result->fetch_assoc()) {
    $items[] = $item;
}
$items_stmt->close();

echo json_encode([
    'invoice' => $invoice,
    'items' => $items
]);
?>