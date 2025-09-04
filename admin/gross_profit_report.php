<?php
$page_title = "تقرير إجمالي الربح";
// $class_reports_active = "active"; // لـ navbar
require_once dirname(__DIR__) . '/config.php';
require_once BASE_DIR . 'partials/session_admin.php'; // صلاحيات المدير فقط

$message = "";
$sales_data = []; // لتخزين تفاصيل الفواتير إذا أردنا عرضها
$total_revenue = 0;
$total_cogs = 0;
$gross_profit = 0;

// القيم الافتراضية للتواريخ (يمكن جعلها الشهر الحالي مثلاً)
$start_date_filter = isset($_GET['start_date']) ? trim($_GET['start_date']) : date('Y-m-01'); // بداية الشهر الحالي كافتراضي
$end_date_filter = isset($_GET['end_date']) ? trim($_GET['end_date']) : date('Y-m-t'); // نهاية الشهر الحالي كافتراضي

$report_generated = false; // لتحديد ما إذا كان يجب عرض قسم النتائج

// --- معالجة طلب عرض التقرير ---
// يتم عرض التقرير دائماً عند تحميل الصفحة إذا كانت التواريخ موجودة (حتى لو افتراضية)
// أو عند الضغط على زر "عرض التقرير"
if (!empty($start_date_filter) && !empty($end_date_filter)) {
    if (DateTime::createFromFormat('Y-m-d', $start_date_filter) === false || DateTime::createFromFormat('Y-m-d', $end_date_filter) === false) {
        $message = "<div class='alert alert-danger'>صيغة التاريخ غير صحيحة. يرجى استخدام YYYY-MM-DD.</div>";
    } elseif ($start_date_filter > $end_date_filter) {
        $message = "<div class='alert alert-danger'>تاريخ البدء لا يمكن أن يكون بعد تاريخ الانتهاء.</div>";
    } else {
        $report_generated = true;
        $start_date_sql = $start_date_filter . " 00:00:00";
        $end_date_sql = $end_date_filter . " 23:59:59";

        // 1. حساب إجمالي الإيرادات
        $sql_revenue = "SELECT SUM(ioi.total_price) AS total_revenue
                        FROM invoice_out_items ioi
                        JOIN invoices_out io ON ioi.invoice_out_id = io.id
                        WHERE io.delivered = 'yes'
                        AND io.created_at BETWEEN ? AND ?";
        if ($stmt_revenue = $conn->prepare($sql_revenue)) {
            $stmt_revenue->bind_param("ss", $start_date_sql, $end_date_sql);
            if ($stmt_revenue->execute()) {
                $result_revenue = $stmt_revenue->get_result();
                if ($row_revenue = $result_revenue->fetch_assoc()) {
                    $total_revenue = floatval($row_revenue['total_revenue'] ?? 0);
                }
            } else { $message .= "<div class='alert alert-danger'>خطأ في حساب الإيرادات: " . $stmt_revenue->error . "</div>"; }
            $stmt_revenue->close();
        } else { $message .= "<div class='alert alert-danger'>خطأ في تحضير استعلام الإيرادات: " . $conn->error . "</div>"; }

        // 2. حساب إجمالي تكلفة البضاعة المباعة (COGS)
        // سنستخدم استعلاماً فرعياً لجلب آخر سعر شراء لكل منتج تم بيعه
        // $sql_cogs = "SELECT SUM(sold_items.quantity * COALESCE(last_costs.cost_price_per_unit, 0)) AS total_cogs
        //              FROM (
        //                  SELECT ioi.product_id, ioi.quantity
        //                  FROM invoice_out_items ioi
        //                  JOIN invoices_out io ON ioi.invoice_out_id = io.id
        //                  WHERE io.delivered = 'yes' AND io.created_at BETWEEN ? AND ?
        //              ) AS sold_items
        //              LEFT JOIN (
        //                  SELECT pii.product_id, pii.cost_price_per_unit
        //                  FROM purchase_invoice_items pii
        //                  INNER JOIN (
        //                      SELECT product_id, MAX(id) as max_pii_id
        //                      FROM purchase_invoice_items
        //                      GROUP BY product_id
        //                  ) latest_pii ON pii.id = latest_pii.max_pii_id
        //              ) AS last_costs ON sold_items.product_id = last_costs.product_id";

        //  التعديل اللي بنجربه عشان نحل المشكله بتاع الارباح
        $sql_cogs = "
SELECT SUM(ioi.quantity * COALESCE(pii.cost_price_per_unit, 0)) AS total_cogs
FROM invoice_out_items ioi
JOIN invoices_out io ON ioi.invoice_out_id = io.id AND io.delivered = 'yes'
LEFT JOIN (
    SELECT p1.product_id, p1.cost_price_per_unit
    FROM purchase_invoice_items p1
    INNER JOIN (
        SELECT product_id, MAX(id) AS max_id
        FROM purchase_invoice_items
        GROUP BY product_id
    ) p2 ON p1.id = p2.max_id
) pii ON ioi.product_id = pii.product_id
WHERE io.created_at BETWEEN ? AND ?
";


      if ($stmt_cogs = $conn->prepare($sql_cogs)) {
    $stmt_cogs->bind_param("ss", $start_date_sql, $end_date_sql);
    if ($stmt_cogs->execute()) {
        $result_cogs = $stmt_cogs->get_result();
        if ($row_cogs = $result_cogs->fetch_assoc()) {
            $total_cogs = floatval($row_cogs['total_cogs'] ?? 0);
        }
    } else { $message .= "<div class='alert alert-danger'>خطأ في حساب تكلفة البضاعة المباعة: " . $stmt_cogs->error . "</div>"; }
    $stmt_cogs->close();
} else { $message .= "<div class='alert alert-danger'>خطأ في تحضير استعلام تكلفة البضاعة المباعة: " . $conn->error . "</div>"; }

        // 3. حساب إجمالي الربح
        $gross_profit = $total_revenue - $total_cogs;

        // (اختياري) جلب تفاصيل الفواتير للفترة إذا أردت عرضها
        // $sql_invoices_details = "SELECT ... FROM invoices_out ... WHERE ...";
        // ...
    }
}


