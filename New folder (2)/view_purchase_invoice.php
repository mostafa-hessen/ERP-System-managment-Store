<?php
// admin/view_purchase_invoice_quick.updated.php
$page_title = "فاتورة مشتريات - سريع (مُحسّن)";
ob_start();

if (file_exists(dirname(__DIR__) . '/config.php')) {
    require_once dirname(__DIR__) . '/config.php';
} elseif (file_exists(dirname(dirname(__DIR__)) . '/config.php')) {
    require_once dirname(dirname(__DIR__)) . '/config.php';
} else {
    http_response_code(500);
    echo "خطأ داخلي: إعدادات غير موجودة.";
    exit;
}
if (!isset($conn) || !$conn) { echo "خطأ في الاتصال بقاعدة البيانات."; exit; }

function e($s){ return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

// CSRF token
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf_token = $_SESSION['csrf_token'];

// helpers
function json_out($arr){ header('Content-Type: application/json; charset=utf-8'); echo json_encode($arr, JSON_UNESCAPED_UNICODE); exit; }
function get_next_purchase_invoice_id($conn) {
    $db = null;
    $res = $conn->query("SELECT DATABASE() as db");
    if ($res) { $row = $res->fetch_assoc(); $db = $row['db']; $res->free(); }
    if (!$db) return null;
    $stmt = $conn->prepare("SELECT AUTO_INCREMENT FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'purchase_invoices' LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param("s", $db); $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc(); $stmt->close();
    return $r['AUTO_INCREMENT'] ?? null;
}

// ---------------- batch helper ----------------
function create_batch_for_item($conn, $product_id, $qty, $cost, $received_at, $source_invoice_id, $source_item_id, $created_by) {
    $stmt = $conn->prepare("INSERT INTO batches (product_id, qty, remaining, original_qty, unit_cost, received_at, source_invoice_id, source_item_id, status, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, NOW())");
    if (!$stmt) return false;
    $remaining = $qty;
    $original_qty = $qty;
    $stmt->bind_param("iddddsiii", $product_id, $qty, $remaining, $original_qty, $cost, $received_at, $source_invoice_id, $source_item_id, $created_by);
    if (!$stmt->execute()) { $stmt->close(); return false; }
    $bid = $stmt->insert_id; $stmt->close();
    return $bid;
}

/* ---------------- AJAX endpoints ---------------- */

/* search products (unchanged) */
if (isset($_GET['action']) && $_GET['action'] === 'search_products') {
    header('Content-Type: application/json; charset=utf-8');
    $q = trim($_GET['q'] ?? '');
    $out = [];
    if ($q === '') {
        $stmt = $conn->prepare("SELECT id, product_code, name, selling_price, cost_price, current_stock, unit_of_measure FROM products ORDER BY name LIMIT 1000");
        $stmt->execute(); $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) $out[] = $r; $stmt->close();
    } else {
        $like = "%$q%";
        $stmt = $conn->prepare("SELECT id, product_code, name, selling_price, cost_price, current_stock, unit_of_measure FROM products WHERE name LIKE ? OR product_code LIKE ? ORDER BY name LIMIT 1000");
        $stmt->bind_param("ss", $like, $like);
        $stmt->execute(); $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) $out[] = $r; $stmt->close();
    }
    json_out(['ok'=>true,'results'=>$out]);
}

