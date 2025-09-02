<?php
$page_title = "تعديل فاتورة مشتريات";
$class_dashboard = "active";
require_once dirname(__DIR__) . '/config.php';
require_once BASE_DIR . 'partials/session_admin.php'; // صلاحيات المدير فقط

$message = "";
$invoice_id = 0;
$supplier_id_current = 0; // لتخزين supplier_id من الفاتورة
$supplier_name_current = ""; // اسم المورد الحالي (للعرض فقط)
$supplier_invoice_number_current = "";
$purchase_date_current = "";
$notes_current = "";
$status_current = "";

// متغيرات لأخطاء التحقق من الصحة
$supplier_invoice_number_err = $purchase_date_err = $status_err = "";

// جلب توكن CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// --- معالجة طلب التحديث (عند إرسال النموذج) ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_purchase_invoice'])) {
    // التحقق من CSRF
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $message = "<div class='alert alert-danger'>خطأ: طلب غير صالح (CSRF detected).</div>";
    } else {
        $invoice_id = intval($_POST['invoice_id']); // من الحقل المخفي
        $supplier_invoice_number_posted = trim($_POST['supplier_invoice_number']);
        $purchase_date_posted = trim($_POST['purchase_date']);
        $notes_posted = trim($_POST['notes']);
        $status_posted = trim($_POST['status']);
        $updated_by = $_SESSION['id']; // المدير هو من يقوم بالتحديث

        // --- الحصول على الحالة السابقة من قاعدة البيانات
        $previous_status = null;
        $sql_prev = "SELECT status FROM purchase_invoices WHERE id = ? LIMIT 1";
        if ($stmt_prev = $conn->prepare($sql_prev)) {
            $stmt_prev->bind_param("i", $invoice_id);
            $stmt_prev->execute();
            $res_prev = $stmt_prev->get_result();
            if ($row_prev = $res_prev->fetch_assoc()) {
                $previous_status = $row_prev['status'];
            }
            $stmt_prev->close();
        }

        // --- التحقق من صحة البيانات ---
        if (empty($purchase_date_posted)) {
            $purchase_date_err = "الرجاء إدخال تاريخ الشراء.";
        }
        $allowed_statuses = ['pending', 'partial_received', 'fully_received', 'cancelled'];
        if (empty($status_posted) || !in_array($status_posted, $allowed_statuses)) {
            $status_err = "الرجاء اختيار حالة فاتورة صالحة.";
        }

        if (empty($purchase_date_err) && empty($status_err)) {
            // نستخدم معاملة لضمان الاتساق
            $conn->begin_transaction();
            try {
                // تحديث بيانات رأس الفاتورة
                $sql_update = "UPDATE purchase_invoices
                               SET supplier_invoice_number = ?, purchase_date = ?, notes = ?, status = ?, updated_by = ?, updated_at = NOW()
                               WHERE id = ?";
                if ($stmt_update = $conn->prepare($sql_update)) {
                    $stmt_update->bind_param("ssssii", $supplier_invoice_number_posted, $purchase_date_posted, $notes_posted, $status_posted, $updated_by, $invoice_id);
                    if (!$stmt_update->execute()) {
                        throw new Exception("خطأ أثناء تحديث الفاتورة: " . $stmt_update->error);
                    }
                    $stmt_update->close();
                } else {
                    throw new Exception("خطأ في تحضير استعلام التحديث: " . $conn->error);
                }

                // --- تعديل مصطفى: تحديث المخزون وسعر الشراء ---
                $sql_items = "SELECT product_id, quantity, cost_price_per_unit FROM purchase_invoice_items WHERE purchase_invoice_id = ?";
                if ($stmt_items = $conn->prepare($sql_items)) {
                    $stmt_items->bind_param("i", $invoice_id);
                    $stmt_items->execute();
                    $res_items = $stmt_items->get_result();
                    
                    while ($row = $res_items->fetch_assoc()) {
                        $pid = intval($row['product_id']);
                        $qty = floatval($row['quantity']);
                        $invoice_cost_price = floatval($row['cost_price_per_unit']);

                        // جلب السعر القديم للمنتج
                        $sql_old_price = "SELECT cost_price FROM products WHERE id = ? LIMIT 1";
                        $stmt_old_price = $conn->prepare($sql_old_price);
                        $stmt_old_price->bind_param("i", $pid);
                        $stmt_old_price->execute();
                        $res_old_price = $stmt_old_price->get_result();
                        $old_cost_price = 0;
                        if ($row_old = $res_old_price->fetch_assoc()) {
                            $old_cost_price = floatval($row_old['cost_price']);
                        }
                        $stmt_old_price->close();

                        // إضافة الكمية للمخزون إذا التحويل إلى fully_received
                        if ($previous_status !== 'fully_received' && $status_posted === 'fully_received') {
                            $sql_update_stock = "UPDATE products SET current_stock = current_stock + ?, cost_price = ? WHERE id = ?";
                            $stmt_update_stock = $conn->prepare($sql_update_stock);
                            $stmt_update_stock->bind_param("ddi", $qty, $invoice_cost_price, $pid);
                            if (!$stmt_update_stock->execute()) {
                                throw new Exception("فشل تحديث المخزون والسعر للمنتج ID {$pid}: " . $stmt_update_stock->error);
                            }
                            $stmt_update_stock->close();
                        }

                        // إعادة السعر القديم إذا تم التراجع عن fully_received
                        if ($previous_status === 'fully_received' && $status_posted !== 'fully_received') {
                            $sql_restore_price = "UPDATE products SET cost_price = ? WHERE id = ?";
                            $stmt_restore_price = $conn->prepare($sql_restore_price);
                            $stmt_restore_price->bind_param("di", $old_cost_price, $pid);
                            if (!$stmt_restore_price->execute()) {
                                throw new Exception("فشل إعادة السعر القديم للمنتج ID {$pid}: " . $stmt_restore_price->error);
                            }
                            $stmt_restore_price->close();
                        }
                    }
                    $stmt_items->close();
                }

                // --- تحديث إجمالي الفاتورة ---
                $sql_sum_total = "SELECT COALESCE(SUM(total_cost), 0) AS grand_total FROM purchase_invoice_items WHERE purchase_invoice_id = ?";
                if ($stmt_sum = $conn->prepare($sql_sum_total)) {
                    $stmt_sum->bind_param("i", $invoice_id);
                    $stmt_sum->execute();
                    $res_sum = $stmt_sum->get_result();
                    $row_sum = $res_sum->fetch_assoc();
                    $invoice_total_calculated = floatval($row_sum['grand_total']);
                    $stmt_sum->close();

                    $sql_update_invoice_total = "UPDATE purchase_invoices SET total_amount = ? WHERE id = ?";
                    if ($stmt_update_total = $conn->prepare($sql_update_invoice_total)) {
                        $stmt_update_total->bind_param("di", $invoice_total_calculated, $invoice_id);
                        if (!$stmt_update_total->execute()) {
                            throw new Exception("فشل تحديث إجمالي الفاتورة: " . $stmt_update_total->error);
                        }
                        $stmt_update_total->close();
                    } else {
                        throw new Exception("خطأ في تحضير استعلام تحديث إجمالي الفاتورة: " . $conn->error);
                    }
                } else {
                    throw new Exception("خطأ في تحضير استعلام حساب إجمالي الفاتورة: " . $conn->error);
                }

                // commit
                $conn->commit();
                $_SESSION['message'] = "<div class='alert alert-success'>تم تحديث بيانات فاتورة المشتريات رقم #{$invoice_id} بنجاح.</div>";
                header("Location: " . BASE_URL . "admin/view_purchase_invoice.php?id=" . $invoice_id);
                exit;

            } catch (Exception $e) {
                $conn->rollback();
                $message = "<div class='alert alert-danger'>فشل تحديث الفاتورة: " . htmlspecialchars($e->getMessage()) . "</div>";
            }
        } else {
             if (empty($message)) {
                $message = "<div class='alert alert-danger'>الرجاء إصلاح الأخطاء في النموذج.</div>";
             }
             $supplier_invoice_number_current = $supplier_invoice_number_posted;
             $purchase_date_current = $purchase_date_posted;
             $notes_current = $notes_posted;
             $status_current = $status_posted;
        }
    }
}

