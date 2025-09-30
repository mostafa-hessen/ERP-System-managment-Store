<?php
// manage_purchase_invoices.fixed.php
// مُعدَّل بناءً على طلب المستخدم: تسجيل أسباب التعديل، حذف بنود مختارة، تمرير sale_price إلى batches عند الاستلام، تزامن تغييرات أسعار البيع/الشراء بين purchase_invoice_items و batches، تحديث حالة الدُفعات عند الإلغاء، طباعة فاتورة المورد بدون عرض الدُفعات، وتحسين bind_param الديناميكي.

// -- تحذير: خذ نسخة احتياطية قبل وضع الملف في الإنتاج --

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
  $ok =true;
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
  // mysqli::bind_param requires references
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
      // cast numeric fields
      $bb['qty'] = (float)$bb['qty'];
      $bb['remaining'] = (float)$bb['remaining'];
      $bb['original_qty'] = (float)$bb['original_qty'];
      $bb['unit_cost'] = isset($bb['unit_cost']) ? (float)$bb['unit_cost'] : null;
      $bb['sale_price'] = isset($bb['sale_price']) ? (is_null($bb['sale_price']) ? null : (float)$bb['sale_price']) : null;
      $batches[] = $bb;
    }
    $stb->close();
  }

  // can_edit / can_revert logic: pending => yes; fully_received => yes only if batches unconsumed and active
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

  // also return invoice notes
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

// ---------- Print view (supplier-facing, without batches) ----------
if (isset($_GET['action']) && $_GET['action'] === 'print_supplier' && isset($_GET['id'])) {
  $inv_id = intval($_GET['id']);
  if ($inv_id <= 0) {
    echo "Invalid invoice id";
    exit;
  }
  // fetch invoice + items (no batches)
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
  // output minimal printable HTML
?>
  <!doctype html>
  <html lang="ar" dir="rtl">

  <head>
    <meta charset="utf-8">
    <title>طباعة فاتورة المورد - <?php echo e($inv['supplier_name']); ?></title>
    <style>
      body {
        font-family: Tahoma, Arial;
        direction: rtl;
      }

      .sheet {
        width: 210mm;
        margin: 10mm auto;
      }

      table {
        width: 100%;
        border-collapse: collapse
      }

      th,
      td {
        padding: 6px;
        border: 1px solid #333
      }

      .no-batches-note {
        font-size: 12px;
        color: #666
      }

      @media print {
        .no-print {
          display: none
        }
      }
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
            <th>المنتج</th>
            <th>الكمية</th>
            <th>سعر التكلفة</th>
            <th>سعر البيع (إن وُجد)</th>
            <th>الإجمالي</th>
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
      <div style="margin-top:20px">ملاحظات:
        <pre><?php echo e($inv['notes'] ?? ''); ?></pre>
      </div>
      <div class="no-print" style="margin-top:20px"><button onclick="window.print()">طباعة</button></div>
    </div>
  </body>

  </html>
<?php
  exit;
}

