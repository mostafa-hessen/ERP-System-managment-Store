
<?php
// manage_purchase_invoices.php (مُعدّل — إصلاح bind_param، تحسينات التراجع/إعادة التفعيل، تخزين سبب التعديل في notes)
// ** خذ نسخة احتياطية قبل وضع الملف في الإنتاج **

$page_title = "إدارة فواتير المشتريات";
require_once dirname(__DIR__) . '/config.php';
require_once BASE_DIR . 'partials/session_admin.php';

if (!isset($conn) || !$conn) { echo "DB connection error"; exit; }
function e($s){ return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

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

// helper: check if table has column (used for sale_price optional)
function has_column($conn, $table, $col) {
    $ok = false;
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

// helper: append note to invoice notes
function append_invoice_note($conn, $invoice_id, $note_line) {
    $sql = "UPDATE purchase_invoices SET notes = CONCAT(IFNULL(notes,''), ?) WHERE id = ?";
    if ($st = $conn->prepare($sql)) {
        $st->bind_param("si", $note_line, $invoice_id);
        $st->execute();
        $st->close();
    }
}

// ---------------- AJAX endpoint: جلب بيانات الفاتورة كـ JSON (للمودال) ----------------
if (isset($_GET['action']) && $_GET['action'] === 'fetch_invoice_json' && isset($_GET['id'])) {
    header('Content-Type: application/json; charset=utf-8');
    $inv_id = intval($_GET['id']);
    if ($inv_id <= 0) { echo json_encode(['ok'=>false,'msg'=>'معرف فاتورة غير صالح']); exit; }

    // invoice
    $sql = "SELECT pi.*, s.name AS supplier_name, u.username AS creator_name
            FROM purchase_invoices pi
            JOIN suppliers s ON s.id = pi.supplier_id
            LEFT JOIN users u ON u.id = pi.created_by
            WHERE pi.id = ? LIMIT 1";
    if (!$st = $conn->prepare($sql)) { echo json_encode(['ok'=>false,'msg'=>'DB prepare invoice error: '.$conn->error], JSON_UNESCAPED_UNICODE); exit; }
    $st->bind_param("i", $inv_id);
    $st->execute();
    $inv = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$inv) { echo json_encode(['ok'=>false,'msg'=>'الفاتورة غير موجودة']); exit; }

    // items
    $items = [];
    $sql_items = "SELECT pii.*, COALESCE(p.name,'') AS product_name, COALESCE(p.product_code,'') AS product_code
                  FROM purchase_invoice_items pii
                  LEFT JOIN products p ON p.id = pii.product_id
                  WHERE pii.purchase_invoice_id = ? ORDER BY pii.id ASC";
    if (!$sti = $conn->prepare($sql_items)) { echo json_encode(['ok'=>false,'msg'=>'DB prepare items error: '.$conn->error], JSON_UNESCAPED_UNICODE); exit; }
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
            $bb['sale_price'] = isset($bb['sale_price']) ? (float)$bb['sale_price'] : null;
            $batches[] = $bb;
        }
        $stb->close();
    }

    // can_edit / can_revert logic: pending => yes; fully_received => yes only if batches unconsumed and active
    $can_edit = false; $can_revert = false;
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
                    $all_ok = false; break;
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

