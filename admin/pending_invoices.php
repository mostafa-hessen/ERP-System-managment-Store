<?php
// pending_invoices.php (بحث بواسطة رقم الفاتورة أو موبايل العميل + فلتر مجموعة)
// تأكد أن المسارات BASE_DIR و BASE_URL صحيحة في موقعك

$page_title = "الفواتير غير المستلمة";
$class_dashboard = "active";

require_once dirname(__DIR__) . '/config.php';
require_once BASE_DIR . 'partials/session_admin.php';
require_once BASE_DIR . 'partials/header.php';

// دالة إخراج آمن
function e($s){ return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

$message = "";
$result = null;
$grand_total_all_pending = 0;
$displayed_invoices_sum = 0;

if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    unset($_SESSION['message']);
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

/*
  طريقة الإدخال:
  - بحث عبر GET:
      invoice_q  => رقم الفاتورة (مطابق)
      mobile_q   => رقم هاتف العميل (جزئي)
      filter_group_val => مجموعة (group1..group11)
  - "تم التسليم" يُرسل عبر POST (form صغير) ويعيد التوجيه (PRG) مع params السابقة
*/

// قراءة قيم البحث / الفلتر من GET
$invoice_q = isset($_GET['invoice_q']) ? trim($_GET['invoice_q']) : '';
$mobile_q  = isset($_GET['mobile_q']) ? trim($_GET['mobile_q']) : '';
$selected_group = isset($_GET['filter_group_val']) ? trim($_GET['filter_group_val']) : '';

// معالجة POST: حالة "تم التسليم"
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['mark_delivered'])) {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['message'] = "<div class='alert alert-danger'>خطأ: طلب غير صالح (CSRF).</div>";
    } else {
        $invoice_id_to_deliver = intval($_POST['invoice_id_to_deliver']);
        $updated_by = $_SESSION['id'] ?? null;
        $sql_update_delivery = "UPDATE invoices_out SET delivered = 'yes', updated_by = ?, updated_at = NOW() WHERE id = ?";
        if ($stmt_update = $conn->prepare($sql_update_delivery)) {
            $stmt_update->bind_param("ii", $updated_by, $invoice_id_to_deliver);
            if ($stmt_update->execute()) {
                if ($stmt_update->affected_rows > 0) {
                    $_SESSION['message'] = "<div class='alert alert-success'>تم تحديث حالة الفاتورة رقم #{$invoice_id_to_deliver} إلى مستلمة بنجاح.</div>";
                } else {
                    $_SESSION['message'] = "<div class='alert alert-warning'>لم يتم العثور على الفاتورة أو أنها مستلمة بالفعل.</div>";
                }
            } else {
                $_SESSION['message'] = "<div class='alert alert-danger'>حدث خطأ أثناء تحديث حالة الفاتورة: " . e($stmt_update->error) . "</div>";
            }
            $stmt_update->close();
        } else {
            $_SESSION['message'] = "<div class='alert alert-danger'>خطأ في تحضير استعلام تحديث الحالة: " . e($conn->error) . "</div>";
        }
    }

    // إعادة التوجيه (PRG) مع الحفاظ على بارامترات البحث والفلتر (GET)
    $redirect = htmlspecialchars($_SERVER['PHP_SELF']);
    $params = [];
    if ($invoice_q !== '') $params[] = 'invoice_q=' . urlencode($invoice_q);
    if ($mobile_q !== '')  $params[] = 'mobile_q=' . urlencode($mobile_q);
    if ($selected_group !== '') $params[] = 'filter_group_val=' . urlencode($selected_group);
    if (!empty($params)) $redirect .= '?' . implode('&', $params);
    header("Location: " . $redirect);
    exit;
}

// إجمالي الفواتير غير المستلمة (بدون تطبيق البحث) لتلخيص
$sql_grand_total = "SELECT SUM(ioi.total_price) AS grand_total
                    FROM invoice_out_items ioi
                    JOIN invoices_out io ON ioi.invoice_out_id = io.id
                    WHERE io.delivered = 'no'";
$res_gt = $conn->query($sql_grand_total);
if ($res_gt && $res_gt->num_rows > 0) {
    $grand_total_all_pending = floatval($res_gt->fetch_assoc()['grand_total'] ?? 0);
}

// بناء استعلام جلب الفواتير غير المستلمة مع شروط البحث
$sql_select = "SELECT i.id, i.invoice_group, i.created_at,
                      c.name as customer_name, c.mobile as customer_mobile, c.city as customer_city,
                      u.username as creator_name,
                      (SELECT SUM(item.total_price) FROM invoice_out_items item WHERE item.invoice_out_id = i.id) as invoice_total
               FROM invoices_out i
               JOIN customers c ON i.customer_id = c.id
               LEFT JOIN users u ON i.created_by = u.id
               WHERE i.delivered = 'no' ";

$params = [];
$types = "";

