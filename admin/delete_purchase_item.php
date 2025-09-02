<?php
require_once dirname(__DIR__) . '/config.php'; // للوصول إلى config.php من داخل مجلد admin
require_once BASE_DIR . 'partials/session_admin.php'; // صلاحيات المدير فقط

$message_type = 'danger'; // النوع الافتراضي للرسالة

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_purchase_item'])) {
    // 1. التحقق من CSRF
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['message'] = "<div class='alert alert-danger'>خطأ: طلب غير صالح (CSRF).</div>";
        // تحديد صفحة العودة الافتراضية إذا لم يكن invoice_id متوفراً
        $redirect_url = isset($_POST['purchase_invoice_id']) && intval($_POST['purchase_invoice_id']) > 0
                        ? BASE_URL . "admin/view_purchase_invoice.php?id=" . intval($_POST['purchase_invoice_id'])
                        : BASE_URL . "admin/manage_purchase_invoices.php";
        header("Location: " . $redirect_url);
        exit;
    }

    // 2. جلب والتحقق من البيانات المرسلة
    $item_id_to_delete = isset($_POST['item_id_to_delete']) ? intval($_POST['item_id_to_delete']) : 0;
    $purchase_invoice_id = isset($_POST['purchase_invoice_id']) ? intval($_POST['purchase_invoice_id']) : 0;
    $product_id_to_adjust = isset($_POST['product_id_to_adjust']) ? intval($_POST['product_id_to_adjust']) : 0;
    $quantity_to_adjust = isset($_POST['quantity_to_adjust']) ? floatval($_POST['quantity_to_adjust']) : 0;

    if ($item_id_to_delete <= 0 || $purchase_invoice_id <= 0 || $product_id_to_adjust <= 0 || $quantity_to_adjust <= 0) {
        $_SESSION['message'] = "<div class='alert alert-danger'>بيانات حذف البند غير كاملة أو غير صالحة.</div>";
        header("Location: " . ($purchase_invoice_id > 0 ? BASE_URL . "admin/view_purchase_invoice.php?id=" . $purchase_invoice_id : BASE_URL . "admin/manage_purchase_invoices.php"));
        exit;
    }

    // 3. التحقق من حالة الفاتورة (لا يمكن حذف بنود من فاتورة ملغاة)
    $sql_check_invoice_status = "SELECT status FROM purchase_invoices WHERE id = ?";
    $stmt_check_status = $conn->prepare($sql_check_invoice_status);
    $stmt_check_status->bind_param("i", $purchase_invoice_id);
    $stmt_check_status->execute();
    $result_status = $stmt_check_status->get_result();
    $invoice_status_data = $result_status->fetch_assoc();
    $stmt_check_status->close();

    if (!$invoice_status_data) {
        $_SESSION['message'] = "<div class='alert alert-danger'>الفاتورة الأصلية غير موجودة.</div>";
    } elseif ($invoice_status_data['status'] == 'cancelled') {
        $_SESSION['message'] = "<div class='alert alert-warning'>لا يمكن حذف بنود من فاتورة ملغاة.</div>";
    } else {
        // 4. بدء المعاملة (Transaction)
        $conn->begin_transaction();
        try {
            // أ. حذف البند من purchase_invoice_items
            $sql_delete_item = "DELETE FROM purchase_invoice_items WHERE id = ? AND purchase_invoice_id = ?";
            $stmt_delete_item = $conn->prepare($sql_delete_item);
            $stmt_delete_item->bind_param("ii", $item_id_to_delete, $purchase_invoice_id);
            $stmt_delete_item->execute();

            if ($stmt_delete_item->affected_rows > 0) {
                // ب. تعديل (خصم) الكمية من رصيد المنتج
                // بما أننا نحذف بند شراء، فهذا يعني أن الكمية "لم تعد" واردة، لذا نخصمها إذا كانت قد أضيفت.
                // لكن في سير عملنا الحالي، الكمية تضاف للمخزون عند "الاستلام" وليس عند إضافة البند لفاتورة الشراء.
                // بما أننا بسطنا العملية وألغينا "الكمية المطلوبة" و "المستلمة" وأصبح لدينا "كمية" واحدة تضاف للمخزون فوراً
                // عند إضافة البند في view_purchase_invoice.php، فإن حذف البند الآن يجب أن يخصم هذه الكمية.
                $sql_update_stock = "UPDATE products SET current_stock = current_stock - ? WHERE id = ?";
                $stmt_update_stock = $conn->prepare($sql_update_stock);
                $stmt_update_stock->bind_param("di", $quantity_to_adjust, $product_id_to_adjust);
                $stmt_update_stock->execute();
                // لا يهم عدد الصفوف المتأثرة هنا بالضرورة، المهم أن الاستعلام نفذ
                $stmt_update_stock->close();

                // ج. إعادة حساب الإجمالي الكلي لفاتورة الشراء وتحديثه
                $sql_recalculate_total = "UPDATE purchase_invoices
                                          SET total_amount = (SELECT COALESCE(SUM(total_cost), 0.00) FROM purchase_invoice_items WHERE purchase_invoice_id = ?)
                                          WHERE id = ?";
                $stmt_recalculate = $conn->prepare($sql_recalculate_total);
                $stmt_recalculate->bind_param("ii", $purchase_invoice_id, $purchase_invoice_id);
                $stmt_recalculate->execute();
                $stmt_recalculate->close();

                $conn->commit(); // تأكيد جميع العمليات
                $_SESSION['message'] = "<div class='alert alert-success'>تم حذف البند من فاتورة المشتريات وتعديل رصيد المخزون بنجاح.</div>";
            } else {
                $conn->rollback(); // تراجع إذا لم يتم حذف البند
                $_SESSION['message'] = "<div class='alert alert-warning'>لم يتم العثور على البند لحذفه أو خطأ ما.</div>";
            }
            $stmt_delete_item->close();

        } catch (mysqli_sql_exception $exception) {
            $conn->rollback(); // تراجع في حالة أي خطأ آخر
            $_SESSION['message'] = "<div class='alert alert-danger'>فشلت عملية الحذف بسبب خطأ: " . $exception->getMessage() . "</div>";
        }
    }
} else {
    // إذا تم الوصول للصفحة مباشرة أو بطلب غير POST['delete_purchase_item']
    $_SESSION['message'] = "<div class='alert alert-warning'>طلب غير صحيح أو مفقود.</div>";
}

// إعادة التوجيه دائماً إلى صفحة عرض الفاتورة (إذا كان ID متوفراً) أو صفحة إدارة الفواتير
$redirect_url = ($purchase_invoice_id > 0)
                ? BASE_URL . "admin/view_purchase_invoice.php?id=" . $purchase_invoice_id
                : BASE_URL . "admin/manage_purchase_invoices.php"; // صفحة إدارة فواتير المشتريات

header("Location: " . $redirect_url);
exit;
?>