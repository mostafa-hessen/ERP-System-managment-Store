<?php
$page_title = "إعدادات تسجيل المستخدمين";
require_once dirname(__DIR__) . '/config.php'; // للوصول لـ config.php
require_once BASE_DIR . 'partials/session_admin.php'; // التأكد أن المستخدم مدير

$message = "";

// جلب توكن CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// معالجة تحديث الإعداد
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_registration_settings'])) {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $message = "<div class='alert alert-danger'>خطأ: طلب غير صالح (CSRF).</div>";
    } else {
        $new_status = isset($_POST['registration_status']) && $_POST['registration_status'] === 'closed' ? 'closed' : 'open';
        if (update_setting($conn, 'user_registration_status', $new_status)) {
            $_SESSION['message'] = "<div class='alert alert-success'>تم تحديث حالة تسجيل المستخدمين بنجاح.</div>";
        } else {
            $_SESSION['message'] = "<div class='alert alert-danger'>حدث خطأ أثناء تحديث الإعدادات.</div>";
        }
        // إعادة التوجيه لتطبيق PRG وتحديث عرض الرسالة
        header("Location: " . BASE_URL . "admin/registration_settings.php");
        exit;
    }
}

// جلب الحالة الحالية للتسجيل
$current_registration_status = get_setting($conn, 'user_registration_status', 'open'); // القيمة الافتراضية 'open'

// جلب الرسالة من الجلسة (بعد إعادة التوجيه)
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    unset($_SESSION['message']);
}

require_once BASE_DIR . 'partials/header.php';
require_once BASE_DIR . 'partials/sidebar.php';
?>

<div class="container mt-5 pt-3">
    <div class="row justify-content-center">
        <div class="col-md-8 col-lg-6">
            <h1><i class="fas fa-user-cog"></i> إعدادات تسجيل المستخدمين</h1>
            <p>تحكم في إمكانية تسجيل مستخدمين جدد في النظام.</p>

            <?php echo $message; ?>

            <div class="card shadow-sm mt-4">
                <div class="card-header">
                    حالة التسجيل الحالية:
                    <?php if ($current_registration_status === 'open'): ?>
                        <span class="badge bg-success">مفتوح</span>
                    <?php else: ?>
                        <span class="badge bg-danger">مغلق</span>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">

                        <div class="mb-3">
                            <p>اختر الحالة الجديدة لتسجيل المستخدمين:</p>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="registration_status" id="reg_open" value="open" <?php echo ($current_registration_status === 'open') ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="reg_open">
                                    <i class="fas fa-door-open text-success"></i> فتح التسجيل
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="registration_status" id="reg_closed" value="closed" <?php echo ($current_registration_status === 'closed') ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="reg_closed">
                                    <i class="fas fa-door-closed text-danger"></i> غلق التسجيل
                                </label>
                            </div>
                        </div>

                        <button type="submit" name="update_registration_settings" class="btn btn-primary">
                            <i class="fas fa-save"></i> حفظ التغييرات
                        </button>
                         <a href="<?php echo BASE_URL; ?>admin/" class="btn btn-secondary">إلغاء</a> </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
$conn->close();
require_once BASE_DIR . 'partials/footer.php';
?>