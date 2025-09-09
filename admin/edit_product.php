<?php
$page_title = "تعديل منتج";
require_once dirname(__DIR__) . '/config.php';
require_once BASE_DIR . 'partials/session_admin.php'; // صلاحيات المدير فقط

// تعريف المتغيرات
$product_id = 0;
$product_code = $name = $description = $unit_of_measure = "";
$current_stock = $reorder_level = $cost_price = $selling_price = 0.00;

$product_code_err = $name_err = $unit_of_measure_err = $current_stock_err = $reorder_level_err = "";
$message = "";

// جلب توكن CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// --- معالجة طلب التحديث ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_product'])) {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $message = "<div class='alert alert-danger'>خطأ: طلب غير صالح (CSRF).</div>";
    } else {
        $product_id = intval($_POST['product_id']);

        // جلب وتنقية البيانات
        $product_code_posted = trim($_POST["product_code"]);
        $name_posted = trim($_POST["name"]);
        $description_posted = trim($_POST["description"]);
        $unit_of_measure_posted = trim($_POST["unit_of_measure"]);
        $current_stock_posted = $_POST["current_stock"];
        $reorder_level_posted = $_POST["reorder_level"];
        $cost_price_posted = trim($_POST['cost_price']);
        $selling_price_posted = trim($_POST['selling_price']);

        // --- التحقق من صحة البيانات ---
        if (empty($product_code_posted)) {
            $product_code_err = "الرجاء إدخال كود المنتج.";
        } else {
            $sql_check_code = "SELECT id FROM products WHERE product_code = ? AND id != ?";
            if ($stmt_check = $conn->prepare($sql_check_code)) {
                $stmt_check->bind_param("si", $product_code_posted, $product_id);
                $stmt_check->execute();
                $stmt_check->store_result();
                if ($stmt_check->num_rows > 0) {
                    $product_code_err = "كود المنتج هذا مسجل بالفعل لمنتج آخر.";
                }
                $stmt_check->close();
            } else {
                $message = "<div class='alert alert-danger'>خطأ في التحقق من كود المنتج: " . $conn->error . "</div>";
            }
        }

        if (empty($name_posted)) {
            $name_err = "الرجاء إدخال اسم المنتج.";
        }
        if (empty($unit_of_measure_posted)) {
            $unit_of_measure_err = "الرجاء إدخال وحدة القياس.";
        }

        if (!isset($current_stock_posted) || !is_numeric($current_stock_posted) || floatval($current_stock_posted) < 0) {
            $current_stock_err = "الرجاء إدخال رصيد مخزون صحيح (رقم موجب أو صفر).";
        }
        if (!isset($reorder_level_posted) || !is_numeric($reorder_level_posted) || floatval($reorder_level_posted) < 0) {
            $reorder_level_err = "الرجاء إدخال حد إعادة طلب صحيح (رقم موجب أو صفر).";
        }

        // تحديث القيم فقط إذا لم يكن هناك أخطاء
        if (empty($product_code_err) && empty($name_err) && empty($unit_of_measure_err) && empty($current_stock_err) && empty($reorder_level_err) && empty($message)) {
            $product_code = $product_code_posted;
            $name = $name_posted;
            $description = $description_posted;
            $unit_of_measure = $unit_of_measure_posted;
            $current_stock = floatval($current_stock_posted);
            $reorder_level = floatval($reorder_level_posted);

            // إذا تم إدخال قيمة صالحة فقط حدث السعر
            if ($cost_price_posted !== "" && is_numeric($cost_price_posted) && floatval($cost_price_posted) >= 0) {
                $cost_price = floatval($cost_price_posted);
            }
            if ($selling_price_posted !== "" && is_numeric($selling_price_posted) && floatval($selling_price_posted) >= 0) {
                $selling_price = floatval($selling_price_posted);
            }

            $sql_update = "UPDATE products SET product_code = ?, name = ?, description = ?, unit_of_measure = ?, current_stock = ?, reorder_level = ?, cost_price = ?, selling_price = ? WHERE id = ?";
            if ($stmt_update = $conn->prepare($sql_update)) {
                $stmt_update->bind_param("ssssddddi", $product_code, $name, $description, $unit_of_measure, $current_stock, $reorder_level, $cost_price, $selling_price, $product_id);
                if ($stmt_update->execute()) {
                    $_SESSION['message'] = "<div class='alert alert-success'>تم تحديث المنتج \"" . htmlspecialchars($name) . "\" بنجاح!</div>";
                    header("Location: manage_products.php");
                    exit;
                } else {
                    $message = "<div class='alert alert-danger'>حدث خطأ أثناء تحديث المنتج: " . $stmt_update->error . "</div>";
                }
                $stmt_update->close();
            } else {
                $message = "<div class='alert alert-danger'>خطأ في تحضير استعلام التحديث: " . $conn->error . "</div>";
            }
        } else {
            if (empty($message)) $message = "<div class='alert alert-danger'>الرجاء إصلاح الأخطاء في النموذج.</div>";
            // الاحتفاظ بالقيم المرسلة
            $product_code = $product_code_posted;
            $name = $name_posted;
            $description = $description_posted;
            $unit_of_measure = $unit_of_measure_posted;
            $current_stock = $current_stock_posted;
            $reorder_level = $reorder_level_posted;
        }
    }
}

