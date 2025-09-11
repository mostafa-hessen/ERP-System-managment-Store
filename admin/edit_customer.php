<?php
// edit_customer.php
$page_title = "تعديل بيانات العميل";
$class_dashboard = "active";
require_once dirname(__DIR__) . '/config.php';
require_once BASE_DIR . 'partials/session_admin.php';
require_once BASE_DIR . 'partials/header.php';

// دالة خروج آمن
function e($s) { return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

// لأنك أضفت العمود صراحةً
$notes_col = 'notes';

// تهيئة متغيرات
$message = "";
$name_err = $mobile_err = $city_err = "";
$customer_id = $name = $mobile = $city = $address = $created_by = $notes_value = "";
$can_edit = false;

// توكن CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

/* ============================
   معالجة تحديث بيانات العميل
   ============================ */
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_customer'])) {

    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $message = "<div class='alert alert-danger'>خطأ: طلب غير صالح (CSRF).</div>";
    } else {
        $customer_id = intval($_POST['customer_id']);

        // تحقق الصلاحية: من أنشأه أو أدمِن
        $sql_auth = "SELECT created_by FROM customers WHERE id = ?";
        if ($stmt_auth = $conn->prepare($sql_auth)) {
            $stmt_auth->bind_param("i", $customer_id);
            $stmt_auth->execute();
            $stmt_auth->bind_result($fetched_created_by);
            if ($stmt_auth->fetch()) {
                if ($fetched_created_by == ($_SESSION['id'] ?? 0) || ($_SESSION['role'] ?? '') === 'admin') {
                    $can_edit = true;
                }
            }
            $stmt_auth->close();
        }

        if (!$can_edit) {
            $message = "<div class='alert alert-danger'>ليس لديك الصلاحية لتعديل هذا العميل.</div>";
        } else {
            // جلب القيم من POST وتنقيتها
            $name = trim((string)($_POST['name'] ?? ''));
            $mobile = trim((string)($_POST['mobile'] ?? ''));
            $city = trim((string)($_POST['city'] ?? ''));
            $address = trim((string)($_POST['address'] ?? ''));
            $notes_value = trim((string)($_POST['notes'] ?? ''));

            // تحقق بسيط
            if ($name === '') { $name_err = "الرجاء إدخال اسم العميل."; }
            if ($city === '') { $city_err = "الرجاء إدخال المدينة."; }
            if ($mobile === '') {
                $mobile_err = "الرجاء إدخال رقم الموبايل.";
            } elseif (!preg_match('/^[0-9]{11}$/', $mobile)) {
                $mobile_err = "يجب أن يتكون رقم الموبايل من 11 رقمًا بالضبط.";
            } else {
                // تحقق تفرد الموبايل باستثناء هذا العميل
                $sql_check_mobile = "SELECT id FROM customers WHERE mobile = ? AND id != ?";
                if ($stmt_check = $conn->prepare($sql_check_mobile)) {
                    $stmt_check->bind_param("si", $mobile, $customer_id);
                    $stmt_check->execute();
                    $stmt_check->store_result();
                    if ($stmt_check->num_rows > 0) {
                        $mobile_err = "رقم الموبايل هذا مسجل بالفعل لعميل آخر.";
                    }
                    $stmt_check->close();
                }
            }

            if (empty($name_err) && empty($mobile_err) && empty($city_err)) {
                // نحدّث مع حقل notes (لأنك ضفته)
                $sql_update = "UPDATE customers SET name = ?, mobile = ?, city = ?, address = ?, `{$notes_col}` = ? WHERE id = ?";
                if ($stmt_update = $conn->prepare($sql_update)) {
                    $stmt_update->bind_param("sssssi", $name, $mobile, $city, $address, $notes_value, $customer_id);
                    if ($stmt_update->execute()) {
                        $_SESSION['message'] = "<div class='alert alert-success'>تم تحديث بيانات العميل بنجاح.</div>";
                        $redirect_url = (($_SESSION['role'] ?? '') === 'admin') ? BASE_URL . 'admin/manage_customer.php' : BASE_URL . 'customer/show_customer.php';
                        header("Location: " . $redirect_url);
                        exit;
                    } else {
                        $message = "<div class='alert alert-danger'>حدث خطأ أثناء التحديث: " . e($stmt_update->error) . "</div>";
                    }
                    $stmt_update->close();
                } else {
                    $message = "<div class='alert alert-danger'>خطأ في تحضير استعلام التحديث: " . e($conn->error) . "</div>";
                }
            } else {
                $message = "<div class='alert alert-danger'>الرجاء إصلاح الأخطاء أدناه.</div>";
            }
        }
    }
}

/* =================================
   جلب بيانات العميل للعرض في النموذج
   (يأتي عادةً عبر POST من صفحة manage/show)
   ================================= */
