<?php
$page_title = "تقرير تقييم المخزون";
$class_dashboard = "active";
require_once dirname(__DIR__) . '/config.php';
require_once BASE_DIR . 'partials/session_admin.php'; // صلاحيات المدير فقط

$message = "";
$valuation_data = []; // لتخزين بيانات التقييم
$grand_total_stock_value = 0; // الإجمالي الكلي لقيمة المخزون

// تم تعديل الإعداد الافتراضي: الاعتماد دائماً على سعر المنتج من جدول المنتجات
$price_source = 'product';


// --- بناء الاستعلام الرئيسي لجلب بيانات تقييم المخزون ---
// في حالتين (أو ثلاث): نكوّن SQL مختلفًا اعتمادًا على مصدر السعر
if ($price_source === 'product') {
    // هنا نفترض وجود عمود cost_price في جدول products يمثل سعر التكلفة الافتراضي
    $sql = "SELECT
                p.id,
                p.product_code,
                p.name AS product_name,
                p.unit_of_measure,
                p.current_stock,
                COALESCE(p.cost_price, 0.00) AS cost_price_used,
                (p.current_stock * COALESCE(p.cost_price, 0.00)) AS stock_value
            FROM
                products p
            ORDER BY
                p.name ASC;";
} else if ($price_source === 'prefer_product') {
    // نفضل سعر المنتج إذا كان مُعرّفًا وإلا نستخدم آخر سعر شراء
    $sql = "SELECT
                p.id,
                p.product_code,
                p.name AS product_name,
                p.unit_of_measure,
                p.current_stock,
                COALESCE(p.cost_price, last_purchase.cost_price_per_unit, 0.00) AS cost_price_used,
                (p.current_stock * COALESCE(p.cost_price, last_purchase.cost_price_per_unit, 0.00)) AS stock_value
            FROM
                products p
            LEFT JOIN
                (SELECT
                     pii.product_id,
                     pii.cost_price_per_unit
                 FROM purchase_invoice_items pii
                 INNER JOIN (
                     SELECT product_id, MAX(id) as max_pii_id
                     FROM purchase_invoice_items
                     GROUP BY product_id
                 ) latest_pii ON pii.id = latest_pii.max_pii_id
                ) AS last_purchase ON p.id = last_purchase.product_id
            ORDER BY
                p.name ASC;";
} else {
    // default: last_purchase
    $sql = "SELECT
                p.id,
                p.product_code,
                p.name AS product_name,
                p.unit_of_measure,
                p.current_stock,
                COALESCE(last_purchase.cost_price_per_unit, 0.00) AS cost_price_used,
                (p.current_stock * COALESCE(last_purchase.cost_price_per_unit, 0.00)) AS stock_value
            FROM
                products p
            LEFT JOIN
                (SELECT
                     pii.product_id,
                     pii.cost_price_per_unit
                 FROM purchase_invoice_items pii
                 INNER JOIN (
                     SELECT product_id, MAX(id) as max_pii_id
                     FROM purchase_invoice_items
                     GROUP BY product_id
                 ) latest_pii ON pii.id = latest_pii.max_pii_id
                ) AS last_purchase ON p.id = last_purchase.product_id
            ORDER BY
                p.name ASC;";
}

$result_valuation_report = $conn->query($sql);

if (!$result_valuation_report) {
    $message = "<div class='alert alert-danger'>حدث خطأ أثناء جلب بيانات تقييم المخزون: " . htmlspecialchars($conn->error) . "</div>";
} else {
    while ($row = $result_valuation_report->fetch_assoc()) {
        // لضمان وجود قيم عددية صحيحة
        $row['current_stock'] = floatval($row['current_stock']);
        $row['cost_price_used'] = floatval($row['cost_price_used']);
        $row['stock_value'] = floatval($row['stock_value']);

        $valuation_data[] = $row;
        $grand_total_stock_value += $row['stock_value'];
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

    <div class="card shadow mb-3">
        <div class="card-body">
            <div class="alert alert-info mb-0">التقرير الآن يحسب قيمة المخزون اعتمادًا (سعر التكلفة الخاص بالمنتج).</div>
        </div>
    </div>

    <div class="card shadow">
        <div class="card-header">
            <!-- تقييم المخزون الحالي بناءً على
            <?php
                if ($price_source === 'product') echo 'سعر من جدول المنتجات (p.cost_price)';
                else if ($price_source === 'prefer_product') echo 'سعر المنتج إن وجد وإلا آخر سعر شراء';
                else echo 'آخر سعر شراء';
            ?> -->
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
                            <th class="text-center">الكميه الموجوده </th>
                            <th class="text-end">سعر التكلفة المستخدم</th>
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
                                    <td class="text-center"><?php echo number_format($product_valuation['current_stock'], 2); ?></td>
                                    <td class="text-end"><?php echo number_format($product_valuation['cost_price_used'], 2); ?> ج.م</td>
                                    <td class="text-end fw-bold"><?php echo number_format($product_valuation['stock_value'], 2); ?> ج.م</td>
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
            <small class="text-muted">هذا التقرير يحسب قيمة المخزون باستخدام سعر المنتج المسجَّل في النظام. تأكد من أن سعر المنتج مُحدَّث داخل صفحة إدارة المنتجات ليعكس التكلفة الحقيقية.</small>
        </div>
    </div>
</div>

<?php
$conn->close();
require_once BASE_DIR . 'partials/footer.php';
?>
