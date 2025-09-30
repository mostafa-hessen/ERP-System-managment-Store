<?php
// reports/net_profit_summary.responsive.php
// تصميم مُحدّث: صفحات ملخّص الفواتير + جدول المصروفات + حساب صافي الربح = الإيرادات - تكلفة البضاعة - المصروفات
$page_title = "تقرير صافي الربح — ملخص الفواتير";
require_once dirname(__DIR__) . '/config.php';
require_once BASE_DIR . 'partials/session_admin.php'; // صلاحيات المدير فقط

if (!isset($conn) || !$conn) {
    echo "DB connection error";
    exit;
}
function e($s)
{
    return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// الافتراضي: اليوم (لو المستخدم لم يحدد)
$start_date_filter = isset($_GET['start_date']) && trim($_GET['start_date']) !== '' ? trim($_GET['start_date']) : date('Y-m-d');
$end_date_filter   = isset($_GET['end_date']) && trim($_GET['end_date']) !== '' ? trim($_GET['end_date']) : date('Y-m-d');

$message = '';
$summaries = [];
$expenses_list = [];
$report_generated = false;

$grand_total_revenue = 0.0;
$grand_total_cost = 0.0;
$total_expenses = 0.0;
$net_profit = 0.0;

// validate and produce report
if (!empty($start_date_filter) && !empty($end_date_filter)) {
    if (DateTime::createFromFormat('Y-m-d', $start_date_filter) === false || DateTime::createFromFormat('Y-m-d', $end_date_filter) === false) {
        $message = "<div class='alert alert-danger'>صيغة التاريخ غير صحيحة. استخدم YYYY-MM-DD.</div>";
    } elseif ($start_date_filter > $end_date_filter) {
        $message = "<div class='alert alert-danger'>تاريخ البدء لا يمكن أن يكون بعد تاريخ الانتهاء.</div>";
    } else {
        $report_generated = true;
        $start_sql = $start_date_filter . " 00:00:00";
        $end_sql   = $end_date_filter . " 23:59:59";
        $start_sql_date = $start_date_filter; // expenses use DATE field
        $end_sql_date = $end_date_filter;

        // 1) إجماليات: إيرادات و تكلفة (من بنود الفواتير المسلمة)
        $totals_sql = "
            SELECT
              COALESCE(SUM(ioi.total_price),0) AS total_revenue,
              COALESCE(SUM(ioi.quantity * COALESCE(ioi.cost_price_per_unit, p.cost_price, 0)),0) AS total_cost
            FROM invoices_out io
            JOIN invoice_out_items ioi ON ioi.invoice_out_id = io.id
            LEFT JOIN products p ON p.id = ioi.product_id
            WHERE io.delivered = 'yes'
              AND io.created_at BETWEEN ? AND ?
        ";
        if ($st = $conn->prepare($totals_sql)) {
            $st->bind_param('ss', $start_sql, $end_sql);
            if ($st->execute()) {
                $r = $st->get_result()->fetch_assoc();
                $grand_total_revenue = floatval($r['total_revenue'] ?? 0);
                $grand_total_cost = floatval($r['total_cost'] ?? 0);
            } else {
                $message .= "<div class='alert alert-danger'>فشل حساب الإجماليات: " . e($st->error) . "</div>";
            }
            $st->close();
        } else {
            $message .= "<div class='alert alert-danger'>فشل تحضير استعلام الإجماليات: " . e($conn->error) . "</div>";
        }

        // 2) ملخّص الفواتير (بدون عرض البنود)
        $sql = "
          SELECT
            io.id AS invoice_id,
            io.created_at AS invoice_created_at,
            COALESCE(c.name, '') AS customer_name,
            COALESCE(SUM(ioi.total_price),0) AS total_sold,
            COALESCE(SUM(ioi.quantity * COALESCE(ioi.cost_price_per_unit, p.cost_price, 0)),0) AS total_cost,
            COALESCE(SUM(ioi.quantity),0) AS total_qty
          FROM invoices_out io
          JOIN invoice_out_items ioi ON ioi.invoice_out_id = io.id
          LEFT JOIN products p ON p.id = ioi.product_id
          LEFT JOIN customers c ON c.id = io.customer_id
          WHERE io.delivered = 'yes'
            AND io.created_at BETWEEN ? AND ?
          GROUP BY io.id
          ORDER BY io.created_at DESC, io.id DESC
        ";
        if ($stmt = $conn->prepare($sql)) {
            $stmt->bind_param('ss', $start_sql, $end_sql);
            if ($stmt->execute()) {
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $row['total_sold'] = floatval($row['total_sold']);
                    $row['total_cost'] = floatval($row['total_cost']);
                    $row['total_qty']  = floatval($row['total_qty']);
                    $row['profit'] = $row['total_sold'] - $row['total_cost'];
                    $summaries[] = $row;
                }
            } else {
                $message .= "<div class='alert alert-danger'>خطأ في استعلام ملخّص الفواتير: " . e($stmt->error) . "</div>";
            }
            $stmt->close();
        }

        // 3) جلب المصروفات خلال الفترة (expense_date هو DATE)
        $sql_expenses = "SELECT id, expense_date, description, amount, category_id, notes FROM expenses WHERE expense_date BETWEEN ? AND ? ORDER BY expense_date ASC, id ASC";
        if ($sx = $conn->prepare($sql_expenses)) {
            $sx->bind_param('ss', $start_sql_date, $end_sql_date);
            if ($sx->execute()) {
                $rx = $sx->get_result();
                while ($ex = $rx->fetch_assoc()) {
                    $ex['amount'] = floatval($ex['amount']);
                    $expenses_list[] = $ex;
                }
            } else {
                $message .= "<div class='alert alert-warning'>خطأ في جلب المصروفات: " . e($sx->error) . "</div>";
            }
            $sx->close();
        }

        // 4) مجموع المصروفات
        $sql_exp_sum = "SELECT COALESCE(SUM(amount),0) AS total_expenses FROM expenses WHERE expense_date BETWEEN ? AND ?";
        if ($sx2 = $conn->prepare($sql_exp_sum)) {
            $sx2->bind_param('ss', $start_sql_date, $end_sql_date);
            if ($sx2->execute()) {
                $rr = $sx2->get_result()->fetch_assoc();
                $total_expenses = floatval($rr['total_expenses'] ?? 0);
            }
            $sx2->close();
        }

        // 5) صافي الربح النهائي
        $net_profit = $grand_total_revenue - $grand_total_cost - $total_expenses;
    }
}

