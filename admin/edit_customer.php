<?php
$page_title = "تعديل بيانات العميل"; // تحديد عنوان الصفحة
$class_dashboard = "active";
require_once dirname(__DIR__) . '/config.php';
require_once BASE_DIR . 'partials/session_admin.php';
require_once BASE_DIR . 'partials/header.php';
require_once BASE_DIR . 'partials/sidebar.php';

// تعريف المتغيرات
$message = "";
$name_err = $mobile_err = $city_err = "";
$customer_id = $name = $mobile = $city = $address = $created_by = "";
$can_edit = false; // هل المستخدم مصرح له بالتعديل؟

// جلب توكن CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// --- 6. معالجة طلب التحديث ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_customer'])) {
    // التحقق من CSRF
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $message = "<div class='alert alert-danger'>خطأ: طلب غير صالح (CSRF detected).</div>";
    } else {
        $customer_id = intval($_POST['customer_id']);

        // --- !! التحقق من الصلاحية قبل التحديث !! ---
        $sql_auth = "SELECT created_by FROM customers WHERE id = ?";
        if($stmt_auth = $conn->prepare($sql_auth)){
            $stmt_auth->bind_param("i", $customer_id);
            $stmt_auth->execute();
            $stmt_auth->bind_result($fetched_created_by);
            if($stmt_auth->fetch() && ($fetched_created_by == $_SESSION['id'] || $_SESSION['role'] == 'admin')){
                $can_edit = true;
            }
            $stmt_auth->close();
        }

        if(!$can_edit) {
             $message = "<div class='alert alert-danger'>ليس لديك الصلاحية لتعديل هذا العميل.</div>";
        } else {
            // جلب وتنقية البيانات
            $name = trim($_POST["name"]);
            $mobile = trim($_POST["mobile"]);
            $city = trim($_POST["city"]);
            $address = trim($_POST["address"]);

            // --- التحقق من صحة البيانات ---
            if (empty($name)) { $name_err = "الرجاء إدخال اسم العميل."; }
            if (empty($city)) { $city_err = "الرجاء إدخال المدينة."; }
            if (empty($mobile)) {
                $mobile_err = "الرجاء إدخال رقم الموبايل.";
            } elseif (!preg_match('/^[0-9]{11}$/', $mobile)) {
                $mobile_err = "يجب أن يتكون رقم الموبايل من 11 رقمًا بالضبط.";
            } else {
                // التحقق من أن رقم الموبايل فريد (باستثناء هذا العميل)
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

            // إذا لم يكن هناك أخطاء، قم بالتحديث
            if (empty($name_err) && empty($mobile_err) && empty($city_err)) {
                $sql_update = "UPDATE customers SET name = ?, mobile = ?, city = ?, address = ? WHERE id = ?";
                if ($stmt_update = $conn->prepare($sql_update)) {
                    $stmt_update->bind_param("ssssi", $name, $mobile, $city, $address, $customer_id);
                    if ($stmt_update->execute()) {
                        // تخزين رسالة النجاح في الجلسة
                        $_SESSION['message'] = "<div class='alert alert-success'>تم تحديث بيانات العميل بنجاح.</div>";
                                        
                        // تحديد صفحة العودة بناءً على دور المستخدم
                        $redirect_url = ($_SESSION['role'] == 'admin' ? 'manage_customer.php' : 'show_customer.php');
                                        
                        // إعادة التوجيه
                        header("Location: " . $redirect_url);
                        exit; // مهم جداً: إيقاف تنفيذ الكود بعد إعادة التوجيه
                                        
                    } else {
                        $message = "<div class='alert alert-danger'>حدث خطأ أثناء التحديث: " . $stmt_update->error . "</div>";
                    }
                    $stmt_update->close();
                } else {
                    $message = "<div class='alert alert-danger'>خطأ في تحضير التحديث: " . $conn->error . "</div>";
                }
            } else {
                $message = "<div class='alert alert-danger'>الرجاء إصلاح الأخطاء أدناه.</div>";
            }
        }
    }
}
// --- 2. جلب بيانات المستخدم لعرضها (إذا لم يكن طلب تحديث) ---
elseif ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['customer_id_to_edit'])) {
    // التحقق من CSRF القادم من manage/show_customer.php
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die("خطأ: طلب غير صالح (CSRF detected).");
    }

    $customer_id = intval($_POST['customer_id_to_edit']);
    $sql_select = "SELECT name, mobile, city, address, created_by FROM customers WHERE id = ?";
    if ($stmt_select = $conn->prepare($sql_select)) {
        $stmt_select->bind_param("i", $customer_id);
        if ($stmt_select->execute()) {
            $stmt_select->bind_result($name, $mobile, $city, $address, $created_by);
            if ($stmt_select->fetch()) {
                 // --- !! التحقق من الصلاحية عند العرض !! ---
                if($created_by == $_SESSION['id'] || $_SESSION['role'] == 'admin'){
                    $can_edit = true;
                } else {
                    $message = "<div class='alert alert-danger'>ليس لديك الصلاحية لعرض أو تعديل هذا العميل.</div>";
                    $customer_id = ""; // منع عرض النموذج
                }
            } else {
                $message = "<div class='alert alert-danger'>لم يتم العثور على العميل المطلوب.</div>";
                $customer_id = "";
            }
        } else {
            $message = "<div class='alert alert-danger'>خطأ أثناء جلب بيانات العميل.</div>";
            $customer_id = "";
        }
        $stmt_select->close();
    } else {
        $message = "<div class='alert alert-danger'>خطأ في تحضير استعلام الجلب.</div>";
        $customer_id = "";
    }
}
// --- إذا لم يكن هناك طلب صالح ---
else {
    // إذا لم يكن هناك طلب POST صالح، أعد التوجيه
    header("location: " . ($_SESSION['role'] == 'admin' ? 'manage_customer.php' : 'show_customer.php'));
    exit;
}

