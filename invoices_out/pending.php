<?php
$page_title = "الفواتير غير المستلمة";
// $class1 = "active"; // تأكد من استخدام هذا للملاحة إذا لزم الأمر

if (file_exists(dirname(__DIR__) . '/config.php')) {
    require_once dirname(__DIR__) . '/config.php';
} else {
    if (file_exists(dirname(dirname(__DIR__)) . '/config.php')) {
         require_once dirname(dirname(__DIR__)) . '/config.php';
    } else {
        die("ملف config.php غير موجود!");
    }
}

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("Location: " . BASE_URL . "auth/login.php");
    exit;
}

$message = "";
$selected_group = "";
$result = null;
$grand_total_all_pending = 0; // الإجمالي الكلي لكل الفواتير غير المستلمة
$displayed_invoices_sum = 0;  // إجمالي الفواتير المعروضة حالياً بعد الفلتر

if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    unset($_SESSION['message']);
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// --- معالجة زر "تم التسليم" ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['mark_delivered'])) {
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
        $_SESSION['message'] = "<div class='alert alert-danger'>ليس لديك الصلاحية لتنفيذ هذا الإجراء.</div>";
    } elseif (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['message'] = "<div class='alert alert-danger'>خطأ: طلب غير صالح (CSRF detected).</div>";
    } else {
        $invoice_id_to_deliver = intval($_POST['invoice_id_to_deliver']);
        $updated_by = $_SESSION['id'];
        $sql_update_delivery = "UPDATE invoices_out SET delivered = 'yes', updated_by = ?, updated_at = NOW() WHERE id = ?";
        if ($stmt_update = $conn->prepare($sql_update_delivery)) {
            $stmt_update->bind_param("ii", $updated_by, $invoice_id_to_deliver);
            if ($stmt_update->execute()) {
                $_SESSION['message'] = ($stmt_update->affected_rows > 0) ? "<div class='alert alert-success'>تم تحديث حالة الفاتورة رقم #{$invoice_id_to_deliver} إلى مستلمة بنجاح.</div>" : "<div class='alert alert-warning'>لم يتم العثور على الفاتورة أو أنها مستلمة بالفعل.</div>";
            } else {
                $_SESSION['message'] = "<div class='alert alert-danger'>حدث خطأ أثناء تحديث حالة الفاتورة: " . $stmt_update->error . "</div>";
            }
            $stmt_update->close();
        } else {
            $_SESSION['message'] = "<div class='alert alert-danger'>خطأ في تحضير استعلام تحديث الحالة: " . $conn->error . "</div>";
        }
    }
    $redirect_page_path = $_SERVER['PHP_SELF']; // للحفاظ على المسار الحالي
    header("Location: " . $redirect_page_path . (!empty($selected_group) ? "?filter_group_val=" . urlencode($selected_group) : ""));
    exit;
}

// --- معالجة طلب التصفية ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['filter_invoices'])) {
    if (!empty($_POST['invoice_group_filter'])) {
        $selected_group = trim($_POST['invoice_group_filter']);
    }
} elseif (isset($_GET['filter_group_val'])) {
    $selected_group = trim($_GET['filter_group_val']);
}

// --- !! جلب الإجمالي الكلي لجميع الفواتير غير المستلمة (قبل الفلترة) !! ---
$sql_grand_total = "SELECT SUM(ioi.total_price) AS grand_total
                    FROM invoice_out_items ioi
                    JOIN invoices_out io ON ioi.invoice_out_id = io.id
                    WHERE io.delivered = 'no'";
$result_grand_total_query = $conn->query($sql_grand_total);
if ($result_grand_total_query && $result_grand_total_query->num_rows > 0) {
    $row_grand_total = $result_grand_total_query->fetch_assoc();
    $grand_total_all_pending = floatval($row_grand_total['grand_total'] ?? 0);
}
// --- !! نهاية جلب الإجمالي الكلي !! ---


