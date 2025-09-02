<?php
$page_title = "تقرير صافي الربح";
// $class_reports_active = "active";
require_once dirname(__DIR__) . '/config.php';
require_once BASE_DIR . 'partials/session_admin.php'; // صلاحيات المدير فقط

$message = "";
$report_data = []; // لتخزين بيانات التقرير التفصيلية إذا أردنا
$total_revenue = 0;
$total_cogs = 0;
$total_expenses = 0; //  إجمالي المصاريف للفترة
$net_profit = 0;

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
        $start_date_sql_datetime = $start_date_filter . " 00:00:00";
        $end_date_sql_datetime = $end_date_filter . " 23:59:59";
        // للـ expenses التي تستخدم DATE
        $start_date_sql_date = $start_date_filter;
        $end_date_sql_date = $end_date_filter;


        // 1. حساب إجمالي الإيرادات
        $sql_revenue = "SELECT SUM(ioi.total_price) AS total_revenue
                        FROM invoice_out_items ioi
                        JOIN invoices_out io ON ioi.invoice_out_id = io.id
                        WHERE io.delivered = 'yes'
                        AND io.created_at BETWEEN ? AND ?";
        if ($stmt_revenue = $conn->prepare($sql_revenue)) {
            $stmt_revenue->bind_param("ss", $start_date_sql_datetime, $end_date_sql_datetime);
            if ($stmt_revenue->execute()) {
                $result_revenue = $stmt_revenue->get_result();
                if ($row_revenue = $result_revenue->fetch_assoc()) {
                    $total_revenue = floatval($row_revenue['total_revenue'] ?? 0);
                }
            } else { $message .= "<div class='alert alert-danger'>خطأ في حساب الإيرادات: " . $stmt_revenue->error . "</div>"; }
            $stmt_revenue->close();
        } else { $message .= "<div class='alert alert-danger'>خطأ في تحضير استعلام الإيرادات: " . $conn->error . "</div>"; }

        // 2. حساب إجمالي تكلفة البضاعة المباعة (COGS)
        $sql_cogs = "SELECT SUM(sold_items.quantity * COALESCE(last_costs.cost_price_per_unit, 0)) AS total_cogs
                     FROM (
                         SELECT ioi.product_id, ioi.quantity
                         FROM invoice_out_items ioi
                         JOIN invoices_out io ON ioi.invoice_out_id = io.id
                         WHERE io.delivered = 'yes' AND io.created_at BETWEEN ? AND ?
                     ) AS sold_items
                     LEFT JOIN (
                         SELECT pii.product_id, pii.cost_price_per_unit
                         FROM purchase_invoice_items pii
                         INNER JOIN (
                             SELECT product_id, MAX(id) as max_pii_id
                             FROM purchase_invoice_items
                             GROUP BY product_id
                         ) latest_pii ON pii.id = latest_pii.max_pii_id
                     ) AS last_costs ON sold_items.product_id = last_costs.product_id";
        if ($stmt_cogs = $conn->prepare($sql_cogs)) {
            $stmt_cogs->bind_param("ss", $start_date_sql_datetime, $end_date_sql_datetime);
            if ($stmt_cogs->execute()) {
                $result_cogs = $stmt_cogs->get_result();
                if ($row_cogs = $result_cogs->fetch_assoc()) {
                    $total_cogs = floatval($row_cogs['total_cogs'] ?? 0);
                }
            } else { $message .= "<div class='alert alert-danger'>خطأ في حساب تكلفة البضاعة المباعة: " . $stmt_cogs->error . "</div>"; }
            $stmt_cogs->close();
        } else { $message .= "<div class='alert alert-danger'>خطأ في تحضير استعلام تكلفة البضاعة المباعة: " . $conn->error . "</div>"; }

        // --- !! 3. حساب إجمالي المصاريف !! ---
        $sql_expenses = "SELECT SUM(amount) AS total_expenses
                         FROM expenses
                         WHERE expense_date BETWEEN ? AND ?";
        if ($stmt_expenses = $conn->prepare($sql_expenses)) {
            $stmt_expenses->bind_param("ss", $start_date_sql_date, $end_date_sql_date);
            if ($stmt_expenses->execute()) {
                $result_total_expenses = $stmt_expenses->get_result();
                if ($row_total_expenses = $result_total_expenses->fetch_assoc()) {
                    $total_expenses = floatval($row_total_expenses['total_expenses'] ?? 0);
                }
            } else { $message .= "<div class='alert alert-danger'>خطأ في حساب إجمالي المصاريف: " . $stmt_expenses->error . "</div>"; }
            $stmt_expenses->close();
        } else { $message .= "<div class='alert alert-danger'>خطأ في تحضير استعلام المصاريف: " . $conn->error . "</div>"; }
        // --- !! نهاية حساب المصاريف !! ---

        // 4. حساب صافي الربح
        $gross_profit_for_period = $total_revenue - $total_cogs; // إجمالي الربح للفترة
        $net_profit = $gross_profit_for_period - $total_expenses;

        if ($report_generated && empty($message) && $total_revenue == 0 && $total_cogs == 0 && $total_expenses == 0) {
             $message = "<div class='alert alert-info'>لا توجد بيانات مالية (مبيعات، تكاليف، أو مصاريف) خلال الفترة المحددة.</div>";
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
        <h1><i class="fas fa-balance-scale"></i> تقرير صافي الربح</h1>
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

    <?php if ($report_generated && empty($message) ): ?>
    <div class="card shadow">
        <div class="card-header bg-info text-white">
            نتائج تقرير صافي الربح للفترة من: <strong><?php echo htmlspecialchars($start_date_filter); ?></strong> إلى: <strong><?php echo htmlspecialchars($end_date_filter); ?></strong>
        </div>
        <div class="card-body">
            <div class="row text-center">
                <div class="col-md-3 mb-3">
                    <div class="stat-card p-3 border rounded bg-light shadow-sm">
                        <h5 class="text-primary">إجمالي الإيرادات</h5>
                        <h3 class="fw-bold"><?php echo number_format($total_revenue, 2); ?> ج.م</h3>
                    </div>
                </div>
                <div class="col-md-3 mb-3">
                    <div class="stat-card p-3 border rounded bg-light shadow-sm">
                        <h5 class="text-warning">تكلفة البضاعة المباعة</h5>
                        <h3 class="fw-bold"><?php echo number_format($total_cogs, 2); ?> ج.م</h3>
                    </div>
                </div>
                <div class="col-md-3 mb-3">
                    <div class="stat-card p-3 border rounded bg-light shadow-sm">
                        <h5 class="text-danger">إجمالي المصروفات</h5>
                        <h3 class="fw-bold"><?php echo number_format($total_expenses, 2); ?> ج.م</h3>
                    </div>
                </div>
                <div class="col-md-3 mb-3">
                    <div class="stat-card p-3 border rounded bg-light shadow-sm">
                        <h5 class="text-success">صافي الربح</h5>
                        <h2 class="fw-bolder <?php echo ($net_profit < 0) ? 'text-danger' : 'text-success'; ?>">
                            <?php echo number_format($net_profit, 2); ?> ج.م
                        </h2>
                        <?php if($total_revenue > 0): ?>
                            <p class="mb-0 text-muted">(بنسبة <?php echo number_format(($net_profit / $total_revenue) * 100, 1); ?>% من الإيرادات)</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <hr>
             <p class="text-muted small mt-3">
                * الإيرادات: من إجمالي الفواتير الصادرة المسلمة خلال الفترة.<br>
                * تكلفة البضاعة المباعة: بناءً على آخر سعر شراء مسجل لكل منتج تم بيعه خلال الفترة.<br>
                * المصروفات: مجموع المصروفات المسجلة خلال الفترة المحددة.<br>
                * صافي الربح = الإيرادات - تكلفة البضاعة المباعة - المصروفات.
            </p>
            <?php /*
            // يمكنك هنا إضافة تفاصيل أكثر، مثل قائمة بالمصاريف أو الفواتير المساهمة
            */ ?>
        </div>
    </div>
    <?php elseif ($report_generated && !empty($message) && strpos($message, 'alert-danger') === false && strpos($message, 'alert-warning') === false): ?>
        <?php // هذا الشرط لعرض رسالة "لا توجد بيانات" إذا لم تكن هناك أخطاء أخرى ?>
         <div class="alert alert-info">لا توجد بيانات مالية (مبيعات، تكاليف، أو مصاريف) خلال الفترة المحددة لعرض التقرير.</div>
    <?php endif; ?>
</div>

<style>
    .stat-card h5 { font-size: 0.9rem; margin-bottom: 0.5rem; text-transform: uppercase; }
    .stat-card h2, .stat-card h3 { font-size: 1.75rem; }
</style>

<?php
$conn->close();
require_once BASE_DIR . 'partials/footer.php';
?>