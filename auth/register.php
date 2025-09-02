<?php
$page_title = "تسجيل عضوية جديدة";
require_once dirname(__DIR__) . '/config.php'; // للوصول لـ config.php

// جلب حالة التسجيل
$registration_status = get_setting($conn, 'user_registration_status', 'open'); // 'open' كقيمة افتراضية

if ($registration_status === 'closed') {
    // إذا كان التسجيل مغلقاً، اعرض رسالة وأوقف التنفيذ
    require_once BASE_DIR . 'partials/header.php';
    // يمكنك تضمين navbar.php إذا أردت
    echo "<div class='container mt-5 pt-5 text-center vh-100 d-flex justify-content-center align-items-center'>";
    echo "  <div>";
    echo "      <div class='alert alert-warning p-4 shadow' role='alert' style='max-width: 500px; margin: auto;'>";
    echo "          <h4 class='alert-heading'><i class='fas fa-exclamation-triangle fa-2x mb-3'></i><br>التسجيل مغلق حالياً</h4>";
    echo "          <p>عفواً، التسجيل في الموقع مغلق حالياً بأمر من الإدارة. يرجى المحاولة في وقت لاحق.</p>";
    echo "          <hr>";
    echo "          <p class='mb-0'><a href='" . BASE_URL . "auth/login.php' class='btn btn-primary'>العودة لصفحة تسجيل الدخول</a></p>";
    echo "      </div>";
    echo "  </div>";
    echo "</div>";
    if($conn) $conn->close(); // أغلق الاتصال إذا تم الخروج
    require_once BASE_DIR . 'partials/footer.php';
    exit;
}

// --- !! إضافة/تأكيد تعريف المتغيرات هنا !! ---
// إذا وصلنا إلى هنا، فالتسجيل مفتوح.
// تعريف المتغيرات التي سيتم استخدامها في النموذج ورسائل الخطأ
$username = $email = ""; // للمساعدة في إعادة ملء النموذج إذا حدث خطأ
$username_err = $email_err = $password_err = ""; // لرسائل أخطاء الحقول
$message = ""; // لرسائل الحالة العامة (مثل نجاح التسجيل أو أخطاء أخرى)
// --- !! نهاية الإضافة/التأكيد !! ---


