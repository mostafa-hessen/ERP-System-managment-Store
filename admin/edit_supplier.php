<?php
$page_title = "تعديل بيانات المورد";
$class_dashboard = "active";
require_once dirname(__DIR__) . '/config.php';
require_once BASE_DIR . 'partials/session_admin.php'; // صلاحيات المدير فقط

// تعريف المتغيرات
$supplier_id = 0;
$name = $mobile = $city = $address = $commercial_register = "";
$name_err = $mobile_err = $city_err = $commercial_register_err = "";
$message = "";

// جلب توكن CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// --- معالجة طلب التحديث (عند إرسال النموذج) ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_supplier'])) {
    // التحقق من CSRF
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $message = "<div class='alert alert-danger'>خطأ: طلب غير صالح (CSRF detected).</div>";
    } else {
        $supplier_id = intval($_POST['supplier_id']); // من الحقل المخفي

        // جلب وتنقية البيانات
        $name_posted = trim($_POST["name"]);
        $mobile_posted = trim($_POST["mobile"]);
        $city_posted = trim($_POST["city"]);
        $address_posted = trim($_POST["address"]);
        $commercial_register_posted = trim($_POST["commercial_register"]);
        // $updated_by = $_SESSION['id']; // إذا كان لديك عمود updated_by في جدول suppliers

        // --- التحقق من صحة البيانات ---
        if (empty($name_posted)) { $name_err = "الرجاء إدخال اسم المورد."; }
        else { $name = $name_posted; }

        if (empty($mobile_posted)) { $mobile_err = "الرجاء إدخال رقم الموبايل."; }
        elseif (!preg_match('/^[0-9]{11}$/', $mobile_posted)) { $mobile_err = "يجب أن يتكون رقم الموبايل من 11 رقمًا بالضبط."; }
        else {
            // التحقق من أن رقم الموبايل فريد (باستثناء هذا المورد نفسه)
            $sql_check_mobile = "SELECT id FROM suppliers WHERE mobile = ? AND id != ?";
            if ($stmt_check_mobile = $conn->prepare($sql_check_mobile)) {
                $stmt_check_mobile->bind_param("si", $mobile_posted, $supplier_id);
                $stmt_check_mobile->execute();
                $stmt_check_mobile->store_result();
                if ($stmt_check_mobile->num_rows > 0) {
                    $mobile_err = "رقم الموبايل هذا مسجل لمورد آخر بالفعل.";
                } else {
                    $mobile = $mobile_posted;
                }
                $stmt_check_mobile->close();
            } else { $message = "<div class='alert alert-danger'>خطأ في التحقق من رقم الموبايل.</div>"; }
        }

        if (empty($city_posted)) { $city_err = "الرجاء إدخال مدينة المورد."; }
        else { $city = $city_posted; }

        // السجل التجاري (اختياري ولكن فريد إذا أدخل، باستثناء هذا المورد)
        if (!empty($commercial_register_posted)) {
            $sql_check_cr = "SELECT id FROM suppliers WHERE commercial_register = ? AND id != ?";
            if ($stmt_check_cr = $conn->prepare($sql_check_cr)) {
                $stmt_check_cr->bind_param("si", $commercial_register_posted, $supplier_id);
                $stmt_check_cr->execute();
                $stmt_check_cr->store_result();
                if ($stmt_check_cr->num_rows > 0) {
                    $commercial_register_err = "رقم السجل التجاري هذا مسجل لمورد آخر بالفعل.";
                } else {
                    $commercial_register = $commercial_register_posted;
                }
                $stmt_check_cr->close();
            } else { $message = "<div class='alert alert-danger'>خطأ في التحقق من السجل التجاري.</div>"; }
        } else {
            $commercial_register = null; // إذا كان فارغاً، اجعله NULL
        }
        $address = $address_posted; // العنوان اختياري

        // إذا لم يكن هناك أخطاء، قم بالتحديث
        if (empty($name_err) && empty($mobile_err) && empty($city_err) && empty($commercial_register_err) && empty($message)) {
            // ملاحظة: updated_at يتم تحديثه تلقائياً بواسطة قاعدة البيانات
            // إذا أضفت عمود updated_by ستحتاج لتضمينه في الاستعلام
            $sql_update = "UPDATE suppliers SET name = ?, mobile = ?, city = ?, address = ?, commercial_register = ? WHERE id = ?";
            if ($stmt_update = $conn->prepare($sql_update)) {
                $stmt_update->bind_param("sssssi", $name, $mobile, $city, $address, $commercial_register, $supplier_id);
                if ($stmt_update->execute()) {
                    $_SESSION['message'] = "<div class='alert alert-success'>تم تحديث بيانات المورد \"".htmlspecialchars($name)."\" بنجاح!</div>";
                    header("Location: manage_suppliers.php");
                    exit;
                } else {
                    $message = "<div class='alert alert-danger'>حدث خطأ أثناء تحديث المورد: " . $stmt_update->error . "</div>";
                }
                $stmt_update->close();
            } else {
                 $message = "<div class='alert alert-danger'>خطأ في تحضير استعلام التحديث: " . $conn->error . "</div>";
            }
        } else {
             if (empty($message)) {
                $message = "<div class='alert alert-danger'>الرجاء إصلاح الأخطاء في النموذج.</div>";
             }
        }
    }
}
// --- جلب بيانات المورد للعرض (إذا لم يكن طلب تحديث أو فشل التحديث) ---
elseif (($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['supplier_id_to_edit'])) || ($_SERVER["REQUEST_METHOD"] == "GET" && isset($_GET['id']))) {

    if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['supplier_id_to_edit'])) {
        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            // بدلاً من die، يمكنك توجيه المستخدم أو عرض رسالة خطأ أكثر لطفاً
             $_SESSION['message'] = "<div class='alert alert-danger'>خطأ: طلب غير صالح (CSRF).</div>";
             header("Location: manage_suppliers.php");
             exit;
        }
        $supplier_id = intval($_POST['supplier_id_to_edit']);
    } elseif ($_SERVER["REQUEST_METHOD"] == "GET" && isset($_GET['id'])) {
        $supplier_id = intval($_GET['id']);
    }

    if ($supplier_id > 0) {
        $sql_fetch = "SELECT name, mobile, city, address, commercial_register FROM suppliers WHERE id = ?";
        if ($stmt_fetch = $conn->prepare($sql_fetch)) {
            $stmt_fetch->bind_param("i", $supplier_id);
            if ($stmt_fetch->execute()) {
                $stmt_fetch->bind_result($name, $mobile, $city, $address, $commercial_register);
                if (!$stmt_fetch->fetch()) {
                    $_SESSION['message'] = "<div class='alert alert-danger'>لم يتم العثور على المورد المطلوب (ID: {$supplier_id}).</div>";
                    header("Location: manage_suppliers.php");
                    exit;
                }
            } else {
                $_SESSION['message'] = "<div class='alert alert-danger'>خطأ أثناء جلب بيانات المورد.</div>";
                header("Location: manage_suppliers.php");
                exit;
            }
            $stmt_fetch->close();
        } else {
            $_SESSION['message'] = "<div class='alert alert-danger'>خطأ في تحضير استعلام جلب المورد: " . $conn->error . "</div>";
            header("Location: manage_suppliers.php");
            exit;
        }
    } else {
        $_SESSION['message'] = "<div class='alert alert-warning'>رقم المورد غير صالح.</div>";
        header("Location: manage_suppliers.php");
        exit;
    }
} else {
    $_SESSION['message'] = "<div class='alert alert-warning'>لم يتم تحديد مورد للتعديل.</div>";
    header("Location: manage_suppliers.php");
    exit;
}

