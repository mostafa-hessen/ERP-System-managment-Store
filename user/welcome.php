<?php
$page_title = "لوحة التحكم";
$page_css = 'welcome.css';
require_once dirname(__DIR__) . '/config.php';
require_once BASE_DIR . 'partials/session_admin.php';
require_once BASE_DIR . 'partials/header.php';
require_once BASE_DIR . 'partials/sidebar.php';

function e($s)
{
    return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$startOfMonth = date('Y-m-01 00:00:00');
$endOfMonth   = date('Y-m-t 23:59:59');
$startOfMonth_date = date('Y-m-01');
$endOfMonth_date   = date('Y-m-t');

/* --- إجمالي العملاء --- */
$total_customers = $conn->query("SELECT COUNT(*) AS c FROM customers")->fetch_assoc()['c'] ?? 0;

/* --- إجمالي المنتجات --- */
$total_products = $conn->query("SELECT COUNT(*) AS c FROM products")->fetch_assoc()['c'] ?? 0;

/* --- فواتير لم تُسلم --- */
$total_pending_sales_invoices = $conn->query("SELECT COUNT(*) AS c FROM invoices_out WHERE delivered = 'no'")->fetch_assoc()['c'] ?? 0;

/* --- مبيعات الشهر --- */
$current_month_sales = 0.0;
if ($stmt = $conn->prepare("
    SELECT COALESCE(SUM(ioi.total_price),0) AS monthly_total
    FROM invoice_out_items ioi
    JOIN invoices_out io ON ioi.invoice_out_id = io.id
    WHERE io.delivered = 'yes' AND io.created_at BETWEEN ? AND ?
")) {
    $stmt->bind_param("ss", $startOfMonth, $endOfMonth);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $current_month_sales = floatval($r['monthly_total'] ?? 0);
    $stmt->close();
}

/* --- مصاريف الشهر --- */
$current_month_expenses = 0.0;
if ($stmt = $conn->prepare("
    SELECT COALESCE(SUM(amount),0) AS total_expenses 
    FROM expenses 
    WHERE expense_date BETWEEN ? AND ?
")) {
    $stmt->bind_param("ss", $startOfMonth_date, $endOfMonth_date);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $current_month_expenses = floatval($r['total_expenses'] ?? 0);
    $stmt->close();
}

/* --- منتجات منخفضة الرصيد --- */
/* --- منتجات منخفضة الرصيد (باحتساب remaining من الدفعات - active أو consumed) --- */
/* --- منتجات منخفضة الرصيد (باحتساب remaining من الدفعات - active أو consumed) --- */
$total_low_stock_items = 0;
$total_remaining_all = 0;
$low_stock_preview = [];

// Subquery لتجميع remaining
$batchesAggSql = "
    SELECT product_id, SUM(remaining) AS total_remaining
    FROM batches
    WHERE status IN ('active','consumed')
    GROUP BY product_id
";

// 1) إجمالي عدد المنتجات المنخفضة
$countSql = "
    SELECT COUNT(*) AS c
    FROM products p
    LEFT JOIN (
        $batchesAggSql
    ) b ON p.id = b.product_id
    WHERE p.reorder_level > 0
      AND COALESCE(b.total_remaining, 0) <= p.reorder_level
";
$res = $conn->query($countSql);
$total_low_stock_items = intval($res->fetch_assoc()['c'] ?? 0);

// 2) إجمالي الكمية المتبقية لكل المنتجات المنخفضة
$remainingSql = "
    SELECT SUM(COALESCE(b.total_remaining, 0)) AS total_remaining_all
    FROM products p
    LEFT JOIN (
        $batchesAggSql
    ) b ON p.id = b.product_id
    WHERE p.reorder_level > 0
      AND COALESCE(b.total_remaining, 0) <= p.reorder_level
";
$res = $conn->query($remainingSql);
$total_remaining_all = floatval($res->fetch_assoc()['total_remaining_all'] ?? 0);

// 3) Preview (زي ما شرحنا قبل كده)…


// 2) معاينة (preview) لمنتجات منخفضة الرصيد — نعرض المتبقي من الدفعات جنب current_stock
$previewSql = "
    SELECT
      p.id,
      p.product_code,
      p.name,
      p.unit_of_measure,
      p.current_stock,
      p.reorder_level,
      COALESCE(b.total_remaining, 0) AS batches_remaining
    FROM products p
    LEFT JOIN (
        $batchesAggSql
    ) b ON p.id = b.product_id
    WHERE p.reorder_level > 0
      AND COALESCE(b.total_remaining, 0) <= p.reorder_level
    ORDER BY (p.reorder_level - COALESCE(b.total_remaining, 0)) DESC, p.name ASC
    LIMIT 50
";
$res = $conn->query($previewSql);
while ($row = $res->fetch_assoc()) $low_stock_preview[] = $row;

?>

<style>
    /* ===== Scoped improvements for dashboard stats (inside .welcome) ===== */
.low-stock {
    max-height: 350px;
    overflow-y: auto    ;
    /* border-radius: 20px !important; */



}
</style>
<div class="container welcome mt-5">
    <!-- الوصول السريع -->
    <div class="card dashboard-card fade-in">
        <div class="card-header">الوصول السريع</div>
        <div class="card-body">
            <div class="d-flex flex-wrap w-100">
               
                    
                    <a href="<?php echo BASE_URL; ?>admin/manage_customer.php" class="btn btn-success btn-action"> <i class="fas fa-user-plus"></i> إضافة عميل </a> 
                    <a href="<?php echo BASE_URL; ?>admin/manage_products.php" class="btn btn-info btn-action"> <i class="fas fa-box"></i> إضافة منتج </a> 
                     <a  href="<?php echo BASE_URL; ?>invoices_out/create_invoice.php" class="btn btn-primary btn-action
                     flex-grow-1">
                    <i class="fas fa-plus"></i> إضافة فاتورة بيع </a>
                    <a href="<?php echo BASE_URL; ?>admin/pending_invoices.php" class="btn btn-warning btn-action"> <i class="fas fa-file-invoice"></i> الفواتير غير المسلمة </a> <a href="<?php echo BASE_URL; ?>admin/net_profit_report.php" class="btn btn-danger btn-action"> <i class="fas fa-chart-pie"></i> تقرير الأرباح </a>
            </div>
        </div>
    </div>


    <div class="stats-grid mt-3">
        <!-- إجمالي العملاء -->
        <div class="stat-card">
            <div class="stat-left">
                <div class="stat-icon icon-primary"><i class="fas fa-user"></i></div>
                <div class="stat-body">
                    <div class="value">إجمالي العملاء</div>
                    <div class=" label"><?php echo number_format($total_customers); ?></div>
                </div>
            </div>
            <div class="view-page"><a href="<?php echo BASE_URL; ?>admin/manage_customer.php" class="small text-muted">عرض</a></div>
        </div>

        <!-- منتجات منخفضة الرصيد -->
        <div class="stat-card <?php echo ($total_low_stock_items > 0 ? 'low' : ''); ?>">
            <div class="stat-left">
                <div class="stat-icon <?php echo ($total_low_stock_items > 0 ? 'icon-danger' : 'icon-secondary'); ?>"><i class="fas fa-battery-quarter"></i></div>
                <div class="stat-body">
                    <div class="value">منتجات منخفضة الرصيد</div>
                    <div class="label"><?php echo number_format($total_low_stock_items); ?></div>

                </div>
            </div>
            <div class="view-page"><a href="<?php echo BASE_URL; ?>admin/low_stock_report.php" class="small text-muted">تقرير</a></div>
        </div>

        <!-- مبيعات الشهر -->
        <div class="stat-card">
            <div class="stat-left">
                <div class="stat-icon icon-success"><i class="fas fa-money-bill-wave"></i></div>
                <div class="stat-body">
                    <div class="value">مبيعات الشهر</div>
                    <div class="label"><?php echo number_format($current_month_sales, 2); ?> ج.م</div>
                </div>
            </div>
            <div class="view-page"><a href="<?php echo BASE_URL; ?>admin/sales_report_period.php" class="small text-muted">تفاصيل</a></div>
        </div>

        <!-- فواتير لم تسلم -->
        <div class="stat-card">
            <div class="stat-left">
                <div class="stat-icon icon-warning"><i class="fas fa-truck-loading"></i></div>
                <div class="stat-body">
                    <div class="value">فواتير لم تُسلم</div>
                    <div class="label"><?php echo number_format($total_pending_sales_invoices); ?></div>
                </div>
            </div>
            <div class="view-page"><a href="<?php echo BASE_URL; ?>admin/pending_invoices.php" class="small text-muted">عرض</a></div>
        </div>

        <!-- مصاريف الشهر -->
        <div class="stat-card">
            <div class="stat-left">
                <div class="stat-icon icon-danger"><i class="fas fa-receipt"></i></div>
                <div class="stat-body">
                    <div class="value">مصاريف الشهر</div>
                    <div class="label"><?php echo number_format($current_month_expenses, 2); ?> ج.م</div>
                </div>
            </div>
            <div class="view-page"><a href="<?php echo BASE_URL; ?>admin/manage_expenses.php" class="small text-muted">عرض</a></div>
        </div>

        <!-- إجمالي المنتجات -->
        <div class="stat-card">
            <div class="stat-left">
                <div class="stat-icon icon-primary"><i class="fas fa-box"></i></div>
                <div class="stat-body">
                    <div class="value">إجمالي المنتجات</div>
                    <div class="label"><?php echo number_format($total_products); ?></div>
                </div>
            </div>
            <div class="view-page"><a href="<?php echo BASE_URL; ?>admin/manage_products.php" class="small text-muted">عرض</a></div>
        </div>
    </div>

    <!-- جدول منتجات منخفضة الرصيد -->
<div style="margin-top:18px">
    <div class="low-stock" style="background:var(--surface); padding:14px; border-radius:var(--radius); box-shadow:var(--shadow-1); border:1px solid var(--border);">
        <h3 style="margin:0 0 10px 0">تفاصيل المنتجات منخفضة الرصيد</h3>
        <?php if ($total_low_stock_items > 0): ?>
            <table class="low-table" role="table">
                <thead>
                    <tr>
                        <th>المنتج</th>
                        <th>الكود</th>
                        <!-- <th>الرصيد (current_stock)</th> -->
                        <th>المتبقي من الدفعات</th>
                        <th>حد إعادة الطلب</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($low_stock_preview as $row): ?>
                        <tr class="low-row">
                            <td><?php echo e($row['name']); ?></td>
                            <td><?php echo e($row['product_code']); ?></td>
                            <!-- <td><?php echo number_format($row['current_stock'], 2); ?></td> -->
                            <td class="fw-bold text-danger">
                                <?php echo number_format($row['batches_remaining'], 2); ?>
                            </td>
                            <td><?php echo number_format($row['reorder_level'], 2); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="small text-muted">لا توجد منتجات منخفضة الرصيد الآن — كل شيء على ما يرام ✅</div>
        <?php endif; ?>
    </div>
</div>






    <!-- الأقسام الرئيسية -->
    <h2 class="h4 mb-3 mt-5">الأقسام الرئيسية</h2>
    <div class="category-grid">

        <!-- قسم المبيعات -->
        <div class="card dashboard-card fade-in">
            <div class="card-header category-sales">المبيعات والعملاء</div>
            <div class="card-body">
                <div class="d-grid gap-2">
                    <a href="<?php echo BASE_URL; ?>admin/pending_invoices.php" class="btn btn-outline-primary text-start">
                        <i class="fas fa-cash-register me-2"></i>
                        فواتير البيع المؤجله

                    </a>
                    <a href="<?php echo BASE_URL; ?>admin/delivered_invoices.php" class="btn btn-outline-primary text-start">
                        <i class="fas fa-check-double card-icon-lg text-info mb-3"></i>

                        فواتير البيع المسلمه

                    </a>

                    <a href="<?php echo BASE_URL; ?>admin/manage_customer.php" class="btn btn-outline-primary text-start">
                        <i class="fas fa-users me-2"></i> إدارة العملاء
                    </a>
                    <a href="<?php echo BASE_URL; ?>admin/sales_report_period.php" class="btn btn-outline-primary text-start">
                        <i class="fas fa-list-alt me-2"></i> تقارير المبيعات
                    </a>
                    <a href="<?php echo BASE_URL; ?>admin/top_selling_products_report.php" class="btn btn-outline-primary text-start">
                        <i class="fas fa-chart-line me-2"></i>
                        المنتجات الاكثر مبيعا
                    </a>

                </div>
            </div>
        </div>

        <!-- قسم المخزون -->
        <div class="card dashboard-card fade-in">
            <div class="card-header category-inventory">إدارة المخزون</div>
            <div class="card-body">
                <div class="d-grid gap-2">
                    <a href="<?php echo BASE_URL; ?>admin/manage_products.php" class="btn btn-outline-success text-start">
                        <i class="fas fa-boxes me-2"></i> المنتجات
                    </a>
                    <a href="<?php echo BASE_URL; ?>admin/add_product.php" class="btn btn-outline-success text-start">
                        <i class="fas fa-plus me-2"></i>
                        اضافه منتج جديد للمخزن
                    </a>
                    <!-- <a href="<?php echo BASE_URL; ?>admin/stock_report.php" class="btn btn-outline-success text-start">
                        <i class="fas fa-chart-bar me-2"></i> تقارير المخزون
                    </a> -->

                    <a href="<?php echo BASE_URL; ?>admin/stock_valuation_report.php" class="btn btn-outline-success text-start">
                        <i class="fas fa-balance-scale me-2"></i> تقرير تقييم المخزون
                    </a>
                </div>
            </div>
        </div>

        <!-- قسم المشتريات -->
        <div class="card dashboard-card fade-in">
            <div class="card-header category-purchases">المشتريات والموردين</div>
            <div class="card-body">
                <div class="d-grid gap-2">
                    <a href="<?php echo BASE_URL; ?>admin/manage_purchase_invoices.php" class="btn btn-outline-warning text-start">
                        <i class="fas fa-shopping-cart me-2"></i> فواتير الشراء
                    </a>
                    <a href="<?php echo BASE_URL; ?>admin/manage_suppliers.php" class="btn btn-outline-warning text-start">
                        <i class="fas fa-people-carry me-2"></i> إدارة الموردين
                    </a>
                    <a href="<?php echo BASE_URL; ?>admin/manage_purchase_invoices.php" class="btn btn-outline-warning text-start">
                        <i class="fas fa-file-import me-2"></i> تقارير المشتريات
                    </a>
                </div>
            </div>
        </div>

        <!-- قسم التقارير -->
        <div class="card dashboard-card fade-in">
            <div class="card-header category-reports">التقارير المالية</div>
            <div class="card-body">
                <div class="d-grid gap-2">
                    <a href="<?php echo BASE_URL; ?>admin/gross_profit_report.php" class="btn btn-outline-danger text-start">
                        <i class="fas fa-chart-line me-2"></i> تقرير المبيعات
                    </a>
                    <a href="<?php echo BASE_URL; ?>admin/net_profit_report.php" class="btn btn-outline-danger text-start">
                        <i class="fas fa-funnel-dollar me-2"></i> صافي الارباح
                    </a>
                    <a href="<?php echo BASE_URL; ?>admin/gross_profit_report.php" class="btn btn-outline-danger text-start">
                        <i class="fas fa-funnel-dollar me-2"></i> اجمالي الارباح
                    </a>
                    <a href="<?php echo BASE_URL; ?>admin/manage_expenses.php" class="btn btn-outline-danger text-start">
                        <i class="fas fa-balance-scale me-2"></i> تقرير المصروفات
                    </a>


                    <a href="<?php echo BASE_URL; ?>admin/manage_expense_categories.php"
                        class="btn btn-outline-danger text-start">
                        <i class="fas fa-dollar me-2"></i> اضافه مصروف جديد
                    </a>
                </div>
            </div>
        </div>

        <!-- قسم الإعدادات -->
        <div class="card dashboard-card fade-in">
            <div class="card-header category-settings">الإعدادات والإدارة</div>
            <div class="card-body">
                <div class="d-grid gap-2">
                    <a href="<?php echo BASE_URL; ?>admin/manage_users.php" class="btn btn-outline-secondary text-start">
                        <i class="fas fa-users-cog me-2"></i> إدارة المستخدمين
                    </a>
                    <a href="<?php echo BASE_URL; ?>admin/registration_settings.php" class="btn btn-outline-secondary text-start">
                        <i class="fas fa-cog me-2"></i> إعدادات النظام
                    </a>



                </div>
            </div>
        </div>

    </div>
</div>
</div>

<?php require_once BASE_DIR . 'partials/footer.php'; ?>