<?php
// manage_purchase_invoices.php
// نسخة مُصحّحة من الملف الذي أرسلتَه — إصلاح bind_param وAJAX endpoints والمودالات.
// ** قبل التشغيل **: خذ نسخة احتياطية من الملف الحالي وقاعدة البيانات.

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

// ---------- AJAX endpoint: جلب بيانات الفاتورة كـ JSON (للمودال) ----------
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

    // can_edit / can_revert logic: pending => yes; fully_received => yes only if batches unconsumed
    $can_edit = false; $can_revert = false;
    if ($inv['status'] === 'pending') {
        $can_edit = true;
    } elseif ($inv['status'] === 'fully_received') {
        $all_ok = true;
        $sql_b = "SELECT id, qty, remaining, original_qty, status FROM batches WHERE source_invoice_id = ?";
        if ($stb = $conn->prepare($sql_b)) {
            $stb->bind_param("i", $inv_id);
            $stb->execute();
            $rb = $stb->get_result();
            while ($bb = $rb->fetch_assoc()) {
                if (((float)$bb['remaining']) < ((float)$bb['original_qty']) || $bb['status'] !== 'active') {
                    $all_ok = false; break;
                }
            }
            $stb->close();
        } else {
            $all_ok = false;
        }
        $can_edit = $all_ok;
        $can_revert = $all_ok;
    }

    echo json_encode(['ok'=>true,'invoice'=>$inv,'items'=>$items,'can_edit'=>$can_edit,'can_revert'=>$can_revert,'status_labels'=>$status_labels], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------- POST handlers ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['message'] = "<div class='alert alert-danger'>خطأ: طلب غير صالح (CSRF).</div>";
        header("Location: " . basename(__FILE__)); exit;
    }

    // ----- RECEIVE (fully) -----
    if (isset($_POST['receive_purchase_invoice'])) {
        $invoice_id = intval($_POST['purchase_invoice_id'] ?? 0);
        if ($invoice_id <= 0) { $_SESSION['message'] = "<div class='alert alert-danger'>معرف غير صالح.</div>"; header("Location: ".basename(__FILE__)); exit; }

        $conn->begin_transaction();
        try {
            // lock invoice
            $st = $conn->prepare("SELECT status FROM purchase_invoices WHERE id = ? FOR UPDATE");
            $st->bind_param("i", $invoice_id); $st->execute(); $invrow = $st->get_result()->fetch_assoc(); $st->close();
            if (!$invrow) throw new Exception("الفاتورة غير موجودة");
            if ($invrow['status'] === 'fully_received') throw new Exception("الفاتورة مُسلمة بالفعل");
            if ($invrow['status'] === 'cancelled') throw new Exception("الفاتورة ملغاة");

            // ensure no partial received
            $sti = $conn->prepare("SELECT id, qty_received FROM purchase_invoice_items WHERE purchase_invoice_id = ? FOR UPDATE");
            $sti->bind_param("i",$invoice_id); $sti->execute(); $resi = $sti->get_result();
            while ($r = $resi->fetch_assoc()) {
                if ((float)($r['qty_received'] ?? 0) > 0) throw new Exception("تم استلام جزء من هذه الفاتورة سابقًا — لا يوجد دعم للاستلام الجزئي هنا.");
            }
            $sti->close();

            // fetch items to insert batches
            $stii = $conn->prepare("SELECT id, product_id, quantity, cost_price_per_unit FROM purchase_invoice_items WHERE purchase_invoice_id = ?");
            $stii->bind_param("i",$invoice_id); $stii->execute(); $rit = $stii->get_result();

            $stmt_update_product = $conn->prepare("UPDATE products SET current_stock = current_stock + ? WHERE id = ?");
            $stmt_insert_batch = $conn->prepare("INSERT INTO batches (product_id, qty, remaining, original_qty, unit_cost, received_at, source_invoice_id, source_item_id, status, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, NOW(), NOW())");
            $stmt_update_item = $conn->prepare("UPDATE purchase_invoice_items SET qty_received = ? WHERE id = ?");

            if (!$stmt_update_product || !$stmt_insert_batch || !$stmt_update_item) {
                throw new Exception("فشل تحضير استعلامات داخليّة: " . $conn->error);
            }

            while ($it = $rit->fetch_assoc()) {
                $item_id = intval($it['id']);
                $product_id = intval($it['product_id']);
                $qty = (float)$it['quantity'];
                $unit_cost = (float)$it['cost_price_per_unit'];
                if ($qty <= 0) continue;

                // update product stock
                $u_qty = $qty; $u_pid = $product_id;
                if (!$stmt_update_product->bind_param("di", $u_qty, $u_pid) || !$stmt_update_product->execute()) {
                    throw new Exception('فشل تحديث المنتج: ' . $stmt_update_product->error);
                }

                // insert batch
                $b_product_id = $product_id;
                $b_qty = $qty;
                $b_remaining = $qty;
                $b_original = $qty;
                $b_unit_cost = $unit_cost;
                $b_received_at = date('Y-m-d');
                $b_source_invoice_id = $invoice_id;
                $b_source_item_id = $item_id;
                $b_created_by = $_SESSION['user_id'] ?? null;

                // types: i d d d d s i i i => "iddddsiii"
                if (!$stmt_insert_batch->bind_param("iddddsiii", $b_product_id, $b_qty, $b_remaining, $b_original, $b_unit_cost, $b_received_at, $b_source_invoice_id, $b_source_item_id, $b_created_by)) {
                    throw new Exception('فشل ربط بيانات إدخال الدفعة: ' . $stmt_insert_batch->error);
                }
                if (!$stmt_insert_batch->execute()) {
                    throw new Exception('فشل إدخال الدفعة: ' . $stmt_insert_batch->error);
                }

                // update item qty_received
                $u_qty_received = $qty; $u_item_id = $item_id;
                if (!$stmt_update_item->bind_param("di", $u_qty_received, $u_item_id) || !$stmt_update_item->execute()) {
                    throw new Exception('فشل تحديث بند الفاتورة: ' . $stmt_update_item->error);
                }
            }

            // update invoice status to fully_received
            $stup = $conn->prepare("UPDATE purchase_invoices SET status = 'fully_received', updated_by = ?, updated_at = NOW() WHERE id = ?");
            $upd_by = $_SESSION['user_id'] ?? null;
            $stup->bind_param("ii", $upd_by, $invoice_id);
            if (!$stup->execute()) throw new Exception('فشل تحديث حالة الفاتورة: ' . $stup->error);
            $stup->close();

            $conn->commit();
            $_SESSION['message'] = "<div class='alert alert-success'>تم استلام الفاتورة وإنشاء الدُفعات وتحديث المخزون بنجاح.</div>";
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

                if (!$upd_prod->bind_param("di", $qty, $pid) || !$upd_prod->execute()) {
                    throw new Exception("فشل تحديث رصيد المنتج أثناء التراجع: " . $upd_prod->error);
                }
                if (!$upd_batch->bind_param("si", $reason, $bid) || !$upd_batch->execute()) {
                    throw new Exception("فشل تحديث الدفعة أثناء التراجع: " . $upd_batch->error);
                }
            }

            // reset items qty_received
            $rst = $conn->prepare("UPDATE purchase_invoice_items SET qty_received = 0 WHERE purchase_invoice_id = ?");
            $rst->bind_param("i", $invoice_id); $rst->execute(); $rst->close();

            // update invoice
            $u = $conn->prepare("UPDATE purchase_invoices SET status = 'pending', revert_reason = ?, updated_by = ?, updated_at = NOW() WHERE id = ?");
            $u_by = $_SESSION['user_id'] ?? null;
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
            $upd_by = $_SESSION['user_id'] ?? null;
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
            $st = $conn->prepare("SELECT status FROM purchase_invoices WHERE id = ? FOR UPDATE");
            $st->bind_param("i",$invoice_id); $st->execute(); $inv = $st->get_result()->fetch_assoc(); $st->close();
            if (!$inv) throw new Exception("الفاتورة غير موجودة");

            foreach ($items_data as $it) {
                $item_id = intval($it['item_id'] ?? 0);
                $new_qty = (float)($it['new_quantity'] ?? 0);
                if ($item_id <= 0) continue;
                // lock item
                $sti = $conn->prepare("SELECT id, purchase_invoice_id, product_id, quantity, qty_received FROM purchase_invoice_items WHERE id = ? FOR UPDATE");
                $sti->bind_param("i", $item_id); $sti->execute(); $row = $sti->get_result()->fetch_assoc(); $sti->close();
                if (!$row) throw new Exception("بند غير موجود: #$item_id");
                $old_qty = (float)$row['quantity']; $prod_id = intval($row['product_id']);

                if ($inv['status'] === 'pending') {
                    $diff = $new_qty - $old_qty;
                    $upit = $conn->prepare("UPDATE purchase_invoice_items SET quantity = ?, qty_adjusted = ?, adjustment_reason = ?, adjusted_by = ?, adjusted_at = NOW() WHERE id = ?");
                    $qty_adj = $diff;
                    $upit->bind_param("dssii", $new_qty, (string)$qty_adj, $adjust_reason, $_SESSION['user_id'] ?? null, $item_id);
                    if (!$upit->execute()) { $upit->close(); throw new Exception("فشل تعديل البند: " . $upit->error); }
                    $upit->close();
                    continue;
                }

                if ($inv['status'] === 'fully_received') {
                    // find batch
                    $stb = $conn->prepare("SELECT id, qty, remaining, original_qty FROM batches WHERE source_item_id = ? FOR UPDATE");
                    $stb->bind_param("i", $item_id); $stb->execute(); $batch = $stb->get_result()->fetch_assoc(); $stb->close();
                    if (!$batch) throw new Exception("لا توجد دفعة مرتبطة بالبند #$item_id");
                    if (((float)$batch['remaining']) < ((float)$batch['original_qty'])) throw new Exception("لا يمكن تعديل هذا البند لأن الدفعة المرتبطة به قد اُستهلكت.");

                    $diff = $new_qty - $old_qty;
                    $upit = $conn->prepare("UPDATE purchase_invoice_items SET quantity = ?, qty_adjusted = ?, adjustment_reason = ?, adjusted_by = ?, adjusted_at = NOW() WHERE id = ?");
                    $qty_adj = $diff;
                    $upit->bind_param("dssii", $new_qty, (string)$qty_adj, $adjust_reason, $_SESSION['user_id'] ?? null, $item_id);
                    if (!$upit->execute()) { $upit->close(); throw new Exception("فشل تعديل البند: " . $upit->error); }
                    $upit->close();

                    // update batch
                    $new_batch_qty = (float)$batch['qty'] + $diff;
                    $new_remaining = (float)$batch['remaining'] + $diff;
                    $new_original = (float)$batch['original_qty'] + $diff;
                    if ($new_remaining < 0) throw new Exception("التعديل يؤدي إلى قيمة متبقية سلبية");

                    $upb = $conn->prepare("UPDATE batches SET qty = ?, remaining = ?, original_qty = ?, adjusted_by = ?, adjusted_at = NOW() WHERE id = ?");
                    $upb->bind_param("ddiii", $new_batch_qty, $new_remaining, $new_original, $_SESSION['user_id'] ?? null, $batch['id']);
                    if (!$upb->execute()) { $upb->close(); throw new Exception("فشل تحديث الدفعة: " . $upb->error); }
                    $upb->close();

                    // update product stock
                    $upprod = $conn->prepare("UPDATE products SET current_stock = current_stock + ? WHERE id = ?");
                    $upprod->bind_param("di", $diff, $prod_id);
                    if (!$upprod->execute()) { $upprod->close(); throw new Exception("فشل تحديث المخزون: " . $upprod->error); }
                    $upprod->close();
                    continue;
                }

                throw new Exception("لا يمكن التعديل في الحالة الحالية");
            }

            // recalc invoice total
            $sttot = $conn->prepare("SELECT COALESCE(SUM(quantity * cost_price_per_unit),0) AS total FROM purchase_invoice_items WHERE purchase_invoice_id = ?");
            $sttot->bind_param("i", $invoice_id); $sttot->execute(); $rt = $sttot->get_result()->fetch_assoc(); $sttot->close();
            $new_total = (float)($rt['total'] ?? 0.0);
            $upinv = $conn->prepare("UPDATE purchase_invoices SET total_amount = ?, updated_by = ?, updated_at = NOW() WHERE id = ?");
            $upinv->bind_param("dii", $new_total, $_SESSION['user_id'] ?? null, $invoice_id); $upinv->execute(); $upinv->close();

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
    if (!empty($params)) $stmt_select->bind_param($types, ...$params);
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

<!-- VIEW modal (read-only) -->
<div id="invoiceModalBackdrop" class="modal-backdrop-custom"><div class="modal-card-custom" id="invoiceModalCard"><div id="invoiceModalContent" style="min-height:120px;padding:6px;">جارٍ التحميل...</div><div style="text-align:left;margin-top:12px"><button onclick="document.getElementById('invoiceModalBackdrop').style.display='none';" class="btn btn-outline-secondary btn-sm">إغلاق</button></div></div></div>

<!-- EDIT modal -->
<div id="editModalBackdrop" class="modal-backdrop-custom"><div class="modal-card-custom"><form id="editInvoiceForm" method="post"><input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>"><input type="hidden" name="edit_invoice" value="1"><input type="hidden" name="invoice_id" id="edit_invoice_id" value=""><div id="editInvoiceBody" style="min-height:120px;padding:6px;">جارٍ التحميل...</div><div style="margin-top:10px"><label>سبب التعديل:</label><input type="text" id="adjust_reason" name="adjust_reason" class="form-control"></div><div style="margin-top:10px;text-align:left"><button type="submit" class="btn btn-primary">حفظ التعديلات</button> <button type="button" onclick="document.getElementById('editModalBackdrop').style.display='none';" class="btn btn-outline-secondary">إغلاق</button></div></form></div></div>

<!-- Reason modal -->
<div id="reasonModalBackdrop" class="modal-backdrop-custom"><div class="modal-card-custom"><form id="reasonForm" method="post"><input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>"><input type="hidden" name="purchase_invoice_id" id="reason_invoice_id" value=""><input type="hidden" name="new_status" id="reason_new_status" value=""><div style="margin-bottom:8px"><label>السبب (مطلوب)</label><textarea name="reason" id="reason_text" class="form-control" rows="4" required></textarea></div><div style="text-align:left;"><button type="submit" class="btn btn-primary">تأكيد</button> <button type="button" onclick="document.getElementById('reasonModalBackdrop').style.display='none';" class="btn btn-outline-secondary">إلغاء</button></div></form></div></div>

<script>
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
</script>

<?php
require_once BASE_DIR . 'partials/footer.php';
$conn->close();
?>
