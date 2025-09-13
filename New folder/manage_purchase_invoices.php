<?php
// manage_purchase_invoices.redesign.php
// إعادة تصميم صفحة إدارة فواتير المشتريات - UI محسن + backend آمن
// ملاحظات: شغّل فقط على بيئة اختبارية أولًا، احفظ نسخة احتياطية من DB.

$page_title = "إدارة فواتير المشتريات";
require_once dirname(__DIR__) . '/config.php';
require_once BASE_DIR . 'partials/session_admin.php'; // صلاحيات المدير فقط (مفترض موجود)

// --- إعدادات وتهيئة ---
$message = "";
$selected_supplier_id = "";
$selected_status = "";
$result_invoices = null;
$grand_total_all_purchases = 0;
$displayed_invoices_sum = 0;
$suppliers_list = [];

$status_labels = [
    'pending' => 'قيد الانتظار',
    'partial_received' => 'تم الاستلام جزئياً',
    'fully_received' => 'تم الاستلام بالكامل',
    'cancelled' => 'ملغاة'
];

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Helpers
function is_ajax_request() {
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

// --- POST HANDLERS (AJAX-aware) ---
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // common CSRF check for POST actions (AJAX will include csrf_token too)
    $csrf_ok = isset($_POST['csrf_token']) && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '');

    // ---------- 1) Edit invoice item (AJAX) - only for pending invoices and qty_received==0 ----------
    if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'edit_invoice_item') {
        header('Content-Type: application/json; charset=utf-8');
        if (!$csrf_ok) {
            echo json_encode(['success'=>false,'message'=>'خطأ: طلب غير صالح (CSRF).']); exit;
        }
        $item_id = intval($_POST['item_id'] ?? 0);
        $new_qty = floatval($_POST['quantity'] ?? 0);
        $new_cost = floatval($_POST['unit_cost'] ?? 0);
        if ($item_id <= 0 || $new_qty <= 0 || $new_cost < 0) {
            echo json_encode(['success'=>false,'message'=>'بيانات غير صحيحة.']); exit;
        }

        // check item & invoice status & qty_received
        $sql = "SELECT pii.purchase_invoice_id, pii.qty_received, pi.status
                FROM purchase_invoice_items pii
                JOIN purchase_invoices pi ON pii.purchase_invoice_id = pi.id
                WHERE pii.id = ? LIMIT 1";
        if ($st = $conn->prepare($sql)) {
            $st->bind_param("i", $item_id);
            $st->execute();
            $res = $st->get_result()->fetch_assoc() ?? null;
            $st->close();
            if (!$res) { echo json_encode(['success'=>false,'message'=>'البند غير موجود.']); exit; }
            if ($res['status'] !== 'pending') {
                echo json_encode(['success'=>false,'message'=>'لا يمكن تعديل هذا البند لأن الفاتورة ليست قيد الانتظار.']); exit;
            }
            if (floatval($res['qty_received']) > 0) {
                echo json_encode(['success'=>false,'message'=>'لا يمكن تعديل هذا البند لأن كمية منه قد استُلمت.']); exit;
            }

            // perform update
            $sql_up = "UPDATE purchase_invoice_items SET quantity = ?, cost_price_per_unit = ? WHERE id = ?";
            if ($st2 = $conn->prepare($sql_up)) {
                $st2->bind_param("ddi", $new_qty, $new_cost, $item_id);
                if ($st2->execute()) {
                    echo json_encode(['success'=>true,'message'=>'تم حفظ التعديلات على البند.']); exit;
                } else {
                    error_log("Edit invoice item error: " . $st2->error);
                    echo json_encode(['success'=>false,'message'=>'فشل حفظ التعديلات.']); exit;
                }
            } else {
                echo json_encode(['success'=>false,'message'=>'خطأ داخلي أثناء التحديث.']); exit;
            }
        } else {
            echo json_encode(['success'=>false,'message'=>'خطأ داخلي أثناء التحقق.']); exit;
        }
    }

    // ---------- 2) Receive invoice (can be AJAX or normal POST) ----------
    if (isset($_POST['receive_purchase_invoice'])) {
        // allow AJAX or normal POST
        if (!$csrf_ok) {
            if (is_ajax_request()) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['success'=>false,'message'=>'CSRF']); exit; }
            $_SESSION['message'] = "<div class='alert alert-danger'>خطأ: طلب غير صالح (CSRF).</div>";
            header("Location: manage_purchase_invoices.php"); exit;
        }

        $invoice_id = intval($_POST['purchase_invoice_id'] ?? 0);
        if ($invoice_id <= 0) {
            if (is_ajax_request()) { header('Content-Type: application/json'); echo json_encode(['success'=>false,'message'=>'بيانات غير صحيحة.']); exit; }
            $_SESSION['message'] = "<div class='alert alert-danger'>بيانات غير صحيحة لاستلام الفاتورة.</div>";
            header("Location: manage_purchase_invoices.php"); exit;
        }

        // Begin transaction
        $conn->begin_transaction();
        try {
            // lock invoice row
            $sql_check = "SELECT status FROM purchase_invoices WHERE id = ? FOR UPDATE";
            if (!$stmtc = $conn->prepare($sql_check)) throw new Exception('DB error: ' . $conn->error);
            $stmtc->bind_param("i", $invoice_id);
            $stmtc->execute();
            $rc = $stmtc->get_result()->fetch_assoc();
            $stmtc->close();
            if (!$rc) throw new Exception('الفاتورة غير موجودة.');
            if ($rc['status'] === 'fully_received') throw new Exception('الفاتورة مُسلمة بالفعل.');
            if ($rc['status'] === 'cancelled') throw new Exception('لا يمكن استلام فاتورة ملغاة.');

            // fetch items
            $sql_items = "SELECT id, product_id, quantity, cost_price_per_unit FROM purchase_invoice_items WHERE purchase_invoice_id = ?";
            if (!$stmt_items = $conn->prepare($sql_items)) throw new Exception('تحضير بنود الفاتورة فشل.');
            $stmt_items->bind_param("i", $invoice_id);
            $stmt_items->execute();
            $res_items = $stmt_items->get_result();

            // prepare statements
            $sql_sel_prod = "SELECT current_stock FROM products WHERE id = ? FOR UPDATE";
            $stmt_sel_prod = $conn->prepare($sql_sel_prod);
            if (!$stmt_sel_prod) throw new Exception('تحضير استعلام قفل المنتج فشل.');

            $sql_update_product = "UPDATE products SET current_stock = ?, cost_price = ? WHERE id = ?";
            $stmt_up_prod = $conn->prepare($sql_update_product);
            if (!$stmt_up_prod) throw new Exception('تحضير استعلام تحديث المنتج فشل.');

            $sql_insert_batch = "INSERT INTO batches (product_id, qty, remaining, original_qty, unit_cost, received_at, source_invoice_id, source_item_id, status, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, NOW(), NOW())";
            $stmt_ins_batch = $conn->prepare($sql_insert_batch);
            if (!$stmt_ins_batch) throw new Exception('تحضير استعلام إدخال الدفعة فشل.');

            $sql_update_item = "UPDATE purchase_invoice_items SET qty_received = ?, batch_id = ? WHERE id = ?";
            $stmt_up_item = $conn->prepare($sql_update_item);
            if (!$stmt_up_item) throw new Exception('تحضير استعلام تحديث البند فشل.');

            $any = false;
            while ($item = $res_items->fetch_assoc()) {
                $any = true;
                $item_id = intval($item['id']);
                $product_id = intval($item['product_id']);
                $qty = floatval($item['quantity']);
                $unit_cost = floatval($item['cost_price_per_unit']);

                // lock product
                if (!$stmt_sel_prod->bind_param("i", $product_id) || !$stmt_sel_prod->execute()) throw new Exception('فشل قفل المنتج: ' . $stmt_sel_prod->error);
                $prod_row = $stmt_sel_prod->get_result()->fetch_assoc();
                if (!$prod_row) throw new Exception("المنتج غير موجود (ID: $product_id).");
                $current_stock = floatval($prod_row['current_stock'] ?? 0);
                $new_stock = $current_stock + $qty;

                // update product
                if (!$stmt_up_prod->bind_param("ddi", $new_stock, $unit_cost, $product_id) || !$stmt_up_prod->execute()) throw new Exception('فشل تحديث المنتج: ' . $stmt_up_prod->error);

                // insert batch
                $received_at = date('Y-m-d');
                $created_by = intval($_SESSION['user_id'] ?? 0);
                $remaining = $qty;
                // bind types: i d d d d s i i i  -> but mysqli types string: "iddddsiii"
                if (!$stmt_ins_batch->bind_param("iddddsiii", $product_id, $qty, $remaining, $qty, $unit_cost, $received_at, $invoice_id, $item_id, $created_by)) {
                    throw new Exception('فشل ربط بيانات إدخال الدفعة: ' . $stmt_ins_batch->error);
                }
                if (!$stmt_ins_batch->execute()) throw new Exception('فشل إدخال الدفعة: ' . $stmt_ins_batch->error);
                $batch_id = $conn->insert_id;

                // update item
                if (!$stmt_up_item->bind_param("dii", $qty, $batch_id, $item_id) || !$stmt_up_item->execute()) throw new Exception('فشل تحديث بند الفاتورة: ' . $stmt_up_item->error);
            }

            if (!$any) throw new Exception('الفاتورة ليس لديها بنود.');

            // update invoice status
            $sql_update_invoice = "UPDATE purchase_invoices SET status = 'fully_received', updated_by = ?, updated_at = NOW() WHERE id = ?";
            if ($stmt_up_inv = $conn->prepare($sql_update_invoice)) {
                $updated_by = intval($_SESSION['user_id'] ?? 0);
                $stmt_up_inv->bind_param("ii", $updated_by, $invoice_id);
                if (!$stmt_up_inv->execute()) throw new Exception('فشل تحديث حالة الفاتورة: ' . $stmt_up_inv->error);
                $stmt_up_inv->close();
            } else throw new Exception('تحضير استعلام تحديث الفاتورة فشل.');

            // close stmts
            $stmt_items->close();
            $stmt_sel_prod->close();
            $stmt_up_prod->close();
            $stmt_ins_batch->close();
            $stmt_up_item->close();

            $conn->commit();

            if (is_ajax_request()) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success'=>true,'message'=>'تم استلام الفاتورة وإنشاء الدُفعات وتحديث المخزون بنجاح.']);
                exit;
            } else {
                $_SESSION['message'] = "<div class='alert alert-success'>تم استلام الفاتورة وإنشاء الدُفعات وتحديث المخزون بنجاح.</div>";
                $query_params = [];
                if (!empty($_POST['supplier_filter_val'])) $query_params['supplier_filter_val'] = $_POST['supplier_filter_val'];
                if (!empty($_POST['status_filter_val'])) $query_params['status_filter_val'] = $_POST['status_filter_val'];
                header("Location: manage_purchase_invoices.php" . (!empty($query_params) ? "?" . http_build_query($query_params) : ""));
                exit;
            }

        } catch (Exception $e) {
            $conn->rollback();
            error_log('Receive invoice error: ' . $e->getMessage());
            if (is_ajax_request()) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success'=>false,'message'=>'حدث خطأ أثناء عملية الاستلام. لم يتم إجراء أي تعديل.']);
                exit;
            } else {
                $_SESSION['message'] = "<div class='alert alert-danger'>حدث خطأ أثناء عملية استلام الفاتورة. لم يتم إجراء أي تعديل.</div>";
                header("Location: manage_purchase_invoices.php");
                exit;
            }
        }
    }

    // ---------- 3) Unreceive (إلغاء الاستلام) - AJAX or normal POST ----------
    if (isset($_POST['unreceive_invoice'])) {
        if (!$csrf_ok) {
            if (is_ajax_request()) { header('Content-Type: application/json'); echo json_encode(['success'=>false,'message'=>'CSRF']); exit; }
            $_SESSION['message'] = "<div class='alert alert-danger'>خطأ: طلب غير صالح (CSRF).</div>";
            header("Location: manage_purchase_invoices.php"); exit;
        }
        $invoice_id = intval($_POST['purchase_invoice_id'] ?? 0);
        if ($invoice_id <= 0) {
            if (is_ajax_request()) { header('Content-Type: application/json'); echo json_encode(['success'=>false,'message'=>'بيانات غير صحيحة.']); exit; }
            $_SESSION['message'] = "<div class='alert alert-danger'>بيانات غير صحيحة.</div>";
            header("Location: manage_purchase_invoices.php"); exit;
        }

        // Start transaction
        $conn->begin_transaction();
        try {
            // lock invoice
            $sql_lock = "SELECT status FROM purchase_invoices WHERE id = ? FOR UPDATE";
            if (!$st = $conn->prepare($sql_lock)) throw new Exception('تحضير استعلام فشل.');
            $st->bind_param("i", $invoice_id);
            $st->execute();
            $row = $st->get_result()->fetch_assoc();
            $st->close();
            if (!$row) throw new Exception('الفاتورة غير موجودة.');
            if ($row['status'] !== 'fully_received') throw new Exception('الفاتورة ليست في حالة مستلمة بالكامل.');

            // check consumption: prefer sales_items table if exists
            $consumed = false;
            // attempt to check sales_items.batch_id existence
            $sql_check_sales = "SELECT COUNT(*) AS cnt FROM sales_items WHERE batch_id IN (SELECT id FROM batches WHERE source_invoice_id = ?)";
            if ($st2 = $conn->prepare($sql_check_sales)) {
                $st2->bind_param("i", $invoice_id);
                $st2->execute();
                $rc = $st2->get_result()->fetch_assoc();
                $st2->close();
                if (intval($rc['cnt'] ?? 0) > 0) $consumed = true;
            } else {
                // if sales_items table doesn't exist or query fails, fallback to checking batch.remaining != original_qty
                $sql_check_batch = "SELECT COUNT(*) AS cnt FROM batches WHERE source_invoice_id = ? AND remaining < original_qty";
                if ($st3 = $conn->prepare($sql_check_batch)) {
                    $st3->bind_param("i", $invoice_id);
                    $st3->execute();
                    $rc2 = $st3->get_result()->fetch_assoc();
                    $st3->close();
                    if (intval($rc2['cnt'] ?? 0) > 0) $consumed = true;
                }
            }

            if ($consumed) throw new Exception('لا يمكن إلغاء الاستلام لأن بعض الكميات قد استُخدمت/بيعت.');

            // proceed to revert: for each batch, reduce product stock and archive batch
            $sql_batches = "SELECT id, product_id, qty FROM batches WHERE source_invoice_id = ? AND status = 'active'";
            if (!$st4 = $conn->prepare($sql_batches)) throw new Exception('فشل جلب الدُفعات.');
            $st4->bind_param("i", $invoice_id);
            $st4->execute();
            $res_batches = $st4->get_result();

            // prepare product update & batch archive & update items
            $sql_update_prod = "UPDATE products SET current_stock = current_stock - ? WHERE id = ?";
            $st_up_prod = $conn->prepare($sql_update_prod);
            if (!$st_up_prod) throw new Exception('تحضير تحديث المنتج فشل.');

            $sql_archive_batch = "UPDATE batches SET status = 'archived', updated_at = NOW() WHERE id = ?";
            $st_archive = $conn->prepare($sql_archive_batch);
            if (!$st_archive) throw new Exception('تحضير أرشفة الدفعة فشل.');

            // also reset purchase_invoice_items qty_received and batch_id
            $sql_reset_item = "UPDATE purchase_invoice_items SET qty_received = 0, batch_id = NULL WHERE source_invoice_id = ? AND id = ?"; 
            // note: purchase_invoice_items likely doesn't have source_invoice_id; adjust: use purchase_invoice_id
            $sql_reset_item = "UPDATE purchase_invoice_items SET qty_received = 0, batch_id = NULL WHERE purchase_invoice_id = ? AND id = ?";
            $st_reset_item = $conn->prepare($sql_reset_item);

            while ($b = $res_batches->fetch_assoc()) {
                $batch_id = intval($b['id']);
                $product_id = intval($b['product_id']);
                $qty = floatval($b['qty']);

                if (!$st_up_prod->bind_param("di", $qty, $product_id) || !$st_up_prod->execute()) throw new Exception('فشل تعديل رصيد المنتج: ' . $st_up_prod->error);
                if (!$st_archive->bind_param("i", $batch_id) || !$st_archive->execute()) throw new Exception('فشل أرشفة الدفعة: ' . $st_archive->error);

                // find purchase_invoice_item id(s) linked to this batch (if any)
                $sql_find_item = "SELECT id FROM purchase_invoice_items WHERE purchase_invoice_id = ? AND batch_id = ? LIMIT 1";
                if ($stfi = $conn->prepare($sql_find_item)) {
                    $stfi->bind_param("ii", $invoice_id, $batch_id);
                    $stfi->execute();
                    $fri = $stfi->get_result()->fetch_assoc();
                    $stfi->close();
                    if ($fri) {
                        $item_id = intval($fri['id']);
                        if (!$st_reset_item->bind_param("ii", $invoice_id, $item_id) || !$st_reset_item->execute()) throw new Exception('فشل إعادة ضبط بند الفاتورة: ' . $st_reset_item->error);
                    }
                }
            }

            // update invoice status to pending
            $sql_up_inv = "UPDATE purchase_invoices SET status = 'pending', updated_by = ?, updated_at = NOW() WHERE id = ?";
            if ($stui = $conn->prepare($sql_up_inv)) {
                $updated_by = intval($_SESSION['user_id'] ?? 0);
                $stui->bind_param("ii", $updated_by, $invoice_id);
                if (!$stui->execute()) throw new Exception('فشل تحديث حالة الفاتورة: ' . $stui->error);
                $stui->close();
            }

            // close stmts
            $st4->close();
            $st_up_prod->close();
            $st_archive->close();
            if ($st_reset_item) $st_reset_item->close();

            $conn->commit();
            if (is_ajax_request()) { header('Content-Type: application/json'); echo json_encode(['success'=>true,'message'=>'تم إلغاء الاستلام وتحويل الفاتورة إلى قيد الانتظار.']); exit; }
            $_SESSION['message'] = "<div class='alert alert-success'>تم إلغاء الاستلام وتحويل الفاتورة إلى قيد الانتظار.</div>";
            header("Location: manage_purchase_invoices.php"); exit;

        } catch (Exception $e) {
            $conn->rollback();
            error_log('Unreceive invoice error: ' . $e->getMessage());
            if (is_ajax_request()) { header('Content-Type: application/json'); echo json_encode(['success'=>false,'message'=>$e->getMessage()]); exit; }
            $_SESSION['message'] = "<div class='alert alert-danger'>فشل إلغاء الاستلام: " . htmlspecialchars($e->getMessage()) . "</div>";
            header("Location: manage_purchase_invoices.php"); exit;
        }
    }

    // ---------- 4) change_invoice_status (non-AJAX) ----------
    if (isset($_POST['change_invoice_status'])) {
        if (!$csrf_ok) { $_SESSION['message'] = "<div class='alert alert-danger'>CSRF.</div>"; header("Location: manage_purchase_invoices.php"); exit; }
        $invoice_id = intval($_POST['purchase_invoice_id'] ?? 0);
        $new_status = trim($_POST['new_status'] ?? '');
        if ($invoice_id <= 0 || $new_status === '') { $_SESSION['message'] = "<div class='alert alert-danger'>بيانات غير صحيحة.</div>"; header("Location: manage_purchase_invoices.php"); exit; }

        // safety: prevent changing fully_received -> pending here (use unreceive flow)
        $sql = "SELECT status FROM purchase_invoices WHERE id = ? LIMIT 1";
        if ($st = $conn->prepare($sql)) {
            $st->bind_param("i", $invoice_id);
            $st->execute();
            $row = $st->get_result()->fetch_assoc();
            $st->close();
            if (!$row) { $_SESSION['message'] = "<div class='alert alert-danger'>الفاتورة غير موجودة.</div>"; header("Location: manage_purchase_invoices.php"); exit; }
            if ($row['status'] === 'fully_received' && $new_status === 'pending') {
                $_SESSION['message'] = "<div class='alert alert-warning'>لا يمكن إعادة الفاتورة إلى 'قيد الانتظار' من هنا. استخدم إلغاء الاستلام إذا كانت الشروط مستوفاة.</div>";
                header("Location: manage_purchase_invoices.php"); exit;
            }
            $sql_up = "UPDATE purchase_invoices SET status = ?, updated_by = ?, updated_at = NOW() WHERE id = ?";
            if ($st2 = $conn->prepare($sql_up)) {
                $st2->bind_param("sii", $new_status, intval($_SESSION['user_id'] ?? 0), $invoice_id);
                if ($st2->execute()) { $_SESSION['message'] = "<div class='alert alert-success'>تم تغيير الحالة.</div>"; }
                else { error_log('Change status error: ' . $st2->error); $_SESSION['message'] = "<div class='alert alert-danger'>فشل تغيير الحالة.</div>"; }
                $st2->close();
            }
        } else {
            $_SESSION['message'] = "<div class='alert alert-danger'>خطأ داخلي.</div>";
        }
        $query_params = [];
        if (!empty($_POST['supplier_filter_val'])) $query_params['supplier_filter_val'] = $_POST['supplier_filter_val'];
        if (!empty($_POST['status_filter_val'])) $query_params['status_filter_val'] = $_POST['status_filter_val'];
        header("Location: manage_purchase_invoices.php" . (!empty($query_params) ? "?" . http_build_query($query_params) : ""));
        exit;
    }

    // ---------- 5) cancel_purchase_invoice (same as original, but added sanity checks) ----------
    if (isset($_POST['cancel_purchase_invoice'])) {
        if (!$csrf_ok) { $_SESSION['message'] = "<div class='alert alert-danger'>CSRF.</div>"; header("Location: manage_purchase_invoices.php"); exit; }
        $invoice_id = intval($_POST['purchase_invoice_id'] ?? 0);
        if ($invoice_id <= 0) { $_SESSION['message'] = "<div class='alert alert-danger'>بيانات غير صحيحة.</div>"; header("Location: manage_purchase_invoices.php"); exit; }

        // check status
        $sql_check = "SELECT status FROM purchase_invoices WHERE id = ? LIMIT 1";
        if ($stmtc = $conn->prepare($sql_check)) {
            $stmtc->bind_param("i", $invoice_id);
            $stmtc->execute();
            $rc = $stmtc->get_result()->fetch_assoc();
            $stmtc->close();
            if (!$rc) { $_SESSION['message'] = "<div class='alert alert-danger'>الفاتورة غير موجودة.</div>"; header("Location: manage_purchase_invoices.php"); exit; }
            if ($rc['status'] === 'fully_received') { $_SESSION['message'] = "<div class='alert alert-warning'>لا يمكن إلغاء فاتورة مستلمة بالكامل هنا. استخدم إجراء تراجع المخزون أولاً.</div>"; header("Location: manage_purchase_invoices.php"); exit; }

            $sql_cancel = "UPDATE purchase_invoices SET status = 'cancelled', updated_by = ?, updated_at = NOW() WHERE id = ?";
            if ($stmt_can = $conn->prepare($sql_cancel)) {
                $updated_by = intval($_SESSION['user_id'] ?? 0);
                $stmt_can->bind_param("ii", $updated_by, $invoice_id);
                if ($stmt_can->execute()) { $_SESSION['message'] = "<div class='alert alert-success'>تم إلغاء الفاتورة بنجاح.</div>"; }
                else { error_log('Cancel invoice error: ' . $stmt_can->error); $_SESSION['message'] = "<div class='alert alert-danger'>فشل إلغاء الفاتورة.</div>"; }
                $stmt_can->close();
            }
        }
        $query_params = [];
        if (!empty($_POST['supplier_filter_val'])) $query_params['supplier_filter_val'] = $_POST['supplier_filter_val'];
        if (!empty($_POST['status_filter_val'])) $query_params['status_filter_val'] = $_POST['status_filter_val'];
        header("Location: manage_purchase_invoices.php" . (!empty($query_params) ? "?" . http_build_query($query_params) : ""));
        exit;
    }

    // ---------- 6) delete_purchase_invoice (safe deletion checks) ----------
    if (isset($_POST['delete_purchase_invoice'])) {
        if (!$csrf_ok) { $_SESSION['message'] = "<div class='alert alert-danger'>CSRF.</div>"; header("Location: manage_purchase_invoices.php"); exit; }
        $invoice_id_to_delete = intval($_POST['purchase_invoice_id_to_delete'] ?? 0);
        if ($invoice_id_to_delete <= 0) { $_SESSION['message'] = "<div class='alert alert-danger'>بيانات خاطئة.</div>"; header("Location: manage_purchase_invoices.php"); exit; }

        // check restrictions
        $sql_check = "SELECT status FROM purchase_invoices WHERE id = ? LIMIT 1";
        if ($stmtc = $conn->prepare($sql_check)) {
            $stmtc->bind_param("i", $invoice_id_to_delete);
            $stmtc->execute();
            $row = $stmtc->get_result()->fetch_assoc();
            $stmtc->close();
            if ($row && $row['status'] === 'fully_received') {
                $_SESSION['message'] = "<div class='alert alert-warning'>لا يمكن حذف فاتورة مستلمة بالكامل. الرجاء تراجع المخزون أولاً.</div>";
                header("Location: manage_purchase_invoices.php"); exit;
            }
            // ensure no qty_received or batches
            $sql_received = "SELECT COUNT(*) AS cnt FROM purchase_invoice_items WHERE purchase_invoice_id = ? AND COALESCE(qty_received,0) > 0";
            $sql_batches = "SELECT COUNT(*) AS cnt FROM batches WHERE source_invoice_id = ?";
            $rcv = 0; $bct = 0;
            if ($s1 = $conn->prepare($sql_received)) {
                $s1->bind_param("i", $invoice_id_to_delete); $s1->execute();
                $rcv = intval($s1->get_result()->fetch_assoc()['cnt'] ?? 0);
                $s1->close();
            }
            if ($s2 = $conn->prepare($sql_batches)) {
                $s2->bind_param("i", $invoice_id_to_delete); $s2->execute();
                $bct = intval($s2->get_result()->fetch_assoc()['cnt'] ?? 0);
                $s2->close();
            }
            if ($rcv > 0 || $bct > 0) {
                $_SESSION['message'] = "<div class='alert alert-warning'>لا يمكن حذف الفاتورة لوجود بنود مستلمة أو دفعات مرتبطة.</div>";
                header("Location: manage_purchase_invoices.php"); exit;
            }
            // safe to delete (or soft-delete depending on policy)
            $sql_delete = "DELETE FROM purchase_invoice_items WHERE purchase_invoice_id = ?";
            if ($sd = $conn->prepare($sql_delete)) {
                $sd->bind_param("i", $invoice_id_to_delete); $sd->execute(); $sd->close();
            }
            $sql_delete2 = "DELETE FROM purchase_invoices WHERE id = ?";
            if ($sd2 = $conn->prepare($sql_delete2)) {
                $sd2->bind_param("i", $invoice_id_to_delete); $sd2->execute();
                $_SESSION['message'] = ($sd2->affected_rows > 0) ? "<div class='alert alert-success'>تم حذف الفاتورة وبنودها.</div>" : "<div class='alert alert-warning'>لم يتم العثور على الفاتورة.</div>";
                $sd2->close();
            }
        }
        $query_params = [];
        if (!empty($_POST['supplier_filter_val'])) $query_params['supplier_filter_val'] = $_POST['supplier_filter_val'];
        if (!empty($_POST['status_filter_val'])) $query_params['status_filter_val'] = $_POST['status_filter_val'];
        header("Location: manage_purchase_invoices.php" . (!empty($query_params) ? "?" . http_build_query($query_params) : ""));
        exit;
    }

    // ---------- 7) filter_purchases handled below by POST -> variables ----------
    if (isset($_POST['filter_purchases'])) {
        $selected_supplier_id = isset($_POST['supplier_filter']) ? intval($_POST['supplier_filter']) : "";
        $selected_status = isset($_POST['status_filter']) ? trim($_POST['status_filter']) : "";
    }
}

