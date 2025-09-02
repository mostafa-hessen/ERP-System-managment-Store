<?php
$page_title = "إدارة فواتير المشتريات";
$class_dashboard = "active";
require_once dirname(__DIR__) . '/config.php';
require_once BASE_DIR . 'partials/session_admin.php'; // صلاحيات المدير فقط

$message = "";
$selected_supplier_id = "";
$selected_status = "";
$result_invoices = null;
$grand_total_all_purchases = 0;
$displayed_invoices_sum = 0;
$suppliers_list = [];

$status_labels = [
    'pending' => 'قيد الانتظار',
    'partial_received' => 'تم الاستلام جزئياً',
    'fully_received' => 'تم الاستلام بالكامل',
    'cancelled' => 'ملغاة'
];

// --- جلب الرسائل من الجلسة ---
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    unset($_SESSION['message']);
}

// --- جلب توكن CSRF ---
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// --- معالجة الحذف ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_purchase_invoice'])) {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['message'] = "<div class='alert alert-danger'>خطأ: طلب غير صالح (CSRF).</div>";
    } else {
        $invoice_id_to_delete = intval($_POST['purchase_invoice_id_to_delete']);
        $sql_delete = "DELETE FROM purchase_invoices WHERE id = ?";
        if ($stmt_delete = $conn->prepare($sql_delete)) {
            $stmt_delete->bind_param("i", $invoice_id_to_delete);
            if ($stmt_delete->execute()) {
                $_SESSION['message'] = ($stmt_delete->affected_rows > 0) ? "<div class='alert alert-success'>تم حذف فاتورة المشتريات وبنودها بنجاح.</div>" : "<div class='alert alert-warning'>لم يتم العثور على الفاتورة أو لم يتم حذفها.</div>";
            } else {
                $_SESSION['message'] = "<div class='alert alert-danger'>حدث خطأ أثناء حذف الفاتورة: " . $stmt_delete->error . "</div>";
            }
            $stmt_delete->close();
        } else {
            $_SESSION['message'] = "<div class='alert alert-danger'>خطأ في تحضير استعلام الحذف: " . $conn->error . "</div>";
        }
    }
    $query_params = [];
    if (!empty($_POST['supplier_filter_val'])) $query_params['supplier_filter_val'] = $_POST['supplier_filter_val'];
    if (!empty($_POST['status_filter_val'])) $query_params['status_filter_val'] = $_POST['status_filter_val'];
    header("Location: manage_purchase_invoices.php" . (!empty($query_params) ? "?" . http_build_query($query_params) : ""));
    exit;
}

// --- معالجة طلب التصفية ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['filter_purchases'])) {
    $selected_supplier_id = isset($_POST['supplier_filter']) ? intval($_POST['supplier_filter']) : "";
    $selected_status = isset($_POST['status_filter']) ? trim($_POST['status_filter']) : "";
} elseif ($_SERVER["REQUEST_METHOD"] == "GET") {
    $selected_supplier_id = isset($_GET['supplier_filter_val']) ? intval($_GET['supplier_filter_val']) : "";
    $selected_status = isset($_GET['status_filter_val']) ? trim($_GET['status_filter_val']) : "";
}

// --- جلب قائمة الموردين للفلتر ---
$sql_suppliers = "SELECT id, name FROM suppliers ORDER BY name ASC";
$result_s = $conn->query($sql_suppliers);
if ($result_s) {
    while ($row_s = $result_s->fetch_assoc()) {
        $suppliers_list[] = $row_s;
    }
}

// --- جلب الإجمالي الكلي لفواتير المشتريات (غير الملغاة) ---
$sql_grand_total = "SELECT SUM(total_amount) AS grand_total FROM purchase_invoices WHERE status != 'cancelled'";
$result_grand_total_query = $conn->query($sql_grand_total);
if ($result_grand_total_query && $result_grand_total_query->num_rows > 0) {
    $row_grand_total = $result_grand_total_query->fetch_assoc();
    $grand_total_all_purchases = floatval($row_grand_total['grand_total'] ?? 0);
}

// --- بناء استعلام الفواتير مع الفلاتر ---
$sql_select_invoices = "SELECT pi.id, pi.supplier_invoice_number, pi.purchase_date, pi.status, pi.total_amount, pi.created_at,
                               s.name as supplier_name,
                               u.username as creator_name
                        FROM purchase_invoices pi
                        JOIN suppliers s ON pi.supplier_id = s.id
                        LEFT JOIN users u ON pi.created_by = u.id";

$conditions = [];
$params = [];
$types = "";

