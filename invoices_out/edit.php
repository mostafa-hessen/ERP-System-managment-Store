<?php
$page_title = "تعديل الفاتورة";
$class1 = "active";
require_once dirname(__DIR__) . '/config.php';
require_once BASE_DIR . 'partials/session_user.php';
require_once BASE_DIR . 'partials/header.php';
require_once BASE_DIR . 'partials/sidebar.php';

// --- !! التحقق من أن المستخدم هو مدير !! ---
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    $_SESSION['message'] = "<div class='alert alert-danger'>ليس لديك الصلاحية للوصول إلى هذه الصفحة. (المدير فقط)</div>";
    header("Location: " . ($_SESSION['role'] == 'admin' ? 'manage_customer.php' : 'show_customer.php')); // توجيه مناسب
    exit;
}

$message = "";
$invoice_id = 0;
$current_invoice_group = "";
$current_delivered = "";
// $customer_id = 0; // لا نحتاجه مباشرة هنا إلا للعودة أو إذا أردنا عرض اسم العميل

$invoice_group_err = $delivered_err = "";

// جلب توكن CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// --- 2. معالجة طلب التحديث (عند إرسال النموذج) ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_invoice'])) {
    // التحقق من CSRF
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $message = "<div class='alert alert-danger'>خطأ: طلب غير صالح (CSRF detected).</div>";
    } else {
        $invoice_id = intval($_POST['invoice_id']); // من الحقل المخفي
        $invoice_group_posted = trim($_POST['invoice_group']);
        $delivered_posted = trim($_POST['delivered']);
        $updated_by = $_SESSION['id']; // المدير هو من يقوم بالتحديث

        // التحقق من صحة البيانات (يمكن تكرار التحقق من الصلاحية هنا إذا أردت، لكننا تحققنا في الأعلى)
        $allowed_groups = ['group1', 'group2', 'group3', 'group4', 'group5', 'group6', 'group7', 'group8', 'group9', 'group10', 'group11'];
        if (empty($invoice_group_posted) || !in_array($invoice_group_posted, $allowed_groups)) {
            $invoice_group_err = "الرجاء اختيار مجموعة فاتورة صالحة.";
        }
        if (!in_array($delivered_posted, ['yes', 'no'])) {
            $delivered_err = "الرجاء اختيار حالة تسليم صالحة.";
        }

        if (empty($invoice_group_err) && empty($delivered_err)) {
            $sql_update = "UPDATE invoices_out SET invoice_group = ?, delivered = ?, updated_by = ?, updated_at = NOW() WHERE id = ?";
            if ($stmt_update = $conn->prepare($sql_update)) {
                $stmt_update->bind_param("ssii", $invoice_group_posted, $delivered_posted, $updated_by, $invoice_id);
                if ($stmt_update->execute()) {
                    $_SESSION['message'] = "<div class='alert alert-success'>تم تحديث الفاتورة رقم #{$invoice_id} بنجاح.</div>";
                    header("Location: view.php?id=" . $invoice_id);
                    exit;
                } else {
                    $message = "<div class='alert alert-danger'>حدث خطأ أثناء تحديث الفاتورة: " . $stmt_update->error . "</div>";
                }
                $stmt_update->close();
            } else {
                $message = "<div class='alert alert-danger'>خطأ في تحضير استعلام التحديث: " . $conn->error . "</div>";
            }
        } else {
            // إذا فشل التحقق، أعد تعبئة القيم الحالية بالقيم المرسلة لعرضها في النموذج
            $current_invoice_group = $invoice_group_posted;
            $current_delivered = $delivered_posted;
            if(empty($message)) $message = "<div class='alert alert-danger'>الرجاء إصلاح الأخطاء في النموذج.</div>";
        }
    }
}
// --- 3. جلب بيانات الفاتورة لعرضها في النموذج (عند تحميل الصفحة عبر GET) ---
// يتم هذا الجزء فقط إذا لم يكن هناك طلب POST للتحديث (لتجنب جلب البيانات القديمة بعد فشل POST)
elseif (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $invoice_id = intval($_GET['id']);
    $sql_fetch = "SELECT customer_id, delivered, invoice_group FROM invoices_out WHERE id = ?";
    if ($stmt_fetch = $conn->prepare($sql_fetch)) {
        $stmt_fetch->bind_param("i", $invoice_id);
        if ($stmt_fetch->execute()) {
            $stmt_fetch->bind_result($customer_id_fetched, $current_delivered, $current_invoice_group); // تم تغيير $customer_id إلى $customer_id_fetched
            if (!$stmt_fetch->fetch()) {
                $_SESSION['message'] = "<div class='alert alert-danger'>لم يتم العثور على الفاتورة المطلوبة (رقم: {$invoice_id}).</div>";
                header("Location: manage_customer.php"); // بما أن المدير فقط هنا، نوجهه لصفحة إدارة العملاء
                exit;
            }
            // لا نحتاج للتحقق من الصلاحية مرة أخرى هنا لأننا تحققنا في بداية الصفحة
        } else {
            $_SESSION['message'] = "<div class='alert alert-danger'>خطأ أثناء جلب بيانات الفاتورة.</div>";
            header("Location: manage_customer.php");
            exit;
        }
        $stmt_fetch->close();
    } else {
        $_SESSION['message'] = "<div class='alert alert-danger'>خطأ في تحضير استعلام جلب الفاتورة: " . $conn->error . "</div>";
        header("Location: manage_customer.php");
        exit;
    }
} else {
    // إذا لم يتم توفير ID صالح في GET ولم يكن هناك طلب POST للتحديث
    $_SESSION['message'] = "<div class='alert alert-warning'>رقم الفاتورة غير محدد.</div>";
    header("Location: manage_customer.php"); // بما أن المدير فقط هنا، نوجهه لصفحة إدارة العملاء
    exit;
}

