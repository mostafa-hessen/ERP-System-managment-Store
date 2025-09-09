<?php
// delivered_invoices.php
$page_title = "الفواتير المستلمة";
$class_dashboard = "active";

require_once dirname(__DIR__) . '/config.php';
require_once BASE_DIR . 'partials/session_admin.php';
require_once BASE_DIR . 'partials/header.php';

$message = "";
$selected_group = "";
$result = null;
$grand_total_all_delivered = 0;
$displayed_invoices_sum = 0;

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// --- استقبال رسائل من عمليات POST السابقة (PRG) ---
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    unset($_SESSION['message']);
}

// ========== معالجة تحويل الفاتورة إلى "مؤجلة" (من مستلمة -> لا) ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_pending'])) {
    // تحقق CSRF
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['message'] = "<div class='alert alert-danger'>خطأ: طلب غير صالح (CSRF).</div>";
        header("Location: " . (strpos($_SERVER['PHP_SELF'], '/admin/') !== false ? BASE_URL . 'admin/delivered_invoices.php' : htmlspecialchars($_SERVER['PHP_SELF'])));
        exit;
    }

    // تحقق الصلاحية (فقط الادمن أو من تراه مناسباً)
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
        $_SESSION['message'] = "<div class='alert alert-danger'>ليس لديك صلاحية لتنفيذ هذا الإجراء.</div>";
        header("Location: " . (strpos($_SERVER['PHP_SELF'], '/admin/') !== false ? BASE_URL . 'admin/delivered_invoices.php' : htmlspecialchars($_SERVER['PHP_SELF'])));
        exit;
    }

    $invoice_id = intval($_POST['invoice_id'] ?? 0);
    if ($invoice_id <= 0) {
        $_SESSION['message'] = "<div class='alert alert-warning'>رقم فاتورة غير صالح.</div>";
        header("Location: " . (strpos($_SERVER['PHP_SELF'], '/admin/') !== false ? BASE_URL . 'admin/delivered_invoices.php' : htmlspecialchars($_SERVER['PHP_SELF'])));
        exit;
    }

    // تحديث الحالة في قاعدة البيانات
    $updated_by = intval($_SESSION['id'] ?? 0);
    $sql_update = "UPDATE invoices_out SET delivered = 'no', updated_by = ?, updated_at = NOW() WHERE id = ? AND delivered = 'yes'";
    if ($stmt = $conn->prepare($sql_update)) {
        $stmt->bind_param("ii", $updated_by, $invoice_id);
        if ($stmt->execute()) {
            if ($stmt->affected_rows > 0) {
                $_SESSION['message'] = "<div class='alert alert-success'>تم إرجاع الفاتورة #{$invoice_id} إلى الفواتير المؤجلة بنجاح.</div>";
            } else {
                $_SESSION['message'] = "<div class='alert alert-warning'>لم يتم تعديل حالة الفاتورة — ربما كانت مُؤجلة بالفعل أو غير موجودة.</div>";
            }
        } else {
            $_SESSION['message'] = "<div class='alert alert-danger'>حدث خطأ أثناء تحديث الحالة: " . htmlspecialchars($stmt->error) . "</div>";
        }
        $stmt->close();
    } else {
        $_SESSION['message'] = "<div class='alert alert-danger'>خطأ في تحضير استعلام التحديث: " . htmlspecialchars($conn->error) . "</div>";
    }

    // إعادة توجيه بعد المعالجة (PRG)
    header("Location: " . (strpos($_SERVER['PHP_SELF'], '/admin/') !== false ? BASE_URL . 'admin/delivered_invoices.php' : htmlspecialchars($_SERVER['PHP_SELF'])));
    exit;
}

// ================= قراءة معايير البحث/الفلترة ================
$search_invoice = isset($_REQUEST['q_invoice']) ? trim($_REQUEST['q_invoice']) : '';
$search_mobile  = isset($_REQUEST['q_mobile'])  ? trim($_REQUEST['q_mobile'])  : '';
if (!empty($_REQUEST['invoice_group_filter'])) {
    $selected_group = trim($_REQUEST['invoice_group_filter']);
} elseif (isset($_GET['filter_group_val'])) {
    $selected_group = trim($_GET['filter_group_val']);
}

// --- جلب الإجمالي الكلي لجميع الفواتير المستلمة (قبل الفلترة) ---
$sql_grand_total = "SELECT COALESCE(SUM(ioi.total_price),0) AS grand_total
                    FROM invoice_out_items ioi
                    JOIN invoices_out io ON ioi.invoice_out_id = io.id
                    WHERE io.delivered = 'yes'";
$res_gt = $conn->query($sql_grand_total);
if ($res_gt) {
    $row_gt = $res_gt->fetch_assoc();
    $grand_total_all_delivered = floatval($row_gt['grand_total'] ?? 0);
    $res_gt->free();
}

