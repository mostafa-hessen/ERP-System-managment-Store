<?php
$page_title = "إضافة مصروف جديد";
// $class_expenses_active = "active"; // لتفعيل الرابط في الـ navbar
require_once dirname(__DIR__) . '/config.php'; // للوصول إلى config.php من داخل مجلد admin
require_once BASE_DIR . 'partials/session_admin.php'; // صلاحيات المدير فقط

// تعريف المتغيرات
$expense_date = date('Y-m-d'); // تاريخ اليوم كافتراضي
$description = "";
$amount = "";
$category_id = ""; // سيكون فارغاً إذا لم يتم اختيار فئة
$notes = "";

$expense_date_err = $description_err = $amount_err = $category_id_err = "";
$message = "";
$categories_list = []; // لتخزين فئات المصاريف

// جلب توكن CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// --- جلب فئات المصاريف لملء القائمة المنسدلة ---
$sql_categories = "SELECT id, name FROM expense_categories ORDER BY name ASC";
$result_categories = $conn->query($sql_categories);
if ($result_categories && $result_categories->num_rows > 0) {
    while ($row_cat = $result_categories->fetch_assoc()) {
        $categories_list[] = $row_cat;
    }
}

// --- معالجة النموذج عند الإرسال ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_expense'])) {
    // التحقق من CSRF
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $message = "<div class='alert alert-danger'>خطأ: طلب غير صالح (CSRF).</div>";
    } else {
        // جلب وتنقية البيانات
        $expense_date = trim($_POST["expense_date"]);
        $description = trim($_POST["description"]);
        $amount_posted = trim($_POST["amount"]);
        $category_id = !empty($_POST["category_id"]) ? intval($_POST["category_id"]) : null; // إذا لم يتم اختيار فئة، اجعلها NULL
        $notes = trim($_POST["notes"]);
        $created_by = $_SESSION['id'];

        // --- التحقق من صحة البيانات ---
        if (empty($expense_date)) {
            $expense_date_err = "الرجاء إدخال تاريخ المصروف.";
        } elseif (DateTime::createFromFormat('Y-m-d', $expense_date) === false) {
            $expense_date_err = "صيغة التاريخ غير صحيحة.";
        }

        if (empty($description)) {
            $description_err = "الرجاء إدخال وصف المصروف.";
        }

        if (empty($amount_posted)) {
            $amount_err = "الرجاء إدخال قيمة المصروف.";
        } elseif (!is_numeric($amount_posted) || floatval($amount_posted) <= 0) {
            $amount_err = "الرجاء إدخال قيمة مصروف صحيحة (رقم أكبر من صفر).";
        } else {
            $amount = floatval($amount_posted);
        }
        
        // (اختياري) التحقق من أن category_id موجود إذا تم اختياره
        if ($category_id !== null) {
            $category_exists = false;
            foreach ($categories_list as $cat) {
                if ($cat['id'] == $category_id) {
                    $category_exists = true;
                    break;
                }
            }
            if (!$category_exists && $category_id != 0) { // 0 أو "" قد يعني "بدون فئة"
                 // $category_id_err = "الفئة المختارة غير صالحة.";
                 // بدلاً من الخطأ، يمكننا ببساطة تعيينه إلى NULL إذا لم تكن هناك فئة مطابقة
                 // أو الاعتماد على أن القائمة المنسدلة لن تسمح بإرسال قيمة غير موجودة.
                 // إذا كان الـ value للقائمة المنسدلة هو "" لـ "بدون فئة"، فإن intval("") تصبح 0
                 if($category_id == 0) $category_id = null;

            }
        }


        // التحقق من عدم وجود أخطاء قبل الإدراج
        if (empty($expense_date_err) && empty($description_err) && empty($amount_err) && empty($category_id_err) && empty($message)) {
            $sql_insert = "INSERT INTO expenses (expense_date, description, amount, category_id, notes, created_by)
                           VALUES (?, ?, ?, ?, ?, ?)";
            if ($stmt_insert = $conn->prepare($sql_insert)) {
                // تحديد نوع category_id بناءً على ما إذا كان NULL أم لا
                if ($category_id === null) {
                    $stmt_insert->bind_param("ssdssi", $expense_date, $description, $amount, $category_id_null, $notes, $created_by);
                    $category_id_null = null; // تمرير NULL بشكل صريح
                } else {
                    $stmt_insert->bind_param("ssdidi", $expense_date, $description, $amount, $category_id, $notes, $created_by);
                }

                if ($stmt_insert->execute()) {
                    $_SESSION['message'] = "<div class='alert alert-success'>تم إضافة المصروف بنجاح!</div>";
                    header("Location: manage_expenses.php"); // توجيه لصفحة إدارة المصاريف (سننشئها لاحقاً)
                    exit;
                } else {
                    $message = "<div class='alert alert-danger'>حدث خطأ أثناء إضافة المصروف: " . $stmt_insert->error . "</div>";
                }
                $stmt_insert->close();
            } else {
                 $message = "<div class='alert alert-danger'>خطأ في تحضير الاستعلام: " . $conn->error . "</div>";
            }
        } else {
             if (empty($message)) {
                $message = "<div class='alert alert-danger'>الرجاء إصلاح الأخطاء في النموذج.</div>";
             }
        }
    }
}

