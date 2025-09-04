<?php
$page_title = "تقرير تقييم المخزون";
$class_dashboard = "active";
require_once dirname(__DIR__) . '/config.php';
require_once BASE_DIR . 'partials/session_admin.php'; // صلاحيات المدير فقط

$message = "";
$valuation_data = []; // لتخزين بيانات التقييم
$grand_total_stock_value = 0; // الإجمالي الكلي لقيمة المخزون

// --- بناء الاستعلام الرئيسي لجلب بيانات تقييم المخزون ---
// هذا الاستعلام يجلب كل منتج، رصيده الحالي، وآخر سعر شراء له
$sql = "SELECT
            p.id,
            p.product_code,
            p.name AS product_name,
            p.unit_of_measure,
            p.current_stock,
            COALESCE(last_purchase.cost_price_per_unit, 0.00) AS last_cost_price,
            (p.current_stock * COALESCE(last_purchase.cost_price_per_unit, 0.00)) AS stock_value
        FROM
            products p
        LEFT JOIN
            (SELECT
                 pii.product_id,
                 pii.cost_price_per_unit
             FROM purchase_invoice_items pii
             INNER JOIN (
                 SELECT product_id, MAX(id) as max_pii_id -- نفترض أن الأعلى ID هو الأحدث
                 FROM purchase_invoice_items
                 GROUP BY product_id
             ) latest_pii ON pii.id = latest_pii.max_pii_id
            ) AS last_purchase ON p.id = last_purchase.product_id
        ORDER BY
            p.name ASC;";

$result_valuation_report = $conn->query($sql);

if (!$result_valuation_report) {
    $message = "<div class='alert alert-danger'>حدث خطأ أثناء جلب بيانات تقييم المخزون: " . $conn->error . "</div>";
} else {
    while ($row = $result_valuation_report->fetch_assoc()) {
        $valuation_data[] = $row;
        $grand_total_stock_value += floatval($row['stock_value']);
    }
}

require_once BASE_DIR . 'partials/header.php';
require_once BASE_DIR . 'partials/sidebar.php';
?>

<div class="container mt-5 pt-3">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1><i class="fas fa-calculator"></i> تقرير تقييم المخزون</h1>
        </div>

    <?php echo $message; ?>

    <div class="card shadow">
        <div class="card-header">
            تقييم المخزون الحالي بناءً على آخر سعر شراء
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
                            <th class="text-end">آخر سعر تكلفة (شراء)</th>
                            <th class="text-end">القيمة الإجمالية للمخزون</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($valuation_data)): ?>
                            <?php $counter = 1; ?>
                            <?php foreach($valuation_data as $product_valuation): ?>
                                <tr>
                                    <td><?php echo $counter++; ?></td>
                                    <td><?php echo htmlspecialchars($product_valuation["product_code"]); ?></td>
                                    <td><?php echo htmlspecialchars($product_valuation["product_name"]); ?></td>
                                    <td><?php echo htmlspecialchars($product_valuation["unit_of_measure"]); ?></td>
                                    <td class="text-center"><?php echo number_format(floatval($product_valuation['current_stock']), 2); ?></td>
                                    <td class="text-end"><?php echo number_format(floatval($product_valuation['last_cost_price']), 2); ?> ج.م</td>
                                    <td class="text-end fw-bold"><?php echo number_format(floatval($product_valuation['stock_value']), 2); ?> ج.م</td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7" class="text-center">لا توجد بيانات منتجات لعرضها.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                    <?php if (!empty($valuation_data)): ?>
                    <tfoot class="table-light">
                        <tr>
                            <td colspan="6" class="text-end fw-bolder fs-5">الإجمالي الكلي لقيمة المخزون:</td>
                            <td class="text-end fw-bolder fs-5"><?php echo number_format($grand_total_stock_value, 2); ?> ج.م</td>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
        <div class="card-footer">
            <small class="text-muted">
                * يتم حساب "آخر سعر تكلفة" بناءً على أحدث سجل شراء للمنتج في فواتير المشتريات.
                * المنتجات التي ليس لها سجلات شراء ستظهر بتكلفة صفر وقيمة صفر.
            </small>
        </div>
    </div>
</div>

<?php
$conn->close();
require_once BASE_DIR . 'partials/footer.php';
?>