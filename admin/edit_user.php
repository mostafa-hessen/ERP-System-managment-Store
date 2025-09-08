<?php
$page_title = "تعديل مستخدم"; 
$class_dashboard = "active";
require_once dirname(__DIR__) . '/config.php';
require_once BASE_DIR . 'partials/session_admin.php';
require_once BASE_DIR . 'partials/header.php';

$message = ""; // لرسائل النجاح أو الخطأ
$username_err = $email_err = $role_err = ""; // لرسائل أخطاء الحقول
$user_id = $username = $email = $role = ""; // لبيانات المستخدم

// جلب توكن CSRF الحالي
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// --- 6. معالجة طلب التحديث ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_user'])) {

    // التحقق من CSRF
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $message = "<div class='alert alert-danger'>خطأ: طلب غير صالح (CSRF detected).</div>";
    } else {
        $user_id = intval($_POST['user_id']); // جلب ID المستخدم من الحقل المخفي

        // جلب وتنقية البيانات
        $username = trim($_POST["username"]);
        $email = trim($_POST["email"]);
        $role = trim($_POST["role"]);

        // --- التحقق من صحة البيانات ---
        if (empty($username)) { $username_err = "الرجاء إدخال اسم المستخدم."; }
        if (empty($email)) { $email_err = "الرجاء إدخال البريد الإلكتروني."; }
        elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $email_err = "الرجاء إدخال بريد إلكتروني صالح."; }
        if (empty($role) || !in_array($role, ['user', 'admin'])) { $role_err = "الرجاء تحديد دور صالح."; }

        // --- التحقق من تكرار اسم المستخدم والبريد الإلكتروني (للمستخدمين الآخرين) ---
        if (empty($username_err)) {
            $sql_check_username = "SELECT id FROM users WHERE username = ? AND id != ?";
            if($stmt = $conn->prepare($sql_check_username)){
                $stmt->bind_param("si", $username, $user_id);
                $stmt->execute();
                $stmt->store_result();
                if($stmt->num_rows > 0){ $username_err = "اسم المستخدم هذا مستخدم بالفعل."; }
                $stmt->close();
            }
        }
        if (empty($email_err)) {
            $sql_check_email = "SELECT id FROM users WHERE email = ? AND id != ?";
             if($stmt = $conn->prepare($sql_check_email)){
                $stmt->bind_param("si", $email, $user_id);
                $stmt->execute();
                $stmt->store_result();
                if($stmt->num_rows > 0){ $email_err = "هذا البريد الإلكتروني مستخدم بالفعل."; }
                $stmt->close();
            }
        }

        // إذا لم يكن هناك أخطاء، قم بالتحديث
        if (empty($username_err) && empty($email_err) && empty($role_err)) {
            $sql_update = "UPDATE users SET username = ?, email = ?, role = ? WHERE id = ?";
            if ($stmt_update = $conn->prepare($sql_update)) {
                $stmt_update->bind_param("sssi", $username, $email, $role, $user_id);
                if ($stmt_update->execute()) {
                    // --- !! تغيير PRG هنا !! ---
                    // تخزين رسالة النجاح في الجلسة
                    $_SESSION['message'] = "<div class='alert alert-success'>تم تحديث بيانات المستخدم بنجاح.</div>";
                    // إعادة التوجيه إلى صفحة إدارة المستخدمين
                    header("Location: manage_users.php");
                    exit; // إيقاف التنفيذ بعد إعادة التوجيه
                    // --- !! نهاية تغيير PRG !! ---
                } else {
                    $message = "<div class='alert alert-danger'>حدث خطأ أثناء تحديث البيانات: " . $stmt_update->error . "</div>";
                }
                $stmt_update->close();
            } else {
                 $message = "<div class='alert alert-danger'>خطأ في تحضير استعلام التحديث: " . $conn->error . "</div>";
            }
        } else {
             $message = "<div class='alert alert-danger'>الرجاء إصلاح الأخطاء أدناه.</div>";
        }
    }
}
// --- 2. جلب بيانات المستخدم لعرضها (إذا لم يكن طلب تحديث) ---
elseif ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['user_id_to_edit'])) {
    // التحقق من CSRF القادم من manage_user.php
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die("خطأ: طلب غير صالح (CSRF detected).");
    }

    $user_id = intval($_POST['user_id_to_edit']);
    $sql_select = "SELECT username, email, role FROM users WHERE id = ?";
    if ($stmt_select = $conn->prepare($sql_select)) {
        $stmt_select->bind_param("i", $user_id);
        if ($stmt_select->execute()) {
            $stmt_select->bind_result($username, $email, $role);
            if (!$stmt_select->fetch()) {
                $message = "<div class='alert alert-danger'>لم يتم العثور على المستخدم المطلوب.</div>";
                $user_id = ""; // أفرغ الـ ID لمنع عرض النموذج
            }
        } else {
            $message = "<div class='alert alert-danger'>خطأ أثناء جلب بيانات المستخدم.</div>";
            $user_id = "";
        }
        $stmt_select->close();
    } else {
        $message = "<div class='alert alert-danger'>خطأ في تحضير استعلام الجلب.</div>";
        $user_id = "";
    }
}
// --- إذا لم يكن هناك طلب صالح ---
else {
    // إذا لم يكن هناك طلب POST صالح (لا تحديث ولا طلب تعديل أولي)، أعد التوجيه
    if (empty($message)) { // تأكد من عدم وجود رسالة خطأ بالفعل
       header("location: manage_users.php");
       exit;
    }
}

