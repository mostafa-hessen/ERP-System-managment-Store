<?php
$page_title = "أهلاً بك";
$page_css   = "welcome.css";
require_once dirname(__DIR__) . '/config.php';
require_once BASE_DIR . 'partials/session_user.php';
require_once BASE_DIR . 'partials/header.php';
require_once BASE_DIR . 'partials/sidebar.php';
?>

    <div class="container-fluid mt-4" >

        <!-- الوصول السريع -->
        <div class="card dashboard-card fade-in">
            <div class="card-header">الوصول السريع</div>
            <div class="card-body">
                <div class="d-flex flex-wrap">
                    <a href="<?php echo BASE_URL; ?>admin/manage_purchase_invoices.php" class="btn btn-primary btn-action">
                        <i class="fas fa-plus"></i> إضافة فاتورة بيع
                    </a>
                    <a href="<?php echo BASE_URL; ?>admin/manage_customer.php" class="btn btn-success btn-action">
                        <i class="fas fa-user-plus"></i> إضافة عميل
                    </a>
                    <a href="<?php echo BASE_URL; ?>admin/manage_products.php" class="btn btn-info btn-action">
                        <i class="fas fa-box"></i> إضافة منتج
                    </a>
                    <a href="<?php echo BASE_URL; ?>admin/pending_invoices.php" class="btn btn-warning btn-action">
                        <i class="fas fa-file-invoice"></i> الفواتير غير المسلمة
                    </a>
                    <a href="<?php echo BASE_URL; ?>admin/net_profit_report.php" class="btn btn-danger btn-action">
                        <i class="fas fa-chart-pie"></i> تقرير الأرباح
                    </a>
                </div>
            </div>
        </div>

        <!-- الإحصائيات السريعة -->
        <div class="stats-grid">
            <div class="stat-card category-sales">
                <i class="fas fa-shopping-cart"></i>
                <div class="number">5,246</div>
                <div class="label">إجمالي المبيعات</div>
            </div>
            <div class="stat-card category-inventory">
                <i class="fas fa-boxes"></i>
                <div class="number">128</div>
                <div class="label">المنتجات</div>
            </div>
            <div class="stat-card category-purchases">
                <i class="fas fa-truck-loading"></i>
                <div class="number">12</div>
                <div class="label">فواتير غير مسلمة</div>
            </div>
            <div class="stat-card category-finance">
                <i class="fas fa-hand-holding-usd"></i>
                <div class="number">8,540</div>
                <div class="label">صافي الأرباح</div>
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
                        <a href="<?php echo BASE_URL; ?>admin/manage_purchase_invoices.php" class="btn btn-outline-primary text-start">
                            <i class="fas fa-cash-register me-2"></i> فواتير البيع
                        </a>
                        <a href="<?php echo BASE_URL; ?>admin/manage_customer.php" class="btn btn-outline-primary text-start">
                            <i class="fas fa-users me-2"></i> إدارة العملاء
                        </a>
                        <a href="<?php echo BASE_URL; ?>admin/sales_report_period.php" class="btn btn-outline-primary text-start">
                            <i class="fas fa-list-alt me-2"></i> تقارير المبيعات
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
                        <a href="<?php echo BASE_URL; ?>admin/edit_product.php" class="btn btn-outline-success text-start">
                            <i class="fas fa-edit me-2"></i> تعديل الأرصدة
                        </a>
                        <a href="<?php echo BASE_URL; ?>admin/stock_report.php" class="btn btn-outline-success text-start">
                            <i class="fas fa-chart-bar me-2"></i> تقارير المخزون
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
                        <a href="<?php echo BASE_URL; ?>admin/top_selling_products_report.php" class="btn btn-outline-warning text-start">
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
                            <i class="fas fa-funnel-dollar me-2"></i> تقرير الأرباح
                        </a>
                        <a href="<?php echo BASE_URL; ?>admin/stock_valuation_report.php" class="btn btn-outline-danger text-start">
                            <i class="fas fa-balance-scale me-2"></i> تقرير المصروفات
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
                        <a href="<?php echo BASE_URL; ?>admin/manage_expense_categories.php" class="btn btn-outline-secondary text-start">
                            <i class="fas fa-sliders-h me-2"></i> التخصيصات
                        </a>
                    </div>
                </div>
            </div>

        </div>
</div>

<?php require_once BASE_DIR . 'partials/footer.php'; ?>
