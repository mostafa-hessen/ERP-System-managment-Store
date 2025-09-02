<?php
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== 'admin'){
    header("location: " . BASE_URL . "auth/login.php"); // أو يمكنك توجيهه إلى صفحة "غير مصرح"
    exit;
}