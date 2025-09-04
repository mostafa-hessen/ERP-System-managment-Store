<?php
$page_title = "تقرير حركة وأرصدة المخزون";
$class_dashboard = "active";
require_once dirname(__DIR__) . '/config.php';
require_once BASE_DIR . 'partials/session_admin.php';

$message = "";
$stock_data = [];

// --- جلب الرسائل من الجلسة ---
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    unset($_SESSION['message']);
}
// --- جلب توكن CSRF (إذا احتجنا لنماذج إجراءات في هذه الصفحة لاحقاً) ---
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];


$sql = "SELECT
            p.id,
            p.product_code,
            p.name AS product_name,
            p.unit_of_measure,
            p.current_stock AS actual_current_stock,
            COALESCE(incoming.total_quantity, 0) AS total_incoming,
            COALESCE(outgoing.total_quantity, 0) AS total_outgoing
        FROM
            products p
        LEFT JOIN
            (SELECT product_id, SUM(quantity) AS total_quantity
             FROM purchase_invoice_items
             -- JOIN purchase_invoices pi_h ON purchase_invoice_items.purchase_invoice_id = pi_h.id WHERE pi_h.status = 'fully_received' -- أو الحالة التي تعتبرها وارد فعلي
             GROUP BY product_id) AS incoming ON p.id = incoming.product_id
        LEFT JOIN
            (SELECT soi.product_id, SUM(soi.quantity) AS total_quantity
             FROM invoice_out_items soi
             JOIN invoices_out io ON soi.invoice_out_id = io.id
             WHERE io.delivered = 'yes'
             GROUP BY soi.product_id) AS outgoing ON p.id = outgoing.product_id
        ORDER BY
            p.name ASC;";

$result_stock_report = $conn->query($sql);

if (!$result_stock_report) {
    $message = "<div class='alert alert-danger'>حدث خطأ أثناء جلب بيانات المخزون: " . $conn->error . "</div>";
} else {
    while ($row = $result_stock_report->fetch_assoc()) {
        $stock_data[] = $row;
    }
}

require_once BASE_DIR . 'partials/header.php';
require_once BASE_DIR . 'partials/sidebar.php';
$edit_product_link_base = BASE_URL . "admin/edit_product.php"; // رابط صفحة تعديل المنتج
?>

<div class="container mt-5 pt-3">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1><i class="fas fa-chart-line"></i> تقرير حركة وأرصدة المخزون</h1>
    </div>

    <?php echo $message; ?>

    <div class="card shadow">
        <div class="card-header">
            ملخص أرصدة المنتجات
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped table-hover table-bordered align-middle">
                    <thead class="table-dark">
                        <tr>
                            <th>#</th>
                            <th class="d-none d-md-table-cell">كود المنتج</th>
                            <th>اسم المنتج</th>
                            <th class="d-none d-md-table-cell">وحدة القياس</th>
                            <th class="text-center">إجمالي الوارد</th>
                            <th class="text-center">إجمالي الصادر</th>
                            <th class="text-center">الرصيد المحسوب</th>
                            <th class="text-center d-none d-md-table-cell">الرصيد الفعلي</th>
                            <th class="text-center d-none d-md-table-cell">الفرق</th>
                            <th class="text-center">إجراء</th> </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($stock_data)): ?>
                            <?php $counter = 1; ?>
                            <?php foreach($stock_data as $product_stock): ?>
                                <?php
                                    $calculated_stock = floatval($product_stock['total_incoming']) - floatval($product_stock['total_outgoing']);
                                    $actual_stock = floatval($product_stock['actual_current_stock']);
                                    $difference = $actual_stock - $calculated_stock;
                                    $row_class = '';
                                    if (abs($difference) > 0.001) {
                                        $row_class = 'table-danger';
                                    } elseif ($actual_stock < 0) {
                                        $row_class = 'table-warning';
                                    }
                                ?>
                                <tr class="<?php echo $row_class; ?>">
                                    <td><?php echo $counter++; ?></td>
                                    <td class="d-none d-md-table-cell"><?php echo htmlspecialchars($product_stock["product_code"]); ?></td>
                                    <td><?php echo htmlspecialchars($product_stock["product_name"]); ?></td>
                                    <td class="d-none d-md-table-cell"><?php echo htmlspecialchars($product_stock["unit_of_measure"]); ?></td>
                                    <td class="text-center text-success fw-bold"><?php echo number_format(floatval($product_stock['total_incoming']), 2); ?></td>
                                    <td class="text-center text-danger fw-bold"><?php echo number_format(floatval($product_stock['total_outgoing']), 2); ?></td>
                                    <td class="text-center fw-bold"><?php echo number_format($calculated_stock, 2); ?></td>
                                    <td class="text-center fw-bolder d-none d-md-table-cell <?php if($actual_stock < 0) echo 'text-danger'; elseif ($actual_stock == 0 && $calculated_stock !=0 && abs($difference) > 0.001) echo 'text-warning'; else echo 'text-primary'; ?>">
                                        <?php echo number_format($actual_stock, 2); ?>
                                    </td>
                                    <td class="text-center fw-bolder d-none d-md-table-cell">
                                        <?php echo number_format($difference, 2); ?>
                                    </td>
                                    <td class="text-center">
                                        <form action="<?php echo $edit_product_link_base; ?>" method="post" class="d-inline">
                                            <input type="hidden" name="product_id_to_edit" value="<?php echo $product_stock['id']; ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; // تأكد من توفر هذا المتغير ?>">
                                            <button type="submit" class="btn btn-warning btn-sm" title="تعديل بيانات المنتج والرصيد الفعلي">
                                                <i class="fas fa-edit"></i> تعديل
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="10" class="text-center">لا توجد بيانات منتجات لعرضها.</td> </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer">
             <small class="text-muted">
                * **إجمالي الوارد:** مجموع الكميات من فواتير المشتريات.<br>
                * **إجمالي الصادر:** مجموع الكميات من الفواتير الصادرة المسلمة.<br>
                * **الرصيد المحسوب:** (الوارد - الصادر).<br>
                * **الرصيد الفعلي:** الرصيد المسجل في جدول المنتجات.<br>
                * **الفرق:** إذا كان غير صفر، فهذا يشير لحاجة لمراجعة أو تسوية. الصفوف المميزة تشير لوجود فرق أو رصيد فعلي سالب.
            </small>
        </div>
    </div>
</div>

<?php
$conn->close();
require_once BASE_DIR . 'partials/footer.php';
?>