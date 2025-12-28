<?php
// manage_purchase_invoices.redesigned.php
// مُعدَّل بناءً على طلب المستخدم: إعادة تصميم كامل للصفحة مع تحسينات البحث والفلاتر

$page_title = "إدارة فواتير المشتريات";
require_once dirname(__DIR__) . '/config.php';
require_once BASE_DIR . 'partials/session_admin.php';

if (!isset($conn) || !$conn) {
  echo "DB connection error";
  exit;
}
function e($s)
{
  return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// CSRF
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf_token = $_SESSION['csrf_token'];

// labels
$status_labels = [
  'pending' => 'قيد الانتظار',
  'partial_received' => 'تم الاستلام جزئياً',
  'fully_received' => 'تم الاستلام بالكامل',
  'cancelled' => 'ملغاة'
];

// ---------- مساعدات عامة ----------
function has_column($conn, $table, $col)
{
  $ok = true;
  $sql = "SHOW COLUMNS FROM `{$table}` LIKE ?";
  if ($st = $conn->prepare($sql)) {
    $st->bind_param("s", $col);
    $st->execute();
    $res = $st->get_result();
    $ok = ($res && $res->num_rows > 0);
    $st->close();
  }
  return $ok;
}

function append_invoice_note($conn, $invoice_id, $note_line)
{
  $sql = "UPDATE purchase_invoices SET notes = CONCAT(IFNULL(notes,''), ?) WHERE id = ?";
  if ($st = $conn->prepare($sql)) {
    $st->bind_param("si", $note_line, $invoice_id);
    $st->execute();
    $st->close();
  }
}

// helper: safe bind for dynamic params
function stmt_bind_params(mysqli_stmt $stmt, string $types, array $params)
{
  if (empty($params)) return true;
  $refs = [];
  $refs[] = $types;
  foreach ($params as $k => $v) $refs[] = &$params[$k];
  return call_user_func_array([$stmt, 'bind_param'], $refs);
}

// ---------- AJAX endpoint: جلب بيانات الفاتورة كـ JSON (للمودال) ----------
if (isset($_GET['action']) && $_GET['action'] === 'fetch_invoice_json' && isset($_GET['id'])) {
  header('Content-Type: application/json; charset=utf-8');
  $inv_id = intval($_GET['id']);
  if ($inv_id <= 0) {
    echo json_encode(['ok' => false, 'msg' => 'معرف فاتورة غير صالح']);
    exit;
  }

  // invoice
  $sql = "SELECT pi.*, s.name AS supplier_name, u.username AS creator_name
            FROM purchase_invoices pi
            JOIN suppliers s ON s.id = pi.supplier_id
            LEFT JOIN users u ON u.id = pi.created_by
            WHERE pi.id = ? LIMIT 1";
  if (!$st = $conn->prepare($sql)) {
    echo json_encode(['ok' => false, 'msg' => 'DB prepare invoice error: ' . $conn->error], JSON_UNESCAPED_UNICODE);
    exit;
  }
  $st->bind_param("i", $inv_id);
  $st->execute();
  $inv = $st->get_result()->fetch_assoc();
  $st->close();
  if (!$inv) {
    echo json_encode(['ok' => false, 'msg' => 'الفاتورة غير موجودة']);
    exit;
  }

  // items
  $items = [];
  $sql_items = "SELECT pii.*, COALESCE(p.name,'') AS product_name, COALESCE(p.product_code,'') AS product_code
                  FROM purchase_invoice_items pii
                  LEFT JOIN products p ON p.id = pii.product_id
                  WHERE pii.purchase_invoice_id = ? ORDER BY pii.id ASC";
  if (!$sti = $conn->prepare($sql_items)) {
    echo json_encode(['ok' => false, 'msg' => 'DB prepare items error: ' . $conn->error], JSON_UNESCAPED_UNICODE);
    exit;
  }
  $sti->bind_param("i", $inv_id);
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

  // batches for this invoice (include reasons/status)
  $batches = [];
  $sql_b = "SELECT id, product_id, qty, remaining, original_qty, unit_cost, status, revert_reason, cancel_reason, sale_price FROM batches WHERE source_invoice_id = ? ORDER BY id ASC";
  if ($stb = $conn->prepare($sql_b)) {
    $stb->bind_param("i", $inv_id);
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

  // can_edit / can_revert logic
  $can_edit = false;
  $can_revert = false;
  if ($inv['status'] === 'pending') {
    $can_edit = true;
  } elseif ($inv['status'] === 'fully_received') {
    $all_ok = true;
    $sql_b2 = "SELECT id, qty, remaining, original_qty, status FROM batches WHERE source_invoice_id = ?";
    if ($stb2 = $conn->prepare($sql_b2)) {
      $stb2->bind_param("i", $inv_id);
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
    'ok' => true,
    'invoice' => $inv,
    'items' => $items,
    'batches' => $batches,
    'can_edit' => $can_edit,
    'can_revert' => $can_revert,
    'status_labels' => $status_labels
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

// ---------- AJAX: بحث الموردين ----------
if (isset($_GET['action']) && $_GET['action'] === 'search_suppliers') {
  header('Content-Type: application/json; charset=utf-8');
  $search = isset($_GET['q']) ? trim($_GET['q']) : '';
  $results = [];
  
  if (strlen($search) >= 2) {
    $sql = "SELECT id, name FROM suppliers WHERE name LIKE ? ORDER BY name LIMIT 10";
    if ($stmt = $conn->prepare($sql)) {
      $search_term = "%{$search}%";
      $stmt->bind_param("s", $search_term);
      $stmt->execute();
      $res = $stmt->get_result();
      while ($row = $res->fetch_assoc()) {
        $results[] = [
          'id' => $row['id'],
          'name' => $row['name']
        ];
      }
      $stmt->close();
    }
  }
  
  echo json_encode($results);
  exit;
}

// ---------- Print view ----------
if (isset($_GET['action']) && $_GET['action'] === 'print_supplier' && isset($_GET['id'])) {
  $inv_id = intval($_GET['id']);
  if ($inv_id <= 0) {
    echo "Invalid invoice id";
    exit;
  }
  
  $st = $conn->prepare("SELECT pi.*, s.name AS supplier_name, s.address AS supplier_address FROM purchase_invoices pi JOIN suppliers s ON s.id = pi.supplier_id WHERE pi.id = ?");
  $st->bind_param("i", $inv_id);
  $st->execute();
  $inv = $st->get_result()->fetch_assoc();
  $st->close();
  
  if (!$inv) {
    echo "Invoice not found";
    exit;
  }
  
  $sti = $conn->prepare("SELECT pii.*, COALESCE(p.name,'') AS product_name FROM purchase_invoice_items pii LEFT JOIN products p ON p.id = pii.product_id WHERE purchase_invoice_id = ?");
  $sti->bind_param("i", $inv_id);
  $sti->execute();
  $items_res = $sti->get_result();
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="utf-8">
  <title>طباعة فاتورة المورد - <?php echo e($inv['supplier_name']); ?></title>
  <style>
    body { font-family: Tahoma, Arial; direction: rtl; }
    .sheet { width: 210mm; margin: 10mm auto; }
    table { width: 100%; border-collapse: collapse }
    th, td { padding: 6px; border: 1px solid #333 }
    .no-batches-note { font-size: 12px; color: #666 }
    @media print { .no-print { display: none } }
  </style>
</head>
<body>
  <div class="sheet">
    <h2>فاتورة مشتريات — المورد: <?php echo e($inv['supplier_name']); ?></h2>
    <div>تاريخ الشراء: <?php echo e($inv['purchase_date']); ?> — حالة: <?php echo e($status_labels[$inv['status']] ?? $inv['status']); ?></div>
    <p class="no-batches-note">ملاحظة: هذا العرض يخص المورد ولا يتضمن معلومات الدُفعات الداخلية.</p>
    <table>
      <thead>
        <tr>
          <th>المنتج</th><th>الكمية</th><th>سعر التكلفة</th><th>سعر البيع</th><th>الإجمالي</th>
        </tr>
      </thead>
      <tbody>
        <?php while ($row = $items_res->fetch_assoc()) {
          $total = ((float)$row['quantity']) * ((float)($row['cost_price_per_unit'] ?? 0)); ?>
          <tr>
            <td><?php echo e($row['product_name']); ?></td>
            <td><?php echo e($row['quantity']); ?></td>
            <td><?php echo e($row['cost_price_per_unit']); ?></td>
            <td><?php echo e($row['sale_price'] ?? ''); ?></td>
            <td><?php echo number_format($total, 2); ?></td>
          </tr>
        <?php } ?>
      </tbody>
    </table>
    <div style="margin-top:10px">المجموع: <?php echo e(number_format($inv['total_amount'], 2)); ?></div>
    <div style="margin-top:20px">ملاحظات: <pre><?php echo e($inv['notes'] ?? ''); ?></pre></div>
    <div class="no-print" style="margin-top:20px"><button onclick="window.print()">طباعة</button></div>
  </div>
</body>
</html>
<?php
  exit;
}

// ---------- POST handlers ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    $_SESSION['message'] = "<div class='alert alert-danger'>خطأ: طلب غير صالح (CSRF).</div>";
    header("Location: " . basename(__FILE__));
    exit;
  }

  $current_user_id = intval($_SESSION['id'] ?? 0);
  $current_user_name = $_SESSION['username'] ?? ('user#' . $current_user_id);

  // ----- RECEIVE -----
  if (isset($_POST['receive_purchase_invoice'])) {
    $invoice_id = intval($_POST['purchase_invoice_id'] ?? 0);
    if ($invoice_id <= 0) {
      $_SESSION['message'] = "<div class='alert alert-danger'>معرف غير صالح.</div>";
      header("Location: " . basename(__FILE__));
      exit;
    }

    $conn->begin_transaction();
    try {
      $st = $conn->prepare("SELECT status FROM purchase_invoices WHERE id = ? FOR UPDATE");
      $st->bind_param("i", $invoice_id);
      $st->execute();
      $invrow = $st->get_result()->fetch_assoc();
      $st->close();
      if (!$invrow) throw new Exception("الفاتورة غير موجودة");
      if ($invrow['status'] === 'fully_received') throw new Exception("الفاتورة مُسلمة بالفعل");
      if ($invrow['status'] === 'cancelled') throw new Exception("الفاتورة ملغاة");

      $sti = $conn->prepare("SELECT id, qty_received FROM purchase_invoice_items WHERE purchase_invoice_id = ? FOR UPDATE");
      $sti->bind_param("i", $invoice_id);
      $sti->execute();
      $resi = $sti->get_result();
      while ($r = $resi->fetch_assoc()) {
        if ((float)($r['qty_received'] ?? 0) > 0) throw new Exception("تم استلام جزء من هذه الفاتورة سابقًا — لا يوجد دعم للاستلام الجزئي هنا.");
      }
      $sti->close();

      $stii = $conn->prepare("SELECT id, product_id, quantity, cost_price_per_unit, COALESCE(sale_price, NULL) AS sale_price FROM purchase_invoice_items WHERE purchase_invoice_id = ?");
      $stii->bind_param("i", $invoice_id);
      $stii->execute();
      $rit = $stii->get_result();
      
      if ($rit->num_rows === 0) {
        throw new Exception("لا يوجد بنود في هذه الفاتورة للاستلام.");
      }

      $has_qty = false;
      $rit->data_seek(0);
      while ($tmp = $rit->fetch_assoc()) {
        if ((float)($tmp['quantity'] ?? 0) > 0) { $has_qty = true; break; }
      }
      $rit->data_seek(0);
      if (!$has_qty) {
        throw new Exception("كل بنود الفاتورة فارغة أو بكميات صفرية — لا يمكن استلام فاتورة فارغة.");
      }

      $stmt_update_product = $conn->prepare("UPDATE products SET current_stock = current_stock + ? WHERE id = ?");
      $stmt_insert_batch_with_sale = $conn->prepare("INSERT INTO batches (product_id, qty, remaining, original_qty, unit_cost, sale_price, received_at, source_invoice_id, source_item_id, status, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, NOW(), NOW())");
      if (!$stmt_insert_batch_with_sale) throw new Exception('prepare insert batch with sale failed: ' . $conn->error);
      
      $stmt_update_item = $conn->prepare("UPDATE purchase_invoice_items SET qty_received = ?, batch_id = ? WHERE id = ?");
      if (!$stmt_update_product || !$stmt_update_item) throw new Exception("فشل تحضير استعلامات داخليّة: " . $conn->error);

      while ($it = $rit->fetch_assoc()) {
        $item_id = intval($it['id']);
        $product_id = intval($it['product_id']);
        $qty = (float)$it['quantity'];
        $unit_cost = (float)$it['cost_price_per_unit'];
        $item_sale_price = isset($it['sale_price']) ? (is_null($it['sale_price']) ? null : (float)$it['sale_price']) : null;
        if ($qty <= 0) continue;

        $st_find_rev = $conn->prepare("SELECT id, qty, remaining, original_qty, unit_cost, sale_price, status FROM batches WHERE source_item_id = ? AND status = 'reverted' LIMIT 1 FOR UPDATE");
        if ($st_find_rev) {
          $st_find_rev->bind_param("i", $item_id);
          $st_find_rev->execute();
          $existing_rev = $st_find_rev->get_result()->fetch_assoc();
          $st_find_rev->close();
        } else {
          $existing_rev = null;
        }

        if ($existing_rev && isset($existing_rev['id'])) {
          $bid = intval($existing_rev['id']);
          $new_qty = (float)$qty;
          $new_remaining = $new_qty;
          $new_original = $new_qty;

          if (!$stmt_update_product->bind_param("di", $new_qty, $product_id) || !$stmt_update_product->execute()) {
            throw new Exception('فشل تحديث المنتج: ' . $stmt_update_product->error);
          }

          $adj_by = $current_user_id;

          if ($item_sale_price === null) {
            $upb = $conn->prepare(
              "UPDATE batches
                 SET qty = ?, remaining = ?, original_qty = ?, unit_cost = ?, sale_price = NULL,
                     status = 'active', adjusted_by = ?, adjusted_at = NOW()
               WHERE id = ?"
            );
            if (!$upb) throw new Exception("فشل تحضير تحديث الدفعة (null sale_price): " . $conn->error);
            if (!$upb->bind_param("ddddii", $new_qty, $new_remaining, $new_original, $unit_cost, $adj_by, $bid) || !$upb->execute()) {
              $err = $upb->error ?: $conn->error;
              $upb->close();
              throw new Exception("فشل تحديث الدفعة (null sale_price): " . $err);
            }
            $upb->close();
          } else {
            $upb = $conn->prepare(
              "UPDATE batches
                 SET qty = ?, remaining = ?, original_qty = ?, unit_cost = ?, sale_price = ?,
                     status = 'active', adjusted_by = ?, adjusted_at = NOW()
               WHERE id = ?"
            );
            if (!$upb) throw new Exception("فشل تحضير تحديث الدفعة (with sale_price): " . $conn->error);
            if (!$upb->bind_param("dddddii", $new_qty, $new_remaining, $new_original, $unit_cost, $item_sale_price, $adj_by, $bid) || !$upb->execute()) {
              $err = $upb->error ?: $conn->error;
              $upb->close();
              throw new Exception("فشل تحديث الدفعة (with sale_price): " . $err);
            }
            $upb->close();
          }

          $new_batch_id = $bid;
          if (!$stmt_update_item->bind_param("dii", $new_qty, $new_batch_id, $item_id) || !$stmt_update_item->execute()) {
            throw new Exception('فشل ربط البند بالدفعة: ' . $stmt_update_item->error);
          }
          continue;
        }

        if (!$stmt_update_product->bind_param("di", $qty, $product_id) || !$stmt_update_product->execute()) {
          throw new Exception('فشل تحديث المنتج: ' . $stmt_update_product->error);
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
          if (!$insq) throw new Exception('فشل تحضير إدخال الدفعة (null sale): ' . $conn->error);
          stmt_bind_params($insq, "iddddsiii", [$b_product_id, $b_qty, $b_remaining, $b_original, $b_unit_cost, $b_received_at, $b_source_invoice_id, $b_source_item_id, $b_created_by]);
          if (!$insq->execute()) {
            $insq->close();
            throw new Exception('فشل إدخال الدفعة (null sale exec): ' . $insq->error);
          }
          $new_batch_id = $insq->insert_id;
          $insq->close();
        } else {
          stmt_bind_params($stmt_insert_batch_with_sale, "idddddsiii", [$b_product_id, $b_qty, $b_remaining, $b_original, $b_unit_cost, $item_sale_price, $b_received_at, $b_source_invoice_id, $b_source_item_id, $b_created_by]);
          if (!$stmt_insert_batch_with_sale->execute()) throw new Exception('فشل إدخال الدفعة: ' . $stmt_insert_batch_with_sale->error);
          $new_batch_id = $stmt_insert_batch_with_sale->insert_id;
        }

        if (!$stmt_update_item->bind_param("dii", $qty, $new_batch_id, $item_id) || !$stmt_update_item->execute()) {
          throw new Exception('فشل تحديث بند الفاتورة بعد إنشاء الدفعة: ' . $stmt_update_item->error);
        }
      }

      $stup = $conn->prepare("UPDATE purchase_invoices SET status = 'fully_received', updated_by = ?, updated_at = NOW() WHERE id = ?");
      $upd_by = $current_user_id;
      $stup->bind_param("ii", $upd_by, $invoice_id);
      if (!$stup->execute()) throw new Exception('فشل تحديث حالة الفاتورة: ' . $stup->error);
      $stup->close();

      $conn->commit();
      $_SESSION['message'] = "<div class='alert alert-success'>تم استلام الفاتورة وإنشاء/تحديث الدُفعات وتحديث المخزون بنجاح.</div>";
    } catch (Exception $e) {
      $conn->rollback();
      error_log('Receive invoice error: ' . $e->getMessage());
      $_SESSION['message'] = "<div class='alert alert-danger'>فشل استلام الفاتورة: " . e($e->getMessage()) . "</div>";
    }

    header("Location: " . basename(__FILE__));
    exit;
  }

  // ----- REVERT -----
  if (isset($_POST['change_invoice_status']) && isset($_POST['new_status']) && $_POST['new_status'] === 'pending') {
    $invoice_id = intval($_POST['purchase_invoice_id'] ?? 0);
    $reason = trim($_POST['reason'] ?? '');
    if ($invoice_id <= 0) {
      $_SESSION['message'] = "<div class='alert alert-danger'>معرف غير صالح.</div>";
      header("Location: " . basename(__FILE__));
      exit;
    }
    if ($reason === '') {
      $_SESSION['message'] = "<div class='alert alert-warning'>الرجاء إدخال سبب الإرجاع.</div>";
      header("Location: " . basename(__FILE__));
      exit;
    }

    $conn->begin_transaction();
    try {
      $stb = $conn->prepare("SELECT id, product_id, qty, remaining, original_qty, status FROM batches WHERE source_invoice_id = ? FOR UPDATE");
      if (!$stb) throw new Exception("فشل تحضير استعلام الدُفعات: " . $conn->error);
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
      if (!$upd_batch || !$upd_prod) throw new Exception("فشل تحضير استعلامات التراجع: " . $conn->error);

      foreach ($batches as $b) {
        $bid = intval($b['id']);
        $pid = intval($b['product_id']);
        $qty = (float)$b['qty'];

        $qty_f = $qty;
        $pid_i = $pid;
        if (!$upd_prod->bind_param("di", $qty_f, $pid_i) || !$upd_prod->execute()) {
          throw new Exception("فشل تحديث رصيد المنتج أثناء التراجع: " . $upd_prod->error);
        }
        $reason_s = $reason;
        $bid_i = $bid;
        if (!$upd_batch->bind_param("si", $reason_s, $bid_i) || !$upd_batch->execute()) {
          throw new Exception("فشل تحديث الدفعة أثناء التراجع: " . $upd_batch->error);
        }
      }

      $rst = $conn->prepare("UPDATE purchase_invoice_items SET qty_received = 0, batch_id = NULL WHERE purchase_invoice_id = ?");
      $rst->bind_param("i", $invoice_id);
      $rst->execute();
      $rst->close();

      $u = $conn->prepare("UPDATE purchase_invoices SET status = 'pending', revert_reason = ?, updated_by = ?, updated_at = NOW() WHERE id = ?");
      $u_by = $current_user_id;
      $u->bind_param("sii", $reason, $u_by, $invoice_id);
      $u->execute();
      $u->close();

      $now = date('Y-m-d H:i:s');
      $note_line = "[" . $now . "] إرجاع إلى قيد الانتظار: " . $reason . " (المحرر: " . e($current_user_name) . ")\n";
      append_invoice_note($conn, $invoice_id, $note_line);

      $conn->commit();
      $_SESSION['message'] = "<div class='alert alert-success'>تم إرجاع الفاتورة إلى قيد الانتظار.</div>";
    } catch (Exception $e) {
      $conn->rollback();
      error_log('Revert invoice error: ' . $e->getMessage());
      $_SESSION['message'] = "<div class='alert alert-danger'>فشل إعادة الفاتورة: " . e($e->getMessage()) . "</div>";
    }

    header("Location: " . basename(__FILE__));
    exit;
  }

  // ----- CANCEL -----
  if (isset($_POST['cancel_purchase_invoice'])) {
    $invoice_id = intval($_POST['purchase_invoice_id'] ?? 0);
    $reason = trim($_POST['reason'] ?? '');
    if ($invoice_id <= 0) {
      $_SESSION['message'] = "<div class='alert alert-danger'>معرف غير صالح.</div>";
      header("Location: " . basename(__FILE__));
      exit;
    }
    if ($reason === '') {
      $_SESSION['message'] = "<div class='alert alert-warning'>الرجاء إدخال سبب الإلغاء.</div>";
      header("Location: " . basename(__FILE__));
      exit;
    }

    $conn->begin_transaction();
    try {
      $st = $conn->prepare("SELECT status FROM purchase_invoices WHERE id = ? FOR UPDATE");
      $st->bind_param("i", $invoice_id);
      $st->execute();
      $r = $st->get_result()->fetch_assoc();
      $st->close();
      if (!$r) {
        $_SESSION['message'] = "<div class='alert alert-danger'>الفاتورة غير موجودة.</div>";
        header("Location: " . basename(__FILE__));
        exit;
      }
      if ($r['status'] === 'fully_received') {
        $_SESSION['message'] = "<div class='alert alert-warning'>لا يمكن إلغاء فاتورة تم استلامها بالكامل. الرجاء إجراء تراجع أولاً.</div>";
        header("Location: " . basename(__FILE__));
        exit;
      }

      $upd = $conn->prepare("UPDATE purchase_invoices SET status = 'cancelled', cancel_reason = ?, updated_by = ?, updated_at = NOW() WHERE id = ?");
      $upd_by = $current_user_id;
      $upd->bind_param("sii", $reason, $upd_by, $invoice_id);
      $upd->execute();
      $upd->close();

      $upd_b = $conn->prepare("
        UPDATE batches
           SET status = 'cancelled',
               cancel_reason = ?,
               revert_reason = NULL,
               updated_at = NOW()
         WHERE source_invoice_id = ? AND status IN ('active','reverted')
      ");
      $upd_b->bind_param("si", $reason, $invoice_id);
      $upd_b->execute();
      $upd_b->close();

      $now = date('Y-m-d H:i:s');
      $note_line = "[" . $now . "] إلغاء الفاتورة: " . $reason . " (المحرر: " . e($current_user_name) . ")\n";
      append_invoice_note($conn, $invoice_id, $note_line);

      $conn->commit();
      $_SESSION['message'] = "<div class='alert alert-success'>تم إلغاء الفاتورة.</div>";
    } catch (Exception $e) {
      $conn->rollback();
      error_log('Cancel invoice error: ' . $e->getMessage());
      $_SESSION['message'] = "<div class='alert alert-danger'>فشل الإلغاء.</div>";
    }
    header("Location: " . basename(__FILE__));
    exit;
  }

  // ----- DELETE single invoice item -----
  if (isset($_POST['delete_invoice_item'])) {
    $invoice_id = intval($_POST['invoice_id'] ?? 0);
    $item_id = intval($_POST['item_id'] ?? 0);
    $reason = trim($_POST['reason'] ?? '');
    if ($invoice_id <= 0 || $item_id <= 0) {
      $_SESSION['message'] = "<div class='alert alert-danger'>بيانات غير صالحة.</div>";
      header("Location: " . basename(__FILE__));
      exit;
    }

    $conn->begin_transaction();
    try {
      $st = $conn->prepare("SELECT status FROM purchase_invoices WHERE id = ? FOR UPDATE");
      $st->bind_param("i", $invoice_id);
      $st->execute();
      $inv = $st->get_result()->fetch_assoc();
      $st->close();
      if (!$inv) throw new Exception('الفاتورة غير موجودة');
      if ($inv['status'] !== 'pending') throw new Exception('لا يمكن حذف بند إلا في حالة قيد الانتظار');

      $sti = $conn->prepare("
        SELECT p.name AS product_name, i.quantity, i.product_id
        FROM purchase_invoice_items i
        JOIN products p ON p.id = i.product_id
        WHERE i.id = ?
      ");
      $sti->bind_param("i", $item_id);
      $sti->execute();
      $it = $sti->get_result()->fetch_assoc();
      $sti->close();

      $del = $conn->prepare("DELETE FROM purchase_invoice_items WHERE id = ?");
      $del->bind_param("i", $item_id);
      $del->execute();
      $del->close();

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

      $now = date('Y-m-d H:i:s');
      $product_name = $it['product_name'] ?? ("ID:" . $it['product_id']);
      $note_line = "[" . $now . "] حذف بند (#{$item_id}) - المنتج: {$product_name}, الكمية: {$it['quantity']}. السبب: " . 
                   ($reason === '' ? 'لم يُذكر' : $reason) . 
                   " (المحرر: " . e($current_user_name) . ")\n";
      append_invoice_note($conn, $invoice_id, $note_line);

      $conn->commit();
      $_SESSION['message'] = "<div class='alert alert-success'>تم حذف البند وتحديث المجموع.</div>";
    } catch (Exception $e) {
      $conn->rollback();
      $_SESSION['message'] = "<div class='alert alert-danger'>فشل حذف البند: " . e($e->getMessage()) . "</div>";
    }

    header("Location: " . basename(__FILE__));
    exit;
  }

  // ----- EDIT invoice items -----
  if (isset($_POST['edit_invoice']) && isset($_POST['invoice_id'])) {
    $invoice_id = intval($_POST['invoice_id']);
    $items_json = $_POST['items_json'] ?? '[]';
    $adjust_reason = trim($_POST['adjust_reason'] ?? '');
    $items_data = json_decode($items_json, true);
    if (!is_array($items_data)) $items_data = [];

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

        $sti = $conn->prepare("SELECT id, purchase_invoice_id, product_id, quantity, qty_received, cost_price_per_unit, sale_price FROM purchase_invoice_items WHERE id = ? FOR UPDATE");
        $sti->bind_param("i", $item_id);
        $sti->execute();
        $row = $sti->get_result()->fetch_assoc();
        $sti->close();
        if (!$row) throw new Exception("بند غير موجود: #$item_id");
        $old_qty = (float)$row['quantity'];
        $prod_id = intval($row['product_id']);

        if ($inv['status'] === 'pending') {
          $diff = $new_qty - $old_qty;
          $qty_adj = (float)$diff;
          $adj_by = $current_user_id;
          $effective_cost = ($new_cost !== null) ? (float)$new_cost : (float)($row['cost_price_per_unit'] ?? 0.0);
          $new_total_cost = $new_qty * $effective_cost;

          $upit = $conn->prepare("UPDATE purchase_invoice_items 
                                  SET quantity = ?, qty_adjusted = ?, adjustment_reason = ?, 
                                      adjusted_by = ?, adjusted_at = NOW(), total_cost = ? 
                                  WHERE id = ?");
          if (!$upit) throw new Exception("فشل تحضير تعديل البند: " . $conn->error);
          $upit->bind_param("dssidi", $new_qty, $qty_adj, $adjust_reason, $adj_by, $new_total_cost, $item_id);
          if (!$upit->execute()) {
            $upit->close();
            throw new Exception("فشل تعديل البند: " . $upit->error);
          }
          $upit->close();

          if ($new_cost !== null) {
            $stmtc = $conn->prepare("UPDATE purchase_invoice_items SET cost_price_per_unit = ? WHERE id = ?");
            $stmtc->bind_param("di", $new_cost, $item_id);
            $stmtc->execute();
            $stmtc->close();
          }
          if ($new_sale !== null) {
            $stmts = $conn->prepare("UPDATE purchase_invoice_items SET sale_price = ? WHERE id = ?");
            $stmts->bind_param("di", $new_sale, $item_id);
            $stmts->execute();
            $stmts->close();
          }
          continue;
        }

        if ($inv['status'] === 'fully_received') {
          $stb = $conn->prepare("SELECT id, qty, remaining, original_qty FROM batches WHERE source_item_id = ? FOR UPDATE");
          $stb->bind_param("i", $item_id);
          $stb->execute();
          $batch = $stb->get_result()->fetch_assoc();
          $stb->close();
          if (!$batch) throw new Exception("لا توجد دفعة مرتبطة بالبند #$item_id");
          if (((float)$batch['remaining']) < ((float)$batch['original_qty'])) throw new Exception("لا يمكن تعديل هذا البند لأن الدفعة المرتبطة به قد اُستهلكت.");

          $diff = $new_qty - $old_qty;
          $qty_adj = $diff;
          $qty_adj_str = (string)$qty_adj;
          $adj_by = $current_user_id;

          $upit = $conn->prepare("UPDATE purchase_invoice_items SET quantity = ?, qty_received = ?, qty_adjusted = ?, adjustment_reason = ?, adjusted_by = ?, adjusted_at = NOW() WHERE id = ?");
          if (!$upit) throw new Exception("فشل تحضير تعديل البند: " . $conn->error);
          $upit->bind_param("ddssii", $new_qty, $new_qty, $qty_adj_str, $adjust_reason, $adj_by, $item_id);
          if (!$upit->execute()) {
            $upit->close();
            throw new Exception("فشل تعديل البند: " . $upit->error);
          }
          $upit->close();

          $st_tot_item = $conn->prepare("UPDATE purchase_invoice_items SET total_cost = (quantity * cost_price_per_unit) WHERE id = ?");
          if (!$st_tot_item) throw new Exception("فشل تحضير تحديث total_cost: " . $conn->error);
          $st_tot_item->bind_param("i", $item_id);
          if (!$st_tot_item->execute()) {
            $st_tot_item->close();
            throw new Exception("فشل تحديث total_cost: " . $st_tot_item->error);
          }
          $st_tot_item->close();

          $new_batch_qty = (float)$batch['qty'] + $diff;
          $new_remaining = (float)$batch['remaining'] + $diff;
          $new_original = (float)$batch['original_qty'] + $diff;
          if ($new_remaining < 0) throw new Exception("التعديل يؤدي إلى قيمة متبقية سلبية");

          $adj_by_i = $current_user_id;
          $upb = $conn->prepare("UPDATE batches SET qty = ?, remaining = ?, original_qty = ?, adjusted_by = ?, adjusted_at = NOW() WHERE id = ?");
          if (!$upb) throw new Exception("فشل تحضير تحديث الدفعة: " . $conn->error);
          $upb->bind_param("ddiii", $new_batch_qty, $new_remaining, $new_original, $adj_by_i, $batch['id']);
          if (!$upb->execute()) {
            $upb->close();
            throw new Exception("فشل تحديث الدفعة: " . $upb->error);
          }
          $upb->close();

          $upprod = $conn->prepare("UPDATE products SET current_stock = current_stock + ? WHERE id = ?");
          $upprod->bind_param("di", $diff, $prod_id);
          if (!$upprod->execute()) {
            $upprod->close();
            throw new Exception("فشل تحديث المخزون: " . $upprod->error);
          }
          $upprod->close();

          if ($new_cost !== null) {
            $stmtc = $conn->prepare("UPDATE purchase_invoice_items SET cost_price_per_unit = ? WHERE id = ?");
            $stmtc->bind_param("di", $new_cost, $item_id);
            $stmtc->execute();
            $stmtc->close();

            $upb_cost = $conn->prepare("UPDATE batches SET unit_cost = ? WHERE id = ?");
            $upb_cost->bind_param("di", $new_cost, $batch['id']);
            $upb_cost->execute();
            $upb_cost->close();

            $st_tot_after_cost = $conn->prepare("UPDATE purchase_invoice_items SET total_cost = (quantity * cost_price_per_unit) WHERE id = ?");
            if (!$st_tot_after_cost) throw new Exception("فشل تحضير تحديث total_cost بعد تغيير السعر: " . $conn->error);
            $st_tot_after_cost->bind_param("i", $item_id);
            if (!$st_tot_after_cost->execute()) {
              $st_tot_after_cost->close();
              throw new Exception("فشل تحديث total_cost بعد تغيير السعر: " . $st_tot_after_cost->error);
            }
            $st_tot_after_cost->close();
          }
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

      $sttot = $conn->prepare("SELECT COALESCE(SUM(quantity * cost_price_per_unit),0) AS total FROM purchase_invoice_items WHERE purchase_invoice_id = ?");
      $sttot->bind_param("i", $invoice_id);
      $sttot->execute();
      $rt = $sttot->get_result()->fetch_assoc();
      $sttot->close();
      $new_total = (float)($rt['total'] ?? 0.0);
      $u_by = $current_user_id;
      $upinv = $conn->prepare("UPDATE purchase_invoices SET total_amount = ?, updated_by = ?, updated_at = NOW() WHERE id = ?");
      $upinv->bind_param("dii", $new_total, $u_by, $invoice_id);
      $upinv->execute();
      $upinv->close();

      if ($adjust_reason !== '') {
        $now = date('Y-m-d H:i:s');
        $note_line = "[" . $now . "] تعديل بنود: " . $adjust_reason . " (المحرر: " . e($current_user_name) . ")\n";
        append_invoice_note($conn, $invoice_id, $note_line);
      }

      $conn->commit();
      $_SESSION['message'] = "<div class='alert alert-success'>تم حفظ التعديلات بنجاح.</div>";
    } catch (Exception $e) {
      $conn->rollback();
      error_log('Edit invoice error: ' . $e->getMessage());
      $_SESSION['message'] = "<div class='alert alert-danger'>فشل حفظ التعديلات: " . e($e->getMessage()) . "</div>";
    }

    header("Location: " . basename(__FILE__));
    exit;
  }
}

// ---------- عرض الصفحة ----------
$selected_supplier_id = isset($_GET['supplier_filter_val']) ? intval($_GET['supplier_filter_val']) : '';
$selected_supplier_name = '';
$selected_status = isset($_GET['status_filter_val']) ? trim($_GET['status_filter_val']) : '';
$search_invoice_id = isset($_GET['invoice_out_id']) ? intval($_GET['invoice_out_id']) : 0;
$search_supplier_name = isset($_GET['search_supplier_name']) ? trim($_GET['search_supplier_name']) : '';

// الحصول على اسم المورد المحدد
if ($selected_supplier_id) {
  $st = $conn->prepare("SELECT name FROM suppliers WHERE id = ?");
  $st->bind_param("i", $selected_supplier_id);
  $st->execute();
  $res = $st->get_result();
  if ($row = $res->fetch_assoc()) {
    $selected_supplier_name = $row['name'];
  }
  $st->close();
}

$grand_total_all_purchases = 0;
$rs2 = $conn->query("SELECT COALESCE(SUM(total_amount),0) AS grand_total FROM purchase_invoices WHERE status != 'cancelled'");
if ($rs2) {
  $r2 = $rs2->fetch_assoc();
  $grand_total_all_purchases = (float)$r2['grand_total'];
}

// جلب الفواتير مع الفلاتر
$sql_select_invoices = "SELECT pi.id, pi.supplier_invoice_number, pi.purchase_date, pi.status, pi.total_amount, pi.created_at, s.name as supplier_name, u.username as creator_name
                        FROM purchase_invoices pi
                        JOIN suppliers s ON pi.supplier_id = s.id
                        LEFT JOIN users u ON pi.created_by = u.id";
$conds = [];
$params = [];
$types = '';

if (!empty($search_invoice_id)) {
  $conds[] = "pi.id = ?";
  $params[] = $search_invoice_id;
  $types .= 'i';
} elseif (!empty($selected_supplier_id)) {
  $conds[] = "pi.supplier_id = ?";
  $params[] = $selected_supplier_id;
  $types .= 'i';
} elseif (!empty($search_supplier_name)) {
  $conds[] = "s.name LIKE ?";
  $params[] = "%{$search_supplier_name}%";
  $types .= 's';
}

if (!empty($selected_status)) {
  $conds[] = "pi.status = ?";
  $params[] = $selected_status;
  $types .= 's';
}

if (!empty($conds)) $sql_select_invoices .= " WHERE " . implode(" AND ", $conds);
$sql_select_invoices .= " ORDER BY pi.purchase_date DESC, pi.id DESC";

$result_invoices = null;
if ($stmt_select = $conn->prepare($sql_select_invoices)) {
  if (!empty($params)) {
    stmt_bind_params($stmt_select, $types, $params);
  }
  $stmt_select->execute();
  $result_invoices = $stmt_select->get_result();
  $stmt_select->close();
} else {
  $message = "<div class='alert alert-danger'>خطأ في تحضير استعلام جلب فواتير المشتريات: " . e($conn->error) . "</div>";
}

$displayed_invoices_sum = 0;
$sql_total_displayed = "SELECT COALESCE(SUM(total_amount),0) AS total_displayed FROM purchase_invoices pi JOIN suppliers s ON s.id = pi.supplier_id WHERE 1=1";
$conds_total = [];
$params_total = [];
$types_total = '';

if (!empty($search_invoice_id)) {
  $conds_total[] = "pi.id = ?";
  $params_total[] = $search_invoice_id;
  $types_total .= 'i';
} elseif (!empty($selected_supplier_id)) {
  $conds_total[] = "pi.supplier_id = ?";
  $params_total[] = $selected_supplier_id;
  $types_total .= 'i';
} elseif (!empty($search_supplier_name)) {
  $conds_total[] = "s.name LIKE ?";
  $params_total[] = "%{$search_supplier_name}%";
  $types_total .= 's';
}

if (!empty($selected_status)) {
  $conds_total[] = "pi.status = ?";
  $params_total[] = $selected_status;
  $types_total .= 's';
}

if (!empty($conds_total)) $sql_total_displayed .= " AND " . implode(" AND ", $conds_total);
if ($stmt_total = $conn->prepare($sql_total_displayed)) {
  if (!empty($params_total)) stmt_bind_params($stmt_total, $types_total, $params_total);
  $stmt_total->execute();
  $res_t = $stmt_total->get_result();
  $rowt = $res_t->fetch_assoc();
  $displayed_invoices_sum = (float)($rowt['total_displayed'] ?? 0);
  $stmt_total->close();
}

require_once BASE_DIR . 'partials/header.php';
require_once BASE_DIR . 'partials/sidebar.php';
?>

<!-- ====== التصميم الجديد ====== -->
<style>
  :root {
    --primary: #4361ee;
    --primary-dark: #3a56d4;
    --secondary: #6c757d;
    --success: #28a745;
    --info: #17a2b8;
    --warning: #ffc107;
    --danger: #dc3545;
    --light: #f8f9fa;
    --dark: #343a40;
    --border: #dee2e6;
    --shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    --radius: 8px;
  }

  .card {
    border: none;
    border-radius: var(--radius);
    box-shadow: var(--shadow);
    margin-bottom: 1.5rem;
    background: #fff;
  }

  .card-header {
    background: #f8f9fa;
    border-bottom: 1px solid var(--border);
    padding: 1rem 1.25rem;
    border-radius: var(--radius) var(--radius) 0 0;
  }

  .card-body {
    padding: 1.25rem;
  }

  .btn {
    padding: 0.375rem 0.75rem;
    border-radius: 4px;
    font-size: 0.875rem;
    transition: all 0.2s;
  }

  .btn-primary {
    background: var(--primary);
    border-color: var(--primary);
  }

  .btn-primary:hover {
    background: var(--primary-dark);
    border-color: var(--primary-dark);
  }

  .badge {
    padding: 0.35em 0.65em;
    font-size: 0.75em;
    font-weight: 600;
    border-radius: 50rem;
  }

  .badge.bg-pending { background: linear-gradient(135deg, #f59e0b, #d97706); }
  .badge.bg-received { background: linear-gradient(135deg, #10b981, #0ea5e9); }
  .badge.bg-cancelled { background: linear-gradient(135deg, #ef4444, #dc2626); }

  .table {
    margin-bottom: 0;
  }

  .table th {
    font-weight: 600;
    background: #f8f9fa;
    border-bottom: 2px solid var(--border);
  }

  .modal-content {
    border: none;
    border-radius: var(--radius);
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
  }

  .search-container {
    position: relative;
  }

  .search-results {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    background: white;
    border: 1px solid var(--border);
    border-radius: 4px;
    max-height: 300px;
    overflow-y: auto;
    z-index: 1000;
    display: none;
  }

  .search-result-item {
    padding: 10px 15px;
    cursor: pointer;
    border-bottom: 1px solid #f0f0f0;
  }

  .search-result-item:hover {
    background: #f8f9fa;
  }

  .search-result-item:last-child {
    border-bottom: none;
  }

  .filter-card {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 20px;
    border-radius: var(--radius);
    margin-bottom: 20px;
  }

  .stat-card {
    text-align: center;
    padding: 20px;
    border-radius: var(--radius);
    margin-bottom: 20px;
  }

  .stat-card .number {
    font-size: 2.5rem;
    font-weight: 700;
  }

  .stat-card .label {
    font-size: 0.875rem;
    color: #6c757d;
  }

  .action-buttons .btn {
    margin: 0 2px;
  }
</style>

<div class="container-fluid py-4">
  <!-- Header -->
  <div class="row mb-4">
    <div class="col">
      <h2 class="mb-0"><i class="fas fa-file-invoice-dollar me-2"></i>إدارة فواتير المشتريات</h2>
      <p class="text-muted">إدارة وعرض فواتير المشتريات من الموردين</p>
    </div>
    <div class="col-auto">
      <a href="<?php echo BASE_URL; ?>admin/manage_suppliers.php" class="btn btn-primary btn-lg">
        <i class="fas fa-plus-circle me-2"></i>إنشاء فاتورة جديدة
      </a>
    </div>
  </div>

  <?php if (!empty($message)) echo $message;
  if (!empty($_SESSION['message'])) {
    echo $_SESSION['message'];
    unset($_SESSION['message']);
  } ?>

  <!-- Filters Card -->
  <div class="card">
    <div class="card-header">
      <h5 class="mb-0"><i class="fas fa-filter me-2"></i>فلاتر البحث المتقدمة</h5>
    </div>
    <div class="card-body">
      <form method="get" class="row g-3">
        <!-- البحث برقم الفاتورة -->
        <div class="col-md-3">
          <label class="form-label">بحث برقم الفاتورة</label>
          <div class="input-group">
            <span class="input-group-text"><i class="fas fa-hashtag"></i></span>
            <input type="number" name="invoice_out_id" class="form-control" placeholder="أدخل رقم الفاتورة" 
                   value="<?php echo e($search_invoice_id); ?>">
          </div>
        </div>

        <!-- البحث بالمورد (تحسين) -->
        <div class="col-md-3">
          <label class="form-label">البحث بالمورد</label>
          <div class="search-container">
            <div class="input-group">
              <span class="input-group-text"><i class="fas fa-search"></i></span>
              <input type="text" id="supplier_search" class="form-control" 
                     placeholder="ابدأ بكتابة اسم المورد..." 
                     value="<?php echo e($selected_supplier_name ?: $search_supplier_name); ?>"
                     autocomplete="off">
              <input type="hidden" name="supplier_filter_val" id="supplier_id" value="<?php echo e($selected_supplier_id); ?>">
              <input type="hidden" name="search_supplier_name" id="supplier_name" value="<?php echo e($search_supplier_name); ?>">
            </div>
            <div class="search-results" id="supplier_results"></div>
          </div>
        </div>

        <!-- فلتر الحالة -->
        <div class="col-md-3">
          <label class="form-label">حالة الفاتورة</label>
          <select name="status_filter_val" class="form-select">
            <option value="">-- جميع الحالات --</option>
            <?php foreach ($status_labels as $k => $v): ?>
              <option value="<?php echo $k; ?>" <?php echo ($selected_status == $k) ? 'selected' : ''; ?>>
                <?php echo e($v); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <!-- أزرار التحكم -->
        <div class="col-md-3 d-flex align-items-end gap-2">
          <button type="submit" class="btn btn-primary flex-fill">
            <i class="fas fa-search me-2"></i>بحث
          </button>
          <?php if ($selected_supplier_id || $selected_status || !empty($search_invoice_id) || !empty($search_supplier_name)): ?>
            <a href="<?php echo basename(__FILE__); ?>" class="btn btn-secondary">
              <i class="fas fa-times me-2"></i>مسح
            </a>
          <?php endif; ?>
        </div>
      </form>
    </div>
  </div>

  <!-- Statistics -->
  <div class="row mb-4">
    <div class="col-md-4">
      <div class="stat-card bg-light">
        <div class="number text-primary"><?php echo number_format($grand_total_all_purchases, 2); ?> ج.م</div>
        <div class="label">إجمالي المشتريات</div>
      </div>
    </div>
    <div class="col-md-4">
      <div class="stat-card bg-light">
        <div class="number text-success"><?php echo number_format($displayed_invoices_sum, 2); ?> ج.م</div>
        <div class="label">إجمالي الفواتير المعروضة</div>
      </div>
    </div>
    <div class="col-md-4">
      <div class="stat-card bg-light">
        <div class="number text-info"><?php echo $result_invoices ? $result_invoices->num_rows : 0; ?></div>
        <div class="label">عدد الفواتير</div>
      </div>
    </div>
  </div>

  <!-- Invoices Table -->
  <div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
      <h5 class="mb-0">قائمة فواتير المشتريات</h5>
      <div class="text-muted">
        <?php echo $result_invoices ? $result_invoices->num_rows : 0; ?> فاتورة
      </div>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-hover mb-0">
          <thead class="table-light">
            <tr>
              <th width="60">#</th>
              <th>المورد</th>
              <th>رقم الفاتورة</th>
              <th>تاريخ الشراء</th>
              <th>الحالة</th>
              <th class="text-end">الإجمالي</th>
              <th class="text-center">الإجراءات</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($result_invoices && $result_invoices->num_rows > 0): 
              while ($inv = $result_invoices->fetch_assoc()): ?>
                <tr>
                  <td><strong>#<?php echo e($inv['id']); ?></strong></td>
                  <td><?php echo e($inv['supplier_name']); ?></td>
                  <td><?php echo e($inv['supplier_invoice_number'] ?: '-'); ?></td>
                  <td><?php echo e(date('Y-m-d', strtotime($inv['purchase_date']))); ?></td>
                  <td>
                    <?php 
                      $status_class = '';
                      if ($inv['status'] === 'pending') $status_class = 'bg-pending';
                      elseif ($inv['status'] === 'fully_received') $status_class = 'bg-received';
                      else $status_class = 'bg-cancelled';
                    ?>
                    <span class="badge <?php echo $status_class; ?>">
                      <?php echo e($status_labels[$inv['status']] ?? $inv['status']); ?>
                    </span>
                  </td>
                  <td class="text-end fw-bold">
                    <?php echo number_format((float)$inv['total_amount'], 2); ?> ج.م
                  </td>
                  <td class="text-center action-buttons">
                    <!-- زر العرض -->
                    <button class="btn btn-sm btn-info" onclick="openInvoiceModalView(<?php echo $inv['id']; ?>)">
                      <i class="fas fa-eye"></i>
                    </button>
                    
                    <?php if ($inv['status'] === 'pending'): ?>
                      <!-- تعديل الفاتورة قيد الانتظار -->
                      <button class="btn btn-sm btn-warning" onclick="openInvoiceModalEdit(<?php echo $inv['id']; ?>)">
                        <i class="fas fa-edit"></i>
                      </button>
                      
                      <!-- استلام الفاتورة -->
                      <form method="post" style="display:inline" onsubmit="return confirm('تأكيد استلام الفاتورة بالكامل؟')">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="purchase_invoice_id" value="<?php echo $inv['id']; ?>">
                        <button type="submit" name="receive_purchase_invoice" class="btn btn-sm btn-success">
                          <i class="fas fa-check-circle"></i>
                        </button>
                      </form>
                      
                      <!-- إلغاء الفاتورة -->
                      <button class="btn btn-sm btn-danger" onclick="openReasonModal('cancel', <?php echo $inv['id']; ?>)">
                        <i class="fas fa-times-circle"></i>
                      </button>
                      
                    <?php elseif ($inv['status'] === 'fully_received'): ?>
                      <!-- تعديل الفاتورة المسلمه -->
                      <button class="btn btn-sm btn-warning" onclick="openInvoiceModalEdit(<?php echo $inv['id']; ?>)">
                        <i class="fas fa-edit"></i>
                      </button>
                      
                      <!-- زر مرتجع -->
                      <button class="btn btn-sm btn-outline-danger" onclick="openReasonModal('revert', <?php echo $inv['id']; ?>)">
                        <i class="fas fa-undo"></i> مرتجع
                      </button>
                      
                    <?php endif; ?>
                    
                    <!-- زر طباعة -->
                    <a href="<?php echo basename(__FILE__); ?>?action=print_supplier&id=<?php echo $inv['id']; ?>" 
                       target="_blank" class="btn btn-sm btn-secondary">
                      <i class="fas fa-print"></i>
                    </a>
                  </td>
                </tr>
              <?php endwhile;
            else: ?>
              <tr>
                <td colspan="7" class="text-center py-4">
                  <div class="text-muted">
                    <i class="fas fa-inbox fa-2x mb-3"></i><br>
                    لا توجد فواتير مطابقة لبحثك
                  </div>
                </td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- Modals (نفس المودالات السابقة مع تحسينات) -->
<div id="invoiceModal" class="modal fade" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">تفاصيل الفاتورة</h5>
        <button type="button" class="btn-close" onclick="closeModal('invoiceModal')"></button>
      </div>
      <div class="modal-body" id="invoiceModalBody">
        جاري التحميل...
      </div>
      <div class="modal-footer" id="invoiceModalFooter">
        <button class="btn btn-secondary" onclick="closeModal('invoiceModal')">إغلاق</button>
      </div>
    </div>
  </div>
</div>

<div id="editInvoiceModal" class="modal fade" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">تعديل بنود الفاتورة <span id="edit_inv_id"></span></h5>
        <button type="button" class="btn-close" onclick="closeModal('editInvoiceModal')"></button>
      </div>
      <div class="modal-body" id="editInvoiceBody">
        جاري التحميل...
      </div>
      <div class="modal-footer">
        <button id="btn_save_edit" class="btn btn-success">حفظ التعديلات</button>
        <button class="btn btn-secondary" onclick="closeModal('editInvoiceModal')">إلغاء</button>
      </div>
    </div>
  </div>
</div>

<div id="reasonModalBackdrop" class="modal fade" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">الرجاء إدخال السبب</h5>
        <button type="button" class="btn-close" onclick="closeModal('reasonModalBackdrop')"></button>
      </div>
      <div class="modal-body">
        <form id="reasonForm" method="post">
          <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
          <input type="hidden" name="purchase_invoice_id" id="reason_invoice_id" value="">
          <div class="mb-3">
            <label class="form-label">السبب (مطلوب)</label>
            <textarea name="reason" id="reason_text" rows="4" class="form-control" required></textarea>
          </div>
          <div class="text-end">
            <button type="submit" class="btn btn-primary">تأكيد</button>
            <button type="button" class="btn btn-secondary" onclick="closeModal('reasonModalBackdrop')">إلغاء</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<script>
// البحث الديناميكي عن الموردين
let supplierSearchTimeout;
document.getElementById('supplier_search').addEventListener('input', function(e) {
  clearTimeout(supplierSearchTimeout);
  const query = e.target.value.trim();
  
  if (query.length < 2) {
    document.getElementById('supplier_results').style.display = 'none';
    return;
  }
  
  supplierSearchTimeout = setTimeout(() => {
    fetch(`<?php echo basename(__FILE__); ?>?action=search_suppliers&q=${encodeURIComponent(query)}`)
      .then(response => response.json())
      .then(data => {
        const resultsContainer = document.getElementById('supplier_results');
        resultsContainer.innerHTML = '';
        
        if (data.length > 0) {
          data.forEach(supplier => {
            const div = document.createElement('div');
            div.className = 'search-result-item';
            div.textContent = supplier.name;
            div.onclick = () => {
              document.getElementById('supplier_id').value = supplier.id;
              document.getElementById('supplier_search').value = supplier.name;
              document.getElementById('supplier_name').value = '';
              resultsContainer.style.display = 'none';
            };
            resultsContainer.appendChild(div);
          });
          resultsContainer.style.display = 'block';
        } else {
          const div = document.createElement('div');
          div.className = 'search-result-item text-muted';
          div.textContent = 'لا توجد نتائج';
          resultsContainer.appendChild(div);
          resultsContainer.style.display = 'block';
        }
      });
  }, 300);
});

// إخفاء نتائج البحث عند النقر خارجها
document.addEventListener('click', function(e) {
  if (!e.target.closest('.search-container')) {
    document.getElementById('supplier_results').style.display = 'none';
  }
});

// دالة لإغلاق المودالات
function closeModal(modalId) {
  const modal = document.getElementById(modalId);
  if (modal) {
    modal.classList.remove('show');
    modal.style.display = 'none';
    document.body.classList.remove('modal-open');
    const backdrop = document.querySelector('.modal-backdrop');
    if (backdrop) backdrop.remove();
  }
}

// دالة لفتح المودالات
function openModal(modalId) {
  const modal = document.getElementById(modalId);
  if (modal) {
    modal.classList.add('show');
    modal.style.display = 'block';
    document.body.classList.add('modal-open');
    const backdrop = document.createElement('div');
    backdrop.className = 'modal-backdrop fade show';
    document.body.appendChild(backdrop);
  }
}

// بقية دوال JavaScript كما هي مع بعض التعديلات البسيطة
const ajaxUrl = '<?php echo basename(__FILE__); ?>';
const CSRF_TOKEN = <?php echo json_encode($csrf_token); ?>;

async function openInvoiceModalView(id) {
  openModal('invoiceModal');
  const body = document.getElementById('invoiceModalBody');
  body.innerHTML = '<div class="text-center p-5"><div class="spinner-border text-primary" role="status"></div><p class="mt-2">جارٍ تحميل بيانات الفاتورة...</p></div>';
  
  try {
    const res = await fetch(ajaxUrl + '?action=fetch_invoice_json&id=' + encodeURIComponent(id));
    const data = await res.json();
    
    if (!data.ok) {
      body.innerHTML = '<div class="alert alert-danger">فشل جلب البيانات.</div>';
      return;
    }
    
    // بناء واجهة عرض الفاتورة
    const inv = data.invoice || {};
    const items = data.items || [];
    const batches = data.batches || [];
    
    let html = `
      <div class="row mb-4">
        <div class="col-md-6">
          <h5>فاتورة مشتريات #${inv.id}</h5>
          <p class="text-muted mb-1">التاريخ: ${inv.purchase_date || ''}</p>
          <p class="text-muted">المورد: ${inv.supplier_name || ''}</p>
        </div>
        <div class="col-md-6 text-end">
          <span class="badge ${inv.status === 'pending' ? 'bg-pending' : inv.status === 'fully_received' ? 'bg-received' : 'bg-cancelled'} fs-6">
            ${data.status_labels[inv.status] || inv.status}
          </span>
          <h3 class="mt-2 text-primary">${parseFloat(inv.total_amount || 0).toFixed(2)} ج.م</h3>
        </div>
      </div>
      
      <div class="mb-4">
        <h6>ملاحظات:</h6>
        <div class="card card-body bg-light">
          ${inv.notes ? inv.notes.replace(/\n/g, '<br>') : 'لا توجد ملاحظات'}
        </div>
      </div>
      
      <h6 class="mb-3">بنود الفاتورة:</h6>
      <div class="table-responsive">
        <table class="table table-bordered">
          <thead class="table-light">
            <tr>
              <th>#</th><th>المنتج</th><th>الكمية</th><th>سعر الشراء</th><th>سعر البيع</th><th>مستلم</th><th>الإجمالي</th>
            </tr>
          </thead>
          <tbody>
    `;
    
    let total = 0;
    items.forEach((it, idx) => {
      const lineTotal = parseFloat(it.total_cost || (it.quantity * it.cost_price_per_unit) || 0);
      total += lineTotal;
      html += `
        <tr>
          <td>${idx + 1}</td>
          <td>${it.product_name || ('#' + it.product_id)}</td>
          <td class="text-center">${parseFloat(it.quantity || 0).toFixed(2)}</td>
          <td class="text-end">${parseFloat(it.cost_price_per_unit || 0).toFixed(2)} ج.م</td>
          <td class="text-end">${it.sale_price ? parseFloat(it.sale_price).toFixed(2) + ' ج.م' : '-'}</td>
          <td class="text-center">${parseFloat(it.qty_received || 0).toFixed(2)}</td>
          <td class="text-end fw-bold">${lineTotal.toFixed(2)} ج.م</td>
        </tr>
      `;
    });
    
    html += `
          </tbody>
          <tfoot class="table-light">
            <tr>
              <td colspan="6" class="text-end fw-bold">المجموع:</td>
              <td class="text-end fw-bold">${total.toFixed(2)} ج.م</td>
            </tr>
          </tfoot>
        </table>
      </div>
    `;
    
    if (batches && batches.length > 0) {
      html += `
        <h6 class="mb-3 mt-4">الدفعات المرتبطة:</h6>
        <div class="table-responsive">
          <table class="table table-bordered">
            <thead class="table-light">
              <tr>
                <th>#</th><th>المنتج</th><th>الكمية</th><th>المتبقي</th><th>سعر الشراء</th><th>سعر البيع</th><th>الحالة</th>
              </tr>
            </thead>
            <tbody>
      `;
      
      batches.forEach((b, idx) => {
        const item = items[idx] || {};
        html += `
          <tr>
            <td>${b.id}</td>
            <td>${item.product_name || ('#' + b.product_id)}</td>
            <td>${parseFloat(b.qty || 0).toFixed(2)}</td>
            <td>${parseFloat(b.remaining || 0).toFixed(2)}</td>
            <td>${b.unit_cost ? parseFloat(b.unit_cost).toFixed(2) + ' ج.م' : '-'}</td>
            <td>${b.sale_price ? parseFloat(b.sale_price).toFixed(2) + ' ج.م' : '-'}</td>
            <td><span class="badge ${b.status === 'active' ? 'bg-success' : b.status === 'reverted' ? 'bg-warning' : 'bg-danger'}">${b.status}</span></td>
          </tr>
        `;
      });
      
      html += `
            </tbody>
          </table>
        </div>
      `;
    }
    
    body.innerHTML = html;
    
  } catch (err) {
    console.error(err);
    body.innerHTML = '<div class="alert alert-danger">فشل الاتصال بالخادم.</div>';
  }
}

async function openInvoiceModalEdit(id) {
  openModal('editInvoiceModal');
  const body = document.getElementById('editInvoiceBody');
  const footerSave = document.getElementById('btn_save_edit');
  
  body.innerHTML = '<div class="text-center p-5"><div class="spinner-border text-primary" role="status"></div><p class="mt-2">جارٍ تحميل البيانات للتعديل...</p></div>';
  footerSave.onclick = null;
  
  try {
    const res = await fetch(ajaxUrl + '?action=fetch_invoice_json&id=' + encodeURIComponent(id));
    const data = await res.json();
    
    if (!data.ok) {
      body.innerHTML = '<div class="alert alert-danger">فشل جلب البيانات.</div>';
      return;
    }
    
    const inv = data.invoice || {};
    const items = data.items || [];
    const canEdit = data.can_edit;
    
    if (!canEdit) {
      body.innerHTML = '<div class="alert alert-warning">لا يمكن التعديل لأن الدُفعات مستهلكة أو الحالة لا تسمح.</div>';
      return;
    }
    
    const allowDelete = (String(inv.status).trim() === 'pending');
    document.getElementById('edit_inv_id').textContent = '#' + inv.id;
    
    let html = `
      <div class="alert alert-info mb-3">
        <i class="fas fa-info-circle me-2"></i>
        يمكنك تعديل الكميات والأسعار. سيتم تحديث الدفعات المرتبطة تلقائياً.
      </div>
      
      <div class="table-responsive">
        <table class="table table-bordered">
          <thead class="table-light">
            <tr>
              <th>#</th><th>المنتج</th><th>الكمية الحالية</th><th>الكمية الجديدة</th>
              <th>سعر الشراء الحالي</th><th>سعر الشراء الجديد</th>
              <th>سعر البيع الحالي</th><th>سعر البيع الجديد</th>
              ${allowDelete ? '<th>حذف</th>' : ''}
            </tr>
          </thead>
          <tbody>
    `;
    
    items.forEach((it, idx) => {
      const curQty = parseFloat(it.quantity || 0).toFixed(2);
      const curCost = parseFloat(it.cost_price_per_unit || 0).toFixed(2);
      const curSale = (it.sale_price !== undefined && it.sale_price !== null) ? parseFloat(it.sale_price).toFixed(2) : '';
      
      html += `
        <tr>
          <td>${idx + 1}</td>
          <td>${it.product_name || ('#' + it.product_id)}</td>
          <td class="text-center">${curQty}</td>
          <td><input class="form-control edit-item-qty" type="number" step="0.01" value="${curQty}" 
                     data-item-id="${it.id || ''}"></td>
          <td class="text-end">${curCost}</td>
          <td><input class="form-control edit-item-cost" type="number" step="0.01" value="${curCost}" 
                     data-item-id="${it.id || ''}"></td>
          <td class="text-end">${curSale ? curSale + ' ج.م' : '-'}</td>
          <td><input class="form-control edit-item-sale" type="number" step="0.01" 
                     value="${curSale ? curSale : ''}" data-item-id="${it.id || ''}"></td>
          ${allowDelete ? `
            <td class="text-center">
              <button type="button" class="btn btn-danger btn-sm js-delete-item" 
                      data-item-id="${it.id || ''}">
                <i class="fas fa-trash"></i>
              </button>
            </td>
          ` : ''}
        </tr>
      `;
    });
    
    html += `
          </tbody>
        </table>
      </div>
      
      <div class="mt-3">
        <label class="form-label fw-bold">سبب التعديل (مطلوب)</label>
        <textarea id="js_adjust_reason" rows="3" class="form-control" 
                  placeholder="أدخل سبب التعديل..."></textarea>
      </div>
    `;
    
    body.innerHTML = html;
    
    // إضافة أحداث الحذف
    if (allowDelete) {
      document.querySelectorAll('.js-delete-item').forEach(btn => {
        btn.onclick = function() {
          const itemId = this.dataset.itemId;
          if (!itemId) return;
          
          const reason = prompt('أدخل سبب الحذف (مطلوب):');
          if (!reason || !reason.trim()) {
            alert('العملية أُلغيت — سبب مطلوب');
            return;
          }
          
          const f = document.createElement('form');
          f.method = 'POST';
          f.action = ajaxUrl;
          f.style.display = 'none';
          
          const fields = [
            ['delete_invoice_item', '1'],
            ['invoice_id', id],
            ['item_id', itemId],
            ['reason', reason],
            ['csrf_token', CSRF_TOKEN]
          ];
          
          fields.forEach(([name, value]) => {
            const i = document.createElement('input');
            i.type = 'hidden';
            i.name = name;
            i.value = value;
            f.appendChild(i);
          });
          
          document.body.appendChild(f);
          f.submit();
        };
      });
    }
    
    footerSave.onclick = function() {
      const inputsQty = document.querySelectorAll('.edit-item-qty');
      const inputsCost = document.querySelectorAll('.edit-item-cost');
      const inputsSale = document.querySelectorAll('.edit-item-sale');
      const mapById = {};
      
      inputsQty.forEach(i => {
        const id = i.dataset.itemId;
        if (!id) return;
        mapById[id] = mapById[id] || {};
        mapById[id].new_quantity = parseFloat(i.value || 0);
      });
      
      inputsCost.forEach(i => {
        const id = i.dataset.itemId;
        if (!id) return;
        mapById[id] = mapById[id] || {};
        mapById[id].new_cost_price = parseFloat(i.value || 0);
      });
      
      inputsSale.forEach(i => {
        const id = i.dataset.itemId;
        if (!id) return;
        mapById[id] = mapById[id] || {};
        mapById[id].new_sale_price = (i.value === '') ? null : parseFloat(i.value || 0);
      });
      
      const itemsPayload = [];
      for (const k in mapById) {
        const obj = mapById[k];
        obj.item_id = parseInt(k, 10);
        itemsPayload.push(obj);
      }
      
      const adjustReason = document.getElementById('js_adjust_reason').value.trim();
      if (!adjustReason) {
        alert('الرجاء إدخال سبب التعديل');
        document.getElementById('js_adjust_reason').focus();
        return;
      }
      
      if (itemsPayload.length === 0) {
        alert('لا توجد بنود للتعديل');
        return;
      }
      
      const form = document.createElement('form');
      form.method = 'POST';
      form.action = ajaxUrl;
      form.style.display = 'none';
      
      const fields = [
        ['edit_invoice', '1'],
        ['invoice_id', id],
        ['items_json', JSON.stringify(itemsPayload)],
        ['adjust_reason', adjustReason],
        ['csrf_token', CSRF_TOKEN]
      ];
      
      fields.forEach(([name, value]) => {
        const i = document.createElement('input');
        i.type = 'hidden';
        i.name = name;
        i.value = value;
        form.appendChild(i);
      });
      
      document.body.appendChild(form);
      form.submit();
    };
    
  } catch (err) {
    console.error(err);
    body.innerHTML = '<div class="alert alert-danger">فشل الاتصال بالخادم.</div>';
  }
}

function openReasonModal(action, invoiceId) {
  openModal('reasonModalBackdrop');
  const form = document.getElementById('reasonForm');
  
  ['change_invoice_status', 'cancel_purchase_invoice', 'new_status'].forEach(n => {
    const elOld = form.querySelector('input[name="' + n + '"]');
    if (elOld) elOld.remove();
  });
  
  if (action === 'revert') {
    const a = document.createElement('input');
    a.type = 'hidden';
    a.name = 'change_invoice_status';
    a.value = '1';
    form.appendChild(a);
    
    const b = document.createElement('input');
    b.type = 'hidden';
    b.name = 'new_status';
    b.value = 'pending';
    form.appendChild(b);
  } else {
    const a = document.createElement('input');
    a.type = 'hidden';
    a.name = 'cancel_purchase_invoice';
    a.value = '1';
    form.appendChild(a);
  }
  
  form.querySelector('#reason_invoice_id').value = invoiceId;
  form.querySelector('#reason_text').value = '';
  
  let csrf = form.querySelector('input[name="csrf_token"]');
  if (!csrf) {
    csrf = document.createElement('input');
    csrf.type = 'hidden';
    csrf.name = 'csrf_token';
    csrf.value = CSRF_TOKEN;
    form.appendChild(csrf);
  }
}

// تعيين الدوال للعامة
window.openInvoiceModalView = openInvoiceModalView;
window.openInvoiceModalEdit = openInvoiceModalEdit;
window.openReasonModal = openReasonModal;
window.closeModal = closeModal;
</script>

<?php
require_once BASE_DIR . 'partials/footer.php';
$conn->close();
?>