// --- جلب بيانات الفاتورة لعرضها في النموذج (GET) ---
elseif (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $invoice_id = intval($_GET['id']);
    $sql_fetch = "SELECT pi.supplier_id, pi.supplier_invoice_number, pi.purchase_date, pi.notes, pi.status, s.name as supplier_name
                  FROM purchase_invoices pi
                  JOIN suppliers s ON pi.supplier_id = s.id
                  WHERE pi.id = ?";
    if ($stmt_fetch = $conn->prepare($sql_fetch)) {
        $stmt_fetch->bind_param("i", $invoice_id);
        if ($stmt_fetch->execute()) {
            $result_fetch = $stmt_fetch->get_result();
            if ($row_fetch = $result_fetch->fetch_assoc()) {
                $supplier_id_current = $row_fetch['supplier_id'];
                $supplier_name_current = $row_fetch['supplier_name'];
                $supplier_invoice_number_current = $row_fetch['supplier_invoice_number'];
                $purchase_date_current = $row_fetch['purchase_date'];
                $notes_current = $row_fetch['notes'];
                $status_current = $row_fetch['status'];
            } else {
                $_SESSION['message'] = "<div class='alert alert-danger'>لم يتم العثور على فاتورة المشتريات المطلوبة (ID: {$invoice_id}).</div>";
                header("Location: " . BASE_URL . "admin/manage_purchase_invoices.php");
                exit;
            }
        } else {
            $_SESSION['message'] = "<div class='alert alert-danger'>خطأ أثناء جلب بيانات الفاتورة.</div>";
            header("Location: " . BASE_URL . "admin/manage_purchase_invoices.php");
            exit;
        }
        $stmt_fetch->close();
    }
} else {
    $_SESSION['message'] = "<div class='alert alert-warning'>رقم فاتورة المشتريات غير محدد.</div>";
    header("Location: " . BASE_URL . "admin/manage_purchase_invoices.php");
    exit;
}

