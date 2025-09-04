<?php
$page_title = "استعراض العملاء"; // تحديد عنوان الصفحة
$class1 = "active";
require_once dirname(__DIR__) . '/config.php';
require_once BASE_DIR . 'partials/session_user.php';
require_once BASE_DIR . 'partials/header.php';
require_once BASE_DIR . 'partials/navbar.php';

$message = ""; // لرسائل الحالة
$search_term = ""; // لتخزين مصطلح البحث
$result = null; // لنتائج البحث

// --- !! جلب الرسائل من الجلسة (إن وجدت) !! ---
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message']; // جلب الرسالة
    unset($_SESSION['message']);    // حذف الرسالة من الجلسة
}

// جلب توكن CSRF الحالي
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];


// --- !! معالجة طلب الإنشاء الفوري !! ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['create_invoice_now'])) {
    // التحقق من CSRF
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['message'] = "<div class='alert alert-danger'>خطأ: طلب غير صالح (CSRF detected).</div>";
    } else {
        $customer_id = intval($_POST['customer_id']);
        $created_by = $_SESSION['id'];
        $invoice_group = 'group1'; // تحديد المجموعة تلقائياً
        $delivered = 'no'; // القيمة الافتراضية

        // التحقق من وجود العميل
        $sql_check_cust = "SELECT id FROM customers WHERE id = ?";
        $stmt_check = $conn->prepare($sql_check_cust);
        $stmt_check->bind_param("i", $customer_id);
        $stmt_check->execute();
        $stmt_check->store_result();

        if($stmt_check->num_rows > 0){
            // إدراج الفاتورة في قاعدة البيانات
            $sql_insert = "INSERT INTO invoices_out (customer_id, delivered, invoice_group, created_by) VALUES (?, ?, ?, ?)";
            if ($stmt_insert = $conn->prepare($sql_insert)) {
                $stmt_insert->bind_param("issi", $customer_id, $delivered, $invoice_group, $created_by);
                if ($stmt_insert->execute()) {
                    $new_invoice_id = $stmt_insert->insert_id; // الحصول على ID الفاتورة الجديدة

                    // --- !! إعادة التوجيه إلى صفحة عرض الفاتورة !! ---
                    header("Location: " . BASE_URL . "invoices_out/view.php?id=" . $new_invoice_id);
                    exit; // مهم جداً

                } else {
                    $_SESSION['message'] = "<div class='alert alert-danger'>حدث خطأ أثناء إنشاء الفاتورة: " . $stmt_insert->error . "</div>";
                }
                $stmt_insert->close();
            } else {
                 $_SESSION['message'] = "<div class='alert alert-danger'>خطأ في تحضير الاستعلام: " . $conn->error . "</div>";
            }
        } else {
            $_SESSION['message'] = "<div class='alert alert-danger'>العميل المحدد غير موجود.</div>";
        }
         $stmt_check->close();
    }
    // --- !! في حالة الخطأ (أو CSRF)، أعد التوجيه مرة أخرى لنفس الصفحة لعرض الرسالة !! ---
    header("Location: show_customer.php");
    exit;
}
// --- !! نهاية معالجة الإنشاء الفوري !! ---


// --- معالجة البحث (باستخدام POST) ---
// if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['search_button'])) {
//     $search_term = trim($_POST['search_term']);
// }

// // --- بناء وعرض العملاء (مع JOIN والبحث) ---
// $sql_select = "SELECT c.id, c.name, c.mobile, c.city, c.address, c.created_at, u.username as creator_name
//                FROM customers c
//                LEFT JOIN users u ON c.created_by = u.id ";

// $params = [];
// $types = "";

// if (!empty($search_term)) {
//     $sql_select .= " WHERE (c.name LIKE ? OR c.mobile LIKE ?) ";
//     $search_like = "%" . $search_term . "%";
//     $params[] = $search_like;
//     $params[] = $search_like;
//     $types .= "ss";
// } //الكود الاصلي 

// --- معالجة البحث (باستخدام POST) ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['search_button'])) {
    $search_term = trim($_POST['search_term']);
}

// --- بناء وعرض العملاء (مع JOIN والبحث) ---
$sql_select = "SELECT c.id, c.name, c.mobile, c.city, c.address, c.created_at, u.username as creator_name
               FROM customers c
               LEFT JOIN users u ON c.created_by = u.id ";

$params = [];
$types = "";