// Support GET filters
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['supplier_filter_val'])) $selected_supplier_id = intval($_GET['supplier_filter_val']);
    if (isset($_GET['status_filter_val'])) $selected_status = trim($_GET['status_filter_val']);
}

// --- fetch suppliers ---
$sql_suppliers = "SELECT id, name FROM suppliers ORDER BY name ASC";
$result_s = $conn->query($sql_suppliers);
if ($result_s) {
    while ($row_s = $result_s->fetch_assoc()) $suppliers_list[] = $row_s;
}

// --- totals ---
$sql_grand_total = "SELECT SUM(total_amount) AS grand_total FROM purchase_invoices WHERE status != 'cancelled'";
$result_grand_total_query = $conn->query($sql_grand_total);
if ($result_grand_total_query && $result_grand_total_query->num_rows > 0) {
    $row_grand_total = $result_grand_total_query->fetch_assoc();
    $grand_total_all_purchases = floatval($row_grand_total['grand_total'] ?? 0);
}

// --- select invoices with pagination (simple page param) ---
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 25;
$offset = ($page - 1) * $per_page;

$sql_select_invoices = "SELECT pi.id, pi.supplier_invoice_number, pi.purchase_date, pi.status, pi.total_amount, pi.created_at, s.name as supplier_name, u.username as creator_name
                        FROM purchase_invoices pi
                        JOIN suppliers s ON pi.supplier_id = s.id
                        LEFT JOIN users u ON pi.created_by = u.id";