// ============== بناء استعلام البحث مع شروط ديناميكية (prepared) ===============
$sql_select_base = "
    SELECT i.id, i.invoice_group, i.created_at, i.delivered, i.updated_at as delivery_date,
           c.name as customer_name, c.mobile as customer_mobile, c.city as customer_city,
           u.username as creator_name,
           u_updater.username as delivered_by_user,
           COALESCE((SELECT SUM(item.total_price) FROM invoice_out_items item WHERE item.invoice_out_id = i.id),0) as invoice_total
    FROM invoices_out i
    JOIN customers c ON i.customer_id = c.id
    LEFT JOIN users u ON i.created_by = u.id
    LEFT JOIN users u_updater ON i.updated_by = u_updater.id
    WHERE i.delivered = 'yes'
";

$params = [];
$types = "";

// فلترة بالمجموعة
if ($selected_group !== '') {
    $sql_select_base .= " AND i.invoice_group = ? ";
    $params[] = $selected_group;
    $types .= "s";
}

// بحث برقم الفاتورة (مطابق) — إذا المستخدم أدخل #123 أو 123
if ($search_invoice !== '') {
    $inv = preg_replace('/[^0-9]/', '', $search_invoice);
    if ($inv !== '') {
        $sql_select_base .= " AND i.id = ? ";
        $params[] = intval($inv);
        $types .= "i";
    }
}

// بحث برقم الموبايل (partial match)
if ($search_mobile !== '') {
    $sql_select_base .= " AND c.mobile LIKE ? ";
    $params[] = "%{$search_mobile}%";
    $types .= "s";
}

$sql_select_base .= " ORDER BY i.updated_at DESC, i.id DESC LIMIT 1000";

if ($stmt_select = $conn->prepare($sql_select_base)) {
    if (!empty($params)) {
        $stmt_select->bind_param($types, ...$params);
    }
    if ($stmt_select->execute()) {
        $result = $stmt_select->get_result();
    } else {
        $message .= "<div class='alert alert-danger'>خطأ أثناء تنفيذ البحث: " . htmlspecialchars($stmt_select->error) . "</div>";
    }
    $stmt_select->close();
} else {
    $message .= "<div class='alert alert-danger'>خطأ في تحضير استعلام الفواتير: " . htmlspecialchars($conn->error) . "</div>";
}

// روابط
$view_invoice_page_link = BASE_URL . "invoices_out/view_invoice_detaiels.php";
$edit_invoice_page_link_base = BASE_URL . "invoices_out/edit.php";
$pending_invoices_link = BASE_URL . "admin/pending_invoices.php";
$current_page_link = (strpos($_SERVER['PHP_SELF'], '/admin/') !== false) ? BASE_URL . 'admin/delivered_invoices.php' : htmlspecialchars($_SERVER["PHP_SELF"]);
require_once BASE_DIR . 'partials/sidebar.php';

?>