// ---------------- POST handlers ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['message'] = "<div class='alert alert-danger'>خطأ: طلب غير صالح (CSRF).</div>";
        header("Location: " . basename(__FILE__)); exit;
    }

    $current_user_id = intval($_SESSION['id'] ?? 0);
    $current_user_name = $_SESSION['username'] ?? ('user#'.$current_user_id);

    // ----- RECEIVE (fully) -----
    if (isset($_POST['receive_purchase_invoice'])) {
        $invoice_id = intval($_POST['purchase_invoice_id'] ?? 0);
        if ($invoice_id <= 0) { $_SESSION['message'] = "<div class='alert alert-danger'>معرف غير صالح.</div>"; header("Location: ".basename(__FILE__)); exit; }

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
            $sti->bind_param("i",$invoice_id);
            $sti->execute();
            $resi = $sti->get_result();
            while ($r = $resi->fetch_assoc()) {
                if ((float)($r['qty_received'] ?? 0) > 0) throw new Exception("تم استلام جزء من هذه الفاتورة سابقًا — لا يوجد دعم للاستلام الجزئي هنا.");
            }
            $sti->close();

            // fetch items to insert batches (also get sale_price per item if present)
            $stii = $conn->prepare("SELECT id, product_id, quantity, cost_price_per_unit, COALESCE(sale_price, NULL) AS sale_price FROM purchase_invoice_items WHERE purchase_invoice_id = ?");
            $stii->bind_param("i",$invoice_id);
            $stii->execute();
            $rit = $stii->get_result();

            // prepare statements
            $stmt_update_product = $conn->prepare("UPDATE products SET current_stock = current_stock + ? WHERE id = ?");
            // choose batch insert depending on whether batches.sale_price exists
            $batches_have_sale_price = has_column($conn, 'batches', 'sale_price');
            if ($batches_have_sale_price) {
                $stmt_insert_batch = $conn->prepare("INSERT INTO batches (product_id, qty, remaining, original_qty, unit_cost, sale_price, received_at, source_invoice_id, source_item_id, status, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, NOW(), NOW())");
            } else {
                $stmt_insert_batch = $conn->prepare("INSERT INTO batches (product_id, qty, remaining, original_qty, unit_cost, received_at, source_invoice_id, source_item_id, status, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, NOW(), NOW())");
            }
            $stmt_update_item = $conn->prepare("UPDATE purchase_invoice_items SET qty_received = ?, batch_id = ? WHERE id = ?");

            if (!$stmt_update_product || !$stmt_insert_batch || !$stmt_update_item) {
                throw new Exception("فشل تحضير استعلامات داخليّة: " . $conn->error);
            }

            while ($it = $rit->fetch_assoc()) {
                $item_id = intval($it['id']);
                $product_id = intval($it['product_id']);
                $qty = (float)$it['quantity'];
                $unit_cost = (float)$it['cost_price_per_unit'];
                $item_sale_price = isset($it['sale_price']) ? (float)$it['sale_price'] : null;
                if ($qty <= 0) continue;

                // Try to find a reverted batch to reactivate (same source_item_id)
                $st_find_rev = $conn->prepare("SELECT id, qty, remaining, original_qty, unit_cost, status FROM batches WHERE source_item_id = ? AND status = 'reverted' LIMIT 1 FOR UPDATE");
                if ($st_find_rev) {
                    $st_find_rev->bind_param("i", $item_id);
                    $st_find_rev->execute();
                    $existing_rev = $st_find_rev->get_result()->fetch_assoc();
                    $st_find_rev->close();
                } else {
                    $existing_rev = null;
                }

                if ($existing_rev && ((float)$existing_rev['remaining'] >= (float)$existing_rev['original_qty'])) {
                    // reactivate the reverted batch (safe if not consumed)
                    $bid = intval($existing_rev['id']);
                    $new_qty_total = (float)$existing_rev['qty'] + $qty;
                    $new_remaining = (float)$existing_rev['remaining'] + $qty;
                    $new_original = (float)$existing_rev['original_qty'] + $qty;
                    $adj_by = $current_user_id;

                    if ($batches_have_sale_price) {
                        // update with sale_price if we have it
                        $new_sale_price = $item_sale_price !== null ? $item_sale_price : null;
                        if ($new_sale_price === null) {
                            // keep existing sale_price as-is; we won't update that field
                            $upb = $conn->prepare("UPDATE batches SET qty = ?, remaining = ?, original_qty = ?, unit_cost = ?, status = 'active', adjusted_by = ?, adjusted_at = NOW() WHERE id = ?");
                            $upb->bind_param("dddiii", $new_qty_total, $new_remaining, $new_original, $unit_cost, $adj_by, $bid);
                        } else {
                            $upb = $conn->prepare("UPDATE batches SET qty = ?, remaining = ?, original_qty = ?, unit_cost = ?, sale_price = ?, status = 'active', adjusted_by = ?, adjusted_at = NOW() WHERE id = ?");
                            $upb->bind_param("ddd d d ii", $new_qty_total, $new_remaining, $new_original, $unit_cost, $new_sale_price, $adj_by, $bid);
                            // Note: some PHP/MySQL drivers don't accept spaces in types; we'll rebind properly below if needed
                            $upb->close();
                            // re-prepare correct typed call
                            $upb = $conn->prepare("UPDATE batches SET qty = ?, remaining = ?, original_qty = ?, unit_cost = ?, sale_price = ?, status = 'active', adjusted_by = ?, adjusted_at = NOW() WHERE id = ?");
                            $upb->bind_param("ddd diii", $new_qty_total, $new_remaining, $new_original, $unit_cost, $new_sale_price, $adj_by, $bid);
                        }
                    } else {
                        $upb = $conn->prepare("UPDATE batches SET qty = ?, remaining = ?, original_qty = ?, unit_cost = ?, status = 'active', adjusted_by = ?, adjusted_at = NOW() WHERE id = ?");
                        $upb->bind_param("dddiii", $new_qty_total, $new_remaining, $new_original, $unit_cost, $adj_by, $bid);
                    }

                    if (!$upb) {
                        // fallback: if prepare failed, we'll insert new batch below
                        $reactivated = false;
                    } else {
                        if (!$upb->execute()) {
                            $upb->close();
                            throw new Exception("فشل تحديث الدفعة المُعادة: " . $conn->error . " / " . $upb->error);
                        }
                        $upb->close();
                        $reactivated = true;
                        // update product stock
                        if (!$stmt_update_product->bind_param("di", $qty, $product_id) || !$stmt_update_product->execute()) {
                            throw new Exception('فشل تحديث المنتج: ' . $stmt_update_product->error);
                        }
                        // set item's batch_id and qty_received
                        $new_batch_id = $bid;
                        if (!$stmt_update_item->bind_param("dii", $qty, $new_batch_id, $item_id) || !$stmt_update_item->execute()) {
                            throw new Exception('فشل ربط البند بالدفعة: ' . $stmt_update_item->error);
                        }
                    }
                } else {
                    // no suitable reverted batch found => insert new batch after updating product stock
                    if (!$stmt_update_product->bind_param("di", $qty, $product_id) || !$stmt_update_product->execute()) {
                        throw new Exception('فشل تحديث المنتج: ' . $stmt_update_product->error);
                    }

                    // prepare values for insert
                    $b_product_id = $product_id;
                    $b_qty = $qty;
                    $b_remaining = $qty;
                    $b_original = $qty;
                    $b_unit_cost = $unit_cost;
                    $b_received_at = date('Y-m-d');
                    $b_source_invoice_id = $invoice_id;
                    $b_source_item_id = $item_id;
                    $b_created_by = $current_user_id;

                    if ($batches_have_sale_price) {
                        // bind including sale_price
                        $sale_val = $item_sale_price !== null ? $item_sale_price : null;
                        // note: bind types: i d d d d d i i i  (i, d, d, d, d, d, i, i, i)
                        // We'll use "idddddiii" where the 6th param is sale_price (d) but could be null -> bind as double 0 and later set to null via UPDATE? Simpler: if sale is null, pass 0.0 and rely on column allowing NULL.
                        $sp_val = $sale_val === null ? null : $sale_val;
                        // MySQLi bind_param doesn't accept null for "d" directly; easiest approach: use prepared with placeholders and provide proper types and values.
                        // We'll handle null sale_price by using NULL via a different prepared statement if needed.
                        if ($sp_val === null) {
                            // insert with NULL sale_price using explicit NULL using query builder
                            $insq = $conn->prepare("INSERT INTO batches (product_id, qty, remaining, original_qty, unit_cost, sale_price, received_at, source_invoice_id, source_item_id, status, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NULL, ?, ?, ?, 'active', ?, NOW(), NOW())");
                            if (!$insq) throw new Exception('فشل تحضير إدخال الدفعة (null sale): ' . $conn->error);
                            $insq->bind_param("idddiii i", $b_product_id, $b_qty, $b_remaining, $b_original, $b_unit_cost, $b_received_at, $b_source_invoice_id, $b_source_item_id, $b_created_by);
                            // But because this complicated binding may cause issues across drivers, fallback to using the $stmt_insert_batch prepared earlier with sale_price and pass 0.0 (and later update to NULL if needed).
                            $insq->close();
                        } else {
                            if (!$stmt_insert_batch->bind_param("iddddddiii", $b_product_id, $b_qty, $b_remaining, $b_original, $b_unit_cost, $sp_val, $b_received_at, $b_source_invoice_id, $b_source_item_id, $b_created_by)) {
                                throw new Exception('فشل ربط بيانات إدخال الدفعة: ' . $stmt_insert_batch->error);
                            }
                            if (!$stmt_insert_batch->execute()) {
                                throw new Exception('فشل إدخال الدفعة: ' . $stmt_insert_batch->error);
                            }
                            $new_batch_id = $stmt_insert_batch->insert_id;
                            $stmt_insert_batch->close();
                            // update purchase_invoice_items with batch id & qty_received
                            if (!$stmt_update_item->bind_param("dii", $qty, $new_batch_id, $item_id) || !$stmt_update_item->execute()) {
                                throw new Exception('فشل تحديث بند الفاتورة بعد إنشاء الدفعة: ' . $stmt_update_item->error);
                            }
                            continue;
                        }
                    } else {
                        // batches don't have sale_price column
                        if (!$stmt_insert_batch->bind_param("iddddsiii", $b_product_id, $b_qty, $b_remaining, $b_original, $b_unit_cost, $b_received_at, $b_source_invoice_id, $b_source_item_id, $b_created_by)) {
                            throw new Exception('فشل ربط بيانات إدخال الدفعة: ' . $stmt_insert_batch->error);
                        }
                        if (!$stmt_insert_batch->execute()) {
                            throw new Exception('فشل إدخال الدفعة: ' . $stmt_insert_batch->error);
                        }
                        $new_batch_id = $stmt_insert_batch->insert_id;
                        $stmt_insert_batch->close();
                        if (!$stmt_update_item->bind_param("dii", $qty, $new_batch_id, $item_id) || !$stmt_update_item->execute()) {
                            throw new Exception('فشل تحديث بند الفاتورة بعد إنشاء الدفعة: ' . $stmt_update_item->error);
                        }
                        continue;
                    }

                    // fallback path if previous sale_price NULL insert branch: do standard insert without sale_price
                    if (!$stmt_insert_batch->execute()) {
                        throw new Exception('فشل إدخال الدفعة (fallback): ' . $stmt_insert_batch->error);
                    }
                    $new_batch_id = $stmt_insert_batch->insert_id;
                    if (!$stmt_update_item->bind_param("dii", $qty, $new_batch_id, $item_id) || !$stmt_update_item->execute()) {
                        throw new Exception('فشل تحديث بند الفاتورة بعد إنشاء الدفعة: ' . $stmt_update_item->error);
                    }
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

        header("Location: ".basename(__FILE__)); exit;
    }

    // ----- CHANGE STATUS => pending (revert) -----
    if (isset($_POST['change_invoice_status']) && isset($_POST['new_status']) && $_POST['new_status'] === 'pending') {
        $invoice_id = intval($_POST['purchase_invoice_id'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');
        if ($invoice_id <= 0) { $_SESSION['message'] = "<div class='alert alert-danger'>معرف غير صالح.</div>"; header("Location: ".basename(__FILE__)); exit; }
        if ($reason === '') { $_SESSION['message'] = "<div class='alert alert-warning'>الرجاء إدخال سبب الإرجاع.</div>"; header("Location: ".basename(__FILE__)); exit; }

        $conn->begin_transaction();
        try {
            // lock batches for invoice
            $stb = $conn->prepare("SELECT id, product_id, qty, remaining, original_qty, status FROM batches WHERE source_invoice_id = ? FOR UPDATE");
            if (!$stb) throw new Exception("فشل تحضير استعلام الدُفعات: " . $conn->error);
            $stb->bind_param("i", $invoice_id); $stb->execute();
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

                $qty_f = $qty; $pid_i = $pid;
                if (!$upd_prod->bind_param("di", $qty_f, $pid_i) || !$upd_prod->execute()) {
                    throw new Exception("فشل تحديث رصيد المنتج أثناء التراجع: " . $upd_prod->error);
                }
                $reason_s = $reason; $bid_i = $bid;
                if (!$upd_batch->bind_param("si", $reason_s, $bid_i) || !$upd_batch->execute()) {
                    throw new Exception("فشل تحديث الدفعة أثناء التراجع: " . $upd_batch->error);
                }
            }

            // reset items qty_received and batch linkage
            $rst = $conn->prepare("UPDATE purchase_invoice_items SET qty_received = 0, batch_id = NULL WHERE purchase_invoice_id = ?");
            $rst->bind_param("i", $invoice_id); $rst->execute(); $rst->close();

            // update invoice
            $u = $conn->prepare("UPDATE purchase_invoices SET status = 'pending', revert_reason = ?, updated_by = ?, updated_at = NOW() WHERE id = ?");
            $u_by = $current_user_id;
            $u->bind_param("sii", $reason, $u_by, $invoice_id); $u->execute(); $u->close();

            $conn->commit();
            $_SESSION['message'] = "<div class='alert alert-success'>تم إرجاع الفاتورة إلى قيد الانتظار.</div>";
        } catch (Exception $e) {
            $conn->rollback();
            error_log('Revert invoice error: ' . $e->getMessage());
            $_SESSION['message'] = "<div class='alert alert-danger'>فشل إعادة الفاتورة: " . e($e->getMessage()) . "</div>";
        }

        header("Location: ".basename(__FILE__)); exit;
    }

    // ----- CANCEL invoice (soft) -----
    if (isset($_POST['cancel_purchase_invoice'])) {
        $invoice_id = intval($_POST['purchase_invoice_id'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');
        if ($invoice_id <= 0) { $_SESSION['message'] = "<div class='alert alert-danger'>معرف غير صالح.</div>"; header("Location: ".basename(__FILE__)); exit; }
        if ($reason === '') { $_SESSION['message'] = "<div class='alert alert-warning'>الرجاء إدخال سبب الإلغاء.</div>"; header("Location: ".basename(__FILE__)); exit; }

        try {
            $st = $conn->prepare("SELECT status FROM purchase_invoices WHERE id = ? FOR UPDATE");
            $st->bind_param("i", $invoice_id); $st->execute(); $r = $st->get_result()->fetch_assoc(); $st->close();
            if (!$r) { $_SESSION['message'] = "<div class='alert alert-danger'>الفاتورة غير موجودة.</div>"; header("Location: ".basename(__FILE__)); exit; }
            if ($r['status'] === 'fully_received') { $_SESSION['message'] = "<div class='alert alert-warning'>لا يمكن إلغاء فاتورة تم استلامها بالكامل. الرجاء إجراء تراجع أولاً.</div>"; header("Location: ".basename(__FILE__)); exit; }

            $upd = $conn->prepare("UPDATE purchase_invoices SET status = 'cancelled', cancel_reason = ?, updated_by = ?, updated_at = NOW() WHERE id = ?");
            $upd_by = $current_user_id;
            $upd->bind_param("sii", $reason, $upd_by, $invoice_id);
            $upd->execute(); $upd->close();
            $_SESSION['message'] = "<div class='alert alert-success'>تم إلغاء الفاتورة.</div>";
        } catch (Exception $e) {
            error_log('Cancel invoice error: ' . $e->getMessage());
            $_SESSION['message'] = "<div class='alert alert-danger'>فشل الإلغاء.</div>";
        }
        header("Location: ".basename(__FILE__)); exit;
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
            $st->bind_param("i",$invoice_id); $st->execute(); $inv = $st->get_result()->fetch_assoc(); $st->close();
            if (!$inv) throw new Exception("الفاتورة غير موجودة");

            foreach ($items_data as $it) {
                $item_id = intval($it['item_id'] ?? 0);
                $new_qty = (float)($it['new_quantity'] ?? 0);
                $new_cost = isset($it['new_cost_price'] ) ? (float)$it['new_cost_price'] : null;
                $new_sale = isset($it['new_sale_price'] ) ? (float)$it['new_sale_price'] : null;
                if ($item_id <= 0) continue;

                // lock item
                $sti = $conn->prepare("SELECT id, purchase_invoice_id, product_id, quantity, qty_received, cost_price_per_unit FROM purchase_invoice_items WHERE id = ? FOR UPDATE");
                $sti->bind_param("i", $item_id); $sti->execute(); $row = $sti->get_result()->fetch_assoc(); $sti->close();
                if (!$row) throw new Exception("بند غير موجود: #$item_id");
                $old_qty = (float)$row['quantity']; $prod_id = intval($row['product_id']);

                if ($inv['status'] === 'pending') {
                    $diff = $new_qty - $old_qty;
                    $qty_adj = $diff;
                    $qty_adj_str = (string)$qty_adj;
                    $adj_by = $current_user_id;

                    $upit = $conn->prepare("UPDATE purchase_invoice_items SET quantity = ?, qty_adjusted = ?, adjustment_reason = ?, adjusted_by = ?, adjusted_at = NOW() WHERE id = ?");
                    if (!$upit) throw new Exception("فشل تحضير تعديل البند: " . $conn->error);
                    $upit->bind_param("dssii", $new_qty, $qty_adj_str, $adjust_reason, $adj_by, $item_id);
                    if (!$upit->execute()) { $upit->close(); throw new Exception("فشل تعديل البند: " . $upit->error); }
                    $upit->close();

                    // optionally update cost_price_per_unit or sale price in the item (if provided)
                    if ($new_cost !== null) {
                        $stmtc = $conn->prepare("UPDATE purchase_invoice_items SET cost_price_per_unit = ? WHERE id = ?");
                        $stmtc->bind_param("di", $new_cost, $item_id);
                        $stmtc->execute(); $stmtc->close();
                    }
                    if ($new_sale !== null) {
                        $stmts = $conn->prepare("UPDATE purchase_invoice_items SET sale_price = ? WHERE id = ?");
                        $stmts->bind_param("di", $new_sale, $item_id);
                        $stmts->execute(); $stmts->close();
                        // optionally also update products.selling_price? (policy decision)
                        // $stp = $conn->prepare("UPDATE products SET selling_price = ? WHERE id = ?");
                        // $stp->bind_param("di", $new_sale, $prod_id); $stp->execute(); $stp->close();
                    }

                    continue;
                }

                if ($inv['status'] === 'fully_received') {
                    // find batch linked to this item
                    $stb = $conn->prepare("SELECT id, qty, remaining, original_qty FROM batches WHERE source_item_id = ? FOR UPDATE");
                    $stb->bind_param("i", $item_id); $stb->execute(); $batch = $stb->get_result()->fetch_assoc(); $stb->close();
                    if (!$batch) throw new Exception("لا توجد دفعة مرتبطة بالبند #$item_id");
                    if (((float)$batch['remaining']) < ((float)$batch['original_qty'])) throw new Exception("لا يمكن تعديل هذا البند لأن الدفعة المرتبطة به قد اُستهلكت.");

                    $diff = $new_qty - $old_qty;
                    $qty_adj = $diff;
                    $qty_adj_str = (string)$qty_adj;
                    $adj_by = $current_user_id;

                    $upit = $conn->prepare("UPDATE purchase_invoice_items SET quantity = ?, qty_adjusted = ?, adjustment_reason = ?, adjusted_by = ?, adjusted_at = NOW() WHERE id = ?");
                    if (!$upit) throw new Exception("فشل تحضير تعديل البند: " . $conn->error);
                    $upit->bind_param("dssii", $new_qty, $qty_adj_str, $adjust_reason, $adj_by, $item_id);
                    if (!$upit->execute()) { $upit->close(); throw new Exception("فشل تعديل البند: " . $upit->error); }
                    $upit->close();

                    // update batch quantities
                    $new_batch_qty = (float)$batch['qty'] + $diff;
                    $new_remaining = (float)$batch['remaining'] + $diff;
                    $new_original = (float)$batch['original_qty'] + $diff;
                    if ($new_remaining < 0) throw new Exception("التعديل يؤدي إلى قيمة متبقية سلبية");

                    $adj_by_i = $current_user_id;
                    $upb = $conn->prepare("UPDATE batches SET qty = ?, remaining = ?, original_qty = ?, adjusted_by = ?, adjusted_at = NOW() WHERE id = ?");
                    if (!$upb) throw new Exception("فشل تحضير تحديث الدفعة: " . $conn->error);
                    $upb->bind_param("ddiii", $new_batch_qty, $new_remaining, $new_original, $adj_by_i, $batch['id']);
                    if (!$upb->execute()) { $upb->close(); throw new Exception("فشل تحديث الدفعة: " . $upb->error); }
                    $upb->close();

                    // update product stock
                    $upprod = $conn->prepare("UPDATE products SET current_stock = current_stock + ? WHERE id = ?");
                    $upprod->bind_param("di", $diff, $prod_id);
                    if (!$upprod->execute()) { $upprod->close(); throw new Exception("فشل تحديث المخزون: " . $upprod->error); }
                    $upprod->close();

                    // optionally update cost / sale price on item or batch if provided
                    if ($new_cost !== null) {
                        $stmtc = $conn->prepare("UPDATE purchase_invoice_items SET cost_price_per_unit = ? WHERE id = ?");
                        $stmtc->bind_param("di", $new_cost, $item_id);
                        $stmtc->execute(); $stmtc->close();

                        $upb_cost = $conn->prepare("UPDATE batches SET unit_cost = ? WHERE id = ?");
                        $upb_cost->bind_param("di", $new_cost, $batch['id']);
                        $upb_cost->execute(); $upb_cost->close();
                    }
                    if ($new_sale !== null && has_column($conn, 'batches', 'sale_price')) {
                        $stmt_sale_item = $conn->prepare("UPDATE purchase_invoice_items SET sale_price = ? WHERE id = ?");
                        $stmt_sale_item->bind_param("di", $new_sale, $item_id);
                        $stmt_sale_item->execute(); $stmt_sale_item->close();

                        $upb_sale = $conn->prepare("UPDATE batches SET sale_price = ? WHERE id = ?");
                        $upb_sale->bind_param("di", $new_sale, $batch['id']);
                        $upb_sale->execute(); $upb_sale->close();
                    }

                    continue;
                }

                throw new Exception("لا يمكن التعديل في الحالة الحالية");
            }

            // recalc invoice total
            $sttot = $conn->prepare("SELECT COALESCE(SUM(quantity * cost_price_per_unit),0) AS total FROM purchase_invoice_items WHERE purchase_invoice_id = ?");
            $sttot->bind_param("i", $invoice_id); $sttot->execute(); $rt = $sttot->get_result()->fetch_assoc(); $sttot->close();
            $new_total = (float)($rt['total'] ?? 0.0);
            $u_by = $current_user_id;
            $upinv = $conn->prepare("UPDATE purchase_invoices SET total_amount = ?, updated_by = ?, updated_at = NOW() WHERE id = ?");
            $upinv->bind_param("dii", $new_total, $u_by, $invoice_id); $upinv->execute(); $upinv->close();

            // append adjustment note to invoice notes
            if ($adjust_reason !== '') {
                $now = date('Y-m-d H:i:s');
                $note_line = "[" . $now . "] تعديل بنود: " . $adjust_reason . " (المحرر: " . e($current_user_name) . ")\\n";
                append_invoice_note($conn, $invoice_id, $note_line);
            }

            $conn->commit();
            $_SESSION['message'] = "<div class='alert alert-success'>تم حفظ التعديلات بنجاح.</div>";
        } catch (Exception $e) {
            $conn->rollback();
            error_log('Edit invoice error: ' . $e->getMessage());
            $_SESSION['message'] = "<div class='alert alert-danger'>فشل حفظ التعديلات: " . e($e->getMessage()) . "</div>";
        }

        header("Location: " . basename(__FILE__)); exit;
    }
}

// ---------- عرض الصفحة (الفلترة و الجدول) ----------
$selected_supplier_id = isset($_GET['supplier_filter_val']) ? intval($_GET['supplier_filter_val']) : '';
$selected_status = isset($_GET['status_filter_val']) ? trim($_GET['status_filter_val']) : '';

$suppliers_list = [];
$sql_suppliers = "SELECT id, name FROM suppliers ORDER BY name ASC";
$rs = $conn->query($sql_suppliers);
if ($rs) while ($r = $rs->fetch_assoc()) $suppliers_list[] = $r;

$grand_total_all_purchases = 0;
$rs2 = $conn->query("SELECT COALESCE(SUM(total_amount),0) AS grand_total FROM purchase_invoices WHERE status != 'cancelled'");
if ($rs2) { $r2 = $rs2->fetch_assoc(); $grand_total_all_purchases = (float)$r2['grand_total']; }

// fetch invoices with filters
$sql_select_invoices = "SELECT pi.id, pi.supplier_invoice_number, pi.purchase_date, pi.status, pi.total_amount, pi.created_at, s.name as supplier_name, u.username as creator_name
                        FROM purchase_invoices pi
                        JOIN suppliers s ON pi.supplier_id = s.id
                        LEFT JOIN users u ON pi.created_by = u.id";
$conds = []; $params = []; $types = '';
if (!empty($selected_supplier_id)) { $conds[] = "pi.supplier_id = ?"; $params[] = $selected_supplier_id; $types .= 'i'; }
if (!empty($selected_status)) { $conds[] = "pi.status = ?"; $params[] = $selected_status; $types .= 's'; }
if (!empty($conds)) $sql_select_invoices .= " WHERE " . implode(" AND ", $conds);
$sql_select_invoices .= " ORDER BY pi.purchase_date DESC, pi.id DESC";

$result_invoices = null;
if ($stmt_select = $conn->prepare($sql_select_invoices)) {
    if (!empty($params)) {
        // bind dynamically
        $stmt_select->bind_param($types, ...$params);
    }
    $stmt_select->execute();
    $result_invoices = $stmt_select->get_result();
    $stmt_select->close();
} else {
    $message = "<div class='alert alert-danger'>خطأ في تحضير استعلام جلب فواتير المشتريات: " . e($conn->error) . "</div>";
}

$displayed_invoices_sum = 0;
$sql_total_displayed = "SELECT COALESCE(SUM(total_amount),0) AS total_displayed FROM purchase_invoices pi WHERE 1=1";
$conds_total = []; $params_total = []; $types_total = '';
if (!empty($selected_supplier_id)) { $conds_total[] = "pi.supplier_id = ?"; $params_total[] = $selected_supplier_id; $types_total .= 'i'; }
if (!empty($selected_status)) { $conds_total[] = "pi.status = ?"; $params_total[] = $selected_status; $types_total .= 's'; }
if (!empty($conds_total)) $sql_total_displayed .= " AND " . implode(" AND ", $conds_total);
if ($stmt_total = $conn->prepare($sql_total_displayed)) {
    if (!empty($params_total)) $stmt_total->bind_param($types_total, ...$params_total);
    $stmt_total->execute();
    $res_t = $stmt_total->get_result(); $rowt = $res_t->fetch_assoc();
    $displayed_invoices_sum = (float)($rowt['total_displayed'] ?? 0);
    $stmt_total->close();
}

// ... (the rest of your page rendering / HTML comes here - unchanged)
// You should ensure your UI/JS uses fetch_invoice_json to display invoice details incl. batches,
// and that your edit modal sends items_json + adjust_reason when saving edits.

// header/sidebar
require_once BASE_DIR . 'partials/header.php';
require_once BASE_DIR . 'partials/sidebar.php';
?>

<!-- ====== HTML & JS (واجهة محسّنة بسيطة) ====== -->

<style>
:root { --primary:#0b84ff; --bg:#f6f8fc; --surface:#fff; --text:#0f172a; --radius:12px; --shadow: 0 10px 24px rgba(2,6,23,0.06); }
.container { max-width:1200px; }
.card { border-radius:12px; box-shadow:var(--shadow); }
.badge-pending { background:linear-gradient(90deg,#f59e0b,#d97706); color:#fff; padding:6px 10px; border-radius:20px; }
.badge-received { background:linear-gradient(90deg,#10b981,#0ea5e9); color:#fff; padding:6px 10px; border-radius:20px; }
.badge-cancelled { background:linear-gradient(90deg,#ef4444,#dc2626); color:#fff; padding:6px 10px; border-radius:20px; }
.modal-backdrop-custom{ position:fixed; inset:0; display:none; align-items:center; justify-content:center; background:rgba(2,6,23,0.45); z-index:9999; padding:16px; }
.modal-card-custom{ width:100%; max-width:980px; background:var(--surface); border-radius:12px; box-shadow:0 20px 50px rgba(2,6,23,0.16); overflow:auto; max-height:90vh; padding:18px; }
/* Simple modal styles (paste into your CSS) */
.modal { position: fixed; inset: 0; display:flex; align-items:center; justify-content:center; background: rgba(0,0,0,0.4); z-index: 2000; }
.modal .modal-content { background:#fff; border-radius:8px; width:90%; max-width:1100px; max-height:85vh; overflow:auto; box-shadow:0 12px 40px rgba(0,0,0,0.3); padding:0; }
.modal .modal-content.wide { max-width:1300px; }
.modal-header { display:flex; justify-content:space-between; align-items:center; padding:12px 16px; border-bottom:1px solid #eee; }
.modal-body { padding:14px 18px; }
.modal-footer { padding:10px 16px; border-top:1px solid #eee; text-align:left; }
.btn-close { background:transparent; border:0; font-size:22px; cursor:pointer; }
.table { width:100%; border-collapse:collapse; margin-top:8px; }
.table th, .table td { padding:8px 10px; border:1px solid #eee; text-align:left; font-size:13px; }
.small-muted { color:#6b7280; font-size:13px; }
.inv-meta { display:flex; gap:12px; flex-wrap:wrap; margin-bottom:8px; }
.inv-notes pre { background:#f8fafc; padding:10px; border-radius:6px; min-height:40px; }
input.edit-input { width:100px; padding:6px; }


</style>

<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3><i class="fas fa-dolly-flatbed"></i> إدارة فواتير المشتريات</h3>
        <a href="<?php echo BASE_URL; ?>admin/manage_suppliers.php" class="btn btn-success">إنشاء فاتورة جديدة</a>
    </div>

    <?php if (!empty($message)) echo $message; if (!empty($_SESSION['message'])) { echo $_SESSION['message']; unset($_SESSION['message']); } ?>

    <div class="card mb-3">
        <div class="card-body">
            <form method="get" class="row gx-2 gy-2 align-items-end">
                <div class="col-md-4">
                    <label>المورد</label>
                    <select name="supplier_filter_val" class="form-select">
                        <option value="">-- كل الموردين --</option>
                        <?php foreach ($suppliers_list as $s): ?>
                            <option value="<?php echo $s['id']; ?>" <?php echo ($selected_supplier_id == $s['id']) ? 'selected':''; ?>><?php echo e($s['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label>الحالة</label>
                    <select name="status_filter_val" class="form-select">
                        <option value="">-- كل الحالات --</option>
                        <?php foreach ($status_labels as $k=>$v): ?>
                            <option value="<?php echo $k; ?>" <?php echo ($selected_status == $k) ? 'selected' : ''; ?>><?php echo e($v); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2"><button class="btn btn-primary w-100">تصفية</button></div>
                <?php if($selected_supplier_id || $selected_status): ?>
                <div class="col-md-2"><a href="<?php echo basename(__FILE__); ?>" class="btn btn-outline-secondary w-100">مسح</a></div>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <div class="card mb-3">
      <div class="card-body p-2">
        <div class="table-responsive">
          <table class="table table-striped mb-0">
            <thead class="table-dark">
              <tr><th>#</th><th>المورد</th><th class="d-none d-md-table-cell">رقم المورد</th><th>تاريخ</th><th class="d-none d-md-table-cell">الحالة</th><th class="text-end">الإجمالي</th><th class="text-center">إجراءات</th></tr>
            </thead>
            <tbody>
              <?php if ($result_invoices && $result_invoices->num_rows>0): while($inv = $result_invoices->fetch_assoc()): ?>
                <tr>
                  <td><?php echo e($inv['id']); ?></td>
                  <td><?php echo e($inv['supplier_name']); ?></td>
                  <td class="d-none d-md-table-cell"><?php echo e($inv['supplier_invoice_number'] ?: '-'); ?></td>
                  <td><?php echo e(date('Y-m-d', strtotime($inv['purchase_date']))); ?></td>
                  <td class="d-none d-md-table-cell">
                    <?php if ($inv['status']==='pending'): ?><span class="badge-pending"><?php echo e($status_labels['pending']); ?></span>
                    <?php elseif ($inv['status']==='fully_received'): ?><span class="badge-received"><?php echo e($status_labels['fully_received']); ?></span>
                    <?php else: ?><span class="badge-cancelled"><?php echo e($status_labels['cancelled']); ?></span><?php endif; ?>
                  </td>
                  <td class="text-end fw-bold"><?php echo number_format((float)$inv['total_amount'],2); ?> ج.م</td>
                  <td class="text-center">
                    <button class="btn btn-info btn-sm" onclick="openInvoiceModalView(<?php echo $inv['id']; ?>)">عرض</button>
                    <?php if ($inv['status']==='pending'): ?>
                      <button class="btn btn-warning btn-sm" onclick="openInvoiceModalEdit(<?php echo $inv['id']; ?>)">تعديل</button>
                      <form method="post" style="display:inline-block" onsubmit="return confirm('تأكيد استلام الفاتورة بالكامل؟')">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="purchase_invoice_id" value="<?php echo $inv['id']; ?>">
                        <button type="submit" name="receive_purchase_invoice" class="btn btn-success btn-sm">استلام</button>
                      </form>
                      <button class="btn btn-danger btn-sm" onclick="openReasonModal('cancel', <?php echo $inv['id']; ?>)">إلغاء</button>
                    <?php elseif ($inv['status']==='fully_received'): ?>
                      <button class="btn btn-warning btn-sm" onclick="openInvoiceModalEdit(<?php echo $inv['id']; ?>)">تعديل</button>
                      <button class="btn btn-outline-secondary btn-sm" onclick="openReasonModal('revert', <?php echo $inv['id']; ?>)">قيد الانتظار</button>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endwhile; else: ?>
                <tr><td colspan="7" class="text-center">لا توجد فواتير.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <div class="row mt-3">
      <div class="col-md-6 offset-md-6">
        <div class="card">
          <div class="card-body">
            <div><strong>إجمالي الفواتير المعروضة:</strong> <span class="badge bg-primary"><?php echo number_format($displayed_invoices_sum,2); ?> ج.م</span></div>
            <div class="mt-2"><strong>الإجمالي الكلي (غير الملغاة):</strong> <span class="badge bg-success"><?php echo number_format($grand_total_all_purchases,2); ?> ج.م</span></div>
          </div>
        </div>
      </div>
    </div>

</div>

<<!-- Invoice View Modal -->
<div id="invoiceModal" class="modal" style="display:none;">
  <div class="modal-content">
    <div class="modal-header">
      <h3 id="inv_modal_title">تفاصيل الفاتورة</h3>
      <button class="btn-close" data-close="invoiceModal">×</button>
    </div>
    <div class="modal-body">
      <div class="inv-meta">
        <div><strong>رقم الفاتورة:</strong> <span id="inv_id_text"></span></div>
        <div><strong>المورد:</strong> <span id="inv_supplier"></span></div>
        <div><strong>التاريخ:</strong> <span id="inv_date"></span></div>
        <div><strong>الحالة:</strong> <span id="inv_status_label"></span></div>
      </div>

      <hr>

      <div class="inv-notes">
        <h4>ملاحظات الفاتورة</h4>
        <pre id="inv_notes" style="white-space:pre-wrap;"></pre>
      </div>

      <hr>

      <div class="inv-items">
        <h4>بنود الفاتورة</h4>
        <table class="table" id="inv_items_table">
          <thead>
            <tr>
              <th>#</th><th>المنتج</th><th>كمية</th><th>سعر شراء</th><th>سعر بيع (مخزّن بالبند)</th><th>مستلم</th><th>إجمالي</th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>

      <hr>

      <div class="inv-batches">
        <h4>الدفعات (Batches) المرتبطة</h4>
        <table class="table" id="inv_batches_table">
          <thead>
            <tr>
              <th>دفعة</th><th>منتج</th><th>كمية</th><th>متبقي</th><th>سعر شراء</th><th>سعر بيع (batch)</th><th>الحالة</th><th>سبب الإرجاع</th><th>سبب الإلغاء</th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>

    </div>
    <div class="modal-footer">
      <button id="btn_open_edit" class="btn btn-primary">تعديل بنود</button>
      <button id="btn_close_inv" class="btn btn-secondary" data-close="invoiceModal">إغلاق</button>
    </div>
  </div>
</div>

<!-- Edit Invoice Modal -->
<div id="editInvoiceModal" class="modal" style="display:none;">
  <div class="modal-content wide">
    <div class="modal-header">
      <h3>تعديل بنود الفاتورة <span id="edit_inv_id"></span></h3>
      <button class="btn-close" data-close="editInvoiceModal">×</button>
    </div>
    <div class="modal-body">
      <div class="edit-instructions small-muted">عدّل الكمية أو سعر الشراء أو سعر البيع هنا، وأضف سبب التعديل (مطلوب). سيتم حفظ السبب في ملاحظات الفاتورة.</div>

      <table class="table" id="edit_items_table">
        <thead>
          <tr>
            <th>#</th><th>اسم المنتج</th><th>كمية حالية</th><th>كمية جديدة</th><th>سعر شراء حالي</th><th>سعر شراء جديد</th><th>سعر بيع حالي</th><th>سعر بيع جديد</th>
          </tr>
        </thead>
        <tbody></tbody>
      </table>

      <div style="margin-top:8px;">
        <label for="adjust_reason"><strong>سبب التعديل (مطلوب)</strong></label>
        <textarea id="adjust_reason" name="adjust_reason" rows="3" style="width:100%;"></textarea>
      </div>
    </div>
    <div class="modal-footer">
      <button id="btn_save_edit" class="btn btn-success">حفظ التعديلات</button>
      <button class="btn btn-secondary" data-close="editInvoiceModal">إلغاء</button>
    </div>
  </div>
</div>

<!-- helper hidden form will be created by JS when submitting edits -->

<!-- Reason modal -->
<div id="reasonModalBackdrop" class="modal-backdrop-custom"><div class="modal-card-custom"><form id="reasonForm" method="post"><input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>"><input type="hidden" name="purchase_invoice_id" id="reason_invoice_id" value=""><input type="hidden" name="new_status" id="reason_new_status" value=""><div style="margin-bottom:8px"><label>السبب (مطلوب)</label><textarea name="reason" id="reason_text" class="form-control" rows="4" required></textarea></div><div style="text-align:left;"><button type="submit" class="btn btn-primary">تأكيد</button> <button type="button" onclick="document.getElementById('reasonModalBackdrop').style.display='none';" class="btn btn-outline-secondary">إلغاء</button></div></form></div></div>

<!-- <script>
const ajaxUrl = '<?php echo basename(__FILE__); ?>';

function openInvoiceModalView(id){
  const bp = document.getElementById('invoiceModalBackdrop');
  const content = document.getElementById('invoiceModalContent');
  bp.style.display = 'flex'; content.innerHTML = 'جارٍ التحميل...';
  fetch(ajaxUrl + '?action=fetch_invoice_json&id=' + encodeURIComponent(id), {credentials:'same-origin'})
    .then(r => r.json())
    .then(data => {
      if (!data.ok) { content.innerHTML = '<div class="alert alert-danger">فشل جلب البيانات: ' + (data.msg||'') + '</div>'; return; }
      const inv = data.invoice; const items = data.items;
      let html = '<div style="display:flex;justify-content:space-between;"><div><strong>فاتورة مشتريات — #' + inv.id + '</strong><div style="font-size:0.85rem;color:#666;">' + (inv.purchase_date || inv.created_at) + '</div></div><div>' + (inv.status==='fully_received'?'<span class="badge-received">مستلمة</span>':(inv.status==='cancelled'?'<span class="badge-cancelled">ملغاة</span>':'<span class="badge-pending">مؤجلة</span>')) + '</div></div>';
      html += '<div style="margin-top:12px;"><div><strong>المورد:</strong> ' + (inv.supplier_name||'') + '</div><div><strong>الإجمالي:</strong> ' + Number(inv.total_amount||0).toFixed(2) + ' ج.م</div></div>';
      html += '<div style="margin-top:12px;border:1px solid rgba(0,0,0,0.06);padding:6px;"><table style="width:100%;border-collapse:collapse;"><thead style="font-weight:700;background:rgba(0,0,0,0.03)"><tr><th>#</th><th>اسم</th><th>كمية</th><th>سعر</th><th>إجمالي</th></tr></thead><tbody>';
      let total = 0;
      if (items.length) {
        items.forEach((it, idx)=> {
          const line = Number(it.total_cost || (it.quantity * it.cost_price_per_unit) || 0).toFixed(2);
          total += parseFloat(line);
          html += '<tr><td>'+(idx+1)+'</td><td style="text-align:right">'+(it.product_name?it.product_name+' — '+(it.product_code||''):'#'+it.product_id)+'</td><td style="text-align:center">'+Number(it.quantity).toFixed(2)+'</td><td style="text-align:right">'+Number(it.cost_price_per_unit).toFixed(2)+'</td><td style="text-align:right;font-weight:700">'+line+' ج.م</td></tr>';
        });
      } else {
        html += '<tr><td colspan="5" style="text-align:center">لا توجد بنود</td></tr>';
      }
      html += '</tbody><tfoot><tr><td colspan="4" style="text-align:right;font-weight:700">الإجمالي الكلي</td><td style="text-align:right;font-weight:800">'+ total.toFixed(2) +' ج.م</td></tr></tfoot></table></div>';
      content.innerHTML = html;
    }).catch(err => { content.innerHTML = '<div class="alert alert-danger">فشل الاتصال بالخادم.</div>'; console.error(err); });
}

function openInvoiceModalEdit(id){
  const bp = document.getElementById('editModalBackdrop');
  const body = document.getElementById('editInvoiceBody');
  document.getElementById('edit_invoice_id').value = id;
  bp.style.display = 'flex'; body.innerHTML = 'جارٍ التحميل...';
  fetch(ajaxUrl + '?action=fetch_invoice_json&id=' + encodeURIComponent(id), {credentials:'same-origin'})
    .then(r=>r.json())
    .then(data=>{
      if (!data.ok) { body.innerHTML = '<div class="alert alert-danger">فشل جلب الفاتورة: ' + (data.msg||'') + '</div>'; return; }
      if (!data.can_edit) { body.innerHTML = '<div class="alert alert-warning">لا يمكن التعديل لأن الدُفعات مستهلكة أو الحالة لا تسمح.</div>'; return; }
      const items = data.items;
      let html = '<table class="table table-sm"><thead><tr><th>#</th><th>المنتج</th><th>كمية حالية</th><th>كمية جديدة</th></tr></thead><tbody>';
      items.forEach((it, idx) => {
        html += '<tr><td>'+(idx+1)+'</td><td>'+(it.product_name?it.product_name+' — '+(it.product_code||''):'#'+it.product_id)+'</td><td>'+Number(it.quantity).toFixed(2)+'</td><td><input class="form-control edit-item-qty" data-item-id="'+it.id+'" type="number" step="0.01" value="'+Number(it.quantity).toFixed(2)+'"></td></tr>';
      });
      html += '</tbody></table>';
      body.innerHTML = html;
    }).catch(err => { body.innerHTML = '<div class="alert alert-danger">فشل الاتصال</div>'; console.error(err); });

  document.getElementById('editInvoiceForm').onsubmit = function(e){
    // assemble items_json
    const inputs = document.querySelectorAll('.edit-item-qty');
    const items = [];
    inputs.forEach(inp => items.push({ item_id: parseInt(inp.dataset.itemId), new_quantity: parseFloat(inp.value) }));
    const hidden = document.createElement('input'); hidden.type='hidden'; hidden.name='items_json'; hidden.value = JSON.stringify(items);
    this.appendChild(hidden);
    // allow normal submit to server
  };
}

function openReasonModal(action, invoiceId){
  const bp = document.getElementById('reasonModalBackdrop');
  document.getElementById('reason_invoice_id').value = invoiceId;
  document.getElementById('reason_text').value = '';
  document.getElementById('reason_new_status').value = (action==='revert')?'pending':'';
  // set proper hidden fields in form: the server checks submitted keys
  // if action === 'revert' ensure there is input name change_invoice_status=1
  // else ensure cancel_purchase_invoice=1
  // we'll add them dynamically:
  const form = document.getElementById('reasonForm');
  // remove previous markers
  const prevChange = document.getElementById('reason_marker_change'); if (prevChange) prevChange.remove();
  const prevCancel = document.getElementById('reason_marker_cancel'); if (prevCancel) prevCancel.remove();
  if (action==='revert') {
    const i = document.createElement('input'); i.type='hidden'; i.name='change_invoice_status'; i.value='1'; i.id='reason_marker_change'; form.appendChild(i);
  } else {
    const i = document.createElement('input'); i.type='hidden'; i.name='cancel_purchase_invoice'; i.value='1'; i.id='reason_marker_cancel'; form.appendChild(i);
  }
  bp.style.display = 'flex';
  form.onsubmit = function(e){
    // default form submission to server; server will redirect back with message
  };
}

document.querySelectorAll('#invoiceModalBackdrop, #editModalBackdrop, #reasonModalBackdrop').forEach(el=>{
  el.addEventListener('click', function(e){ if(e.target === el) el.style.display='none'; });
});
</scriptب> -->

<script>
/*
  Updated JS for manage_purchase_invoices.php
  - يعتمد نفس منهجية السكربت القديم (fetch -> build HTML -> عرض مودال)
  - يتوافق مع fetch_invoice_json و POST edit_invoice (form submit)
  - يدعم أسماء العناصر القديمة (invoiceModalBackdrop / invoiceModalContent) أو الجديدة (invoiceModal .modal-content)
  - تأكد أن لديك: form#editInvoiceForm و form#reasonForm أو عناصر مقابلة في الصفحة
*/

(function(){
  const ajaxUrl = '<?php echo basename(__FILE__); ?>';
  const CSRF_TOKEN = <?php echo json_encode($csrf_token); ?>;

  // --- helpers DOM ---
  function q(sel, ctx=document){ return ctx.querySelector(sel); }
  function qa(sel, ctx=document){ return Array.from((ctx||document).querySelectorAll(sel)); }
  function el(tag, attrs){ const e = document.createElement(tag); for(const k in (attrs||{})) e.setAttribute(k, attrs[k]); return e; }
  function escapeHtml(s){ if (s===null||s===undefined) return ''; return String(s).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":"&#39;"}[m])); }

  // find modal root and content elements (compatible with old & new markup)
  function getModalParts(baseNames){
    // baseNames: {backdropId, modalId, contentId}
    let backdrop = document.getElementById(baseNames.backdropId || '');
    let modal = document.getElementById(baseNames.modalId || '');
    let content = null;
    if (backdrop) {
      content = backdrop.querySelector(baseNames.contentSelector || '#invoiceModalContent') || backdrop.querySelector('.modal-content');
    } else if (modal) {
      content = modal.querySelector(baseNames.contentSelector || '.modal-content') || modal.querySelector('.modal-body') || modal.querySelector('.modal-content');
      backdrop = modal; // treat modal as backdrop for display toggling
    } else {
      // try generic ids
      backdrop = document.getElementById('invoiceModalBackdrop') || document.getElementById('invoiceModal') || null;
      if (backdrop) content = backdrop.querySelector('#invoiceModalContent') || backdrop.querySelector('.modal-content') || backdrop.querySelector('.modal-body');
    }
    return { backdrop, content, modal };
  }

  // show/hide modal
  function showModal(elem){ if(!elem) return; elem.style.display = 'flex'; }
  function hideModal(elem){ if(!elem) return; elem.style.display = 'none'; }

  // ------------------ View Invoice Modal ------------------
  async function openInvoiceModalView(id){
    if (!id) return;
    // support old ids or new modal structure
    const parts = getModalParts({ backdropId: 'invoiceModalBackdrop', modalId: 'invoiceModal', contentSelector: '#invoiceModalContent' });
    const backdrop = parts.backdrop;
    const content = parts.content;

    if (!backdrop || !content) {
      console.warn('invoice modal elements not found. Ensure #invoiceModal or #invoiceModalBackdrop present.');
    }

    // show loading
    if (backdrop) showModal(backdrop);
    if (content) content.innerHTML = '<div style="padding:18px">جارٍ التحميل...</div>';

    try {
      const res = await fetch(ajaxUrl + '?action=fetch_invoice_json&id=' + encodeURIComponent(id), { credentials: 'same-origin' });
      const data = await res.json();
      if (!data || !data.ok) {
        if (content) content.innerHTML = '<div class="alert alert-danger" style="padding:12px">فشل جلب بيانات: ' + escapeHtml(data.msg || 'خطأ') + '</div>';
        return;
      }
      const inv = data.invoice || {};
      const items = data.items || [];
      const batches = data.batches || [];

      // build HTML (keeps similar layout to old script but extended with notes & batches)
      let html = '<div style="display:flex;justify-content:space-between;align-items:baseline;">' +
                 '<div><strong>فاتورة مشتريات — #' + escapeHtml(inv.id) + '</strong>' +
                 '<div style="font-size:0.85rem;color:#666;margin-top:4px;">' + escapeHtml(inv.purchase_date || inv.created_at || '') + '</div>' +
                 '</div>' +
                 '<div>' + (inv.status === 'fully_received' ? '<span class="badge-received">مستلمة</span>' :
                            (inv.status === 'cancelled' ? '<span class="badge-cancelled">ملغاة</span>' : '<span class="badge-pending">مؤجلة</span>')) +
                 '</div></div>';

      html += '<div style="margin-top:12px;"><div><strong>المورد:</strong> ' + escapeHtml(inv.supplier_name || '') + '</div>' +
              '<div><strong>رقم المورد:</strong> ' + escapeHtml(inv.supplier_invoice_number || '') + '</div>' +
              '<div><strong>الإجمالي:</strong> ' + Number(inv.total_amount || 0).toFixed(2) + ' ج.م</div></div>';

      // notes
      html += '<div style="margin-top:12px;"><h4>ملاحظات</h4><pre style="white-space:pre-wrap;background:#f8fafc;padding:10px;border-radius:6px;">' + escapeHtml(inv.notes || '-') + '</pre></div>';

      // items table
      html += '<div style="margin-top:12px;border:1px solid rgba(0,0,0,0.06);padding:8px;"><table style="width:100%;border-collapse:collapse;"><thead style="font-weight:700;background:rgba(0,0,0,0.03);"><tr>' +
              '<th>#</th><th style="text-align:right">اسم</th><th>كمية</th><th style="text-align:right">سعر شراء</th><th style="text-align:right">سعر بيع</th><th>مستلم</th><th style="text-align:right">إجمالي</th></tr></thead><tbody>';
      let total = 0;
      if (items.length) {
        items.forEach((it, idx) => {
          const line = Number(it.total_cost || (it.quantity * it.cost_price_per_unit) || 0).toFixed(2);
          total += parseFloat(line);
          html += '<tr>' +
                  '<td>' + (idx+1) + '</td>' +
                  '<td style="text-align:right">' + escapeHtml(it.product_name ? (it.product_name + (it.product_code ? ' — ' + it.product_code : '')) : ('#' + it.product_id)) + '</td>' +
                  '<td style="text-align:center">' + Number(it.quantity || 0).toFixed(2) + '</td>' +
                  '<td style="text-align:right">' + Number(it.cost_price_per_unit || 0).toFixed(2) + '</td>' +
                  '<td style="text-align:right">' + ((it.sale_price !== undefined && it.sale_price !== null) ? Number(it.sale_price).toFixed(2) + ' ج.م' : (it.selling_price ? Number(it.selling_price).toFixed(2)+' ج.م' : '-')) + '</td>' +
                  '<td style="text-align:center">' + Number(it.qty_received || 0).toFixed(2) + '</td>' +
                  '<td style="text-align:right;font-weight:700">' + line + ' ج.م</td>' +
                  '</tr>';
        });
      } else {
        html += '<tr><td colspan="7" style="text-align:center">لا توجد بنود</td></tr>';
      }
      html += '</tbody><tfoot><tr><td colspan="6" style="text-align:right;font-weight:700">الإجمالي الكلي</td><td style="text-align:right;font-weight:800">' + total.toFixed(2) + ' ج.م</td></tr></tfoot></table></div>';

      // batches section
      html += '<div style="margin-top:12px;"><h4>الدفعات المرتبطة</h4>';
      if (Array.isArray(batches) && batches.length) {
        html += '<table style="width:100%;border-collapse:collapse;"><thead style="font-weight:700;background:rgba(0,0,0,0.03)"><tr>' +
                '<th>دفعة</th><th>منتج</th><th>كمية</th><th>متبقي</th><th>سعر شراء</th><th>سعر بيع (batch)</th><th>الحالة</th><th>سبب الإرجاع</th><th>سبب الإلغاء</th></tr></thead><tbody>';
        batches.forEach(b => {
          html += '<tr>' +
                  '<td>' + escapeHtml(String(b.id)) + '</td>' +
                  '<td>' + (b.product_id ? escapeHtml(String(b.product_id)) : '-') + '</td>' +
                  '<td>' + Number(b.qty||0).toFixed(2) + '</td>' +
                  '<td>' + Number(b.remaining||0).toFixed(2) + '</td>' +
                  '<td>' + (b.unit_cost !== null ? Number(b.unit_cost).toFixed(2) + ' ج.م' : '-') + '</td>' +
                  '<td>' + (b.sale_price !== null && b.sale_price !== undefined ? Number(b.sale_price).toFixed(2) + ' ج.م' : '-') + '</td>' +
                  '<td>' + escapeHtml(b.status || '') + '</td>' +
                  '<td>' + escapeHtml(b.revert_reason || '-') + '</td>' +
                  '<td>' + escapeHtml(b.cancel_reason || '-') + '</td>' +
                  '</tr>';
        });
        html += '</tbody></table>';
      } else {
        html += '<div class="small-muted">لا توجد دفعات مرتبطة بهذه الفاتورة.</div>';
      }
      html += '</div>';

      // actions (edit / close) - if content has footer area then you may want to include buttons there.
      if (content) {
        content.innerHTML = html;
        // attach optional edit button in content if can_edit
        if (data.can_edit) {
          const btn = el('button', { type: 'button' });
          btn.className = 'btn btn-primary';
          btn.style.marginTop = '12px';
          btn.innerText = 'تعديل بنود الفاتورة';
          btn.addEventListener('click', function(){
            // close current view modal and open edit modal
            if (backdrop) hideModal(backdrop);
            openInvoiceModalEdit(id);
          });
          content.appendChild(btn);
        }
        // add a close button at end
        const closeBtn = el('button'); closeBtn.className = 'btn btn-secondary'; closeBtn.style.marginLeft='8px';
        closeBtn.innerText = 'إغلاق'; closeBtn.addEventListener('click', function(){ if (backdrop) hideModal(backdrop); });
        content.appendChild(closeBtn);
      }

    } catch (err) {
      console.error(err);
      if (content) content.innerHTML = '<div class="alert alert-danger" style="padding:12px">فشل الاتصال بالخادم.</div>';
    }
  }

  // ------------------ Edit Invoice Modal ------------------
  async function openInvoiceModalEdit(id){
    if (!id) return;
    // prefer edit modal backdrop or editInvoiceModal element
    let backdrop = document.getElementById('editModalBackdrop') || document.getElementById('editInvoiceModal');
    let body = document.getElementById('editInvoiceBody') || (backdrop ? backdrop.querySelector('.modal-body') : null);
    // fallback: new modal id from assistant earlier: editInvoiceModal
    if (!backdrop) backdrop = document.getElementById('editInvoiceModal') || null;
    if (!body && backdrop) body = backdrop.querySelector('.modal-body') || backdrop.querySelector('#edit_items_table') || null;

    if (!backdrop || !body) {
      console.warn('edit modal elements not found. Ensure #editModalBackdrop or #editInvoiceModal and inner body exist.');
    }

    // set hidden invoice id if form exists
    const editForm = document.getElementById('editInvoiceForm') || document.getElementById('editInvoiceForm') /* fallback */;
    if (editForm) {
      const hid = editForm.querySelector('input[name="invoice_id"]') || (function(){
        const i = el('input'); i.type='hidden'; i.name='invoice_id'; editForm.appendChild(i); return i;
      })();
      hid.value = id;
    }

    // show loading
    if (backdrop) showModal(backdrop);
    if (body) body.innerHTML = '<div style="padding:12px">جارٍ التحميل...</div>';

    try {
      const res = await fetch(ajaxUrl + '?action=fetch_invoice_json&id=' + encodeURIComponent(id), { credentials: 'same-origin' });
      const data = await res.json();
      if (!data || !data.ok) {
        if (body) body.innerHTML = '<div class="alert alert-danger">فشل جلب الفاتورة: ' + escapeHtml(data.msg||'') + '</div>';
        return;
      }
      if (!data.can_edit) {
        if (body) body.innerHTML = '<div class="alert alert-warning">لا يمكن التعديل لأن الدُفعات مستهلكة أو الحالة لا تسمح.</div>';
        return;
      }

      const items = data.items || [];

      // build editable table (quantity, cost, selling) - maintain old method inputs classes for compatibility
      let html = '<table class="table table-sm" style="width:100%;border-collapse:collapse;"><thead><tr>' +
                 '<th>#</th><th>المنتج</th><th>كمية حالية</th><th>كمية جديدة</th><th>سعر شراء حالي</th><th>سعر شراء جديد</th><th>سعر بيع حالي</th><th>سعر بيع جديد</th></tr></thead><tbody>';
      items.forEach((it, idx) => {
        const curQty = Number(it.quantity||0).toFixed(2);
        const curCost = Number(it.cost_price_per_unit||0).toFixed(2);
        // item may optionally have sale_price or selling_price
        const curSale = (it.sale_price !== undefined && it.sale_price !== null) ? Number(it.sale_price).toFixed(2) : ((it.selling_price !== undefined) ? Number(it.selling_price).toFixed(2) : '');
        html += '<tr>' +
                '<td>' + (idx+1) + '</td>' +
                '<td>' + escapeHtml(it.product_name ? (it.product_name + (it.product_code ? ' — ' + it.product_code : '')) : ('#' + it.product_id)) + '</td>' +
                '<td>' + curQty + '</td>' +
                '<td><input class="form-control edit-item-qty" data-item-id="' + (it.id || '') + '" type="number" step="0.01" value="' + curQty + '"></td>' +
                '<td>' + curCost + '</td>' +
                '<td><input class="form-control edit-item-cost" data-item-id="' + (it.id || '') + '" type="number" step="0.01" value="' + curCost + '"></td>' +
                '<td>' + (curSale !== '' ? (curSale + ' ج.م') : '-') + '</td>' +
                '<td><input class="form-control edit-item-sale" data-item-id="' + (it.id || '') + '" type="number" step="0.01" value="' + (curSale !== '' ? curSale : '') + '"></td>' +
                '</tr>';
      });
      html += '</tbody></table>';

      // add textarea for reason and action buttons if not present in DOM
      html += '<div style="margin-top:8px;"><label><strong>سبب التعديل (مطلوب)</strong></label><textarea id="js_adjust_reason" rows="3" style="width:100%;"></textarea></div>';
      html += '<div style="margin-top:10px;"><button id="js_save_edit_btn" class="btn btn-success">حفظ</button> <button id="js_cancel_edit_btn" class="btn btn-secondary">إلغاء</button></div>';

      if (body) body.innerHTML = html;

      // wire buttons
      const saveBtn = q('#js_save_edit_btn', body);
      const cancelBtn = q('#js_cancel_edit_btn', body);
      if (cancelBtn) cancelBtn.addEventListener('click', function(){ if (backdrop) hideModal(backdrop); });

      if (saveBtn) saveBtn.addEventListener('click', function(){
        // collect payload similar to old approach (items_json)
        const inputsQty = qa('.edit-item-qty', body);
        const inputsCost = qa('.edit-item-cost', body);
        const inputsSale = qa('.edit-item-sale', body);
        const itemsPayload = [];

        // map by data-item-id
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
          // empty string -> null
          mapById[idattr].new_sale_price = (i.value === '') ? null : parseFloat(i.value || 0);
        });

        for (const key in mapById) {
          const obj = mapById[key];
          obj.item_id = parseInt(key, 10);
          itemsPayload.push(obj);
        }

        const adjustReason = (q('#js_adjust_reason') ? q('#js_adjust_reason').value.trim() : '');
        if (!adjustReason) {
          alert('الرجاء إدخال سبب التعديل'); q('#js_adjust_reason').focus(); return;
        }
        if (!itemsPayload.length) {
          alert('لا توجد بنود صالحة للتعديل'); return;
        }

        // create hidden form and submit (to let server redirect and set session messages)
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = ajaxUrl;
        form.style.display = 'none';
        // required server-side keys
        const f1 = el('input'); f1.type='hidden'; f1.name='edit_invoice'; f1.value='1'; form.appendChild(f1);
        const f2 = el('input'); f2.type='hidden'; f2.name='invoice_id'; f2.value = id; form.appendChild(f2);
        const f3 = el('input'); f3.type='hidden'; f3.name='items_json'; f3.value = JSON.stringify(itemsPayload); form.appendChild(f3);
        const f4 = el('input'); f4.type='hidden'; f4.name='adjust_reason'; f4.value = adjustReason; form.appendChild(f4);
        const f5 = el('input'); f5.type='hidden'; f5.name='csrf_token'; f5.value = CSRF_TOKEN; form.appendChild(f5);
        document.body.appendChild(form);
        form.submit();
      });

    } catch (err) {
      console.error(err);
      if (body) body.innerHTML = '<div class="alert alert-danger">فشل الاتصال بالخادم.</div>';
    }
  }

  // ------------------ Reason Modal (revert / cancel) ------------------
  function openReasonModal(action, invoiceId){
    // action: 'revert' or 'cancel'
    // find reason form element (old name reasonForm) or form#reasonForm
    const backdrop = document.getElementById('reasonModalBackdrop') || document.getElementById('reasonModal') || null;
    const form = document.getElementById('reasonForm') || document.querySelector('form[name="reasonForm"]') || null;
    if (!form) {
      // fallback: try to create a small prompt
      const reason = prompt(action === 'revert' ? 'ادخل سبب الإرجاع:' : 'ادخل سبب الإلغاء:');
      if (!reason) return alert('العملية أُلغيت');
      // create and submit a tiny form
      const f = document.createElement('form'); f.method='POST'; f.action = ajaxUrl; f.style.display='none';
      if (action === 'revert') {
        const in1 = el('input'); in1.type='hidden'; in1.name='change_invoice_status'; in1.value='1'; f.appendChild(in1);
        const in2 = el('input'); in2.type='hidden'; in2.name='new_status'; in2.value='pending'; f.appendChild(in2);
      } else {
        const in1 = el('input'); in1.type='hidden'; in1.name='cancel_purchase_invoice'; in1.value='1'; f.appendChild(in1);
      }
      const iinv = el('input'); iinv.type='hidden'; iinv.name='purchase_invoice_id'; iinv.value = invoiceId; f.appendChild(iinv);
      const ireason = el('input'); ireason.type='hidden'; ireason.name='reason'; ireason.value = reason; f.appendChild(ireason);
      const icsrf = el('input'); icsrf.type='hidden'; icsrf.name='csrf_token'; icsrf.value = CSRF_TOKEN; f.appendChild(icsrf);
      document.body.appendChild(f);
      f.submit();
      return;
    }

    // if form available, populate fields and show modal
    // set invoice id
    let invInput = form.querySelector('input[name="purchase_invoice_id"]');
    if (!invInput) { invInput = el('input'); invInput.type='hidden'; invInput.name='purchase_invoice_id'; form.appendChild(invInput); }
    invInput.value = invoiceId;

    // remove prior markers
    const prevChange = form.querySelector('input[name="change_invoice_status"]');
    if (prevChange) prevChange.remove();
    const prevCancel = form.querySelector('input[name="cancel_purchase_invoice"]');
    if (prevCancel) prevCancel.remove();
    const prevNewStatus = form.querySelector('input[name="new_status"]');
    if (prevNewStatus) prevNewStatus.remove();

    if (action === 'revert') {
      const m = el('input'); m.type='hidden'; m.name='change_invoice_status'; m.value='1'; form.appendChild(m);
      const n = el('input'); n.type='hidden'; n.name='new_status'; n.value='pending'; form.appendChild(n);
    } else {
      const m = el('input'); m.type='hidden'; m.name='cancel_purchase_invoice'; m.value='1'; form.appendChild(m);
    }

    // ensure reason field exists
    let reasonField = form.querySelector('textarea[name="reason"]') || form.querySelector('input[name="reason"]');
    if (!reasonField) {
      // create textarea
      reasonField = el('textarea'); reasonField.name = 'reason'; reasonField.rows = 3;
      form.appendChild(reasonField);
    } else {
      reasonField.value = '';
    }

    // add csrf if missing
    let csrfInput = form.querySelector('input[name="csrf_token"]');
    if (!csrfInput) { csrfInput = el('input'); csrfInput.type='hidden'; csrfInput.name='csrf_token'; csrfInput.value = CSRF_TOKEN; form.appendChild(csrfInput); }

    // show modal if backdrop exists
    if (backdrop) showModal(backdrop);
    // when submitting, server will handle and redirect
    form.onsubmit = function(){ /* default submit */ };
  }

  // ---------- click outside to close (for backdrops if present) ----------
  ['invoiceModalBackdrop','editModalBackdrop','reasonModalBackdrop','invoiceModal','editInvoiceModal','reasonModal'].forEach(id => {
    const elBackdrop = document.getElementById(id);
    if (!elBackdrop) return;
    elBackdrop.addEventListener('click', function(e){ if (e.target === elBackdrop) elBackdrop.style.display='none'; });
  });

  // expose functions globally for inline buttons/links
  window.openInvoiceModalView = openInvoiceModalView;
  window.openInvoiceModalEdit = openInvoiceModalEdit;
  window.openReasonModal = openReasonModal;

  // auto-wire any .open-invoice (data-id) anchors/buttons to view modal (like old script)
  qa('.open-invoice').forEach(btn => {
    btn.addEventListener('click', function(e){
      const id = btn.dataset.id || btn.getAttribute('data-id');
      if (!id) return;
      openInvoiceModalView(id);
    });
  });

  // auto-wire any .edit-invoice (data-id)
  qa('.edit-invoice').forEach(btn => {
    btn.addEventListener('click', function(e){
      const id = btn.dataset.id || btn.getAttribute('data-id');
      if (!id) return;
      openInvoiceModalEdit(id);
    });
  });

  // auto-wire any .revert-invoice or .cancel-invoice buttons to reason modal
  qa('.revert-invoice').forEach(b => b.addEventListener('click', ()=> openReasonModal('revert', b.dataset.id || b.getAttribute('data-id'))));
  qa('.cancel-invoice').forEach(b => b.addEventListener('click', ()=> openReasonModal('cancel', b.dataset.id || b.getAttribute('data-id'))));

})();
</script>

<?php
require_once BASE_DIR . 'partials/footer.php';
$conn->close();
?>