$conditions = [];
$params = [];
$types = "";
if (!empty($selected_supplier_id)) { $conditions[] = "pi.supplier_id = ?"; $params[] = $selected_supplier_id; $types .= 'i'; }
if (!empty($selected_status)) { $conditions[] = "pi.status = ?"; $params[] = $selected_status; $types .= 's'; }
if (!empty($conditions)) $sql_select_invoices .= " WHERE " . implode(" AND ", $conditions);
$sql_select_invoices .= " ORDER BY pi.purchase_date DESC, pi.id DESC LIMIT ? OFFSET ?";
if ($stmt_select = $conn->prepare($sql_select_invoices)) {
    // bind params + limit/offset
    if (!empty($params)) {
        // build types with ints for limit/offset
        $types_with_lo = $types . 'ii';
        $params[] = $per_page;
        $params[] = $offset;
        $stmt_select->bind_param($types_with_lo, ...$params);
    } else {
        $stmt_select->bind_param("ii", $per_page, $offset);
    }
    if ($stmt_select->execute()) $result_invoices = $stmt_select->get_result();
    else $message = "<div class='alert alert-danger'>حدث خطأ أثناء جلب فواتير المشتريات: " . htmlspecialchars($stmt_select->error) . "</div>";
    $stmt_select->close();
} else {
    $message = "<div class='alert alert-danger'>خطأ في تحضير استعلام جلب فواتير المشتريات: " . htmlspecialchars($conn->error) . "</div>";
}