elseif ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['customer_id_to_edit'])) {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die("خطأ: طلب غير صالح (CSRF).");
    }

    $customer_id = intval($_POST['customer_id_to_edit']);

    $sql_select = "SELECT name, mobile, city, address, created_by, `{$notes_col}` AS notes FROM customers WHERE id = ?";
    if ($stmt_select = $conn->prepare($sql_select)) {
        $stmt_select->bind_param("i", $customer_id);
        if ($stmt_select->execute()) {
            $stmt_select->bind_result($name, $mobile, $city, $address, $created_by, $notes_value);
            if ($stmt_select->fetch()) {
                // صلاحية العرض/التعديل
                if ($created_by == ($_SESSION['id'] ?? 0) || ($_SESSION['role'] ?? '') == 'admin') {
                    $can_edit = true;
                } else {
                    $message = "<div class='alert alert-danger'>ليس لديك الصلاحية لعرض أو تعديل هذا العميل.</div>";
                    $customer_id = "";
                }
            } else {
                $message = "<div class='alert alert-danger'>لم يتم العثور على العميل المطلوب.</div>";
                $customer_id = "";
            }
        } else {
            $message = "<div class='alert alert-danger'>حدث خطأ أثناء جلب بيانات العميل.</div>";
            $customer_id = "";
        }
        $stmt_select->close();
    } else {
        $message = "<div class='alert alert-danger'>خطأ في تحضير استعلام الجلب: " . e($conn->error) . "</div>";
        $customer_id = "";
    }
} else {
    // إعادة التوجيه في حالات غير متوقعة
    header("location: " . (($_SESSION['role'] == 'admin') ? BASE_URL . 'admin/manage_customer.php' : BASE_URL . 'customer/show_customer.php'));
    exit;
}

// تضمين السايدبار لو لم يكن مضمّن
require_once BASE_DIR . 'partials/sidebar.php';
?>

<div class="container mt-5 pt-3">
    <h1><i class="fas fa-user-edit"></i> تعديل بيانات العميل</h1>
    <p>قم بتغيير بيانات العميل أدناه ثم اضغط على "تحديث".</p>

    <?php echo $message; ?>

    <?php if (!empty($customer_id) && $can_edit): ?>
        <div class="card shadow-sm">
            <div class="card-header">
                بيانات العميل: <?php echo e($name); ?> (ID: <?php echo e($customer_id); ?>)
            </div>
            <div class="card-body p-4">
                <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post" class="row g-3">
                    <input type="hidden" name="customer_id" value="<?php echo e($customer_id); ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">

                    <div class="col-md-6">
                        <label for="name" class="form-label"><i class="fas fa-user"></i> اسم العميل:</label>
                        <input type="text" name="name" id="name" class="form-control <?php echo (!empty($name_err)) ? 'is-invalid' : ''; ?>" value="<?php echo e($name); ?>">
                        <div class="invalid-feedback"><?php echo e($name_err); ?></div>
                    </div>

                    <div class="col-md-6">
                        <label for="mobile" class="form-label"><i class="fas fa-mobile-alt"></i> رقم الموبايل (11 رقم):</label>
                        <input type="tel" name="mobile" id="mobile" class="form-control <?php echo (!empty($mobile_err)) ? 'is-invalid' : ''; ?>" value="<?php echo e($mobile); ?>" pattern="[0-9]{11}" title="يجب إدخال 11 رقماً">
                        <div class="invalid-feedback"><?php echo e($mobile_err); ?></div>
                    </div>

                    <div class="col-md-6">
                        <label for="city" class="form-label"><i class="fas fa-city"></i> المدينة:</label>
                        <input type="text" name="city" id="city" class="form-control <?php echo (!empty($city_err)) ? 'is-invalid' : ''; ?>" value="<?php echo e($city); ?>">
                        <div class="invalid-feedback"><?php echo e($city_err); ?></div>
                    </div>

                    <div class="col-12">
                        <label for="address" class="form-label"><i class="fas fa-map-marker-alt"></i> العنوان (اختياري):</label>
                        <textarea name="address" id="address" class="form-control" rows="2"><?php echo e($address); ?></textarea>
                    </div>

                    <!-- حقل الملاحظات -->
                    <div class="col-12">
                        <label for="notes" class="form-label"><i class="fas fa-sticky-note"></i> ملاحظات عن العميل</label>
                        <textarea name="notes" id="notes" class="form-control" rows="4"><?php echo e($notes_value); ?></textarea>
                    </div>

                    <div class="col-12 text-end mt-2">
                        <button type="submit" name="update_customer" class="btn btn-primary"><i class="fas fa-save"></i> تحديث</button>
                        <a href="<?php echo (($_SESSION['role'] == 'admin') ? BASE_URL .'admin/manage_customer.php' : BASE_URL .'customer/show_customer.php'); ?>" class="btn btn-secondary">إلغاء</a>
                    </div>
                </form>
            </div>
        </div>
    <?php elseif (empty($message)): ?>
        <div class='alert alert-warning'>لم يتم تحديد عميل للتعديل أو ليس لديك الصلاحية. <a href="<?php echo (($_SESSION['role'] == 'admin') ? BASE_URL .'admin/manage_customer.php' : BASE_URL .'customer/show_customer.php'); ?>">العودة</a>.</div>
    <?php endif; ?>
</div>

<?php
$conn->close();
require_once BASE_DIR . 'partials/footer.php';
?>