// ---------------- POST handlers ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // CSRF
  if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    $_SESSION['message'] = "<div class='alert alert-danger'>خطأ: طلب غير صالح (CSRF).</div>";
    header("Location: " . basename(__FILE__));
    exit;
  }

  $current_user_id = intval($_SESSION['id'] ?? 0);
  $current_user_name = $_SESSION['username'] ?? ('user#' . $current_user_id);

  // ----- RECEIVE (fully) -----
  if (isset($_POST['receive_purchase_invoice'])) {
    $invoice_id = intval($_POST['purchase_invoice_id'] ?? 0);
    if ($invoice_id <= 0) {
      $_SESSION['message'] = "<div class='alert alert-danger'>معرف غير صالح.</div>";
      header("Location: " . basename(__FILE__));
      exit;
    }

    $conn->begin_transaction();
    try {
      // lock invoice
      $st = $conn->prepare("SELECT status FROM purchase_invoices WHERE id = ? FOR UPDATE");
      $st->bind_param("i", $invoice_id);
      $st->execute();
      $invrow = $st->get_result()->fetch_assoc();
      $st->close();
      if (!$invrow) throw new Exception("الفاتورة غير موجودة");
      if ($invrow['status'] === 'fully_received') throw new Exception("الفاتورة مُسلمة بالفعل");
      if ($invrow['status'] === 'cancelled') throw new Exception("الفاتورة ملغاة");

      // ensure no partial received
      $sti = $conn->prepare("SELECT id, qty_received FROM purchase_invoice_items WHERE purchase_invoice_id = ? FOR UPDATE");
      $sti->bind_param("i", $invoice_id);
      $sti->execute();
      $resi = $sti->get_result();
      while ($r = $resi->fetch_assoc()) {
        if ((float)($r['qty_received'] ?? 0) > 0) throw new Exception("تم استلام جزء من هذه الفاتورة سابقًا — لا يوجد دعم للاستلام الجزئي هنا.");
      }
      $sti->close();

      // fetch items to insert batches
      $stii = $conn->prepare("SELECT id, product_id, quantity, cost_price_per_unit, COALESCE(sale_price, NULL) AS sale_price FROM purchase_invoice_items WHERE purchase_invoice_id = ?");
      $stii->bind_param("i", $invoice_id);
      $stii->execute();
      $rit = $stii->get_result();
// بعد: $rit = $stii->get_result();
if ($rit->num_rows === 0) {
  throw new Exception("لا يوجد بنود في هذه الفاتورة للاستلام.");
}

// أو تحقق من مجموع الكميات > 0 إن أردت:
$has_qty = false;
$rit->data_seek(0);
while ($tmp = $rit->fetch_assoc()) {
  if ((float)($tmp['quantity'] ?? 0) > 0) { $has_qty = true; break; }
}
$rit->data_seek(0);
if (!$has_qty) {
  throw new Exception("كل بنود الفاتورة فارغة أو بكميات صفرية — لا يمكن استلام فاتورة فارغة.");
}

      // prepare statements
      $stmt_update_product = $conn->prepare("UPDATE products SET current_stock = current_stock + ? WHERE id = ?");
      // $batches_have_sale_price = has_column($conn,'batches','sale_price');

      // if ($batches_have_sale_price) {
        $stmt_insert_batch_with_sale = $conn->prepare("INSERT INTO batches (product_id, qty, remaining, original_qty, unit_cost, sale_price, received_at, source_invoice_id, source_item_id, status, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, NOW(), NOW())");
        if (!$stmt_insert_batch_with_sale) throw new Exception('prepare insert batch with sale failed: ' . $conn->error);
      // } else {
      //   $stmt_insert_batch_without_sale = $conn->prepare("INSERT INTO batches (product_id, qty, remaining, original_qty, unit_cost, received_at, source_invoice_id, source_item_id, status, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, NOW(), NOW())");
      //   if (!$stmt_insert_batch_without_sale) throw new Exception('prepare insert batch without sale failed: ' . $conn->error);
      // }

      $stmt_update_item = $conn->prepare("UPDATE purchase_invoice_items SET qty_received = ?, batch_id = ? WHERE id = ?");
      if (!$stmt_update_product || !$stmt_update_item) throw new Exception("فشل تحضير استعلامات داخليّة: " . $conn->error);

      while ($it = $rit->fetch_assoc()) {
        $item_id = intval($it['id']);
        $product_id = intval($it['product_id']);
        $qty = (float)$it['quantity'];
        $unit_cost = (float)$it['cost_price_per_unit'];
        $item_sale_price = isset($it['sale_price']) ? (is_null($it['sale_price']) ? null : (float)$it['sale_price']) : null;
        if ($qty <= 0) continue;

        // Try to find a reverted batch to reactivate (same source_item_id)
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
    // سنسمح بقبول التعديلات على الكمية أو سعر البيع:
    // سنقوم بكتابة القيم الجديدة على صف الدفعة (overwrite) ثم نعيد تفعيلها
    // ونزيد رصيد المنتج مرة واحدة بمقدار الكمية المطلوبة.
    $bid = intval($existing_rev['id']);
    $new_qty = (float)$qty;
    $new_remaining = $new_qty;
    $new_original = $new_qty;

    // 1) زد رصيد المنتج (لأن التراجع سبق وخصم الكمية)
    if (!$stmt_update_product->bind_param("di", $new_qty, $product_id) || !$stmt_update_product->execute()) {
        throw new Exception('فشل تحديث المنتج: ' . $stmt_update_product->error);
    }

    $adj_by = $current_user_id;

    // 2) حدّث صف الدفعة: overwrite للقيم qty, remaining, original_qty, unit_cost, sale_price (أو NULL)
    if ($item_sale_price === null) {
        $upb = $conn->prepare(
            "UPDATE batches
               SET qty = ?, remaining = ?, original_qty = ?, unit_cost = ?, sale_price = NULL,
                   status = 'active', adjusted_by = ?, adjusted_at = NOW()
             WHERE id = ?"
        );
        if (!$upb) throw new Exception("فشل تحضير تحديث الدفعة (null sale_price): " . $conn->error);
        // types: d,d,d,d,i,i  => qty, remaining, original, unit_cost, adjusted_by, id
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
        // types: d,d,d,d,d,i,i => qty,remaining,original,unit_cost,sale_price,adjusted_by,id
        if (!$upb->bind_param("dddddii", $new_qty, $new_remaining, $new_original, $unit_cost, $item_sale_price, $adj_by, $bid) || !$upb->execute()) {
            $err = $upb->error ?: $conn->error;
            $upb->close();
            throw new Exception("فشل تحديث الدفعة (with sale_price): " . $err);
        }
        $upb->close();
    }

    // 3) اربط البند بالدفعة وحدد qty_received
    $new_batch_id = $bid;
    if (!$stmt_update_item->bind_param("dii", $new_qty, $new_batch_id, $item_id) || !$stmt_update_item->execute()) {
        throw new Exception('فشل ربط البند بالدفعة: ' . $stmt_update_item->error);
    }

    // انتهى التعامل مع هذا البند
    continue;
}


        // no suitable reverted batch found => insert new batch after updating product stock
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

        // if ($batches_have_sale_price) {
          if ($item_sale_price === null) {
            // insert with explicit NULL sale_price
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
        // } else {
        //   stmt_bind_params($stmt_insert_batch_without_sale, "iddddsiii", [$b_product_id, $b_qty, $b_remaining, $b_original, $b_unit_cost, $b_received_at, $b_source_invoice_id, $b_source_item_id, $b_created_by]);
        //   if (!$stmt_insert_batch_without_sale->execute()) throw new Exception('فشل إدخال الدفعة: ' . $stmt_insert_batch_without_sale->error);
        //   $new_batch_id = $stmt_insert_batch_without_sale->insert_id;
        // }

        // update purchase_invoice_items with batch id & qty_received
        if (!$stmt_update_item->bind_param("dii", $qty, $new_batch_id, $item_id) || !$stmt_update_item->execute()) {
          throw new Exception('فشل تحديث بند الفاتورة بعد إنشاء الدفعة: ' . $stmt_update_item->error);
        }
      } // end items loop

      // update invoice status to fully_received
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

  // ----- CHANGE STATUS => pending (revert) -----
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
      // lock batches for invoice
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

      // reset items qty_received and batch linkage
      $rst = $conn->prepare("UPDATE purchase_invoice_items SET qty_received = 0, batch_id = NULL WHERE purchase_invoice_id = ?");
      $rst->bind_param("i", $invoice_id);
      $rst->execute();
      $rst->close();

      // update invoice
      $u = $conn->prepare("UPDATE purchase_invoices SET status = 'pending', revert_reason = ?, updated_by = ?, updated_at = NOW() WHERE id = ?");
      $u_by = $current_user_id;
      $u->bind_param("sii", $reason, $u_by, $invoice_id);
      $u->execute();
      $u->close();

      // append reason to notes
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

  // ----- CANCEL invoice (soft) -----
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

      // update invoice
      $upd = $conn->prepare("UPDATE purchase_invoices SET status = 'cancelled', cancel_reason = ?, updated_by = ?, updated_at = NOW() WHERE id = ?");
      $upd_by = $current_user_id;
      $upd->bind_param("sii", $reason, $upd_by, $invoice_id);
      $upd->execute();
      $upd->close();

      // update related batches: mark cancelled and save reason
      // $upd_b = $conn->prepare("UPDATE batches SET status = 'cancelled', cancel_reason = ?, updated_at = NOW() WHERE source_invoice_id = ? AND status = 'active'");
      // $upd_b->bind_param("si", $reason, $invoice_id);
      // $upd_b->execute();
      // $upd_b->close();


      // update related batches: mark cancelled and save reason (including reverted ones)
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


      // append cancel reason to invoice notes
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

  // ----- DELETE single invoice item (pending only) -----
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
      // check invoice status
      $st = $conn->prepare("SELECT status FROM purchase_invoices WHERE id = ? FOR UPDATE");
      $st->bind_param("i", $invoice_id);
      $st->execute();
      $inv = $st->get_result()->fetch_assoc();
      $st->close();
      if (!$inv) throw new Exception('الفاتورة غير موجودة');
      if ($inv['status'] !== 'pending') throw new Exception('لا يمكن حذف بند إلا في حالة قيد الانتظار');

      // get item info to show in notes
      $sti = $conn->prepare("SELECT product_id, quantity  FROM purchase_invoice_items WHERE id = ?");
      $sti->bind_param("i", $item_id);
      $sti->execute();
      $it = $sti->get_result()->fetch_assoc();
      $sti->close();
      if (!$it) throw new Exception('البند غير موجود');// get item info to show in notes
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



      // delete
      $del = $conn->prepare("DELETE FROM purchase_invoice_items WHERE id = ?");
      $del->bind_param("i", $item_id);
      $del->execute();
      $del->close();

      // recalc invoice total
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

      // append note
      // $now = date('Y-m-d H:i:s');
      // $note_line = "[" . $now . "] حذف بند (#{$item_id}) - المنتج ID: {$it['product_id']},  الكمية: {$it['quantity']}. السبب: " . ($reason === '' ? 'لم يُذكر' : $reason) . " (المحرر: " . e($current_user_name) . ")\n";
      // append_invoice_note($conn, $invoice_id, $note_line);

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

  // ----- EDIT invoice items (adjustments) -----
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

        // lock item
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

    // تحديد التكلفة بعد التعديل
    $effective_cost = ($new_cost !== null) ? (float)$new_cost : (float)($row['cost_price_per_unit'] ?? 0.0);
    $new_total_cost = $new_qty * $effective_cost;

    // تحديث الكمية + التعديلات + السبب
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

    // تحديث التكلفة أو سعر البيع لو تم إدخالهم
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


        


        // التعديل االاتي عملته عشان مكنش بيحسب ال total_cost in purchase_invoice_items
        if ($inv['status'] === 'fully_received') {
    // find batch linked to this item
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

    // update item quantity and record adjustment
    $upit = $conn->prepare("UPDATE purchase_invoice_items SET quantity = ?, qty_received = ?, qty_adjusted = ?, adjustment_reason = ?, adjusted_by = ?, adjusted_at = NOW() WHERE id = ?");
    if (!$upit) throw new Exception("فشل تحضير تعديل البند: " . $conn->error);
    $upit->bind_param("ddssii", $new_qty,$new_qty, $qty_adj_str, $adjust_reason, $adj_by, $item_id);
    if (!$upit->execute()) {
      $upit->close();
      throw new Exception("فشل تعديل البند: " . $upit->error);
    }
    $upit->close();

    // <-- **جديد**: بعد تعديل الكمية نعيد حساب total_cost لهذا البند
    $st_tot_item = $conn->prepare("UPDATE purchase_invoice_items SET total_cost = (quantity * cost_price_per_unit) WHERE id = ?");
    if (!$st_tot_item) throw new Exception("فشل تحضير تحديث total_cost: " . $conn->error);
    $st_tot_item->bind_param("i", $item_id);
    if (!$st_tot_item->execute()) {
      $st_tot_item->close();
      throw new Exception("فشل تحديث total_cost: " . $st_tot_item->error);
    }
    $st_tot_item->close();

    // update batch quantities
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

    // update product stock
    $upprod = $conn->prepare("UPDATE products SET current_stock = current_stock + ? WHERE id = ?");
    $upprod->bind_param("di", $diff, $prod_id);
    if (!$upprod->execute()) {
      $upprod->close();
      throw new Exception("فشل تحديث المخزون: " . $upprod->error);
    }
    $upprod->close();

    // update cost and sale price on item and batch if provided
    if ($new_cost !== null) {
      $stmtc = $conn->prepare("UPDATE purchase_invoice_items SET cost_price_per_unit = ? WHERE id = ?");
      $stmtc->bind_param("di", $new_cost, $item_id);
      $stmtc->execute();
      $stmtc->close();

      $upb_cost = $conn->prepare("UPDATE batches SET unit_cost = ? WHERE id = ?");
      $upb_cost->bind_param("di", $new_cost, $batch['id']);
      $upb_cost->execute();
      $upb_cost->close();

      // <-- **جديد**: بعد تعديل سعر الشراء أيضاً نعيد حساب total_cost
      $st_tot_after_cost = $conn->prepare("UPDATE purchase_invoice_items SET total_cost = (quantity * cost_price_per_unit) WHERE id = ?");
      if (!$st_tot_after_cost) throw new Exception("فشل تحضير تحديث total_cost بعد تغيير السعر: " . $conn->error);
      $st_tot_after_cost->bind_param("i", $item_id);
      if (!$st_tot_after_cost->execute()) {
        $st_tot_after_cost->close();
        throw new Exception("فشل تحديث total_cost بعد تغيير السعر: " . $st_tot_after_cost->error);
      }
      $st_tot_after_cost->close();
    }
    if ($new_sale !== null ) {
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

      // recalc invoice total
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

      // append adjustment note to invoice notes
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

// ---------- عرض الصفحة (الفلترة و الجدول) ----------
$selected_supplier_id = isset($_GET['supplier_filter_val']) ? intval($_GET['supplier_filter_val']) : '';
$selected_status = isset($_GET['status_filter_val']) ? trim($_GET['status_filter_val']) : '';
// فلتر بحث مباشر برقم الفاتورة (invoice_out -> id)
$search_invoice_id = isset($_GET['invoice_out_id']) ? intval($_GET['invoice_out_id']) : 0;


$suppliers_list = [];
$sql_suppliers = "SELECT id, name FROM suppliers ORDER BY name ASC";
$rs = $conn->query($sql_suppliers);
if ($rs) while ($r = $rs->fetch_assoc()) $suppliers_list[] = $r;

$grand_total_all_purchases = 0;
$rs2 = $conn->query("SELECT COALESCE(SUM(total_amount),0) AS grand_total FROM purchase_invoices WHERE status != 'cancelled'");
if ($rs2) {
  $r2 = $rs2->fetch_assoc();
  $grand_total_all_purchases = (float)$r2['grand_total'];
}

// fetch invoices with filters
$sql_select_invoices = "SELECT pi.id, pi.supplier_invoice_number, pi.purchase_date, pi.status, pi.total_amount, pi.created_at, s.name as supplier_name, u.username as creator_name
                        FROM purchase_invoices pi
                        JOIN suppliers s ON pi.supplier_id = s.id
                        LEFT JOIN users u ON pi.created_by = u.id";
$conds = [];
$params = [];
$types = '';
// if (!empty($selected_supplier_id)) {
//   $conds[] = "pi.supplier_id = ?";
//   $params[] = $selected_supplier_id;
//   $types .= 'i';
// }
// if (!empty($selected_status)) {
//   $conds[] = "pi.status = ?";
//   $params[] = $selected_status;
//   $types .= 's';
// }


if (!empty($search_invoice_id)) {
  $conds[] = "pi.id = ?"; // exact match on invoice id
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
$sql_total_displayed = "SELECT COALESCE(SUM(total_amount),0) AS total_displayed FROM purchase_invoices pi WHERE 1=1";
$conds_total = [];
$params_total = [];
$types_total = '';
if (!empty($search_invoice_id)) {
  $conds[] = "pi.id = ?"; // exact match on invoice id
  $params[] = $search_invoice_id;
  $types .= 'i';
} 
if (!empty($selected_supplier_id)) {
  $conds_total[] = "pi.supplier_id = ?";
  $params_total[] = $selected_supplier_id;
  $types_total .= 'i';
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


// apply filters in order of priority; if invoice id search is provided, it will be the strongest filter



// ... (the rest of your page rendering / HTML comes here - unchanged)
// You should ensure your UI/JS uses fetch_invoice_json to display invoice details incl. batches,
// and that your edit modal sends items_json + adjust_reason when saving edits.

// header/sidebar
require_once BASE_DIR . 'partials/header.php';
require_once BASE_DIR . 'partials/sidebar.php';
?>

<!-- ====== HTML & JS (واجهة محسّنة بسيطة) ====== -->
<!-- BEGIN: manage_purchase_invoices.html (updated frontend) -->
<!-- START: manage_purchase_invoices HTML+JS (استبدل الجزء الموجود عندك بهذا) -->
<style>

  .container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 12px;
  }

  .card {
    border-radius: 12px;
    box-shadow: var(--shadow);
    background: var(--bg);
    margin-bottom: 12px;
  }

  .badge-pending {
    background: linear-gradient(90deg, #f59e0b, #d97706);
    color: #fff;
    padding: 6px 10px;
    border-radius: 20px;
  }

  .badge-received {
    background: linear-gradient(90deg, #10b981, #0ea5e9);
    color: #fff;
    padding: 6px 10px;
    border-radius: 20px;
  }

  .badge-cancelled {
    background: linear-gradient(90deg, #ef4444, #dc2626);
    color: #fff;
    padding: 6px 10px;
    border-radius: 20px;
  }

  .modal {
    position: fixed;
    inset: 0;
    display: none;
    align-items: center;
    justify-content: center;
    background: rgba(0, 0, 0, 0.4);
    z-index: 2000;
  }

  .modal .modal-content {
    background: var(--bg);
    border-radius: 8px;
    width: 92%;
    max-width: 1100px;
    max-height: 85vh;
    overflow: auto;
    box-shadow: 0 12px 40px rgba(0, 0, 0, 0.3);
    padding: 0;
  }

  .modal .modal-content.wide {
    max-width: 1300px;
  }

  .modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 16px;
    border-bottom: 1px solid #eee;
  }

  .modal-body {
    padding: 14px 18px;
  }

  .modal-footer {
    padding: 10px 16px;
    border-top: 1px solid #eee;
    text-align: left;
  }

  .btn-close {
    background: transparent;
    border: 0;
    font-size: 22px;
    cursor: pointer;
  }

  .table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 8px;
  }

  .table th,
  .table td {
    padding: 8px 10px;
    border: 1px solid #eee;
    text-align: left;
    font-size: 13px;
  }

  .small-muted {
    color: #6b7280;
    font-size: 13px;
  }

  .inv-meta {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    margin-bottom: 8px;
  }

  .inv-notes pre {
    background: var(--bg) !important;
    padding: 10px;
    border-radius: 6px;
    min-height: 40px;
  }

  input.edit-input {
    width: 100px;
    padding: 6px;
  }

  .btn {
    padding: 6px 10px;
    border-radius: 6px;
    border: 0;
    cursor: pointer;
  }

  .btn.btn-primary {
    background: var(--primary);
    color: #fff;
  }

  .btn.btn-secondary {
    background: #f3f4f6;
    color: #111827;
    border: 1px solid #e5e7eb;
  }

  .btn.btn-success {
    background: #10b981;
    color: #fff;
  }

  .btn.btn-danger {
    background: #ef4444;
    color: #fff;
  }

  .btn.btn-warning {
    background: #f59e0b;
    color: #fff;
  }

  .text-end {
    text-align: right;
  }

  .text-center {
    text-align: center;
  }


  .form-control {
    padding: 6px;
    border: 1px solid #e5e7eb;
    border-radius: 6px;
    width: 100%;
    box-sizing: border-box;
  }
</style>

<div class="container">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
    <h3>إدارة فواتير المشتريات</h3>
    <a href="<?php echo BASE_URL; ?>admin/manage_suppliers.php" class="btn btn-success">إنشاء فاتورة جديدة</a>
  </div>

  <?php if (!empty($message)) echo $message;
  if (!empty($_SESSION['message'])) {
    echo $_SESSION['message'];
    unset($_SESSION['message']);
  } ?>

  <!-- Filters card (كما في صفحتك) -->
  <div class="card">
    <div style="padding:12px;">
      <form method="get" style="display:flex;gap:12px;flex-wrap:wrap;align-items:end;">
        <div style="flex:1;min-width:200px">
          <label>المورد</label>
          <select name="supplier_filter_val" class="form-control">
            <option value="">-- كل الموردين --</option>
            <?php foreach ($suppliers_list as $s): ?>
              <option value="<?php echo $s['id']; ?>" <?php echo ($selected_supplier_id == $s['id']) ? 'selected' : ''; ?>><?php echo e($s['name']); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div style="flex:1;min-width:200px">
          <label>الحالة</label>
          <select name="status_filter_val" class="form-control">
            <option value="">-- كل الحالات --</option>
            <?php foreach ($status_labels as $k => $v): ?>
              <option value="<?php echo $k; ?>" <?php echo ($selected_status == $k) ? 'selected' : ''; ?>><?php echo e($v); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div style="min-width:220px">
  <label>بحث برقم الفاتورة</label>
  <input type="number" name="invoice_out_id" class="form-control" placeholder="أدخل رقم الفاتورة" value="<?php echo e($search_invoice_id); ?>">
</div>

        <div style="min-width:120px">
          <button class="btn btn-primary" type="submit">تصفية</button>
        </div>
        <?php if ($selected_supplier_id || $selected_status || !empty($search_invoice_id)): ?>

          <div style="min-width:120px"><a href="<?php echo basename(__FILE__); ?>" class="btn btn-secondary">مسح</a></div>
        <?php endif; ?>
      </form>
    </div>
  </div>

  <!-- Invoices table -->
  <div class="card">
    <div style="padding:8px;">
      <div style="overflow:auto; " class="custom-table-wrapper">
        <table class=" tabe custom-table customized">
          <thead class="table-dark center">
            <tr>
              <th>#</th>
              <th>المورد</th>
              <th>رقم المورد</th>
              <th>تاريخ</th>
              <th>الحالة</th>
              <th class="text-end">الإجمالي</th>
              <th class="text-center">إجراءات</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($result_invoices && $result_invoices->num_rows > 0): while ($inv = $result_invoices->fetch_assoc()): ?>
                <tr>
                  <td><?php echo e($inv['id']); ?></td>
                  <td><?php echo e($inv['supplier_name']); ?></td>
                  <td><?php echo e($inv['supplier_invoice_number'] ?: '-'); ?></td>
                  <td><?php echo e(date('Y-m-d', strtotime($inv['purchase_date']))); ?></td>
                  <td>
                    <?php if ($inv['status'] === 'pending'): ?><span class="badge-pending"><?php echo e($status_labels['pending']); ?></span>
                    <?php elseif ($inv['status'] === 'fully_received'): ?><span class="badge-received"><?php echo e($status_labels['fully_received']); ?></span>
                    <?php else: ?><span class="badge-cancelled"><?php echo e($status_labels['cancelled']); ?></span><?php endif; ?>
                  </td>
                  <td class="text-end fw-bold"><?php echo number_format((float)$inv['total_amount'], 2); ?> ج.م</td>
                  <td class="text-center">
                    <button class="btn btn-info btn-sm" onclick="openInvoiceModalView(<?php echo $inv['id']; ?>)">عرض</button>
                    <?php if ($inv['status'] === 'pending'): ?>
                      <button class="btn btn-warning btn-sm" onclick="openInvoiceModalEdit(<?php echo $inv['id']; ?>)">تعديل</button>
                      <form method="post" style="display:inline-block" onsubmit="return confirm('تأكيد استلام الفاتورة بالكامل؟')">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="purchase_invoice_id" value="<?php echo $inv['id']; ?>">
                        <button type="submit" name="receive_purchase_invoice" class="btn btn-success btn-sm">استلام</button>
                      </form>
                      <button class="btn btn-danger btn-sm" onclick="openReasonModal('cancel', <?php echo $inv['id']; ?>)">إلغاء</button>
                    <?php elseif ($inv['status'] === 'fully_received'): ?>
                      <button class="btn btn-warning btn-sm" onclick="openInvoiceModalEdit(<?php echo $inv['id']; ?>)">تعديل</button>
                      <button class="btn btn-outline-secondary btn-sm" onclick="openReasonModal('revert', <?php echo $inv['id']; ?>)">قيد الانتظار</button>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endwhile;
            else: ?>
              <tr>
                <td colspan="7" class="text-center">لا توجد فواتير.</td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- summary -->
  <div style="display:flex;justify-content:flex-end;"class=" custom-text">
    <div class="card" style="width:360px;">
      <div style="padding:12px;">
        <div class=" custom-text"><strong>إجمالي الفواتير المعروضة:</strong> <span class="badge bg-primary"><?php echo number_format($displayed_invoices_sum, 2); ?> ج.م</span></div>
        <div class=" custom-text" style="margin-top:8px"><strong>الإجمالي الكلي (غير الملغاة):</strong> <span class="badge bg-success"><?php echo number_format($grand_total_all_purchases, 2); ?> ج.م</span></div>
      </div>
    </div>
  </div>
</div>

<!-- ======================= Invoice View Modal ======================= -->
<div id="invoiceModal" class="modal" aria-hidden="true">
  <div class="modal-content">
    <div class="modal-header">
      <h3>تفاصيل الفاتورة</h3>
      <button class="btn-close" onclick="document.getElementById('invoiceModal').style.display='none'">×</button>
    </div>
    <div class="modal-body" id="invoiceModalBody">
      <!-- يملأ بالـ JS -->
      <div style="padding:12px">جارٍ التحميل...</div>
    </div>
    <div class="modal-footer" id="invoiceModalFooter">
      <button id="btn_inv_edit" class="btn btn-primary" style="display:none">تعديل بنود</button>
      <button id="btn_inv_print" class="btn btn-secondary" style="display:none">طباعة للمورد</button>
      <button class="btn btn-secondary" onclick="document.getElementById('invoiceModal').style.display='none'">إغلاق</button>
    </div>
  </div>
</div>

<!-- ======================= Edit Invoice Modal ======================= -->
<div id="editInvoiceModal" class="modal" aria-hidden="true">
  <div class="modal-content wide">
    <div class="modal-header">
      <h3>تعديل بنود الفاتورة <span id="edit_inv_id"></span></h3>
      <button class="btn-close" onclick="document.getElementById('editInvoiceModal').style.display='none'">×</button>
    </div>
    <div class="modal-body" id="editInvoiceBody">
      <!-- يملأ بالـ JS -->
      <div style="padding:12px">جارٍ التحميل...</div>
    </div>
    <div class="modal-footer">
      <button id="btn_save_edit" class="btn btn-success">حفظ التعديلات</button>
      <button class="btn btn-secondary" onclick="document.getElementById('editInvoiceModal').style.display='none'">إلغاء</button>
    </div>
  </div>
</div>

<!-- ======================= Reason Modal (revert/cancel) ======================= -->
<div id="reasonModalBackdrop" class="modal" style="display:none;">
  <div class="modal-content" style="max-width:640px;">
    <div class="modal-header">
      <h3>الرجاء إدخال السبب</h3><button class="btn-close" onclick="document.getElementById('reasonModalBackdrop').style.display='none'">×</button>
    </div>
    <div class="modal-body">
      <form id="reasonForm" method="post">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
        <input type="hidden" name="purchase_invoice_id" id="reason_invoice_id" value="">
        <div style="margin-bottom:8px;"><label>السبب (مطلوب)</label><textarea name="reason" id="reason_text" rows="4" class="form-control" required></textarea></div>
        <div style="text-align:left;">
          <!-- inputs new_status/change are مضافة ديناميكياً من JS -->
          <button type="submit" class="btn btn-primary">تأكيد</button>
          <button type="button" class="btn btn-secondary" onclick="document.getElementById('reasonModalBackdrop').style.display='none'">إلغاء</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
  /*
  Full front-end JS for manage_purchase_invoices
  - يعرض المودالات، يجلب بيانات الفاتورة من endpoint ?action=fetch_invoice_json&id=...
  - مودال التعديل يضيف عمود حذف في حالة invoice.status === 'pending'
  - زر الحذف يطلب سبب الحذف ثم يرسل form POST مخفي الى السيرفر (delete_invoice_item)
  - زر الحفظ في المودال يجمع items_json ويرسله الى edit_invoice POST
*/

  (function() {
    const ajaxUrl = '<?php echo basename(__FILE__); ?>';
    const CSRF_TOKEN = <?php echo json_encode($csrf_token); ?>;

    function q(sel, ctx = document) {
      return ctx.querySelector(sel);
    }

    function qa(sel, ctx = document) {
      return Array.from((ctx || document).querySelectorAll(sel));
    }

    function el(tag, attrs) {
      const e = document.createElement(tag);
      for (const k in (attrs || {})) e.setAttribute(k, attrs[k]);
      return e;
    }

    function escapeHtml(s) {
      if (s === null || s === undefined) return '';
      return String(s).replace(/[&<>"']/g, m => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": "&#39;"
      } [m]));
    }

    function showModal(elm) {
      if (!elm) return;
      elm.style.display = 'flex';
      elm.setAttribute('aria-hidden', 'false');
    }

    function hideModal(elm) {
      if (!elm) return;
      elm.style.display = 'none';
      elm.setAttribute('aria-hidden', 'true');
    }

    // ----- VIEW invoice -----
    async function openInvoiceModalView(id) {
      if (!id) return;
      const modal = document.getElementById('invoiceModal');
      const body = document.getElementById('invoiceModalBody');
      const btnEdit = document.getElementById('btn_inv_edit');
      const btnPrint = document.getElementById('btn_inv_print');

      showModal(modal);
      body.innerHTML = '<div style="padding:12px">جارٍ التحميل...</div>';
      btnEdit.style.display = 'none';
      btnPrint.style.display = 'none';

      try {
        const res = await fetch(ajaxUrl + '?action=fetch_invoice_json&id=' + encodeURIComponent(id), {
          credentials: 'same-origin'
        });
        const data = await res.json();
        if (!data || !data.ok) {
          body.innerHTML = '<div class="alert alert-danger">فشل جلب البيانات.</div>';
          return;
        }

        const inv = data.invoice || {};
        const items = data.items || [];
        const batches = data.batches || [];

        let html = '<div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;">' +
          '<div><strong>فاتورة #' + escapeHtml(inv.id) + '</strong><div class="small-muted">' + escapeHtml(inv.purchase_date || inv.created_at || '') + '</div></div>' +
          '<div>' + (inv.status === 'fully_received' ? '<span class="badge-received">مستلمة</span>' : (inv.status === 'cancelled' ? '<span class="badge-cancelled">ملغاة</span>' : '<span class="badge-pending">قيد الانتظار</span>')) + '</div></div>';

        html += '<div style="margin-top:10px;"><strong>المورد:</strong> ' + escapeHtml(inv.supplier_name || '') + ' &nbsp; <strong>الإجمالي:</strong> ' + Number(inv.total_amount || 0).toFixed(2) + ' ج.م</div>';
        html += '<div style="margin-top:10px"><h4>ملاحظات</h4><pre style="white-space:pre-wrap;padding:10px;border-radius:6px;">' + escapeHtml(inv.notes || '-') + '</pre></div>';

        // items
        html += '<div class="custom-table-wrapper" style="margin-top:10px"><h4>بنود الفاتورة</h4>';
        html += '<table class="custom-table"><thead class="center"><tr><th>#</th><th>اسم</th><th>كمية</th><th>سعر شراء</th><th>سعر بيع</th><th>مستلم</th><th>إجمالي</th></tr></thead><tbody>';
        let total = 0;
        if (items.length) {
          items.forEach((it, idx) => {
            const line = Number(it.total_cost || (it.quantity * it.cost_price_per_unit) || 0).toFixed(2);
            total += parseFloat(line);
            html += '<tr><td>' + (idx + 1) + '</td><td style="text-align:right">' + escapeHtml(it.product_name || ('#' + it.product_id)) + '</td><td style="text-align:center">' + Number(it.quantity || 0).toFixed(2) + '</td><td style="text-align:right">' + Number(it.cost_price_per_unit || 0).toFixed(2) + '</td><td style="text-align:right">' + ((it.sale_price !== undefined && it.sale_price !== null) ? Number(it.sale_price).toFixed(2) + ' ج.م' : '-') + '</td><td style="text-align:center">' + Number(it.qty_received || 0).toFixed(2) + '</td><td style="text-align:right;font-weight:700">' + line + ' ج.م</td></tr>';
          });
        } else {
          html += '<tr><td colspan="7" style="text-align:center">لا توجد بنود</td></tr>';
        }
        html += '</tbody><tfoot><tr><td colspan="6" style="text-align:right;font-weight:700">الإجمالي</td><td style="text-align:right;font-weight:800">' + total.toFixed(2) + ' ج.م</td></tr></tfoot></table></div>';

        // batches
        html += '<div class="custom-table-wrapper" style="margin-top:10px"><h4>الدفعات المرتبطة</h4>';
        if (batches && batches.length) {
          html += '<table class="custom-table"><thead class="center"><tr><th>دفعة</th><th>اسم</th><th>منتج</th><th>كمية</th><th>متبقي</th><th>سعر شراء</th><th>سعر بيع</th><th>الحالة</th><th>سبب الإرجاع</th><th>سبب الإلغاء</th></tr></thead><tbody>';
          batches.forEach((b,index) => {
            html += '<tr><td>' + escapeHtml(String(b.id)) + '</td><td>'+ escapeHtml(String(items[index]?.product_name?? '_')) + '</td><td>' + escapeHtml(String(b.product_id || '-')) + '</td><td>' + Number(b.qty || 0).toFixed(2) + '</td><td>' + Number(b.remaining || 0).toFixed(2) + '</td><td>' + (b.unit_cost !== null ? Number(b.unit_cost).toFixed(2) + ' ج.م' : '-') + '</td><td>' + (b.sale_price !== null && b.sale_price !== undefined ? Number(b.sale_price).toFixed(2) + ' ج.م' : '-') + '</td><td>' + escapeHtml(b.status || '') + '</td><td>' + escapeHtml(b.revert_reason || '-') + '</td><td>' + escapeHtml(b.cancel_reason || '-') + '</td></tr>';
          });
          html += '</tbody></table>';
        } else {
          html += '<div class="small-muted">لا توجد دفعات مرتبطة.</div>';
        }
        html += '</div>';

        body.innerHTML = html;

        // footer buttons
        if (data.can_edit) {
          document.getElementById('btn_inv_edit').style.display = 'inline-block';
          document.getElementById('btn_inv_edit').onclick = function() {
            hideModal(modal);
            openInvoiceModalEdit(id);
          };
        } else {
          document.getElementById('btn_inv_edit').style.display = 'none';
        }
        document.getElementById('btn_inv_print').style.display = 'inline-block';
        document.getElementById('btn_inv_print').onclick = function() {
          window.open(ajaxUrl + '?action=print_supplier&id=' + encodeURIComponent(id), '_blank');
        };

      } catch (err) {
        console.error(err);
        body.innerHTML = '<div class="alert alert-danger">فشل الاتصال بالخادم.</div>';
      }
    }

    // ----- EDIT invoice (with delete) -----
    async function openInvoiceModalEdit(id) {
      if (!id) return;
      const modal = document.getElementById('editInvoiceModal');
      const body = document.getElementById('editInvoiceBody');
      const footerSave = document.getElementById('btn_save_edit');

      showModal(modal);
      body.innerHTML = '<div style="padding:12px">جارٍ التحميل...</div>';
      footerSave.onclick = null; // clear previous handler

      try {
        const res = await fetch(ajaxUrl + '?action=fetch_invoice_json&id=' + encodeURIComponent(id), {
          credentials: 'same-origin'
        });
        const data = await res.json();
        if (!data || !data.ok) {
          body.innerHTML = '<div class="alert alert-danger">فشل جلب الفاتورة.</div>';
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

        // build editable table
        let html = '<table class="custom-table"><thead class="center">' +
          '<tr<tr><th>#</th><th>المنتج</th><th>كمية حالية</th><th>كمية جديدة</th><th>سعر شراء حالي</th><th>سعر شراء جديد</th><th>سعر بيع حالي</th><th>سعر بيع جديد</th>';
        if (allowDelete) html += '<th>حذف</th>';
        html += '</tr></thead><tbody>';

        items.forEach((it, idx) => {
          const curQty = Number(it.quantity || 0).toFixed(2);
          const curCost = Number(it.cost_price_per_unit || 0).toFixed(2);
          const curSale = (it.sale_price !== undefined && it.sale_price !== null) ? Number(it.sale_price).toFixed(2) : '';
          html += '<tr>' +
            '<td>' + (idx + 1) + '</td>' +
            '<td style="text-align:right">' + escapeHtml(it.product_name || ('#' + it.product_id)) + '</td>' +
            '<td>' + curQty + '</td>' +
            '<td><input class="form-control edit-item-qty" data-item-id="' + (it.id || '') + '" type="number" step="0.01" value="' + curQty + '"></td>' +
            '<td>' + curCost + '</td>' +
            '<td><input class="form-control edit-item-cost" data-item-id="' + (it.id || '') + '" type="number" step="0.01" value="' + curCost + '"></td>' +
            '<td>' + (curSale ? curSale + ' ج.م' : '-') + '</td>' +
            '<td><input class="form-control edit-item-sale" data-item-id="' + (it.id || '') + '" type="number" step="0.01" value="' + (curSale ? curSale : '') + '"></td>';
          if (allowDelete) html += '<td><button type="button" class="btn btn-danger btn-sm js-delete-item" data-item-id="' + (it.id || '') + '">حذف</button></td>';
          html += '</tr>';
        });

        html += '</tbody></table>';
        html += '<div style="margin-top:8px;"><label><strong>سبب التعديل (مطلوب)</strong></label><textarea id="js_adjust_reason" rows="3" class="form-control"></textarea></div>';
        body.innerHTML = html;
        document.getElementById('edit_inv_id').innerText = '#' + id;

        // wire delete buttons
        if (allowDelete) {
          qa('.js-delete-item', body).forEach(btn => {
            btn.onclick = function() {
              const itemId = this.dataset.itemId;
              if (!itemId) return;
              const reason = prompt('أدخل سبب الحذف (مطلوب):');
              if (!reason || !reason.trim()) {
                alert('العملية أُلغيت — سبب مطلوب');
                return;
              }
              // create and submit hidden POST form to server
              const f = document.createElement('form');
              f.method = 'POST';
              f.action = ajaxUrl;
              f.style.display = 'none';

              const i1 = el('input');
              i1.type = 'hidden';
              i1.name = 'delete_invoice_item';
              i1.value = '1';
              f.appendChild(i1);
              const i2 = el('input');
              i2.type = 'hidden';
              i2.name = 'invoice_id';
              i2.value = id;
              f.appendChild(i2);
              const i3 = el('input');
              i3.type = 'hidden';
              i3.name = 'item_id';
              i3.value = itemId;
              f.appendChild(i3);
              const i4 = el('input');
              i4.type = 'hidden';
              i4.name = 'reason';
              i4.value = reason;
              f.appendChild(i4);
              const i5 = el('input');
              i5.type = 'hidden';
              i5.name = 'csrf_token';
              i5.value = CSRF_TOKEN;
              f.appendChild(i5);

              document.body.appendChild(f);
              f.submit();
            };
          });
        }

        // wire save button
        footerSave.onclick = function() {
          // collect inputs
          const inputsQty = qa('.edit-item-qty', body);
          const inputsCost = qa('.edit-item-cost', body);
          const inputsSale = qa('.edit-item-sale', body);
          const mapById = {};
          inputsQty.forEach(i => {
            const idattr = i.dataset.itemId || '';
            if (!idattr) return;
            mapById[idattr] = mapById[idattr] || {};
            mapById[idattr].new_quantity = parseFloat(i.value || 0);
          });
          inputsCost.forEach(i => {
            const idattr = i.dataset.itemId || '';
            if (!idattr) return;
            mapById[idattr] = mapById[idattr] || {};
            mapById[idattr].new_cost_price = parseFloat(i.value || 0);
          });
          inputsSale.forEach(i => {
            const idattr = i.dataset.itemId || '';
            if (!idattr) return;
            mapById[idattr] = mapById[idattr] || {};
            mapById[idattr].new_sale_price = (i.value === '') ? null : parseFloat(i.value || 0);
          });

          const itemsPayload = [];
          for (const k in mapById) {
            const obj = mapById[k];
            obj.item_id = parseInt(k, 10);
            itemsPayload.push(obj);
          }

          const adjustReason = (q('#js_adjust_reason') ? q('#js_adjust_reason').value.trim() : '');
          if (!adjustReason) {
            alert('الرجاء إدخال سبب التعديل');
            q('#js_adjust_reason').focus();
            return;
          }
          if (!itemsPayload.length) {
            alert('لا توجد بنود للتعديل');
            return;
          }

          // submit hidden form to trigger server-side edit handler
          const form = document.createElement('form');
          form.method = 'POST';
          form.action = ajaxUrl;
          form.style.display = 'none';
          const f1 = el('input');
          f1.type = 'hidden';
          f1.name = 'edit_invoice';
          f1.value = '1';
          form.appendChild(f1);
          const f2 = el('input');
          f2.type = 'hidden';
          f2.name = 'invoice_id';
          f2.value = id;
          form.appendChild(f2);
          const f3 = el('input');
          f3.type = 'hidden';
          f3.name = 'items_json';
          f3.value = JSON.stringify(itemsPayload);
          form.appendChild(f3);
          const f4 = el('input');
          f4.type = 'hidden';
          f4.name = 'adjust_reason';
          f4.value = adjustReason;
          form.appendChild(f4);
          const f5 = el('input');
          f5.type = 'hidden';
          f5.name = 'csrf_token';
          f5.value = CSRF_TOKEN;
          form.appendChild(f5);

          document.body.appendChild(form);
          form.submit();
        };

      } catch (err) {
        console.error(err);
        body.innerHTML = '<div class="alert alert-danger">فشل الاتصال بالخادم.</div>';
      }
    }

    // ----- reason modal (revert/cancel) -----
    function openReasonModal(action, invoiceId) {
      const backdrop = document.getElementById('reasonModalBackdrop');
      const form = document.getElementById('reasonForm');
      if (!form) {
        alert('نموذج السبب غير متوفر');
        return;
      }
      // clear old inputs
      // remove previous hidden markers
      ['change_invoice_status', 'cancel_purchase_invoice', 'new_status'].forEach(n => {
        const elOld = form.querySelector('input[name="' + n + '"]');
        if (elOld) elOld.remove();
      });
      // add new markers
      if (action === 'revert') {
        const a = el('input');
        a.type = 'hidden';
        a.name = 'change_invoice_status';
        a.value = '1';
        form.appendChild(a);
        const b = el('input');
        b.type = 'hidden';
        b.name = 'new_status';
        b.value = 'pending';
        form.appendChild(b);
      } else {
        const a = el('input');
        a.type = 'hidden';
        a.name = 'cancel_purchase_invoice';
        a.value = '1';
        form.appendChild(a);
      }
      form.querySelector('input[name="purchase_invoice_id"]').value = invoiceId;
      // ensure csrf exists
      let csrf = form.querySelector('input[name="csrf_token"]');
      if (!csrf) {
        csrf = el('input');
        csrf.type = 'hidden';
        csrf.name = 'csrf_token';
        csrf.value = CSRF_TOKEN;
        form.appendChild(csrf);
      }
      showModal(backdrop);
      // default submit behavior will post to server and server redirects
    }

    // attach to global
    window.openInvoiceModalView = openInvoiceModalView;
    window.openInvoiceModalEdit = openInvoiceModalEdit;
    window.openReasonModal = openReasonModal;

    // close modals on backdrop click
    ['invoiceModal', 'editInvoiceModal'].forEach(id => {
      const m = document.getElementById(id);
      if (!m) return;
      m.addEventListener('click', function(e) {
        if (e.target === m) m.style.display = 'none';
      });
    });
    const reasonBackdrop = document.getElementById('reasonModalBackdrop');
    if (reasonBackdrop) reasonBackdrop.addEventListener('click', function(e) {
      if (e.target === reasonBackdrop) reasonBackdrop.style.display = 'none';
    });

  })();
</script>
<!-- END: manage_purchase_invoices HTML+JS -->

<?php
require_once BASE_DIR . 'partials/footer.php';
$conn->close();
?>