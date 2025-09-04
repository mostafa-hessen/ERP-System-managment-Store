<?php
$page_title = "إدارة الموردين";
$class_dashboard = "active";
require_once dirname(__DIR__) . '/config.php';
require_once BASE_DIR . 'partials/session_admin.php'; // صلاحيات المدير فقط
require_once BASE_DIR . 'partials/sidebar.php';

$message = "";
$search_term = "";
$result_suppliers = null;

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
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_supplier'])) {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['message'] = "<div class='alert alert-danger'>خطأ: طلب غير صالح (CSRF).</div>";
    } else {
        $supplier_id_to_delete = intval($_POST['supplier_id_to_delete']);
        // لا تنسَ التحقق من الارتباطات المستقبلية قبل الحذف الفعلي
        $sql_delete = "DELETE FROM suppliers WHERE id = ?";
        if ($stmt_delete = $conn->prepare($sql_delete)) {
            $stmt_delete->bind_param("i", $supplier_id_to_delete);
            if ($stmt_delete->execute()) {
                $_SESSION['message'] = ($stmt_delete->affected_rows > 0) ? "<div class='alert alert-success'>تم حذف المورد بنجاح.</div>" : "<div class='alert alert-warning'>لم يتم العثور على المورد أو لم يتم حذفه.</div>";
            } else {
                $_SESSION['message'] = "<div class='alert alert-danger'>حدث خطأ أثناء حذف المورد: " . $stmt_delete->error . ". قد يكون مرتبطاً بسجلات أخرى.</div>";
            }
            $stmt_delete->close();
        } else {
            $_SESSION['message'] = "<div class='alert alert-danger'>خطأ في تحضير استعلام الحذف: " . $conn->error . "</div>";
        }
    }
    header("Location: manage_suppliers.php" . (!empty($search_term) ? "?search_term_val=" . urlencode($search_term) : "") ); // PRG مع الحفاظ على البحث
    exit;
}

// --- معالجة البحث ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['search_button'])) {
    $search_term = trim($_POST['search_term']);
} elseif (isset($_GET['search_term_val'])) { // للحفاظ على البحث بعد إعادة التوجيه
    $search_term = trim($_GET['search_term_val']);
}

// --- جلب الموردين ---
$sql_select_suppliers = "SELECT s.id, s.name, s.mobile, s.city, s.address, s.commercial_register, s.created_at, u.username as creator_name
                         FROM suppliers s
                         LEFT JOIN users u ON s.created_by = u.id";
$params = [];
$types = "";

if (!empty($search_term)) {
    $sql_select_suppliers .= " WHERE (s.name LIKE ? OR s.mobile LIKE ?) ";
    $search_like = "%" . $search_term . "%";
    $params[] = $search_like;
    $params[] = $search_like;
    $types .= "ss";
}
$sql_select_suppliers .= " ORDER BY s.id DESC";

if ($stmt_select = $conn->prepare($sql_select_suppliers)) {
    if (!empty($params)) {
        $stmt_select->bind_param($types, ...$params);
    }
    if ($stmt_select->execute()) {
        $result_suppliers = $stmt_select->get_result();
    } else {
        $message .= "<div class='alert alert-danger'>حدث خطأ أثناء جلب بيانات الموردين: " . $stmt_select->error . "</div>";
    }
    $stmt_select->close();
} else {
    $message .= "<div class='alert alert-danger'>خطأ في تحضير استعلام جلب الموردين: " . $conn->error . "</div>";
}

require_once BASE_DIR . 'partials/header.php';
require_once BASE_DIR . 'partials/navbar.php';
$current_page_url_for_forms = htmlspecialchars($_SERVER["PHP_SELF"]) . (!empty($search_term) ? "?search_term_val=" . urlencode($search_term) : "");
$create_purchase_invoice_link = BASE_URL . "admin/create_purchase_invoice.php"; // افترض أن الصفحة ستكون هنا
?>

