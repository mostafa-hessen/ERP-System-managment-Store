<?php
// admin/view_purchase_invoice.php  (مُعدّل ليتوافق مع schema الموجودة ويمنع partial_received)
// - مواءمة session user id
// - تجاهل partial_received (نستخدم pending / fully_received / cancelled)
// - عند fully_received: set qty_received = quantity, أنشئ batches، حدّث products.current_stock، خزّن batch_id
// - منع التعديلات على الفاتورة بعد fully_received (لحماية المخزون)
// - تحسينات CSRF / error handling / transactions

ob_start();

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
// require_once BASE_DIR . 'partials/session_admin.php'; // التأكد أن المستخدم مدير

if (file_exists(dirname(__DIR__) . '/config.php')) {
  require_once dirname(__DIR__) . '/config.php';
} elseif (file_exists(dirname(dirname(__DIR__)) . '/config.php')) {
  require_once dirname(dirname(__DIR__) . '/config.php');
} else {
  http_response_code(500);
  echo "خطأ داخلي: إعدادات غير موجودة.";
  exit;
}
if (!isset($conn) || !$conn) {
  echo "خطأ في الاتصال بقاعدة البيانات.";
  exit;
}

function e($s) {
  return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// CSRF
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf_token = $_SESSION['csrf_token'];

// unified user id
$user_id = isset($_SESSION['id']) ? intval($_SESSION['id']) : null;

// Helpers
function json_out($arr) {
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($arr, JSON_UNESCAPED_UNICODE);
  exit;
}

// ---------------- AJAX endpoints ----------------
// 1) Search products (unchanged)
if (isset($_GET['action']) && $_GET['action'] === 'search_products') {
  header('Content-Type: application/json; charset=utf-8');
  $q = trim($_GET['q'] ?? '');
  $out = [];
  if ($q === '') {
    $stmt = $conn->prepare("SELECT id, product_code, name, selling_price, cost_price, current_stock, unit_of_measure FROM products ORDER BY name LIMIT 1000");
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) $out[] = $r;
    $stmt->close();
  } else {
    $like = "%$q%";
    $stmt = $conn->prepare("SELECT id, product_code, name, selling_price, cost_price, current_stock, unit_of_measure FROM products WHERE name LIKE ? OR product_code LIKE ? ORDER BY name LIMIT 1000");
    $stmt->bind_param("ss", $like, $like);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) $out[] = $r;
    $stmt->close();
  }
  json_out(['ok' => true, 'results' => $out]);
}

// Helper: check invoice status (for protecting edits)
function get_invoice_status($conn, $invoice_id) {
  $st = $conn->prepare("SELECT status FROM purchase_invoices WHERE id = ? LIMIT 1");
  $st->bind_param("i", $invoice_id);
  $st->execute();
  $r = $st->get_result()->fetch_assoc();
  $st->close();
  return $r['status'] ?? null;
}

// Add item to existing invoice (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_item_ajax') {
  if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) json_out(['success' => false, 'message' => 'CSRF']);
  $invoice_id = intval($_POST['invoice_id'] ?? 0);
  $product_id = intval($_POST['product_id'] ?? 0);
  $qty = floatval($_POST['quantity'] ?? 0);
  $cost = floatval($_POST['cost_price'] ?? 0);
  $selling = floatval($_POST['selling_price'] ?? -1);
  if ($invoice_id <= 0 || $product_id <= 0 || $qty <= 0) json_out(['success' => false, 'message' => 'بيانات غير صحيحة']);
  // prevent modification if invoice already fully_received
  $status = get_invoice_status($conn, $invoice_id);
  if ($status === 'fully_received') json_out(['success' => false, 'message' => 'لا يمكن تعديل فاتورة بعد أن تم استلامها بالكامل.']);
  try {
    $conn->begin_transaction();

    // try find existing same product row in same invoice
    $st_check = $conn->prepare("SELECT id, quantity FROM purchase_invoice_items WHERE purchase_invoice_id = ? AND product_id = ? LIMIT 1");
    $st_check->bind_param("ii", $invoice_id, $product_id);
    $st_check->execute();
    $res_check = $st_check->get_result();
    $existing = $res_check->fetch_assoc();
    $st_check->close();

    if ($existing) {
      $new_qty = floatval($existing['quantity']) + $qty;
      $new_total = $new_qty * $cost;
      $st_up = $conn->prepare("UPDATE purchase_invoice_items SET quantity = ?, cost_price_per_unit = ?, total_cost = ?, updated_at = NOW() WHERE id = ?");
      $st_up->bind_param("dddi", $new_qty, $cost, $new_total, $existing['id']);
      if (!$st_up->execute()) {
        $conn->rollback();
        json_out(['success' => false, 'message' => 'فشل التحديث: ' . $st_up->error]);
      }
      $st_up->close();
      if ($selling >= 0) {
        $stps = $conn->prepare("UPDATE products SET selling_price = ? WHERE id = ?");
        $stps->bind_param("di", $selling, $product_id);
        $stps->execute();
        $stps->close();
      }
      // recalc invoice total
      $st_sum = $conn->prepare("SELECT IFNULL(SUM(total_cost),0) AS grand_total FROM purchase_invoice_items WHERE purchase_invoice_id = ?");
      $st_sum->bind_param("i", $invoice_id);
      $st_sum->execute();
      $res = $st_sum->get_result()->fetch_assoc();
      $st_sum->close();
      $grand_total = floatval($res['grand_total']);
      $st_up_inv = $conn->prepare("UPDATE purchase_invoices SET total_amount = ? WHERE id = ?");
      $st_up_inv->bind_param("di", $grand_total, $invoice_id);
      $st_up_inv->execute();
      $st_up_inv->close();

      $st_item = $conn->prepare("SELECT p_item.id as item_id_pk, p_item.product_id, p_item.quantity, p_item.cost_price_per_unit, p_item.total_cost, p.product_code, p.name as product_name, p.unit_of_measure FROM purchase_invoice_items p_item JOIN products p ON p_item.product_id = p.id WHERE p_item.id = ?");
      $st_item->bind_param("i", $existing['id']);
      $st_item->execute();
      $item_row = $st_item->get_result()->fetch_assoc();
      $st_item->close();

      $conn->commit();
      json_out(['success' => true, 'message' => 'تم تحديث البند', 'item' => $item_row, 'grand_total' => $grand_total]);
    } else {
      $total = $qty * $cost;
      // when invoice not yet fully_received, qty_received remains 0
      $st = $conn->prepare("INSERT INTO purchase_invoice_items (purchase_invoice_id, product_id, quantity, cost_price_per_unit, total_cost, created_at, qty_received) VALUES (?, ?, ?, ?, ?, NOW(), 0)");
      $st->bind_param("iiddd", $invoice_id, $product_id, $qty, $cost, $total);
      if (!$st->execute()) {
        $conn->rollback();
        json_out(['success' => false, 'message' => 'فشل الإدخال: ' . $st->error]);
      }
      $new_item_id = $conn->insert_id;
      $st->close();
      if ($selling >= 0) {
        $stps = $conn->prepare("UPDATE products SET selling_price = ? WHERE id = ?");
        $stps->bind_param("di", $selling, $product_id);
        $stps->execute();
        $stps->close();
      }
      // recalc invoice total
      $st_sum = $conn->prepare("SELECT IFNULL(SUM(total_cost),0) AS grand_total FROM purchase_invoice_items WHERE purchase_invoice_id = ?");
      $st_sum->bind_param("i", $invoice_id);
      $st_sum->execute();
      $res = $st_sum->get_result()->fetch_assoc();
      $st_sum->close();
      $grand_total = floatval($res['grand_total']);
      $st_up = $conn->prepare("UPDATE purchase_invoices SET total_amount = ? WHERE id = ?");
      $st_up->bind_param("di", $grand_total, $invoice_id);
      $st_up->execute();
      $st_up->close();
      $conn->commit();

      $st_item = $conn->prepare("SELECT p_item.id as item_id_pk, p_item.product_id, p_item.quantity, p_item.cost_price_per_unit, p_item.total_cost, p.product_code, p.name as product_name, p.unit_of_measure FROM purchase_invoice_items p_item JOIN products p ON p_item.product_id = p.id WHERE p_item.id = ?");
      $st_item->bind_param("i", $new_item_id);
      $st_item->execute();
      $item_row = $st_item->get_result()->fetch_assoc();
      $st_item->close();
      json_out(['success' => true, 'message' => 'تم الإضافة', 'item' => $item_row, 'grand_total' => $grand_total]);
    }
  } catch (Exception $ex) {
    if ($conn->in_transaction) $conn->rollback();
    error_log("add_item_ajax exception: " . $ex->getMessage());
    json_out(['success' => false, 'message' => 'خطأ: ' . $ex->getMessage()]);
  }
}

