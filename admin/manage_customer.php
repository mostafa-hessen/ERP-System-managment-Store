<?php
$page_title = "إدارة العملاء"; // تحديد عنوان الصفحة
$class_dashboard = "active";
require_once dirname(__DIR__) . '/config.php';
require_once BASE_DIR . 'partials/session_admin.php';
require_once BASE_DIR . 'partials/header.php';
require_once BASE_DIR . 'partials/sidebar.php';

$message = ""; // تهيئة المتغير

// --- !! إضافة جديدة: التحقق من وجود رسالة في الجلسة !! ---
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message']; // جلب الرسالة
    unset($_SESSION['message']);    // حذف الرسالة من الجلسة حتى لا تظهر مرة أخرى
}

// تأمين: دالة مساعدة لطباعة نص آمن
function e($s) {
    return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// جلب توكن CSRF الحالي (نحتاجه قبل معالجة POST وبعده)
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];


// --- معالجة الحذف (باستخدام POST) --- (لا تغيّر هذا الجزء - يحتفظ بالأمان)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_customer'])) {

    // التحقق من توكن CSRF
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $message = "<div class='alert alert-danger'>خطأ: طلب غير صالح (CSRF detected).</div>";
    } else {
        $customer_id_to_delete = intval($_POST['customer_id_to_delete']);

        // تحضير استعلام الحذف
        $sql_delete = "DELETE FROM customers WHERE id = ?";
        if ($stmt_delete = $conn->prepare($sql_delete)) {
            $stmt_delete->bind_param("i", $customer_id_to_delete);

            if ($stmt_delete->execute()) {
                if ($stmt_delete->affected_rows > 0) {
                    $message = "<div class='alert alert-success'>تم حذف العميل بنجاح.</div>";
                } else {
                    $message = "<div class='alert alert-warning'>لم يتم العثور على العميل أو لم يتم حذفه.</div>";
                }
            } else {
                $message = "<div class='alert alert-danger'>حدث خطأ أثناء حذف العميل: " . e($stmt_delete->error) . "</div>";
            }
            $stmt_delete->close();
        } else {
            $message = "<div class='alert alert-danger'>خطأ في تحضير استعلام الحذف: " . e($conn->error) . "</div>";
        }
    }
}

/* =========================
   SEARCH: معالجة وعرض النتائج
   - إضافة إمكانية البحث عبر GET param 'q'
   - البحث في name, mobile, city, address باستخدام LIKE
   ========================= */

// قراءة كلمة البحث من GET (إذا موجودة)
$q = '';
if (isset($_GET['q'])) {
    $q = trim((string) $_GET['q']);
    // حماية: حد الطول لمنع إدخالات ضخمة
    if (mb_strlen($q) > 255) {
        $q = mb_substr($q, 0, 255);
    }
}

// بناء الاستعلام اعتمادًا على وجود كلمة البحث
if ($q !== '') {
    // --- تم التعديل: استخدام prepared statement مع LIKE للبحث الآمن ---
    $sql_select = "SELECT c.id, c.name, c.mobile, c.city, c.address, c.created_at, u.username as creator_name
                   FROM customers c
                   LEFT JOIN users u ON c.created_by = u.id
                   WHERE (c.name LIKE ? OR c.mobile LIKE ? OR c.city LIKE ? OR c.address LIKE ?)
                   ORDER BY c.id DESC";
    $like = '%' . $q . '%';
    $customers_stmt = $conn->prepare($sql_select);
    if ($customers_stmt) {
        $customers_stmt->bind_param('ssss', $like, $like, $like, $like);
        $customers_stmt->execute();
        $result = $customers_stmt->get_result();
        // $customers_stmt->close(); // لا تغلق الآن إذا كنت تريد استخدامه لاحقًا (لكن هنا نغلق لعدم تسريب موارد)
        // سنغلق بعد عرض النتائج (أو يمكنك غلقه فورًا)
    } else {
        // في حالة فشل التحضير: نعرض رسالة ونعود لاستعلام افتراضي آمن
        $message .= "<div class='alert alert-danger'>خطأ في تحضير استعلام البحث: " . e($conn->error) . "</div>";
        $sql_select = "SELECT c.id, c.name, c.mobile, c.city, c.address, c.created_at, u.username as creator_name
                   FROM customers c
                   LEFT JOIN users u ON c.created_by = u.id
                   ORDER BY c.id DESC";
        $result = $conn->query($sql_select);
    }
} else {
    // بدون بحث — استعلام افتراضي كما كان
    $sql_select = "SELECT c.id, c.name, c.mobile, c.city, c.address, c.created_at, u.username as creator_name
                   FROM customers c
                   LEFT JOIN users u ON c.created_by = u.id
                   ORDER BY c.id DESC";
    $result = $conn->query($sql_select);
}