<div class="container mt-5 pt-3">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1><i class="fas fa-people-carry"></i> إدارة الموردين</h1>
        <a href="<?php echo BASE_URL; ?>admin/add_supplier.php" class="btn btn-success"><i class="fas fa-plus-circle"></i> إضافة مورد جديد</a>
    </div>

    <?php echo $message; ?>

    <div class="card mb-4 shadow-sm">
        <div class="card-body">
            <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post" class="row gx-3 gy-2 align-items-center">
                <div class="col-sm-8">
                    <label class="visually-hidden" for="search_term">بحث</label>
                    <input type="text" class="form-control" id="search_term" name="search_term"
                           placeholder="ابحث بالاسم أو رقم الموبايل..."
                           value="<?php echo htmlspecialchars($search_term); ?>">
                </div>
                <div class="col-sm-2">
                    <button type="submit" name="search_button" class="btn btn-primary w-100">
                        <i class="fas fa-search"></i> بحث
                    </button>
                </div>
                <?php if(!empty($search_term)): ?>
                <div class="col-sm-2">
                     <a href="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" class="btn btn-outline-secondary w-100">
                        <i class="fas fa-times"></i> مسح
                     </a>
                </div>
                <?php endif; ?>
            </form>
        </div>
    </div>


    <div class="card shadow">
        <div class="card-header">
            قائمة الموردين المسجلين
            <?php if(!empty($search_term)) { echo " (نتائج البحث عن: \"" . htmlspecialchars($search_term) . "\")"; } ?>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped table-hover table-bordered align-middle">
                    <thead class="table-dark">
                        <tr>
                            <th>#</th>
                            <th>اسم المورد</th>
                            <th>الموبايل</th>
                            <th>المدينة</th>
                            <th class="d-none d-md-table-cell">السجل التجاري</th>
                            <th class="d-none d-md-table-cell">أضيف بواسطة</th>
                            <th class="d-none d-md-table-cell">تاريخ الإضافة</th>
                            <th class="text-center" style="min-width: 200px;">إجراءات</th> </tr>
                    </thead>
                    <tbody>
                        <?php if ($result_suppliers && $result_suppliers->num_rows > 0): ?>
                            <?php while($supplier = $result_suppliers->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo $supplier["id"]; ?></td>
                                    <td><?php echo htmlspecialchars($supplier["name"]); ?></td>
                                    <td><?php echo htmlspecialchars($supplier["mobile"]); ?></td>
                                    <td><?php echo htmlspecialchars($supplier["city"]); ?></td>
                                    <td class="d-none d-md-table-cell"><?php echo !empty($supplier["commercial_register"]) ? htmlspecialchars($supplier["commercial_register"]) : '-'; ?></td>
                                    <td class="d-none d-md-table-cell"><?php echo htmlspecialchars($supplier["creator_name"] ?? 'غير محدد'); ?></td>
                                    <td class="d-none d-md-table-cell"><?php echo date('Y-m-d', strtotime($supplier["created_at"])); ?></td>
                                    <td class="text-center">
                                        <form action="<?php echo BASE_URL; ?>admin/edit_supplier.php" method="post" class="d-inline">
                                            <input type="hidden" name="supplier_id_to_edit" value="<?php echo $supplier["id"]; ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                            <button type="submit" class="btn btn-warning btn-sm" title="تعديل المورد">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                        </form>

                                        <form action="<?php echo $current_page_url_for_forms; // يرسل لنفس الصفحة مع الحفاظ على البحث ?>" method="post" class="d-inline ms-1">
                                            <input type="hidden" name="supplier_id_to_delete" value="<?php echo $supplier["id"]; ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                            <button type="submit" name="delete_supplier" class="btn btn-danger btn-sm"
                                                    onclick="return confirm('هل أنت متأكد من حذف هذا المورد؟ لا يمكن التراجع عن هذا الإجراء.');"
                                                    title="حذف المورد">
                                                <i class="fas fa-trash-alt"></i>
                                            </button>
                                        </form>

                                        <form action="<?php echo $create_purchase_invoice_link; ?>" method="post" class="d-inline ms-1">
                                            <input type="hidden" name="supplier_id" value="<?php echo $supplier["id"]; ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; // ممارسة جيدة ?>">
                                            <button type="submit" class="btn btn-success btn-sm" title="بدء عمل فاتورة وارد لهذا المورد">
                                                <i class="fas fa-file-import"></i> فاتورة وارد
                                            </button>
                                        </form>
                                        </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8" class="text-center"> <?php echo !empty($search_term) ? 'لا توجد نتائج تطابق بحثك.' : 'لا يوجد موردون مسجلون حالياً.'; ?>
                                    <a href="<?php echo BASE_URL; ?>admin/add_supplier.php">أضف مورداً الآن!</a>
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