// total displayed sum
$sql_total_displayed = "SELECT SUM(total_amount) AS total_displayed FROM purchase_invoices pi WHERE 1=1";
$conditions_total = [];
$params_total = [];
$types_total = "";
if (!empty($selected_supplier_id)) { $conditions_total[] = "pi.supplier_id = ?"; $params_total[] = $selected_supplier_id; $types_total .= 'i'; }
if (!empty($selected_status)) { $conditions_total[] = "pi.status = ?"; $params_total[] = $selected_status; $types_total .= 's'; }
if (!empty($conditions_total)) $sql_total_displayed .= " AND " . implode(" AND ", $conditions_total);
if ($stmt_total = $conn->prepare($sql_total_displayed)) {
    if (!empty($params_total)) $stmt_total->bind_param($types_total, ...$params_total);
    $stmt_total->execute();
    $result_total = $stmt_total->get_result();
    $row_total = $result_total->fetch_assoc();
    $displayed_invoices_sum = floatval($row_total['total_displayed'] ?? 0);
    $stmt_total->close();
}

require_once BASE_DIR . 'partials/header.php';
require_once BASE_DIR . 'partials/sidebar.php';
?>

<!-- ========================= UI ========================= -->
<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="mb-0"><i class="fas fa-dolly-flatbed me-2"></i> إدارة فواتير المشتريات</h2>
        <div>
            <a href="<?php echo BASE_URL; ?>admin/manage_suppliers.php" class="btn btn-success">
                <i class="fas fa-plus-circle"></i> إنشاء فاتورة مشتريات جديدة
            </a>
        </div>
    </div>

    <?php if(!empty($message)) echo $message; ?>

    <div class="card mb-3 shadow-sm">
        <div class="card-body">
            <form id="filterForm" action="<?php echo $current_page_url_for_forms; ?>" method="post" class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label class="form-label">تصفية حسب المورد</label>
                    <select name="supplier_filter" class="form-select">
                        <option value="">-- كل الموردين --</option>
                        <?php foreach($suppliers_list as $s): ?>
                            <option value="<?php echo $s['id']; ?>" <?php echo ($selected_supplier_id == $s['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($s['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">تصفية حسب الحالة</label>
                    <select name="status_filter" class="form-select">
                        <option value="">-- كل الحالات --</option>
                        <?php foreach($status_labels as $k=>$v): ?>
                            <option value="<?php echo $k; ?>" <?php echo ($selected_status == $k) ? 'selected' : ''; ?>><?php echo $v; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <button name="filter_purchases" class="btn btn-primary w-100"><i class="fas fa-filter"></i> تصفية</button>
                </div>
                <div class="col-md-2 text-end">
                    <?php if(!empty($selected_supplier_id) || !empty($selected_status)): ?>
                        <a href="<?php echo $current_page_url_for_forms; ?>" class="btn btn-outline-secondary w-100"><i class="fas fa-times"></i> مسح الفلتر</a>
                    <?php endif; ?>
                </div>

                <input type="hidden" name="supplier_filter_val" value="<?php echo htmlspecialchars($selected_supplier_id); ?>">
                <input type="hidden" name="status_filter_val" value="<?php echo htmlspecialchars($selected_status); ?>">
            </form>
        </div>
    </div>

    <div class="card shadow mb-3">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-primary">
                        <tr>
                            <th>#</th>
                            <th>المورد</th>
                            <th class="d-none d-md-table-cell">رقم المورد</th>
                            <th>تاريخ الشراء</th>
                            <th class="d-none d-md-table-cell">الحالة</th>
                            <th class="text-end">الإجمالي</th>
                            <th class="d-none d-md-table-cell">أنشئت بواسطة</th>
                            <th class="d-none d-md-table-cell">تاريخ الإنشاء</th>
                            <th class="text-center">إجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result_invoices && $result_invoices->num_rows > 0): ?>
                            <?php while($invoice = $result_invoices->fetch_assoc()): 
                                $status = $invoice['status'];
                                $total = floatval($invoice['total_amount'] ?? 0);
                            ?>
                            <tr id="invoice-row-<?php echo $invoice['id']; ?>">
                                <td><?php echo $invoice['id']; ?></td>
                                <td class="fw-semibold"><?php echo htmlspecialchars($invoice['supplier_name']); ?></td>
                                <td class="d-none d-md-table-cell"><?php echo htmlspecialchars($invoice['supplier_invoice_number'] ?: '-'); ?></td>
                                <td><?php echo date('Y-m-d', strtotime($invoice['purchase_date'])); ?></td>
                                <td class="d-none d-md-table-cell">
                                    <span class="badge <?php 
                                        switch($status){
                                            case 'pending': echo 'bg-warning text-dark'; break;
                                            case 'partial_received': echo 'bg-info'; break;
                                            case 'fully_received': echo 'bg-success'; break;
                                            case 'cancelled': echo 'bg-danger'; break;
                                            default: echo 'bg-secondary';
                                        }
                                    ?>"><?php echo htmlspecialchars($status_labels[$status] ?? $status); ?></span>
                                </td>
                                <td class="text-end fw-bold"><?php echo number_format($total,2); ?> ج.م</td>
                                <td class="d-none d-md-table-cell"><?php echo htmlspecialchars($invoice['creator_name'] ?? '—'); ?></td>
                                <td class="d-none d-md-table-cell"><?php echo date('Y-m-d H:i', strtotime($invoice['created_at'])); ?></td>
                                <td class="text-center">
                                    <!-- View -->
                                    <button class="btn btn-sm btn-outline-primary me-1" title="عرض البنود" onclick="openInvoiceModal(<?php echo $invoice['id']; ?>,'view')">
                                        <i class="fas fa-eye"></i>
                                    </button>

                                    <!-- Edit (opens modal) - allowed if pending OR if fully_received but editable (checked in modal) -->
                                    <button class="btn btn-sm btn-outline-warning me-1" title="تعديل"openInvoiceModal(<?php echo $invoice['id']; ?>,'edit')">
                                        <i class="fas fa-edit"></i>
                                    </button>

                                    <!-- Receive (only if not fully_received/cancelled) -->
                                    <?php if (!in_array($status, ['fully_received','cancelled'])): ?>
                                    <button class="btn btn-sm btn-success me-1" title="استلام الفاتورة" onclick="receiveInvoice(<?php echo $invoice['id']; ?>, this)">
                                        <span class="btn-text"><i class="fas fa-check-double"></i></span>
                                        <span class="spinner-border spinner-border-sm ms-1 d-none" role="status" aria-hidden="true"></span>
                                    </button>
                                    <?php endif; ?>

                                    <!-- Unreceive (only when fully_received) -->
                                    <?php if ($status === 'fully_received'): ?>
                                        <button class="btn btn-sm btn-outline-secondary me-1" title="إلغاء الاستلام (إن أمكن)" onclick="unreceiveInvoice(<?php echo $invoice['id']; ?>, this)">
                                            <i class="fas fa-undo-alt"></i>
                                        </button>
                                    <?php endif; ?>

                                    <!-- Cancel (not available if fully_received) -->
                                    <?php if (!in_array($status, ['fully_received','cancelled'])): ?>
                                        <form method="post" class="d-inline" onsubmit="return confirm('هل تريد فعلاً إلغاء هذه الفاتورة؟')">
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                            <input type="hidden" name="purchase_invoice_id" value="<?php echo $invoice['id']; ?>">
                                            <button type="submit" name="cancel_purchase_invoice" class="btn btn-sm btn-danger me-1" title="إلغاء">
                                                <i class="fas fa-ban"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <!-- Delete (not allowed if fully_received) -->
                                    <?php if ($status !== 'fully_received'): ?>
                                        <form method="post" class="d-inline" onsubmit="return confirm('هل تريد حذف هذه الفاتورة نهائياً؟')">
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                            <input type="hidden" name="purchase_invoice_id_to_delete" value="<?php echo $invoice['id']; ?>">
                                            <button type="submit" name="delete_purchase_invoice" class="btn btn-sm btn-outline-danger" title="حذف">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="9" class="text-center py-4">لا توجد فواتير مطابقة.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Totals -->
    <div class="row mb-4">
        <div class="col-md-6 offset-md-6">
            <div class="card shadow-sm">
                <div class="card-body">
                    <h6 class="mb-3 text-center">ملخص إجماليات فواتير المشتريات</h6>
                    <div class="d-flex justify-content-between">
                        <div>إجمالي الفواتير المعروضة</div>
                        <div class="fw-bold"><?php echo number_format($displayed_invoices_sum,2); ?> ج.م</div>
                    </div>
                    <hr>
                    <div class="d-flex justify-content-between">
                        <div>الإجمالي الكلي (غير الملغاة)</div>
                        <div class="fw-bold text-success"><?php echo number_format($grand_total_all_purchases,2); ?> ج.م</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>

<!-- Modal (AJAX-loaded content from view_purchase_invoice.php?for_manage=1&mode=...) -->
<div class="modal fade" id="invoiceModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">تفاصيل الفاتورة</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
      </div>
      <div class="modal-body" id="invoiceModalBody">
        <div class="text-center py-5">جارٍ التحميل...</div>
      </div>
      <div class="modal-footer">
        <button id="modalCloseBtn" type="button" class="btn btn-secondary" data-bs-dismiss="modal">إغلاق</button>
      </div>
    </div>
  </div>
</div>

<script >

    document.addEventListener('DOMContentLoaded', function() {

// ======= Helpers =======
function isJSONResponse(response) {
    const contentType = response.headers.get('content-type') || '';
    return contentType.indexOf('application/json') !== -1;
}

// ======= Modal loader (view/edit) =======
function openInvoiceModal(id, mode) {
    log('Opening invoice modal for ID ' + id + ' in mode ' + mode);
    const url = "<?php echo $view_purchase_invoice_link; ?>?id=" + encodeURIComponent(id) + "&for_manage=1&mode=" + encodeURIComponent(mode);
    const body = document.getElementById('invoiceModalBody');
    if (!body) { console.error('invoiceModalBody not found'); return; }
    body.innerHTML = '<div class="text-center py-5"><div class="spinner-border" role="status"></div></div>';

    fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(resp => {
            if (!resp.ok) throw new Error('فشل تحميل محتوى المودال (HTTP ' + resp.status + ')');
            return resp.text();
        })
        .then(html => {
            body.innerHTML = html;
            const invoiceModal = new bootstrap.Modal(document.getElementById('invoiceModal'));
            invoiceModal.show();
        })
        .catch(err => {
            console.error(err);
            body.innerHTML = '<div class="alert alert-danger">فشل تحميل تفاصيل الفاتورة.</div>';
        });
}

// ======= Receive invoice (AJAX) =======
function receiveInvoice(id, btn) {
    if (!confirm('هل أنت متأكد من استلام هذه الفاتورة؟ هذه العملية ستُحدث المخزون.')) return;

    // prepare UI
    if (btn) {
        btn.disabled = true;
        const spinner = btn.querySelector('.spinner-border');
        const text = btn.querySelector('.btn-text');
        if (spinner) spinner.classList.remove('d-none');
        if (text) text.classList.add('d-none');
    }

    const form = new FormData();
    form.append('csrf_token', '<?php echo $csrf_token; ?>');
    form.append('receive_purchase_invoice', '1');
    form.append('purchase_invoice_id', id);

    fetch('<?php echo $current_page_url_for_forms; ?>', {
        method: 'POST',
        credentials: 'same-origin',
        body: form,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(resp => {
        // try JSON first, fallback to text
        if (isJSONResponse(resp)) return resp.json();
        return resp.text().then(t => { try { return JSON.parse(t); } catch(e) { return { success:false, message: t }; } });
    })
    .then(data => {
        if (data && data.success) {
            alert(data.message || 'تم الاستلام.');
            location.reload();
        } else {
            alert((data && data.message) ? data.message : 'فشل الاستلام.');
            location.reload();
        }
    })
    .catch(err => {
        console.error(err);
        alert('فشل أثناء الاتصال.');
        location.reload();
    });
}

// ======= Unreceive invoice (AJAX) =======
function unreceiveInvoice(id, btn) {
    if (!confirm('هل تريد إلغاء الاستلام وإعادة الفاتورة إلى قيد الانتظار؟')) return;

    if (btn) btn.disabled = true;

    const form = new FormData();
    form.append('csrf_token', '<?php echo $csrf_token; ?>');
    form.append('unreceive_invoice', '1');
    form.append('purchase_invoice_id', id);

    fetch('<?php echo $current_page_url_for_forms; ?>', {
        method: 'POST',
        credentials: 'same-origin',
        body: form,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(resp => {
        if (isJSONResponse(resp)) return resp.json();
        return resp.text().then(t => { try { return JSON.parse(t); } catch(e) { return { success:false, message: t }; } });
    })
    .then(data => {
        if (data && data.success) {
            alert(data.message || 'تم إلغاء الاستلام.');
            location.reload();
        } else {
            alert((data && data.message) ? data.message : 'فشل إلغاء الاستلام.');
            location.reload();
        }
    })
    .catch(err => {
        console.error(err);
        alert('فشل أثناء الاتصال.');
        location.reload();
    });
}

// ======= DOM Ready safety (optional handlers) =======
document.addEventListener('DOMContentLoaded', function() {
    // Example: if you want to attach delegated handlers instead of inline onclicks
    // document.querySelector('#someButton')?.addEventListener('click', function(){ ... });
});
    });
    
// function openInvoiceModal(id, mode) {
//     // mode: 'view' أو 'edit' - نرسل for_manage=1 حتى يعرض view_purchase_invoice جزئية مهيأة للمودال
//     const url = '<?php echo $view_purchase_invoice_link; ?>?id=' + encodeURIComponent(id) + '&for_manage=1&mode=' + encodeURIComponent(mode);
//     const modalBody = document.getElementById('invoiceModalBody');
//     modalBody.innerHTML = '<div class="text-center py-4">جارٍ التحميل...</div>';
//     fetch(url, { credentials: 'same-origin' })
//         .then(r => r.text())
//         .then(html => {
//             modalBody.innerHTML = html;
//             var invoiceModal = new bootstrap.Modal(document.getElementById('invoiceModal'));
//             invoiceModal.show();
//         })
//         .catch(err => {
//             modalBody.innerHTML = '<div class="alert alert-danger">فشل تحميل تفاصيل الفاتورة.</div>';
//             console.error(err);
//         });
// }
</script>
<?php
$conn->close();
require_once BASE_DIR . 'partials/footer.php';
?>
