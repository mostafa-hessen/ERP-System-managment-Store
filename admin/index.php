<?php
$page_title = "لوحة تحكم الإدارة";
$class2= "active"; // لتفعيل رابط "لوحة التحكم" في الـ navbar
require_once dirname(__DIR__) . '/config.php';
require_once BASE_DIR . 'partials/session_admin.php';

// --- جلب الإحصائيات العامة ---
$sql_customers_count = "SELECT COUNT(*) as total_customers FROM customers";
$result_customers_count = $conn->query($sql_customers_count);
$total_customers = ($result_customers_count && $result_customers_count->num_rows > 0) ? $result_customers_count->fetch_assoc()['total_customers'] : 0;

$sql_products_count = "SELECT COUNT(*) as total_products FROM products";
$result_products_count = $conn->query($sql_products_count);
$total_products = ($result_products_count && $result_products_count->num_rows > 0) ? $result_products_count->fetch_assoc()['total_products'] : 0;

$sql_suppliers_count = "SELECT COUNT(*) as total_suppliers FROM suppliers";
$result_suppliers_count = $conn->query($sql_suppliers_count);
$total_suppliers = ($result_suppliers_count && $result_suppliers_count->num_rows > 0) ? $result_suppliers_count->fetch_assoc()['total_suppliers'] : 0;

// --- !! إضافة جديدة: عدد الفواتير الصادرة غير المستلمة !! ---
$sql_pending_sales_invoices_count = "SELECT COUNT(*) as total_pending_sales FROM invoices_out WHERE delivered = 'no'";
$result_pending_sales_count = $conn->query($sql_pending_sales_invoices_count);
$total_pending_sales_invoices = ($result_pending_sales_count && $result_pending_sales_count->num_rows > 0) ? $result_pending_sales_count->fetch_assoc()['total_pending_sales'] : 0;
// --- !! نهاية الإضافة !! ---


// --- جلب إحصائيات المبيعات والمصاريف والأرباح الشهرية ---
$current_month_sales = 0;
$current_month_cogs = 0;
$current_month_expenses = 0;
$current_month_gross_profit = 0;
$current_month_net_profit = 0;

$current_month_start_date_only = date('Y-m-01');
$current_month_end_date_only = date('Y-m-t');
$current_month_start_datetime = $current_month_start_date_only . ' 00:00:00';
$current_month_end_datetime = $current_month_end_date_only . ' 23:59:59';

// 1. إجمالي مبيعات الشهر الحالي
$sql_current_sales = "SELECT SUM(ioi.total_price) AS monthly_total
                      FROM invoice_out_items ioi
                      JOIN invoices_out io ON ioi.invoice_out_id = io.id
                      WHERE io.delivered = 'yes' AND io.created_at BETWEEN ? AND ?";
$stmt_current_sales = $conn->prepare($sql_current_sales);
if($stmt_current_sales){
    $stmt_current_sales->bind_param("ss", $current_month_start_datetime, $current_month_end_datetime);
    $stmt_current_sales->execute();
    $result_current_sales = $stmt_current_sales->get_result();
    if ($result_current_sales && $result_current_sales->num_rows > 0) {
        $row_current_sales = $result_current_sales->fetch_assoc();
        $current_month_sales = floatval($row_current_sales['monthly_total'] ?? 0);
    }
    $stmt_current_sales->close();
}

// 2. إجمالي تكلفة البضاعة المباعة للشهر الحالي
$sql_current_cogs = "SELECT SUM(sold_items.quantity * COALESCE(last_costs.cost_price_per_unit, 0)) AS total_cogs
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
if ($stmt_current_cogs = $conn->prepare($sql_current_cogs)) {
    $stmt_current_cogs->bind_param("ss", $current_month_start_datetime, $current_month_end_datetime);
    if ($stmt_current_cogs->execute()) {
        $result_cogs = $stmt_current_cogs->get_result();
        if ($row_cogs = $result_cogs->fetch_assoc()) {
            $current_month_cogs = floatval($row_cogs['total_cogs'] ?? 0);
        }
    }
    $stmt_current_cogs->close();
}

// 3. إجمالي مصاريف الشهر الحالي
$sql_current_expenses = "SELECT SUM(amount) AS total_expenses
                         FROM expenses
                         WHERE expense_date BETWEEN ? AND ?";
if ($stmt_current_expenses = $conn->prepare($sql_current_expenses)) {
    $stmt_current_expenses->bind_param("ss", $current_month_start_date_only, $current_month_end_date_only);
    if ($stmt_current_expenses->execute()) {
        $result_expenses = $stmt_current_expenses->get_result();
        if ($row_expenses = $result_expenses->fetch_assoc()) {
            $current_month_expenses = floatval($row_expenses['total_expenses'] ?? 0);
        }
    }
    $stmt_current_expenses->close();
}