/* Add item to existing invoice (AJAX) - as before (handles fully_received) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_item_ajax') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) json_out(['success'=>false,'message'=>'CSRF']);
    $invoice_id = intval($_POST['invoice_id'] ?? 0);
    $product_id = intval($_POST['product_id'] ?? 0);
    $qty = floatval($_POST['quantity'] ?? 0);
    $cost = floatval($_POST['cost_price'] ?? 0);
    $selling = floatval($_POST['selling_price'] ?? -1);
    if ($invoice_id <=0 || $product_id<=0 || $qty<=0) json_out(['success'=>false,'message'=>'بيانات غير صحيحة']);
    try {
        $conn->begin_transaction();

        $st_status = $conn->prepare("SELECT status, purchase_date FROM purchase_invoices WHERE id = ? LIMIT 1");
        $st_status->bind_param("i", $invoice_id); $st_status->execute();
        $inv_row = $st_status->get_result()->fetch_assoc(); $st_status->close();
        $inv_status = $inv_row['status'] ?? 'pending';
        $inv_purchase_date = $inv_row['purchase_date'] ?? date('Y-m-d');

        $st_check = $conn->prepare("SELECT id, quantity, batch_id FROM purchase_invoice_items WHERE purchase_invoice_id = ? AND product_id = ? LIMIT 1");
        $st_check->bind_param("ii", $invoice_id, $product_id); $st_check->execute(); $res_check = $st_check->get_result(); $existing = $res_check->fetch_assoc(); $st_check->close();

        if ($existing) {
            $old_qty = floatval($existing['quantity']);
            $new_qty = $old_qty + $qty;
            $new_total = $new_qty * $cost;
            $st_up = $conn->prepare("UPDATE purchase_invoice_items SET quantity = ?, cost_price_per_unit = ?, total_cost = ?, updated_at = NOW() WHERE id = ?");
            $st_up->bind_param("dddi", $new_qty, $cost, $new_total, $existing['id']);
            if (!$st_up->execute()) { $conn->rollback(); json_out(['success'=>false,'message'=>'فشل التحديث: '.$st_up->error]); }
            $st_up->close();

            if ($inv_status === 'fully_received') {
                $delta = $qty;
                $batch_id = intval($existing['batch_id'] ?? 0);
                if ($batch_id > 0) {
                    $upd = $conn->prepare("UPDATE batches SET qty = qty + ?, remaining = remaining + ?, original_qty = original_qty + ?, unit_cost = ?, adjusted_by = ?, adjusted_at = NOW() WHERE id = ?");
                    $created_by = $_SESSION['id'] ?? null;
                    $upd->bind_param("dddiii", $delta, $delta, $delta, $cost, $created_by, $batch_id);
                    $upd->execute(); $upd->close();
                } else {
                    $st_item_id = intval($existing['id']);
                    $new_batch_id = create_batch_for_item($conn, $product_id, $delta, $cost, $inv_purchase_date, $invoice_id, $st_item_id, $_SESSION['id'] ?? null);
                    if ($new_batch_id) {
                        $upd_blink = $conn->prepare("UPDATE purchase_invoice_items SET batch_id = ? WHERE id = ?");
                        $upd_blink->bind_param("ii", $new_batch_id, $st_item_id); $upd_blink->execute(); $upd_blink->close();
                    }
                }
                $upd_prod = $conn->prepare("UPDATE products SET current_stock = current_stock + ?, cost_price = ? WHERE id = ?");
                $upd_prod->bind_param("ddi", $delta, $cost, $product_id); $upd_prod->execute(); $upd_prod->close();
            }

            if ($selling >= 0) {
                $stps = $conn->prepare("UPDATE products SET selling_price = ? WHERE id = ?"); $stps->bind_param("di", $selling, $product_id); $stps->execute(); $stps->close();
            }

            $st_sum = $conn->prepare("SELECT IFNULL(SUM(total_cost),0) AS grand_total FROM purchase_invoice_items WHERE purchase_invoice_id = ?");
            $st_sum->bind_param("i", $invoice_id); $st_sum->execute(); $res = $st_sum->get_result()->fetch_assoc(); $st_sum->close();
            $grand_total = floatval($res['grand_total']);
            $st_up_inv = $conn->prepare("UPDATE purchase_invoices SET total_amount = ? WHERE id = ?"); $st_up_inv->bind_param("di", $grand_total, $invoice_id); $st_up_inv->execute(); $st_up_inv->close();

            $st_item = $conn->prepare("SELECT p_item.id as item_id_pk, p_item.product_id, p_item.quantity, p_item.cost_price_per_unit, p_item.total_cost, p_item.batch_id, p.product_code, p.name as product_name, p.unit_of_measure FROM purchase_invoice_items p_item JOIN products p ON p_item.product_id = p.id WHERE p_item.id = ?");
            $st_item->bind_param("i", $existing['id']); $st_item->execute(); $item_row = $st_item->get_result()->fetch_assoc(); $st_item->close();

            $conn->commit();
            json_out(['success'=>true,'message'=>'تم تحديث البند','item'=>$item_row,'grand_total'=>$grand_total]);

        } else {
            $total = $qty * $cost;
            $st = $conn->prepare("INSERT INTO purchase_invoice_items (purchase_invoice_id, product_id, quantity, cost_price_per_unit, total_cost, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
            $st->bind_param("iiddd", $invoice_id, $product_id, $qty, $cost, $total);
            if (!$st->execute()) { $conn->rollback(); json_out(['success'=>false,'message'=>'فشل الإدخال: '.$st->error]); }
            $new_item_id = $st->insert_id; $st->close();

            if ($inv_status === 'fully_received') {
                $new_batch_id = create_batch_for_item($conn, $product_id, $qty, $cost, $inv_purchase_date, $invoice_id, $new_item_id, $_SESSION['id'] ?? null);
                if ($new_batch_id) {
                    $stub = $conn->prepare("UPDATE purchase_invoice_items SET batch_id = ? WHERE id = ?");
                    $stub->bind_param("ii", $new_batch_id, $new_item_id); $stub->execute(); $stub->close();
                }
                $upd = $conn->prepare("UPDATE products SET current_stock = current_stock + ?, cost_price = ? WHERE id = ?");
                $upd->bind_param("ddi", $qty, $cost, $product_id); $upd->execute(); $upd->close();
            }

            if ($selling >= 0) {
                $stps = $conn->prepare("UPDATE products SET selling_price = ? WHERE id = ?"); $stps->bind_param("di", $selling, $product_id); $stps->execute(); $stps->close();
            }

            $st_sum = $conn->prepare("SELECT IFNULL(SUM(total_cost),0) AS grand_total FROM purchase_invoice_items WHERE purchase_invoice_id = ?");
            $st_sum->bind_param("i", $invoice_id); $st_sum->execute(); $res = $st_sum->get_result()->fetch_assoc(); $st_sum->close();
            $grand_total = floatval($res['grand_total']);
            $st_up = $conn->prepare("UPDATE purchase_invoices SET total_amount = ? WHERE id = ?");
            $st_up->bind_param("di", $grand_total, $invoice_id); $st_up->execute(); $st_up->close();
            $conn->commit();

            $st_item = $conn->prepare("SELECT p_item.id as item_id_pk, p_item.product_id, p_item.quantity, p_item.cost_price_per_unit, p_item.total_cost, p_item.batch_id, p.product_code, p.name as product_name, p.unit_of_measure FROM purchase_invoice_items p_item JOIN products p ON p_item.product_id = p.id WHERE p_item.id = ?");
            $st_item->bind_param("i", $new_item_id); $st_item->execute(); $item_row = $st_item->get_result()->fetch_assoc(); $st_item->close();
            json_out(['success'=>true,'message'=>'تم الإضافة','item'=>$item_row,'grand_total'=>$grand_total]);
        }
    } catch (Exception $ex) {
        if ($conn->in_transaction) $conn->rollback(); json_out(['success'=>false,'message'=>'خطأ: '.$ex->getMessage()]);
    }
}

/* Update item AJAX (edit qty, cost, selling) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_item_ajax') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) json_out(['success'=>false,'message'=>'CSRF']);
    $item_id = intval($_POST['item_id'] ?? 0);
    $qty = floatval($_POST['quantity'] ?? 0);
    $cost = floatval($_POST['cost_price'] ?? 0);
    $selling = floatval($_POST['selling_price'] ?? -1);
    if ($item_id<=0 || $qty<0) json_out(['success'=>false,'message'=>'بيانات غير صحيحة']);
    try {
        $conn->begin_transaction();

        $st_old = $conn->prepare("SELECT purchase_invoice_id, product_id, quantity, cost_price_per_unit, batch_id FROM purchase_invoice_items WHERE id = ? LIMIT 1");
        $st_old->bind_param("i", $item_id); $st_old->execute(); $old = $st_old->get_result()->fetch_assoc(); $st_old->close();
        if (!$old) { $conn->rollback(); json_out(['success'=>false,'message'=>'البند غير موجود']); }

        $old_qty = floatval($old['quantity']);
        $product_id = intval($old['product_id']);
        $batch_id = intval($old['batch_id'] ?? 0);
        $invoice_id = intval($old['purchase_invoice_id']);

        $st_status = $conn->prepare("SELECT status, purchase_date FROM purchase_invoices WHERE id = ? LIMIT 1");
        $st_status->bind_param("i", $invoice_id); $st_status->execute(); $inv = $st_status->get_result()->fetch_assoc(); $st_status->close();
        $inv_status = $inv['status'] ?? 'pending';
        $inv_purchase_date = $inv['purchase_date'] ?? date('Y-m-d');

        $total = $qty * $cost;
        $st = $conn->prepare("UPDATE purchase_invoice_items SET quantity = ?, cost_price_per_unit = ?, total_cost = ?, updated_at = NOW() WHERE id = ?");
        $st->bind_param("dddi", $qty, $cost, $total, $item_id);
        if (!$st->execute()) { $conn->rollback(); json_out(['success'=>false,'message'=>'فشل التحديث: '.$st->error]); }
        $st->close();

        if ($selling >= 0) {
            $stp = $conn->prepare("UPDATE products SET selling_price = ? WHERE id = ?");
            if ($stp) { $stp->bind_param("di", $selling, $product_id); $stp->execute(); $stp->close(); }
        }

        if ($inv_status === 'fully_received') {
            $delta = $qty - $old_qty;
            if ($delta != 0) {
                $upd_prod = $conn->prepare("UPDATE products SET current_stock = current_stock + ?, cost_price = ? WHERE id = ?");
                $upd_prod->bind_param("ddi", $delta, $cost, $product_id);
                $upd_prod->execute(); $upd_prod->close();

                if ($batch_id > 0) {
                    if ($delta > 0) {
                        $upd_batch = $conn->prepare("UPDATE batches SET qty = qty + ?, remaining = remaining + ?, original_qty = original_qty + ?, unit_cost = ?, adjusted_by = ?, adjusted_at = NOW() WHERE id = ?");
                        $adj_by = $_SESSION['id'] ?? null;
                        $upd_batch->bind_param("dddiii", $delta, $delta, $delta, $cost, $adj_by, $batch_id);
                        $upd_batch->execute(); $upd_batch->close();
                    } else {
                        $dec = abs($delta);
                        $get_rem = $conn->prepare("SELECT remaining FROM batches WHERE id = ? LIMIT 1");
                        $get_rem->bind_param("i", $batch_id); $get_rem->execute(); $rr = $get_rem->get_result()->fetch_assoc(); $get_rem->close();
                        $rem = floatval($rr['remaining'] ?? 0);
                        $new_rem = max(0, $rem - $dec);
                        $upd_batch = $conn->prepare("UPDATE batches SET qty = qty - ?, remaining = ?, adjusted_by = ?, adjusted_at = NOW() WHERE id = ?");
                        $adj_by = $_SESSION['id'] ?? null;
                        $upd_batch->bind_param("ddii", $dec, $new_rem, $adj_by, $batch_id);
                        $upd_batch->execute(); $upd_batch->close();
                    }
                } else {
                    if ($delta > 0) {
                        $new_batch_id = create_batch_for_item($conn, $product_id, $delta, $cost, $inv_purchase_date, $invoice_id, $item_id, $_SESSION['id'] ?? null);
                        if ($new_batch_id) {
                            $blink = $conn->prepare("UPDATE purchase_invoice_items SET batch_id = ? WHERE id = ?");
                            $blink->bind_param("ii", $new_batch_id, $item_id); $blink->execute(); $blink->close();
                        }
                    }
                }
            }
        }

        $st_sum = $conn->prepare("SELECT IFNULL(SUM(total_cost),0) AS grand_total FROM purchase_invoice_items WHERE purchase_invoice_id = ?");
        $st_sum->bind_param("i", $invoice_id); $st_sum->execute(); $res = $st_sum->get_result()->fetch_assoc(); $st_sum->close();
        $grand_total = floatval($res['grand_total']);

        $st_up = $conn->prepare("UPDATE purchase_invoices SET total_amount = ? WHERE id = ?");
        $st_up->bind_param("di", $grand_total, $invoice_id); $st_up->execute(); $st_up->close();

        $conn->commit();
        json_out(['success'=>true,'message'=>'تم التحديث','total_cost'=>$total,'grand_total'=>$grand_total]);
    } catch (Exception $ex) {
        if ($conn->in_transaction) $conn->rollback();
        json_out(['success'=>false,'message'=>'خطأ: '.$ex->getMessage()]);
    }
}

/* Delete item AJAX */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_item_ajax') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) json_out(['success'=>false,'message'=>'CSRF']);
    $item_id = intval($_POST['item_id'] ?? 0);
    if ($item_id<=0) json_out(['success'=>false,'message'=>'بيانات غير صحيحة']);
    try {
        $conn->begin_transaction();
        $stg = $conn->prepare("SELECT purchase_invoice_id, product_id, quantity, batch_id FROM purchase_invoice_items WHERE id = ? LIMIT 1");
        $stg->bind_param("i", $item_id); $stg->execute(); $r = $stg->get_result()->fetch_assoc(); $stg->close();
        if (!$r) { $conn->rollback(); json_out(['success'=>false,'message'=>'البند غير موجود']); }
        $invoice_id = intval($r['purchase_invoice_id']); $product_id = intval($r['product_id']); $qty = floatval($r['quantity']); $batch_id = intval($r['batch_id'] ?? 0);

        $st_status = $conn->prepare("SELECT status FROM purchase_invoices WHERE id = ? LIMIT 1");
        $st_status->bind_param("i", $invoice_id); $st_status->execute(); $inv = $st_status->get_result()->fetch_assoc(); $st_status->close();
        $inv_status = $inv['status'] ?? 'pending';

        if ($inv_status === 'fully_received') {
            $upd_prod = $conn->prepare("UPDATE products SET current_stock = current_stock - ? WHERE id = ?");
            $upd_prod->bind_param("di", $qty, $product_id); $upd_prod->execute(); $upd_prod->close();

            if ($batch_id > 0) {
                $get_rem = $conn->prepare("SELECT remaining FROM batches WHERE id = ? LIMIT 1");
                $get_rem->bind_param("i", $batch_id); $get_rem->execute(); $rr = $get_rem->get_result()->fetch_assoc(); $get_rem->close();
                $rem = floatval($rr['remaining'] ?? 0);
                $new_rem = max(0, $rem - $qty);
                $upd_batch = $conn->prepare("UPDATE batches SET remaining = ?, adjusted_by = ?, adjusted_at = NOW() WHERE id = ?");
                $adj_by = $_SESSION['id'] ?? null;
                $upd_batch->bind_param("dii", $new_rem, $adj_by, $batch_id);
                $upd_batch->execute(); $upd_batch->close();
            }
        }

        $std = $conn->prepare("DELETE FROM purchase_invoice_items WHERE id = ?");
        $std->bind_param("i", $item_id);
        if (!$std->execute()) { $conn->rollback(); json_out(['success'=>false,'message'=>'فشل الحذف: '.$std->error]); }
        $std->close();

        $st_sum = $conn->prepare("SELECT IFNULL(SUM(total_cost),0) AS grand_total FROM purchase_invoice_items WHERE purchase_invoice_id = ?");
        $st_sum->bind_param("i", $invoice_id); $st_sum->execute(); $res = $st_sum->get_result()->fetch_assoc(); $st_sum->close();
        $grand_total = floatval($res['grand_total']);
        $st_up = $conn->prepare("UPDATE purchase_invoices SET total_amount = ? WHERE id = ?"); $st_up->bind_param("di", $grand_total, $invoice_id); $st_up->execute(); $st_up->close();

        $conn->commit();
        json_out(['success'=>true,'message'=>'تم الحذف','grand_total'=>$grand_total]);
    } catch (Exception $ex) { if ($conn->in_transaction) $conn->rollback(); json_out(['success'=>false,'message'=>'خطأ: '.$ex->getMessage()]); }
}

