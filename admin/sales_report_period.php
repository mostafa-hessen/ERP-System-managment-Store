<?php
$page_title = "تقرير المبيعات خلال فترة";
$class_dashboard = "active";
require_once dirname(__DIR__) . '/config.php';
require_once BASE_DIR . 'partials/session_admin.php'; // صلاحيات المدير فقط

$message = "";
$sales_data = []; // لتخزين بيانات الفواتير للتقرير
$total_invoices_period = 0;
$total_sales_amount_period = 0;

// القيم الافتراضية للتواريخ (يمكن جعلها الشهر الحالي مثلاً)
$start_date_filter = isset($_GET['start_date']) ? trim($_GET['start_date']) : '';
$end_date_filter = isset($_GET['end_date']) ? trim($_GET['end_date']) : '';

// --- معالجة طلب عرض التقرير ---
if (!empty($start_date_filter) && !empty($end_date_filter)) {
    // التحقق من صحة صيغة التاريخ (يمكن إضافة تحقق أكثر تفصيلاً)
    if (DateTime::createFromFormat('Y-m-d', $start_date_filter) === false || DateTime::createFromFormat('Y-m-d', $end_date_filter) === false) {
        $message = "<div class='alert alert-danger'>صيغة التاريخ غير صحيحة. يرجى استخدام YYYY-MM-DD.</div>";
    } elseif ($start_date_filter > $end_date_filter) {
        $message = "<div class='alert alert-danger'>تاريخ البدء لا يمكن أن يكون بعد تاريخ الانتهاء.</div>";
    } else {
        // تعديل تاريخ الانتهاء ليشمل اليوم بأكمله
        $start_date_sql = $start_date_filter . " 00:00:00";
        $end_date_sql = $end_date_filter . " 23:59:59";

        $sql = "SELECT
                    io.id as invoice_id,
                    io.created_at as invoice_date,
                    c.name as customer_name,
                    (SELECT SUM(ioi.total_price) FROM invoice_out_items ioi WHERE ioi.invoice_out_id = io.id) as invoice_total
                FROM invoices_out io
                JOIN customers c ON io.customer_id = c.id
                WHERE io.delivered = 'yes'
                AND io.created_at BETWEEN ? AND ?
                ORDER BY io.created_at DESC";

        if ($stmt = $conn->prepare($sql)) {
            $stmt->bind_param("ss", $start_date_sql, $end_date_sql);
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $sales_data[] = $row;
                    $total_sales_amount_period += floatval($row['invoice_total'] ?? 0);
                }
                $total_invoices_period = count($sales_data);
                if ($total_invoices_period == 0 && empty($message)) {
                    $message = "<div class='alert alert-info'>لا توجد فواتير مبيعات مسلمة خلال الفترة المحددة.</div>";
                }
            } else {
                $message = "<div class='alert alert-danger'>حدث خطأ أثناء تنفيذ استعلام المبيعات: " . $stmt->error . "</div>";
            }
            $stmt->close();
        } else {
            $message = "<div class='alert alert-danger'>خطأ في تحضير استعلام المبيعات: " . $conn->error . "</div>";
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET' && (isset($_GET['start_date']) || isset($_GET['end_date']))) {
    // إذا تم إرسال النموذج ولكن أحد الحقول فارغ
    if (empty($start_date_filter) || empty($end_date_filter)) {
        $message = "<div class='alert alert-warning'>الرجاء تحديد تاريخ البدء وتاريخ الانتهاء لعرض التقرير.</div>";
    }
}


require_once BASE_DIR . 'partials/header.php';
require_once BASE_DIR . 'partials/sidebar.php';
?>

<div class="container mt-5 pt-3">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1><i class="fas fa-chart-bar"></i> تقرير المبيعات خلال فترة</h1>
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

    <?php if (!empty($sales_data)): ?>
    <div class="card shadow">
        <div class="card-header">
            نتائج التقرير للفترة من: <strong><?php echo htmlspecialchars($start_date_filter); ?></strong> إلى: <strong><?php echo htmlspecialchars($end_date_filter); ?></strong>
        </div>
        <div class="card-body">
            <div class="alert alert-secondary" role="alert">
                <div class="row">
                    <div class="col-md-6">
                        <strong>إجمالي عدد الفواتير:</strong> <?php echo $total_invoices_period; ?> فاتورة
                    </div>
                    <div class="col-md-6 text-md-end">
                        <strong>إجمالي قيمة المبيعات:</strong> <?php echo number_format($total_sales_amount_period, 2); ?> ج.م
                    </div>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-striped table-hover table-bordered align-middle">
                    <thead class="table-dark">
                        <tr>
                            <th>#</th>
                            <th>رقم الفاتورة</th>
                            <th>تاريخ الفاتورة</th>
                            <th>اسم العميل</th>
                            <th class="text-end">إجمالي الفاتورة</th>
                            <th class="text-center">عرض</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $counter = 1; ?>
                        <?php foreach($sales_data as $invoice): ?>
                            <tr>
                                <td><?php echo $counter++; ?></td>
                                <td>#<?php echo $invoice["invoice_id"]; ?></td>
                                <td><?php echo date('Y-m-d H:i A', strtotime($invoice["invoice_date"])); ?></td>
                                <td><?php echo htmlspecialchars($invoice["customer_name"]); ?></td>
                                <td class="text-end fw-bold"><?php echo number_format(floatval($invoice['invoice_total'] ?? 0), 2); ?> ج.م</td>
                                <td class="text-center">
                                    <a href="<?php echo BASE_URL; ?>invoices_out/view_invoice_detaiels.php?id=<?php echo $invoice["invoice_id"]; // تأكد من أن هذا المسار صحيح لـ view_invoice.php ?>" class="btn btn-info btn-sm" title="مشاهدة تفاصيل الفاتورة">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php elseif ($_SERVER['REQUEST_METHOD'] === 'GET' && !empty($start_date_filter) && !empty($end_date_filter) && empty($message) ): ?>
        <div class="alert alert-info">لا توجد فواتير مبيعات مسلمة خلال الفترة المحددة.</div>
    <?php endif; ?>

</div>

<?php
$conn->close();
require_once BASE_DIR . 'partials/footer.php';
?>