require_once BASE_DIR . 'partials/header.php';
require_once BASE_DIR . 'partials/navbar.php';
?>

<div class="container mt-5 pt-3">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1><i class="fas fa-funnel-dollar"></i> تقرير إجمالي الربح</h1>
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

    <?php if ($report_generated && empty($message) ): // اعرض النتائج فقط إذا تم إنشاء التقرير بنجاح ?>
    <div class="card shadow">
        <div class="card-header bg-success text-white">
            نتائج التقرير للفترة من: <strong><?php echo htmlspecialchars($start_date_filter); ?></strong> إلى: <strong><?php echo htmlspecialchars($end_date_filter); ?></strong>
        </div>
        <div class="card-body">
            <div class="row text-center">
                <div class="col-md-4 mb-3">
                    <div class="stat-card p-3 border rounded bg-light shadow-sm">
                        <h5 class="text-primary">إجمالي الإيرادات (المبيعات)</h5>
                        <h2 class="fw-bold"><?php echo number_format($total_revenue, 2); ?> ج.م</h2>
                    </div>
                </div>
                <div class="col-md-4 mb-3">
                    <div class="stat-card p-3 border rounded bg-light shadow-sm">
                        <h5 class="text-danger">إجمالي تكلفة البضاعة المباعة</h5>
                        <h2 class="fw-bold"><?php echo number_format($total_cogs, 2); ?> ج.م</h2>
                    </div>
                </div>
                <div class="col-md-4 mb-3">
                    <div class="stat-card p-3 border rounded bg-light shadow-sm">
                        <h5 class="text-success">إجمالي الربح</h5>
                        <h2 class="fw-bold"><?php echo number_format($gross_profit, 2); ?> ج.م</h2>
                        <?php if($total_revenue > 0): ?>
                            <p class="mb-0 text-muted">(بنسبة <?php echo number_format(($gross_profit / $total_revenue) * 100, 1); ?>%)</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <hr>
            <p class="text-muted small">
                * الإيرادات محسوبة من إجمالي أسعار البيع في الفواتير الصادرة المسلمة خلال الفترة.<br>
                * تكلفة البضاعة المباعة محسوبة بناءً على آخر سعر شراء مسجل لكل منتج تم بيعه.<br>
                * إذا كان منتج مباع ليس له سجل شراء، فسيتم اعتبار تكلفته صفراً لهذا التقرير.
            </p>
            <?php /*
            // يمكنك إضافة جدول هنا لعرض تفاصيل الفواتير التي ساهمت في هذا الربح
            if (!empty($sales_data)) {
                // ... كود عرض جدول الفواتير ...
            }
            */ ?>
        </div>
    </div>
    <?php elseif ($report_generated && !empty($message) && strpos($message, 'alert-danger') === false && strpos($message, 'alert-warning') === false): ?>
        <?php // هذا الشرط لعرض رسالة "لا توجد فواتير" إذا لم تكن هناك أخطاء أخرى ?>
        <div class="alert alert-info">لا توجد فواتير مبيعات مسلمة خلال الفترة المحددة.</div>
    <?php endif; ?>

</div>
<style>
    .stat-card h5 { font-size: 1rem; margin-bottom: 0.5rem; }
    .stat-card h2 { font-size: 2rem; }
</style>
<?php
$conn->close();
require_once BASE_DIR . 'partials/footer.php';
?>