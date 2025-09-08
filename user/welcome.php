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
$total_low_stock_items = 0;
$low_stock_preview = [];
$res = $conn->query("SELECT COUNT(*) AS c FROM products WHERE reorder_level > 0 AND current_stock <= reorder_level");
$total_low_stock_items = intval($res->fetch_assoc()['c'] ?? 0);

$res = $conn->query("SELECT id, product_code, name, current_stock, reorder_level 
                     FROM products WHERE reorder_level > 0 AND current_stock <= reorder_level 
                     ORDER BY (reorder_level - current_stock) DESC LIMIT 50");
while ($row = $res->fetch_assoc()) $low_stock_preview[] = $row;
?>

<style>
/* ===== Scoped improvements for dashboard stats (inside .welcome) ===== */
.theme-toggle {
            background: var(--surface);
            border: 1px solid var(--border);
            color: var(--text);
            padding: 8px 16px;
            border-radius: var(--radius-sm);
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all var(--fast);
        }

        .theme-toggle:hover {
            background: var(--surface-2);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }

        .stat-card {
            background: var(--surface);
            border-radius: var(--radius);
            padding: 20px;
            box-shadow: var(--shadow-1);
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: all var(--normal);
            border: 1px solid transparent;
            position: relative;
            overflow: hidden;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-2);
            border-color: var(--primary);
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 100%;
            height: 4px;
            background: var(--grad-1);
            opacity: 0;
            transition: opacity var(--normal);
        }

        .stat-card:hover::before {
            opacity: 1;
        }

        .stat-left {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }

        .icon-primary {
            background: linear-gradient(135deg, rgba(11, 132, 255, 0.15), rgba(11, 132, 255, 0.25));
            color: var(--primary);
        }

        .icon-secondary {
            background: linear-gradient(135deg, rgba(124, 58, 237, 0.15), rgba(124, 58, 237, 0.25));
            color: var(--accent);
        }

        .icon-success {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.15), rgba(16, 185, 129, 0.25));
            color: var(--teal);
        }

        .icon-warning {
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.15), rgba(245, 158, 11, 0.25));
            color: var(--amber);
        }

        .icon-danger {
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.15), rgba(239, 68, 68, 0.25));
            color: var(--rose);
        }

        .stat-body {
            display: flex;
            flex-direction: column;
        }

        .label {
            font-size: 14px;
            color: var(--muted);
            margin-bottom: 5px;
        }

        .value {
            font-size: 24px;
            font-weight: 700;
            color: var(--text);
        }

        .view-page a {
            color: var(--primary);
            text-decoration: none;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 5px;
            transition: color var(--fast);
        }

        .view-page a:hover {
            color: var(--primary-600);
        }

        .stat-card.low {
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% { box-shadow: var(--shadow-1); }
            50% { box-shadow: 0 0 0 4px rgba(239, 68, 68, 0.2); }
            100% { box-shadow: var(--shadow-1); }
        }

        /* التكيف مع الشاشات الصغيرة */
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .stat-card {
                padding: 15px;
            }
            
            .stat-icon {
                width: 50px;
                height: 50px;
                font-size: 20px;
            }
            
            .value {
                font-size: 20px;
            }
        }

/* low table styling (scoped) */
.welcome .low-table {
  width:100%;
  border-collapse:collapse;
  margin-top:12px;
  font-size:14px;
}
.welcome .low-table th, .welcome .low-table td {
  padding:10px 12px;
  text-align:right;
  border-bottom:1px solid rgba(2,6,23,0.06);
}
.welcome .low-table tr.low-row {
  background: linear-gradient(90deg, rgba(255,240,240,0.9), rgba(255,248,248,0.95));
  color: #b91c1c;
  font-weight:700;
}

.welcome .view-page a{
    color: var(--text) !important;
    /* background-color: #b91c1c; */
}
/* responsive: small screens stack content vertically (nice spacing) */
@media (max-width: 720px) {
  .welcome .stat-card { min-height: auto; padding:12px; }
  .welcome .stat-icon { width:56px; height:56px; font-size:20px; border-radius:12px; }
  .welcome .stat-action { width:100%; justify-content:center; }
}

 </style>
