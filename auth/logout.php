<?php
// بدء الجلسة
session_start();

// إلغاء تعيين جميع متغيرات الجلسة
$_SESSION = array();

// تدمير الجلسة
session_destroy();

// إعادة التوجيه إلى صفحة تسجيل الدخول
header("location: login.php");
exit;
?>