// فلتر المجموعة (إن وُجد)
if ($selected_group !== '') {
    $sql_select .= " AND i.invoice_group = ? ";
    $types .= "s";
    $params[] = $selected_group;
}

// قواعد البحث: رقم الفاتورة يأخذ الأولوية إذا معطى
if ($invoice_q !== '') {
    // رقم الفاتورة يجب أن يكون عدديًا
    if (ctype_digit($invoice_q)) {
        $sql_select .= " AND i.id = ? ";
        $types .= "i";
        $params[] = intval($invoice_q);
    } else {
        // إذا أدخل المستخدم نص في حقل رقم الفاتورة -> لن نضيف شرط (يمكن تحسين لاحقاً)
    }
} elseif ($mobile_q !== '') {
    // البحث في موبايل العميل (جزئي)
    $sql_select .= " AND c.mobile LIKE ? ";
    $types .= "s";
    $params[] = '%' . $mobile_q . '%';
}

$sql_select .= " ORDER BY i.created_at DESC, i.id DESC";

if ($stmt = $conn->prepare($sql_select)) {
    if (!empty($params)) {
        // ربط الوسائط ديناميكياً
        $bind_vars = [];
        $bind_vars[] = $types;
        for ($i=0; $i < count($params); $i++) $bind_vars[] = &$params[$i];
        call_user_func_array([$stmt, 'bind_param'], $bind_vars);
    }
    if ($stmt->execute()) {
        $result = $stmt->get_result();
    } else {
        $message = "<div class='alert alert-danger'>خطأ أثناء تنفيذ استعلام جلب الفواتير: " . e($stmt->error) . "</div>";
    }
    $stmt->close();
} else {
    $message = "<div class='alert alert-danger'>خطأ في تحضير استعلام جلب الفواتير: " . e($conn->error) . "</div>";
}

// روابط صفحات مساعدة
$view_invoice_page_link = BASE_URL . "invoices_out/view_invoice_detaiels.php";
$edit_invoice_page_link_base = BASE_URL . "invoices_out/edit.php";
$delivered_invoices_link = BASE_URL . "admin/delivered_invoices.php";
$current_page_link = htmlspecialchars($_SERVER["PHP_SELF"]);


require_once BASE_DIR . 'partials/sidebar.php';

?>

