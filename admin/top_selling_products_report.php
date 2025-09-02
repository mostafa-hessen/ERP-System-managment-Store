<?php
$page_title = "تقرير المنتجات الأكثر مبيعاً";
// $class_reports_active = "active"; // لـ navbar
require_once dirname(__DIR__) . '/config.php';
require_once BASE_DIR . 'partials/session_admin.php'; // صلاحيات المدير فقط

$message = "";
$top_products_data = []; // لتخزين بيانات المنتجات للتقرير

// القيم الافتراضية للتواريخ (الشهر الحالي)
$start_date_filter = isset($_GET['start_date']) ? trim($_GET['start_date']) : date('Y-m-01');
$end_date_filter = isset($_GET['end_date']) ? trim($_GET['end_date']) : date('Y-m-t');

$report_generated = false;

// --- معالجة طلب عرض التقرير ---
if (!empty($start_date_filter) && !empty($end_date_filter)) {
    if (DateTime::createFromFormat('Y-m-d', $start_date_filter) === false || DateTime::createFromFormat('Y-m-d', $end_date_filter) === false) {
        $message = "<div class='alert alert-danger'>صيغة التاريخ غير صحيحة. يرجى استخدام YYYY-MM-DD.</div>";
    } elseif ($start_date_filter > $end_date_filter) {
        $message = "<div class='alert alert-danger'>تاريخ البدء لا يمكن أن يكون بعد تاريخ الانتهاء.</div>";
    } else {
        $report_generated = true;
        $start_date_sql = $start_date_filter . " 00:00:00";
        $end_date_sql = $end_date_filter . " 23:59:59";

        $sql = "SELECT
                    p.id AS product_id,
                    p.product_code,
                    p.name AS product_name,
                    p.unit_of_measure,
                    SUM(ioi.quantity) AS total_quantity_sold
                FROM
                    products p
                JOIN
                    invoice_out_items ioi ON p.id = ioi.product_id
                JOIN
                    invoices_out io ON ioi.invoice_out_id = io.id
                WHERE
                    io.delivered = 'yes'
                    AND io.created_at BETWEEN ? AND ?
                GROUP BY
                    p.id, p.product_code, p.name, p.unit_of_measure
                ORDER BY
                    total_quantity_sold DESC, p.name ASC"; // ترتيب حسب الكمية ثم الاسم

        if ($stmt = $conn->prepare($sql)) {
            $stmt->bind_param("ss", $start_date_sql, $end_date_sql);
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $top_products_data[] = $row;
                }
                if (empty($top_products_data) && empty($message)) {
                    $message = "<div class='alert alert-info'>لا توجد بيانات مبيعات للمنتجات خلال الفترة المحددة.</div>";
                }
            } else {
                $message = "<div class='alert alert-danger'>حدث خطأ أثناء تنفيذ استعلام التقرير: " . $stmt->error . "</div>";
            }
            $stmt->close();
        } else {
            $message = "<div class='alert alert-danger'>خطأ في تحضير استعلام التقرير: " . $conn->error . "</div>";
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET' && (isset($_GET['start_date']) || isset($_GET['end_date']))) {
    if (empty($start_date_filter) || empty($end_date_filter)) {
        $message = "<div class='alert alert-warning'>الرجاء تحديد تاريخ البدء وتاريخ الانتهاء لعرض التقرير.</div>";
    }
}

require_once BASE_DIR . 'partials/header.php';
require_once BASE_DIR . 'partials/navbar.php';
?>

<div class="container mt-5 pt-3">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1><i class="fas fa-award"></i> تقرير المنتجات الأكثر مبيعاً</h1>
        </div>

    <?php echo $message; ?>

    <div class="card mb-4 shadow-sm">
        <div class="card-header">
            تحديد فترة التقرير
        </div>
        <div class="card-body">
            <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="get" class="row gx-3 gy-2 align-items-end">
                <div class="col-md-5">
                    <label for="start_date" class="form-label">من تاريخ:</label>
                    <input type="date" class="form-control" id="start_date" name="start_date" value="<?php echo htmlspecialchars($start_date_filter); ?>" required>
                </div>
                <div class="col-md-5">
                    <label for="end_date" class="form-label">إلى تاريخ:</label>
                    <input type="date" class="form-control" id="end_date" name="end_date" value="<?php echo htmlspecialchars($end_date_filter); ?>" required>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100"><i class="fas fa-eye"></i> عرض التقرير</button>
                </div>
            </form>
        </div>
    </div>

    <?php if ($report_generated && !empty($top_products_data)): ?>
    <div class="card shadow">
        <div class="card-header">
            المنتجات الأكثر مبيعاً للفترة من: <strong><?php echo htmlspecialchars($start_date_filter); ?></strong> إلى: <strong><?php echo htmlspecialchars($end_date_filter); ?></strong>
            (مرتبة حسب إجمالي الكمية المباعة)
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
                            <th class="text-center">إجمالي الكمية المباعة</th>
                            </tr>
                    </thead>
                    <tbody>
                        <?php $counter = 1; ?>
                        <?php foreach($top_products_data as $product_sale): ?>
                            <tr>
                                <td><?php echo $counter++; ?></td>
                                <td><?php echo htmlspecialchars($product_sale["product_code"]); ?></td>
                                <td><?php echo htmlspecialchars($product_sale["product_name"]); ?></td>
                                <td><?php echo htmlspecialchars($product_sale["unit_of_measure"]); ?></td>
                                <td class="text-center fw-bold"><?php echo number_format(floatval($product_sale['total_quantity_sold']), 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php elseif ($report_generated && empty($top_products_data) && empty($message)): ?>
        <div class="alert alert-info">لا توجد بيانات مبيعات للمنتجات خلال الفترة المحددة.</div>
    <?php endif; ?>

</div>

<?php
$conn->close();
require_once BASE_DIR . 'partials/footer.php';
?>