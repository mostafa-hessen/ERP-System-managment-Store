<?php
$page_title = "إدارة المنتجات";
$class_dashboard = "active";
require_once dirname(__DIR__) . '/config.php';
require_once BASE_DIR . 'partials/session_admin.php';
require_once BASE_DIR . 'partials/header.php';
require_once BASE_DIR . 'partials/navbar.php';

$message = "";

// --- جلب الرسائل من الجلسة (بعد الإضافة أو التعديل أو الحذف) ---
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    unset($_SESSION['message']);
}

// جلب توكن CSRF (لنماذج الحذف)
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// --- معالجة الحذف ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_product'])) {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['message'] = "<div class='alert alert-danger'>خطأ: طلب غير صالح.</div>";
    } else {
        $product_id_to_delete = intval($_POST['product_id_to_delete']);
        //  !! تنبيه: قبل الحذف، يجب التحقق مما إذا كان المنتج مستخدماً في فواتير (invoice_out_items) !!
        //  إذا كان كذلك، يجب إما منع الحذف أو التعامل معه (مثل الحذف الناعم أو إزالة الارتباط)
        //  للتبسيط الآن، سنقوم بالحذف المباشر.

        $sql_delete = "DELETE FROM products WHERE id = ?";
        if ($stmt_delete = $conn->prepare($sql_delete)) {
            $stmt_delete->bind_param("i", $product_id_to_delete);
            if ($stmt_delete->execute()) {
                if ($stmt_delete->affected_rows > 0) {
                    $_SESSION['message'] = "<div class='alert alert-success'>تم حذف المنتج بنجاح.</div>";
                } else {
                    $_SESSION['message'] = "<div class='alert alert-warning'>لم يتم العثور على المنتج أو لم يتم حذفه.</div>";
                }
            } else {
                // قد يكون الخطأ بسبب قيود المفتاح الأجنبي إذا كان المنتج مستخدماً
                $_SESSION['message'] = "<div class='alert alert-danger'>حدث خطأ أثناء حذف المنتج: " . $stmt_delete->error . ". قد يكون المنتج مستخدماً في سجلات أخرى.</div>";
            }
            $stmt_delete->close();
        } else {
            $_SESSION['message'] = "<div class='alert alert-danger'>خطأ في تحضير استعلام الحذف: " . $conn->error . "</div>";
        }
    }
    header("Location: manage_products.php"); // إعادة تحميل الصفحة لعرض الرسالة وتحديث القائمة
    exit;
}


// --- جلب كل المنتجات ---
$sql_select_products = "SELECT id, product_code, name, unit_of_measure, current_stock, created_at,reorder_level,cost_price , selling_price FROM products ORDER BY id DESC";
$result_products = $conn->query($sql_select_products);


?>

<div class="container mt-5 pt-3">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1><i class="fas fa-boxes"></i> إدارة المنتجات</h1>
        <a href="add_product.php" class="btn btn-success"><i class="fas fa-plus-circle"></i> إضافة منتج جديد</a>
    </div>

    <?php echo $message; ?>

    <div class="card shadow">
        <div class="card-header">
            قائمة المنتجات المسجلة
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped table-hover table-bordered align-middle">
                    <thead class="table-dark">
                        <tr>
                            <th>#</th>
                            <th>كود المنتج</th>
                            <th>اسم المنتج</th>
                            <th class="d-none d-md-table-cell">وحدة القياس</th>
                            <th> لرصيد الحالي</th>
                            <th class="text-center">حد اعاده الطلب</th>
                            <th class="d-none d-md-table-cell">تاريخ الإضافة</th>
                            <th>سعر الشراء</th>
                            <th>سعر البيع</th>

                            <th class="text-center">إجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result_products && $result_products->num_rows > 0): ?>
                            <?php while ($product = $result_products->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo $product["id"]; ?></td>
                                    <td><?php echo htmlspecialchars($product["product_code"]); ?></td>
                                    <!-- <td><?php echo htmlspecialchars(is_numeric($product['reorder_level']) ? number_format(floatval($product['reorder_level']), 2, '.', '') : $product['reorder_level']); ?></td> -->
                                    <td><?php echo htmlspecialchars($product["name"]); ?></td>
                                    <td class="d-none d-md-table-cell"><?php echo htmlspecialchars($product["unit_of_measure"]); ?></td>
                                    <td class="text-center"><?php echo $product["current_stock"]; ?></td>
                                    <td class="text-center"><?php echo $product["reorder_level"]; ?></td>
                                    <td class="d-none d-md-table-cell"><?php echo date('Y-m-d', strtotime($product["created_at"])); ?></td>
                                    <td><?php echo $product["cost_price"]; ?></td>
                                    <td><?php echo $product["selling_price"]; ?></td>
                                    <td class="text-center">
                                        <form action="edit_product.php" method="post" class="d-inline">
                                            <input type="hidden" name="product_id_to_edit" value="<?php echo $product["id"]; ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                            <button type="submit" class="btn btn-warning btn-sm" title="تعديل المنتج">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                        </form>
                                        <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post" class="d-inline ms-1">
                                            <input type="hidden" name="product_id_to_delete" value="<?php echo $product["id"]; ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                            <button type="submit" name="delete_product" class="btn btn-danger btn-sm"
                                                onclick="return confirm('هل أنت متأكد من حذف هذا المنتج؟ لا يمكن التراجع عن هذا الإجراء.');"
                                                title="حذف المنتج">
                                                <i class="fas fa-trash-alt"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7" class="text-center">لا توجد منتجات مسجلة حالياً. <a href="add_product.php">أضف منتجاً الآن!</a></td>
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
// المسارات تحتاج لتعديل لأننا داخل مجلد admin
require_once BASE_DIR . 'partials/footer.php';
?>