// --- جلب بيانات المنتج للعرض في الفورم ---
elseif (($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['product_id_to_edit'])) || ($_SERVER["REQUEST_METHOD"] == "GET" && isset($_GET['id']))) {
    $product_id = intval($_POST['product_id_to_edit'] ?? $_GET['id']);

    $sql_fetch = "SELECT product_code, name, description, unit_of_measure, current_stock, reorder_level, cost_price, selling_price FROM products WHERE id = ?";
    if ($stmt_fetch = $conn->prepare($sql_fetch)) {
        $stmt_fetch->bind_param("i", $product_id);
        $stmt_fetch->execute();
        $stmt_fetch->bind_result($product_code_db, $name_db, $description_db, $unit_of_measure_db, $current_stock_db, $reorder_level_db, $cost_price_db, $selling_price_db);
        if ($stmt_fetch->fetch()) {
            $product_code = $product_code_db;
            $name = $name_db;
            $description = $description_db;
            $unit_of_measure = $unit_of_measure_db;
            $current_stock = floatval($current_stock_db);
            $reorder_level = floatval($reorder_level_db);
            $cost_price = floatval($cost_price_db);
            $selling_price = floatval($selling_price_db);
        } else {
            $_SESSION['message'] = "<div class='alert alert-danger'>لم يتم العثور على المنتج المطلوب.</div>";
            header("Location: manage_products.php");
            exit;
        }
        $stmt_fetch->close();
    } else {
        $_SESSION['message'] = "<div class='alert alert-danger'>خطأ في تحضير استعلام جلب المنتج: " . $conn->error . "</div>";
        header("Location: manage_products.php");
        exit;
    }
}

require_once BASE_DIR . 'partials/header.php';
require_once BASE_DIR . 'partials/sidebar.php';
?>

<div class="container mt-5 pt-3">
    <div class="row justify-content-center">
        <div class="col-md-8 col-lg-7">
            <?php if ($product_id > 0) : ?>
                <div class="card shadow-sm">
                    <div class="card-header bg-warning text-dak text-center">
                        <h2><i class="fas fa-edit"></i> تعديل المنتج (ID: <?php echo $product_id; ?> - <?php echo htmlspecialchars($name); ?>)</h2>
                    </div>
                    <div class="card-body p-4">
                        <?php echo $message; ?>
                        <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>?id=<?php echo $product_id; ?>" method="post">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            <input type="hidden" name="product_id" value="<?php echo $product_id; ?>">

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
                                    <input type="text" name="unit_of_measure" id="unit_of_measure" placeholder="مثال: قطعة، كجم" class="form-control <?php echo (!empty($unit_of_measure_err)) ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($unit_of_measure); ?>" required>
                                    <span class="invalid-feedback"><?php echo $unit_of_measure_err; ?></span>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label for="current_stock" class="form-label"><i class="fas fa-cubes"></i> الرصيد الحالي:</label>
                                    <input type="number" name="current_stock" id="current_stock" class="form-control <?php echo (!empty($current_stock_err)) ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars(is_numeric($current_stock) ? number_format($current_stock, 2, '.', '') : $current_stock); ?>" step="0.01" min="0" required>
                                    <span class="invalid-feedback"><?php echo $current_stock_err; ?></span>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label for="reorder_level" class="form-label"><i class="fas fa-exclamation-triangle"></i> حد إعادة الطلب:</label>
                                    <input type="number" name="reorder_level" id="reorder_level" class="form-control <?php echo (!empty($reorder_level_err)) ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars(is_numeric($reorder_level) ? number_format($reorder_level, 2, '.', '') : $reorder_level); ?>" step="0.01" min="0">
                                    <span class="invalid-feedback"><?php echo $reorder_level_err; ?></span>
                                    <small class="form-text note-text">تنبيه إذا قل الرصيد عن هذا الحد.</small>
                                </div>

                                <!-- أسعار الشراء والبيع -->
                                <div class="col-md-6 mb-3">
                                    <label for="cost_price" class="form-label"><i class="fas fa-dollar-sign"></i> سعر الشراء (اختياري):</label>
                                    <input type="number" name="cost_price" id="cost_price" class="form-control"
                                        <?php echo (!empty($cost_price)) ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars(is_numeric($cost_price) ? number_format($cost_price, 2, '.', '') : $cost_price); ?>" step="0.01" min="0">
                                    <small class="form-text note-text">اتركه فارغًا إذا لا تريد تغييره.</small>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="selling_price" class="form-label"><i class="fas fa-dollar-sign"></i> سعر البيع (اختياري):</label>
                                    <input type="number" name="selling_price" id="selling_price" class="form-control"

                                        <?php echo (!empty($selling_price)) ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars(is_numeric($selling_price) ? number_format($selling_price, 2, '.', '') : $selling_price); ?>" step="0.01" min="0"> 
                                        <small class="form-text note-text">اتركه فارغًا إذا لا تريد تغييره.</small>
                                </div>
                            </div>

                            <div class="d-grid gap-2 d-md-flex justify-content-md-end mt-4">
                                <a href="manage_products.php" class="btn btn-secondary me-md-2">إلغاء</a>
                                <button type="submit" name="update_product" class="btn btn-warning"><i class="fas fa-save"></i> تحديث المنتج</button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php else: ?>
                <?php if (empty($message)) echo "<div class='alert alert-warning text-center'>المنتج المطلوب غير موجود أو رقم المنتج غير صحيح. <a href='manage_products.php'>العودة لقائمة المنتجات</a>.</div>"; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
$conn->close();
require_once BASE_DIR . 'partials/footer.php';
?>