// جلب توكن CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// --- معالجة النموذج عند الإرسال (POST request) ---
// (هنا تضع كود معالجة POST الخاص بك، والذي قد يقوم بتغيير قيمة $message أو $username_err إلخ.)
// مثال:
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // ... (كود التحقق من CSRF، التحقق من صحة المدخلات، إلخ.) ...
    // ... (إذا نجح التسجيل، قد تُغير $message إلى رسالة نجاح) ...
    // ... (إذا فشل، قد تملأ $username_err, $email_err, $password_err ورسالة خطأ في $message) ...

    // (الكود الخاص بك لمعالجة التسجيل هنا)
    // على سبيل المثال، إذا كان الكود الأصلي لـ register.php لديك:
    // التحقق من CSRF
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $message = "<div class='alert alert-danger'>خطأ: طلب غير صالح.</div>";
    } else {
        // التحقق من اسم المستخدم
        if (empty(trim($_POST["username"]))) {
            $username_err = "الرجاء إدخال اسم المستخدم.";
        } else {
            $sql_check_username = "SELECT id FROM users WHERE username = ?";
            if ($stmt_check_username = $conn->prepare($sql_check_username)) {
                $stmt_check_username->bind_param("s", $param_username);
                $param_username = trim($_POST["username"]);
                if ($stmt_check_username->execute()) {
                    $stmt_check_username->store_result();
                    if ($stmt_check_username->num_rows == 1) {
                        $username_err = "اسم المستخدم هذا مستخدم بالفعل.";
                    } else {
                        $username = trim($_POST["username"]);
                    }
                } else { $message = "<div class='alert alert-danger'>حدث خطأ ما. الرجاء المحاولة مرة أخرى.</div>"; }
                $stmt_check_username->close();
            }
        }

        // التحقق من البريد الإلكتروني
        if (empty(trim($_POST["email"]))) {
            $email_err = "الرجاء إدخال البريد الإلكتروني.";
        } elseif (!filter_var(trim($_POST["email"]), FILTER_VALIDATE_EMAIL)) {
            $email_err = "الرجاء إدخال بريد إلكتروني صالح.";
        } else {
            $sql_check_email = "SELECT id FROM users WHERE email = ?";
            if ($stmt_check_email = $conn->prepare($sql_check_email)) {
                $stmt_check_email->bind_param("s", $param_email);
                $param_email = trim($_POST["email"]);
                if ($stmt_check_email->execute()) {
                    $stmt_check_email->store_result();
                    if ($stmt_check_email->num_rows == 1) {
                        $email_err = "هذا البريد الإلكتروني مستخدم بالفعل.";
                    } else {
                        $email = trim($_POST["email"]);
                    }
                } else { $message = "<div class='alert alert-danger'>حدث خطأ ما. الرجاء المحاولة مرة أخرى.</div>"; }
                $stmt_check_email->close();
            }
        }

        // التحقق من كلمة المرور
        if (empty(trim($_POST["password"]))) {
            $password_err = "الرجاء إدخال كلمة المرور.";
        } elseif (strlen(trim($_POST["password"])) < 6) {
            $password_err = "يجب أن تتكون كلمة المرور من 6 أحرف على الأقل.";
        } else {
            $password = trim($_POST["password"]); // لا حاجة لتخزينها هنا إذا سيتم تشفيرها مباشرة
        }

        // التحقق من عدم وجود أخطاء قبل الإدراج
        if (empty($username_err) && empty($email_err) && empty($password_err) && empty($message)) {
            $sql_insert_user = "INSERT INTO users (username, email, password) VALUES (?, ?, ?)";
            if ($stmt_insert = $conn->prepare($sql_insert_user)) {
                $stmt_insert->bind_param("sss", $username, $email, $hashed_password);
                $hashed_password = password_hash(trim($_POST["password"]), PASSWORD_DEFAULT);
                if ($stmt_insert->execute()) {
                    // تم التسجيل بنجاح، يمكنك توجيه المستخدم لصفحة الدخول أو عرض رسالة نجاح
                    $_SESSION['message'] = "<div class='alert alert-success'>تم التسجيل بنجاح! يمكنك الآن تسجيل الدخول.</div>";
                    header("Location: " . BASE_URL . "auth/login.php");
                    exit;
                } else {
                    $message = "<div class='alert alert-danger'>حدث خطأ أثناء التسجيل: " . $stmt_insert->error . "</div>";
                }
                $stmt_insert->close();
            } else { $message = "<div class='alert alert-danger'>خطأ في تحضير الاستعلام.</div>"; }
        } elseif (empty($message)) { // إذا كانت هناك أخطاء في الحقول ولم يكن هناك خطأ عام آخر
            $message = "<div class='alert alert-danger'>الرجاء إصلاح الأخطاء في النموذج.</div>";
        }
    }
}
// نهاية معالجة POST

require_once BASE_DIR . 'partials/header.php';
require_once BASE_DIR . 'partials/navbar.php'; // أو لا إذا كانت صفحة التسجيل لا تحتوي على navbar
?>

<div class="container mt-5 pt-3">
    <div class="row justify-content-center">
        <div class="col-md-8 col-lg-6"> <?php // تعديل ليتناسب مع النموذج الأصغر ?>
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white text-center">
                    <h2><i class="fas fa-user-plus"></i> تسجيل عضوية جديدة</h2>
                </div>
                <div class="card-body p-4">
                    <?php echo $message; // هنا يتم طباعة $message، وهو السطر 39 أو قريب منه ?>
                    <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">

                        <div class="mb-3">
                            <label for="username" class="form-label"><i class="fas fa-user"></i> اسم المستخدم:</label>
                            <input type="text" name="username" id="username" class="form-control <?php echo (!empty($username_err)) ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($username); ?>" required>
                            <span class="invalid-feedback"><?php echo $username_err; ?></span>
                        </div>

                        <div class="mb-3">
                            <label for="email" class="form-label"><i class="fas fa-envelope"></i> البريد الإلكتروني:</label>
                            <input type="email" name="email" id="email" class="form-control <?php echo (!empty($email_err)) ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($email); ?>" required>
                            <span class="invalid-feedback"><?php echo $email_err; ?></span>
                        </div>

                        <div class="mb-3">
                            <label for="password" class="form-label"><i class="fas fa-lock"></i> كلمة المرور:</label>
                            <input type="password" name="password" id="password" class="form-control <?php echo (!empty($password_err)) ? 'is-invalid' : ''; ?>" required>
                            <span class="invalid-feedback"><?php echo $password_err; ?></span>
                        </div>

                        <div class="d-grid">
                            <button type="submit" name="register_user" class="btn btn-primary btn-lg">تسجيل</button>
                        </div>
                         <p class="text-center mt-3">هل لديك حساب بالفعل؟ <a href="<?php echo BASE_URL; ?>auth/login.php">تسجيل الدخول هنا</a>.</p>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
if($conn) $conn->close();
require_once BASE_DIR . 'partials/footer.php';
?>