require_once BASE_DIR . 'partials/header.php';
require_once BASE_DIR . 'partials/navbar.php';
?>

<div class="container mt-5 pt-3">
    <div class="row justify-content-center">
        <div class="col-md-8 col-lg-7">
            <?php if ($supplier_id > 0) : // اعرض النموذج فقط إذا كان ID المورد صالحاً ?>
            <div class="card shadow-sm">
                <div class="card-header bg-warning text-dark text-center">
                    <h2><i class="fas fa-user-edit"></i> تعديل بيانات المورد (ID: <?php echo $supplier_id; ?>)</h2>
                </div>
                <div class="card-body p-4">
                    <?php echo $message; ?>
                    <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>?id=<?php echo $supplier_id; // للحفاظ على ID في الرابط عند فشل التحقق ?>" method="post">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="supplier_id" value="<?php echo $supplier_id; ?>">

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
                            <input type="text" name="commercial_register" id="commercial_register" class="form-control <?php echo (!empty($commercial_register_err)) ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($commercial_register ?? ''); // استخدام ?? '' لتجنب خطأ إذا كان null ?>">
                            <span class="invalid-feedback"><?php echo $commercial_register_err; ?></span>
                        </div>

                        <div class="d-grid gap-2 d-md-flex justify-content-md-end mt-4">
                             <a href="manage_suppliers.php" class="btn btn-secondary me-md-2">إلغاء</a>
                            <button type="submit" name="update_supplier" class="btn btn-warning"><i class="fas fa-save"></i> تحديث المورد</button>
                        </div>
                    </form>
                </div>
            </div>
            <?php else: ?>
                <?php if(empty($message)) echo "<div class='alert alert-warning text-center'>المورد المطلوب غير موجود أو رقم المورد غير صحيح. <a href='manage_suppliers.php'>العودة لقائمة الموردين</a>.</div>"; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
$conn->close();
require_once BASE_DIR . 'partials/footer.php';
?>