?>

<div class="container mt-5 pt-3">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1><i class="fas fa-address-book"></i> إدارة العملاء</h1>
        <a href="<?php echo BASE_URL; ?>customer/insert.php" class="btn btn-success"><i class="fas fa-plus-circle"></i> إضافة عميل جديد</a>
    </div>

    <?php echo $message; // عرض رسائل النجاح أو الخطأ ?>

    <!-- =========================
         SEARCH FORM: أضفت نموذج بحث هنا (GET)
         - يمكن البحث بالاسم/الموبايل/المدينة/العنوان
         - التعليق: هذا الجزء هو التعديل/الإضافة المطلوبة لتمكين البحث
         ========================= -->
    <div class="card mb-3">
        <div class="card-body">
            <form class="row g-2 align-items-center" method="get" action="<?php echo e($_SERVER['PHP_SELF']); ?>">
                <div class="col-auto" style="flex:1;">
                    <label for="q" class="form-label visually-hidden">بحث</label>
                    <input id="q" name="q" type="search" class="form-control" placeholder="ابحث بالاسم أو الموبايل أو المدينة أو العنوان" value="<?php echo e($q); ?>">
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> بحث</button>
                    <a href="<?php echo e($_SERVER['PHP_SELF']); ?>" class="btn btn-outline-secondary ms-1" title="إعادة تعيين">إظهار الكل</a>
                </div>
            </form>
        </div>
    </div>
    <!-- ========================= END SEARCH FORM ========================= -->

    <div class="card">
        <div class="card-header">
            قائمة العملاء المسجلين
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped table-hover table-bordered align-middle">
                    <thead class="table-dark">
                        <tr>
                            <th>#</th>
                            <th>اسم العميل</th>
                            <th>الموبايل</th>
                            <th>المدينة</th>
                            <th class="d-none d-md-table-cell">العنوان</th>
                            <th class="d-none d-md-table-cell">أضيف بواسطة</th>
                            <th class="d-none d-md-table-cell">تاريخ الإضافة</th>
                            <th class="text-center">إجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result && $result->num_rows > 0): ?>
                            <?php while($row = $result->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo e($row["id"]); ?></td>
                                    <td><?php echo e($row["name"]); ?></td>
                                    <td><?php echo e($row["mobile"]); ?></td>
                                    <td><?php echo e($row["city"]); ?></td>
                                    <td class="d-none d-md-table-cell"><?php echo !empty($row["address"]) ? e($row["address"]) : '-'; ?></td>
                                    <td class="d-none d-md-table-cell">
                                        <?php echo !empty($row["creator_name"]) ? e($row["creator_name"]) : '<span class="text-muted">محذوف/نظام</span>'; ?>
                                    </td>
                                    <td class="d-none d-md-table-cell"><?php echo !empty($row["created_at"]) ? date('Y-m-d', strtotime($row["created_at"])) : '-'; ?></td>
                                    <td class="text-center">
                                        <form action="<?php echo BASE_URL; ?>admin/edit_customer.php" method="post" class="d-inline">
                                            <input type="hidden" name="customer_id_to_edit" value="<?php echo e($row["id"]); ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                            <button type="submit" class="btn btn-warning btn-sm" title="تعديل">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                        </form>

                                        <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post" class="d-inline ms-2">
                                            <input type="hidden" name="customer_id_to_delete" value="<?php echo e($row["id"]); ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                            <button type="submit" name="delete_customer" class="btn btn-danger btn-sm"
                                                    onclick="return confirm('هل أنت متأكد من حذف هذا العميل؟');"
                                                    title="حذف">
                                                <i class="fas fa-trash-alt"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                            <?php
                                // إذا كان stmt مستخدمًا (في حالة البحث)، نغلقه الآن لتحرير الموارد
                                if (isset($customers_stmt) && $customers_stmt instanceof mysqli_stmt) {
                                    $customers_stmt->close();
                                }
                            ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8" class="text-center">لا يوجد عملاء لعرضهم. <a href="insert_customer.php">أضف واحداً الآن!</a></td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php $conn->close();?>

<?php require_once BASE_DIR . 'partials/footer.php'; ?>