if (!empty($selected_supplier_id)) {
    $conditions[] = "pi.supplier_id = ?";
    $params[] = $selected_supplier_id;
    $types .= "i";
}
if (!empty($selected_status)) {
    $conditions[] = "pi.status = ?";
    $params[] = $selected_status;
    $types .= "s";
}

if (!empty($conditions)) {
    $sql_select_invoices .= " WHERE " . implode(" AND ", $conditions);
}
$sql_select_invoices .= " ORDER BY pi.purchase_date DESC, pi.id DESC";

if ($stmt_select = $conn->prepare($sql_select_invoices)) {
    if (!empty($params)) {
        $stmt_select->bind_param($types, ...$params);
    }
    if ($stmt_select->execute()) {
        $result_invoices = $stmt_select->get_result();
    } else {
        $message = "<div class='alert alert-danger'>حدث خطأ أثناء جلب فواتير المشتريات: " . $stmt_select->error . "</div>";
    }
    $stmt_select->close();
} else {
    $message = "<div class='alert alert-danger'>خطأ في تحضير استعلام جلب فواتير المشتريات: " . $conn->error . "</div>";
}

// --- حساب إجمالي الفواتير المعروضة حالياً مباشرة من قاعدة البيانات ---
$displayed_invoices_sum = 0;
$sql_total_displayed = "SELECT SUM(total_amount) AS total_displayed FROM purchase_invoices pi WHERE 1=1";
$conditions_total = [];
$params_total = [];
$types_total = "";

if (!empty($selected_supplier_id)) {
    $conditions_total[] = "pi.supplier_id = ?";
    $params_total[] = $selected_supplier_id;
    $types_total .= "i";
}
if (!empty($selected_status)) {
    $conditions_total[] = "pi.status = ?";
    $params_total[] = $selected_status;
    $types_total .= "s";
}
if (!empty($conditions_total)) {
    $sql_total_displayed .= " AND " . implode(" AND ", $conditions_total);
}

if ($stmt_total = $conn->prepare($sql_total_displayed)) {
    if (!empty($params_total)) {
        $stmt_total->bind_param($types_total, ...$params_total);
    }
    $stmt_total->execute();
    $result_total = $stmt_total->get_result();
    $row_total = $result_total->fetch_assoc();
    $displayed_invoices_sum = floatval($row_total['total_displayed'] ?? 0);
    $stmt_total->close();
}

$view_purchase_invoice_link = BASE_URL . "admin/view_purchase_invoice.php";
$edit_purchase_invoice_link = BASE_URL . "admin/edit_purchase_invoice.php";
$current_page_url_for_forms = htmlspecialchars($_SERVER["PHP_SELF"]);

require_once BASE_DIR . 'partials/header.php';
require_once BASE_DIR . 'partials/navbar.php';
?>

