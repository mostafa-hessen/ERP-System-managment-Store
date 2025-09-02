<?php
$page_title = "تقرير المنتجات منخفضة الرصيد";
$active_nav_link = 'low_stock_report'; // لتفعيل الرابط في الـ navbar إذا أضفته
// تحديد المسار لـ config.php بشكل صحيح
if (file_exists(dirname(__DIR__) . '/config.php')) {
    require_once dirname(__DIR__) . '/config.php';
} else {
    // محاولة مسار بديل إذا كان الهيكل مختلفاً قليلاً
    if (file_exists(dirname(dirname(__DIR__)) . '/config.php')) {
         require_once dirname(dirname(__DIR__)) . '/config.php';
    } else {
        die("ملف config.php غير موجود!");
    }
}
require_once BASE_DIR . 'partials/session_admin.php'; // صلاحيات المدير فقط

$low_stock_products = [];
$message = "";

// --- جلب الرسائل من الجلسة (إن وجدت) ---
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    unset($_SESSION['message']);
}

// جلب المنتجات التي رصيدها أقل من أو يساوي حد إعادة الطلب (وفقط إذا كان حد إعادة الطلب > 0)
$sql = "SELECT id, product_code, name, unit_of_measure, current_stock, reorder_level
        FROM products
        WHERE current_stock <= reorder_level AND reorder_level > 0
        ORDER BY (reorder_level - current_stock) DESC, name ASC"; // الأكثر حاجة أولاً

$result_low_stock = $conn->query($sql);

if ($result_low_stock) {
    while ($row = $result_low_stock->fetch_assoc()) {
        $low_stock_products[] = $row;
    }
    if (empty($low_stock_products) && empty($message)) { // لا تعرض هذه الرسالة إذا كان هناك خطأ بالفعل
        $message = "<div class='alert alert-info'><i class='fas fa-check-circle me-2'></i>لا توجد منتجات منخفضة الرصيد حالياً بناءً على حدود إعادة الطلب المحددة.</div>";
    }
} else {
    $message = "<div class='alert alert-danger'>حدث خطأ أثناء جلب بيانات المنتجات منخفضة الرصيد: " . $conn->error . "</div>";
}

require_once BASE_DIR . 'partials/header.php';
require_once BASE_DIR . 'partials/navbar.php';
// رابط صفحة تعديل المنتج
$edit_product_link_base = BASE_URL . "admin/edit_product.php";
?>

<div class="container mt-5 pt-3">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1><i class="fas fa-battery-quarter text-danger me-2"></i> تقرير المنتجات منخفضة الرصيد</h1>
        <a href="<?php echo BASE_URL; ?>admin/manage_products.php" class="btn btn-outline-secondary">
            <i class="fas fa-boxes me-1"></i> العودة لإدارة المنتجات
        </a>
    </div>

    <?php echo $message; ?>

    <?php if (!empty($low_stock_products)): ?>
    <div class="card shadow-sm">
        <div class="card-header bg-light">
            <i class="fas fa-exclamation-triangle text-warning me-1"></i> قائمة المنتجات التي تحتاج لإعادة طلب
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped table-hover table-bordered align-middle">
                    <thead class="table-dark">
                        <tr>
                            <th>#</th>
                            <th>كود المنتج</th>
                            <th>اسم المنتج</th>
                            <th>وحدة القياس</th>
                            <th class="text-center">الرصيد الحالي</th>
                            <th class="text-center">حد إعادة الطلب</th>
                            <th class="text-center">النقص عن الحد</th>
                            <th class="text-center">إجراء</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $counter = 1; ?>
                        <?php foreach($low_stock_products as $product): ?>
                            <?php
                                // للتأكد من أن القيم أرقام عشرية للمعالجة
                                $current_stock_val = floatval($product['current_stock']);
                                $reorder_level_val = floatval($product['reorder_level']);
                                $shortage = $reorder_level_val - $current_stock_val;
                            ?>
                            <tr class="align-middle">
                                <td><?php echo $counter++; ?></td>
                                <td><?php echo htmlspecialchars($product["product_code"]); ?></td>
                                <td><?php echo htmlspecialchars($product["name"]); ?></td>
                                <td><?php echo htmlspecialchars($product["unit_of_measure"]); ?></td>
                                <td class="text-center fw-bold <?php echo ($current_stock_val <= 0 && $reorder_level_val > 0) ? 'text-danger' : ''; ?>">
                                    <?php echo number_format($current_stock_val, 2); ?>
                                </td>
                                <td class="text-center"><?php echo number_format($reorder_level_val, 2); ?></td>
                                <td class="text-center fw-bold text-danger">
                                    <?php echo number_format($shortage, 2); ?>
                                </td>
                                <td class="text-center">
                                    <a href="<?php echo $edit_product_link_base; ?>?id=<?php echo $product['id']; ?>" class="btn btn-warning btn-sm" title="تعديل بيانات المنتج والرصيد">
                                        <i class="fas fa-edit"></i> تعديل
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php
if(isset($conn)) $conn->close();
require_once BASE_DIR . 'partials/footer.php';
?>