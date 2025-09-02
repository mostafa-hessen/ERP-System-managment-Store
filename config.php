<?php
// بدء الجلسة
session_start();



if (!defined('BASE_DIR')) {
    define('BASE_DIR', __DIR__ . '/'); 
}


if (!defined('BASE_URL')) {

    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";

    $host = $_SERVER['HTTP_HOST'];

 
    define('BASE_URL', $protocol . $host . '/saied_test/');
                                                  
}



/**
 * دالة لجلب قيمة إعداد معين من قاعدة البيانات.
 * @param mysqli $conn كائن الاتصال بقاعدة البيانات.
 * @param string $setting_name اسم الإعداد المطلوب.
 * @param mixed $default_value القيمة الافتراضية إذا لم يتم العثور على الإعداد.
 * @return mixed قيمة الإعداد أو القيمة الافتراضية.
 */
function get_setting($conn, $setting_name, $default_value = null) {
    $sql = "SELECT setting_value FROM settings WHERE setting_name = ?";
    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param("s", $setting_name);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $stmt->close();
                return $row['setting_value'];
            }
        }
        $stmt->close();
    }
    return $default_value;
}

/**
 * دالة لتحديث أو إضافة قيمة إعداد معين في قاعدة البيانات.
 * @param mysqli $conn كائن الاتصال بقاعدة البيانات.
 * @param string $setting_name اسم الإعداد.
 * @param string $setting_value القيمة الجديدة للإعداد.
 * @return bool True عند النجاح, False عند الفشل.
 */
function update_setting($conn, $setting_name, $setting_value) {
    $sql = "INSERT INTO settings (setting_name, setting_value) VALUES (?, ?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)";
    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param("ss", $setting_name, $setting_value);
        if ($stmt->execute()) {
            $stmt->close();
            return true;
        }
        $stmt->close();
    }
    return false;
}





// إعدادات قاعدة البيانات
define('DB_SERVER', 'localhost');
define('DB_USERNAME', 'root'); //  اسم مستخدم قاعدة البيانات الخاص بك
define('DB_PASSWORD', '');     // كلمة مرور قاعدة البيانات الخاصة بك
define('DB_NAME', 'saied_db'); // اسم قاعدة البيانات


// إنشاء توكن CSRF إذا لم يكن موجوداً
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];


// محاولة الاتصال بقاعدة البيانات
$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);

// التحقق من الاتصال
if($conn->connect_error){
    die("فشل الاتصال: " . $conn->connect_error);
}

// ضبط الترميز إلى UTF-8 لدعم اللغة العربية
if (!$conn->set_charset("utf8mb4")) {
    printf("خطأ في تحميل مجموعة الأحرف utf8mb4: %s\n", $conn->error);
    exit();
}

?>