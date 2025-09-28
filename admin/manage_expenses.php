<?php
$page_title = "إدارة المصروفات";
// $class_expenses_active = "active"; // لـ navbar
require_once dirname(__DIR__) . '/config.php';
require_once BASE_DIR . 'partials/session_admin.php'; // صلاحيات المدير فقط

$message = "";
$expenses_list = [];
$categories_list = [];
$total_expenses_displayed = 0;

// روابط مفيدة
$add_expense_link = BASE_URL . "admin/add_expense.php";
$edit_expense_link_base = BASE_URL . "admin/edit_expense.php";
$back_link = BASE_URL . "user/welcome.php"; // زر العودة

// القيم الافتراضية للفلاتر
$start_date_filter = isset($_REQUEST['filter_start_date']) ? trim($_REQUEST['filter_start_date']) : '';
$end_date_filter = isset($_REQUEST['filter_end_date']) ? trim($_REQUEST['filter_end_date']) : '';
$selected_category_id = isset($_REQUEST['filter_category_id']) ? intval($_REQUEST['filter_category_id']) : '';


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

// --- جلب فئات المصاريف للفلتر ---
$sql_categories = "SELECT id, name FROM expense_categories ORDER BY name ASC";
$result_cats = $conn->query($sql_categories);
if ($result_cats) {
    while ($cat_row = $result_cats->fetch_assoc()) {
        $categories_list[] = $cat_row;
    }
}

// --- معالجة الحذف ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_expense'])) {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['message'] = "<div class='alert alert-danger'>خطأ: طلب غير صالح (CSRF).</div>";
    } else {
        $expense_id_to_delete = intval($_POST['expense_id_to_delete']);
        $sql_delete = "DELETE FROM expenses WHERE id = ?";
        if ($stmt_delete = $conn->prepare($sql_delete)) {
            $stmt_delete->bind_param("i", $expense_id_to_delete);
            if ($stmt_delete->execute()) {
                $_SESSION['message'] = ($stmt_delete->affected_rows > 0) ? "<div class='alert alert-success'>تم حذف المصروف بنجاح.</div>" : "<div class='alert alert-warning'>لم يتم العثور على المصروف أو لم يتم حذفه.</div>";
            } else {
                $_SESSION['message'] = "<div class='alert alert-danger'>حدث خطأ أثناء حذف المصروف: " . htmlspecialchars($stmt_delete->error) . "</div>";
            }
            $stmt_delete->close();
        } else {
            $_SESSION['message'] = "<div class='alert alert-danger'>خطأ في تحضير استعلام الحذف: " . htmlspecialchars($conn->error) . "</div>";
        }
    }
    // PRG - إعادة تحميل الصفحة مع الحفاظ على الفلاتر
    $query_params_for_redirect = [];
    if (!empty($start_date_filter)) $query_params_for_redirect['filter_start_date'] = $start_date_filter;
    if (!empty($end_date_filter)) $query_params_for_redirect['filter_end_date'] = $end_date_filter;
    if (!empty($selected_category_id)) $query_params_for_redirect['filter_category_id'] = $selected_category_id;

    header("Location: manage_expenses.php" . (!empty($query_params_for_redirect) ? "?" . http_build_query($query_params_for_redirect) : ""));
    exit;
}

// --- بناء وعرض المصروفات ---
$sql_select_expenses = "SELECT e.id, e.expense_date, e.description, e.amount, e.notes,
                               ec.name as category_name,
                               u.username as creator_name,
                               e.created_at
                        FROM expenses e
                        LEFT JOIN expense_categories ec ON e.category_id = ec.id
                        LEFT JOIN users u ON e.created_by = u.id";

$conditions = [];
$params = [];
$types = "";

if (!empty($start_date_filter)) {
    $conditions[] = "e.expense_date >= ?";
    $params[] = $start_date_filter . " 00:00:00";
    $types .= "s";
}
if (!empty($end_date_filter)) {
    $conditions[] = "e.expense_date <= ?";
    $params[] = $end_date_filter . " 23:59:59";
    $types .= "s";
}
if (!empty($selected_category_id)) {
    $conditions[] = "e.category_id = ?";
    $params[] = $selected_category_id;
    $types .= "i";
}

if (!empty($conditions)) {
    $sql_select_expenses .= " WHERE " . implode(" AND ", $conditions);
}

