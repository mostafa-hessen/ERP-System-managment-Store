<?php
// التحقق مما إذا كان المستخدم قد قام بتسجيل الدخول، وإلا قم بإعادة توجيهه إلى صفحة تسجيل الدخول
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
    header("location: " . BASE_URL . "auth/login.php");
    exit;
}