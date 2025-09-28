<?php
$page_title = "تقرير المنتجات منخفضة الرصيد";
$active_nav_link = 'low_stock_report';
if (file_exists(dirname(__DIR__) . '/config.php')) {
    require_once dirname(__DIR__) . '/config.php';
} else {
    if (file_exists(dirname(dirname(__DIR__)) . '/config.php')) {
         require_once dirname(dirname(__DIR__)) . '/config.php';
    } else {
        die("ملف config.php غير موجود!");
    }
}
require_once BASE_DIR . 'partials/session_admin.php';

$low_stock_products = [];
$message = "";

if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    unset($_SESSION['message']);
}

/*
  حلّ المشكلة: استخدام استعلام فرعي يجري التجميع داخله ثم نطبق شرط WHERE على الاسم المستعار.
  هذا يتجنب خطأ "Reference 'batches_remaining' not supported".
*/
$sql = "
SELECT *
FROM (
  SELECT
    p.id,
    p.product_code,
    p.name,
    p.unit_of_measure,
    ROUND(GREATEST(IFNULL(SUM(b.remaining), 0), 0), 2) AS batches_remaining,
    ROUND(p.reorder_level, 2) AS reorder_level,
    ROUND(p.current_stock, 2) AS current_stock
  FROM products p
  LEFT JOIN batches b
    ON b.product_id = p.id AND b.status IN ('active','consumed')
  GROUP BY
    p.id, p.product_code, p.name, p.unit_of_measure, p.reorder_level, p.current_stock
) AS t
WHERE t.batches_remaining <= t.reorder_level AND t.reorder_level > 0
ORDER BY (t.reorder_level - t.batches_remaining) DESC, t.name ASC
";


$result_low_stock = $conn->query($sql);

if ($result_low_stock) {
    while ($row = $result_low_stock->fetch_assoc()) {
        $low_stock_products[] = $row;
    }
    if (empty($low_stock_products) && empty($message)) {
        $message = "<div class='alert alert-info'><i class='fas fa-check-circle me-2'></i>لا توجد منتجات منخفضة الرصيد حالياً بناءً على حدود إعادة الطلب المحددة.</div>";
    }
} else {
    $message = "<div class='alert alert-danger'>حدث خطأ أثناء جلب بيانات المنتجات منخفضة الرصيد: " . htmlspecialchars($conn->error) . "</div>";
}

require_once BASE_DIR . 'partials/header.php';
require_once BASE_DIR . 'partials/sidebar.php';
$edit_product_link_base = BASE_URL . "admin/edit_product.php";
?>


<style>
/* wrapper - controls scrolling and border */
.custom-table-wrapper {
  max-height: 60vh;               /* غيّر الارتفاع حسب الحاجة */
  overflow-y: auto;
  overflow-x: auto;
  border: 1px solid var(--border);
  background: var(--surface);
  border-radius: var(--radius-sm);
  box-shadow: var(--shadow-1);
}

/* the table itself */
.custom-table {
  width: 100%;
  border-collapse: collapse;
  font-family: system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial;
  font-size: 0.95rem;
  color: var(--text);
}

/* sticky header */
.custom-table thead th {
  position: sticky;
  top: 0;
  z-index: 5;
  background: var(--surface-2); /* استخدم سطح 2 للترويسة */
  color: var(--text);
  font-weight: 600;
  padding: 12px 14px;
  text-align: left;
  border-bottom: 1px solid var(--border);
}

/* body cells */
.custom-table tbody td {
  padding: 10px 14px;
  border-bottom: 1px dashed rgba(0,0,0,0.04); /* خفيف لفصل الصفوف */
  vertical-align: middle;
}

/* alternate row shading using variables (works in dark too) */
.custom-table tbody tr:nth-child(even) {
  background: linear-gradient(180deg, transparent, transparent); /* keep subtle */
}
.custom-table tbody tr:hover {
  background: rgba(11,132,255,0.04); /* subtle hover using brand color */
}

/* small utilities */
.custom-table th.center,
.custom-table td.center {
  text-align: center;
}

/* responsive: keep columns readable on small screens */
@media (max-width: 768px) {
  .custom-table thead th,
  .custom-table tbody td {
    padding: 10px 8px;
    font-size: 0.88rem;
  }
}

/* If your layout has a fixed header (height controlled by --header-h),
   add class .has-fixed-header to body so sticky headers don't hide under navbar */
body.has-fixed-header .custom-table thead th {
  top: var(--header-h, 64px);
}

/* Accessibility: make header contrast strong in both themes */
.custom-table thead th {
  box-shadow: inset 0 -1px 0 rgba(0,0,0,0.03);
}


</style>
<div class="container mt-5 pt-3">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1><i class="fas fa-battery-quarter text-danger me-2"></i> تقرير المنتجات منخفضة الرصيد</h1>
        <a href="<?php echo BASE_URL; ?>admin/manage_products.php" class="btn btn-outline-secondary">
            <i class="fas fa-boxes me-1"></i> العودة لإدارة المنتجات
        </a>
    </div>

    <?php echo $message; ?>

    <?php if (!empty($low_stock_products)): ?>
    <div class="card shadow-sm  low_stock_card">
        <div class="card-header bg-light">
            <i class="fas fa-exclamation-triangle text-warning me-1"></i> قائمة المنتجات التي تحتاج لإعادة طلب
        </div>
        <div class="card-body">
            <div class="custom-table-wrapper">
                <table class="custom-table">
                    <thead class="table-dark">
                        <tr>
                            <th>#</th>
                            <th>كود المنتج</th>
                            <th>اسم المنتج</th>
                            <th>وحدة القياس</th>
                            <th class="text-center">الرصيد المتبقي (من الدفعات)</th>
                            <th class="text-center">الرصيد القديم </th>
                            <th class="text-center">حد إعادة الطلب</th>
                            <th class="text-center">النقص عن الحد</th>
                            <th class="text-center">إجراء</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $counter = 1; ?>
                        <?php foreach($low_stock_products as $product): ?>
                            <?php
                                $batches_remaining = floatval($product['batches_remaining']);
                                $current_stock_val = floatval($product['current_stock']);
                                $reorder_level_val = floatval($product['reorder_level']);
                                $shortage = $reorder_level_val - $batches_remaining;
                            ?>
                            <tr class="align-middle">
                                <td><?php echo $counter++; ?></td>
                                <td><?php echo htmlspecialchars($product["product_code"]); ?></td>
                                <td><?php echo htmlspecialchars($product["name"]); ?></td>
                                <td><?php echo htmlspecialchars($product["unit_of_measure"]); ?></td>
                                <td class="text-center fw-bold <?php echo ($batches_remaining <= 0 && $reorder_level_val > 0) ? 'text-danger' : ''; ?>">
                                    <?php echo number_format($batches_remaining, 2); ?>
                                </td>
                                <td class="text-center"><?php echo number_format($current_stock_val, 2); ?></td>
                                <td class="text-center"><?php echo number_format($reorder_level_val, 2); ?></td>
                                <td class="text-center fw-bold text-danger">
                                    <?php echo number_format($shortage, 2); ?>
                                </td>
                                <td class="text-center">
                                  
                                    <a target="_blank" href="<?php echo BASE_URL; ?>admin/manage_suppliers.php" class="btn btn-sm btn-outline-primary ms-1" title=" دفعات توريد">
                                        <i class="fas fa-layer-group"></i> توريد
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