<div class="container mt-5 pt-3">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1><i class="fas fa-truck-loading"></i> الفواتير غير المستلمة</h1>
        <a href="<?php echo $delivered_invoices_link; ?>" class="btn btn-success"><i class="fas fa-check-double"></i> عرض الفواتير المستلمة</a>
    </div>

    <?php echo $message; ?>

    <!-- نموذج البحث: حقلين منفصلين (رقم الفاتورة / موبايل العميل) -->
    <div class="card mb-4 shadow-sm">
        <div class="card-body">
            <form method="get" action="<?php echo $current_page_link; ?>" class="row gx-3 gy-2 align-items-center">
                <div class="col-md-4 mt-4">
                    <label class="form-label small mb-1">بحث برقم الفاتورة</label>
                    <input type="text" name="invoice_q" value="<?php echo e($invoice_q); ?>" class="form-control" placeholder="مثال: 123">
                </div>
                <div class="col-md-4 mt-4">
                    <label class="form-label small mb-1">أو بحث برقم هاتف العميل</label>
                    <input type="text" name="mobile_q" value="<?php echo e($mobile_q); ?>" class="form-control" placeholder="مثال: 01157787113 (جزئي مقبول)">
                </div>
                <!-- <div class="col-md-2">
                    <label class="form-label small mb-1">مجموعة الفاتورة</label>
                    <select name="filter_group_val" class="form-select">
                        <option value="">-- كل المجموعات --</option>
                        <?php for ($i = 1; $i <= 11; $i++):
                            $g = "group{$i}";
                        ?>
                            <option value="<?php echo $g; ?>" <?php echo ($selected_group == $g) ? 'selected' : ''; ?>><?php echo "Group {$i}"; ?></option>
                        <?php endfor; ?>
                    </select>
                </div> -->
                <div class="col-md-4 mt-5 d-flex gap-2 align-items-end">
                    <button type="submit" class="btn btn-primary w-100"><i class="fas fa-search"></i> بحث</button>
                    <a href="<?php echo $current_page_link; ?>" class="btn btn-outline-secondary w-100"><i class="fas fa-times"></i> مسح</a>
                </div>
            </form>
            <div class="note-text mt-3 ">أدخل إما رقم الفاتورة أو رقم موبايل العميل — يتم إعطاء أولوية لرقم الفاتورة إن وُجد.</div>
        </div>
    </div>

    <!-- جدول الفواتير -->
    <div class="card shadow">
        <div class="card-header">
            قائمة الفواتير التي لم يتم تسليمها
            <?php
                if ($selected_group !== '') echo " (المجموعة: " . e($selected_group) . ")";
                if ($invoice_q !== '') echo " — نتائج البحث عن فاتورة رقم: <strong>" . e($invoice_q) . "</strong>";
                elseif ($mobile_q !== '') echo " — نتائج البحث لرقم الموبايل: <strong>" . e($mobile_q) . "</strong>";
            ?>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped table-hover table-bordered align-middle">
                    <thead class="table-dark">
                        <tr>
                            <th>رقم الفاتورة</th>
                            <th>اسم العميل</th>
                            <th>الموبايل</th>
                            <th>مجموعة الفاتورة</th>
                            <th class="d-none d-md-table-cell">أنشئت بواسطة</th>
                            <th class="d-none d-md-table-cell">تاريخ الإنشاء</th>
                            <th class="text-end">إجمالي الفاتورة</th>
                            <th class="text-center">إجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result && $result->num_rows > 0): ?>
                            <?php while($row = $result->fetch_assoc()):
                                $current_invoice_total_for_row = floatval($row["invoice_total"] ?? 0);
                                $displayed_invoices_sum += $current_invoice_total_for_row;
                            ?>
                                <tr>
                                    <td>#<?php echo e($row["id"]); ?></td>
                                    <td><?php echo e($row["customer_name"]); ?></td>
                                    <td><?php echo e($row["customer_mobile"]); ?></td>
                                    <td><span class="badge bg-info"><?php echo e($row["invoice_group"]); ?></span></td>
                                    <td class="d-none d-md-table-cell"><?php echo e($row["creator_name"] ?? 'غير معروف'); ?></td>
                                    <td class="d-none d-md-table-cell"><?php echo e(date('Y-m-d H:i A', strtotime($row["created_at"]))); ?></td>
                                    <td class="text-end fw-bold"><?php echo number_format($current_invoice_total_for_row, 2); ?> ج.م</td>
                                    <td class="text-center">
                                        <a href="<?php echo $view_invoice_page_link; ?>?id=<?php echo e($row["id"]); ?>" class="btn btn-info btn-sm" title="مشاهدة تفاصيل الفاتورة"><i class="fas fa-eye"></i></a>
                                        <?php if (isset($_SESSION['role']) && $_SESSION['role'] == 'admin'): ?>
                                            <!-- <a href="<?php echo $edit_invoice_page_link_base; ?>?id=<?php echo e($row["id"]); ?>" class="btn btn-warning btn-sm ms-1" title="تعديل الفاتورة"><i class="fas fa-edit"></i></a> -->

                                            <form action="<?php echo $current_page_link; ?>" method="post" class="d-inline ms-1">
                                                <input type="hidden" name="invoice_id_to_deliver" value="<?php echo e($row["id"]); ?>">
                                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                                <button type="submit" name="mark_delivered" class="btn btn-success btn-sm" title="تحديد هذه الفاتورة كمستلمة"><i class="fas fa-check-circle"></i></button>
                                            </form>

                                            <form action="<?php echo BASE_URL; ?>admin/delete_sales_invoice.php" method="post" class="d-inline ms-1">
                                                <input type="hidden" name="invoice_out_id_to_delete" value="<?php echo e($row["id"]); ?>">
                                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                                <button type="submit" name="delete_sales_invoice" class="btn btn-danger btn-sm"
                                                        onclick="return confirm('هل أنت متأكد من حذف هذه الفاتورة (#<?php echo e($row["id"]); ?>) وكل بنودها؟ سيتم إعادة الكميات للمخزون.');"
                                                        title="حذف فاتورة المبيعات"><i class="fas fa-trash"></i></button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8" class="text-center">
                                    <?php
                                        if ($invoice_q !== '') {
                                            echo 'لا توجد فواتير تطابق رقم الفاتورة #' . e($invoice_q) . '.';
                                        } elseif ($mobile_q !== '') {
                                            echo 'لا توجد فواتير تطابق رقم الموبايل "' . e($mobile_q) . '".';
                                        } elseif ($selected_group !== '') {
                                            echo 'لا توجد فواتير غير مستلمة في هذه المجموعة.';
                                        } else {
                                            echo 'لا توجد فواتير غير مستلمة حالياً.';
                                        }
                                    ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ملخص الإجماليات -->
    <div class="row mt-4">
        <div class="col-md-6 offset-md-6">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title text-center mb-3 note-text">ملخص الإجماليات</h5>
                    <ul class="list-group list-group-flush rounded">
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <strong>إجمالي الفواتير المعروضة حالياً:</strong>
                            <span class="badge bg-primary rounded-pill fs-6"><?php echo number_format($displayed_invoices_sum, 2); ?> ج.م</span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <strong>الإجمالي الكلي لجميع الفواتير غير المستلمة:</strong>
                            <span class="badge bg-danger rounded-pill fs-6"><?php echo number_format($grand_total_all_pending, 2); ?> ج.م</span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

</div>

<?php
$conn->close();
require_once BASE_DIR . 'partials/footer.php';
?>