require_once BASE_DIR . 'partials/sidebar.php';

?>

<div class="container mt-5 pt-3">
    <h1><i class="fas fa-user-edit"></i> تعديل بيانات المستخدم</h1>
    <p>قم بتغيير بيانات المستخدم أدناه ثم اضغط на "تحديث".</p>

    <?php echo $message; // عرض رسائل الحالة (ستظهر الآن فقط عند حدوث خطأ أو قبل إعادة التوجيه) ?>

    <?php // عرض النموذج فقط إذا كان لدينا user_id صالح ?>
    <?php if (!empty($user_id)): ?>
        <div class="card">
            <div class="card-header">
                بيانات المستخدم: <?php echo htmlspecialchars($username); ?> (ID: <?php echo $user_id; ?>)
            </div>
            <div class="card-body">
                <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
                    <input type="hidden" name="user_id" value="<?php echo $user_id; ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">

                    <div class="mb-3">
                        <label for="username" class="form-label">اسم المستخدم:</label>
                        <input type="text" name="username" id="username" class="form-control <?php echo (!empty($username_err)) ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($username); ?>">
                        <span class="invalid-feedback"><?php echo $username_err; ?></span>
                    </div>

                    <div class="mb-3">
                        <label for="email" class="form-label">البريد الإلكتروني:</label>
                        <input type="email" name="email" id="email" class="form-control <?php echo (!empty($email_err)) ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($email); ?>">
                        <span class="invalid-feedback"><?php echo $email_err; ?></span>
                    </div>

                    <div class="mb-3">
                        <label for="role" class="form-label">الدور:</label>
                        <select name="role" id="role" class="form-select <?php echo (!empty($role_err)) ? 'is-invalid' : ''; ?>">
                            <option value="user" <?php echo ($role == 'user') ? 'selected' : ''; ?>>مستخدم</option>
                            <option value="admin" <?php echo ($role == 'admin') ? 'selected' : ''; ?>>مدير</option>
                        </select>
                        <span class="invalid-feedback"><?php echo $role_err; ?></span>
                    </div>
<!-- 
                    <div class="mb-3">
                        <p class="form-text">لتغيير كلمة المرور، يرجى إنشاء صفحة منفصلة أو إضافة حقول مخصصة هنا.</p>
                    </div> -->

                    <button type="submit" name="update_user" class="btn btn-primary"><i class="fas fa-save"></i> تحديث</button>
                    <a href="<?php echo BASE_URL; ?>admin/manage_users.php" class="btn btn-secondary">إلغاء</a>
                </form>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php
$conn->close();
?>
<?php require_once BASE_DIR . 'partials/footer.php'; ?>