$sql_select_expenses .= " ORDER BY e.expense_date DESC, e.id DESC";

if ($stmt_select = $conn->prepare($sql_select_expenses)) {
    if (!empty($params)) {
        $stmt_select->bind_param($types, ...$params);
    }
    if ($stmt_select->execute()) {
        $result_expenses = $stmt_select->get_result();
        if ($result_expenses) {
            while ($row = $result_expenses->fetch_assoc()) {
                $expenses_list[] = $row;
                $total_expenses_displayed += floatval($row['amount']);
            }
        }
    } else {
        $message .= "<div class='alert alert-danger'>حدث خطأ أثناء جلب المصروفات: " . htmlspecialchars($stmt_select->error) . "</div>";
    }
    $stmt_select->close();
} else {
    $message .= "<div class='alert alert-danger'>خطأ في تحضير استعلام جلب المصروفات: " . htmlspecialchars($conn->error) . "</div>";
}

$current_page_url_for_forms = htmlspecialchars($_SERVER["PHP_SELF"]);

require_once BASE_DIR . 'partials/header.php';
require_once BASE_DIR . 'partials/sidebar.php';
?>

<!-- تخصيص مظهر الصفحة المحلي -->
<style>
    /* اجعل الجدول يملأ العرض المتاح ويكون أكثر راحة للقراءة */
    .page-actions .btn { min-width: 140px; }
    .expenses-card .card-header { background: linear-gradient(90deg, #0d6efd22, #0dcaf022); }
    .expenses-card .table thead th { vertical-align: middle; }
    .wide-table-wrapper { overflow-x: auto; }
    .table-lg td, .table-lg th { padding: 0.85rem; }
    @media (min-width: 992px) {
        .content-max { max-width: 1200px; margin: 0 auto; }
    }
</style>

<div class="container-fluid mt-4 pt-3">
    <div class="content-max">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <div>
                <h1 class="h3 mb-0"><i class="fas fa-receipt"></i> إدارة المصروفات</h1>
                <small class="custom-text">عرض وتصفية المصروفات المسجلة مع واجهة أبسط ومقروءة.</small>
            </div>
            <div class="page-actions d-flex gap-2">
                <a href="<?php echo $back_link; ?>" class="btn btn-outline-secondary"><i class="fas fa-arrow-left"></i> عودة</a>
                <a href="<?php echo $add_expense_link; ?>" class="btn btn-success"><i class="fas fa-plus-circle"></i> إضافة مصروف جديد</a>
            </div>
        </div>

        <?php echo $message; ?>

        <div class="card mb-3 shadow-sm expenses-card">
            <form action="<?php echo $current_page_url_for_forms; ?>" method="get" class="row gx-3 gy-2 align-items-end">
            <div class="card-header py-1 d-flex justify-content-around align-items-center">
               <!-- <div class="p-2">

                   <i class="fas fa-filter"></i> تصفية المصروفات
               </div>  -->

                
            <!-- </div> -->

            <!-- <div class="card-body row"> -->
                    <div class="col-md-3">
                        <label for="filter_start_date" class="form-label">من تاريخ:</label>
                        <input type="date" class="form-control" id="filter_start_date" name="filter_start_date" value="<?php echo htmlspecialchars($start_date_filter); ?>">
                    </div>
                    <div class="col-md-3">
                        <label for="filter_end_date" class="form-label">إلى تاريخ:</label>
                        <input type="date" class="form-control" id="filter_end_date" name="filter_end_date" value="<?php echo htmlspecialchars($end_date_filter); ?>">
                    </div>
                    <div class="col-md-3">
                        <label for="filter_category_id" class="form-label">حسب الفئة:</label>
                        <select name="filter_category_id" id="filter_category_id" class="form-select">
                            <option value="">-- كل الفئات --</option>
                            <?php foreach ($categories_list as $category): ?>
                                <option value="<?php echo $category['id']; ?>" <?php echo ($selected_category_id == $category['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($category['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="text mt-3">
                        <button type="submit" name="filter_expenses_btn" class="btn btn-primary"><i class="fas fa-search"></i> عرض</button>
                        <a href="<?php echo $current_page_url_for_forms; ?>" class="btn btn-outline-secondary ms-2"><i class="fas fa-times"></i> مسح الفلتر</a>
                    </div>
                </form>
            </div>
        </div>

        <div class="card shadow-lg expenses-card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <strong>قائمة المصروفات</strong>
                    <?php
                        $filter_text_display = [];
                        if(!empty($start_date_filter)) { $filter_text_display[] = "من: " . htmlspecialchars($start_date_filter); }
                        if(!empty($end_date_filter)) { $filter_text_display[] = "إلى: " . htmlspecialchars($end_date_filter); }
                        if(!empty($selected_category_id) && !empty($categories_list)) {
                            foreach($categories_list as $c_item) { if($c_item['id'] == $selected_category_id) {$filter_text_display[] = "فئة: " . htmlspecialchars($c_item['name']); break;}}
                        }
                        if(!empty($filter_text_display)) { echo " <small class='custom-text'>(" . implode("، ", $filter_text_display) . ")</small>";}
                    ?>
                </div>
                <span class="badge bg-danger rounded-pill fs-6">الإجمالي: <?php echo number_format($total_expenses_displayed, 2); ?> ج.م</span>
            </div>
            <!-- <div class="card-body"> -->
                <div class="wide-table-wrapper custom-table-wrapper">
                    <table class="tabe custom-table customized">
                        <thead class="table-dark">
                            <tr>
                                <th style="width:60px">#</th>
                                <th style="width:120px">التاريخ</th>
                                <th>البيان/الوصف</th>
                                <th style="width:130px" class="text-end">القيمة</th>
                                <th style="width:140px">الفئة</th>
                                <th>ملاحظات</th>
                                <th style="width:140px">أضيف بواسطة</th>
                                <th style="width:160px">تاريخ التسجيل</th>
                                <th style="width:120px" class="text-center">إجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($expenses_list)): ?>
                                <?php $counter = 1; ?>
                                <?php foreach($expenses_list as $expense): ?>
                                    <tr>
                                        <td><?php echo $counter++; ?></td>
                                        <td><?php echo date('Y-m-d', strtotime($expense["expense_date"])); ?></td>
                                        <td><?php echo htmlspecialchars($expense["description"]); ?></td>
                                        <td class="text-end fw-bold"><?php echo number_format(floatval($expense["amount"]), 2); ?> ج.م</td>
                                        <td><?php echo htmlspecialchars($expense["category_name"] ?? '-'); ?></td>
                                        <td><?php echo !empty($expense["notes"]) ? nl2br(htmlspecialchars($expense["notes"])) : '-'; ?></td>
                                        <td><?php echo htmlspecialchars($expense["creator_name"] ?? 'غير محدد'); ?></td>
                                        <td><?php echo date('Y-m-d H:i', strtotime($expense["created_at"])); ?></td>
                                        <td class="text-center">
                                            <form action="<?php echo $edit_expense_link_base; ?>" method="post" class="d-inline">
                                                <input type="hidden" name="expense_id_to_edit" value="<?php echo $expense["id"]; ?>">
                                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                                <button type="submit" class="btn btn-warning btn-sm" title="تعديل المصروف">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                            </form>
                                            <form action="<?php echo $current_page_url_for_forms; ?>" method="post" class="d-inline ms-1">
                                                <input type="hidden" name="expense_id_to_delete" value="<?php echo $expense["id"]; ?>">
                                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                                <input type="hidden" name="filter_start_date" value="<?php echo htmlspecialchars($start_date_filter); ?>">
                                                <input type="hidden" name="filter_end_date" value="<?php echo htmlspecialchars($end_date_filter); ?>">
                                                <input type="hidden" name="filter_category_id" value="<?php echo htmlspecialchars($selected_category_id); ?>">
                                                <button type="submit" name="delete_expense" class="btn btn-danger btn-sm"
                                                        onclick="return confirm('هل أنت متأكد من حذف هذا المصروف؟');"
                                                        title="حذف المصروف">
                                                    <i class="fas fa-trash-alt"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="9" class="text-center"> لا توجد مصروفات مسجلة تطابق معايير البحث الحالية.
                                        <?php if(empty($start_date_filter) && empty($end_date_filter) && empty($selected_category_id)): ?>
                                            <a href="<?php echo $add_expense_link; ?>">أضف مصروفاً الآن!</a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
        </div>
    </div>
</div>

<?php
$conn->close();
require_once BASE_DIR . 'partials/footer.php';
?>