<div class="container welcome mt-5">
    <!-- الوصول السريع -->
    <div class="card dashboard-card fade-in">
        <div class="card-header">الوصول السريع</div>
        <div class="card-body">
            <div class="d-flex flex-wrap"> <a href="<?php echo BASE_URL; ?>invoices_out/create_invoice.php" class="btn btn-primary btn-action"> <i class="fas fa-plus"></i> إضافة فاتورة بيع </a> <a href="<?php echo BASE_URL; ?>admin/manage_customer.php" class="btn btn-success btn-action"> <i class="fas fa-user-plus"></i> إضافة عميل </a> <a href="<?php echo BASE_URL; ?>admin/manage_products.php" class="btn btn-info btn-action"> <i class="fas fa-box"></i> إضافة منتج </a> <a href="<?php echo BASE_URL; ?>admin/pending_invoices.php" class="btn btn-warning btn-action"> <i class="fas fa-file-invoice"></i> الفواتير غير المسلمة </a> <a href="<?php echo BASE_URL; ?>admin/net_profit_report.php" class="btn btn-danger btn-action"> <i class="fas fa-chart-pie"></i> تقرير الأرباح </a> </div>
        </div>
    </div>


    <div class="stats-grid">
        <!-- إجمالي العملاء -->
        <div class="stat-card">
            <div class="stat-left">
                <div class="stat-icon icon-primary"><i class="fas fa-user"></i></div>
                <div class="stat-body">
                    <div class="label">إجمالي العملاء</div>
                    <div class="value"><?php echo number_format($total_customers); ?></div>
                </div>
            </div>
            <div class="view-page"><a href="<?php echo BASE_URL; ?>admin/manage_customer.php" class="small text-muted">عرض</a></div>
        </div>

        <!-- منتجات منخفضة الرصيد -->
        <div class="stat-card <?php echo ($total_low_stock_items > 0 ? 'low' : ''); ?>">
            <div class="stat-left">
                <div class="stat-icon <?php echo ($total_low_stock_items > 0 ? 'icon-danger' : 'icon-secondary'); ?>"><i class="fas fa-battery-quarter"></i></div>
                <div class="stat-body">
                    <div class="label">منتجات منخفضة الرصيد</div>
                    <div class="value"><?php echo number_format($total_low_stock_items); ?></div>
                  
                </div>
            </div>
            <div class="view-page"><a href="<?php echo BASE_URL; ?>admin/low_stock_report.php" class="small text-muted">تقرير</a></div>
        </div>

        <!-- مبيعات الشهر -->
        <div class="stat-card">
            <div class="stat-left">
                <div class="stat-icon icon-success"><i class="fas fa-money-bill-wave"></i></div>
                <div class="stat-body">
                    <div class="label">مبيعات الشهر</div>
                    <div class="value"><?php echo number_format($current_month_sales, 2); ?> ج.م</div>
                </div>
            </div>
            <div class="view-page"><a href="<?php echo BASE_URL; ?>admin/sales_report_period.php" class="small text-muted">تفاصيل</a></div>
        </div>

        <!-- فواتير لم تسلم -->
        <div class="stat-card">
            <div class="stat-left">
                <div class="stat-icon icon-warning"><i class="fas fa-truck-loading"></i></div>
                <div class="stat-body">
                    <div class="label">فواتير لم تُسلم</div>
                    <div class="value"><?php echo number_format($total_pending_sales_invoices); ?></div>
                </div>
            </div>
            <div class="view-page"><a href="<?php echo BASE_URL; ?>admin/pending_invoices.php" class="small text-muted">عرض</a></div>
        </div>

        <!-- مصاريف الشهر -->
        <div class="stat-card">
            <div class="stat-left">
                <div class="stat-icon icon-danger"><i class="fas fa-receipt"></i></div>
                <div class="stat-body">
                    <div class="label">مصاريف الشهر</div>
                    <div class="value"><?php echo number_format($current_month_expenses, 2); ?> ج.م</div>
                </div>
            </div>
            <div class="view-page"><a href="<?php echo BASE_URL; ?>admin/manage_expenses.php" class="small text-muted">عرض</a></div>
        </div>

        <!-- إجمالي المنتجات -->
        <div class="stat-card">
            <div class="stat-left">
                <div class="stat-icon icon-primary"><i class="fas fa-box"></i></div>
                <div class="stat-body">
                    <div class="label">إجمالي المنتجات</div>
                    <div class="value"><?php echo number_format($total_products); ?></div>
                </div>
            </div>
            <div class="view-page"><a href="<?php echo BASE_URL; ?>admin/manage_products.php" class="small text-muted">عرض</a></div>
        </div>
    </div>

    <!-- جدول منتجات منخفضة الرصيد -->
    <div style="margin-top:18px">
        <div style="background:var(--surface); padding:14px; border-radius:var(--radius); box-shadow:var(--shadow-1); border:1px solid var(--border);">
            <h3 style="margin:0 0 10px 0">تفاصيل المنتجات منخفضة الرصيد</h3>
            <?php if ($total_low_stock_items > 0): ?>
                <table class="low-table" role="table">
                    <thead>
                        <tr>
                            <th>المنتج</th>
                            <th>الكود</th>
                            <th>الرصيد الحالي</th>
                            <th>حد إعادة الطلب</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($low_stock_preview as $row): ?>
                            <tr class="low-row">
                                <td><?php echo e($row['name']); ?></td>
                                <td><?php echo e($row['product_code']); ?></td>
                                <td><?php echo e($row['current_stock']); ?></td>
                                <td><?php echo e($row['reorder_level']); ?></td>
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
                        <i class="fas fa-funnel-dollar me-2"></i> صافي الارباح
                    </a>
                    <a href="<?php echo BASE_URL; ?>admin/gross_profit_report.php" class="btn btn-outline-danger text-start">
                        <i class="fas fa-funnel-dollar me-2"></i> اجمالي الارباح
                    </a>
                    <a href="<?php echo BASE_URL; ?>admin/stock_valuation_report.php" class="btn btn-outline-danger text-start">
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