// Update item AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_item_ajax') {
  if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) json_out(['success' => false, 'message' => 'CSRF']);
  $item_id = intval($_POST['item_id'] ?? 0);
  $qty = floatval($_POST['quantity'] ?? 0);
  $cost = floatval($_POST['cost_price'] ?? 0);
  $selling = floatval($_POST['selling_price'] ?? -1);
  if ($item_id <= 0 || $qty < 0) json_out(['success' => false, 'message' => 'بيانات غير صحيحة']);

  // check invoice status
  $st_get_invoice = $conn->prepare("SELECT purchase_invoice_id FROM purchase_invoice_items WHERE id = ? LIMIT 1");
  $st_get_invoice->bind_param("i", $item_id);
  $st_get_invoice->execute();
  $resinv = $st_get_invoice->get_result()->fetch_assoc();
  $st_get_invoice->close();
  $invoice_id = intval($resinv['purchase_invoice_id'] ?? 0);
  if ($invoice_id <= 0) json_out(['success' => false, 'message' => 'البند غير مرتبط بأي فاتورة']);

  $status = get_invoice_status($conn, $invoice_id);
  if ($status === 'fully_received') {
    // prevent changing quantity/cost on a fully received invoice (inventory already affected)
    json_out(['success' => false, 'message' => 'لا يمكن تعديل بنود فاتورة مُسلمة بالفعل.']);
  }

  try {
    $total = $qty * $cost;
    $st = $conn->prepare("UPDATE purchase_invoice_items SET quantity = ?, cost_price_per_unit = ?, total_cost = ?, updated_at = NOW() WHERE id = ?");
    $st->bind_param("dddi", $qty, $cost, $total, $item_id);
    if (!$st->execute()) json_out(['success' => false, 'message' => 'فشل التحديث: ' . $st->error]);
    $st->close();
    if ($selling >= 0) {
      $stp = $conn->prepare("UPDATE products SET selling_price = ? WHERE id = (SELECT product_id FROM purchase_invoice_items WHERE id = ? LIMIT 1)");
      if ($stp) {
        $stp->bind_param("di", $selling, $item_id);
        $stp->execute();
        $stp->close();
      }
    }
    $st_sum = $conn->prepare("SELECT IFNULL(SUM(total_cost),0) AS grand_total FROM purchase_invoice_items WHERE purchase_invoice_id = ?");
    $st_sum->bind_param("i", $invoice_id);
    $st_sum->execute();
    $res = $st_sum->get_result()->fetch_assoc();
    $st_sum->close();
    $grand_total = floatval($res['grand_total']);
    $st_up = $conn->prepare("UPDATE purchase_invoices SET total_amount = ? WHERE id = ?");
    $st_up->bind_param("di", $grand_total, $invoice_id);
    $st_up->execute();
    $st_up->close();
    json_out(['success' => true, 'message' => 'تم التحديث', 'total_cost' => $total, 'grand_total' => $grand_total]);
  } catch (Exception $ex) {
    error_log("update_item_ajax exception: " . $ex->getMessage());
    json_out(['success' => false, 'message' => 'خطأ: ' . $ex->getMessage()]);
  }
}

// Delete item AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_item_ajax') {
  if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) json_out(['success' => false, 'message' => 'CSRF']);
  $item_id = intval($_POST['item_id'] ?? 0);
  if ($item_id <= 0) json_out(['success' => false, 'message' => 'بيانات غير صحيحة']);
  try {
    // get invoice and batch info
    $stg = $conn->prepare("SELECT purchase_invoice_id, batch_id, product_id FROM purchase_invoice_items WHERE id = ? LIMIT 1");
    $stg->bind_param("i", $item_id);
    $stg->execute();
    $r = $stg->get_result()->fetch_assoc();
    $stg->close();
    if (!$r) json_out(['success' => false, 'message' => 'البند غير موجود']);
    $invoice_id = intval($r['purchase_invoice_id']);
    $batch_id = $r['batch_id'] ? intval($r['batch_id']) : null;
    $product_id = intval($r['product_id']);

    $status = get_invoice_status($conn, $invoice_id);
    if ($status === 'fully_received') {
      // if there's a batch linked, check whether it has been consumed
      if ($batch_id) {
        $stb = $conn->prepare("SELECT remaining, original_qty FROM batches WHERE id = ? LIMIT 1");
        $stb->bind_param("i", $batch_id);
        $stb->execute();
        $br = $stb->get_result()->fetch_assoc();
        $stb->close();
        if ($br) {
          $remaining = floatval($br['remaining']);
          $original = floatval($br['original_qty']);
          if ($remaining < $original) {
            json_out(['success' => false, 'message' => 'لا يمكن حذف بند مرتبط بدفعة تم استهلاك جزء منها.']);
          } else {
            // safe to delete: must remove batch and deduct stock
            $conn->begin_transaction();
            // reduce product stock
            $upd = $conn->prepare("UPDATE products SET current_stock = current_stock - ? WHERE id = ?");
            $upd->bind_param("di", $original, $product_id);
            if (!$upd->execute()) {
              $conn->rollback();
              json_out(['success' => false, 'message' => 'فشل تحديث رصيد المنتج: ' . $upd->error]);
            }
            $upd->close();
            // delete batch
            $delb = $conn->prepare("DELETE FROM batches WHERE id = ?");
            $delb->bind_param("i", $batch_id);
            if (!$delb->execute()) {
              $conn->rollback();
              json_out(['success' => false, 'message' => 'فشل حذف الدفعة: ' . $delb->error]);
            }
            $delb->close();
            // delete invoice item
            $std = $conn->prepare("DELETE FROM purchase_invoice_items WHERE id = ?");
            $std->bind_param("i", $item_id);
            if (!$std->execute()) {
              $conn->rollback();
              json_out(['success' => false, 'message' => 'فشل حذف البند: ' . $std->error]);
            }
            $std->close();
            // recalc invoice total
            $st_sum = $conn->prepare("SELECT IFNULL(SUM(total_cost),0) AS grand_total FROM purchase_invoice_items WHERE purchase_invoice_id = ?");
            $st_sum->bind_param("i", $invoice_id);
            $st_sum->execute();
            $res = $st_sum->get_result()->fetch_assoc();
            $st_sum->close();
            $grand_total = floatval($res['grand_total']);
            $st_up = $conn->prepare("UPDATE purchase_invoices SET total_amount = ? WHERE id = ?");
            $st_up->bind_param("di", $grand_total, $invoice_id);
            $st_up->execute();
            $st_up->close();
            $conn->commit();
            json_out(['success' => true, 'message' => 'تم حذف البند وإزالة الدفعة وتحديث المخزون', 'grand_total' => $grand_total]);
          }
        } else {
          json_out(['success' => false, 'message' => 'دفعة مرتبطة بالبند غير موجودة']);
        }
      } else {
        json_out(['success' => false, 'message' => 'لا يمكن حذف بنود فاتورة مستلمة بالكامل بدون دفعات مرتبطة']); 
      }
    } else {
      // invoice pending: safe to delete item directly
      $std = $conn->prepare("DELETE FROM purchase_invoice_items WHERE id = ?");
      $std->bind_param("i", $item_id);
      if (!$std->execute()) json_out(['success' => false, 'message' => 'فشل الحذف: ' . $std->error]);
      $std->close();
      $st_sum = $conn->prepare("SELECT IFNULL(SUM(total_cost),0) AS grand_total FROM purchase_invoice_items WHERE purchase_invoice_id = ?");
      $st_sum->bind_param("i", $invoice_id);
      $st_sum->execute();
      $res = $st_sum->get_result()->fetch_assoc();
      $st_sum->close();
      $grand_total = floatval($res['grand_total']);
      $st_up = $conn->prepare("UPDATE purchase_invoices SET total_amount = ? WHERE id = ?");
      $st_up->bind_param("di", $grand_total, $invoice_id);
      $st_up->execute();
      $st_up->close();
      json_out(['success' => true, 'message' => 'تم الحذف', 'grand_total' => $grand_total]);
    }
  } catch (Exception $ex) {
    error_log("delete_item_ajax exception: " . $ex->getMessage());
    if ($conn->in_transaction) $conn->rollback();
    json_out(['success' => false, 'message' => 'خطأ: ' . $ex->getMessage()]);
  }
}

