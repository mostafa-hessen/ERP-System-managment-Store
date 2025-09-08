<?php
// view_invoice_detaiels.php
$page_title = "تعديل الفاتورة";
$class1 = "active";
require_once dirname(__DIR__) . '/config.php';
require_once BASE_DIR . 'partials/session_user.php';
require_once BASE_DIR . 'partials/header.php';

// === تحقق صلاحية المدير (كما كانت) ===
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    $_SESSION['message'] = "<div class='alert alert-danger'>ليس لديك الصلاحية للوصول إلى هذه الصفحة. (المدير فقط)</div>";
    header("Location: " . ($_SESSION['role'] == 'admin' ? 'manage_customer.php' : 'show_customer.php'));
    exit;
}

$message = "";
$invoice_id = 0;
$current_invoice_group = "";
$current_delivered = "";
$invoice_group_err = $delivered_err = "";

// جلب توكن CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

/* =====================
   معالجة التحديث (POST)
   ===================== */
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_invoice'])) {
    // التحقق من CSRF
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $message = "<div class='alert alert-danger'>خطأ: طلب غير صالح (CSRF detected).</div>";
    } else {
        $invoice_id = intval($_POST['invoice_id']); // الحقل المخفي
        $invoice_group_posted = trim($_POST['invoice_group']);
        $delivered_posted = trim($_POST['delivered']);
        $updated_by = $_SESSION['id'];

        $allowed_groups = ['group1','group2','group3','group4','group5','group6','group7','group8','group9','group10','group11'];
        if (empty($invoice_group_posted) || !in_array($invoice_group_posted, $allowed_groups)) {
            $invoice_group_err = "الرجاء اختيار مجموعة فاتورة صالحة.";
        }
        if (!in_array($delivered_posted, ['yes','no'])) {
            $delivered_err = "الرجاء اختيار حالة تسليم صالحة.";
        }

        if (empty($invoice_group_err) && empty($delivered_err)) {
            $sql_update = "UPDATE invoices_out SET invoice_group = ?, delivered = ?, updated_by = ?, updated_at = NOW() WHERE id = ?";
            if ($stmt_update = $conn->prepare($sql_update)) {
                $stmt_update->bind_param("ssii", $invoice_group_posted, $delivered_posted, $updated_by, $invoice_id);
                if ($stmt_update->execute()) {
                    $_SESSION['message'] = "<div class='alert alert-success'>تم تحديث الفاتورة رقم #{$invoice_id} بنجاح.</div>";
                    // === تعديل: إعادة التوجيه بعد الحفظ إلى view_invoice_detaiels.php بدلاً من view.php
                    header("Location: view_invoice_detaiels.php?id=" . $invoice_id);
                    exit;
                } else {
                    $message = "<div class='alert alert-danger'>حدث خطأ أثناء تحديث الفاتورة: " . htmlspecialchars($stmt_update->error) . "</div>";
                }
                $stmt_update->close();
            } else {
                $message = "<div class='alert alert-danger'>خطأ في تحضير استعلام التحديث: " . htmlspecialchars($conn->error) . "</div>";
            }
        } else {
            // إعادة تعبئة القيم المرسلة لعرضها مرة أخرى في النموذج
            $current_invoice_group = $invoice_group_posted;
            $current_delivered = $delivered_posted;
            if(empty($message)) $message = "<div class='alert alert-danger'>الرجاء إصلاح الأخطاء في النموذج.</div>";
        }
    }
}
/* =====================
   جلب بيانات الفاتورة عند GET
   ===================== */
elseif (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $invoice_id = intval($_GET['id']);
    $sql_fetch = "SELECT customer_id, delivered, invoice_group FROM invoices_out WHERE id = ?";
    if ($stmt_fetch = $conn->prepare($sql_fetch)) {
        $stmt_fetch->bind_param("i", $invoice_id);
        if ($stmt_fetch->execute()) {
            $stmt_fetch->bind_result($customer_id_fetched, $current_delivered, $current_invoice_group);
            if (!$stmt_fetch->fetch()) {
                $_SESSION['message'] = "<div class='alert alert-danger'>لم يتم العثور على الفاتورة المطلوبة (رقم: {$invoice_id}).</div>";
                header("Location: manage_customer.php");
                exit;
            }
        } else {
            $_SESSION['message'] = "<div class='alert alert-danger'>خطأ أثناء جلب بيانات الفاتورة.</div>";
            header("Location: manage_customer.php");
            exit;
        }
        $stmt_fetch->close();
    } else {
        $_SESSION['message'] = "<div class='alert alert-danger'>خطأ في تحضير استعلام جلب الفاتورة: " . htmlspecialchars($conn->error) . "</div>";
        header("Location: manage_customer.php");
        exit;
    }
} else {
    $_SESSION['message'] = "<div class='alert alert-warning'>رقم الفاتورة غير محدد.</div>";
    header("Location: manage_customer.php");
    exit;
}