require_once BASE_DIR . 'partials/header.php';
require_once BASE_DIR . 'partials/navbar.php';
?>

<div class="container mt-5 pt-3">
    <div class="row justify-content-center">
        <div class="col-md-8 col-lg-7">
            <div class="card shadow-sm">
                <div class="card-header bg-danger text-white text-center">
                    <h2><i class="fas fa-money-bill-wave"></i> إضافة مصروف جديد</h2>
                </div>
                <div class="card-body p-4">
                    <?php echo $message; ?>
                    <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="expense_date" class="form-label"><i class="fas fa-calendar-alt"></i> تاريخ المصروف:</label>
                                <input type="date" name="expense_date" id="expense_date" class="form-control <?php echo (!empty($expense_date_err)) ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($expense_date); ?>" required>
                                <span class="invalid-feedback"><?php echo $expense_date_err; ?></span>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="amount" class="form-label"><i class="fas fa-coins"></i> قيمة المصروف:</label>
                                <input type="number" name="amount" id="amount" class="form-control <?php echo (!empty($amount_err)) ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($amount); ?>" step="0.01" min="0.01" required>
                                <span class="invalid-feedback"><?php echo $amount_err; ?></span>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="description" class="form-label"><i class="fas fa-file-alt"></i> وصف/بيان المصروف:</label>
                            <input type="text" name="description" id="description" class="form-control <?php echo (!empty($description_err)) ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($description); ?>" required>
                            <span class="invalid-feedback"><?php echo $description_err; ?></span>
                        </div>

                        <div class="mb-3">
                            <label for="category_id" class="form-label"><i class="fas fa-tags"></i> فئة المصروف (اختياري):</label>
                            <select name="category_id" id="category_id" class="form-select <?php echo (!empty($category_id_err)) ? 'is-invalid' : ''; ?>">
                                <option value="">-- بدون فئة --</option>
                                <?php if (!empty($categories_list)): ?>
                                    <?php foreach ($categories_list as $category): ?>
                                        <option value="<?php echo $category['id']; ?>" <?php echo ($category_id == $category['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($category['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <option value="" disabled>لا توجد فئات مصاريف معرفة حالياً</option>
                                <?php endif; ?>
                            </select>
                            <span class="invalid-feedback"><?php echo $category_id_err; ?></span>
                             <small class="form-text text-muted">يمكنك إضافة فئات من <a href="<?php echo BASE_URL; ?>admin/manage_expense_categories.php">إدارة فئات المصاريف</a>.</small>
                        </div>

                        <div class="mb-3">
                            <label for="notes" class="form-label"><i class="fas fa-sticky-note"></i> ملاحظات (اختياري):</label>
                            <textarea name="notes" id="notes" class="form-control" rows="3"><?php echo htmlspecialchars($notes); ?></textarea>
                        </div>

                        <div class="d-grid gap-2 d-md-flex justify-content-md-end mt-4">
                             <a href="<?php echo BASE_URL; ?>admin/manage_expenses.php" class="btn btn-secondary me-md-2">إلغاء</a> <button type="submit" name="add_expense" class="btn btn-danger"><i class="fas fa-plus-circle"></i> إضافة المصروف</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
$conn->close();
require_once BASE_DIR . 'partials/footer.php';
?>