/* Change invoice status (AJAX) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_status_ajax') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) json_out(['success'=>false,'message'=>'CSRF']);
    $invoice_id = intval($_POST['invoice_id'] ?? 0);
    $new_status = $_POST['new_status'] ?? '';
    $allowed = ['pending','fully_received','cancelled'];
    if ($invoice_id<=0 || !in_array($new_status,$allowed)) json_out(['success'=>false,'message'=>'بيانات غير صحيحة']);
    try {
        $conn->begin_transaction();
        $stmt_prev = $conn->prepare("SELECT status, purchase_date FROM purchase_invoices WHERE id = ? LIMIT 1");
        $stmt_prev->bind_param("i", $invoice_id); $stmt_prev->execute(); $prev = $stmt_prev->get_result()->fetch_assoc(); $stmt_prev->close();
        $prev_status = $prev['status'] ?? null;
        $purchase_date = $prev['purchase_date'] ?? date('Y-m-d');

        $st = $conn->prepare("UPDATE purchase_invoices SET status = ?, updated_at = NOW() WHERE id = ?");
        $st->bind_param("si", $new_status, $invoice_id); $st->execute(); $st->close();

        if ($new_status === 'fully_received' && $prev_status !== 'fully_received') {
            $qitems = $conn->prepare("SELECT id, product_id, quantity, cost_price_per_unit, batch_id FROM purchase_invoice_items WHERE purchase_invoice_id = ?");
            $qitems->bind_param("i", $invoice_id); $qitems->execute(); $res = $qitems->get_result();
            while ($row = $res->fetch_assoc()) {
                $pid = intval($row['product_id']); $qty = floatval($row['quantity']); $cost = floatval($row['cost_price_per_unit']); $item_id = intval($row['id']); $batch_id = intval($row['batch_id'] ?? 0);
                if ($batch_id > 0) {
                    $upd = $conn->prepare("UPDATE products SET current_stock = current_stock + ?, cost_price = ? WHERE id = ?");
                    $upd->bind_param("ddi", $qty, $cost, $pid); $upd->execute(); $upd->close();
                } else {
                    $new_bid = create_batch_for_item($conn, $pid, $qty, $cost, $purchase_date, $invoice_id, $item_id, $_SESSION['id'] ?? null);
                    if ($new_bid) {
                        $ubl = $conn->prepare("UPDATE purchase_invoice_items SET batch_id = ? WHERE id = ?"); $ubl->bind_param("ii", $new_bid, $item_id); $ubl->execute(); $ubl->close();
                        $upd2 = $conn->prepare("UPDATE products SET current_stock = current_stock + ?, cost_price = ? WHERE id = ?");
                        $upd2->bind_param("ddi", $qty, $cost, $pid); $upd2->execute(); $upd2->close();
                    }
                }
            }
            $qitems->close();
        }

        $conn->commit();
        $labels = ['pending'=>'قيد الانتظار','fully_received'=>'تم الاستلام','cancelled'=>'ملغاة'];
        json_out(['success'=>true,'message'=>'تم تغيير الحالة','label'=>$labels[$new_status] ?? $new_status]);
    } catch (Exception $ex) { if ($conn->in_transaction) $conn->rollback(); json_out(['success'=>false,'message'=>'خطأ: '.$ex->getMessage()]); }
}

/* Finalize invoice (AJAX) - now supports: (a) create new invoice OR (b) add items to existing invoice when invoice_id provided */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'finalize_invoice_ajax') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) json_out(['success'=>false,'message'=>'CSRF']);
    $supplier_id = intval($_POST['supplier_id'] ?? 0);
    $purchase_date = trim($_POST['purchase_date'] ?? date('Y-m-d'));
    $status = $_POST['status'] ?? 'pending';
    $notes = trim($_POST['notes'] ?? '');
    $items_json = $_POST['items'] ?? '[]';
    $items = json_decode($items_json, true);
    $existing_invoice_id = intval($_POST['invoice_id'] ?? 0); // <-- NEW: if present, add items to this invoice
    if ($supplier_id <= 0) json_out(['success'=>false,'message'=>'اختر موردًا قبل الإتمام']);
    if (!is_array($items) || count($items) === 0) json_out(['success'=>false,'message'=>'الفاتورة لا يمكن أن تكون فارغة']);
    $valid_items = [];
    foreach ($items as $it) {
        $pid = intval($it['product_id'] ?? 0); $qty = floatval($it['qty'] ?? 0); $cost = floatval($it['cost_price'] ?? 0);
        if ($pid <= 0 || $qty <= 0) continue;
        $valid_items[] = ['product_id'=>$pid,'quantity'=>$qty,'cost_price'=>$cost,'total'=>$qty*$cost, 'selling_price'=> floatval($it['selling_price'] ?? -1)];
    }
    if (empty($valid_items)) json_out(['success'=>false,'message'=>'لا توجد بنود صالحة لإتمام الفاتورة']);
    try {
        $conn->begin_transaction();
        $created_by = $_SESSION['id'] ?? 0;

        if ($existing_invoice_id > 0) {
            // ADD ITEMS to existing invoice (don't create a new invoice)
            // validate invoice exists and belongs to this supplier (optional)
            $chk = $conn->prepare("SELECT id, supplier_id, status, purchase_date FROM purchase_invoices WHERE id = ? LIMIT 1");
            $chk->bind_param("i", $existing_invoice_id); $chk->execute(); $invrow = $chk->get_result()->fetch_assoc(); $chk->close();
            if (!$invrow) { $conn->rollback(); json_out(['success'=>false,'message'=>'الفاتورة غير موجودة']); }
            // optionally ensure supplier matches (skip strict check to allow reconciling)
            $inv_purchase_date = $invrow['purchase_date'] ?? $purchase_date;

            $ins = $conn->prepare("INSERT INTO purchase_invoice_items (purchase_invoice_id, product_id, quantity, cost_price_per_unit, total_cost, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
            if (!$ins) { $conn->rollback(); json_out(['success'=>false,'message'=>'فشل تحضير إدخال البنود']); }
            foreach ($valid_items as $it) {
                $pid = $it['product_id']; $qty = $it['quantity']; $cost = $it['cost_price']; $total = $it['total'];
                $ins->bind_param("iiddd", $existing_invoice_id, $pid, $qty, $cost, $total);
                if (!$ins->execute()) { $ins->close(); $conn->rollback(); json_out(['success'=>false,'message'=>'فشل إضافة بند: '.$ins->error]); }
                $new_item_id = $ins->insert_id;

                if (isset($it['selling_price']) && floatval($it['selling_price']) >= 0) {
                    $sp = floatval($it['selling_price']);
                    $stps = $conn->prepare("UPDATE products SET selling_price = ? WHERE id = ?"); $stps->bind_param("di", $sp, $pid); $stps->execute(); $stps->close();
                }

                if ($status === 'fully_received' || $invrow['status'] === 'fully_received') {
                    $batch_id = create_batch_for_item($conn, $pid, $qty, $cost, $inv_purchase_date, $existing_invoice_id, $new_item_id, $created_by);
                    if ($batch_id) {
                        $upd_link = $conn->prepare("UPDATE purchase_invoice_items SET batch_id = ? WHERE id = ?");
                        $upd_link->bind_param("ii", $batch_id, $new_item_id); $upd_link->execute(); $upd_link->close();
                    }
                    $upd = $conn->prepare("UPDATE products SET current_stock = current_stock + ?, cost_price = ? WHERE id = ?");
                    $upd->bind_param("ddi", $qty, $cost, $pid); $upd->execute(); $upd->close();
                }
            }
            $ins->close();

            $st_sum = $conn->prepare("SELECT IFNULL(SUM(total_cost),0) AS grand_total FROM purchase_invoice_items WHERE purchase_invoice_id = ?");
            $st_sum->bind_param("i", $existing_invoice_id); $st_sum->execute(); $g = $st_sum->get_result()->fetch_assoc(); $st_sum->close();
            $grand_total = floatval($g['grand_total'] ?? 0);
            $st_up = $conn->prepare("UPDATE purchase_invoices SET total_amount = ?, updated_at = NOW() WHERE id = ?"); $st_up->bind_param("di", $grand_total, $existing_invoice_id); $st_up->execute(); $st_up->close();

            $conn->commit();

            // set friendly session message for manage_suppliers.php
            if ($status === 'fully_received' || $invrow['status'] === 'fully_received') {
                $_SESSION['message'] = "<div class='alert alert-success'>تمت إضافة البنود إلى الفاتورة رقم #{$existing_invoice_id} وتم إنشاء دفعات في المخزن (المخزون محدث).</div>";
            } else {
                $_SESSION['message'] = "<div class='alert alert-info'>تمت إضافة البنود إلى الفاتورة رقم #{$existing_invoice_id} — الفاتورة لا تزال مؤجلة (لم يُنشأ دفعات بالمخزن).</div>";
            }

            json_out(['success'=>true,'message'=>'تمت إضافة البنود إلى الفاتورة الموجودة','invoice_id'=>$existing_invoice_id,'grand_total'=>$grand_total]);

        } else {
            // CREATE NEW invoice (original behavior)
            $st = $conn->prepare("INSERT INTO purchase_invoices (supplier_id,supplier_invoice_number,purchase_date,notes,total_amount,status,created_by,created_at) VALUES (?, ?, ?, ?, 0, ?, ?, NOW())");
            if (!$st) { $conn->rollback(); json_out(['success'=>false,'message'=>'تحضير إدخال الفاتورة فشل']); }
            $supplier_invoice_number = trim($_POST['supplier_invoice_number'] ?? '');
            $st->bind_param("issssi", $supplier_id, $supplier_invoice_number, $purchase_date, $notes, $status, $created_by);
            if (!$st->execute()) { $err = $st->error; $st->close(); $conn->rollback(); json_out(['success'=>false,'message'=>'فشل إدخال الفاتورة: '.$err]); }
            $new_invoice_id = $st->insert_id; $st->close();

            $ins = $conn->prepare("INSERT INTO purchase_invoice_items (purchase_invoice_id, product_id, quantity, cost_price_per_unit, total_cost, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
            if (!$ins) { $conn->rollback(); json_out(['success'=>false,'message'=>'فشل تحضير إدخال البنود']); }
            foreach ($valid_items as $it) {
                $pid = $it['product_id']; $qty = $it['quantity']; $cost = $it['cost_price']; $total = $it['total'];
                $ins->bind_param("iiddd", $new_invoice_id, $pid, $qty, $cost, $total);
                if (!$ins->execute()) { $ins->close(); $conn->rollback(); json_out(['success'=>false,'message'=>'فشل إضافة بند: '.$ins->error]); }
                $new_item_id = $ins->insert_id;

                if (isset($it['selling_price']) && floatval($it['selling_price']) >= 0) {
                    $sp = floatval($it['selling_price']);
                    $stps = $conn->prepare("UPDATE products SET selling_price = ? WHERE id = ?"); $stps->bind_param("di", $sp, $pid); $stps->execute(); $stps->close();
                }

                if ($status === 'fully_received') {
                    $batch_id = create_batch_for_item($conn, $pid, $qty, $cost, $purchase_date, $new_invoice_id, $new_item_id, $created_by);
                    if ($batch_id) {
                        $upd_link = $conn->prepare("UPDATE purchase_invoice_items SET batch_id = ? WHERE id = ?");
                        $upd_link->bind_param("ii", $batch_id, $new_item_id); $upd_link->execute(); $upd_link->close();
                    }
                    $upd = $conn->prepare("UPDATE products SET current_stock = current_stock + ?, cost_price = ? WHERE id = ?");
                    $upd->bind_param("ddi", $qty, $cost, $pid); $upd->execute(); $upd->close();
                }
            }
            $ins->close();

            $st_sum = $conn->prepare("SELECT IFNULL(SUM(total_cost),0) AS grand_total FROM purchase_invoice_items WHERE purchase_invoice_id = ?");
            $st_sum->bind_param("i", $new_invoice_id); $st_sum->execute(); $g = $st_sum->get_result()->fetch_assoc(); $st_sum->close();
            $grand_total = floatval($g['grand_total'] ?? 0);
            $st_up = $conn->prepare("UPDATE purchase_invoices SET total_amount = ? WHERE id = ?"); $st_up->bind_param("di", $grand_total, $new_invoice_id); $st_up->execute(); $st_up->close();

            $conn->commit();

            if ($status === 'fully_received') {
                $_SESSION['message'] = "<div class='alert alert-success'>تمت إضافة الفاتورة رقم #{$new_invoice_id} وتم إنشاء دفعات في المخزن.</div>";
            } else {
                $_SESSION['message'] = "<div class='alert alert-info'>تمت إضافة الفاتورة رقم #{$new_invoice_id} ولكنها مؤجلة (لم تُسجل دفعات في المخزن).</div>";
            }

            json_out(['success'=>true,'message'=>'تمت إضافة الفاتورة بنجاح','invoice_id'=>$new_invoice_id,'grand_total'=>$grand_total,'status'=>$status]);
        }
    } catch (Exception $ex) {
        if ($conn->in_transaction) $conn->rollback();
        json_out(['success'=>false,'message'=>'خطأ في الخادم: '.$ex->getMessage()]);
    }
}

/* ---------------- Page rendering: load products (initial) and invoice if id ---------------- */
$products_list = [];
$sqlP = "SELECT id, product_code, name, selling_price, cost_price, current_stock, unit_of_measure FROM products ORDER BY name LIMIT 2000";
if ($resP = $conn->query($sqlP)) { while ($r = $resP->fetch_assoc()) $products_list[] = $r; $resP->free(); }

$next_invoice_id = get_next_purchase_invoice_id($conn);

$invoice = null; $invoice_id = 0; $items = [];
if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $invoice_id = intval($_GET['id']);
    $st = $conn->prepare("SELECT pi.*, s.name as supplier_name, u.username as creator_name FROM purchase_invoices pi LEFT JOIN suppliers s ON pi.supplier_id = s.id LEFT JOIN users u ON pi.created_by = u.id WHERE pi.id = ? LIMIT 1");
    $st->bind_param("i", $invoice_id); $st->execute(); $invoice = $st->get_result()->fetch_assoc(); $st->close();
    if ($invoice) {
        $s2 = $conn->prepare("SELECT id, product_id, quantity, cost_price_per_unit, total_cost, batch_id FROM purchase_invoice_items WHERE purchase_invoice_id = ? ORDER BY id ASC");
        $s2->bind_param("i", $invoice_id); $s2->execute(); $res2 = $s2->get_result(); while ($r = $res2->fetch_assoc()) $items[] = $r; $s2->close();
    }
}

