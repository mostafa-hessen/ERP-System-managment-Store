<?php
$page_title = "إضافة منتج جديد";
// لتفعيل الرابط في الـ navbar، يمكنك استخدام متغير أكثر وصفاً
// $class_products_active = "active"; أو $active_nav_link = 'manage_products';
// وتستخدمه في navbar.php
require_once dirname(__DIR__) . '/config.php'; // للوصول إلى config.php من داخل مجلد admin
require_once BASE_DIR . 'partials/session_admin.php'; // التأكد من أن المستخدم هو مدير

// تعريف المتغيرات
$product_code = $name = $description = $unit_of_measure = "";
$current_stock = 0.00; // قيمة افتراضية عشرية
$reorder_level = 0.00; // قيمة افتراضية عشرية لحد إعادة الطلب

$product_code_err = $name_err = $unit_of_measure_err = $current_stock_err = $reorder_level_err = "";
$message = "";

// جلب توكن CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// معالجة النموذج عند الإرسال
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_product'])) {
    // التحقق من CSRF
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $message = "<div class='alert alert-danger'>خطأ: طلب غير صالح (CSRF).</div>";
    } else {
        // جلب وتنقية البيانات
        $product_code = trim($_POST["product_code"]);
        $name = trim($_POST["name"]);
        $description = trim($_POST["description"]);
        $unit_of_measure = trim($_POST["unit_of_measure"]);
        $current_stock_posted = $_POST["current_stock"]; // سيتم تحويله لـ float
        $reorder_level_posted = $_POST["reorder_level"]; // سيتم تحويله لـ float

        // --- التحقق من صحة البيانات ---
        // التحقق من كود المنتج
        if (empty($product_code)) {
            $product_code_err = "الرجاء إدخال كود المنتج.";
        } else {
            // التحقق من أن كود المنتج فريد
            $sql_check_code = "SELECT id FROM products WHERE product_code = ?";
            if ($stmt_check = $conn->prepare($sql_check_code)) {
                $stmt_check->bind_param("s", $product_code);
                $stmt_check->execute();
                $stmt_check->store_result();
                if ($stmt_check->num_rows > 0) {
                    $product_code_err = "كود المنتج هذا مسجل بالفعل.";
                }
                // لا نعيد تعيين $product_code هنا إلا إذا كان صالحاً تماماً
                $stmt_check->close();
            } else {
                $message = "<div class='alert alert-danger'>خطأ في التحقق من كود المنتج: " . $conn->error . "</div>";
            }
        }

        // التحقق من اسم المنتج
        if (empty($name)) {
            $name_err = "الرجاء إدخال اسم المنتج.";
        }

        // التحقق من وحدة القياس
        if (empty($unit_of_measure)) {
            $unit_of_measure_err = "الرجاء إدخال وحدة القياس.";
        }

        // التحقق من الرصيد الحالي
        if (!isset($current_stock_posted) || !is_numeric($current_stock_posted) || floatval($current_stock_posted) < 0) {
            $current_stock_err = "الرجاء إدخال رصيد مخزون صحيح (رقم موجب أو صفر، يمكن أن يحتوي على كسور).";
        } else {
            $current_stock = floatval($current_stock_posted);
        }

        // التحقق من حد إعادة الطلب
        if (!isset($reorder_level_posted) || !is_numeric($reorder_level_posted) || floatval($reorder_level_posted) < 0) {
            $reorder_level_err = "الرجاء إدخال حد إعادة طلب صحيح (رقم موجب أو صفر، يمكن أن يحتوي على كسور).";
        } else {
            $reorder_level = floatval($reorder_level_posted);
        }

        // التحقق من عدم وجود أخطاء قبل الإدراج
        if (empty($product_code_err) && empty($name_err) && empty($unit_of_measure_err) && empty($current_stock_err) && empty($reorder_level_err) && empty($message)) {
            $sql_insert = "INSERT INTO products (product_code, name, description, unit_of_measure, current_stock, reorder_level) VALUES (?, ?, ?, ?, ?, ?)";
            if ($stmt_insert = $conn->prepare($sql_insert)) {
                // product_code (s), name (s), description (s), unit_of_measure (s), current_stock (d), reorder_level (d)
                $stmt_insert->bind_param("ssssdd", $product_code, $name, $description, $unit_of_measure, $current_stock, $reorder_level);
                if ($stmt_insert->execute()) {
                    $_SESSION['message'] = "<div class='alert alert-success'>تم إضافة المنتج \"".htmlspecialchars($name)."\" بنجاح!</div>";
                    header("Location: manage_products.php"); // توجيه لصفحة إدارة المنتجات (داخل نفس المجلد admin)
                    exit;
                } else {
                    $message = "<div class='alert alert-danger'>حدث خطأ أثناء إضافة المنتج: " . $stmt_insert->error . "</div>";
                }
                $stmt_insert->close();
            } else {
                 $message = "<div class='alert alert-danger'>خطأ في تحضير الاستعلام: " . $conn->error . "</div>";
            }
        } else {
             if (empty($message)) { // لا تعرض هذه الرسالة إذا كان هناك خطأ آخر ناتج عن التحقق من الكود مثلاً
                $message = "<div class='alert alert-danger'>الرجاء إصلاح الأخطاء في النموذج.</div>";
             }
             // إذا فشل التحقق، قيم POST الأصلية لا تزال في المتغيرات ($product_code, $name, etc.)
             // ولكن يجب التأكد من إعادة تعيين $current_stock و $reorder_level بالقيم المرسلة من POST
             // ليتم عرضها بشكل صحيح في النموذج مع الأخطاء
             $current_stock = isset($current_stock_posted) ? $current_stock_posted : $current_stock;
             $reorder_level = isset($reorder_level_posted) ? $reorder_level_posted : $reorder_level;

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
                <div class="card-header bg-primary text-white text-center">
                    <h2><i class="fas fa-box-open"></i> إضافة منتج جديد</h2>
                </div>
                <div class="card-body p-4">
                    <?php echo $message; ?>
                    <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">

                        <div class="mb-3">
                            <label for="product_code" class="form-label"><i class="fas fa-barcode"></i> كود المنتج:</label>
                            <input type="text" name="product_code" id="product_code" class="form-control <?php echo (!empty($product_code_err)) ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($product_code); ?>" required>
                            <span class="invalid-feedback"><?php echo $product_code_err; ?></span>
                        </div>

                        <div class="mb-3">
                            <label for="name" class="form-label"><i class="fas fa-tag"></i> اسم المنتج:</label>
                            <input type="text" name="name" id="name" class="form-control <?php echo (!empty($name_err)) ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($name); ?>" required>
                            <span class="invalid-feedback"><?php echo $name_err; ?></span>
                        </div>

                        <div class="mb-3">
                            <label for="description" class="form-label"><i class="fas fa-align-left"></i> الوصف (اختياري):</label>
                            <textarea name="description" id="description" class="form-control" rows="3"><?php echo htmlspecialchars($description); ?></textarea>
                        </div>

                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label for="unit_of_measure" class="form-label"><i class="fas fa-balance-scale-right"></i> وحدة القياس:</label>
                                <input type="text" name="unit_of_measure" id="unit_of_measure" placeholder="مثال: قطعة، كجم، عبوة" class="form-control <?php echo (!empty($unit_of_measure_err)) ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($unit_of_measure); ?>" required>
                                <span class="invalid-feedback"><?php echo $unit_of_measure_err; ?></span>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="current_stock" class="form-label"><i class="fas fa-cubes"></i> الرصيد الحالي المبدئي:</label>
                                <input type="number" name="current_stock" id="current_stock" class="form-control <?php echo (!empty($current_stock_err)) ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($current_stock); // سيعرض القيمة كـ 0.00 أو القيمة المدخلة ?>" step="0.01" min="0" required>
                                <span class="invalid-feedback"><?php echo $current_stock_err; ?></span>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="reorder_level" class="form-label"><i class="fas fa-exclamation-triangle"></i> حد إعادة الطلب:</label>
                                <input type="number" name="reorder_level" id="reorder_level" class="form-control <?php echo (!empty($reorder_level_err)) ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($reorder_level); // سيعرض القيمة كـ 0.00 أو القيمة المدخلة ?>" step="0.01" min="0">
                                <span class="invalid-feedback"><?php echo $reorder_level_err; ?></span>
                                <small class="form-text text-muted">تنبيه إذا قل الرصيد عن هذا الحد.</small>
                            </div>

                            <div class="col-md-4 mb-3">
    <label for="cost_price" class="form-label">سعر التكلفة:</label>
    <input type="number" name="cost_price" id="cost_price" class="form-control" step="0.01" min="0" value="<?php echo htmlspecialchars($cost_price); ?>" required>
</div>

<div class="col-md-4 mb-3">
    <label for="selling_price" class="form-label">سعر البيع:</label>
    <input type="number" name="selling_price" id="selling_price" class="form-control" step="0.01" min="0" value="<?php echo htmlspecialchars($selling_price); ?>" required>
</div>

                        </div>

                        <div class="d-grid gap-2 d-md-flex justify-content-md-end mt-4">
                             <a href="manage_products.php" class="btn btn-secondary me-md-2">إلغاء</a>
                            <button type="submit" name="add_product" class="btn btn-primary"><i class="fas fa-plus-circle"></i> إضافة المنتج</button>
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