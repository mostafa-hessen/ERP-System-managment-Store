<?php
$page_title = "إنشاء فاتورة مشتريات جديدة";
$class_dashboard = "active";
require_once dirname(__DIR__) . '/config.php';
require_once BASE_DIR . 'partials/session_admin.php';

$message = "";
$supplier_id = 0;
$supplier_name = "غير محدد";
$supplier_invoice_number = "";
$purchase_date = date('Y-m-d');
$notes = "";
$supplier_invoice_number_err = $purchase_date_err = "";

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// --- معالجة حفظ الفاتورة عند الضغط على الزر ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['save_purchase_invoice'])) {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $message = "<div class='alert alert-danger'>خطأ: طلب غير صالح (CSRF).</div>";
    } else {
        $supplier_id = intval($_POST['supplier_id']);
        $supplier_invoice_number = trim($_POST['supplier_invoice_number']);
        $purchase_date = trim($_POST['purchase_date']);
        $notes = trim($_POST['notes']);
        $created_by = $_SESSION['id'];
        $status = 'pending';
        $total_amount = 0.00;

        if (empty($purchase_date)) {
            $purchase_date_err = "الرجاء إدخال تاريخ الشراء.";
        }

        if (empty($purchase_date_err) && $supplier_id > 0) {
            $sql_insert = "INSERT INTO purchase_invoices (supplier_id, supplier_invoice_number, purchase_date, notes, status, total_amount, created_by)
                           VALUES (?, ?, ?, ?, ?, ?, ?)";
            if ($stmt_insert = $conn->prepare($sql_insert)) {
                $stmt_insert->bind_param("issssdi", $supplier_id, $supplier_invoice_number, $purchase_date, $notes, $status, $total_amount, $created_by);
                if ($stmt_insert->execute()) {
                    $new_purchase_invoice_id = $stmt_insert->insert_id;
                    $_SESSION['message'] = "<div class='alert alert-success'>تم إنشاء فاتورة المشتريات رقم #{$new_purchase_invoice_id} بنجاح. يمكنك الآن إضافة البنود.</div>";
                    header("Location: " . BASE_URL . "admin/view_purchase_invoice.php?id=" . $new_purchase_invoice_id);
                    exit;
                } else {
                    $message = "<div class='alert alert-danger'>حدث خطأ أثناء إنشاء الفاتورة: " . $stmt_insert->error . "</div>";
                }
                $stmt_insert->close();
            } else {
                $message = "<div class='alert alert-danger'>خطأ في تحضير الاستعلام: " . $conn->error . "</div>";
            }
        } else {
            if (empty($message)) $message = "<div class='alert alert-danger'>الرجاء إصلاح الأخطاء في النموذج.</div>";
            if ($supplier_id > 0) {
                $sql_get_supplier_name = "SELECT name FROM suppliers WHERE id = ?";
                if ($stmt_name = $conn->prepare($sql_get_supplier_name)) {
                    $stmt_name->bind_param("i", $supplier_id);
                    $stmt_name->execute();
                    $result_name = $stmt_name->get_result();
                    if ($row_name = $result_name->fetch_assoc()) {
                        $supplier_name = $row_name['name'];
                    }
                    $stmt_name->close();
                }
            }
        }
    }
}
// --- جلب بيانات المورد عند الوصول من manage_suppliers.php ---
elseif ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['supplier_id']) && !isset($_POST['save_purchase_invoice'])) {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['message'] = "<div class='alert alert-danger'>خطأ: طلب غير صالح.</div>";
        header("Location: " . BASE_URL . "admin/manage_suppliers.php");
        exit;
    }
    $supplier_id = intval($_POST['supplier_id']);
    if ($supplier_id > 0) {
        $sql_get_supplier = "SELECT name FROM suppliers WHERE id = ?";
        if ($stmt_get = $conn->prepare($sql_get_supplier)) {
            $stmt_get->bind_param("i", $supplier_id);
            if ($stmt_get->execute()) {
                $result_get = $stmt_get->get_result();
                if ($row_get = $result_get->fetch_assoc()) {
                    $supplier_name = $row_get['name'];
                } else {
                    $_SESSION['message'] = "<div class='alert alert-danger'>المورد غير موجود.</div>";
                    header("Location: " . BASE_URL . "admin/manage_suppliers.php");
                    exit;
                }
            }
            $stmt_get->close();
        }
    } else {
        $_SESSION['message'] = "<div class='alert alert-warning'>لم يتم تحديد مورد بشكل صحيح.</div>";
        header("Location: " . BASE_URL . "admin/manage_suppliers.php");
        exit;
    }
} else {
    if (empty($message)) {
        $_SESSION['message'] = "<div class='alert alert-warning'>الرجاء اختيار مورد من <a href='" . BASE_URL . "admin/manage_suppliers.php'>قائمة الموردين</a> أولاً.</div>";
        header("Location: " . BASE_URL . "admin/manage_suppliers.php");
        exit;
    }
}

require_once BASE_DIR . 'partials/header.php';
require_once BASE_DIR . 'partials/sidebar.php';
?>

<div class="container mt-5 pt-3">
    <div class="row justify-content-center">
        <div class="col-md-8 col-lg-7">
            <div class="card shadow-sm">
                <div class="card-header bg-success text-white text-center">
                    <h2><i class="fas fa-file-import"></i> إنشاء فاتورة مشتريات جديدة</h2>
                </div>
                <div class="card-body p-4">
                    <?php echo $message; ?>
                    <?php if ($supplier_id > 0): ?>
                    <h4 class="mb-3">المورد: <span class="text-primary fw-bold"><?php echo htmlspecialchars($supplier_name); ?></span></h4>
                    <hr>
                    <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="supplier_id" value="<?php echo $supplier_id; ?>">

                        <div class="mb-3">
                            <label for="supplier_invoice_number" class="form-label">رقم فاتورة المورد (اختياري):</label>
                            <input type="text" name="supplier_invoice_number" id="supplier_invoice_number" class="form-control" value="<?php echo htmlspecialchars($supplier_invoice_number); ?>">
                        </div>

                        <div class="mb-3">
                            <label for="purchase_date" class="form-label">تاريخ الشراء:</label>
                            <input type="date" name="purchase_date" id="purchase_date" class="form-control <?php echo (!empty($purchase_date_err)) ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($purchase_date); ?>" required>
                            <span class="invalid-feedback"><?php echo $purchase_date_err; ?></span>
                        </div>

                        <div class="mb-3">
                            <label for="notes" class="form-label">ملاحظات (اختياري):</label>
                            <textarea name="notes" id="notes" class="form-control" rows="3"><?php echo htmlspecialchars($notes); ?></textarea>
                        </div>

                        <div class="d-grid gap-2 d-md-flex justify-content-md-end mt-4">
                             <a href="<?php echo BASE_URL; ?>admin/manage_suppliers.php" class="btn btn-secondary me-md-2">إلغاء والعودة للموردين</a>
                            <button type="submit" name="save_purchase_invoice" class="btn btn-success"><i class="fas fa-save"></i> حفظ وبدء إضافة البنود</button>
                        </div>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
$conn->close();
require_once BASE_DIR . 'partials/footer.php';
?>
