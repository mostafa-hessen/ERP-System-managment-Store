<?php
require_once dirname(__DIR__) . '/config.php'; // للوصول إلى config.php من داخل invoices_out

// التحقق الأساسي من تسجيل الدخول
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    // إذا لم يكن مسجلاً، لا يمكنه تنفيذ أي إجراء
    $_SESSION['message'] = "<div class='alert alert-danger'>الرجاء تسجيل الدخول أولاً.</div>";
    header("Location: " . BASE_URL . "auth/login.php");
    exit;
}

$message = ""; // لرسائل الحالة

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_item'])) {
    // التحقق من CSRF
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['message'] = "<div class='alert alert-danger'>خطأ: طلب غير صالح (CSRF).</div>";
    } else {
        $item_id_to_delete = isset($_POST['item_id_to_delete']) ? intval($_POST['item_id_to_delete']) : 0;
        $invoice_id_for_redirect = isset($_POST['invoice_id']) ? intval($_POST['invoice_id']) : 0;
        $product_id_to_return = isset($_POST['product_id_to_return']) ? intval($_POST['product_id_to_return']) : 0;
        $quantity_to_return = isset($_POST['quantity_to_return']) ? floatval($_POST['quantity_to_return']) : 0;

        if ($item_id_to_delete <= 0 || $invoice_id_for_redirect <= 0 || $product_id_to_return <= 0 || $quantity_to_return <= 0) {
            $_SESSION['message'] = "<div class='alert alert-danger'>بيانات حذف البند غير كاملة أو غير صالحة.</div>";
        } else {
            // --- التحقق من صلاحية حذف البند (المدير أو منشئ الفاتورة، والفاتورة لم تسلم) ---
            $can_delete_this_item = false;
            $sql_auth = "SELECT created_by, delivered FROM invoices_out WHERE id = ?";
            if ($stmt_auth = $conn->prepare($sql_auth)) {
                $stmt_auth->bind_param("i", $invoice_id_for_redirect);
                $stmt_auth->execute();
                $result_auth = $stmt_auth->get_result();
                if ($invoice_header_data = $result_auth->fetch_assoc()) {
                    if ($invoice_header_data['delivered'] == 'yes') {
                        $_SESSION['message'] = "<div class='alert alert-warning'>لا يمكن حذف بنود من فاتورة تم تسليمها.</div>";
                    } elseif ( (isset($_SESSION['role']) && $_SESSION['role'] == 'admin') || (isset($invoice_header_data['created_by']) && $invoice_header_data['created_by'] == $_SESSION['id']) ) {
                        $can_delete_this_item = true;
                    } else {
                        $_SESSION['message'] = "<div class='alert alert-danger'>ليس لديك الصلاحية لحذف بنود من هذه الفاتورة.</div>";
                    }
                } else {
                     $_SESSION['message'] = "<div class='alert alert-danger'>الفاتورة الأصلية غير موجودة.</div>";
                }
                $stmt_auth->close();
            } else {
                $_SESSION['message'] = "<div class='alert alert-danger'>خطأ في التحقق من صلاحيات الفاتورة.</div>";
            }


            if ($can_delete_this_item) {
                // بدء معاملة (Transaction) لضمان تنفيذ العمليتين معاً أو لا شيء
                $conn->begin_transaction();

                try {
                    // 1. حذف البند من الفاتورة
                    $sql_delete_item = "DELETE FROM invoice_out_items WHERE id = ?";
                    $stmt_delete_item = $conn->prepare($sql_delete_item);
                    $stmt_delete_item->bind_param("i", $item_id_to_delete);
                    $stmt_delete_item->execute();

                    if ($stmt_delete_item->affected_rows > 0) {
                        // 2. إعادة الكمية إلى رصيد المنتج
                        $sql_update_stock = "UPDATE products SET current_stock = current_stock + ? WHERE id = ?";
                        $stmt_update_stock = $conn->prepare($sql_update_stock);
                        $stmt_update_stock->bind_param("di", $quantity_to_return, $product_id_to_return);
                        $stmt_update_stock->execute();
                        $stmt_update_stock->close();

                        $conn->commit(); // تأكيد المعاملة
                        $_SESSION['message'] = "<div class='alert alert-success'>تم حذف البند من الفاتورة وإعادة الكمية للمخزون بنجاح.</div>";
                    } else {
                        $conn->rollback(); // تراجع عن المعاملة إذا لم يتم حذف البند
                        $_SESSION['message'] = "<div class='alert alert-warning'>لم يتم العثور على البند لحذفه.</div>";
                    }
                    $stmt_delete_item->close();
                } catch (mysqli_sql_exception $exception) {
                    $conn->rollback(); // تراجع عن المعاملة في حالة حدوث أي خطأ
                    $_SESSION['message'] = "<div class='alert alert-danger'>حدث خطأ أثناء عملية الحذف: " . $exception->getMessage() . "</div>";
                }
            }
        }
    }
    // إعادة التوجيه إلى صفحة عرض الفاتورة
    if ($invoice_id_for_redirect > 0) {
        header("Location: " . BASE_URL . "invoices_out/view.php?id=" . $invoice_id_for_redirect);
    } else {
        // إذا لم يكن لدينا ID فاتورة للعودة، نوجه لصفحة عامة
        header("Location: " . BASE_URL . "user/welcome.php");
    }
    exit;
} else {
    // إذا تم الوصول للصفحة مباشرة بدون POST، أعد التوجيه
    $_SESSION['message'] = "<div class='alert alert-warning'>طلب غير صحيح.</div>";
    header("Location: " . BASE_URL . "user/welcome.php"); // أو أي صفحة مناسبة
    exit;
}
?>