require_once BASE_DIR . 'partials/header.php';
require_once BASE_DIR . 'partials/sidebar.php';
?>

<style>
    /* container and header */
    .page-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        margin-bottom: 12px;
    }

    .page-header h3 {
        margin: 0;
        font-size: 1.25rem;
        color: var(--text);
    }

    .small-muted {
        color: var(--text-soft);
    }

    /* filters */
    .card.filter-card {
        background: var(--surface);
        border-radius: 12px;
        padding: 12px;
        margin-bottom: 16px;
        box-shadow: var(--shadow-1);
    }

    .form-row {
        display: flex;
        gap: 12px;
        flex-wrap: wrap;
        align-items: flex-end;
    }

    /* quick periods buttons */
    .periods {
        display: flex;
        gap: 8px;
        align-items: center;
        margin-right: 8px;
    }

    .periods button {
        background: transparent;
        border: 1px solid var(--border);
        padding: 8px 10px;
        border-radius: 8px;
        cursor: pointer;
        color: var(--text);
    }

    .periods button.active {
        background: var(--primary);
        color: #fff;
        box-shadow: var(--shadow-2);
        transform: translateY(-2px);
    }

    /* summary cards */
    .summary-cards {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 16px;
        margin-bottom: 20px;
    }

    .summary-card {
        background: var(--surface);
        border-radius: var(--radius);
        padding: 16px 18px;
        box-shadow: var(--shadow-1);
        position: relative;
        overflow: hidden;
        transition: transform .18s ease, box-shadow .18s ease;
    }

    .summary-card:hover {
        transform: translateY(-6px);
        box-shadow: var(--shadow-2);
    }

    .summary-card .title {
        color: var(--muted);
        font-size: 0.95rem;
        margin-bottom: 8px;
    }

    .summary-card .value {
        font-size: 1.75rem;
        font-weight: 800;
        color: var(--text);
    }

    .summary-card .sub {
        color: var(--text-soft);
        margin-top: 8px;
        font-size: 0.9rem;
    }

    .summary-card::before {
        content: '';
        position: absolute;
        right: -30px;
        top: -30px;
        width: 160px;
        height: 160px;
        opacity: 0.12;
        transform: rotate(20deg);
    }

    .card-revenue::before {
        background: var(--grad-1) !important;
    }

    .card-cost::before {
        background: var(--grad-3);
    }

    .card-profit::before {
        background: var(--grad-2);
    }

    .card-expenses::before {
        background: var(--grad-4);
    }

    .currency-badge {
        display: inline-block;
        margin-left: 8px;
        font-weight: 700;
        color: var(--muted);
        font-size: 0.85rem;
    }


    .badge-profit {
        font-weight: 700;
        padding: 6px 8px;
        border-radius: 6px;
        display: inline-block;
    }

    .badge-profit.positive {
        background: rgba(16, 185, 129, 0.08);
        color: #075928;
        border: 1px solid rgba(16, 185, 129, 0.12);
    }

    .badge-profit.negative {
        background: rgba(239, 68, 68, 0.06);
        color: #7f1d1d;
        border: 1px solid rgba(239, 68, 68, 0.12);
    }

    /* modal lite */
    .modal-backdrop-lite {
        position: fixed;
        left: 0;
        top: 0;
        right: 0;
        bottom: 0;
        background: rgba(2, 6, 23, 0.5);
        display: none;
        align-items: center;
        justify-content: center;
        z-index: 9999;
        padding: 16px;
    }

    .modal-card {
        background: var(--surface);
        border-radius: 12px;
        max-width: 980px;
        width: 100%;
        max-height: 85vh;
        overflow: auto;
        padding: 18px;
        box-shadow: var(--shadow-2);
    }

    /* responsive */
    @media (max-width:900px) {
        .summary-cards {
            grid-template-columns: repeat(2, 1fr);
        }

        .table {
            min-width: 700px;
        }
    }

    @media (max-width:640px) {
        .summary-cards {
            grid-template-columns: 1fr;
        }

        .page-header {
            flex-direction: column;
            align-items: flex-start;
            gap: 6px;
        }

        .table {
            min-width: 600px;
        }
    }

    .layout-grid {
        display: grid;
        grid-template-columns: 2fr 1fr;
        gap: 24px;
        align-items: start;
    }

    @media (max-width:900px) {
        .layout-grid {
            grid-template-columns: 1fr;
            gap: 18px;
        }
    }
