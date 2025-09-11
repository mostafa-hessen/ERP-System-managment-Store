<?php
require_once dirname(__DIR__) . '/config.php';
require_once BASE_DIR . 'partials/session_admin.php'; // المدير فقط يمكنه الحذف

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_sales_invoice'])) {
    // التحقق من CSRF
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['message'] = "<div class='alert alert-danger'>خطأ: طلب غير صالح (CSRF).</div>";
    } else {
        $invoice_out_id_to_delete = isset($_POST['invoice_out_id_to_delete']) ? intval($_POST['invoice_out_id_to_delete']) : 0;

        if ($invoice_out_id_to_delete <= 0) {
            $_SESSION['message'] = "<div class='alert alert-danger'>رقم فاتورة المبيعات غير صالح.</div>";
        } else {
            // (اختياري) التحقق إذا كانت الفاتورة يمكن حذفها (مثلاً، ليست مرتبطة بمدفوعات مكتملة أو عمليات أخرى لا يمكن التراجع عنها)
            // حالياً سنقوم بالحذف المباشر بعد تعديل المخزون

            $conn->begin_transaction();
            try {
                // 1. جلب بنود الفاتورة لاستعادة كمياتها للمخزون
                $items_to_return = [];
                $sql_get_items = "SELECT product_id, quantity FROM invoice_out_items WHERE invoice_out_id = ?";
                if ($stmt_get_items = $conn->prepare($sql_get_items)) {
                    $stmt_get_items->bind_param("i", $invoice_out_id_to_delete);
                    $stmt_get_items->execute();
                    $result_items = $stmt_get_items->get_result();
                    while ($item = $result_items->fetch_assoc()) {
                        $items_to_return[] = $item;
                    }
                    $stmt_get_items->close();
                } else {
                    throw new Exception("خطأ في جلب بنود الفاتورة: " . $conn->error);
                }

                // 2. تحديث المخزون (إعادة الكميات)
                foreach ($items_to_return as $item) {
                    $sql_update_stock = "UPDATE products SET current_stock = current_stock + ? WHERE id = ?";
                    if ($stmt_update_stock = $conn->prepare($sql_update_stock)) {
                        $stmt_update_stock->bind_param("di", $item['quantity'], $item['product_id']);
                        if(!$stmt_update_stock->execute()){
                            throw new Exception("خطأ في تحديث رصيد المنتج: " . $stmt_update_stock->error);
                        }
                        $stmt_update_stock->close();
                    } else {
                        throw new Exception("خطأ في تحضير استعلام تحديث المخزون: " . $conn->error);
                    }
                }

                // 3. حذف الفاتورة من invoices_out (سيقوم بحذف البنود تلقائياً بسبب ON DELETE CASCADE)
                $sql_delete_invoice = "DELETE FROM invoices_out WHERE id = ?";
                if ($stmt_delete_invoice = $conn->prepare($sql_delete_invoice)) {
                    $stmt_delete_invoice->bind_param("i", $invoice_out_id_to_delete);
                    if ($stmt_delete_invoice->execute()) {
                        if ($stmt_delete_invoice->affected_rows > 0) {
                            $conn->commit();
                            $_SESSION['message'] = "<div class='alert alert-success'>تم حذف فاتورة المبيعات رقم #{$invoice_out_id_to_delete} وبنودها وتحديث المخزون بنجاح.</div>";
                        } else {
                            throw new Exception("لم يتم العثور على الفاتورة لحذفها أو تم حذفها بالفعل.");
                        }
                    } else {
                        throw new Exception("خطأ أثناء حذف الفاتورة: " . $stmt_delete_invoice->error);
                    }
                    $stmt_delete_invoice->close();
                } else {
                    throw new Exception("خطأ في تحضير استعلام حذف الفاتورة: " . $conn->error);
                }

            } catch (Exception $e) {
                $conn->rollback();
                $_SESSION['message'] = "<div class='alert alert-danger'>فشلت عملية الحذف: " . $e->getMessage() . "</div>";
            }
        }
    }
    // إعادة التوجيه (يمكنك تحديد الصفحة المناسبة، مثلاً قائمة الفواتير غير المستلمة أو المستلمة)
    // سنفترض وجود صفحة عامة لإدارة فواتير الصادر أو العودة لصفحة كانت تعرض الفاتورة
    // إذا كنت تحذف من pending_invoices.php أو delivered_invoices.php، يمكنك تمرير متغير لتحديد العودة.
    // للتبسيط الآن، سنوجه لـ pending_invoices.php
  // نهاية المعالجة — بدلاً من توجيه ثابت، نقرر الوجهة بناءً على input أو referer
$allowed = ['pending','delivered']; // الصفحات المسموح الرجوع إليها
$redirect_key = 'pending'; // افتراضي

// 1) إذا أرسلت الصفحة نفسها متغير redirect_to (مفضّل ـ ضع هذا الحقل في الفورم)
if (isset($_POST['redirect_to']) && in_array($_POST['redirect_to'], $allowed, true)) {
    $redirect_key = $_POST['redirect_to'];
} else {
    // 2) محاولة ذكية: استخرج من HTTP_REFERER إذا كان من نفس الموقع ويحتوي اسم الصفحة
    if (!empty($_SERVER['HTTP_REFERER']) && strpos($_SERVER['HTTP_REFERER'], BASE_URL) === 0) {
        $ref = $_SERVER['HTTP_REFERER'];
        if (strpos($ref, 'delivered_invoices.php') !== false) $redirect_key = 'delivered';
        elseif (strpos($ref, 'pending_invoices.php') !== false) $redirect_key = 'pending';
    }
}

// أخيراً وجه للصفحة المختارة (آمن داخل BASE_URL)
header("Location: " . BASE_URL . "admin/{$redirect_key}_invoices.php");
exit;

    exit;
} else {
    $_SESSION['message'] = "<div class='alert alert-warning'>طلب غير صحيح.</div>";
    header("Location: " . BASE_URL . "admin/"); // توجيه لصفحة المدير الرئيسية
    exit;
}
?>