// Change invoice status (AJAX)
// Allowed statuses: pending, fully_received, cancelled
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_status_ajax') {
  if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) json_out(['success' => false, 'message' => 'CSRF']);
  $invoice_id = intval($_POST['invoice_id'] ?? 0);
  $new_status = $_POST['new_status'] ?? '';
  $allowed = ['pending', 'fully_received', 'cancelled'];
  if ($invoice_id <= 0 || !in_array($new_status, $allowed)) json_out(['success' => false, 'message' => 'بيانات غير صحيحة']);
  try {
    $conn->begin_transaction();

    // lock invoice row
    $stmt_prev = $conn->prepare("SELECT status FROM purchase_invoices WHERE id = ? LIMIT 1 FOR UPDATE");
    $stmt_prev->bind_param("i", $invoice_id);
    $stmt_prev->execute();
    $prev = $stmt_prev->get_result()->fetch_assoc();
    $stmt_prev->close();
    $prev_status = $prev['status'] ?? null;

    if ($prev_status === $new_status) {
      $conn->commit();
      $labels = ['pending' => 'قيد الانتظار', 'fully_received' => 'تم الاستلام', 'cancelled' => 'ملغاة'];
      json_out(['success' => true, 'message' => 'الحالة لم تتغير', 'label' => $labels[$new_status] ?? $new_status]);
    }

    // update status
    $st = $conn->prepare("UPDATE purchase_invoices SET status = ?, updated_at = NOW(), updated_by = ? WHERE id = ?");
    $st->bind_param("sii", $new_status, $user_id, $invoice_id);
    if (!$st->execute()) {
      $conn->rollback();
      json_out(['success' => false, 'message' => 'فشل تغيير حالة الفاتورة: ' . $st->error]);
    }
    $st->close();

    if ($new_status === 'fully_received' && $prev_status !== 'fully_received') {
      // for each item: create batch (if not exists), set qty_received = quantity, update products.current_stock and set purchase_invoice_items.batch_id
      $qitems = $conn->prepare("SELECT id, product_id, quantity, cost_price_per_unit, batch_id FROM purchase_invoice_items WHERE purchase_invoice_id = ?");
      $qitems->bind_param("i", $invoice_id);
      $qitems->execute();
      $res = $qitems->get_result();
      while ($row = $res->fetch_assoc()) {
        $item_id = intval($row['id']);
        $pid = intval($row['product_id']);
        $qty = floatval($row['quantity']);
        $cost = floatval($row['cost_price_per_unit']);
        $existing_batch_id = $row['batch_id'] ? intval($row['batch_id']) : null;

        if ($existing_batch_id) {
          // if batch exists, but qty_received might be 0 -> update qty_received and products stock if needed
          // check batch remaining/original
          $stb = $conn->prepare("SELECT original_qty, remaining FROM batches WHERE id = ? LIMIT 1 FOR UPDATE");
          $stb->bind_param("i", $existing_batch_id);
          $stb->execute();
          $br = $stb->get_result()->fetch_assoc();
          $stb->close();
          if ($br) {
            // if this item was marked not received (qty_received==0) then we add stock equal to (quantity - qty_received)
            $stq = $conn->prepare("SELECT qty_received FROM purchase_invoice_items WHERE id = ? LIMIT 1");
            $stq->bind_param("i", $item_id);
            $stq->execute();
            $rrq = $stq->get_result()->fetch_assoc();
            $stq->close();
            $prev_received = floatval($rrq['qty_received'] ?? 0.0);
            $to_add = $qty - $prev_received;
            if ($to_add > 0) {
              $upd = $conn->prepare("UPDATE products SET current_stock = current_stock + ? WHERE id = ?");
              $upd->bind_param("di", $to_add, $pid);
              if (!$upd->execute()) {
                $conn->rollback();
                json_out(['success' => false, 'message' => 'فشل تحديث رصيد المنتج: ' . $upd->error]);
              }
              $upd->close();
            }
            // set qty_received = quantity
            $st_up_q = $conn->prepare("UPDATE purchase_invoice_items SET qty_received = ? WHERE id = ?");
            $st_up_q->bind_param("di", $qty, $item_id);
            $st_up_q->execute();
            $st_up_q->close();
          }
        } else {
          // create new batch
          $upd = $conn->prepare("UPDATE products SET current_stock = current_stock + ?, cost_price = ? WHERE id = ?");
          $upd->bind_param("dii", $qty, $cost, $pid);
          if (!$upd->execute()) {
            $conn->rollback();
            json_out(['success' => false, 'message' => 'فشل تحديث رصيد المنتج: ' . $upd->error]);
          }
          $upd->close();

          $insb = $conn->prepare("INSERT INTO batches (product_id, qty, remaining, original_qty, unit_cost, received_at, source_invoice_id, source_item_id, status, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, CURDATE(), ?, ?, 'active', ?, NOW(), NOW())");
          $insb->bind_param("iddddiii", $pid, $qty, $qty, $qty, $cost, $invoice_id, $item_id, $user_id);
          if (!$insb->execute()) {
            $conn->rollback();
            json_out(['success' => false, 'message' => 'فشل إنشاء دفعة: ' . $insb->error]);
          }
          $new_batch_id = $insb->insert_id;
          $insb->close();

          // set item qty_received and batch_id
          $st_up_item = $conn->prepare("UPDATE purchase_invoice_items SET qty_received = ?, batch_id = ? WHERE id = ?");
          $st_up_item->bind_param("dii", $qty, $new_batch_id, $item_id);
          if (!$st_up_item->execute()) {
            $conn->rollback();
            json_out(['success' => false, 'message' => 'فشل ربط البند بالدفعة: ' . $st_up_item->error]);
          }
          $st_up_item->close();
        }
      }
      $qitems->close();
    }

    $conn->commit();
    $labels = ['pending' => 'قيد الانتظار', 'fully_received' => 'تم الاستلام', 'cancelled' => 'ملغاة'];
    json_out(['success' => true, 'message' => 'تم تغيير الحالة', 'label' => $labels[$new_status] ?? $new_status]);
  } catch (Exception $ex) {
    if ($conn->in_transaction) $conn->rollback();
    error_log("change_status_ajax exception: " . $ex->getMessage());
    json_out(['success' => false, 'message' => 'خطأ: ' . $ex->getMessage()]);
  }
}

