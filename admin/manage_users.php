<?php
$page_title = "إدارة المستخدمين"; // تحديد عنوان الصفحة
$class_dashboard = "active";
require_once dirname(__DIR__) . '/config.php';
require_once BASE_DIR . 'partials/session_admin.php';
require_once BASE_DIR . 'partials/header.php';
require_once BASE_DIR . 'partials/sidebar.php';

$message = ""; // تهيئة المتغير

// --- !! إضافة جديدة: التحقق من وجود رسالة في الجلسة !! ---
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message']; // جلب الرسالة
    unset($_SESSION['message']);    // حذف الرسالة من الجلسة
}

// --- 5. معالجة الحذف (باستخدام POST) ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_user'])) {

    // --- !! قسم تصحيح الأخطاء (يمكنك إزالته لاحقاً) !! ---
    // echo "<pre>POST Data: "; print_r($_POST); echo "</pre>";
    // echo "<pre>Session CSRF: "; print_r($_SESSION['csrf_token']); echo "</pre>";
    // --- !! نهاية قسم تصحيح الأخطاء !! ---

    // التحقق من توكن CSRF
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) { // استخدم hash_equals لأمان أفضل
        $message = "<div class='alert alert-danger'>خطأ: طلب غير صالح (CSRF detected).</div>";
    } else {
        $user_id_to_delete = intval($_POST['user_id_to_delete']);

        // لا تسمح للمدير بحذف نفسه
        if ($user_id_to_delete == $_SESSION['id']) {
            $message = "<div class='alert alert-warning'>لا يمكنك حذف حسابك الخاص.</div>";
        } else {
            // تحضير استعلام الحذف
            $sql_delete = "DELETE FROM users WHERE id = ?";
            if ($stmt_delete = $conn->prepare($sql_delete)) {
                $stmt_delete->bind_param("i", $user_id_to_delete);

                if ($stmt_delete->execute()) {
                    // التحقق مما إذا تم حذف أي صف (اختياري ولكن جيد)
                    if ($stmt_delete->affected_rows > 0) {
                        $message = "<div class='alert alert-success'>تم حذف المستخدم بنجاح.</div>";
                    } else {
                        $message = "<div class='alert alert-warning'>لم يتم العثور على المستخدم أو لم يتم حذفه.</div>";
                    }
                } else {
                    // --- !! عرض خطأ قاعدة البيانات !! ---
                    $message = "<div class='alert alert-danger'>حدث خطأ أثناء حذف المستخدم: " . $stmt_delete->error . "</div>";
                }
                $stmt_delete->close();
            } else {
                // --- !! عرض خطأ قاعدة البيانات !! ---
                $message = "<div class='alert alert-danger'>خطأ في تحضير استعلام الحذف: " . $conn->error . "</div>";
            }
        }
    }
}

// --- 2. عرض المستخدمين ---
$sql_select = "SELECT id, username, email, role, created_at FROM users ORDER BY id ASC";
$result = $conn->query($sql_select);

// جلب أو إنشاء توكن CSRF الحالي من الجلسة (تأكد من أنه يُنشأ في config.php أو header.php)
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];


?>

<div class="container mt-5 pt-3">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1><i class="fas fa-users-cog"></i> إدارة المستخدمين</h1>
        <a href="register.php" class="btn btn-success"><i class="fas fa-user-plus"></i> إضافة مستخدم جديد</a>
    </div>

    <?php echo $message; // عرض رسائل النجاح أو الخطأ ?>

    <div class="card">
        <div class="card-header">
            قائمة المستخدمين المسجلين
        </div>
        <!-- <div class="card-body"> -->
            <div class="table-responsive custom-table-wrapper">
                <table class="tale custom-table customized">
                    <thead class="table-dark">
                        <tr>
                            <th>#</th>
                            <th>اسم المستخدم</th>
                            <th>البريد الإلكتروني</th>
                            <th>الدور</th>
                            <th>تاريخ التسجيل</th>
                            <th class="text-center">إجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result && $result->num_rows > 0): ?>
                            <?php while($row = $result->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo $row["id"]; ?></td>
                                    <td><?php echo htmlspecialchars($row["username"]); ?></td>
                                    <td><?php echo htmlspecialchars($row["email"]); ?></td>
                                    <td>
                                        <?php if($row["role"] == 'admin'): ?>
                                            <span class="badge bg-primary">مدير</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">مستخدم</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo date('Y-m-d', strtotime($row["created_at"])); ?></td>
                                    <td class="text-center">
                                        <form action="<?php echo BASE_URL; ?>admin/edit_user.php" method="post" class="d-inline">
                                            <input type="hidden" name="user_id_to_edit" value="<?php echo $row["id"]; ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                            <button type="submit" class="btn btn-warning btn-sm" title="تعديل">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                        </form>

                                        <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post" class="d-inline">
                                            <input type="hidden" name="user_id_to_delete" value="<?php echo $row["id"]; ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                            <button type="submit" name="delete_user" class="btn btn-danger btn-sm"
                                                    onclick="return confirm('هل أنت متأكد من حذف هذا المستخدم؟ لا يمكن التراجع عن هذا الإجراء.');"
                                                    <?php echo ($row["id"] == $_SESSION['id']) ? 'disabled' : ''; ?>
                                                    title="حذف">
                                                <i class="fas fa-trash-alt"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" class="text-center">لا يوجد مستخدمون لعرضهم.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <!-- </div> -->
</div>

<?php
$conn->close(); // إغلاق الاتصال بقاعدة البيانات
?>

<?php require_once BASE_DIR . 'partials/footer.php'; ?>