// ضمان استمرار invoice_id من GET إذا لم يكن POST
if ($_SERVER["REQUEST_METHOD"] != "POST" || !isset($_POST['update_invoice'])) {
    if (isset($_GET['id']) && is_numeric($_GET['id']) && empty($_POST['invoice_id'])) {
        $invoice_id = intval($_GET['id']);
    }
}

require_once BASE_DIR . 'partials/sidebar.php';
?>

<!-- ============================
     CSS خاص بالصفحة (مقيد داخل .invoice-detiales)
     يحتوي التعليقات أين عدلت لتفصيل التغييرات
     ============================ -->
<style>
/* === مقيد لعنصر الصفحة فقط === */
.invoice-detiales {}

/* === تحسين مظهر الكارد والنموذج === */
.invoice-detiales .card {
  border-radius: 14px;
  overflow: visible;
  transition: box-shadow .18s ease, transform .12s ease;
}
.invoice-detiales .card-header {
  background: linear-gradient(90deg, var(--amber), color-mix(in srgb, var(--amber) 80%, white 20%));
  color: #111827;
  font-weight: 900;
}
.invoice-detiales .card-body { padding: 22px; }

/* === badges خاصة بالصفحة === */
.invoice-detiales .status-badge {
  display:inline-block;
  padding:6px 12px;
  border-radius: 999px;
  font-weight:800;
  font-size:0.92rem;
  color: #fff;
  box-shadow: 0 6px 18px rgba(2,6,23,0.06);
  transition: transform .12s ease, box-shadow .12s ease;
}
.invoice-detiales .status-badge.paid { background: linear-gradient(135deg, #16a34a, #059669); }
.invoice-detiales .status-badge.pending { background: linear-gradient(135deg, #f59e0b, #f97316); }

/* === إصلاح ألوان النص للـ radio labels (حل مشكلة النص الأسود) === */
/* === تعديل: استخدام var(--text) لضمان التوافق مع Dark/Light theme === */
.invoice-detiales .form-check-label {
  color: var(--text) !important; /* override أي قاعدة أخرى */
  font-weight: 700;
  margin-right: 8px; /* للـ RTL تباعد مع input */
}

/* لو كان هناك وصف صغير داخل الليبل */
.invoice-detiales .form-check-label .muted {
  color: var(--text-soft);
  font-weight: 600;
}

/* تأكد أن عناصر الإدخال تتبع الثيم */
.invoice-detiales .form-check-input {
  width: 18px;
  height: 18px;
  border-radius: 6px;
  box-shadow: none;
  border: 1px solid var(--border);
  background: var(--surface);
  accent-color: var(--primary); /* يدعم المتصفحات الحديثة */
}

/* حالة الاختيار */
.invoice-detiales .form-check-input:checked {
  background: var(--primary);
  border-color: var(--primary);
}

/* prevent any child from forcing black color */
.invoice-detiales * { color: inherit; }

/* === Date input & placeholder fixes === */
.invoice-detiales ::placeholder {
  color: var(--muted);
  opacity: 0.9;
}
[data-app][data-theme="dark"] .invoice-detiales ::placeholder {
  color: var(--text-soft);
  opacity: 0.85;
}
.invoice-detiales input[type="date"] {
  background: var(--surface);
  color: var(--text);
  border: 1px solid var(--border);
  padding: 8px 10px;
  border-radius: 10px;
}
.invoice-detiales input[type="date"]::-webkit-calendar-picker-indicator {
  filter: none;
}
[data-app][data-theme="dark"] .invoice-detiales input[type="date"]::-webkit-calendar-picker-indicator {
  filter: invert(1) brightness(1.2);
}

/* buttons look & spacing */
.invoice-detiales .btn {
  border-radius: 12px;
  font-weight: 800;
  padding: 10px 14px;
}
.invoice-detiales .btn-warning {
  background: var(--amber);
  border-color: color-mix(in srgb, var(--amber) 70%, black 10%);
  color: #111827;
}

/* responsive */
@media (max-width: 720px) {
  .invoice-detiales .card-body { padding: 16px; }
  .invoice-detiales .status-badge { font-size: 0.88rem; padding:6px 10px; }
}
</style>

<!-- صفحة العرض / التعديل -->
<div class="container mt-5 pt-3 invoice-detiales"> <!-- مُقيد بالصفحة -->
    <div class="row justify-content-center">
        <div class="col-md-8 col-lg-6">
            <?php if ($invoice_id > 0) : ?>
            <div class="card shadow-sm">
                <div class="card-header text-center">
                    <h2 class="m-0"><i class="fas fa-edit me-2"></i> تعديل الفاتورة رقم: #<?php echo htmlspecialchars($invoice_id); ?></h2>
                </div>
                <div class="card-body p-4">
                    <?php echo $message; ?>

                    <!-- حالة الفاتورة (badge) -->
                    <div class="mb-3 d-flex align-items-center justify-content-between">
                        <div>
                            <?php if ($current_delivered === 'yes'): ?>
                                <span class="status-badge paid">تم التسليم</span>
                            <?php else: ?>
                                <span class="status-badge pending">لم يتم التسليم</span>
                            <?php endif; ?>
                        </div>
                        <!-- <div class="text-muted small">مجموعة الفاتورة: <strong><?php echo htmlspecialchars($current_invoice_group); ?></strong></div> -->
                    </div>

                    <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]) . "?id=" . intval($invoice_id); ?>" method="post" novalidate>
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="invoice_id" value="<?php echo intval($invoice_id); ?>">

                        <!-- <div class="mb-3">
                            <label for="invoice_group" class="form-label">مجموعة الفاتورة:</label>
                            <select name="invoice_group" id="invoice_group" class="form-select <?php echo (!empty($invoice_group_err)) ? 'is-invalid' : ''; ?>" required>
                                <?php for ($i = 1; $i <= 11; $i++): ?>
                                    <option value="group<?php echo $i; ?>" <?php echo ($current_invoice_group == "group{$i}") ? 'selected' : ''; ?>>
                                        Group <?php echo $i; ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                            <span class="invalid-feedback"><?php echo htmlspecialchars($invoice_group_err); ?></span>
                        </div> -->

                        <div class="mb-4">
                            <label class="form-label">حالة التسليم:</label>
                            <div class="form-check">
                                <input class="form-check-input <?php echo (!empty($delivered_err)) ? 'is-invalid' : ''; ?>" type="radio" name="delivered" id="delivered_no" value="no" <?php echo ($current_delivered == 'no') ? 'checked' : ''; ?>>
                                <!-- === تعديل: نص الليبل يتبع الـ theme (var(--text)) ليتجنب السواد الثابت -->
                                <label class="form-check-label" for="delivered_no">لم يتم التسليم (No)</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input <?php echo (!empty($delivered_err)) ? 'is-invalid' : ''; ?>" type="radio" name="delivered" id="delivered_yes" value="yes" <?php echo ($current_delivered == 'yes') ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="delivered_yes">تم التسليم (Yes)</label>
                            </div>
                            <span class="invalid-feedback d-block"><?php echo htmlspecialchars($delivered_err); ?></span>
                        </div>

                        <!-- <div class="mb-3">
                            <label for="exampleDate" class="form-label">تاريخ مثال (اختياري)</label>
                            <input id="exampleDate" type="date" class="form-control" name="example_date" placeholder="اختر تاريخ" />
                        </div> -->

                        <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                            <!-- === تعديل: رابط الإلغاء يذهب لصفحة view_invoice_detaiels.php مع id === -->
                            <a href="view_invoice_detaiels.php?id=<?php echo intval($invoice_id); ?>" class="btn btn-secondary me-md-2">إلغاء</a>

                            <!-- زر الحفظ: يقوم بنفس POST ثم بعد النجاح server-side يعيد التوجيه (قمت بتعديله أعلاه) -->
                            <button type="submit" name="update_invoice" class="btn btn-warning">
                                <i class="fas fa-save"></i> حفظ / تحديث
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            <?php else: ?>
                <?php if(empty($message)) echo "<div class='alert alert-warning text-center'>الفاتورة المطلوبة غير موجودة أو رقمها غير صحيح.</div>"; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php $conn->close(); ?>
<?php require_once BASE_DIR . 'partials/footer.php'; ?>
