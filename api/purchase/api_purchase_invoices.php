<?php
// api_purchase_invoices.php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

require_once dirname(__DIR__, 2) . '/config.php';

if (!isset($conn) || !$conn) {
    echo json_encode([
        "success" => false,
        "message" => "خطأ في الاتصال بقاعدة البيانات"
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// التحقق من المصادقة
$current_user_id = intval($_SESSION['id'] ?? 0);
if ($current_user_id <= 0) {
    echo json_encode([
        "success" => false,
        "message" => "غير مصرح بالوصول. يرجى تسجيل الدخول."
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// الحصول على طريقة الطلب
$method = $_SERVER['REQUEST_METHOD'];
$action = isset($_GET['action']) ? $_GET['action'] : '';
$invoice_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// تعريف وظائف المساعدة
function stmt_bind_params($stmt, $types, $params) {
    if (empty($params)) return true;
    $refs = [&$types];
    foreach ($params as $k => $v) $refs[] = &$params[$k];
    return call_user_func_array([$stmt, 'bind_param'], $refs);
}

function append_invoice_note($conn, $invoice_id, $note_line) {
    $sql = "UPDATE purchase_invoices SET notes = CONCAT(IFNULL(notes,''), ?) WHERE id = ?";
    if ($st = $conn->prepare($sql)) {
        $st->bind_param("si", $note_line, $invoice_id);
        $st->execute();
        $st->close();
        return true;
    }
    return false;
}

// معالجة الطلبات بناءً على method و action
switch ($method) {
    case 'GET':
        handleGetRequests($action, $invoice_id, $conn);
        break;
    case 'POST':
        handlePostRequests($conn, $current_user_id);
        break;
    default:
        echo json_encode([
            "success" => false,
            "message" => "طريقة الطلب غير مدعومة"
        ], JSON_UNESCAPED_UNICODE);
        break;
}

// ==================== وظائف معالجة GET ====================
function handleGetRequests($action, $invoice_id, $conn) {
    switch ($action) {
        case 'fetch_invoice':
            fetchInvoiceJSON($invoice_id, $conn);
            break;
        case 'list_invoices':
            listInvoices($conn);
            break;
        case 'print_supplier':
            printSupplierInvoice($invoice_id, $conn);
            break;
        case 'suppliers':
            getSuppliers($conn);
            break;
        case 'statistics':
            getStatistics($conn);
            break;
        default:
            echo json_encode([
                "success" => false,
                "message" => "الإجراء غير معروف"
            ], JSON_UNESCAPED_UNICODE);
            break;
    }
}

// جلب بيانات فاتورة معينة
function fetchInvoiceJSON($invoice_id, $conn) {
    if ($invoice_id <= 0) {
        echo json_encode([
            "success" => false,
            "message" => "معرف فاتورة غير صالح"
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    // جلب بيانات الفاتورة
    $sql = "SELECT pi.*, s.name AS supplier_name, u.username AS creator_name
            FROM purchase_invoices pi
            JOIN suppliers s ON s.id = pi.supplier_id
            LEFT JOIN users u ON u.id = pi.created_by
            WHERE pi.id = ? LIMIT 1";
    
    if (!$st = $conn->prepare($sql)) {
        echo json_encode([
            "success" => false,
            "message" => "خطأ في تحضير الاستعلام: " . $conn->error
        ], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    $st->bind_param("i", $invoice_id);
    $st->execute();
    $inv = $st->get_result()->fetch_assoc();
    $st->close();
    
    if (!$inv) {
        echo json_encode([
            "success" => false,
            "message" => "الفاتورة غير موجودة"
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    // جلب البنود
    $items = [];
    $sql_items = "SELECT pii.*, COALESCE(p.name,'') AS product_name, COALESCE(p.product_code,'') AS product_code
                  FROM purchase_invoice_items pii
                  LEFT JOIN products p ON p.id = pii.product_id
                  WHERE pii.purchase_invoice_id = ? ORDER BY pii.id ASC";
    
    if (!$sti = $conn->prepare($sql_items)) {
        echo json_encode([
            "success" => false,
            "message" => "خطأ في جلب البنود: " . $conn->error
        ], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    $sti->bind_param("i", $invoice_id);
    $sti->execute();
    $res = $sti->get_result();
    while ($r = $res->fetch_assoc()) {
        $r['quantity'] = (float)$r['quantity'];
        $r['qty_received'] = (float)($r['qty_received'] ?? 0);
        $r['cost_price_per_unit'] = (float)($r['cost_price_per_unit'] ?? 0);
        $r['total_cost'] = isset($r['total_cost']) ? (float)$r['total_cost'] : ($r['quantity'] * $r['cost_price_per_unit']);
        $items[] = $r;
    }
    $sti->close();

    // جلب الدفعات المرتبطة
    $batches = [];
    $sql_b = "SELECT id, product_id, qty, remaining, original_qty, unit_cost, status, revert_reason, cancel_reason, sale_price 
              FROM batches WHERE source_invoice_id = ? ORDER BY id ASC";
    
    if ($stb = $conn->prepare($sql_b)) {
        $stb->bind_param("i", $invoice_id);
        $stb->execute();
        $rb = $stb->get_result();
        while ($bb = $rb->fetch_assoc()) {
            $bb['qty'] = (float)$bb['qty'];
            $bb['remaining'] = (float)$bb['remaining'];
            $bb['original_qty'] = (float)$bb['original_qty'];
            $bb['unit_cost'] = isset($bb['unit_cost']) ? (float)$bb['unit_cost'] : null;
            $bb['sale_price'] = isset($bb['sale_price']) ? (is_null($bb['sale_price']) ? null : (float)$bb['sale_price']) : null;
            $batches[] = $bb;
        }
        $stb->close();
    }

    // التحقق من إمكانية التعديل/التراجع
    $can_edit = false;
    $can_revert = false;
    $status_labels = [
        'pending' => 'قيد الانتظار',
        'partial_received' => 'تم الاستلام جزئياً',
        'fully_received' => 'تم الاستلام بالكامل',
        'cancelled' => 'ملغاة'
    ];

    if ($inv['status'] === 'pending') {
        $can_edit = true;
    } elseif ($inv['status'] === 'fully_received') {
        $all_ok = true;
        $sql_b2 = "SELECT id, qty, remaining, original_qty, status FROM batches WHERE source_invoice_id = ?";
        if ($stb2 = $conn->prepare($sql_b2)) {
            $stb2->bind_param("i", $invoice_id);
            $stb2->execute();
            $rb2 = $stb2->get_result();
            while ($bb2 = $rb2->fetch_assoc()) {
                if (((float)$bb2['remaining']) < ((float)$bb2['original_qty']) || $bb2['status'] !== 'active') {
                    $all_ok = false;
                    break;
                }
            }
            $stb2->close();
        } else {
            $all_ok = false;
        }
        $can_edit = $all_ok;
        $can_revert = $all_ok;
    }

    echo json_encode([
        "success" => true,
        "invoice" => $inv,
        "items" => $items,
        "batches" => $batches,
        "can_edit" => $can_edit,
        "can_revert" => $can_revert,
        "status_labels" => $status_labels
    ], JSON_UNESCAPED_UNICODE);
}

// قائمة الفواتير مع الفلترة
function listInvoices($conn) {
    $selected_supplier_id = isset($_GET['supplier_filter_val']) ? intval($_GET['supplier_filter_val']) : '';
    $selected_status = isset($_GET['status_filter_val']) ? trim($_GET['status_filter_val']) : '';
    $search_invoice_id = isset($_GET['invoice_out_id']) ? intval($_GET['invoice_out_id']) : 0;

    // بناء الاستعلام مع الفلترة
    $sql = "SELECT pi.id, pi.supplier_invoice_number, pi.purchase_date, pi.status, 
                   pi.total_amount, pi.created_at, s.name as supplier_name, 
                   u.username as creator_name
            FROM purchase_invoices pi
            JOIN suppliers s ON pi.supplier_id = s.id
            LEFT JOIN users u ON pi.created_by = u.id
            WHERE 1=1";
    
    $params = [];
    $types = '';
    $conds = [];

    if (!empty($search_invoice_id)) {
        $conds[] = "pi.id = ?";
        $params[] = $search_invoice_id;
        $types .= 'i';
    }
    
    if (!empty($selected_supplier_id)) {
        $conds[] = "pi.supplier_id = ?";
        $params[] = $selected_supplier_id;
        $types .= 'i';
    }
    
    if (!empty($selected_status)) {
        $conds[] = "pi.status = ?";
        $params[] = $selected_status;
        $types .= 's';
    }

    if (!empty($conds)) {
        $sql .= " AND " . implode(" AND ", $conds);
    }

    $sql .= " ORDER BY pi.purchase_date DESC, pi.id DESC";

    $invoices = [];
    if ($stmt = $conn->prepare($sql)) {
        if (!empty($params)) {
            stmt_bind_params($stmt, $types, $params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($inv = $result->fetch_assoc()) {
            $invoices[] = [
                'id' => $inv['id'],
                'supplier_invoice_number' => $inv['supplier_invoice_number'],
                'purchase_date' => $inv['purchase_date'],
                'status' => $inv['status'],
                'total_amount' => (float)$inv['total_amount'],
                'created_at' => $inv['created_at'],
                'supplier_name' => $inv['supplier_name'],
                'creator_name' => $inv['creator_name']
            ];
        }
        $stmt->close();
    }

    // حساب الإحصائيات
    $grand_total_all = 0;
    $rs = $conn->query("SELECT COALESCE(SUM(total_amount),0) AS grand_total FROM purchase_invoices WHERE status != 'cancelled'");
    if ($rs) {
        $r = $rs->fetch_assoc();
        $grand_total_all = (float)$r['grand_total'];
    }

    // المجموع المعروض
    $displayed_sum = 0;
    $sql_total = "SELECT COALESCE(SUM(total_amount),0) AS total_displayed FROM purchase_invoices pi WHERE 1=1";
    if (!empty($conds)) {
        $sql_total .= " AND " . implode(" AND ", $conds);
    }
    
    if ($stmt_total = $conn->prepare($sql_total)) {
        if (!empty($params)) {
            stmt_bind_params($stmt_total, $types, $params);
        }
        $stmt_total->execute();
        $res_t = $stmt_total->get_result();
        $rowt = $res_t->fetch_assoc();
        $displayed_sum = (float)($rowt['total_displayed'] ?? 0);
        $stmt_total->close();
    }

    echo json_encode([
        "success" => true,
        "invoices" => $invoices,
        "statistics" => [
            "total_invoices" => count($invoices),
            "displayed_sum" => $displayed_sum,
            "grand_total_all" => $grand_total_all
        ],
        "filters" => [
            "supplier_id" => $selected_supplier_id,
            "status" => $selected_status,
            "search_invoice_id" => $search_invoice_id
        ]
    ], JSON_UNESCAPED_UNICODE);
}

// جلب قائمة الموردين
function getSuppliers($conn) {
    $suppliers = [];
    $sql = "SELECT id, name FROM suppliers ORDER BY name ASC";
    $rs = $conn->query($sql);
    if ($rs) {
        while ($r = $rs->fetch_assoc()) {
            $suppliers[] = $r;
        }
    }

    echo json_encode([
        "success" => true,
        "suppliers" => $suppliers
    ], JSON_UNESCAPED_UNICODE);
}

// جلب الإحصائيات
function getStatistics($conn) {
    $stats = [];
    
    // إجمالي الفواتير غير الملغاة
    $sql_total = "SELECT COALESCE(SUM(total_amount),0) AS total FROM purchase_invoices WHERE status != 'cancelled'";
    $rs = $conn->query($sql_total);
    if ($rs) {
        $r = $rs->fetch_assoc();
        $stats['grand_total'] = (float)$r['total'];
    }

    // عدد الفواتير حسب الحالة
    $sql_status = "SELECT status, COUNT(*) as count, COALESCE(SUM(total_amount),0) as amount 
                   FROM purchase_invoices GROUP BY status";
    $rs = $conn->query($sql_status);
    if ($rs) {
        $stats['by_status'] = [];
        while ($r = $rs->fetch_assoc()) {
            $stats['by_status'][] = [
                'status' => $r['status'],
                'count' => (int)$r['count'],
                'amount' => (float)$r['amount']
            ];
        }
    }

    echo json_encode([
        "success" => true,
        "statistics" => $stats
    ], JSON_UNESCAPED_UNICODE);
}

// طباعة فاتورة المورد (HTML)
function printSupplierInvoice($invoice_id, $conn) {
    if ($invoice_id <= 0) {
        echo json_encode([
            "success" => false,
            "message" => "معرف فاتورة غير صالح"
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    // جلب بيانات الفاتورة
    $st = $conn->prepare("SELECT pi.*, s.name AS supplier_name, s.address AS supplier_address 
                          FROM purchase_invoices pi 
                          JOIN suppliers s ON s.id = pi.supplier_id 
                          WHERE pi.id = ?");
    $st->bind_param("i", $invoice_id);
    $st->execute();
    $inv = $st->get_result()->fetch_assoc();
    $st->close();
    
    if (!$inv) {
        echo json_encode([
            "success" => false,
            "message" => "الفاتورة غير موجودة"
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    // جلب البنود
    $sti = $conn->prepare("SELECT pii.*, COALESCE(p.name,'') AS product_name 
                          FROM purchase_invoice_items pii 
                          LEFT JOIN products p ON p.id = pii.product_id 
                          WHERE purchase_invoice_id = ?");
    $sti->bind_param("i", $invoice_id);
    $sti->execute();
    $items_res = $sti->get_result();
    
    $items = [];
    while ($row = $items_res->fetch_assoc()) {
        $row['total'] = ((float)$row['quantity']) * ((float)($row['cost_price_per_unit'] ?? 0));
        $items[] = $row;
    }
    $sti->close();

    // تسميات الحالات
    $status_labels = [
        'pending' => 'قيد الانتظار',
        'partial_received' => 'تم الاستلام جزئياً',
        'fully_received' => 'تم الاستلام بالكامل',
        'cancelled' => 'ملغاة'
    ];

    echo json_encode([
        "success" => true,
        "print_data" => [
            "invoice" => $inv,
            "items" => $items,
            "status_label" => $status_labels[$inv['status']] ?? $inv['status']
        ],
        "html_template" => "supplier_invoice_print"
    ], JSON_UNESCAPED_UNICODE);
}

// ==================== وظائف معالجة POST ====================
function handlePostRequests($conn, $current_user_id) {
    // الحصول على البيانات المرسلة
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data) || !isset($data['action'])) {
        echo json_encode([
            "success" => false,
            "message" => "بيانات غير صالحة"
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    switch ($data['action']) {
        case 'receive_invoice':
            receivePurchaseInvoice($conn, $data, $current_user_id);
            break;
        case 'revert_invoice':
            revertInvoiceToPending($conn, $data, $current_user_id);
            break;
        case 'cancel_invoice':
            cancelPurchaseInvoice($conn, $data, $current_user_id);
            break;
        case 'delete_item':
            deleteInvoiceItem($conn, $data, $current_user_id);
            break;
        case 'edit_invoice':
            editInvoiceItems($conn, $data, $current_user_id);
            break;
        default:
            echo json_encode([
                "success" => false,
                "message" => "الإجراء غير معروف"
            ], JSON_UNESCAPED_UNICODE);
            break;
    }
}

// استلام الفاتورة بالكامل
function receivePurchaseInvoice($conn, $data, $current_user_id) {
    $invoice_id = intval($data['invoice_id'] ?? 0);
    if ($invoice_id <= 0) {
        echo json_encode([
            "success" => false,
            "message" => "معرف فاتورة غير صالح"
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    $conn->begin_transaction();
    try {
        // نفس منطق الاستلام من الملف الأصلي
        $st = $conn->prepare("SELECT status FROM purchase_invoices WHERE id = ? FOR UPDATE");
        $st->bind_param("i", $invoice_id);
        $st->execute();
        $invrow = $st->get_result()->fetch_assoc();
        $st->close();
        
        if (!$invrow) throw new Exception("الفاتورة غير موجودة");
        if ($invrow['status'] === 'fully_received') throw new Exception("الفاتورة مُسلمة بالفعل");
        if ($invrow['status'] === 'cancelled') throw new Exception("الفاتورة ملغاة");

        // التحقق من عدم وجود استلام جزئي
        $sti = $conn->prepare("SELECT id, qty_received FROM purchase_invoice_items WHERE purchase_invoice_id = ? FOR UPDATE");
        $sti->bind_param("i", $invoice_id);
        $sti->execute();
        $resi = $sti->get_result();
        while ($r = $resi->fetch_assoc()) {
            if ((float)($r['qty_received'] ?? 0) > 0) throw new Exception("تم استلام جزء من هذه الفاتورة سابقًا");
        }
        $sti->close();

        // جلب البنود
        $stii = $conn->prepare("SELECT id, product_id, quantity, cost_price_per_unit, COALESCE(sale_price, NULL) AS sale_price 
                               FROM purchase_invoice_items WHERE purchase_invoice_id = ?");
        $stii->bind_param("i", $invoice_id);
        $stii->execute();
        $rit = $stii->get_result();
        
        if ($rit->num_rows === 0) {
            throw new Exception("لا يوجد بنود في هذه الفاتورة للاستلام.");
        }

        // تحقق من وجود كميات
        $has_qty = false;
        $rit->data_seek(0);
        while ($tmp = $rit->fetch_assoc()) {
            if ((float)($tmp['quantity'] ?? 0) > 0) { 
                $has_qty = true; 
                break; 
            }
        }
        $rit->data_seek(0);
        
        if (!$has_qty) {
            throw new Exception("كل بنود الفاتورة فارغة أو بكميات صفرية");
        }

        // تحضير الاستعلامات
        $stmt_update_product = $conn->prepare("UPDATE products SET current_stock = current_stock + ? WHERE id = ?");
        $stmt_insert_batch = $conn->prepare("INSERT INTO batches (product_id, qty, remaining, original_qty, unit_cost, sale_price, received_at, source_invoice_id, source_item_id, status, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, NOW(), NOW())");
        $stmt_update_item = $conn->prepare("UPDATE purchase_invoice_items SET qty_received = ?, batch_id = ? WHERE id = ?");
        
        if (!$stmt_update_product || !$stmt_insert_batch || !$stmt_update_item) {
            throw new Exception("فشل تحضير استعلامات داخليّة");
        }

        while ($it = $rit->fetch_assoc()) {
            $item_id = intval($it['id']);
            $product_id = intval($it['product_id']);
            $qty = (float)$it['quantity'];
            $unit_cost = (float)$it['cost_price_per_unit'];
            $item_sale_price = isset($it['sale_price']) ? (is_null($it['sale_price']) ? null : (float)$it['sale_price']) : null;
            
            if ($qty <= 0) continue;

            // البحث عن دفعة معادة لنفس العنصر
            $st_find_rev = $conn->prepare("SELECT id, qty, remaining, original_qty, unit_cost, sale_price, status 
                                          FROM batches WHERE source_item_id = ? AND status = 'reverted' LIMIT 1 FOR UPDATE");
            if ($st_find_rev) {
                $st_find_rev->bind_param("i", $item_id);
                $st_find_rev->execute();
                $existing_rev = $st_find_rev->get_result()->fetch_assoc();
                $st_find_rev->close();
            } else {
                $existing_rev = null;
            }

            if ($existing_rev && isset($existing_rev['id'])) {
                // إعادة تفعيل الدفعة المعادة
                $bid = intval($existing_rev['id']);
                $new_qty = $qty;
                $new_remaining = $new_qty;
                $new_original = $new_qty;

                // تحديث المخزون
                if (!$stmt_update_product->bind_param("di", $new_qty, $product_id) || !$stmt_update_product->execute()) {
                    throw new Exception('فشل تحديث المنتج');
                }

                // تحديث الدفعة
                if ($item_sale_price === null) {
                    $upb = $conn->prepare("UPDATE batches SET qty = ?, remaining = ?, original_qty = ?, unit_cost = ?, sale_price = NULL, status = 'active', adjusted_by = ?, adjusted_at = NOW() WHERE id = ?");
                    if (!$upb || !$upb->bind_param("ddddiii", $new_qty, $new_remaining, $new_original, $unit_cost, $current_user_id, $bid) || !$upb->execute()) {
                        throw new Exception("فشل تحديث الدفعة");
                    }
                    $upb->close();
                } else {
                    $upb = $conn->prepare("UPDATE batches SET qty = ?, remaining = ?, original_qty = ?, unit_cost = ?, sale_price = ?, status = 'active', adjusted_by = ?, adjusted_at = NOW() WHERE id = ?");
                    if (!$upb || !$upb->bind_param("dddddiii", $new_qty, $new_remaining, $new_original, $unit_cost, $item_sale_price, $current_user_id, $bid) || !$upb->execute()) {
                        throw new Exception("فشل تحديث الدفعة");
                    }
                    $upb->close();
                }

                // ربط البند بالدفعة
                if (!$stmt_update_item->bind_param("dii", $new_qty, $bid, $item_id) || !$stmt_update_item->execute()) {
                    throw new Exception('فشل ربط البند بالدفعة');
                }
                
                continue;
            }

            // إنشاء دفعة جديدة
            if (!$stmt_update_product->bind_param("di", $qty, $product_id) || !$stmt_update_product->execute()) {
                throw new Exception('فشل تحديث المنتج');
            }

            $b_product_id = $product_id;
            $b_qty = $qty;
            $b_remaining = $qty;
            $b_original = $qty;
            $b_unit_cost = $unit_cost;
            $b_received_at = date('Y-m-d H:i:s');
            $b_source_invoice_id = $invoice_id;
            $b_source_item_id = $item_id;
            $b_created_by = $current_user_id;

            if ($item_sale_price === null) {
                $insq = $conn->prepare("INSERT INTO batches (product_id, qty, remaining, original_qty, unit_cost, sale_price, received_at, source_invoice_id, source_item_id, status, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NULL, ?, ?, ?, 'active', ?, NOW(), NOW())");
                if (!$insq) throw new Exception('فشل تحضير إدخال الدفعة');
                stmt_bind_params($insq, "iddddsiii", [$b_product_id, $b_qty, $b_remaining, $b_original, $b_unit_cost, $b_received_at, $b_source_invoice_id, $b_source_item_id, $b_created_by]);
                if (!$insq->execute()) throw new Exception('فشل إدخال الدفعة');
                $new_batch_id = $insq->insert_id;
                $insq->close();
            } else {
                stmt_bind_params($stmt_insert_batch, "idddddsiii", [$b_product_id, $b_qty, $b_remaining, $b_original, $b_unit_cost, $item_sale_price, $b_received_at, $b_source_invoice_id, $b_source_item_id, $b_created_by]);
                if (!$stmt_insert_batch->execute()) throw new Exception('فشل إدخال الدفعة');
                $new_batch_id = $stmt_insert_batch->insert_id;
            }

            // تحديث بند الفاتورة
            if (!$stmt_update_item->bind_param("dii", $qty, $new_batch_id, $item_id) || !$stmt_update_item->execute()) {
                throw new Exception('فشل تحديث بند الفاتورة');
            }
        }

        // تحديث حالة الفاتورة
        $stup = $conn->prepare("UPDATE purchase_invoices SET status = 'fully_received', updated_by = ?, updated_at = NOW() WHERE id = ?");
        $stup->bind_param("ii", $current_user_id, $invoice_id);
        if (!$stup->execute()) throw new Exception('فشل تحديث حالة الفاتورة');
        $stup->close();

        $conn->commit();
        
        echo json_encode([
            "success" => true,
            "message" => "تم استلام الفاتورة وإنشاء/تحديث الدُفعات وتحديث المخزون بنجاح."
        ], JSON_UNESCAPED_UNICODE);
        
    } catch (Exception $e) {
        $conn->rollback();
        error_log('Receive invoice error: ' . $e->getMessage());
        
        echo json_encode([
            "success" => false,
            "message" => "فشل استلام الفاتورة: " . $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
}

// إرجاع الفاتورة إلى قيد الانتظار
function revertInvoiceToPending($conn, $data, $current_user_id) {
    $invoice_id = intval($data['invoice_id'] ?? 0);
    $reason = trim($data['reason'] ?? '');
    
    if ($invoice_id <= 0) {
        echo json_encode([
            "success" => false,
            "message" => "معرف فاتورة غير صالح"
        ], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    if ($reason === '') {
        echo json_encode([
            "success" => false,
            "message" => "الرجاء إدخال سبب الإرجاع"
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    $conn->begin_transaction();
    try {
        // التحقق من الدفعات
        $stb = $conn->prepare("SELECT id, product_id, qty, remaining, original_qty, status FROM batches WHERE source_invoice_id = ? FOR UPDATE");
        if (!$stb) throw new Exception("فشل تحضير استعلام الدُفعات");
        
        $stb->bind_param("i", $invoice_id);
        $stb->execute();
        $rb = $stb->get_result();
        $batches = [];
        while ($bb = $rb->fetch_assoc()) $batches[] = $bb;
        $stb->close();

        foreach ($batches as $b) {
            if (((float)$b['remaining']) < ((float)$b['original_qty']) || $b['status'] !== 'active') {
                throw new Exception("لا يمكن إعادة الفاتورة لأن بعض الدُفعات قد اُستهلكت أو تغيرت.");
            }
        }

        $upd_batch = $conn->prepare("UPDATE batches SET status = 'reverted', revert_reason = ?, updated_at = NOW() WHERE id = ?");
        $upd_prod = $conn->prepare("UPDATE products SET current_stock = current_stock - ? WHERE id = ?");
        
        if (!$upd_batch || !$upd_prod) throw new Exception("فشل تحضير استعلامات التراجع");

        foreach ($batches as $b) {
            $bid = intval($b['id']);
            $pid = intval($b['product_id']);
            $qty = (float)$b['qty'];

            if (!$upd_prod->bind_param("di", $qty, $pid) || !$upd_prod->execute()) {
                throw new Exception("فشل تحديث رصيد المنتج أثناء التراجع");
            }
            
            if (!$upd_batch->bind_param("si", $reason, $bid) || !$upd_batch->execute()) {
                throw new Exception("فشل تحديث الدفعة أثناء التراجع");
            }
        }

        // إعادة تعيين البنود
        $rst = $conn->prepare("UPDATE purchase_invoice_items SET qty_received = 0, batch_id = NULL WHERE purchase_invoice_id = ?");
        $rst->bind_param("i", $invoice_id);
        $rst->execute();
        $rst->close();

        // تحديث الفاتورة
        $u = $conn->prepare("UPDATE purchase_invoices SET status = 'pending', revert_reason = ?, updated_by = ?, updated_at = NOW() WHERE id = ?");
        $u->bind_param("sii", $reason, $current_user_id, $invoice_id);
        $u->execute();
        $u->close();

        // إضافة ملاحظة
        $now = date('Y-m-d H:i:s');
        $note_line = "[" . $now . "] إرجاع إلى قيد الانتظار: " . $reason . " (المحرر: " . $_SESSION['username'] . ")\n";
        append_invoice_note($conn, $invoice_id, $note_line);

        $conn->commit();
        
        echo json_encode([
            "success" => true,
            "message" => "تم إرجاع الفاتورة إلى قيد الانتظار."
        ], JSON_UNESCAPED_UNICODE);
        
    } catch (Exception $e) {
        $conn->rollback();
        error_log('Revert invoice error: ' . $e->getMessage());
        
        echo json_encode([
            "success" => false,
            "message" => "فشل إعادة الفاتورة: " . $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
}

// إلغاء الفاتورة
function cancelPurchaseInvoice($conn, $data, $current_user_id) {
    $invoice_id = intval($data['invoice_id'] ?? 0);
    $reason = trim($data['reason'] ?? '');
    
    if ($invoice_id <= 0) {
        echo json_encode([
            "success" => false,
            "message" => "معرف فاتورة غير صالح"
        ], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    if ($reason === '') {
        echo json_encode([
            "success" => false,
            "message" => "الرجاء إدخال سبب الإلغاء"
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    $conn->begin_transaction();
    try {
        $st = $conn->prepare("SELECT status FROM purchase_invoices WHERE id = ? FOR UPDATE");
        $st->bind_param("i", $invoice_id);
        $st->execute();
        $r = $st->get_result()->fetch_assoc();
        $st->close();
        
        if (!$r) throw new Exception("الفاتورة غير موجودة");
        if ($r['status'] === 'fully_received') throw new Exception("لا يمكن إلغاء فاتورة تم استلامها بالكامل. الرجاء إجراء تراجع أولاً.");

        // تحديث الفاتورة
        $upd = $conn->prepare("UPDATE purchase_invoices SET status = 'cancelled', cancel_reason = ?, updated_by = ?, updated_at = NOW() WHERE id = ?");
        $upd->bind_param("sii", $reason, $current_user_id, $invoice_id);
        $upd->execute();
        $upd->close();

        // تحديث الدفعات المرتبطة
        $upd_b = $conn->prepare("UPDATE batches SET status = 'cancelled', cancel_reason = ?, revert_reason = NULL, updated_at = NOW() WHERE source_invoice_id = ? AND status IN ('active','reverted')");
        $upd_b->bind_param("si", $reason, $invoice_id);
        $upd_b->execute();
        $upd_b->close();

        // إضافة ملاحظة
        $now = date('Y-m-d H:i:s');
        $note_line = "[" . $now . "] إلغاء الفاتورة: " . $reason . " (المحرر: " . $_SESSION['username'] . ")\n";
        append_invoice_note($conn, $invoice_id, $note_line);

        $conn->commit();
        
        echo json_encode([
            "success" => true,
            "message" => "تم إلغاء الفاتورة."
        ], JSON_UNESCAPED_UNICODE);
        
    } catch (Exception $e) {
        $conn->rollback();
        error_log('Cancel invoice error: ' . $e->getMessage());
        
        echo json_encode([
            "success" => false,
            "message" => "فشل الإلغاء: " . $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
}

// حذف بند من الفاتورة
function deleteInvoiceItem($conn, $data, $current_user_id) {
    $invoice_id = intval($data['invoice_id'] ?? 0);
    $item_id = intval($data['item_id'] ?? 0);
    $reason = trim($data['reason'] ?? '');
    
    if ($invoice_id <= 0 || $item_id <= 0) {
        echo json_encode([
            "success" => false,
            "message" => "بيانات غير صالحة"
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    $conn->begin_transaction();
    try {
        // التحقق من حالة الفاتورة
        $st = $conn->prepare("SELECT status FROM purchase_invoices WHERE id = ? FOR UPDATE");
        $st->bind_param("i", $invoice_id);
        $st->execute();
        $inv = $st->get_result()->fetch_assoc();
        $st->close();
        
        if (!$inv) throw new Exception('الفاتورة غير موجودة');
        if ($inv['status'] !== 'pending') throw new Exception('لا يمكن حذف بند إلا في حالة قيد الانتظار');

        // جلب معلومات البند
        $sti = $conn->prepare("SELECT p.name AS product_name, i.quantity, i.product_id FROM purchase_invoice_items i JOIN products p ON p.id = i.product_id WHERE i.id = ?");
        $sti->bind_param("i", $item_id);
        $sti->execute();
        $it = $sti->get_result()->fetch_assoc();
        $sti->close();
        
        if (!$it) throw new Exception('البند غير موجود');

        // حذف البند
        $del = $conn->prepare("DELETE FROM purchase_invoice_items WHERE id = ?");
        $del->bind_param("i", $item_id);
        $del->execute();
        $del->close();

        // إعادة حساب المجموع
        $sttot = $conn->prepare("SELECT COALESCE(SUM(quantity * cost_price_per_unit),0) AS total FROM purchase_invoice_items WHERE purchase_invoice_id = ?");
        $sttot->bind_param("i", $invoice_id);
        $sttot->execute();
        $rt = $sttot->get_result()->fetch_assoc();
        $sttot->close();
        
        $new_total = (float)($rt['total'] ?? 0.0);
        $upinv = $conn->prepare("UPDATE purchase_invoices SET total_amount = ?, updated_by = ?, updated_at = NOW() WHERE id = ?");
        $upinv->bind_param("dii", $new_total, $current_user_id, $invoice_id);
        $upinv->execute();
        $upinv->close();

        // إضافة ملاحظة
        $now = date('Y-m-d H:i:s');
        $product_name = $it['product_name'] ?? ("ID:" . $it['product_id']);
        $note_line = "[" . $now . "] حذف بند (#{$item_id}) - المنتج: {$product_name}, الكمية: {$it['quantity']}. السبب: " . ($reason === '' ? 'لم يُذكر' : $reason) . " (المحرر: " . $_SESSION['username'] . ")\n";
        append_invoice_note($conn, $invoice_id, $note_line);

        $conn->commit();
        
        echo json_encode([
            "success" => true,
            "message" => "تم حذف البند وتحديث المجموع."
        ], JSON_UNESCAPED_UNICODE);
        
    } catch (Exception $e) {
        $conn->rollback();
        
        echo json_encode([
            "success" => false,
            "message" => "فشل حذف البند: " . $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
}

// تعديل بنود الفاتورة
function editInvoiceItems($conn, $data, $current_user_id) {
    $invoice_id = intval($data['invoice_id'] ?? 0);
    $items_data = $data['items'] ?? [];
    $adjust_reason = trim($data['adjust_reason'] ?? '');
    
    if ($invoice_id <= 0 || !is_array($items_data)) {
        echo json_encode([
            "success" => false,
            "message" => "بيانات غير صالحة"
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    $conn->begin_transaction();
    try {
        $st = $conn->prepare("SELECT status, notes FROM purchase_invoices WHERE id = ? FOR UPDATE");
        $st->bind_param("i", $invoice_id);
        $st->execute();
        $inv = $st->get_result()->fetch_assoc();
        $st->close();
        
        if (!$inv) throw new Exception("الفاتورة غير موجودة");

        foreach ($items_data as $it) {
            $item_id = intval($it['item_id'] ?? 0);
            $new_qty = (float)($it['new_quantity'] ?? 0);
            $new_cost = isset($it['new_cost_price']) ? (float)$it['new_cost_price'] : null;
            $new_sale = array_key_exists('new_sale_price', $it) ? ($it['new_sale_price'] === null ? null : (float)$it['new_sale_price']) : null;
            
            if ($item_id <= 0) continue;

            // جلب بيانات البند
            $sti = $conn->prepare("SELECT id, purchase_invoice_id, product_id, quantity, qty_received, cost_price_per_unit, sale_price FROM purchase_invoice_items WHERE id = ? FOR UPDATE");
            $sti->bind_param("i", $item_id);
            $sti->execute();
            $row = $sti->get_result()->fetch_assoc();
            $sti->close();
            
            if (!$row) throw new Exception("بند غير موجود: #$item_id");
            
            $old_qty = (float)$row['quantity'];
            $prod_id = intval($row['product_id']);

            if ($inv['status'] === 'pending') {
                // تعديل بند في حالة pending
                $diff = $new_qty - $old_qty;
                $qty_adj = (float)$diff;
                $effective_cost = ($new_cost !== null) ? (float)$new_cost : (float)($row['cost_price_per_unit'] ?? 0.0);
                $new_total_cost = $new_qty * $effective_cost;

                $upit = $conn->prepare("UPDATE purchase_invoice_items SET quantity = ?, qty_adjusted = ?, adjustment_reason = ?, adjusted_by = ?, adjusted_at = NOW(), total_cost = ? WHERE id = ?");
                if (!$upit) throw new Exception("فشل تحضير تعديل البند");
                
                $upit->bind_param("dssidi", $new_qty, $qty_adj, $adjust_reason, $current_user_id, $new_total_cost, $item_id);
                if (!$upit->execute()) throw new Exception("فشل تعديل البند");
                $upit->close();

                // تحديث سعر الشراء إذا تم توفيره
                if ($new_cost !== null) {
                    $stmtc = $conn->prepare("UPDATE purchase_invoice_items SET cost_price_per_unit = ? WHERE id = ?");
                    $stmtc->bind_param("di", $new_cost, $item_id);
                    $stmtc->execute();
                    $stmtc->close();
                }
                
                // تحديث سعر البيع إذا تم توفيره
                if ($new_sale !== null) {
                    $stmts = $conn->prepare("UPDATE purchase_invoice_items SET sale_price = ? WHERE id = ?");
                    $stmts->bind_param("di", $new_sale, $item_id);
                    $stmts->execute();
                    $stmts->close();
                }
                
                continue;
            }

            if ($inv['status'] === 'fully_received') {
                // تعديل بند في حالة fully_received
                $stb = $conn->prepare("SELECT id, qty, remaining, original_qty FROM batches WHERE source_item_id = ? FOR UPDATE");
                $stb->bind_param("i", $item_id);
                $stb->execute();
                $batch = $stb->get_result()->fetch_assoc();
                $stb->close();
                
                if (!$batch) throw new Exception("لا توجد دفعة مرتبطة بالبند #$item_id");
                if (((float)$batch['remaining']) < ((float)$batch['original_qty'])) throw new Exception("لا يمكن تعديل هذا البند لأن الدفعة المرتبطة به قد اُستهلكت.");

                $diff = $new_qty - $old_qty;
                $qty_adj = $diff;

                $upit = $conn->prepare("UPDATE purchase_invoice_items SET quantity = ?, qty_received = ?, qty_adjusted = ?, adjustment_reason = ?, adjusted_by = ?, adjusted_at = NOW() WHERE id = ?");
                if (!$upit) throw new Exception("فشل تحضير تعديل البند");
                
                $upit->bind_param("ddssii", $new_qty, $new_qty, $qty_adj, $adjust_reason, $current_user_id, $item_id);
                if (!$upit->execute()) throw new Exception("فشل تعديل البند");
                $upit->close();

                // تحديث total_cost
                $st_tot_item = $conn->prepare("UPDATE purchase_invoice_items SET total_cost = (quantity * cost_price_per_unit) WHERE id = ?");
                if (!$st_tot_item) throw new Exception("فشل تحضير تحديث total_cost");
                
                $st_tot_item->bind_param("i", $item_id);
                if (!$st_tot_item->execute()) throw new Exception("فشل تحديث total_cost");
                $st_tot_item->close();

                // تحديث الدفعة
                $new_batch_qty = (float)$batch['qty'] + $diff;
                $new_remaining = (float)$batch['remaining'] + $diff;
                $new_original = (float)$batch['original_qty'] + $diff;
                
                if ($new_remaining < 0) throw new Exception("التعديل يؤدي إلى قيمة متبقية سلبية");

                $upb = $conn->prepare("UPDATE batches SET qty = ?, remaining = ?, original_qty = ?, adjusted_by = ?, adjusted_at = NOW() WHERE id = ?");
                if (!$upb) throw new Exception("فشل تحضير تحديث الدفعة");
                
                $upb->bind_param("ddiii", $new_batch_qty, $new_remaining, $new_original, $current_user_id, $batch['id']);
                if (!$upb->execute()) throw new Exception("فشل تحديث الدفعة");
                $upb->close();

                // تحديث المخزون
                $upprod = $conn->prepare("UPDATE products SET current_stock = current_stock + ? WHERE id = ?");
                $upprod->bind_param("di", $diff, $prod_id);
                if (!$upprod->execute()) throw new Exception("فشل تحديث المخزون");
                $upprod->close();

                // تحديث سعر الشراء
                if ($new_cost !== null) {
                    $stmtc = $conn->prepare("UPDATE purchase_invoice_items SET cost_price_per_unit = ? WHERE id = ?");
                    $stmtc->bind_param("di", $new_cost, $item_id);
                    $stmtc->execute();
                    $stmtc->close();

                    $upb_cost = $conn->prepare("UPDATE batches SET unit_cost = ? WHERE id = ?");
                    $upb_cost->bind_param("di", $new_cost, $batch['id']);
                    $upb_cost->execute();
                    $upb_cost->close();

                    // تحديث total_cost بعد تغيير السعر
                    $st_tot_after_cost = $conn->prepare("UPDATE purchase_invoice_items SET total_cost = (quantity * cost_price_per_unit) WHERE id = ?");
                    if (!$st_tot_after_cost) throw new Exception("فشل تحضير تحديث total_cost بعد تغيير السعر");
                    
                    $st_tot_after_cost->bind_param("i", $item_id);
                    if (!$st_tot_after_cost->execute()) throw new Exception("فشل تحديث total_cost بعد تغيير السعر");
                    $st_tot_after_cost->close();
                }
                
                // تحديث سعر البيع
                if ($new_sale !== null) {
                    $stmt_sale_item = $conn->prepare("UPDATE purchase_invoice_items SET sale_price = ? WHERE id = ?");
                    $stmt_sale_item->bind_param("di", $new_sale, $item_id);
                    $stmt_sale_item->execute();
                    $stmt_sale_item->close();

                    $upb_sale = $conn->prepare("UPDATE batches SET sale_price = ? WHERE id = ?");
                    $upb_sale->bind_param("di", $new_sale, $batch['id']);
                    $upb_sale->execute();
                    $upb_sale->close();
                }
                
                continue;
            }

            throw new Exception("لا يمكن التعديل في الحالة الحالية");
        }

        // إعادة حساب المجموع الكلي للفاتورة
        $sttot = $conn->prepare("SELECT COALESCE(SUM(quantity * cost_price_per_unit),0) AS total FROM purchase_invoice_items WHERE purchase_invoice_id = ?");
        $sttot->bind_param("i", $invoice_id);
        $sttot->execute();
        $rt = $sttot->get_result()->fetch_assoc();
        $sttot->close();
        
        $new_total = (float)($rt['total'] ?? 0.0);
        $upinv = $conn->prepare("UPDATE purchase_invoices SET total_amount = ?, updated_by = ?, updated_at = NOW() WHERE id = ?");
        $upinv->bind_param("dii", $new_total, $current_user_id, $invoice_id);
        $upinv->execute();
        $upinv->close();

        // إضافة ملاحظة التعديل
        if ($adjust_reason !== '') {
            $now = date('Y-m-d H:i:s');
            $note_line = "[" . $now . "] تعديل بنود: " . $adjust_reason . " (المحرر: " . $_SESSION['username'] . ")\n";
            append_invoice_note($conn, $invoice_id, $note_line);
        }

        $conn->commit();
        
        echo json_encode([
            "success" => true,
            "message" => "تم حفظ التعديلات بنجاح.",
            "new_total" => $new_total
        ], JSON_UNESCAPED_UNICODE);
        
    } catch (Exception $e) {
        $conn->rollback();
        error_log('Edit invoice error: ' . $e->getMessage());
        
        echo json_encode([
            "success" => false,
            "message" => "فشل حفظ التعديلات: " . $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
}

$conn->close();
?>