</style>

<div class="container-fluid">
    <div class="page-header">
        <h3><i class="fas fa-balance-scale-right"></i> تقرير صافي الربح — ملخص</h3>
        <div>
            <div class="small-muted">الحساب: الإيرادات − تكلفة البضاعة − المصروفات</div>
            <div style="margin-top:6px;text-align:right">
                <a href="<?php echo htmlspecialchars(BASE_URL . 'user/welcome.php'); ?>" class="btn btn-sm btn-light">← العودة</a>
                <button id="printBtn" class="btn btn-sm btn-primary">طباعة</button>
            </div>
        </div>
    </div>

    <?php echo $message; ?>

    <div class="card filter-card">
        <div class="card-body p-2">
            <form id="filterForm" method="get" class="d-flex align-items-end" style="gap:12px;flex-wrap:wrap">
                <div class="periods" role="tablist" aria-label="فترات سريعة">
                    <button type="button" data-period="day" id="btnDay">يوم</button>
                    <button type="button" data-period="week" id="btnWeek">أسبوع</button>
                    <button type="button" data-period="month" id="btnMonth">شهر</button>
                    <button type="button" data-period="custom" id="btnCustom">مخصص</button>
                </div>
                <input type="hidden" name="period" id="periodInput" value="<?php echo e($_GET['period'] ?? 'day'); ?>">

                <div>
                    <label class="form-label">من تاريخ</label>
                    <input type="date" name="start_date" id="start_date" class="form-control" value="<?php echo e($start_date_filter); ?>" required>
                </div>
                <div>
                    <label class="form-label">إلى تاريخ</label>
                    <input type="date" name="end_date" id="end_date" class="form-control" value="<?php echo e($end_date_filter); ?>" required>
                </div>
                <div style="display:flex;gap:8px;align-items:center">
                    <button type="submit" class="btn btn-primary">عرض</button>
                    <button type="button" id="todayQuick" class="btn btn-outline-secondary">اليوم</button>
                </div>
                <div style="margin-left:auto;color:var(--muted);font-size:0.9rem;">عرض الفواتير المسلمة فقط (delivered = yes)</div>
            </form>
        </div>
    </div>

    <?php if ($report_generated): ?>
        <div class="summary-cards">
            <div class="summary-card card-revenue">
                <div class="title">إجمالي الإيرادات</div>
                <div class="value"><?php echo number_format($grand_total_revenue, 2); ?> <span class="currency-badge">ج.م</span></div>
            </div>
            <div class="summary-card card-cost">
                <div class="title">تكلفة البضاعة المباعة</div>
                <div class="value"><?php echo number_format($grand_total_cost, 2); ?> <span class="currency-badge">ج.م</span></div>
            </div>
            <div class="summary-card card-expenses">
                <div class="title">المصروفات خلال الفترة</div>
                <div class="value"><?php echo number_format($total_expenses, 2); ?> <span class="currency-badge">ج.م</span></div>
            </div>

            <div class="summary-card card-profit">
                <div class="title">صافي الربح (الإيرادات − التكلفة − المصروفات)</div>
                <?php
                // حساب النسبة المئوية للربح أو الخسارة من الإيرادات
                $profit_percent = $grand_total_revenue > 0 ? ($net_profit / $grand_total_revenue) * 100 : 0;
                $is_profit = $net_profit >= 0;
                $profit_color = $is_profit ? '#075928' : '#b91c1c';
                $percent_bg = $is_profit ? 'rgba(16,185,129,0.08)' : 'rgba(239,68,68,0.08)';
                $percent_border = $is_profit ? '1px solid rgba(16,185,129,0.12)' : '1px solid rgba(239,68,68,0.12)';
                ?>
                <div class="value" style="color:<?php echo $is_profit ? '#075928' : '#b91c1c'; ?>">
                    <?php echo number_format($net_profit, 2); ?> <span class="currency-badge">ج.م</span>
                </div>
                <div style="margin-top:8px;">
                    <span style="
                        display:inline-block;
                        padding:6px 12px;
                        border-radius:6px;
                        font-weight:700;
                        background:<?php echo $percent_bg; ?>;
                        color:<?php echo $profit_color; ?>;
                        border:<?php echo $percent_border; ?>;
                        font-size:1rem; ">
                        <?php
                        if ($is_profit) {
                            echo 'نسبة الربح: ' . number_format($profit_percent, 2) . '%';
                        } else {
                            echo 'نسبة الخسارة: ' . number_format(abs($profit_percent), 2) . '%';
                        }
                        ?>
                    </span>
                </div>
            </div>
        </div>

        <div class="layout-grid">
            <div>
                <div class="custom-table-wrapper">
                    <table class="custom-table" id="reportTable">
                        <thead>
                            <tr>
                                <th style="width:90px"># فاتورة</th>
                                <th style="width:160px">التاريخ</th>
                                <th>العميل</th>
                                <th style="width:130px" class="text-end">إجمالي بيع</th>
                                <th style="width:140px" class="text-end">إجمالي تكلفة</th>
                                <th style="width:120px" class="text-end">الربح</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($summaries as $s):
                                $profit_class = $s['profit'] >= 0 ? 'positive' : 'negative';
                            ?>
                                <tr>
                                    <td><strong>#<?php echo intval($s['invoice_id']); ?></strong></td>
                                    <td><?php echo e($s['invoice_created_at']); ?></td>
                                    <td><?php echo e($s['customer_name'] ?: 'عميل غير محدد'); ?></td>
                                    <td class="text-end"><?php echo number_format($s['total_sold'], 2); ?> ج.م</td>
                                    <td class="text-end"><?php echo number_format($s['total_cost'], 2); ?> ج.م</td>
                                    <td class="text-end"><span class="badge-profit <?php echo $profit_class; ?>"><?php echo number_format($s['profit'], 2); ?> ج.م</span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <?php
                            $grand_sold = 0;
                            $grand_cost = 0;
                            foreach ($summaries as $ss) {
                                $grand_sold += $ss['total_sold'];
                                $grand_cost += $ss['total_cost'];
                            }
                            $grand_profit = $grand_sold - $grand_cost;
                            ?>
                            <tr>
                                <td colspan="3" style="text-align:right"><strong>إجمالي الفواتير المعروضة:</strong></td>
                                <td class="text-end"><strong><?php echo number_format($grand_sold, 2); ?> ج.م</strong></td>
                                <td class="text-end"><strong><?php echo number_format($grand_cost, 2); ?> ج.م</strong></td>
                                <td class="text-end"><strong><?php echo number_format($grand_profit, 2); ?> ج.م</strong></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <div>
                <div class="custom-table-wrapper" style="padding:12px;">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                        <div><strong>المصروفات خلال الفترة</strong>
                            <div class="small-muted">من <?php echo e($start_date_filter); ?> إلى <?php echo e($end_date_filter); ?></div>
                        </div>
                        <div style="font-weight:700"><?php echo number_format($total_expenses, 2); ?> ج.م</div>
                    </div>

                    <?php if (empty($expenses_list)): ?>
                        <div class="alert alert-info">لا توجد مصروفات مسجّلة خلال الفترة.</div>
                    <?php else: ?>
                        <div style="max-height:380px;overflow:auto;">
                            <table class="custom-table" id="expensesTable">
                                <thead>
                                    <tr>
                                        <th style="width:120px">التاريخ</th>
                                        <th>الوصف</th>
                                        <th style="width:110px" class="text-end">المبلغ</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($expenses_list as $ex): ?>
                                        <tr>
                                            <td><?php echo e($ex['expense_date']); ?></td>
                                            <td><?php echo e($ex['description']); ?></td>
                                            <td class="text-end"><?php echo number_format($ex['amount'], 2); ?> ج.م</td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot>
                                    <tr>
                                        <td colspan="2" style="text-align:right"><strong>الإجمالي:</strong></td>
                                        <td class="text-end"><strong><?php echo number_format($total_expenses, 2); ?> ج.م</strong></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    <?php endif; ?>

                </div>

                <div class="summary-card" style="margin-top:12px;padding:12px;">
                    <div class="small-muted"><strong>ملاحظات:</strong></div>
                    <ul class="small-muted" style="margin:6px 0 0 0;padding-left:18px;">
                        <li>الإيرادات والتكاليف مأخوذة من بنود الفاتورة المسلمة.</li>

                    </ul>
                </div>
            </div>
        </div>

    <?php endif; ?>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        function qs(s) {
            return document.querySelector(s);
        }

        function qsa(s) {
            return Array.from(document.querySelectorAll(s));
        }

        function escapeHtml(s) {
            if (!s && s !== 0) return '';
            return String(s).replace(/[&<>"']/g, function(m) {
                return ({
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#39;'
                })[m];
            });
        }

        const btnDay = qs('#btnDay'),
            btnWeek = qs('#btnWeek'),
            btnMonth = qs('#btnMonth'),
            btnCustom = qs('#btnCustom');
        const startIn = qs('#start_date'),
            endIn = qs('#end_date'),
            periodInput = qs('#periodInput'),
            filterForm = qs('#filterForm');
        const todayQuick = qs('#todayQuick');

        function formatDate(d) {
            return d.toISOString().slice(0, 10);
        }
        const now = new Date();
        if (!startIn.value) startIn.value = formatDate(now);
        if (!endIn.value) endIn.value = formatDate(now);

        function setPeriod(p, autoSubmit = false) {
            [btnDay, btnWeek, btnMonth, btnCustom].forEach(b => b.classList.remove('active'));
            const today = new Date();
            let s = new Date(),
                e = new Date();
            if (p === 'day') {
                s = new Date(today);
                e = new Date(today);
                btnDay.classList.add('active');
            } else if (p === 'week') {
                const day = today.getDay() || 7;
                s = new Date(today);
                s.setDate(today.getDate() - (day - 1));
                e = new Date(s);
                e.setDate(s.getDate() + 6);
                btnWeek.classList.add('active');
            } else if (p === 'month') {
                s = new Date(today.getFullYear(), today.getMonth(), 1);
                e = new Date(today.getFullYear(), today.getMonth() + 1, 0);
                btnMonth.classList.add('active');
            } else {
                btnCustom.classList.add('active');
                periodInput.value = 'custom';
                return;
            }
            startIn.value = formatDate(s);
            endIn.value = formatDate(e);
            periodInput.value = p;
            if (autoSubmit) setTimeout(() => filterForm.submit(), 120);
        }
        const initialPeriod = '<?php echo e($_GET['period'] ?? 'day'); ?>';
        setPeriod(initialPeriod || 'day', false);
        btnDay?.addEventListener('click', () => setPeriod('day', true));
        btnWeek?.addEventListener('click', () => setPeriod('week', true));
        btnMonth?.addEventListener('click', () => setPeriod('month', true));
        btnCustom?.addEventListener('click', () => setPeriod('custom', false));
        todayQuick?.addEventListener('click', () => setPeriod('day', true));

        // Print: include both tables
        qs('#printBtn')?.addEventListener('click', function() {
            const startVal = startIn.value || '';
            const endVal = endIn.value || '';
            let html = '<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><title>طباعة تقرير صافي الربح</title>';
            html += '<style>body{font-family:Arial;padding:18px;color:#111} h2{margin:0 0 8px} table{width:100%;border-collapse:collapse;margin-top:8px} th,td{border:1px solid #ddd;padding:8px;text-align:right} thead th{background:#f6f8fb} tfoot td{font-weight:700}</style>';
            html += '</head><body>';
            html += '<h2>تقرير صافي الربح</h2><div>الفترة: <strong>' + escapeHtml(startVal) + ' → ' + escapeHtml(endVal) + '</strong></div>';

            // invoices
            html += '<h3 style="margin-top:12px">ملخص الفواتير</h3>';
            const invRows = Array.from(document.querySelectorAll('#reportTable tbody tr'));
            if (invRows.length === 0) html += '<div>لا توجد فواتير لعرضها.</div>';
            else {
                html += '<table><thead><tr><th>#</th><th>رقم الفاتورة</th><th>التاريخ</th><th>العميل</th><th>إجمالي بيع</th></tr></thead><tbody>';
                invRows.forEach((tr, i) => {
                    const tds = tr.querySelectorAll('td');
                    const inv = tds[0]?.innerText || '';
                    const dt = tds[1]?.innerText || '';
                    const cust = tds[2]?.innerText || '';
                    const total = tds[3]?.innerText || '0';
                    html += '<tr><td>' + (i + 1) + '</td><td>' + escapeHtml(inv) + '</td><td>' + escapeHtml(dt) + '</td><td>' + escapeHtml(cust) + '</td><td style="text-align:right">' + escapeHtml(total) + '</td></tr>';
                });
                html += '</tbody></table>';
            }

            // expenses
            html += '<h3 style="margin-top:12px">المصروفات</h3>';
            const expRows = Array.from(document.querySelectorAll('#expensesTable tbody tr'));
            if (expRows.length === 0) html += '<div>لا توجد مصروفات لعرضها.</div>';
            else {
                html += '<table><thead><tr><th>التاريخ</th><th>الوصف</th><th>المبلغ</th></tr></thead><tbody>';
                expRows.forEach(tr => {
                    const tds = tr.querySelectorAll('td');
                    const dt = tds[0]?.innerText || '';
                    const desc = tds[1]?.innerText || '';
                    const amt = tds[2]?.innerText || '0';
                    html += '<tr><td>' + escapeHtml(dt) + '</td><td>' + escapeHtml(desc) + '</td><td style="text-align:right">' + escapeHtml(amt) + '</td></tr>';
                });
                html += '</tbody></table>';
            }

            html += '<div style="margin-top:18px;color:#666;font-size:13px">طُبع في: ' + new Date().toLocaleString('ar-EG') + '</div>';
            html += '</body></html>';

            const w = window.open('', '_blank');
            if (!w) {
                alert('يرجى السماح بفتح النوافذ المنبثقة للطباعة');
                return;
            }
            w.document.open();
            w.document.write(html);
            w.document.close();
            w.focus();
            setTimeout(() => w.print(), 300);
        });
    });
</script>

<?php
require_once BASE_DIR . 'partials/footer.php';
$conn->close();
?>