<?php
$page_title = "الفواتير المستلمة";
$class_dashboard = "active";
// تأكد أن المسار لـ config.php صحيح إذا كانت هذه الصفحة داخل مجلد admin
require_once dirname(__DIR__) . '/config.php'; // للوصول لـ config.php من داخل مجلد admin
require_once BASE_DIR . 'partials/session_admin.php'; // هذه الصفحة للمدير فقط
require_once BASE_DIR . 'partials/header.php';
require_once BASE_DIR . 'partials/sidebar.php';

$message = "";
$selected_group = "";
$result = null;
$grand_total_all_delivered = 0; // الإجمالي الكلي لكل الفواتير المستلمة
$displayed_invoices_sum = 0;  // إجمالي الفواتير المعروضة حالياً بعد الفلتر

if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    unset($_SESSION['message']);
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];


if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['filter_invoices'])) {
    if (!empty($_POST['invoice_group_filter'])) {
        $selected_group = trim($_POST['invoice_group_filter']);
    }
} elseif (isset($_GET['filter_group_val'])) {
    $selected_group = trim($_GET['filter_group_val']);
}

// --- !! جلب الإجمالي الكلي لجميع الفواتير المستلمة (قبل الفلترة) !! ---
$sql_grand_total = "SELECT SUM(ioi.total_price) AS grand_total
                    FROM invoice_out_items ioi
                    JOIN invoices_out io ON ioi.invoice_out_id = io.id
                    WHERE io.delivered = 'yes'"; // <<< الفواتير المستلمة
$result_grand_total_query = $conn->query($sql_grand_total);
if ($result_grand_total_query && $result_grand_total_query->num_rows > 0) {
    $row_grand_total = $result_grand_total_query->fetch_assoc();
    $grand_total_all_delivered = floatval($row_grand_total['grand_total'] ?? 0);
}
// --- !! نهاية جلب الإجمالي الكلي !! ---


// --- بناء وعرض الفواتير المستلمة (مع إجمالي كل فاتورة) ---
$sql_select = "SELECT i.id, i.invoice_group, i.created_at, i.delivered, i.updated_at as delivery_date,
                      c.name as customer_name, c.mobile as customer_mobile, c.city as customer_city,
                      u.username as creator_name,
                      u_updater.username as delivered_by_user,
                      (SELECT SUM(item.total_price) FROM invoice_out_items item WHERE item.invoice_out_id = i.id) as invoice_total
               FROM invoices_out i
               JOIN customers c ON i.customer_id = c.id
               LEFT JOIN users u ON i.created_by = u.id
               LEFT JOIN users u_updater ON i.updated_by = u_updater.id
               WHERE i.delivered = 'yes' "; // <<< الشرط الأساسي: الفواتير المستلمة

$params = [];
$types = "";

if (!empty($selected_group)) {
    $sql_select .= " AND i.invoice_group = ? ";
    $params[] = $selected_group;
    $types .= "s";
}

$sql_select .= " ORDER BY i.updated_at DESC, i.id DESC";

if ($stmt_select = $conn->prepare($sql_select)) {
    if (!empty($params)) {
        $stmt_select->bind_param($types, ...$params);
    }
    if ($stmt_select->execute()) {
        $result = $stmt_select->get_result();
    } else {
        $message = "<div class='alert alert-danger'>حدث خطأ أثناء جلب بيانات الفواتير: " . $stmt_select->error . "</div>";
    }
    $stmt_select->close();
} else {
    $message = "<div class='alert alert-danger'>خطأ في تحضير استعلام جلب الفواتير: " . $conn->error . "</div>";
}

// تحديد مسارات الروابط
$view_invoice_page_link = BASE_URL . "invoices_out/view.php"; // افترض أن view.php في مجلد invoices_out
$edit_invoice_page_link_base = BASE_URL . "invoices_out/edit.php"; // افترض أن edit_invoice.php في مجلد admin
$pending_invoices_link = BASE_URL . "admin/pending_invoices.php"; // افترض أن pending_invoices.php في مجلد admin
$current_page_link = htmlspecialchars($_SERVER["PHP_SELF"]); // أو المسار الصحيح إذا كانت الصفحة داخل مجلد admin
if (strpos($_SERVER['PHP_SELF'], '/admin/') !== false) {
    $current_page_link = BASE_URL . 'admin/delivered_invoices.php';
}

?>