<div class="container mt-5 pt-3">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1><i class="fas fa-dolly-flatbed"></i> إدارة فواتير المشتريات</h1>
        <a href="<?php echo BASE_URL; ?>admin/manage_suppliers.php" class="btn btn-success">
            <i class="fas fa-plus-circle"></i> إنشاء فاتورة مشتريات جديدة (اختر مورد)
        </a>
    </div>

    <?php echo $message; ?>

    <div class="card mb-4 shadow-sm">
        <div class="card-body">
            <form action="<?php echo $current_page_url_for_forms; ?>" method="post" class="row gx-3 gy-2 align-items-end">
                <div class="col-md-4">
                    <label for="supplier_filter" class="form-label">تصفية حسب المورد:</label>
                    <select name="supplier_filter" id="supplier_filter" class="form-select">
                        <option value="">-- كل الموردين --</option>
                        <?php foreach ($suppliers_list as $supplier_item): ?>
                            <option value="<?php echo $supplier_item['id']; ?>" <?php echo ($selected_supplier_id == $supplier_item['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($supplier_item['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label for="status_filter" class="form-label">تصفية حسب الحالة:</label>
                    <select name="status_filter" id="status_filter" class="form-select">
                        <option value="">-- كل الحالات --</option>
                        <?php foreach($status_labels as $key => $label): ?>
                            <option value="<?php echo $key; ?>" <?php echo ($selected_status == $key) ? 'selected' : ''; ?>><?php echo $label; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" name="filter_purchases" class="btn btn-primary w-100">
                        <i class="fas fa-filter"></i> تصفية
                    </button>
                </div>
                <?php if(!empty($selected_supplier_id) || !empty($selected_status)): ?>
                <div class="col-md-2">
                     <a href="<?php echo $current_page_url_for_forms; ?>" class="btn btn-outline-secondary w-100">
                        <i class="fas fa-times"></i> مسح الفلتر
                     </a>
                </div>
                <?php endif; ?>
                 <input type="hidden" name="supplier_filter_val" value="<?php echo htmlspecialchars($selected_supplier_id); ?>">
                <input type="hidden" name="status_filter_val" value="<?php echo htmlspecialchars($selected_status); ?>">
            </form>
        </div>
    </div>

    <div class="card shadow">
        <div class="card-header">
            قائمة فواتير المشتريات
            <?php
                $filter_text = [];
                if(!empty($selected_supplier_id) && !empty($suppliers_list)) {
                    foreach($suppliers_list as $s_item) { if($s_item['id'] == $selected_supplier_id) {$filter_text[] = "المورد: " . htmlspecialchars($s_item['name']); break;}}
                }
                if(!empty($selected_status)) {$filter_text[] = "الحالة: " . htmlspecialchars($selected_status);}
                if(!empty($filter_text)) { echo " (" . implode("، ", $filter_text) . ")";}
            ?>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped table-hover table-bordered align-middle">
                    <thead class="table-dark">
                        <tr>
                            <th>#</th>
                            <th>اسم المورد</th>
                            <th class="d-none d-md-table-cell">رقم فاتورة المورد</th>
                            <th>تاريخ الشراء</th>
                            <th class="d-none d-md-table-cell">الحالة</th>
                            <th class="text-end">الإجمالي</th>
                            <th class="d-none d-md-table-cell">أنشئت بواسطة</th>
                            <th class="d-none d-md-table-cell">تاريخ الإنشاء</th>
                            <th class="text-center" style="min-width: 210px;">إجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $current_invoice_total_for_row = 0;
                        if ($result_invoices && $result_invoices->num_rows > 0):
                            while($invoice = $result_invoices->fetch_assoc()):
                                $current_invoice_total_for_row = floatval($invoice["total_amount"] ?? 0);
                        ?>
                                <tr>
                                    <td><?php echo $invoice["id"]; ?></td>
                                    <td><?php echo htmlspecialchars($invoice["supplier_name"]); ?></td>
                                    <td class="d-none d-md-table-cell"><?php echo htmlspecialchars($invoice["supplier_invoice_number"] ?: '-'); ?></td>
                                    <td><?php echo date('Y-m-d', strtotime($invoice["purchase_date"])); ?></td>
                                    <td class="d-none d-md-table-cell"><span class="badge bg-<?php
                                        switch($invoice['status']){
                                            case 'pending': echo 'warning text-dark'; break;
                                            case 'partial_received': echo 'info'; break;
                                            case 'fully_received': echo 'success'; break;
                                            case 'cancelled': echo 'danger'; break;
                                            default: echo 'secondary';
                                        }
                                    ?>"><?php echo $status_labels[$invoice['status']] ?? $invoice['status']; ?></span></td>
                                    <td class="text-end fw-bold"><?php echo number_format($current_invoice_total_for_row, 2); ?> ج.م</td>
                                    <td class="d-none d-md-table-cell"><?php echo htmlspecialchars($invoice["creator_name"] ?? 'غير محدد'); ?></td>
                                    <td class="d-none d-md-table-cell"><?php echo date('Y-m-d H:i A', strtotime($invoice["created_at"])); ?></td>
                                    <td class="text-center">
                                        <a href="<?php echo $view_purchase_invoice_link; ?>?id=<?php echo $invoice["id"]; ?>" class="btn btn-info btn-sm" title="عرض وإدارة البنود">
                                            <i class="fas fa-eye"></i> <span class="d-none d-md-inline">البنود</span>
                                        </a>
                                        <a href="<?php echo $edit_purchase_invoice_link; ?>?id=<?php echo $invoice["id"]; ?>" class="btn btn-warning btn-sm ms-1" title="تعديل بيانات الفاتورة الأساسية">
                                            <i class="fas fa-edit"></i> <span class="d-none d-md-inline">تعديل</span>
                                        </a>
                                    </td>
                                </tr>
                        <?php
                            endwhile;
                        else:
                        ?>
                            <tr>
                                <td colspan="9" class="text-center"> لا توجد فواتير مشتريات تطابق معايير البحث الحالية.</td>
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
                    <h5 class="card-title text-center mb-3">ملخص إجماليات فواتير المشتريات</h5>
                    <ul class="list-group list-group-flush">
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <strong>إجمالي الفواتير المعروضة حالياً:</strong>
                            <span class="badge bg-primary rounded-pill fs-6">
                                <?php echo number_format($displayed_invoices_sum, 2); ?> ج.م
                            </span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <strong>الإجمالي الكلي لفواتير الشراء (غير الملغاة):</strong>
                            <span class="badge bg-success rounded-pill fs-6">
                                <?php echo number_format($grand_total_all_purchases, 2); ?> ج.م
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
