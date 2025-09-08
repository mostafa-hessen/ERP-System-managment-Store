<?php
// admin/register.php
$page_title = "إضافة مستخدم جديد";
require_once dirname(__DIR__) . '/config.php';
require_once BASE_DIR . 'partials/session_admin.php'; // صفحة محمية للمدير
require_once BASE_DIR . 'partials/header.php';

// مساعدة للهروب من XSS عند العرض
if (!function_exists('e')) {
    function e($s){ return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}

// توليد/جلب توكن CSRF
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf_token = $_SESSION['csrf_token'];

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_user'])) {
    // CSRF
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $errors[] = "طلب غير صالح (CSRF).";
    } else {
        // جلب القيم مع تقليم
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $password_confirm = $_POST['password_confirm'] ?? '';
        $role = in_array($_POST['role'] ?? 'user', ['user','admin']) ? $_POST['role'] : 'user';

        // تحقق بسيط
        if ($username === '' || strlen($username) < 3) $errors[] = "يرجى إدخال اسم مستخدم صحيح (3 أحرف على الأقل).";
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "يرجى إدخال بريد إلكتروني صالح.";
        if ($password === '' || strlen($password) < 6) $errors[] = "كلمة المرور يجب أن تكون 6 أحرف على الأقل.";
        if ($password !== $password_confirm) $errors[] = "كلمتا المرور غير متطابقتين.";

        // تحقق التكرار (username أو email)
        if (empty($errors)) {
            $sql_check = "SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1";
            if ($stmt = $conn->prepare($sql_check)) {
                $stmt->bind_param("ss", $username, $email);
                $stmt->execute();
                $stmt->store_result();
                if ($stmt->num_rows > 0) {
                    $errors[] = "اسم المستخدم أو البريد الإلكتروني مستخدم بالفعل.";
                }
                $stmt->close();
            } else {
                $errors[] = "خطأ في قاعدة البيانات (التحقق).";
            }
        }

        // إدخال المستخدم
        if (empty($errors)) {
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            $sql_insert = "INSERT INTO users (username, email, password, role, created_at) VALUES (?, ?, ?, ?, NOW())";
            if ($stmt = $conn->prepare($sql_insert)) {
                $stmt->bind_param("ssss", $username, $email, $password_hash, $role);
                if ($stmt->execute()) {
                    $new_id = $stmt->insert_id;
                    $stmt->close();
                    // رسالة نجاح في الجلسة ثم تحويل لصفحة إدارة المستخدمين
                    $_SESSION['message'] = "<div class='alert alert-success'>تم إضافة المستخدم بنجاح (ID: {$new_id}).</div>";
                    header("Location: " . BASE_URL . "admin/manage_users.php");
                    exit;
                } else {
                    $errors[] = "فشل حفظ المستخدم: " . e($stmt->error);
                    $stmt->close();
                }
            } else {
                $errors[] = "خطأ في تحضير استعلام الإدخال: " . e($conn->error);
            }
        }
    }
}
require_once BASE_DIR . 'partials/sidebar.php';

?>

<style>
/* تصميم بسيط مطابق للثيم (يستخدم CSS variables الموجودة في index.css) */
.register-page {
  max-width: 820px;
  margin: 24px auto;
}
.card-register {
  background: var(--surface);
  border-radius: var(--radius);
  box-shadow: var(--shadow-1);
  padding: 20px;
  border: 1px solid var(--border);
}
.card-register .card-header {
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:12px;
  padding-bottom:12px;
  margin-bottom:12px;
  border-bottom:1px solid rgba(0,0,0,0.04);
}
.brand-badge {
  background: var(--grad-1);
  -webkit-background-clip: text;
  background-clip: text;
  color: transparent;
  font-weight:700;
}
.form-label { font-size: 0.95rem; color: var(--text-soft); }
.btn-primary-custom {
  background: var(--primary);
  border-color: var(--primary-600);
  color: white;
  box-shadow: var(--shadow-2);
}
.small-note { font-size: 0.85rem; color: var(--muted); margin-top:6px; }
.alert { margin-bottom:12px; }
</style>

<div class="container register-page">
    <div class="card-register">
        <div class="card-header">
            <div>
                <h3 style="margin:0">إضافة مستخدم جديد</h3>
                <div class="small-note">أنشئ حساب جديد مع الدور المناسب.</div>
            </div>
            <div>
                <a href="<?php echo BASE_URL; ?>admin/manage_users.php" class="btn btn-outline-secondary">
                    <i class="fas fa-arrow-left"></i> رجوع للقائمة
                </a>
            </div>
        </div>

        <!-- عرض الأخطاء -->
        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <ul style="margin:0;padding-left:18px;">
                    <?php foreach ($errors as $err): ?>
                        <li><?php echo e($err); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo e($success); ?></div>
        <?php endif; ?>

        <form method="post" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" novalidate>
            <input type="hidden" name="csrf_token" value="<?php echo e($csrf_token); ?>">
            <input type="hidden" name="create_user" value="1">

            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">اسم المستخدم</label>
                    <input type="text" name="username" class="form-control" required minlength="3" value="<?php echo e($_POST['username'] ?? ''); ?>" placeholder="مثال: saied">
                    <div class="small-note">يُستخدم لتسجيل الدخول. 3 أحرف على الأقل.</div>
                </div>

                <div class="col-md-6">
                    <label class="form-label">البريد الإلكتروني</label>
                    <input type="email" name="email" class="form-control" required value="<?php echo e($_POST['email'] ?? ''); ?>" placeholder="name@example.com">
                </div>

                <div class="col-md-6">
                    <label class="form-label">كلمة المرور</label>
                    <input type="password" name="password" class="form-control" required minlength="6" placeholder="••••••">
                    <div class="small-note">6 أحرف على الأقل. يمكنك إضافة متطلبات أقوى إذا أردت.</div>
                </div>

                <div class="col-md-6">
                    <label class="form-label">تأكيد كلمة المرور</label>
                    <input type="password" name="password_confirm" class="form-control" required minlength="6" placeholder="أعد كتابة كلمة المرور">
                </div>

                <div class="col-md-6">
                    <label class="form-label">الدور</label>
                    <select name="role" class="form-select">
                        <option value="user" <?php echo (($_POST['role'] ?? '') === 'user') ? 'selected' : ''; ?>>مستخدم</option>
                        <option value="admin" <?php echo (($_POST['role'] ?? '') === 'admin') ? 'selected' : ''; ?>>مدير</option>
                    </select>
                </div>

                <div class="col-12 mt-3 d-flex gap-2">
                    <button type="submit" class="btn btn-primary btn-primary-custom">
                        <i class="fas fa-user-plus me-2"></i> إنشاء المستخدم
                    </button>
                    <a href="<?php echo BASE_URL; ?>admin/manage_users.php" class="btn btn-outline-secondary">إلغاء</a>
                </div>
            </div>
        </form>
    </div>
</div>

<?php
// لا تغلق الاتصال هنا لأن footer قد يحتاجه
require_once BASE_DIR . 'partials/footer.php';
?>