<div class="container mt-5 pt-3">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1><i class="fas fa-check-double"></i> الفواتير المستلمة</h1>
        <a href="<?php echo $pending_invoices_link; ?>" class="btn btn-outline-warning"><i class="fas fa-truck-loading"></i> عرض الفواتير غير المستلمة</a>
    </div>

    <?php echo $message; ?>

    <div class="card mb-4 shadow-sm">
        <div class="card-body">
            <form action="<?php echo $current_page_link; ?>" method="post" class="row gx-3 gy-2 align-items-center">
                <div class="col-sm-5">
                    <label class="visually-hidden" for="invoice_group_filter">مجموعة الفاتورة</label>
                    <select name="invoice_group_filter" id="invoice_group_filter" class="form-select">
                        <option value="" <?php echo empty($selected_group) ? 'selected' : ''; ?>>-- كل المجموعات --</option>
                        <?php for ($i = 1; $i <= 11; $i++): ?>
                            <option value="group<?php echo $i; ?>" <?php echo ($selected_group == "group{$i}") ? 'selected' : ''; ?>>
                                Group <?php echo $i; ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-sm-3">
                    <button type="submit" name="filter_invoices" class="btn btn-primary w-100">
                        <i class="fas fa-filter"></i> تصفية
                    </button>
                </div>
                <?php if(!empty($selected_group)): ?>
                <div class="col-sm-4">
                     <a href="<?php echo $current_page_link; ?>" class="btn btn-outline-secondary w-100">
                        <i class="fas fa-times"></i> عرض الكل
                     </a>
                </div>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <div class="card shadow">
        <div class="card-header">
            قائمة الفواتير التي تم تسليمها
            <?php if(!empty($selected_group)) { echo " (المجموعة: " . htmlspecialchars($selected_group) . ")"; } ?>
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
                            <th class="d-none d-md-table-cell">تم التسليم بواسطة</th>
                            <th class="d-none d-md-table-cell">تاريخ التسليم</th>
                            <th class="text-end">إجمالي الفاتورة</th> <th class="text-center">إجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result && $result->num_rows > 0): ?>
                            <?php while($row = $result->fetch_assoc()): ?>
                                <?php
                                $current_invoice_total_for_row = floatval($row["invoice_total"] ?? 0);
                                $displayed_invoices_sum += $current_invoice_total_for_row; // جمع إجمالي الفواتير المعروضة
                                ?>
                                <tr>
                                    <td>#<?php echo $row["id"]; ?></td>
                                    <td><?php echo htmlspecialchars($row["customer_name"]); ?></td>
                                    <td><?php echo htmlspecialchars($row["customer_mobile"]); ?></td>
                                    <td><span class="badge bg-info"><?php echo htmlspecialchars($row["invoice_group"]); ?></span></td>
                                    <td class="d-none d-md-table-cell"><?php echo htmlspecialchars($row["delivered_by_user"] ?? ($row["creator_name"] ?? 'غير معروف')); ?></td>
                                    <td class="d-none d-md-table-cell"><?php echo date('Y-m-d H:i A', strtotime($row["delivery_date"])); ?></td>
                                    <td class="text-end fw-bold"><?php echo number_format($current_invoice_total_for_row, 2); ?> ج.م</td>
                                    <td class="text-center">
                                        <a href="<?php echo $view_invoice_page_link; ?>?id=<?php echo $row["id"]; ?>" class="btn btn-info btn-sm" title="مشاهدة تفاصيل الفاتورة">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <?php if (isset($_SESSION['role']) && $_SESSION['role'] == 'admin'): ?>
                                            <a href="<?php echo $edit_invoice_page_link_base; ?>?id=<?php echo $row["id"]; ?>" class="btn btn-warning btn-sm ms-1" title="تعديل الفاتورة">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <?php if($page_title == "الفواتير غير المستلمة"): // زر تم التسليم يظهر فقط في صفحة الفواتير غير المستلمة ?>
                                            <form action="<?php echo $current_page_link; ?>" method="post" class="d-inline ms-1">
                                                <input type="hidden" name="invoice_id_to_deliver" value="<?php echo $row["id"]; ?>">
                                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                                <button type="submit" name="mark_delivered" class="btn btn-success btn-sm" title="تحديد هذه الفاتورة كمستلمة">
                                                    <i class="fas fa-check-circle"></i>
                                                </button>
                                            </form>
                                            <?php endif; ?>
                                            <form action="<?php echo BASE_URL; ?>admin/delete_sales_invoice.php" method="post" class="d-inline ms-1">
                                                <input type="hidden" name="invoice_out_id_to_delete" value="<?php echo $row["id"]; ?>">
                                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                              <button type="submit" 
        name="delete_sales_invoice" 
        class="btn btn-danger btn-sm"
        title="حذف فاتورة المبيعات"
        onclick="return confirm('هل أنت متأكد من حذف هذه الفاتورة <?php echo $row["id"]; ?>) وكل بنودها؟ سيتم إعادة الكميات للمخزون.');">
    <i class="fas fa-trash"></i>
</button>

                                            </form>
                                            <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8" class="text-center"> <?php echo !empty($selected_group) ? 'لا توجد فواتير مستلمة تطابق هذه المجموعة.' : 'لا توجد فواتير مستلمة حالياً.'; ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="row mt-4">
        <div class="col-md-6 offset-md-6">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title text-center mb-3">ملخص الإجماليات</h5>
                    <ul class="list-group list-group-flush">
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <strong>إجمالي الفواتير المعروضة حالياً:</strong>
                            <span class="badge bg-primary rounded-pill fs-6">
                                <?php echo number_format($displayed_invoices_sum, 2); ?> ج.م
                            </span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <strong>الإجمالي الكلي لجميع الفواتير المستلمة:</strong>
                            <span class="badge bg-success rounded-pill fs-6"> <?php echo number_format($grand_total_all_delivered, 2); ?> ج.م
                            </span>
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