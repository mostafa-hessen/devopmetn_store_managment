<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once "../../config.php";  // ← مسار ملف config بتاعك

// التحقق من المدخل
$customer_id = isset($_GET['customer_id']) ? intval($_GET['customer_id']) : 0;

if ($customer_id <= 0) {
    echo json_encode([
        "success" => false,
        "message" => "معرف العميل غير صالح"
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// التحقق من أن العميل موجود
$check = $conn->prepare("SELECT id FROM customers WHERE id = ? LIMIT 1");
$check->bind_param("i", $customer_id);
$check->execute();
$result = $check->get_result();

if ($result->num_rows == 0) {
    echo json_encode([
        "success" => false,
        "message" => "العميل غير موجود"
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
$check->close();

// جلب الفواتير
$query = "
    SELECT 
        i.id,
        i.id AS invoice_number,
        DATE(i.created_at) AS date,
        TIME(i.created_at) AS time,
        i.total_after_discount AS total,
        i.paid_amount AS paid,
        i.remaining_amount AS remaining,

        CASE 
            WHEN i.delivered = 'reverted' THEN 'returned'
            WHEN i.remaining_amount = 0 THEN 'paid'
            WHEN i.paid_amount > 0 AND i.remaining_amount > 0 THEN 'partial'
            ELSE 'pending'
        END AS status,

        -- عدد البنود
        (SELECT COUNT(*) FROM invoice_out_items WHERE invoice_out_id = i.id) AS items_count,

        -- هل تحتوي الفاتورة على مرتجعات
        EXISTS(
            SELECT 1 FROM invoice_out_items 
            WHERE invoice_out_id = i.id AND returned_quantity > 0
        ) AS has_returns,

        i.notes AS description

    FROM invoices_out i
    WHERE i.customer_id = ?
    ORDER BY i.created_at DESC
";

$stmt = $conn->prepare($query);
$stmt->bind_param("i", $customer_id);
$stmt->execute();
$res = $stmt->get_result();

$invoices = [];
while ($row = $res->fetch_assoc()) {
    $row['items_count'] = intval($row['items_count']);
    $row['has_returns'] = boolval($row['has_returns']);
    $row['total'] = floatval($row['total']);
    $row['paid'] = floatval($row['paid']);
    $row['remaining'] = floatval($row['remaining']);

   // جلب البنود لكل فاتورة مع اسم المنتج
$itemStmt = $conn->prepare("
    SELECT 
        i.id,
        i.product_id,
        p.name AS product_name,   -- ← اسم المنتج
        i.quantity,
        i.selling_price,
        i.total_price,
        i.returned_quantity,
        i.return_flag,
        i.available_for_return,
        i.price_type,
        i.cost_price_per_unit
    FROM invoice_out_items i
    JOIN products p ON p.id = i.product_id
    WHERE i.invoice_out_id = ?
");
$itemStmt->bind_param("i", $row['id']);
$itemStmt->execute();
$itemsRes = $itemStmt->get_result();

$items = [];
while ($item = $itemsRes->fetch_assoc()) {
    $item['quantity'] = floatval($item['quantity']);
    $item['selling_price'] = floatval($item['selling_price']);
    $item['total_price'] = floatval($item['total_price']);
    $item['returned_quantity'] = floatval($item['returned_quantity']);
    $item['cost_price_per_unit'] = floatval($item['cost_price_per_unit']);
    $item['return_flag'] = boolval($item['return_flag']);
    $item['available_for_return'] = floatval($item['available_for_return']);
    $items[] = $item;
}

$itemStmt->close();
$row['items'] = $items;

    $invoices[] = $row;
}
$stmt->close();


// حساب الإحصائيات مع إضافة TOTAL AMOUNT / total_paid / total_remaining
$summary = [
    'total_invoices' => 0,
    'total_amount' => 0.0,        // ← مجموع إجماليات الفواتير
    'total_paid' => 0.0,          // ← مجموع المدفوعات
    'total_remaining' => 0.0,     // ← مجموع المتبقي
    'pending_count' => 0,
    'pending_amount' => 0.0,
    'partial_count' => 0,
    'partial_amount' => 0.0,
    'paid_count' => 0,
    'paid_amount' => 0.0,
    'returned_count' => 0,
    'returned_amount' => 0.0
];

foreach ($invoices as $inv) {
    $summary['total_invoices']++;

    // إجماليات عامة
    $summary['total_amount'] += $inv['total'];
    $summary['total_paid'] += $inv['paid'];
    $summary['total_remaining'] += $inv['remaining'];

    switch ($inv['status']) {
        case 'pending':
            $summary['pending_count']++;
            $summary['pending_amount'] += $inv['remaining'];
            break;

        case 'partial':
            $summary['partial_count']++;
            $summary['partial_amount'] += $inv['remaining'];
            break;

        case 'paid':
            $summary['paid_count']++;
            $summary['paid_amount'] += $inv['total'];
            break;

        case 'returned':
            $summary['returned_count']++;
            $summary['returned_amount'] += $inv['total'];
            break;
    }
}

echo json_encode([
    "success" => true,
    "invoices" => $invoices,
    "summary" => $summary,
    "count" => count($invoices)
], JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);
