<?php
require_once dirname(__DIR__) . '/config.php';
require_once BASE_DIR . 'partials/session_admin.php'; // المدير فقط يمكنه الحذف

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_purchase_invoice'])) {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['message'] = "<div class='alert alert-danger'>خطأ: طلب غير صالح (CSRF).</div>";
    } else {
        $purchase_invoice_id_to_delete = isset($_POST['purchase_invoice_id_to_delete']) ? intval($_POST['purchase_invoice_id_to_delete']) : 0;

        if ($purchase_invoice_id_to_delete <= 0) {
            $_SESSION['message'] = "<div class='alert alert-danger'>رقم فاتورة المشتريات غير صالح.</div>";
        } else {
            // (اختياري) التحقق إذا كانت الفاتورة يمكن حذفها (مثلاً، ليست ملغاة بالفعل أو حالتها تسمح بالحذف)
            $sql_check_status = "SELECT status FROM purchase_invoices WHERE id = ?";
            $stmt_check = $conn->prepare($sql_check_status);
            $stmt_check->bind_param("i", $purchase_invoice_id_to_delete);
            $stmt_check->execute();
            $result_check = $stmt_check->get_result();
            $invoice_info = $result_check->fetch_assoc();
            $stmt_check->close();

            if (!$invoice_info) {
                $_SESSION['message'] = "<div class='alert alert-warning'>لم يتم العثور على فاتورة المشتريات.</div>";
            } elseif ($invoice_info['status'] == 'cancelled') {
                 $_SESSION['message'] = "<div class='alert alert-warning'>لا يمكن حذف فاتورة مشتريات ملغاة.</div>";
            }
            // يمكنك إضافة المزيد من الشروط هنا إذا لزم الأمر
            else {
                $conn->begin_transaction();
                try {
                    // 1. جلب بنود الفاتورة لخصم كمياتها من المخزون
                    $items_to_deduct = [];
                    $sql_get_items = "SELECT product_id, quantity FROM purchase_invoice_items WHERE purchase_invoice_id = ?";
                    if ($stmt_get_items = $conn->prepare($sql_get_items)) {
                        $stmt_get_items->bind_param("i", $purchase_invoice_id_to_delete);
                        $stmt_get_items->execute();
                        $result_items = $stmt_get_items->get_result();
                        while ($item = $result_items->fetch_assoc()) {
                            $items_to_deduct[] = $item;
                        }
                        $stmt_get_items->close();
                    } else {
                        throw new Exception("خطأ في جلب بنود فاتورة المشتريات: " . $conn->error);
                    }

                    // 2. تحديث المخزون (خصم الكميات)
                    foreach ($items_to_deduct as $item) {
                        // فقط إذا كانت الفاتورة قد تم اعتبارها "مستلمة" وتم إضافة الكمية للمخزون سابقاً
                        // في سير عملنا الحالي، الكمية تضاف للمخزون عند إضافة البند لـ view_purchase_invoice.php
                        // لذا يجب خصمها عند حذف الفاتورة
                        $sql_update_stock = "UPDATE products SET current_stock = current_stock - ? WHERE id = ?";
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

                    // 3. حذف الفاتورة من purchase_invoices (سيقوم بحذف البنود تلقائياً بسبب ON DELETE CASCADE)
                    $sql_delete_invoice = "DELETE FROM purchase_invoices WHERE id = ?";
                    if ($stmt_delete_invoice = $conn->prepare($sql_delete_invoice)) {
                        $stmt_delete_invoice->bind_param("i", $purchase_invoice_id_to_delete);
                        if ($stmt_delete_invoice->execute()) {
                            if ($stmt_delete_invoice->affected_rows > 0) {
                                $conn->commit();
                                $_SESSION['message'] = "<div class='alert alert-success'>تم حذف فاتورة المشتريات رقم #{$purchase_invoice_id_to_delete} وبنودها وتحديث المخزون بنجاح.</div>";
                            } else {
                                throw new Exception("لم يتم العثور على فاتورة المشتريات لحذفها أو تم حذفها بالفعل.");
                            }
                        } else {
                            throw new Exception("خطأ أثناء حذف فاتورة المشتريات: " . $stmt_delete_invoice->error);
                        }
                        $stmt_delete_invoice->close();
                    } else {
                        throw new Exception("خطأ في تحضير استعلام حذف فاتورة المشتريات: " . $conn->error);
                    }

                } catch (Exception $e) {
                    $conn->rollback();
                    $_SESSION['message'] = "<div class='alert alert-danger'>فشلت عملية الحذف: " . $e->getMessage() . "</div>";
                }
            }
        }
    }
    // إعادة التوجيه إلى صفحة إدارة فواتير المشتريات
    // يمكنك الحفاظ على الفلاتر هنا أيضاً بنفس طريقة صفحة الحذف الأخرى
    header("Location: " . BASE_URL . "admin/manage_purchase_invoices.php");
    exit;
} else {
    $_SESSION['message'] = "<div class='alert alert-warning'>طلب غير صحيح.</div>";
    header("Location: " . BASE_URL . "admin/");
    exit;
}
?>