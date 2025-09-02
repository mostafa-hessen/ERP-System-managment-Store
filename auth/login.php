<?php
require_once dirname(__DIR__) . '/config.php';

$username_err = $password_err = $login_err = "";

// التحقق مما إذا كان المستخدم قد قام بتسجيل الدخول بالفعل، إذا كان الأمر كذلك قم بإعادة توجيهه إلى صفحة الترحيب
if(isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true){
    header("location: welcome.php");
    exit;
}

// معالجة بيانات النموذج عند إرساله
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // التحقق من توكن CSRF
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        // إذا لم يتطابق التوكن، أوقف التنفيذ أو أظهر خطأ
        die("خطأ: طلب غير صالح (CSRF detected).");
    }

    // التحقق من اسم المستخدم
    if (empty(trim($_POST["username"]))) {
        $username_err = "الرجاء إدخال اسم المستخدم.";
    } else {
        $username = trim($_POST["username"]);
    }

    // التحقق من كلمة المرور
    if (empty(trim($_POST["password"]))) {
        $password_err = "الرجاء إدخال كلمة المرور.";
    } else {
        $password = trim($_POST["password"]);
    }

    // التحقق من صحة بيانات الاعتماد
    if (empty($username_err) && empty($password_err)) {
        $sql = "SELECT id, username, password, role FROM users WHERE username = ?";
        if ($stmt = $conn->prepare($sql)) {
            $stmt->bind_param("s", $param_username);
            $param_username = $username;
            if ($stmt->execute()) {
                $stmt->store_result();
                if ($stmt->num_rows == 1) {
                    $stmt->bind_result($id, $username, $hashed_password, $role);
                    if ($stmt->fetch()) {
                        if (password_verify($password, $hashed_password)) {
                            // كلمة المرور صحيحة، ابدأ جلسة جديدة
                            session_start();

                            // تخزين البيانات في متغيرات الجلسة
                            $_SESSION["loggedin"] = true;
                            $_SESSION["id"] = $id;
                            $_SESSION["username"] = $username;
                            $_SESSION["role"] = $role;

                            // إعادة التوجيه إلى صفحة الترحيب
                            header("Location: " . BASE_URL . "user/welcome.php");
                            exit();
                        } else {
                            $login_err = "اسم المستخدم أو كلمة المرور غير صالحة.";
                        }
                    }
                } else {
                    $login_err = "اسم المستخدم أو كلمة المرور غير صالحة.";
                }
            } else {
                $login_err = "حدث خطأ ما. الرجاء المحاولة مرة أخرى لاحقًا.";
            }
            $stmt->close();
        }
    }
    $conn->close();
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تسجيل الدخول</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css">
    <style>
        body { background-color: #f8f9fa; }
        .wrapper { width: 100%; max-width: 400px; padding: 20px; margin: 50px auto; background: #fff; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,.1); }
        .form-group { margin-bottom: 1.5rem; }
        .btn-primary { background-color: #007bff; border-color: #007bff; }
        .text-danger { font-size: 0.875em; }
        h2 { text-align: center; margin-bottom: 20px; }
    </style>
</head>
<body>
    <div class="wrapper">
        <h2>تسجيل الدخول</h2>
        <p>الرجاء ملء بيانات الاعتماد الخاصة بك لتسجيل الدخول.</p>

        <?php
        if(!empty($login_err)){
            echo '<div class="alert alert-danger">' . $login_err . '</div>';
        }
        ?>

        <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <div class="form-group">
                <label>اسم المستخدم</label>
                <input type="text" name="username" class="form-control <?php echo (!empty($username_err)) ? 'is-invalid' : ''; ?>" value="<?php echo isset($_POST['username']) ? $_POST['username'] : ''; ?>">
                <span class="text-danger"><?php echo $username_err; ?></span>
            </div>
            <div class="form-group">
                <label>كلمة المرور</label>
                <input type="password" name="password" class="form-control <?php echo (!empty($password_err)) ? 'is-invalid' : ''; ?>">
                <span class="text-danger"><?php echo $password_err; ?></span>
            </div>
            <div class="form-group">
                <input type="submit" class="btn btn-primary w-100" value="دخول">
            </div>
            <p class="text-center">ليس لديك حساب؟ <a href="register.php">سجل الآن</a>.</p>
        </form>
    </div>
</body>
</html>