// If supplier_id is passed from external page (e.g. manage_suppliers -> فاتورة وارد)
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

// include header & sidebar
require_once BASE_DIR . 'partials/header.php';
require_once BASE_DIR . 'partials/sidebar.php';
?>

<!-- (rest of HTML + CSS + JS remains the same as previous version, but we must ensure JS sends invoice_id) -->

<style>
/* visual improvements + dark mode support */
:root{ --surface:#ffffff; --muted:#6b7280; --border:#e5e7eb; --primary:#0b84ff; --card-shadow: 0 10px 24px rgba(15,23,42,0.06);} 
@media (prefers-color-scheme: dark){
  :root{ --surface:#0b1220; --muted:#9ca3af; --border:#1f2937; --primary:#4aa3ff; --card-shadow: 0 8px 28px rgba(0,0,0,0.6);} 
  body{ background:#071019; color:#e6eef8; }
}
.soft{ background:var(--surface); border-radius:12px; padding:14px; box-shadow:var(--card-shadow); border:1px solid var(--border); }
.product-item{ display:flex; justify-content:space-between; gap:10px; padding:10px; border-radius:10px; margin-bottom:8px; cursor:pointer; border:1px solid transparent; transition:all .12s; }
.product-item:hover{ transform:translateY(-3px); border-color: rgba(11,132,255,0.06); }
.badge-out{ background:#c00; color:#fff; padding:3px 8px; border-radius:8px; font-size:12px; }
.items-wrapper{ max-height:460px; overflow:auto; border-radius:8px; border:1px solid var(--border); }
.items-wrapper table thead th{ position:sticky; top:0; background:var(--surface); z-index:5; }
.status-group{ display:flex; gap:8px; }
.status-btn{ padding:8px 12px; border-radius:10px; cursor:pointer; border:1px solid transparent; background:linear-gradient(180deg, rgba(255,255,255,0.02), transparent); color:inherit; }
.status-btn.active{ background:linear-gradient(135deg,var(--primary),#7c3aed); color:#fff; box-shadow:0 8px 20px rgba(11,132,255,0.08); }
.no-items{ color:var(--muted); padding:20px; text-align:center; }
.small-muted{ color:var(--muted); }
.modal-backdrop{ position:fixed; left:0; top:0; right:0; bottom:0; display:none; align-items:center; justify-content:center; background:rgba(2,6,23,0.4); z-index:9999; }
.toast{ position:fixed; right:20px; bottom:20px; background:#111827; color:#fff; padding:10px 14px; border-radius:8px; box-shadow:0 8px 30px rgba(2,6,23,0.6); z-index:10001; opacity:0; transform:translateY(8px); transition:all .28s; }
.toast.show{ opacity:1; transform:translateY(0); }
.toast.success{ background:linear-gradient(90deg,#10b981,#059669); }
.toast.error{ background:linear-gradient(90deg,#ef4444,#dc2626); }
.toast.info{ background:linear-gradient(90deg,#0ea5ff,#0284c7); }
/* Print settings: print invoice header & items only, hide no-print and invoice-notes */
@media print { 
  .no-print{ display:none !important; }
  .invoice-notes{ display:none !important; } 
  .modal-backdrop, .toast, .btn { display:none !important; }
}
</style>

<div class="container mt-4 mb-5">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <div>
      <h3 class="mb-0">تفاصيل فاتورة مشتريات <?php if ($invoice) echo '#'.intval($invoice['id']); ?></h3>
      <?php if ($invoice): ?>
        <div class="small-muted">رقم فاتورة المورد: <?php echo e($invoice['supplier_invoice_number'] ?: '-'); ?> — تاريخ الشراء: <?php echo e(date('Y-m-d', strtotime($invoice['purchase_date'] ?? date('Y-m-d')))); ?></div>
        <div class="small-muted">المورد: <?php echo e($invoice['supplier_name'] ?? '-'); ?> — أنشئت بواسطة: <?php echo e($invoice['creator_name'] ?? '-'); ?> — تاريخ الإنشاء: <?php echo e($invoice['created_at'] ?? '-'); ?></div>
        <div class="small-muted">الحالة: <strong id="currentStatusLabel"><?php echo e(($invoice['status'] ?? 'pending') === 'fully_received' ? 'تم الاستلام' : 'قيد الانتظار'); ?></strong></div>
      <?php else: ?>
        <div class="small-muted">
          إنشاء فاتورة جديدة — افتراضياً: قيد الانتظار
          <?php if ($external_supplier_id): ?>
            — المورد المحدد: <strong><?php echo e($external_supplier_name); ?></strong> (ممرّر من الصفحة السابقة)
          <?php else: ?>
            — <span class="text-danger">لم يتم تمرير مورد؛ مرّر supplier_id في رابط الصفحة أو افتح فاتورة مرتبطة بمورد.</span>
          <?php endif; ?>
        </div>
        <?php if ($next_invoice_id): ?>
          <div class="small-muted">الرقم المرجعي القادم (عرض فقط): <strong>#<?php echo intval($next_invoice_id); ?></strong></div>
        <?php endif; ?>
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
          <?php foreach($products_list as $p): $o = floatval($p['current_stock']) <= 0 ? ' out-of-stock' : ''; ?>
            <div class="product-item<?php echo $o; ?>" data-id="<?php echo (int)$p['id']; ?>" data-name="<?php echo e($p['name']); ?>" data-code="<?php echo e($p['product_code']); ?>" data-cost="<?php echo floatval($p['cost_price']); ?>" data-selling="<?php echo floatval($p['selling_price']); ?>" data-stock="<?php echo floatval($p['current_stock']); ?>">
              <div>
                <div style="font-weight:700"><?php echo e($p['name']); ?></div>
                <div class="small-muted">كود: <?php echo e($p['product_code']); ?> — رصيد: <span class="stock-number"><?php echo e($p['current_stock']); ?></span></div>
              </div>
              <div style="text-align:left">
                <div style="font-weight:700"><?php echo number_format($p['cost_price'],2); ?> ج.م</div>
                <?php if(floatval($p['current_stock']) <= 0): ?><div class="badge-out">نفذ</div><?php endif; ?>
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
                $statuses = ['pending'=> 'قيد الانتظار', 'fully_received'=>'تم الاستلام'];
                $cur = $invoice['status'] ?? 'pending';
                foreach ($statuses as $k=>$label) {
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
          <div class="small-muted">سعر الشراء يُستخدم لحساب إجمالي الفاتورة (افتراضي: آخر سعر تكلفة / أو ما أدخلته)</div>
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
              <?php if (!empty($items)): $i=1; foreach($items as $it): ?>
                <?php $pname = '#'.$it['product_id']; foreach ($products_list as $pp) if ($pp['id']==$it['product_id']) { $pname = $pp['name']; $last_sell = $pp['selling_price']; break; } ?>
                <tr data-item-id="<?php echo $it['id']; ?>" data-product-id="<?php echo $it['product_id']; ?>">
                  <td><?php echo $i++; ?></td>
                  <td><?php echo e($pname); ?></td>
                  <td class="text-center"><input class="form-control form-control-sm item-qty text-center" value="<?php echo number_format($it['quantity'],2); ?>" step="0.01" min="0" style="width:100px; margin:auto"></td>
                  <td class="text-end"><input class="form-control form-control-sm item-cost text-end" value="<?php echo number_format($it['cost_price_per_unit'],2); ?>" step="0.01" min="0" style="width:110px; display:inline-block"> ج.م</td>
                  <td class="text-end"><input class="form-control form-control-sm item-selling text-end" value="<?php echo number_format($last_sell ?? 0,2); ?>" step="0.01" min="0" style="width:110px; display:inline-block"> ج.م</td>
                  <td class="text-end fw-bold item-total"><?php echo number_format($it['total_cost'],2); ?> ج.م</td>
                  <td class="text-center no-print"><button class="btn btn-sm btn-danger btn-delete-item" data-item-id="<?php echo $it['id']; ?>">حذف</button></td>
                </tr>
              <?php endforeach; else: ?>
                <tr id="no-items-row"><td colspan="7" class="no-items">لا توجد بنود بعد — اختر منتجاً لإضافته.</td></tr>
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
          <div class="small-muted">ملاحظة: الفاتورة لا تُؤثر على المخزون إلا عند تغيير الحالة إلى "تم الاستلام" أو إذا اخترت الحالة "تم" عند الإتمام.</div>
        </div>

      </div>

      <div class="soft">
        <div><strong>ملاحظة الفاتورة (عرض فقط للطباعة مخفي):</strong></div>
        <div class="small-muted invoice-notes"><?php echo nl2br(e($invoice['notes'] ?? '-')); ?></div>
      </div>

    </div>

  </div>
</div>

<!-- Confirm Modal (before finalize) -->
<div id="modalConfirm" class="modal-backdrop">
  <div class="mymodal soft" style="max-width:720px;">
    <h4>تأكيد إتمام الفاتورة</h4>
    <div id="confirmPreview" style="margin-top:10px; max-height:300px; overflow:auto;"></div>
    <div class="d-flex justify-content-between align-items-center mt-3">
      <div>
        <button id="confirmCancel" class="btn btn-outline-secondary">إلغاء</button>
        <button id="confirmSend" class="btn btn-success">تأكيد وإرسال</button>
      </div>
      <div><strong>الإجمالي:</strong> <span id="confirm_total">0.00</span> ج.م</div>
    </div>
  </div>
</div>

<!-- Result Modal (after finalize) -->
<div id="modalResult" class="modal-backdrop">
  <div class="mymodal soft" style="max-width:640px;">
    <h4 id="resultTitle">الحالة</h4>
    <div id="resultBody" style="margin-top:10px;"></div>
    <div class="d-flex justify-content-end mt-3">
      <button id="resultOk" class="btn btn-primary">الانتقال إلى صفحة الموردين</button>
    </div>
  </div>
</div>

<div id="toast_holder"></div>

<script>
document.addEventListener('DOMContentLoaded', function(){
  function q(sel, ctx=document){ return ctx.querySelector(sel); }
  function qa(sel, ctx=document){ return Array.from(ctx.querySelectorAll(sel)); }
  function escapeHtml(s){ return String(s||'').replace(/[&<>'"]/g,function(m){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]; }); }

  const BASE_URL = <?php echo json_encode(BASE_URL); ?>;
  const csrf = <?php echo json_encode($csrf_token); ?>;
  let invoiceId = <?php echo intval($invoice_id ?: 0); ?>;
  const externalSupplierId = <?php echo json_encode($external_supplier_id); ?>;
  const externalSupplierName = <?php echo json_encode($external_supplier_name); ?>;
  const invoiceSupplierId = <?php echo json_encode(intval($invoice['supplier_id'] ?? 0)); ?>;

  const productsLocal = Array.from(qa('.product-item')).map(el=>({
    id: parseInt(el.dataset.id||0,10), name: el.dataset.name, code: el.dataset.code,
    cost: parseFloat(el.dataset.cost||0), selling: parseFloat(el.dataset.selling||0), stock: parseFloat(el.dataset.stock||0), el: el
  }));

  q('#product_search').addEventListener('input', function(e){
    const qv = (e.target.value||'').trim().toLowerCase();
    productsLocal.forEach(p=>{
      const show = !qv || (p.name && p.name.toLowerCase().includes(qv)) || (p.code && p.code.toLowerCase().includes(qv));
      p.el.style.display = show ? '' : 'none';
    });
  });

  function showToast(msg, type='info'){
    const id = 't'+Date.now();
    const div = document.createElement('div'); div.className = 'toast '+(type==='success'? 'success': type==='error'?'error': type==='info'?'info':''); div.id = id; div.innerText = msg;
    q('#toast_holder').appendChild(div);
    setTimeout(()=> div.classList.add('show'), 10);
    setTimeout(()=>{ div.classList.remove('show'); setTimeout(()=> div.remove(), 300); }, 3500);
  }

  function renderGrand(){
    let g = 0;
    qa('#items_tbody tr').forEach(tr=>{
      if (tr.id === 'no-items-row') return;
      const text = tr.querySelector('.item-total')?.innerText || '0';
      const num = parseFloat(String(text).replace(/[^\d.-]/g,'')) || 0;
      g += num;
    });
    q('#grand_total').innerText = g.toFixed(2) + ' ج.م';
    return g;
  }

  q('#product_list').addEventListener('click', function(e){
    const it = e.target.closest('.product-item'); if (!it) return; const pid = parseInt(it.dataset.id,10);
    const p = productsLocal.find(x=> x.id === pid); if (!p) return;
    const qty = parseFloat(q('#default_qty').value) || 1;
    if (invoiceId) {
      const form = new URLSearchParams(); form.append('action','add_item_ajax'); form.append('csrf_token', csrf); form.append('invoice_id', invoiceId); form.append('product_id', pid); form.append('quantity', qty); form.append('cost_price', p.cost); form.append('selling_price', p.selling);
      fetch(location.pathname, { method:'POST', body: form }).then(r=>r.json()).then(d=>{
        if (!d.success) { showToast(d.message || 'فشل', 'error'); return; }
        const itRow = d.item;
        const existing = qa('#items_tbody tr').find(tr=> parseInt(tr.dataset.productId||0,10) === parseInt(itRow.product_id,10));
        if (existing) {
          existing.dataset.itemId = itRow.item_id_pk;
          existing.dataset.productId = itRow.product_id;
          existing.children[2].querySelector('.item-qty').value = parseFloat(itRow.quantity).toFixed(2);
          existing.children[3].querySelector('.item-cost').value = parseFloat(itRow.cost_price_per_unit).toFixed(2);
          existing.children[4].querySelector('.item-selling').value = parseFloat(p.selling).toFixed(2);
          existing.querySelector('.item-total').innerText = parseFloat(itRow.total_cost).toFixed(2) + ' ج.م';
          attachRowHandlers(existing);
        } else {
          const tr = document.createElement('tr'); tr.dataset.itemId = itRow.item_id_pk; tr.dataset.productId = itRow.product_id;
          const idx = qa('#items_tbody tr').length;
          tr.innerHTML = `<td>${idx}</td><td>${escapeHtml(itRow.product_name)}</td><td class="text-center"><input class="form-control form-control-sm item-qty text-center" value="${parseFloat(itRow.quantity).toFixed(2)}" step="0.01" min="0" style="width:100px;margin:auto"></td><td class="text-end"><input class="form-control form-control-sm item-cost text-end" value="${parseFloat(itRow.cost_price_per_unit).toFixed(2)}" step="0.01" min="0" style="width:110px;display:inline-block"> ج.م</td><td class="text-end"><input class="form-control form-control-sm item-selling text-end" value="${parseFloat(p.selling).toFixed(2)}" step="0.01" min="0" style="width:110px;display:inline-block"> ج.م</td><td class="text-end fw-bold item-total">${parseFloat(itRow.total_cost).toFixed(2)} ج.م</td><td class="text-center no-print"><button class="btn btn-sm btn-danger btn-delete-item" data-item-id="${itRow.item_id_pk}">حذف</button></td>`;
          const noRow = q('#no-items-row'); if (noRow) noRow.remove(); q('#items_tbody').appendChild(tr); attachRowHandlers(tr); renderGrand();
        }
        showToast(d.message || 'تمت الإضافة', 'success'); renderGrand();
      }).catch(err=>{ console.error(err); showToast('خطأ في الاتصال', 'error'); });
    } else {
      const existing = qa('#items_tbody tr').find(tr=> parseInt(tr.dataset.productId||0,10) === pid && !tr.dataset.itemId);
      if (existing) {
        const qel = existing.querySelector('.item-qty'); qel.value = (parseFloat(qel.value)||0) + qty; existing.querySelector('.item-qty').dispatchEvent(new Event('input')); return;
      }
      const noRow = q('#no-items-row'); if (noRow) noRow.remove();
      const idx = qa('#items_tbody tr').length;
      const tr = document.createElement('tr'); tr.dataset.productId = pid;
      tr.innerHTML = `<td>${idx}</td><td>${escapeHtml(p.name)}</td><td class="text-center"><input class="form-control form-control-sm item-qty text-center" value="${qty.toFixed(2)}" step="0.01" min="0" style="width:100px;margin:auto"></td><td class="text-end"><input class="form-control form-control-sm item-cost text-end" value="${parseFloat(p.cost).toFixed(2)}" step="0.01" min="0" style="width:110px;display:inline-block"> ج.م</td><td class="text-end"><input class="form-control form-control-sm item-selling text-end" value="${parseFloat(p.selling).toFixed(2)}" step="0.01" min="0" style="width:110px;display:inline-block"> ج.م</td><td class="text-end fw-bold item-total">${(qty * p.cost).toFixed(2)} ج.م</td><td class="text-center no-print"><button class="btn btn-sm btn-danger remove-row">حذف</button></td>`;
      q('#items_tbody').appendChild(tr);
      attachLocalRowHandlers(tr); renderGrand();
    }
  });

  function attachRowHandlers(tr){
    const qty = tr.querySelector('.item-qty'); const cost = tr.querySelector('.item-cost'); const selling = tr.querySelector('.item-selling'); const del = tr.querySelector('.btn-delete-item');
    const itemId = tr.dataset.itemId;
    if (qty) qty.addEventListener('change', function(){ sendUpdate(itemId, tr); });
    if (cost) cost.addEventListener('change', function(){ sendUpdate(itemId, tr); });
    if (selling) selling.addEventListener('change', function(){ sendUpdate(itemId, tr); });
    if (del) del.addEventListener('click', function(){ if(!confirm('هل تريد حذف هذا البند؟')) return; const form = new URLSearchParams(); form.append('action','delete_item_ajax'); form.append('csrf_token', csrf); form.append('item_id', itemId); fetch(location.pathname, { method:'POST', body: form }).then(r=>r.json()).then(d=>{ if(!d.success) { showToast(d.message||'خطأ','error'); return; } tr.remove(); renumberRows(); q('#grand_total').innerText = parseFloat(d.grand_total).toFixed(2) + ' ج.م'; showToast(d.message || 'تم الحذف', 'success'); }).catch(e=>{console.error(e); showToast('خطأ في الاتصال','error');}); });
  }
  function sendUpdate(itemId, tr){
    const qv = parseFloat(tr.querySelector('.item-qty').value) || 0; const cv = parseFloat(tr.querySelector('.item-cost').value) || 0; const sv = parseFloat(tr.querySelector('.item-selling').value) || 0;
    const form = new URLSearchParams(); form.append('action','update_item_ajax'); form.append('csrf_token', csrf); form.append('item_id', itemId); form.append('quantity', qv); form.append('cost_price', cv); form.append('selling_price', sv);
    fetch(location.pathname, { method:'POST', body: form }).then(r=>r.json()).then(d=>{ if(!d.success) { showToast(d.message || 'خطأ', 'error'); return; } tr.querySelector('.item-total').innerText = parseFloat(d.total_cost).toFixed(2) + ' ج.م'; document.getElementById('grand_total').innerText = parseFloat(d.grand_total).toFixed(2) + ' ج.م'; showToast(d.message || 'تم التحديث', 'success'); }).catch(e=>{ console.error(e); showToast('خطأ في الاتصال','error'); });
  }

  function attachLocalRowHandlers(tr){
    const qty = tr.querySelector('.item-qty'); const cost = tr.querySelector('.item-cost'); const selling = tr.querySelector('.item-selling'); const rem = tr.querySelector('.remove-row');
    const updateLocal = ()=>{ const qv = parseFloat(qty.value)||0; const cv = parseFloat(cost.value)||0; tr.querySelector('.item-total').innerText = (qv*cv).toFixed(2) + ' ج.م'; renderGrand(); };
    if (qty) qty.addEventListener('input', updateLocal); if (cost) cost.addEventListener('input', updateLocal); if (selling) selling.addEventListener('input', updateLocal);
    if (rem) rem.addEventListener('click', function(){ if(!confirm('حذف البنود المؤقتة؟')) return; tr.remove(); if (qa('#items_tbody tr').length === 0){ const r = document.createElement('tr'); r.id='no-items-row'; r.innerHTML = '<td colspan=\"7\" class=\"no-items\">لا توجد بنود بعد — اختر منتجاً لإضافته.</td>'; q('#items_tbody').appendChild(r);} renumberRows(); renderGrand(); showToast('تم الحذف', 'success'); });
  }

  function renumberRows(){ qa('#items_tbody tr').forEach((r,i)=> r.children[0].innerText = i+1); }
  qa('#items_tbody tr').forEach(tr=>{ if (tr.id !== 'no-items-row') attachRowHandlers(tr); });
  renderGrand();

  // status buttons
  qa('.status-btn').forEach(btn=> btn.addEventListener('click', function(){
    qa('.status-btn').forEach(x=>x.classList.remove('active')); this.classList.add('active');
    const newStatus = this.dataset.status; q('#currentStatusLabel').innerText = this.innerText;
    if (invoiceId) {
      const form = new URLSearchParams(); form.append('action','change_status_ajax'); form.append('csrf_token', csrf); form.append('invoice_id', invoiceId); form.append('new_status', newStatus);
      fetch(location.pathname,{ method:'POST', body: form }).then(r=>r.json()).then(d=>{ if (!d.success) { showToast(d.message||'فشل تغيير الحالة','error'); return; } q('#currentStatusLabel').innerText = d.label || newStatus; showToast(d.message || 'تم تغيير الحالة', 'success'); }).catch(err=>{ console.error(err); showToast('خطأ في الاتصال','error'); });
    }
  }));

  // finalize (for create flow) - gather rows without data-item-id
  q('#finalizeBtn').addEventListener('click', function(){
    const rows = qa('#items_tbody tr').filter(tr=> tr.id!=='no-items-row');
    if (rows.length === 0) return showToast('لا توجد بنود لإتمام الفاتورة','error');
    let supplier_id = 0;
    if (invoiceId && invoiceSupplierId) supplier_id = invoiceSupplierId;
    else if (externalSupplierId) supplier_id = externalSupplierId;
    if (!supplier_id) { showToast('يجب أن تحدد موردًا قبل الإتمام. مرّر supplier_id في رابط الصفحة أو افتح فاتورة مرتبطة بمورد.','error'); return; }

    const preview = q('#confirmPreview'); preview.innerHTML = '';
    const itemsPayload = [];
    let total = 0;
    rows.forEach(tr=>{
      const pid = parseInt(tr.dataset.productId||0,10);
      const qv = parseFloat(tr.querySelector('.item-qty').value) || 0;
      const cv = parseFloat(tr.querySelector('.item-cost').value) || 0;
      const sv = parseFloat(tr.querySelector('.item-selling').value) || 0;
      const line = qv*cv; total += line;
      const div = document.createElement('div'); div.style.display='flex'; div.style.justifyContent='space-between'; div.style.marginBottom='6px';
      div.innerHTML = `<div><strong>${escapeHtml(tr.children[1].innerText)}</strong><div class="small-muted">كمية: ${qv} — سعر شراء: ${cv.toFixed(2)} — سعر بيع: ${sv.toFixed(2)}</div></div><div>${line.toFixed(2)}</div>`;
      preview.appendChild(div);
      itemsPayload.push({ product_id: pid, qty: qv, cost_price: cv, selling_price: sv });
    });
    q('#confirm_total').innerText = total.toFixed(2);
    q('#modalConfirm').style.display = 'flex';

    q('#confirmSend').onclick = async function(){
      const activeStatusBtn = qa('.status-btn').find(x => x.classList.contains('active'));
      const chosenStatus = activeStatusBtn ? activeStatusBtn.dataset.status : 'pending';
      const fd = new FormData(); fd.append('action','finalize_invoice_ajax'); fd.append('csrf_token', csrf); fd.append('supplier_id', supplier_id); fd.append('purchase_date', (new Date()).toISOString().slice(0,10)); fd.append('status', chosenStatus); fd.append('notes', ''); fd.append('items', JSON.stringify(itemsPayload)); fd.append('supplier_invoice_number', '');
      if (invoiceId) fd.append('invoice_id', invoiceId); // <-- IMPORTANT: send existing invoice id so server updates instead of creating duplicate

      try {
        const res = await fetch(location.pathname, { method:'POST', body: fd });
        const text = await res.text();
        try {
          const data = JSON.parse(text);
          if (!data.success) { showToast(data.message || 'فشل', 'error'); return; }
          const st = data.status || chosenStatus;
          const invoiceIdNew = data.invoice_id || invoiceId || null;
          let title = ''; let body = '';
          if (st === 'fully_received') {
            title = 'تمت العملية — الفاتورة مستلمة';
            body = `<p>تم إنشاء/تحديث الفاتورة بنجاح (رقم: <strong>#${invoiceIdNew}</strong>).</p><p>تمت إنشاء دفعات في المخزن لكل بنود الفاتورة وتم تحديث أرصدة المنتجات.</p>`;
          } else {
            title = 'تمت إضافة فاتورة وارد مؤجلة';
            body = `<p>تم إنشاء/تحديث الفاتورة بنجاح (رقم: <strong>#${invoiceIdNew}</strong>).</p><p>حالة الفاتورة: مؤجلة — لم يتم إنشاء دفعات في المخزن.</p>`;
          }
          q('#modalConfirm').style.display = 'none';
          q('#resultTitle').innerText = title;
          q('#resultBody').innerHTML = body;
          q('#modalResult').style.display = 'flex';

          q('#resultOk').onclick = function(){
            window.location.href = BASE_URL + 'admin/manage_suppliers.php';
          };
        } catch(parseErr){ console.error('Non-JSON response:', text); showToast('استجابة غير متوقعة من الخادم — افتح الكونسول لمزيد من التفاصيل','error'); }
      } catch (err) { console.error(err); showToast('خطأ في الاتصال','error'); }
    };
  });

  q('#confirmCancel').addEventListener('click', function(){ q('#modalConfirm').style.display = 'none'; });

});
</script>

<?php
require_once BASE_DIR . 'partials/footer.php';
ob_end_flush();
?>