if (!empty($search_term)) {
    // البحث بالاسم أو الموبايل أو كود العميل (ID)
    $sql_select .= " WHERE (c.name LIKE ? OR c.mobile LIKE ? OR c.id = ?) ";
    $search_like = "%" . $search_term . "%";
    $params[] = $search_like;
    $params[] = $search_like;
    
    // التحقق إذا كان مصطلح البحث رقمًا (للبحث بالكود)
    if (is_numeric($search_term)) {
        $params[] = intval($search_term);
        $types .= "ssi"; // نوعين نص ورقم
    } else {
        $params[] = 0; // قيمة غير صحيحة للبحث بالكود إذا لم يكن رقمًا
        $types .= "ssi"; // نوعين نص ورقم
    }
}//الكود الجديد سعيد

// $sql_select .= " ORDER BY c.id DESC"; // دا الكود الاصلي 
$sql_select .= " ORDER BY (c.id = 8) DESC, c.id DESC";  //دا تعديل سعيد


if ($stmt_select = $conn->prepare($sql_select)) {
    if (!empty($params)) {
        $stmt_select->bind_param($types, ...$params);
    }
    if ($stmt_select->execute()) {
        $result = $stmt_select->get_result();
    } else {
        $message = "<div class='alert alert-danger'>حدث خطأ أثناء جلب بيانات العملاء. " . $stmt_select->error . "</div>";
    }
    $stmt_select->close();
} else {
    $message = "<div class='alert alert-danger'>خطأ في تحضير استعلام الجلب: " . $conn->error . "</div>";
}

?>

<div class="container mt-5 pt-3">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1><i class="fas fa-search"></i> استعراض وبحث العملاء</h1>
        <a href="insert.php" class="btn btn-success"><i class="fas fa-plus-circle"></i> إضافة عميل جديد</a>
    </div>

    <div class="card mb-4">
        <div class="card-body">
            <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
                <div class="row g-3 align-items-center">
                    <div class="col">
                        <label for="search_term" class="visually-hidden">بحث</label>
                        <input type="text" class="form-control" id="search_term" name="search_term"
                               placeholder="أدخل جزء من الاسم أو رقم الموبايل..."
                               value="<?php echo htmlspecialchars($search_term); ?>">
                    </div>
                    <div class="col-auto">
                        <button type="submit" name="search_button" class="btn btn-primary">
                            <i class="fas fa-search"></i> بحث
                        </button>
                        <?php if(!empty($search_term)): ?>
                           <a href="show_customer.php" class="btn btn-outline-secondary ms-2">
                               <i class="fas fa-times"></i> مسح البحث
                           </a>
                        <?php endif; ?>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <?php echo $message; // عرض الرسائل (من الجلسة أو من أخطاء الجلب) ?>

    <div class="card">
        <div class="card-header">
            قائمة العملاء
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
                            <th>العنوان</th>
                            <th>أضيف بواسطة</th>
                            <th>تاريخ الإضافة</th>
                            <th class="text-center">إجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result && $result->num_rows > 0): ?>
                            <?php while($row = $result->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo $row["id"]; ?></td>
                                    <td><?php echo htmlspecialchars($row["name"]); ?></td>
                                    <td><?php echo htmlspecialchars($row["mobile"]); ?></td>
                                    <td><?php echo htmlspecialchars($row["city"]); ?></td>
                                    <td><?php echo !empty($row["address"]) ? htmlspecialchars($row["address"]) : '-'; ?></td>
                                    <td>
                                        <?php echo !empty($row["creator_name"]) ? htmlspecialchars($row["creator_name"]) : '<span class="text-muted">محذوف/نظام</span>'; ?>
                                    </td>
                                    <td><?php echo date('Y-m-d', strtotime($row["created_at"])); ?></td>
                                    <td class="text-center">
                                        <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post" class="d-inline">
                                            <input type="hidden" name="customer_id" value="<?php echo $row["id"]; ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                            <button type="submit" name="create_invoice_now" class="btn btn-success btn-sm" title="إنشاء فاتورة (Group 1)">
                                                <i class="fas fa-file-invoice-dollar"></i> إنشاء فاتورة
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8">
                                    <?php echo !empty($search_term) ? 'لا توجد نتائج تطابق بحثك.' : 'لا يوجد عملاء لعرضهم.'; ?>
                                    <a href="insert_customer.php">أضف واحداً الآن!</a>
                                </td>
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