// Finalize invoice (AJAX) - create invoice + items + optionally batches (if fully_received)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'finalize_invoice_ajax') {
  if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) json_out(['success' => false, 'message' => 'CSRF']);
  $supplier_id = intval($_POST['supplier_id'] ?? 0);
  $purchase_date = trim($_POST['purchase_date'] ?? date('Y-m-d'));
  $status = $_POST['status'] ?? 'pending';
  // normalize: ignore partial_received
  if ($status !== 'fully_received') $status = 'pending';
  $notes = trim($_POST['notes'] ?? '');
  $items_json = $_POST['items'] ?? '[]';
  $items = json_decode($items_json, true);
  if ($supplier_id <= 0) json_out(['success' => false, 'message' => 'اختر موردًا قبل الإتمام']);
  if (!is_array($items) || count($items) === 0) json_out(['success' => false, 'message' => 'الفاتورة لا يمكن أن تكون فارغة']);
  $valid_items = [];
  foreach ($items as $it) {
    $pid = intval($it['product_id'] ?? 0);
    $qty = floatval($it['qty'] ?? 0);
    $cost = floatval($it['cost_price'] ?? 0);
    if ($pid <= 0 || $qty <= 0) continue;
    $valid_items[] = ['product_id' => $pid, 'quantity' => $qty, 'cost_price' => $cost, 'total' => $qty * $cost, 'selling_price' => floatval($it['selling_price'] ?? -1)];
  }
  if (empty($valid_items)) json_out(['success' => false, 'message' => 'لا توجد بنود صالحة لإتمام الفاتورة']);
  try {
    $conn->begin_transaction();
    $created_by = $user_id ?? 0;
    $supplier_invoice_number = trim($_POST['supplier_invoice_number'] ?? '');
    $st = $conn->prepare("INSERT INTO purchase_invoices (supplier_id,supplier_invoice_number,purchase_date,notes,total_amount,status,created_by,created_at) VALUES (?, ?, ?, ?, 0, ?, ?, NOW())");
    if (!$st) {
      $conn->rollback();
      json_out(['success' => false, 'message' => 'تحضير إدخال الفاتورة فشل: ' . $conn->error]);
    }
    $st->bind_param("issssi", $supplier_id, $supplier_invoice_number, $purchase_date, $notes, $status, $created_by);
    if (!$st->execute()) {
      $err = $st->error;
      $st->close();
      $conn->rollback();
      json_out(['success' => false, 'message' => 'فشل إدخال الفاتورة: ' . $err]);
    }
    $new_invoice_id = $st->insert_id;
    $st->close();

    $ins = $conn->prepare("INSERT INTO purchase_invoice_items (purchase_invoice_id, product_id, quantity, cost_price_per_unit, total_cost, created_at, qty_received) VALUES (?, ?, ?, ?, ?, NOW(), ?)");
    if (!$ins) {
      $conn->rollback();
      json_out(['success' => false, 'message' => 'تحضير إدخال البنود فشل: ' . $conn->error]);
    }
    $created_batch_count = 0;
    foreach ($valid_items as $it) {
      $pid = $it['product_id'];
      $qty = $it['quantity'];
      $cost = $it['cost_price'];
      $total = $it['total'];
      // qty_received = quantity if fully_received, else 0
      $qty_received_insert = ($status === 'fully_received') ? $qty : 0.0;
      $ins->bind_param("iidddd", $new_invoice_id, $pid, $qty, $cost, $total, $qty_received_insert);
      if (!$ins->execute()) {
        $ins->close();
        $conn->rollback();
        json_out(['success' => false, 'message' => 'فشل إضافة بند: ' . $ins->error]);
      }
      $inserted_item_id = $conn->insert_id;

      if (isset($it['selling_price']) && floatval($it['selling_price']) >= 0) {
        $sp = floatval($it['selling_price']);
        $stps = $conn->prepare("UPDATE products SET selling_price = ? WHERE id = ?");
        $stps->bind_param("di", $sp, $pid);
        $stps->execute();
        $stps->close();
      }

      if ($status === 'fully_received') {
        // update product stock and create batch
        $upd = $conn->prepare("UPDATE products SET current_stock = current_stock + ?, cost_price = ? WHERE id = ?");
        $upd->bind_param("dii", $qty, $cost, $pid);
        if (!$upd->execute()) {
          $conn->rollback();
          json_out(['success' => false, 'message' => 'فشل تحديث رصيد المنتج: ' . $upd->error]);
        }
        $upd->close();

        $insb = $conn->prepare("INSERT INTO batches (product_id, qty, remaining, original_qty, unit_cost, received_at, source_invoice_id, source_item_id, status, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, CURDATE(), ?, ?, 'active', ?, NOW(), NOW())");
        $insb->bind_param("iddddiii", $pid, $qty, $qty, $qty, $cost, $new_invoice_id, $inserted_item_id, $created_by);
        if (!$insb->execute()) {
          error_log("Failed to insert batch for invoice {$new_invoice_id}, item {$inserted_item_id}: " . $insb->error);
          $insb->close();
          $ins->close();
          $conn->rollback();
          json_out(['success' => false, 'message' => 'فشل إنشاء دفعة للمخزن: ' . $insb->error]);
        }
        $new_batch_id = $insb->insert_id;
        $insb->close();

        // update purchase_invoice_items.batch_id
        $up_item = $conn->prepare("UPDATE purchase_invoice_items SET batch_id = ? WHERE id = ?");
        $up_item->bind_param("ii", $new_batch_id, $inserted_item_id);
        if (!$up_item->execute()) {
          $conn->rollback();
          json_out(['success' => false, 'message' => 'فشل ربط البند بالدفعة: ' . $up_item->error]);
        }
        $up_item->close();

        $created_batch_count++;
      }
    }
    $ins->close();

    // compute grand total
    $st_sum = $conn->prepare("SELECT IFNULL(SUM(total_cost),0) AS grand_total FROM purchase_invoice_items WHERE purchase_invoice_id = ?");
    $st_sum->bind_param("i", $new_invoice_id);
    $st_sum->execute();
    $g = $st_sum->get_result()->fetch_assoc();
    $st_sum->close();
    $grand_total = floatval($g['grand_total'] ?? 0);
    $st_up = $conn->prepare("UPDATE purchase_invoices SET total_amount = ? WHERE id = ?");
    $st_up->bind_param("di", $grand_total, $new_invoice_id);
    $st_up->execute();
    $st_up->close();

    $conn->commit();

    $status_label = ($status === 'fully_received') ? 'تم الاستلام' : 'قيد الانتظار';
    json_out([
      'success' => true,
      'message' => 'تمت إضافة الفاتورة بنجاح',
      'invoice_id' => $new_invoice_id,
      'grand_total' => $grand_total,
      'status' => $status,
      'status_label' => $status_label,
      'created_batches' => $created_batch_count
    ]);
  } catch (Exception $ex) {
    if ($conn->in_transaction) $conn->rollback();
    error_log("finalize_invoice_ajax exception: " . $ex->getMessage());
    json_out(['success' => false, 'message' => 'خطأ في الخادم: ' . $ex->getMessage()]);
  }
}

// ---------------- Page rendering (load products and invoice if id) ----------------
$products_list = [];
$sqlP = "SELECT id, product_code, name, selling_price, cost_price, current_stock, unit_of_measure FROM products ORDER BY name LIMIT 2000";
if ($resP = $conn->query($sqlP)) {
  while ($r = $resP->fetch_assoc()) $products_list[] = $r;
  $resP->free();
}

// get last batch unit_cost per product (same technique)
$last_batch_costs = [];
$ids = array_map(function ($p) { return intval($p['id']); }, $products_list);
if (!empty($ids)) {
  $in = implode(',', $ids);
  $q = "SELECT b.product_id, b.unit_cost FROM batches b JOIN (SELECT product_id, MAX(id) AS maxid FROM batches WHERE product_id IN ($in) GROUP BY product_id) m ON b.product_id = m.product_id AND b.id = m.maxid";
  if ($res = $conn->query($q)) {
    while ($rr = $res->fetch_assoc()) {
      $last_batch_costs[intval($rr['product_id'])] = floatval($rr['unit_cost']);
    }
    $res->free();
  }
}

$next_invoice_id = null;
{
  $res = $conn->query("SELECT DATABASE() as db");
  if ($res) {
    $row = $res->fetch_assoc();
    $db = $row['db'];
    $res->free();
    if ($db) {
      $stmtAi = $conn->prepare("SELECT AUTO_INCREMENT FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'purchase_invoices' LIMIT 1");
      if ($stmtAi) {
        $stmtAi->bind_param("s", $db);
        $stmtAi->execute();
        $r = $stmtAi->get_result()->fetch_assoc();
        $next_invoice_id = $r['AUTO_INCREMENT'] ?? null;
        $stmtAi->close();
      }
    }
  }
}

$invoice = null;
$invoice_id = 0;
$items = [];
if (isset($_GET['id']) && is_numeric($_GET['id'])) {
  $invoice_id = intval($_GET['id']);
  $st = $conn->prepare("SELECT pi.*, s.name as supplier_name, u.username as creator_name FROM purchase_invoices pi LEFT JOIN suppliers s ON pi.supplier_id = s.id LEFT JOIN users u ON pi.created_by = u.id WHERE pi.id = ? LIMIT 1");
  $st->bind_param("i", $invoice_id);
  $st->execute();
  $invoice = $st->get_result()->fetch_assoc();
  $st->close();
  if ($invoice) {
    $s2 = $conn->prepare("SELECT id, product_id, quantity, cost_price_per_unit, total_cost, qty_received, batch_id FROM purchase_invoice_items WHERE purchase_invoice_id = ? ORDER BY id ASC");
    $s2->bind_param("i", $invoice_id);
    $s2->execute();
    $res2 = $s2->get_result();
    while ($r = $res2->fetch_assoc()) $items[] = $r;
    $s2->close();
  }
}

$external_supplier_id = 0;
$external_supplier_name = '';
if (isset($_GET['supplier_id']) && is_numeric($_GET['supplier_id'])) {
  $external_supplier_id = intval($_GET['supplier_id']);
  $sts = $conn->prepare("SELECT name FROM suppliers WHERE id = ? LIMIT 1");
  if ($sts) {
    $sts->bind_param("i", $external_supplier_id);
    $sts->execute();
    $rr = $sts->get_result();
    if ($rr && $rowr = $rr->fetch_assoc()) $external_supplier_name = $rowr['name'];
    $sts->close();
  }
}

// Render UI (the rest of the original HTML/CSS/JS can be reused as-is) 
require_once BASE_DIR . 'partials/header.php';
require_once BASE_DIR . 'partials/sidebar.php';

// (Below: re-use the same full HTML/JS present in your original file for UI)
// For brevity in this response I will include the same HTML/JS as before
// — the earlier provided markup/JS already matches the AJAX endpoints above (status buttons, finalize flow).
// Ensure the JS uses the same AJAX actions and CSRF token (already done in your JS).
?>