// إذا فشل تحديث POST ووصلنا إلى هنا، $invoice_id سيكون معيّنًا من POST
// ولكن نحتاج $customer_id_fetched إذا لم يكن لدينا تحديث POST
if ($_SERVER["REQUEST_METHOD"] != "POST" || !isset($_POST['update_invoice'])) {
    // هذا الجزء مهم إذا فشل تحديث POST أو عند تحميل GET
    // تأكد أن $invoice_id من GET لا يزال محتفظًا به إذا لم يكن هناك تحديث
    if (isset($_GET['id']) && is_numeric($_GET['id']) && empty($_POST['invoice_id'])) {
        $invoice_id = intval($_GET['id']);
    }
}
?>

<div class="container mt-5 pt-3">
    <div class="row justify-content-center">
        <div class="col-md-8 col-lg-6">
            <?php if ($invoice_id > 0) : // اعرض النموذج فقط إذا كان ID الفاتورة صالحاً ?>
            <div class="card shadow-sm">
                <div class="card-header bg-warning text-dark text-center">
                    <h2><i class="fas fa-edit"></i> تعديل الفاتورة رقم: #<?php echo $invoice_id; ?></h2>
                </div>
                <div class="card-body p-4">
                    <?php echo $message; // عرض رسائل الحالة ?>

                    <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]) . "?id=" . $invoice_id; // أبقي الـ ID في الرابط للعودة بعد فشل ال POST ?>" method="post">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="invoice_id" value="<?php echo $invoice_id; ?>">

                        <div class="mb-3">
                            <label for="invoice_group" class="form-label">مجموعة الفاتورة:</label>
                            <select name="invoice_group" id="invoice_group" class="form-select <?php echo (!empty($invoice_group_err)) ? 'is-invalid' : ''; ?>" required>
                                <?php for ($i = 1; $i <= 11; $i++): ?>
                                    <option value="group<?php echo $i; ?>" <?php echo ($current_invoice_group == "group{$i}") ? 'selected' : ''; ?>>
                                        Group <?php echo $i; ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                            <span class="invalid-feedback"><?php echo $invoice_group_err; ?></span>
                        </div>

                        <div class="mb-4">
                            <label class="form-label">حالة التسليم:</label>
                            <div class="form-check">
                                <input class="form-check-input <?php echo (!empty($delivered_err)) ? 'is-invalid' : ''; ?>" type="radio" name="delivered" id="delivered_no" value="no" <?php echo ($current_delivered == 'no') ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="delivered_no">
                                    لم يتم التسليم (No)
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input <?php echo (!empty($delivered_err)) ? 'is-invalid' : ''; ?>" type="radio" name="delivered" id="delivered_yes" value="yes" <?php echo ($current_delivered == 'yes') ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="delivered_yes">
                                    تم التسليم (Yes)
                                </label>
                            </div>
                             <span class="invalid-feedback d-block"><?php echo $delivered_err; ?></span>
                        </div>

                        <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                            <a href="view.php?id=<?php echo $invoice_id; ?>" class="btn btn-secondary me-md-2">إلغاء</a>
                            <button type="submit" name="update_invoice" class="btn btn-warning">
                                <i class="fas fa-save"></i> تحديث الفاتورة
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            <?php else: // إذا لم يتم العثور على الفاتورة أو ID غير صالح ?>
                <?php if(empty($message)) echo "<div class='alert alert-warning text-center'>الفاتورة المطلوبة غير موجودة أو رقمها غير صحيح.</div>"; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php $conn->close(); ?>
<?php require_once BASE_DIR . 'partials/footer.php'; ?>