// --- بناء وعرض الفواتير (مع JOIN والبحث وإجمالي كل فاتورة) ---
$sql_select = "SELECT
                    i.id, i.invoice_group, i.created_at,
                    c.name as customer_name, c.mobile as customer_mobile, c.city as customer_city,
                    u.username as creator_name,
                    (SELECT SUM(item.total_price) FROM invoice_out_items item WHERE item.invoice_out_id = i.id) as invoice_total
               FROM invoices_out i
               JOIN customers c ON i.customer_id = c.id
               LEFT JOIN users u ON i.created_by = u.id
               WHERE i.delivered = 'no' ";

$params = [];
$types = "";

if (!empty($selected_group)) {
    $sql_select .= " AND i.invoice_group = ? ";
    $params[] = $selected_group;
    $types .= "s";
}

$sql_select .= " ORDER BY i.created_at DESC, i.id DESC";

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

$view_invoice_page_link = BASE_URL . "invoices_out/create_invoice.php";
$current_page_link = htmlspecialchars($_SERVER["PHP_SELF"]);


require_once BASE_DIR . 'partials/header.php';
require_once BASE_DIR . 'partials/sidebar.php';
?>

<div class="container mt-5 pt-3">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1><i class="fas fa-truck-loading"></i> الفواتير غير المستلمة</h1>
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
            قائمة الفواتير التي لم يتم تسليمها
            <?php if(!empty($selected_group)) { echo " (المجموعة: " . htmlspecialchars($selected_group) . ")"; } ?>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped table-hover table-bordered align-middle">
                    <thead class="table-dark">
                        <tr>
                            <th>رقم الفاتورة</th>
                            <th>اسم العميل</th>
                            <th class="d-none d-md-table-cell">الموبايل</th>
                            <th>مجموعة الفاتورة</th>
                            <th class="d-none d-md-table-cell">أنشئت بواسطة</th>
                            <th class="d-none d-md-table-cell">تاريخ الإنشاء</th>
                            <th class="text-end">إجمالي الفاتورة</th>
                            <th class="text-center">إجراءات</th>
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
                                    <td class="d-none d-md-table-cell"><?php echo htmlspecialchars($row["customer_mobile"]); ?></td>
                                    <td><span class="badge bg-info"><?php echo htmlspecialchars($row["invoice_group"]); ?></span></td>
                                    <td class="d-none d-md-table-cell"><?php echo htmlspecialchars($row["creator_name"] ?? 'غير معروف'); ?></td>
                                    <td class="d-none d-md-table-cell"><?php echo date('Y-m-d H:i A', strtotime($row["created_at"])); ?></td>
                                    <td class="text-end fw-bold"><?php echo number_format($current_invoice_total_for_row, 2); ?> ج.م</td>
                                    <td class="text-center">
                                        <a href="<?php echo $view_invoice_page_link; ?>?id=<?php echo $row["id"]; ?>" class="btn btn-info btn-sm" title="مشاهدة تفاصيل الفاتورة">
                                            <i class="fas fa-eye"></i> مشاهدة
                                        </a>
                                        <?php if (isset($_SESSION['role']) && $_SESSION['role'] == 'admin'): ?>
                                        <form action="<?php echo $current_page_link; ?>" method="post" class="d-inline ms-1">
                                            <input type="hidden" name="invoice_id_to_deliver" value="<?php echo $row["id"]; ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                            <button type="submit" name="mark_delivered" class="btn btn-success btn-sm" title="تحديد هذه الفاتورة كمستلمة">
                                                <i class="fas fa-check-circle"></i> تم التسليم
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8" class="text-center"> <?php echo !empty($selected_group) ? 'لا توجد فواتير غير مستلمة تطابق هذه المجموعة.' : 'لا توجد فواتير غير مستلمة حالياً.'; ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="row mt-4">
        <div class="col-md-6 offset-md-6"> <?php // لجعلها على اليمين أو يمكنك تعديل الـ offset ?>
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
                            <strong>الإجمالي الكلي لجميع الفواتير غير المستلمة:</strong>
                            <span class="badge bg-danger rounded-pill fs-6">
                                <?php echo number_format($grand_total_all_pending, 2); ?> ج.م
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