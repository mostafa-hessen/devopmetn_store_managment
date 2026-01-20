<?php
header('Content-Type: application/json; charset=utf-8');
require_once dirname(__DIR__) . '/config.php';

// التحقق من الجلسة والصلاحيات
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'ليس لديك صلاحية للوصول إلى هذه الصفحة'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// التحقق من معرف الفاتورة
$invoice_id = isset($_GET['invoice_id']) ? intval($_GET['invoice_id']) : 0;

if ($invoice_id <= 0) {
    echo json_encode([
        'success' => false,
        'message' => 'معرف الفاتورة غير صالح'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // جلب بيانات الفاتورة
    $stmt = $conn->prepare("
        SELECT 
            i.id,
            i.customer_id,
            i.total_before_discount,
            i.discount_amount,
            i.total_after_discount,
            i.paid_amount,
            i.remaining_amount,
            i.total_cost,
            i.profit_amount,
            i.work_order_id,
            i.delivered,
            i.created_at,
            c.name AS customer_name,
            c.balance AS customer_balance,
            c.wallet AS customer_wallet,
            w.title AS work_order_title,
            CASE 
                WHEN i.delivered = 'reverted' THEN 'returned'
                WHEN i.remaining_amount = 0 THEN 'paid'
                WHEN i.paid_amount > 0 AND i.remaining_amount > 0 THEN 'partial'
                ELSE 'pending'
            END AS status
        FROM invoices_out i
        LEFT JOIN customers c ON i.customer_id = c.id
        LEFT JOIN work_orders w ON i.work_order_id = w.id
        WHERE i.id = ? 
        AND i.delivered != 'canceled'
        AND i.delivered != 'reverted'
    ");
    
    $stmt->bind_param("i", $invoice_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $invoice = $result->fetch_assoc();
    $stmt->close();
    
    if (!$invoice) {
        echo json_encode([
            'success' => false,
            'message' => 'الفاتورة غير موجودة أو غير قابلة للتعديل'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // جلب جميع البنود (بما فيها المرتجعة لحساب المرتجعات)
    $stmt = $conn->prepare("
        SELECT 
            ioi.id,
            ioi.product_id,
            p.name AS product_name,
            ioi.quantity,
            ioi.returned_quantity,
            ioi.available_for_return,
            ioi.selling_price,
            ioi.cost_price_per_unit,
            ioi.total_before_discount,
            ioi.discount_type,
            ioi.discount_value,
            ioi.discount_amount,
            ioi.total_after_discount,
            ioi.unit_price_after_discount
        FROM invoice_out_items ioi
        JOIN products p ON p.id = ioi.product_id
        WHERE ioi.invoice_out_id = ?
        /* AND ioi.returned_quantity < ioi.quantity */
        ORDER BY ioi.id
    ");
    
    $stmt->bind_param("i", $invoice_id);
    $stmt->execute();
    $items_result = $stmt->get_result();
    
    $items = [];
    $totalReturns = 0; // إجمالي المرتجعات
    
    while ($item = $items_result->fetch_assoc()) {
        // حساب المرتجعات
        $returnedQty = floatval($item['returned_quantity'] ?? 0);
        $sellingPrice = floatval($item['selling_price'] ?? 0);
        $returnedAmount = $returnedQty * $sellingPrice;
        $totalReturns += $returnedAmount;
        
        // حساب الربح للبند (مخفى في الواجهة)
        $item_cost_total = floatval($item['available_for_return']) * floatval($item['cost_price_per_unit']);
        $item_profit = floatval($item['total_after_discount']) - $item_cost_total;
        
        $items[] = [
            'id' => intval($item['id']),
            'product_id' => intval($item['product_id']),
            'product_name' => $item['product_name'],
            'quantity' => floatval($item['quantity']),
            'returned_quantity' => $returnedQty,
            'available_for_return' => floatval($item['available_for_return']),
            'selling_price' => $sellingPrice,
            'cost_price_per_unit' => floatval($item['cost_price_per_unit']),
            'total_before_discount' => floatval($item['total_before_discount']),
            'discount_type' => $item['discount_type'],
            'discount_value' => floatval($item['discount_value'] ?? 0),
            'discount_amount' => floatval($item['discount_amount'] ?? 0),
            'total_after_discount' => floatval($item['total_after_discount'] ?? 0),
            'unit_price_after_discount' => floatval($item['unit_price_after_discount'] ?? 0),
            'profit' => round($item_profit, 2) // ربح البند (مخفى)
        ];
    }
    $stmt->close();
    
    // تحويل البيانات
    $invoice['id'] = intval($invoice['id']);
    $invoice['customer_id'] = intval($invoice['customer_id']);
    $invoice['total_before_discount'] = floatval($invoice['total_before_discount']);
    $invoice['discount_amount'] = floatval($invoice['discount_amount'] ?? 0);
    $invoice['total_after_discount'] = floatval($invoice['total_after_discount']);
    $invoice['paid_amount'] = floatval($invoice['paid_amount'] ?? 0);
    $invoice['remaining_amount'] = floatval($invoice['remaining_amount'] ?? 0);
    $invoice['total_cost'] = floatval($invoice['total_cost'] ?? 0);
    $invoice['profit_amount'] = floatval($invoice['profit_amount'] ?? 0);
    $invoice['customer_balance'] = floatval($invoice['customer_balance'] ?? 0);
    $invoice['customer_wallet'] = floatval($invoice['customer_wallet'] ?? 0);
    $invoice['total_returns'] = round($totalReturns, 2); // إجمالي المرتجعات
    $invoice['items'] = $items;
    
    echo json_encode([
        'success' => true,
        'invoice' => $invoice
    ], JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'خطأ في جلب البيانات: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>