// مسارات أخرى
$view_purchase_invoice_link = BASE_URL . "admin/view_purchase_invoice.php?id=" . $invoice_id;
$manage_purchase_invoices_link = BASE_URL . "admin/manage_purchase_invoices.php";

require_once BASE_DIR . 'partials/header.php';
require_once BASE_DIR . 'partials/navbar.php';
?>

<div class="container mt-5 pt-3">
    <div class="row justify-content-center">
        <div class="col-md-8 col-lg-7">
            <?php if ($invoice_id > 0 && !empty($supplier_name_current)) : ?>
            <div class="card shadow-sm">
                <div class="card-header bg-warning text-dark text-center">
                    <h2><i class="fas fa-edit"></i> تعديل فاتورة المشتريات رقم: #<?php echo $invoice_id; ?></h2>
                    <h5 class="text-muted">للمورد: <?php echo htmlspecialchars($supplier_name_current); ?></h5>
                </div>
                <div class="card-body p-4">
                    <?php echo $message; ?>
                    <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>?id=<?php echo $invoice_id; ?>" method="post">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="invoice_id" value="<?php echo $invoice_id; ?>">
                        <div class="mb-3">
                            <label for="supplier_invoice_number" class="form-label">رقم فاتورة المورد (اختياري):</label>
                            <input type="text" name="supplier_invoice_number" id="supplier_invoice_number" class="form-control <?php echo (!empty($supplier_invoice_number_err)) ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($supplier_invoice_number_current); ?>">
                            <span class="invalid-feedback"><?php echo $supplier_invoice_number_err; ?></span>
                        </div>

                        <div class="mb-3">
                            <label for="purchase_date" class="form-label">تاريخ الشراء/الفاتورة:</label>
                            <input type="date" name="purchase_date" id="purchase_date" class="form-control <?php echo (!empty($purchase_date_err)) ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($purchase_date_current); ?>" required>
                            <span class="invalid-feedback"><?php echo $purchase_date_err; ?></span>
                        </div>

                        <div class="mb-3">
                            <label for="status" class="form-label">حالة الفاتورة:</label>
                            <select name="status" id="status" class="form-select <?php echo (!empty($status_err)) ? 'is-invalid' : ''; ?>" required>
                                <option value="pending" <?php echo ($status_current == 'pending') ? 'selected' : ''; ?>>قيد الانتظار</option>
                                <option value="partial_received" <?php echo ($status_current == 'partial_received') ? 'selected' : ''; ?>>تم الاستلام جزئياً</option>
                                <option value="fully_received" <?php echo ($status_current == 'fully_received') ? 'selected' : ''; ?>>تم الاستلام بالكامل</option>
                                <option value="cancelled" <?php echo ($status_current == 'cancelled') ? 'selected' : ''; ?>>ملغاة</option>
                            </select>
                            <span class="invalid-feedback"><?php echo $status_err; ?></span>
                        </div>

                        <div class="mb-3">
                            <label for="notes" class="form-label">ملاحظات (اختياري):</label>
                            <textarea name="notes" id="notes" class="form-control" rows="3"><?php echo htmlspecialchars($notes_current); ?></textarea>
                        </div>

                        <div class="d-grid gap-2 d-md-flex justify-content-md-end mt-4">
                             <a href="<?php echo $view_purchase_invoice_link; ?>" class="btn btn-secondary me-md-2">إلغاء</a>
                            <button type="submit" name="update_purchase_invoice" class="btn btn-warning"><i class="fas fa-save"></i> تحديث الفاتورة</button>
                        </div>
                    </form>
                </div>
            </div>
            <?php else: ?>
                <?php if(empty($message)) echo "<div class='alert alert-warning text-center'>الفاتورة المطلوبة غير موجودة أو خطأ في تحديدها. <a href='{$manage_purchase_invoices_link}'>العودة لقائمة فواتير المشتريات</a>.</div>"; ?>
            <?php endif; ?>
        </div>
    </div>
</div>





<?php
$conn->close();
require_once BASE_DIR . 'partials/footer.php';
?>