<div class="container mt-5 pt-3">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1><i class="fas fa-check-double"></i> الفواتير المستلمة</h1>
        <a href="<?php echo $pending_invoices_link; ?>" class="btn btn-warning"><i class="fas fa-truck-loading"></i> عرض الفواتير غير المستلمة</a>
    </div>

    <?php echo $message; // رسالة من العمليات السابقة ?>

    <!-- نموذج البحث -->
    <div class="card mb-4 shadow-sm">
        <div class="card-body">
            <form action="<?php echo $current_page_link; ?>" method="get" class="row gx-2 gy-2 align-items-center">
                <div class="col-md-3 mt-4">
                    <label class="form-label small mb-1" for="q_invoice">بحث برقم الفاتورة</label>
                    <input type="text" name="q_invoice" id="q_invoice" class="form-control" placeholder="مثال: 123 أو #123" value="<?php echo htmlspecialchars($search_invoice); ?>">
                </div>
                <div class="col-md-3 mt-4">
                    <label class="form-label small mb-1" for="q_mobile">بحث برقم هاتف العميل</label>
                    <input type="text" name="q_mobile" id="q_mobile" class="form-control" placeholder="مثال: 011xxxxxxxx" value="<?php echo htmlspecialchars($search_mobile); ?>">
                </div>
                <!-- <div class="col-md-3  mt-4">
                    <label class="form-label small mb-1" for="invoice_group_filter">مجموعة الفاتورة</label>
                    <select name="invoice_group_filter" id="invoice_group_filter" class="form-select">
                        <option value="" <?php echo empty($selected_group) ? 'selected' : ''; ?>>-- كل المجموعات --</option>
                        <?php for ($i = 1; $i <= 11; $i++): $g = "group{$i}"; ?>
                            <option value="<?php echo $g; ?>" <?php echo ($selected_group == $g) ? 'selected' : ''; ?>>Group <?php echo $i; ?></option>
                        <?php endfor; ?>
                    </select>
                </div> -->

                <div class="col-md-3 d-flex gap-2 align-items-end mt-5">
                    <button type="submit" name="search" class="btn btn-primary w-100"><i class="fas fa-search me-2"></i>بحث / تصفية</button>
                    <a href="<?php echo $current_page_link; ?>" class="btn btn-outline-secondary w-100"><i class="fas fa-undo me-2"></i>مسح</a>
                </div>
            </form>
            <div class="small mt-2 note-text">يمكنك البحث بالرقم الدقيق للفاتورة أو بأي جزء من رقم الموبايل.</div>
        </div>
    </div>

    <!-- جدول النتائج -->
    <div class="card shadow">
        <div class="card-header">
            قائمة الفواتير التي تم تسليمها
            <?php if(!empty($selected_group)) { echo " (المجموعة: " . htmlspecialchars($selected_group) . ")"; } ?>
            <?php if($search_invoice || $search_mobile): ?>
                <span class="badge bg-info ms-2">نتائج البحث</span>
            <?php endif; ?>
        </div>

        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped table-hover table-bordered align-middle mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th>رقم الفاتورة</th>
                            <th>اسم العميل</th>
                            <th>الموبايل</th>
                            <th>مجموعة الفاتورة</th>
                            <th class="d-none d-md-table-cell">تم التسليم بواسطة</th>
                            <th class="d-none d-md-table-cell">تاريخ التسليم</th>
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
                                    <td>#<?php echo htmlspecialchars($row["id"]); ?></td>
                                    <td><?php echo htmlspecialchars($row["customer_name"]); ?></td>
                                    <td><?php echo htmlspecialchars($row["customer_mobile"]); ?></td>
                                    <td><span class="badge bg-info"><?php echo htmlspecialchars($row["invoice_group"]); ?></span></td>
                                    <td class="d-none d-md-table-cell"><?php echo htmlspecialchars($row["delivered_by_user"] ?? ($row["creator_name"] ?? 'غير معروف')); ?></td>
                                    <td class="d-none d-md-table-cell"><?php echo htmlspecialchars(date('Y-m-d H:i A', strtotime($row["delivery_date"]))); ?></td>
                                    <td class="text-end fw-bold"><?php echo number_format($current_invoice_total_for_row, 2); ?> ج.م</td>
                                    <td class="text-center">
                                        <!-- مشاهدة -->
                                        <a href="<?php echo $view_invoice_page_link; ?>?id=<?php echo urlencode($row["id"]); ?>" class="btn btn-info btn-sm" title="مشاهدة">
                                            <i class="fas fa-eye"></i>
                                        </a>

                                        <?php if (isset($_SESSION['role']) && $_SESSION['role'] == 'admin'): ?>
                                            <!-- زر إعادة الفاتورة للمؤجلة -->
                                            <form method="post" action="<?php echo $current_page_link; ?>" class="d-inline ms-1" onsubmit="return confirm('سيتم إرجاع الفاتورة #<?php echo htmlspecialchars($row['id']); ?> إلى الفواتير المؤجلة. هل أنت متأكد؟');">
                                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                                <input type="hidden" name="invoice_id" value="<?php echo htmlspecialchars($row["id"]); ?>">
                                                <button type="submit" name="mark_pending" class="btn btn-outline-secondary btn-sm" title="إرجاع للمؤجلة">
                                                    <i class="fas fa-undo"></i>
                                                </button>
                                            </form>

                                            <!-- حذف -->
                                            <form action="<?php echo BASE_URL; ?>admin/delete_sales_invoice.php" method="post" class="d-inline ms-1" onsubmit="return confirm('هل أنت متأكد من حذف الفاتورة #<?php echo htmlspecialchars($row['id']); ?>؟ سيتم إعادة الكميات للمخزون.');">
                                                <input type="hidden" name="invoice_out_id_to_delete" value="<?php echo htmlspecialchars($row["id"]); ?>">
                                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                                <button type="submit" name="delete_sales_invoice" class="btn btn-danger btn-sm" title="حذف">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8" class="text-center py-4">
                                    <?php
                                        if ($search_invoice || $search_mobile) {
                                            echo "لا توجد نتائج مطابقة لبحثك.";
                                        } elseif (!empty($selected_group)) {
                                            echo "لا توجد فواتير مستلمة تطابق هذه المجموعة.";
                                        } else {
                                            echo "لا توجد فواتير مستلمة حالياً.";
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
                            <strong>الإجمالي الكلي لجميع الفواتير المستلمة:</strong>
                            <span class="badge bg-success rounded-pill fs-6"><?php echo number_format($grand_total_all_delivered, 2); ?> ج.م</span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

</div>

<?php
// تحرير الموارد
if ($result && is_object($result)) $result->free();
$conn->close();
require_once BASE_DIR . 'partials/footer.php';
?>