?>

<div class="container mt-5 pt-3">
    <h1><i class="fas fa-user-edit"></i> تعديل بيانات العميل</h1>
    <p>قم بتغيير بيانات العميل أدناه ثم اضغط على "تحديث".</p>

    <?php echo $message; // عرض رسائل الحالة ?>

    <?php // عرض النموذج فقط إذا كان لدينا customer_id ومصرح لنا ?>
    <?php if (!empty($customer_id) && $can_edit): ?>
        <div class="card shadow-sm">
             <div class="card-header">
                بيانات العميل: <?php echo htmlspecialchars($name); ?> (ID: <?php echo $customer_id; ?>)
            </div>
            <div class="card-body p-4">
                <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
                    <input type="hidden" name="customer_id" value="<?php echo $customer_id; ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">

                    <div class="mb-3">
                        <label for="name" class="form-label"><i class="fas fa-user"></i> اسم العميل:</label>
                        <input type="text" name="name" id="name" class="form-control <?php echo (!empty($name_err)) ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($name); ?>">
                        <span class="invalid-feedback"><?php echo $name_err; ?></span>
                    </div>

                    <div class="mb-3">
                        <label for="mobile" class="form-label"><i class="fas fa-mobile-alt"></i> رقم الموبايل (11 رقم):</label>
                        <input type="tel" name="mobile" id="mobile" class="form-control <?php echo (!empty($mobile_err)) ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($mobile); ?>" pattern="[0-9]{11}" title="يجب إدخال 11 رقماً">
                        <span class="invalid-feedback"><?php echo $mobile_err; ?></span>
                    </div>

                    <div class="mb-3">
                        <label for="city" class="form-label"><i class="fas fa-city"></i> المدينة:</label>
                        <input type="text" name="city" id="city" class="form-control <?php echo (!empty($city_err)) ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($city); ?>">
                        <span class="invalid-feedback"><?php echo $city_err; ?></span>
                    </div>

                    <div class="mb-3">
                        <label for="address" class="form-label"><i class="fas fa-map-marker-alt"></i> العنوان (اختياري):</label>
                        <textarea name="address" id="address" class="form-control" rows="3"><?php echo htmlspecialchars($address); ?></textarea>
                    </div>

                    <button type="submit" name="update_customer" class="btn btn-primary"><i class="fas fa-save"></i> تحديث</button>
                    <a href="<?php echo ($_SESSION['role'] == 'admin' ?  BASE_URL .'admin/manage_customer.php' : BASE_URL .'customer/show_customer.php'); ?>" class="btn btn-secondary">إلغاء</a>
                </form>
            </div>
        </div>
    <?php elseif(empty($message)) : ?>
         <div class='alert alert-warning'>لم يتم تحديد عميل للتعديل أو ليس لديك الصلاحية. <a href="<?php echo ($_SESSION['role'] == 'admin' ? 'manage_customer.php' : 'show_customer.php'); ?>">العودة</a>.</div>
    <?php endif; ?>
</div>

<?php $conn->close();?>

<?php require_once BASE_DIR . 'partials/footer.php'; ?>