<style>
  .view-purchase {
    height: 80vh;
  }

  /* :root{ --surface:#ffffff; --muted:#6b7280; --border:#e5e7eb; --primary:#0b84ff; --card-shadow: 0 10px 24px rgba(15,23,42,0.06);} 
  @media (prefers-color-scheme: dark) {
    :root {
      --surface: #0b1220;
      --muted: #9ca3af;
      --border: #1f2937;
      --primary: #4aa3ff;
      --card-shadow: 0 8px 28px rgba(0, 0, 0, 0.6);
    }

    body {
      background: #071019;
      color: #e6eef8;
    }
  } */

  .soft {
    background: var(--surface);
    border-radius: 12px;
    padding: 14px;
    box-shadow: var(--card-shadow);
    border: 1px solid var(--border);
  }

  .product-item {
    display: flex;
    justify-content: space-between;
    gap: 10px;
    padding: 10px;
    border-radius: 10px;
    margin-bottom: 8px;
    cursor: pointer;
    border: 1px solid transparent;
    transition: all .12s;
  }

  .product-item:hover {
    transform: translateY(-3px);
    border-color: rgba(11, 132, 255, 0.06);
  }

  .badge-out {
    background: #c00;
    color: #fff;
    padding: 3px 8px;
    border-radius: 8px;
    font-size: 12px;
  }

  .items-wrapper {
    max-height: 460px;
    overflow: auto;
    border-radius: 8px;
    border: 1px solid var(--border);
  }

  .items-wrapper table thead th {
    position: sticky;
    top: 0;
    background: var(--surface);
    z-index: 5;
  }

  .status-group {
    display: flex;
    gap: 8px;
  }

  .status-btn {
    padding: 8px 12px;
    border-radius: 10px;
    cursor: pointer;
    border: 1px solid transparent;
    background: linear-gradient(180deg, rgba(255, 255, 255, 0.02), transparent);
    color: inherit;
  }

  .status-btn.active {
    background: linear-gradient(135deg, var(--primary), #7c3aed);
    color: #fff;
    box-shadow: 0 8px 20px rgba(11, 132, 255, 0.08);
  }

  .no-items {
    color: var(--muted);
    padding: 20px;
    text-align: center;
  }

  .small-muted {
    color: var(--muted);
  }

  .modal-backdrop {
    position: fixed;
    left: 0;
    top: 0;
    right: 0;
    bottom: 0;
    display: none;
    align-items: center;
    justify-content: center;
    background: rgba(2, 6, 23, 0.4);
    z-index: 9999;
  }

  .result-modal {
    background: var(--surface);
    padding: 18px;
    border-radius: 12px;
    width: 520px;
    box-shadow: 0 12px 48px rgba(2, 6, 23, 0.6);
    color: inherit;
    border: 1px solid var(--border);
  }

  .confirm-modal {
    background: var(--surface);
    padding: 18px;
    border-radius: 12px;
    width: 760px;
    max-width: 95%;
    box-shadow: 0 12px 48px rgba(2, 6, 23, 0.6);
    color: inherit;
    border: 1px solid var(--border);
  }

  .toast {
    position: fixed;
    right: 30px;
    top: 30px;
    transform: translateX(120px) scale(0.95);
    background: #111827;
    color: #fff;
    padding: 12px 18px;
    border-radius: 12px;
    box-shadow: 0 12px 32px rgba(2, 6, 23, 0.18);
    z-index: 10001;
    opacity: 0;
    transition: 
      opacity .32s cubic-bezier(.4,0,.2,1),
      transform .32s cubic-bezier(.4,0,.2,1);
    min-width: 220px;
    max-width: 340px;
    font-size: 1rem;
    pointer-events: none;
    }

    .toast.show {
    opacity: 1;
    transform: translateX(0) scale(1);
    pointer-events: auto;
    }
  

  .toast.success {
    background: linear-gradient(90deg, #10b981, #059669);
  }

  .toast.error {
    background: linear-gradient(90deg, #ef4444, #dc2626);
  }

  @media print {
    .no-print {
      display: none !important;
    }

    /* print minimal header */
    .container {
      margin: 0;
      padding: 0;
    }

    .items-wrapper table {
      font-size: 12pt;
    }
  }
</style>

<div class="view-purchase">
  <div class="container mt-4 mb-5">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <div>
        <h3 class="mb-0">تفاصيل فاتورة مشتريات <?php if ($invoice) echo '#' . intval($invoice['id']); ?></h3>
        <div class="small-muted">
          <?php if ($invoice): ?>
            رقم الفاتورة: <strong>#<?php echo intval($invoice['id']); ?></strong>
            — المورد: <?php echo e($invoice['supplier_name'] ?? '-'); ?> — الحالة: <strong id="currentStatusLabel"><?php echo e(($invoice['status'] ?? 'pending') === 'fully_received' ? 'تم الاستلام' : 'قيد الانتظار'); ?></strong>
          <?php else: ?>
            إنشاء فاتورة جديدة — المتوقع رقم: <strong>#<?php echo intval($next_invoice_id ?? 0); ?></strong>
            — افتراضياً: قيد الانتظار.
            <?php if ($external_supplier_id): ?>
              — المورد المحدد: <strong><?php echo e($external_supplier_name); ?></strong>
            <?php else: ?>
              — <span class="text-danger">لم يتم تمرير مورد؛ مرّر supplier_id في رابط الصفحة أو افتح فاتورة مرتبطة بمورد.</span>
            <?php endif; ?>
          <?php endif; ?>
        </div>
        <?php if ($invoice): ?>
          <div class="small-muted">أنشئت بواسطة: <?php echo e($invoice['creator_name'] ?? '-'); ?> — تاريخ الإنشاء: <?php echo e($invoice['created_at'] ?? '-'); ?></div>
        <?php endif; ?>
      </div>
      <div class="btn-group no-print">
        <a href="<?php echo BASE_URL; ?>admin/manage_purchase_invoices.php" class="btn btn-outline-secondary">رجوع</a>
        <button class="btn btn-secondary" onclick="window.print();">طباعة</button>
      </div>
    </div>

    <div class="row g-3">
      <!-- left: products -->
      <div class="col-lg-4">
        <div class="soft">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
            <strong>المنتجات</strong>
            <input id="product_search" placeholder="بحث باسم أو كود..." style="padding:6px;border-radius:6px;border:1px solid var(--border); width:58%">
          </div>
          <div id="product_list" style="max-height:520px; overflow:auto;">
            <?php foreach ($products_list as $p):
              $id = intval($p['id']);
              $default_cost = isset($last_batch_costs[$id]) ? $last_batch_costs[$id] : floatval($p['cost_price']);
              $o = floatval($p['current_stock']) <= 0 ? ' out-of-stock' : '';
            ?>
              <div class="product-item<?php echo $o; ?>" data-id="<?php echo $id; ?>" data-name="<?php echo e($p['name']); ?>" data-code="<?php echo e($p['product_code']); ?>" data-cost="<?php echo $default_cost; ?>" data-selling="<?php echo floatval($p['selling_price']); ?>" data-stock="<?php echo floatval($p['current_stock']); ?>">
                <div>
                  <div style="font-weight:700"><?php echo e($p['name']); ?></div>
                  <div class="small-muted">كود: <?php echo e($p['product_code']); ?> — رصيد: <span class="stock-number"><?php echo e($p['current_stock']); ?></span></div>
                  <div class="small-muted" style="font-size:12px;">سعر شراء (أحدث): <?php echo number_format($default_cost, 2); ?> ج.م — سعر بيع: <?php echo number_format(floatval($p['selling_price']), 2); ?> ج.م</div>
                </div>
                <div style="text-align:left">
                  <div style="font-weight:700"><?php echo number_format($default_cost, 2); ?> ج.م</div>
                  <?php if (floatval($p['current_stock']) <= 0): ?><div class="badge-out">نفذ</div><?php endif; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>

          <div style="display:flex; gap:8px; margin-top:10px; align-items:center;">
            <div style="flex:1">
              <label class="small-muted">كمية افتراضية</label>
              <input id="default_qty" type="number" class="form-control form-control-sm" value="1.00" step="0.01" min="0.01">
            </div>
            <div style="width:120px;text-align:right">
              <button id="open_cart" class="btn btn-primary" style="margin-top:22px;">العناصر (<span id="cart_count">0</span>)</button>
            </div>
          </div>
        </div>
      </div>

      <!-- right: invoice header & items -->
      <div class="col-lg-8">
        <div class="soft mb-3">
          <div class="d-flex justify-content-between align-items-center">
            <div>
              <h5 class="mb-1">بيانات الفاتورة</h5>
              <?php if (!$invoice && $external_supplier_id): ?>
                <div class="small-muted">المورد المحدد: <strong><?php echo e($external_supplier_name); ?></strong></div>
              <?php endif; ?>
            </div>
            <div>
              <div class="status-group" role="tablist" aria-label="حالة الفاتورة">
                <?php
                $statuses = ['pending' => 'قيد الانتظار', 'fully_received' => 'تم الاستلام'];
                $cur = $invoice['status'] ?? 'pending';
                foreach ($statuses as $k => $label) {
                  $active = ($k === $cur) ? ' active' : '';
                  echo "<div class=\"status-btn$active\" data-status=\"$k\">$label</div>";
                }
                ?>
              </div>
            </div>
          </div>
        </div>

        <div class="soft mb-3">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <strong>بنود الفاتورة</strong>
            <div class="small-muted">سعر الشراء يُستخدم لحساب إجمالي الفاتورة (افتراضي: آخر سعر دفعة أو السعر المرجعي).</div>
          </div>

          <div class="items-wrapper">
            <table class="table table-hover mb-0">
              <thead class="table-light">
                <tr>
                  <th style="width:40px">#</th>
                  <th>المنتج</th>
                  <th class="text-center" style="width:120px">الكمية</th>
                  <th class="text-end" style="width:140px">سعر الشراء</th>
                  <th class="text-end" style="width:140px">سعر البيع</th>
                  <th class="text-end" style="width:140px">إجمالي (سعر الشراء)</th>
                  <th class="text-center no-print" style="width:90px">إجراء</th>
                </tr>
              </thead>
              <tbody id="items_tbody">
                <?php if (!empty($items)): $i = 1;
                  foreach ($items as $it): ?>
                    <?php $pname = '#' . $it['product_id'];
                    foreach ($products_list as $pp) if ($pp['id'] == $it['product_id']) {
                      $pname = $pp['name'];
                      $last_sell = $pp['selling_price'];
                      break;
                    } ?>
                    <tr data-item-id="<?php echo $it['id']; ?>" data-product-id="<?php echo $it['product_id']; ?>">
                      <td><?php echo $i++; ?></td>
                      <td><?php echo e($pname); ?></td>
                      <td class="text-center"><input class="form-control form-control-sm item-qty text-center" value="<?php echo number_format($it['quantity'], 2); ?>" step="0.01" min="0" style="width:100px; margin:auto"></td>
                      <td class="text-end"><input class="form-control form-control-sm item-cost text-end" value="<?php echo number_format($it['cost_price_per_unit'], 2); ?>" step="0.01" min="0" style="width:110px; display:inline-block"> ج.م</td>
                      <td class="text-end"><input class="form-control form-control-sm item-selling text-end" value="<?php echo number_format($last_sell ?? 0, 2); ?>" step="0.01" min="0" style="width:110px; display:inline-block"> ج.م</td>
                      <td class="text-end fw-bold item-total"><?php echo number_format($it['total_cost'], 2); ?> ج.م</td>
                      <td class="text-center no-print"><button class="btn btn-sm btn-danger btn-delete-item" data-item-id="<?php echo $it['id']; ?>">حذف</button></td>
                    </tr>
                  <?php endforeach;
                else: ?>
                  <tr id="no-items-row">
                    <td colspan="7" class="no-items">لا توجد بنود بعد — اختر منتجاً لإضافته.</td>
                  </tr>
                <?php endif; ?>
              </tbody>
              <tfoot>
                <tr class="table-light">
                  <td colspan="5" class="text-end fw-bold">الإجمالي الكلي (سعر الشراء):</td>
                  <td class="text-end fw-bold" id="grand_total">0.00 ج.م</td>
                  <td></td>
                </tr>
              </tfoot>
            </table>
          </div>

          <div class="d-flex justify-content-between align-items-center mt-2">
            <div>
              <button id="finalizeBtn" class="btn btn-success no-print">إتمام الفاتورة</button>
            </div>
            <div class="small-muted">ملاحظة: الفاتورة لا تُؤثر على المخزون إلا عند اختيار الحالة "تم الاستلام" أو تغيير الحالة لاحقاً.</div>
          </div>

        </div>

        <div class="soft no-print">
          <div><strong>ملاحظة الفاتورة (لن تُطبع):</strong></div>
          <?php if ($invoice): ?>
            <div class="small-muted invoice-notes no-print"><?php echo nl2br(e($invoice['notes'] ?? '-')); ?></div>
          <?php else: ?>
            <!-- حقل ملاحظات قابل للتحرير عند إنشاء فاتورة جديدة -->
            <textarea id="invoice_notes" class="form-control" rows="3" placeholder="اكتب ملاحظات الفاتورة هنا (لن تظهر عند الطباعة)"></textarea>
          <?php endif; ?>
        </div>

      </div>

    </div>
  </div>

</div>
<!-- Confirm Modal (custom, full preview + choose status) -->
<div id="modalConfirm" class="modal-backdrop" aria-hidden="true" role="dialog" aria-modal="true">
  <div class="confirm-modal" role="document">
    <h4>تأكيد إتمام الفاتورة</h4>
    <div style="margin-top:8px;">
      <div style="display:flex; gap:12px; align-items:center; margin-bottom:6px;">
        <div><strong>اختر حالة الفاتورة:</strong></div>
        <div>
          <label style="margin-right:8px;"><input type="radio" name="confirm_status" value="pending" checked> قيد الانتظار</label>
          <label><input type="radio" name="confirm_status" value="fully_received"> تم الاستلام (إضافة دفعات للمخزن)</label>
        </div>
      </div>
      <div id="confirmPreviewList" style="max-height:260px; overflow:auto; border:1px solid var(--border); padding:8px; border-radius:8px; background: #fafafa;"></div>
      <div style="display:flex; justify-content:space-between; align-items:center; margin-top:12px;">
        <div>
          <button id="confirmCancel" class="btn btn-outline-secondary">إلغاء والعودة</button>
          <button id="confirmSend" class="btn btn-success">تأكيد وإرسال</button>
        </div>
        <div><strong>الإجمالي:</strong> <span id="confirm_total">0.00</span> ج.م</div>
      </div>
    </div>
  </div>
</div>

<!-- Result Modal (non-dismissible except by 'فهمت' button) -->
<div id="modalResult" class="modal-backdrop" aria-hidden="true" role="dialog" aria-modal="true">
  <div class="result-modal" role="document">
    <h4 id="result_title">نتيجة العملية</h4>
    <div id="result_body" style="margin-top:8px;"></div>
    <div style="display:flex; justify-content:space-between; align-items:center; margin-top:16px;">
      <div>
        <!-- <button id="result_view" class="btn btn-primary">عرض الفاتورة</button> -->
        <button id="result_ok" class="btn btn-outline-secondary">فهمت — العودة للموردين</button>
      </div>
      <div id="result_summary" style="font-weight:700"></div>
    </div>
  </div>
</div>

<div id="toast_holder"></div>

<script>
  document.addEventListener('DOMContentLoaded', function() {
    function q(sel, ctx = document) {
      return ctx.querySelector(sel);
    }

    function qa(sel, ctx = document) {
      return Array.from(ctx.querySelectorAll(sel));
    }

    function escapeHtml(s) {
      return String(s || '').replace(/[&<>'\"']/g, function(m) {
        return {
          '&': '&amp;',
          '<': '&lt;',
          '>': '&gt;',
          '\"': '&quot;',
          "'": '&#39;'
        } [m];
      });
    }

    const csrf = <?php echo json_encode($csrf_token); ?>;
    const invoiceId = <?php echo intval($invoice_id ?: 0); ?>;
    const externalSupplierId = <?php echo json_encode($external_supplier_id); ?>;
    const externalSupplierName = <?php echo json_encode($external_supplier_name); ?>;
    const invoiceSupplierId = <?php echo json_encode(intval($invoice['supplier_id'] ?? 0)); ?>;
    const predictedInvoiceId = <?php echo intval($next_invoice_id ?? 0); ?>;

    const productsLocal = Array.from(qa('.product-item')).map(el => ({
      id: parseInt(el.dataset.id || 0, 10),
      name: el.dataset.name,
      code: el.dataset.code,
      cost: parseFloat(el.dataset.cost || 0),
      selling: parseFloat(el.dataset.selling || 0),
      stock: parseFloat(el.dataset.stock || 0),
      el: el
    }));

    q('#product_search').addEventListener('input', function(e) {
      const qv = (e.target.value || '').trim().toLowerCase();
      productsLocal.forEach(p => {
        const show = !qv || (p.name && p.name.toLowerCase().includes(qv)) || (p.code && p.code.toLowerCase().includes(qv));
        p.el.style.display = show ? '' : 'none';
      });
    });

    function showToast(msg, type = 'info') {
      const id = 't' + Date.now();
      const div = document.createElement('div');
      div.className = 'toast ' + (type === 'success' ? 'success' : type === 'error' ? 'error' : '');
      div.id = id;
      div.innerText = msg;
      q('#toast_holder').appendChild(div);
      setTimeout(() => div.classList.add('show'), 10);
      setTimeout(() => {
        div.classList.remove('show');
        setTimeout(() => div.remove(), 300);
      }, 3500);
    }

    function renderCartCount() {
      q('#cart_count').textContent = qa('#items_tbody tr').filter(tr => tr.id !== 'no-items-row').length;
    }

    function renderGrand() {
      let g = 0;
      qa('#items_tbody tr').forEach(tr => {
        if (tr.id === 'no-items-row') return;
        const text = tr.querySelector('.item-total')?.innerText || '0';
        const num = parseFloat(String(text).replace(/[^\d.-]/g, '')) || 0;
        g += num;
      });
      q('#grand_total').innerText = g.toFixed(2) + ' ج.م';
      return g;
    }

    q('#product_list').addEventListener('click', function(e) {
      const it = e.target.closest('.product-item');
      if (!it) return;
      const pid = parseInt(it.dataset.id, 10);
      const p = productsLocal.find(x => x.id === pid);
      if (!p) return;
      const qty = parseFloat(q('#default_qty').value) || 1;
      if (invoiceId) {
        const form = new URLSearchParams();
        form.append('action', 'add_item_ajax');
        form.append('csrf_token', csrf);
        form.append('invoice_id', invoiceId);
        form.append('product_id', pid);
        form.append('quantity', qty);
        form.append('cost_price', p.cost);
        form.append('selling_price', p.selling);
        fetch(location.pathname, {
          method: 'POST',
          body: form
        }).then(r => r.json()).then(d => {
          if (!d.success) {
            showToast(d.message || 'فشل', 'error');
            return;
          }
          const itRow = d.item;
          const existing = qa('#items_tbody tr').find(tr => parseInt(tr.dataset.productId || 0, 10) === parseInt(itRow.product_id, 10));
          if (existing) {
            existing.dataset.itemId = itRow.item_id_pk;
            existing.dataset.productId = itRow.product_id;
            existing.children[2].querySelector('.item-qty').value = parseFloat(itRow.quantity).toFixed(2);
            existing.children[3].querySelector('.item-cost').value = parseFloat(itRow.cost_price_per_unit).toFixed(2);
            existing.children[4].querySelector('.item-selling').value = parseFloat(p.selling).toFixed(2);
            existing.querySelector('.item-total').innerText = parseFloat(itRow.total_cost).toFixed(2) + ' ج.م';
            attachRowHandlers(existing);
          } else {
            const tr = document.createElement('tr');
            tr.dataset.itemId = itRow.item_id_pk;
            tr.dataset.productId = itRow.product_id;
            const idx = qa('#items_tbody tr').length;
            tr.innerHTML = `<td>${idx}</td><td>${escapeHtml(itRow.product_name)}</td><td class="text-center"><input class="form-control form-control-sm item-qty text-center" value="${parseFloat(itRow.quantity).toFixed(2)}" step="0.01" min="0" style="width:100px;margin:auto"></td><td class="text-end"><input class="form-control form-control-sm item-cost text-end" value="${parseFloat(itRow.cost_price_per_unit).toFixed(2)}" step="0.01" min="0" style="width:110px;display:inline-block"> ج.م</td><td class="text-end"><input class="form-control form-control-sm item-selling text-end" value="${parseFloat(p.selling).toFixed(2)}" step="0.01" min="0" style="width:110px;display:inline-block"> ج.م</td><td class="text-end fw-bold item-total">${parseFloat(itRow.total_cost).toFixed(2)} ج.م</td><td class="text-center no-print"><button class="btn btn-sm btn-danger btn-delete-item" data-item-id="${itRow.item_id_pk}">حذف</button></td>`;
            const noRow = q('#no-items-row');
            if (noRow) noRow.remove();
            q('#items_tbody').appendChild(tr);
            attachRowHandlers(tr);
            renderGrand();
          }
          showToast(d.message || 'تمت الإضافة', 'success');
          renderGrand();
          renderCartCount();
        }).catch(err => {
          console.error(err);
          showToast('خطأ في الاتصال', 'error');
        });
      } else {
        const existing = qa('#items_tbody tr').find(tr => parseInt(tr.dataset.productId || 0, 10) === pid && !tr.dataset.itemId);
        if (existing) {
          const qel = existing.querySelector('.item-qty');
          qel.value = (parseFloat(qel.value) || 0) + qty;
          existing.querySelector('.item-qty').dispatchEvent(new Event('input'));
          return;
        }
        const noRow = q('#no-items-row');
        if (noRow) noRow.remove();
        const idx = qa('#items_tbody tr').length;
        const tr = document.createElement('tr');
        tr.dataset.productId = pid;
        tr.innerHTML = `<td>${idx}</td><td>${escapeHtml(p.name)}</td><td class="text-center"><input class="form-control form-control-sm item-qty text-center" value="${qty.toFixed(2)}" step="0.01" min="0" style="width:100px;margin:auto"></td><td class="text-end"><input class="form-control form-control-sm item-cost text-end" value="${parseFloat(p.cost).toFixed(2)}" step="0.01" min="0" style="width:110px;display:inline-block"> ج.م</td><td class="text-end"><input class="form-control form-control-sm item-selling text-end" value="${parseFloat(p.selling).toFixed(2)}" step="0.01" min="0" style="width:110px;display:inline-block"> ج.م</td><td class="text-end fw-bold item-total">${(qty * p.cost).toFixed(2)} ج.م</td><td class="text-center no-print"><button class="btn btn-sm btn-danger remove-row">حذف</button></td>`;
        q('#items_tbody').appendChild(tr);
        attachLocalRowHandlers(tr);
        renderGrand();
        renderCartCount();
      }
    });

    function attachRowHandlers(tr) {
      const qty = tr.querySelector('.item-qty');
      const cost = tr.querySelector('.item-cost');
      const selling = tr.querySelector('.item-selling');
      const del = tr.querySelector('.btn-delete-item');
      const itemId = tr.dataset.itemId;
      if (qty) qty.addEventListener('change', function() {
        sendUpdate(itemId, tr);
      });
      if (cost) cost.addEventListener('change', function() {
        sendUpdate(itemId, tr);
      });
      if (selling) selling.addEventListener('change', function() {
        sendUpdate(itemId, tr);
      });
      if (del) del.addEventListener('click', function() {
        if (!confirm('هل تريد حذف هذا البند؟')) return;
        const form = new URLSearchParams();
        form.append('action', 'delete_item_ajax');
        form.append('csrf_token', csrf);
        form.append('item_id', itemId);
        fetch(location.pathname, {
          method: 'POST',
          body: form
        }).then(r => r.json()).then(d => {
          if (!d.success) {
            showToast(d.message || 'خطأ', 'error');
            return;
          }
          tr.remove();
          renumberRows();
          q('#grand_total').innerText = parseFloat(d.grand_total).toFixed(2) + ' ج.م';
          showToast(d.message || 'تم الحذف', 'success');
          renderCartCount();
        }).catch(e => {
          console.error(e);
          showToast('خطأ في الاتصال', 'error');
        });
      });
    }

    function sendUpdate(itemId, tr) {
      const qv = parseFloat(tr.querySelector('.item-qty').value) || 0;
      const cv = parseFloat(tr.querySelector('.item-cost').value) || 0;
      const sv = parseFloat(tr.querySelector('.item-selling').value) || 0;
      const form = new URLSearchParams();
      form.append('action', 'update_item_ajax');
      form.append('csrf_token', csrf);
      form.append('item_id', itemId);
      form.append('quantity', qv);
      form.append('cost_price', cv);
      form.append('selling_price', sv);
      fetch(location.pathname, {
        method: 'POST',
        body: form
      }).then(r => r.json()).then(d => {
        if (!d.success) {
          showToast(d.message || 'خطأ', 'error');
          return;
        }
        tr.querySelector('.item-total').innerText = parseFloat(d.total_cost).toFixed(2) + ' ج.م';
        document.getElementById('grand_total').innerText = parseFloat(d.grand_total).toFixed(2) + ' ج.م';
        showToast(d.message || 'تم التحديث', 'success');
      }).catch(e => {
        console.error(e);
        showToast('خطأ في الاتصال', 'error');
      });
    }

    function attachLocalRowHandlers(tr) {
      const qty = tr.querySelector('.item-qty');
      const cost = tr.querySelector('.item-cost');
      const selling = tr.querySelector('.item-selling');
      const rem = tr.querySelector('.remove-row');
      const updateLocal = () => {
        const qv = parseFloat(qty.value) || 0;
        const cv = parseFloat(cost.value) || 0;
        tr.querySelector('.item-total').innerText = (qv * cv).toFixed(2) + ' ج.م';
        renderGrand();
      };
      if (qty) qty.addEventListener('input', updateLocal);
      if (cost) cost.addEventListener('input', updateLocal);
      if (selling) selling.addEventListener('input', updateLocal);
      if (rem) rem.addEventListener('click', function() {
        if (!confirm('حذف البنود المؤقتة؟')) return;
        tr.remove();
        if (qa('#items_tbody tr').length === 0) {
          const r = document.createElement('tr');
          r.id = 'no-items-row';
          r.innerHTML = '<td colspan="7" class="no-items">لا توجد بنود بعد — اختر منتجاً لإضافته.</td>';
          q('#items_tbody').appendChild(r);
        }
        renumberRows();
        renderGrand();
        showToast('تم الحذف', 'success');
        renderCartCount();
      });
    }

    function renumberRows() {
      qa('#items_tbody tr').forEach((r, i) => r.children[0].innerText = i + 1);
    }
    qa('#items_tbody tr').forEach(tr => {
      if (tr.id !== 'no-items-row') attachRowHandlers(tr);
    });
    renderGrand();
    renderCartCount();

    // status buttons
    qa('.status-btn').forEach(btn => btn.addEventListener('click', function() {
      qa('.status-btn').forEach(x => x.classList.remove('active'));
      this.classList.add('active');
      const newStatus = this.dataset.status;
      q('#currentStatusLabel').innerText = this.innerText;
      if (invoiceId) {
        const form = new URLSearchParams();
        form.append('action', 'change_status_ajax');
        form.append('csrf_token', csrf);
        form.append('invoice_id', invoiceId);
        form.append('new_status', newStatus);
        fetch(location.pathname, {
          method: 'POST',
          body: form
        }).then(r => r.json()).then(d => {
          if (!d.success) {
            showToast(d.message || 'فشل تغيير الحالة', 'error');
            return;
          }
          q('#currentStatusLabel').innerText = d.label || newStatus;
          showToast(d.message || 'تم تغيير الحالة', 'success');
        }).catch(err => {
          console.error(err);
          showToast('خطأ في الاتصال', 'error');
        });
      }
    }));

    // FINALIZE: open custom confirm modal (instead of browser confirm)
    q('#finalizeBtn').addEventListener('click', function() {
      const rows = qa('#items_tbody tr').filter(tr => tr.id !== 'no-items-row');
      if (rows.length === 0) return showToast('لا توجد بنود لإتمام الفاتورة', 'error');

      let supplier_id = 0;
      if (invoiceId && invoiceSupplierId) supplier_id = invoiceSupplierId;
      else if (externalSupplierId) supplier_id = externalSupplierId;
      if (!supplier_id) {
        showToast('يجب أن تحدد موردًا قبل الإتمام. مرّر supplier_id في رابط الصفحة أو افتح فاتورة مرتبطة بمورد.', 'error');
        return;
      }

      const previewDiv = q('#confirmPreviewList');
      previewDiv.innerHTML = '';
      const itemsPayload = [];
      let total = 0;
      rows.forEach(tr => {
        const pid = parseInt(tr.dataset.productId || 0, 10);
        const qv = parseFloat(tr.querySelector('.item-qty').value) || 0;
        const cv = parseFloat(tr.querySelector('.item-cost').value) || 0;
        const sv = parseFloat(tr.querySelector('.item-selling').value) || 0;
        const line = qv * cv;
        total += line;
        const rowDiv = document.createElement('div');
        rowDiv.style.display = 'flex';
        rowDiv.style.justifyContent = 'space-between';
        rowDiv.style.marginBottom = '6px';
        rowDiv.innerHTML = `<div><strong>${escapeHtml(tr.children[1].innerText)}</strong><div class="small-muted">كمية: ${qv} — سعر شراء: ${cv.toFixed(2)} — سعر بيع: ${sv.toFixed(2)}</div></div><div>${line.toFixed(2)}</div>`;
        previewDiv.appendChild(rowDiv);
        itemsPayload.push({
          product_id: pid,
          qty: qv,
          cost_price: cv,
          selling_price: sv
        });
      });
      q('#confirm_total').innerText = total.toFixed(2);

      // include notes from textarea if present (won't be printed due to .no-print)
      const notesVal = q('#invoice_notes') ? q('#invoice_notes').value.trim() : '';

      // set default status radio from active button (or pending)
      const activeBtn = qa('.status-btn').find(b => b.classList.contains('active'));
      const curStatus = activeBtn ? activeBtn.dataset.status : 'pending';
      qa('input[name="confirm_status"]').forEach(r => r.checked = (r.value === curStatus));

      // show modal
      q('#modalConfirm').style.display = 'flex';
      // prevent closing by backdrop or ESC: do not add click handler to backdrop
      // wire buttons
      q('#confirmCancel').onclick = function() {
        q('#modalConfirm').style.display = 'none';
      };
      q('#confirmSend').onclick = async function() {
        // read chosen status
        const chosen = (qa('input[name="confirm_status"]').find(r => r.checked) || {
          value: 'pending'
        }).value;
        const fd = new FormData();
        fd.append('action', 'finalize_invoice_ajax');
        fd.append('csrf_token', csrf);
        fd.append('supplier_id', supplier_id);
        fd.append('purchase_date', (new Date()).toISOString().slice(0, 10));
        fd.append('status', chosen);
        fd.append('notes', notesVal);
        fd.append('items', JSON.stringify(itemsPayload));
        try {
          const res = await fetch(location.pathname, {
            method: 'POST',
            body: fd
          });
          const text = await res.text();
          let data;
          try {
            data = JSON.parse(text);
          } catch (err) {
            console.error('Non-JSON response:', text);
            showToast('استجابة غير متوقعة من الخادم — افتح الكونسول لمزيد من التفاصيل', 'error');
            return;
          }
          if (!data.success) {
            showToast(data.message || 'فشل', 'error');
            return;
          }
          // hide confirm modal and show result modal
          q('#modalConfirm').style.display = 'none';
          // prepare result modal
          q('#result_title').innerText = (data.status === 'fully_received') ? 'أضيفت فاتورة وارد وتمت إضافة دفعات للمخزن' : 'أضيفت فاتورة وارد (مؤجلة)';
          let bodyHtml = '';
          if (data.status === 'fully_received') {
            bodyHtml += `<div>تم إنشاء الفاتورة وتمت إضافة بنودها إلى المخزن كدفعات.</div>`;
            bodyHtml += `<div class="small-muted" style="margin-top:6px">عدد الدفعات المنشأة: ${parseInt(data.created_batches||0)}</div>`;
          } else {
            bodyHtml += `<div>تم إضافة فاتورة وارد مؤجلة — لم تُنشأ دفعات في المخزن.</div>`;
          }
          bodyHtml += `<hr style="margin:8px 0">`;
          bodyHtml += `<div>رقم الفاتورة: <strong>#${data.invoice_id}</strong></div>`;
          bodyHtml += `<div>الإجمالي: <strong>${parseFloat(data.grand_total).toFixed(2)} ج.م</strong></div>`;
          q('#result_body').innerHTML = bodyHtml;
          q('#result_summary').innerText = data.status_label || '';
          q('#modalResult').style.display = 'flex';

          // disable closing by clicks: only buttons will act
          // q('#result_view').onclick = function() {
          //   window.location.href = location.pathname + '?id=' + encodeURIComponent(data.invoice_id);
          // };
          q('#result_ok').onclick = function() {
            window.location.href = '<?php echo BASE_URL; ?>admin/manage_suppliers.php';
          };

          // clear local create preview table to prepare for a new invoice
          q('#items_tbody').innerHTML = '<tr id="no-items-row"><td colspan="7" class="no-items">لا توجد بنود بعد — اختر منتجاً لإضافته.</td></tr>';
          if (q('#invoice_notes')) q('#invoice_notes').value = '';
          renderGrand();
          renderCartCount();
          showToast(data.message || 'تمت العملية', 'success');

        } catch (err) {
          console.error(err);
          showToast('خطأ في الاتصال', 'error');
        }
      };
    });

    // Prevent closing confirm/result by clicking backdrop or ESC:
    // No backdrop click handler; block ESC:
    window.addEventListener('keydown', function(ev) {
      if ((q('#modalConfirm').style.display === 'flex' || q('#modalResult').style.display === 'flex') && ev.key === 'Escape') {
        ev.preventDefault();
        ev.stopPropagation();
      }
    }, true);

    // Do not attach backdrop click close handlers (so modals are not dismissible by clicking outside)
    // End DOMContentLoaded
  });
</script>

<?php
require_once BASE_DIR . 'partials/footer.php';
ob_end_flush();
?>