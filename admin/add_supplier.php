<?php
$page_title = "إضافة مورد جديد";
$class_dashboard = "active";
require_once dirname(__DIR__) . '/config.php';
require_once BASE_DIR . 'partials/session_admin.php'; // صلاحيات المدير فقط
require_once BASE_DIR . 'partials/sidebar.php';


// تعريف المتغيرات
$name = $mobile = $city = $address = $commercial_register = "";
$name_err = $mobile_err = $city_err = $commercial_register_err = "";
$message = "";

// جلب توكن CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// معالجة النموذج عند الإرسال
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_supplier'])) {
    // التحقق من CSRF
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $message = "<div class='alert alert-danger'>خطأ: طلب غير صالح (CSRF).</div>";
    } else {
        // التحقق من الاسم
        if (empty(trim($_POST["name"]))) {
            $name_err = "الرجاء إدخال اسم المورد.";
        } else {
            $name = trim($_POST["name"]);
        }

        // التحقق من الموبايل
        if (empty(trim($_POST["mobile"]))) {
            $mobile_err = "الرجاء إدخال رقم الموبايل.";
        } elseif (!preg_match('/^[0-9]{11}$/', trim($_POST["mobile"]))) {
            $mobile_err = "يجب أن يتكون رقم الموبايل من 11 رقمًا بالضبط.";
        } else {
            $mobile_check = trim($_POST["mobile"]);
            $sql_check_mobile = "SELECT id FROM suppliers WHERE mobile = ?";
            if ($stmt_check_mobile = $conn->prepare($sql_check_mobile)) {
                $stmt_check_mobile->bind_param("s", $mobile_check);
                $stmt_check_mobile->execute();
                $stmt_check_mobile->store_result();
                if ($stmt_check_mobile->num_rows > 0) {
                    $mobile_err = "رقم الموبايل هذا مسجل لمورد آخر بالفعل.";
                } else {
                    $mobile = $mobile_check;
                }
                $stmt_check_mobile->close();
            } else {
                $message = "<div class='alert alert-danger'>خطأ في التحقق من رقم الموبايل.</div>";
            }
        }

        // التحقق من المدينة
        if (empty(trim($_POST["city"]))) {
            $city_err = "الرجاء إدخال مدينة المورد.";
        } else {
            $city = trim($_POST["city"]);
        }

        // السجل التجاري (اختياري ولكن فريد إذا أدخل)
        if (!empty(trim($_POST["commercial_register"]))) {
            $commercial_register_check = trim($_POST["commercial_register"]);
            $sql_check_cr = "SELECT id FROM suppliers WHERE commercial_register = ?";
            if ($stmt_check_cr = $conn->prepare($sql_check_cr)) {
                $stmt_check_cr->bind_param("s", $commercial_register_check);
                $stmt_check_cr->execute();
                $stmt_check_cr->store_result();
                if ($stmt_check_cr->num_rows > 0) {
                    $commercial_register_err = "رقم السجل التجاري هذا مسجل لمورد آخر بالفعل.";
                } else {
                    $commercial_register = $commercial_register_check;
                }
                $stmt_check_cr->close();
            } else {
                 $message = "<div class='alert alert-danger'>خطأ في التحقق من السجل التجاري.</div>";
            }
        } else {
            $commercial_register = null; // إذا كان فارغاً، اجعله NULL
        }


        // العنوان (اختياري)
        $address = trim($_POST["address"]);
        $created_by = $_SESSION['id']; // المستخدم الحالي هو من أضافه

        // التحقق من عدم وجود أخطاء قبل الإدراج
        if (empty($name_err) && empty($mobile_err) && empty($city_err) && empty($commercial_register_err) && empty($message)) {
            $sql_insert = "INSERT INTO suppliers (name, mobile, city, address, commercial_register, created_by) VALUES (?, ?, ?, ?, ?, ?)";
            if ($stmt_insert = $conn->prepare($sql_insert)) {
                $stmt_insert->bind_param("sssssi", $name, $mobile, $city, $address, $commercial_register, $created_by);
                if ($stmt_insert->execute()) {
                    $_SESSION['message'] = "<div class='alert alert-success'>تم إضافة المورد \"".htmlspecialchars($name)."\" بنجاح!</div>";
                    header("Location: manage_suppliers.php"); // توجيه لصفحة إدارة الموردين
                    exit;
                } else {
                    $message = "<div class='alert alert-danger'>حدث خطأ أثناء إضافة المورد: " . $stmt_insert->error . "</div>";
                }
                $stmt_insert->close();
            } else {
                 $message = "<div class='alert alert-danger'>خطأ في تحضير الاستعلام: " . $conn->error . "</div>";
            }
        } else {
             if (empty($message)) {
                $message = "<div class='alert alert-danger'>الرجاء إصلاح الأخطاء في النموذج.</div>";
             }
        }
    }
}

require_once BASE_DIR . 'partials/header.php';
require_once BASE_DIR . 'partials/sidebar.php';
?>

<div class="container mt-5 pt-3">
    <div class="row justify-content-center">
        <div class="col-md-8 col-lg-7">
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white text-center">
                    <h2><i class="fas fa-truck-loading"></i> إضافة مورد جديد</h2>
                </div>
                <div class="card-body p-4">
                    <?php echo $message; ?>
                    <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">

                        <div class="mb-3">
                            <label for="name" class="form-label"><i class="fas fa-user-tie"></i> اسم المورد:</label>
                            <input type="text" name="name" id="name" class="form-control <?php echo (!empty($name_err)) ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($name); ?>" required>
                            <span class="invalid-feedback"><?php echo $name_err; ?></span>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="mobile" class="form-label"><i class="fas fa-mobile-alt"></i> رقم الموبايل (11 رقم):</label>
                                <input type="tel" name="mobile" id="mobile" class="form-control <?php echo (!empty($mobile_err)) ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($mobile); ?>" pattern="[0-9]{11}" title="يجب إدخال 11 رقماً" required>
                                <span class="invalid-feedback"><?php echo $mobile_err; ?></span>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="city" class="form-label"><i class="fas fa-city"></i> المدينة:</label>
                                <input type="text" name="city" id="city" class="form-control <?php echo (!empty($city_err)) ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($city); ?>" required>
                                <span class="invalid-feedback"><?php echo $city_err; ?></span>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="address" class="form-label"><i class="fas fa-map-marker-alt"></i> العنوان (اختياري):</label>
                            <textarea name="address" id="address" class="form-control" rows="3"><?php echo htmlspecialchars($address); ?></textarea>
                        </div>

                        <div class="mb-3">
                            <label for="commercial_register" class="form-label"><i class="fas fa-id-card"></i> السجل التجاري (اختياري):</label>
                            <input type="text" name="commercial_register" id="commercial_register" class="form-control <?php echo (!empty($commercial_register_err)) ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($commercial_register); ?>">
                            <span class="invalid-feedback"><?php echo $commercial_register_err; ?></span>
                        </div>

                        <div class="d-grid gap-2 d-md-flex justify-content-md-end mt-4">
                             <a href="manage_suppliers.php" class="btn btn-secondary me-md-2">إلغاء</a>
                            <button type="submit" name="add_supplier" class="btn btn-primary"><i class="fas fa-plus-circle"></i> إضافة المورد</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
$conn->close();
require_once BASE_DIR . 'partials/footer.php';
?>