// 4. حساب الأرباح
$current_month_gross_profit = $current_month_sales - $current_month_cogs;
$current_month_net_profit = $current_month_gross_profit - $current_month_expenses;

// --- !! إضافة جديدة: عدد المنتجات منخفضة الرصيد !! ---
$sql_low_stock_count = "SELECT COUNT(*) as total_low_stock
                        FROM products
                        WHERE current_stock <= reorder_level AND reorder_level > 0";
$result_low_stock_count = $conn->query($sql_low_stock_count);
$total_low_stock_items = ($result_low_stock_count && $result_low_stock_count->num_rows > 0) ? $result_low_stock_count->fetch_assoc()['total_low_stock'] : 0;
// --- !! نهاية الإضافة !! ---


require_once BASE_DIR . 'partials/header.php';
require_once BASE_DIR . 'partials/sidebar.php';
?>

<div class="container mt-5 pt-3">

    <div class="p-4 mb-4 bg-light rounded-3 text-center shadow-sm">
        <h1 class="display-5 fw-bold"><i class="fas fa-tachometer-alt me-2"></i>لوحة تحكم الإدارة</h1>
        <p class="fs-4 text-muted">نظرة عامة سريعة وإحصائيات النظام.</p>
    </div>

    <div class="row mb-4">
        <div class="col-xl-2 col-lg-4 col-md-4 col-6 mb-4">
            <div class="card border-start border-primary border-4 shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col">
                            <div class="text-xs fw-bold text-primary text-uppercase mb-1">إجمالي العملاء</div>
                            <div class="h5 mb-0 fw-bold text-gray-800"><?php echo $total_customers; ?></div>
                        </div>
                        <div class="col-auto"><i class="fas fa-users fa-2x text-gray-300"></i></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-lg-4 col-md-4 col-6 mb-4">
            <div class="card border-start border-success border-4 shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col">
                            <div class="text-xs fw-bold text-success text-uppercase mb-1">إجمالي المنتجات</div>
                            <div class="h5 mb-0 fw-bold text-gray-800"><?php echo $total_products; ?></div>
                        </div>
                        <div class="col-auto"><i class="fas fa-boxes fa-2x text-gray-300"></i></div>
                    </div>
                </div>
            </div>
        </div>
        <!-- <div class="col-xl-2 col-lg-4 col-md-4 col-6 mb-4">
            <div class="card border-start border-info border-4 shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col">
                            <div class="text-xs fw-bold text-info text-uppercase mb-1">إجمالي الموردين</div>
                            <div class="h5 mb-0 fw-bold text-gray-800"><?php echo $total_suppliers; ?></div>
                        </div>
                        <div class="col-auto"><i class="fas fa-people-carry fa-2x text-gray-300"></i></div>
                    </div>
                </div>
            </div>
        </div> -->

        <div class="col-xl-2 col-lg-4 col-md-4 col-6 mb-4"> <div class="card border-start <?php echo ($total_low_stock_items > 0) ? 'border-danger' : 'border-secondary'; ?> border-4 shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col">
                            <div class="text-xs fw-bold <?php echo ($total_low_stock_items > 0) ? 'text-danger' : 'text-secondary'; ?> text-uppercase mb-1">منتجات منخفضة الرصيد</div>
                            <div class="h5 mb-0 fw-bold text-gray-800"><?php echo $total_low_stock_items; ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-battery-quarter fa-2x <?php echo ($total_low_stock_items > 0) ? 'text-danger' : 'text-gray-300'; ?>"></i>
                        </div>
                    </div>
                </div>
                 <?php if($total_low_stock_items > 0): ?>
                    <a href="<?php echo BASE_URL; ?>admin/low_stock_report.php" class="card-footer text-white bg-danger text-decoration-none py-1 text-center">
                        <small>عرض التقرير <i class="fas fa-arrow-circle-right"></i></small>
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <div class="col-xl-2 col-lg-4 col-md-4 col-6 mb-4">
            <div class="card border-start border-orange border-4 shadow h-100 py-2"> <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col">
                            <div class="text-xs fw-bold text-orange text-uppercase mb-1">فواتير صادر لم تسلم</div>
                            <div class="h5 mb-0 fw-bold text-gray-800"><?php echo $total_pending_sales_invoices; ?></div>
                        </div>
                        <div class="col-auto"><i class="fas fa-truck-loading fa-2x text-gray-300"></i></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-lg-4 col-md-4 col-6 mb-4">
            <div class="card border-start border-warning border-4 shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col">
                            <div class="text-xs fw-bold text-warning text-uppercase mb-1">مبيعات الشهر</div>
                            <div class="h5 mb-0 fw-bold text-gray-800"><?php echo number_format($current_month_sales, 2); ?> ج.م</div>
                        </div>
                        <div class="col-auto"><i class="fas fa-dollar-sign fa-2x text-gray-300"></i></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-lg-4 col-md-4 col-6 mb-4">
            <div class="card border-start border-danger border-4 shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col">
                            <div class="text-xs fw-bold text-danger text-uppercase mb-1">مصاريف الشهر</div>
                            <div class="h5 mb-0 fw-bold text-gray-800"><?php echo number_format($current_month_expenses, 2); ?> ج.م</div>
                        </div>
                        <div class="col-auto"><i class="fas fa-receipt fa-2x text-gray-300"></i></div>
                    </div>
                </div>
            </div>
        </div>
    </div>


    <div class="row justify-content-center mb-4">
        <div class="col-lg-3 col-md-6 mb-4">
            <div class="card border-start border-purple border-4 shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col">
                            <div class="text-xs fw-bold text-purple text-uppercase mb-1">إجمالي ربح الشهر</div>
                            <div class="h5 mb-0 fw-bold text-gray-800"><?php echo number_format($current_month_gross_profit, 2); ?> ج.م</div>
                        </div>
                        <div class="col-auto"><i class="fas fa-hand-holding-usd fa-2x text-gray-300"></i></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 mb-4">
            <div class="card border-start border-teal border-4 shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col">
                            <div class="text-xs fw-bold text-teal text-uppercase mb-1">صافي ربح الشهر</div>
                            <div class="h5 mb-0 fw-bold text-gray-800"><?php echo number_format($current_month_net_profit, 2); ?> ج.م</div>
                        </div>
                        <div class="col-auto"><i class="fas fa-piggy-bank fa-2x text-gray-300"></i></div>
                    </div>
                </div>
            </div>
        </div>
    </div>


    <h2 class="text-center mb-4 text-muted border-bottom pb-2">أقسام الإدارة الرئيسية</h2>
    <div class="row text-center justify-content-center">
        <div class="col-md-6 col-lg-4 mb-4">
            <div class="card h-100 shadow-sm hover-shadow-lg">
                <div class="card-body d-flex flex-column align-items-center">
                    <i class="fas fa-users-cog card-icon-lg text-primary mb-3"></i>
                    <h5 class="card-title">إدارة المستخدمين</h5>
                    <p class="card-text flex-grow-1 small">عرض، تعديل، وحذف المستخدمين والصلاحيات.</p>
                    <a href="<?php echo BASE_URL; ?>admin/manage_users.php" class="btn btn-primary mt-auto stretched-link">الدخول للقسم</a>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-lg-4 mb-4">
            <div class="card h-100 shadow-sm hover-shadow-lg">
                <div class="card-body d-flex flex-column align-items-center">
                    <i class="fas fa-address-book card-icon-lg text-success mb-3"></i>
                    <h5 class="card-title">إدارة العملاء <span class="badge bg-light text-success rounded-pill"><?php echo $total_customers; ?></span></h5>
                    <p class="card-text flex-grow-1 small">إدارة بيانات العملاء والفواتير الصادرة.</p>
                    <a href="<?php echo BASE_URL; ?>admin/manage_customer.php" class="btn btn-success mt-auto stretched-link">الدخول للقسم</a>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-lg-4 mb-4">
            <div class="card h-100 shadow-sm hover-shadow-lg">
                <div class="card-body d-flex flex-column align-items-center">
                    <i class="fas fa-boxes card-icon-lg mb-3" style="color: #6f42c1;"></i>
                    <h5 class="card-title">إدارة المنتجات <span class="badge rounded-pill ms-1" style="background-color: #f0f0f0; color: #6f42c1;"><?php echo $total_products; ?></span></h5>
                    <p class="card-text flex-grow-1 small">إضافة منتجات، تعديل الأرصدة، وعرض القائمة.</p>
                    <a href="<?php echo BASE_URL; ?>admin/manage_products.php" class="btn mt-auto stretched-link" style="background-color: #6f42c1; color: white;">الدخول للقسم</a>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-lg-4 mb-4">
            <div class="card h-100 shadow-sm hover-shadow-lg">
                <div class="card-body d-flex flex-column align-items-center">
                    <i class="fas fa-people-carry card-icon-lg text-secondary mb-3"></i>
                    <h5 class="card-title">إدارة الموردين <span class="badge bg-light text-secondary rounded-pill"><?php echo $total_suppliers; ?></span></h5>
                    <p class="card-text flex-grow-1 small">إدارة بيانات الموردين وفواتير المشتريات.</p>
                    <a href="<?php echo BASE_URL; ?>admin/manage_suppliers.php" class="btn btn-secondary mt-auto stretched-link">الدخول للقسم</a>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-lg-4 mb-4">
            <div class="card h-100 shadow-sm hover-shadow-lg">
                <div class="card-body d-flex flex-column align-items-center">
                    <i class="fas fa-receipt card-icon-lg text-danger mb-3"></i>
                    <h5 class="card-title">إدارة المصروفات</h5>
                    <p class="card-text flex-grow-1 small">تسجيل وعرض المصروفات التشغيلية.</p>
                    <a href="<?php echo BASE_URL; ?>admin/manage_expenses.php" class="btn btn-danger mt-auto stretched-link">الدخول للقسم</a>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-lg-4 mb-4">
            <div class="card h-100 shadow-sm hover-shadow-lg">
                <div class="card-body d-flex flex-column align-items-center">
                    <i class="fas fa-tags card-icon-lg text-orange mb-3"></i>
                    <h5 class="card-title">فئات المصروفات</h5>
                    <p class="card-text flex-grow-1 small">إدارة تصنيفات وأنواع المصروفات.</p>
                    <a href="<?php echo BASE_URL; ?>admin/manage_expense_categories.php" class="btn mt-auto stretched-link" style="background-color: #fd7e14; color:white;">الدخول للقسم</a>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-lg-4 mb-4">
            <div class="card h-100 shadow-sm hover-shadow-lg">
                <div class="card-body d-flex flex-column align-items-center">
                    <i class="fas fa-dolly-flatbed card-icon-lg mb-3" style="color: #5865F2;"></i>
                    <h5 class="card-title">إدارة فواتير الوارد</h5>
                    <p class="card-text flex-grow-1 small">عرض وإدارة فواتير المشتريات وحالاتها.</p>
                    <a href="<?php echo BASE_URL; ?>admin/manage_purchase_invoices.php" class="btn mt-auto stretched-link" style="background-color: #5865F2; color:white;">الدخول للقسم</a>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-lg-4 mb-4">
            <div class="card h-100 shadow-sm hover-shadow-lg">
                <div class="card-body d-flex flex-column align-items-center">
                    <i class="fas fa-truck-loading card-icon-lg text-warning mb-3"></i>
                    <h5 class="card-title">
                        الفواتير غير المستلمة (صادر)
                        <span class="badge bg-light text-warning rounded-pill"><?php echo $total_pending_sales_invoices; ?></span> </h5>
                    <p class="card-text flex-grow-1 small">تتبع الفواتير الصادرة التي لم يتم تسليمها.</p>
                    <a href="<?php echo BASE_URL; ?>admin/pending_invoices.php" class="btn btn-warning mt-auto stretched-link">الدخول للقسم</a>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-lg-4 mb-4">
            <div class="card h-100 shadow-sm hover-shadow-lg">
                <div class="card-body d-flex flex-column align-items-center">
                    <i class="fas fa-check-double card-icon-lg text-info mb-3"></i>
                    <h5 class="card-title">الفواتير المستلمة (صادر)</h5>
                    <p class="card-text flex-grow-1 small">عرض الفواتير الصادرة التي تم تسليمها.</p>
                    <a href="<?php echo BASE_URL; ?>admin/delivered_invoices.php" class="btn btn-info mt-auto stretched-link">الدخول للقسم</a>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-lg-4 mb-4">
            <div class="card h-100 shadow-sm hover-shadow-lg">
                <div class="card-body d-flex flex-column align-items-center">
                    <i class="fas fa-chart-line card-icon-lg mb-3" style="color: #20c997;"></i>
                    <h5 class="card-title">تقارير المخزون</h5>
                    <p class="card-text flex-grow-1 small">
                        عرض ملخص حركة الأرصدة والمنتجات منخفضة الرصيد.
                    </p>
                    <a href="<?php echo BASE_URL; ?>admin/stock_report.php" class="btn btn-sm mt-auto mb-1" style="background-color: #20c997; color:white;">تقرير حركة المخزون</a>
                    <a href="<?php echo BASE_URL; ?>admin/low_stock_report.php" class="btn btn-sm btn-warning mt-1">تقرير المنتجات منخفضة الرصيد <?php if($total_low_stock_items > 0) echo "<span class='badge bg-danger ms-1'>{$total_low_stock_items}</span>"; ?></a>
                </div>
            </div>
        </div>
         <div class="col-md-6 col-lg-4 mb-4">
            <div class="card h-100 shadow-sm hover-shadow-lg">
                <div class="card-body d-flex flex-column align-items-center">
                    <i class="fas fa-chart-bar card-icon-lg text-success mb-3"></i>
                    <h5 class="card-title">تقرير المبيعات</h5>
                    <p class="card-text flex-grow-1 small">عرض تحليل المبيعات خلال فترات محددة.</p>
                    <a href="<?php echo BASE_URL; ?>admin/sales_report_period.php" class="btn btn-success mt-auto stretched-link">الدخول للقسم</a>
                </div>
            </div>
        </div>
         <div class="col-md-6 col-lg-4 mb-4">
            <div class="card h-100 shadow-sm hover-shadow-lg">
                <div class="card-body d-flex flex-column align-items-center">
                    <i class="fas fa-funnel-dollar card-icon-lg mb-3" style="color: #6610f2;"></i>
                    <h5 class="card-title">تقرير إجمالي الربح</h5>
                    <p class="card-text flex-grow-1 small">تحليل ربحية المبيعات (قبل المصاريف).</p>
                    <a href="<?php echo BASE_URL; ?>admin/gross_profit_report.php" class="btn mt-auto stretched-link" style="background-color: #6610f2; color:white;">الدخول للقسم</a>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-lg-4 mb-4">
            <div class="card h-100 shadow-sm hover-shadow-lg">
                <div class="card-body d-flex flex-column align-items-center">
                    <i class="fas fa-balance-scale card-icon-lg mb-3" style="color: #20c997;"></i>
                    <h5 class="card-title">تقرير صافي الربح</h5>
                    <p class="card-text flex-grow-1 small">تحليل الربحية بعد خصم المصاريف.</p>
                    <a href="<?php echo BASE_URL; ?>admin/net_profit_report.php" class="btn mt-auto stretched-link" style="background-color: #20c997; color:white;">الدخول للقسم</a>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-lg-4 mb-4">
            <div class="card h-100 shadow-sm hover-shadow-lg">
                <div class="card-body d-flex flex-column align-items-center">
                    <i class="fas fa-user-plus card-icon-lg mb-3" style="color: #e83e8c;"></i>
                    <h5 class="card-title">إعدادات التسجيل</h5>
                    <p class="card-text flex-grow-1 small">التحكم في فتح وغلق تسجيل المستخدمين.</p>
                    <a href="<?php echo BASE_URL; ?>admin/registration_settings.php" class="btn mt-auto stretched-link" style="background-color: #e83e8c; color:white;">الدخول للقسم</a>
                </div>
            </div>
        </div>
         <div class="col-md-6 col-lg-4 mb-4">
            <div class="card h-100 shadow-sm hover-shadow-lg">
                <div class="card-body d-flex flex-column align-items-center">
                    <i class="fas fa-cogs card-icon-lg text-muted mb-3"></i>
                    <h5 class="card-title">إعدادات الموقع العامة</h5>
                    <p class="card-text flex-grow-1 small">تغيير اسم الموقع والوصف العام (قريباً).</p>
                    <a href="#" class="btn btn-secondary mt-auto disabled stretched-link">الدخول للقسم (قريباً)</a>
                </div>
            </div>
        </div>
    </div> </div> <style>
/* ... (أنماط CSS السابقة تبقى كما هي) ... */
.text-gray-300 { color: #dddfeb !important; }
.text-gray-800 { color: #5a5c69 !important; }
.border-start.border-teal { border-left-color: #20c997 !important; } /* Teal for Net Profit */
.text-teal { color: #20c997 !important; }
.border-start.border-purple { border-left-color: #6610f2 !important; } /* Purple for Gross Profit */
.text-purple { color: #6610f2 !important; }
.border-start.border-orange { border-left-color: #fd7e14 !important; } /* Orange for Pending Sales Invoices */
.text-orange { color: #fd7e14 !important; }



@media (min-width: 1200px) { /* For xl screens and up, we want 6 cards in a row */
    .row > .col-xl-2 {
        flex: 0 0 auto;
        width: 16.66666667%;
    }
}
/* Bootstrap's col-md-4 will make 3 cards per row on medium screens */
/* Bootstrap's col-6 will make 2 cards per row on small screens */

</style>

<?php require_once BASE_DIR . 'partials/footer.php'; ?>