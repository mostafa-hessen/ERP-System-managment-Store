<?php
// reports/profit_report_invoices_summary.responsive.php
// نسخة محسّنة: تصميم responsive وحديث، متطلبات الطباعة، فترات سريعة، رأس جدول ثابت.
$page_title = "تقرير الربح - ملخص الفواتير";
require_once dirname(__DIR__) . '/config.php';
require_once BASE_DIR . 'partials/session_admin.php'; // صلاحيات المدير فقط

if (!isset($conn) || !$conn) { echo "DB connection error"; exit; }
function e($s){ return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

// === AJAX endpoint: جلب بنود فاتورة معينة ===
if (isset($_GET['action']) && $_GET['action'] === 'get_invoice_items' && isset($_GET['id'])) {
    header('Content-Type: application/json; charset=utf-8');
    $inv_id = intval($_GET['id']);
    if ($inv_id <= 0) { echo json_encode(['ok'=>false,'msg'=>'معرف فاتورة غير صالح']); exit; }

    $sql_items = "
        SELECT ioi.id, ioi.product_id, COALESCE(p.name,'') AS product_name, ioi.quantity,
               ioi.selling_price, ioi.total_price, COALESCE(ioi.cost_price_per_unit, p.cost_price, 0) AS cost_price_per_unit
        FROM invoice_out_items ioi
        LEFT JOIN products p ON p.id = ioi.product_id
        WHERE ioi.invoice_out_id = ?
        ORDER BY ioi.id ASC
    ";
    if ($stmt = $conn->prepare($sql_items)) {
        $stmt->bind_param("i", $inv_id);
        if ($stmt->execute()) {
            $res = $stmt->get_result();
            $items = [];
            while ($r = $res->fetch_assoc()) {
                $r['quantity'] = floatval($r['quantity']);
                $r['selling_price'] = floatval($r['selling_price']);
                $r['total_price'] = floatval($r['total_price']);
                $r['cost_price_per_unit'] = floatval($r['cost_price_per_unit']);
                $r['line_cogs'] = $r['quantity'] * $r['cost_price_per_unit'];
                $r['line_profit'] = $r['total_price'] - $r['line_cogs'];
                $items[] = $r;
            }
            echo json_encode(['ok'=>true,'items'=>$items], JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode(['ok'=>false,'msg'=>'فشل تنفيذ الاستعلام: '.$stmt->error], JSON_UNESCAPED_UNICODE);
        }
        $stmt->close();
    } else {
        echo json_encode(['ok'=>false,'msg'=>'فشل تحضير الاستعلام: '.$conn->error], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// === Main report ===
$message = '';
$summaries = [];

// الافتراضي: اليوم (لو المستخدم لم يحدد)
$start_date_filter = isset($_GET['start_date']) && trim($_GET['start_date']) !== '' ? trim($_GET['start_date']) : date('Y-m-d');
$end_date_filter   = isset($_GET['end_date']) && trim($_GET['end_date']) !== '' ? trim($_GET['end_date']) : date('Y-m-d');

$report_generated = false;

if (!empty($start_date_filter) && !empty($end_date_filter)) {
    if (DateTime::createFromFormat('Y-m-d', $start_date_filter) === false || DateTime::createFromFormat('Y-m-d', $end_date_filter) === false) {
        $message = "<div class='alert alert-danger'>صيغة التاريخ غير صحيحة. استخدم YYYY-MM-DD.</div>";
    } elseif ($start_date_filter > $end_date_filter) {
        $message = "<div class='alert alert-danger'>تاريخ البدء لا يمكن أن يكون بعد تاريخ الانتهاء.</div>";
    } else {
        $report_generated = true;
        $start_sql = $start_date_filter . " 00:00:00";
        $end_sql   = $end_date_filter . " 23:59:59";

        // ====== إجماليات البطاقات (مجموع الفترات) ======
        $totals_sql = "
            SELECT
                COALESCE(SUM(ioi.total_price),0) AS total_revenue,
                COALESCE(SUM(ioi.quantity),0) AS total_quantity,
                COALESCE(SUM(ioi.quantity * COALESCE(ioi.cost_price_per_unit, p.cost_price, 0)),0) AS total_cost
            FROM invoices_out io
            JOIN invoice_out_items ioi ON ioi.invoice_out_id = io.id
            LEFT JOIN products p ON p.id = ioi.product_id
            WHERE io.delivered = 'yes'
              AND io.created_at BETWEEN ? AND ?
        ";
        if ($stt = $conn->prepare($totals_sql)) {
            $stt->bind_param("ss", $start_sql, $end_sql);
            if ($stt->execute()) {
                $r = $stt->get_result()->fetch_assoc();
                $grand_total_revenue = floatval($r['total_revenue'] ?? 0);
                $grand_total_quantity = floatval($r['total_quantity'] ?? 0);
                $grand_total_cost = floatval($r['total_cost'] ?? 0);
                $grand_total_profit = $grand_total_revenue - $grand_total_cost;
                $profit_percent = ($grand_total_revenue > 0) ? ($grand_total_profit / $grand_total_revenue) * 100 : 0;
            } else {
                $message = "<div class='alert alert-danger'>فشل حساب الإجماليات: " . e($stt->error) . "</div>";
            }
            $stt->close();
        } else {
            $message = "<div class='alert alert-danger'>فشل تحضير استعلام الإجماليات: " . e($conn->error) . "</div>";
        }

        // ====== ملخص كل فاتورة (قائمة) ======
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
            $stmt->bind_param("ss", $start_sql, $end_sql);
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
                $message = "<div class='alert alert-danger'>خطأ في تنفيذ الاستعلام: " . e($stmt->error) . "</div>";
            }
            $stmt->close();
        } else {
            $message = "<div class='alert alert-danger'>خطأ في تحضير الاستعلام: " . e($conn->error) . "</div>";
        }
    }
}

// ====== (إبقاء حساب اليوم كمرجع — لكنه لن يُعرض كـ card منفصلة الآن) ======
$today_revenue = 0; $today_cost = 0; $today_profit = 0;
$today_start = date('Y-m-d') . " 00:00:00";
$today_end   = date('Y-m-d') . " 23:59:59";
$today_sql = "SELECT COALESCE(SUM(ioi.total_price),0) AS rev, COALESCE(SUM(ioi.quantity * COALESCE(ioi.cost_price_per_unit, p.cost_price, 0)),0) AS cost
              FROM invoices_out io
              JOIN invoice_out_items ioi ON ioi.invoice_out_id = io.id
              LEFT JOIN products p ON p.id = ioi.product_id
              WHERE io.delivered = 'yes' AND io.created_at BETWEEN ? AND ?";
if ($tst = $conn->prepare($today_sql)) {
    $tst->bind_param('ss', $today_start, $today_end);
    if ($tst->execute()) {
        $tr = $tst->get_result()->fetch_assoc();
        $today_revenue = floatval($tr['rev'] ?? 0);
        $today_cost = floatval($tr['cost'] ?? 0);
        $today_profit = $today_revenue - $today_cost;
    }
    $tst->close();
}

require_once BASE_DIR . 'partials/header.php';
require_once BASE_DIR . 'partials/sidebar.php';
?>

<style>
:root {
  --primary: #0b84ff;
  --grad-1: linear-gradient(135deg, #0b84ff, #7c3aed);
  --grad-2: linear-gradient(135deg, #10b981, #0ea5e9);
  --grad-3: linear-gradient(135deg, #f59e0b, #ef4444);
  --surface: #ffffff;
  --surface-2: #f9fbff;
  --text: #0f172a;
  --text-soft: #334155;
  --muted: #64748b;
  --border: rgba(2,6,23,0.08);
  --radius: 14px;
  --shadow-1: 0 10px 24px rgba(15,23,42,0.06);
  --shadow-2: 0 12px 28px rgba(11,132,255,0.14);
}

/* container and header */
.container { max-width:1100px; margin:0 auto; padding:18px; }
.page-header { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:12px; }
.page-header h3 { margin:0; font-size:1.25rem; color:var(--text); }
.small-muted { color:var(--text-soft); }

/* filters */
.card.filter-card { background:var(--surface); border-radius:12px; padding:12px; margin-bottom:16px; box-shadow:var(--shadow-1); }
.form-row { display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end; }

/* quick periods buttons */
.periods { display:flex; gap:8px; align-items:center; margin-right:8px; }
.periods button { background:transparent; border:1px solid var(--border); padding:8px 10px; border-radius:8px; cursor:pointer; color:var(--text); }
.periods button.active { background:var(--primary); color:#fff; box-shadow:var(--shadow-2); transform:translateY(-2px); }

/* summary cards */
.summary-cards { display:grid; grid-template-columns: repeat(3, 1fr); gap:16px; margin-bottom:20px; }
.summary-card {
  background: var(--surface);
  border-radius: var(--radius);
  padding:16px 18px;
  box-shadow: var(--shadow-1);
  position:relative;
  overflow:hidden;
  transition: transform .18s ease, box-shadow .18s ease;
}
.summary-card:hover { transform: translateY(-6px); box-shadow: var(--shadow-2); }
.summary-card .title { color:var(--muted); font-size:0.95rem; margin-bottom:8px; }
.summary-card .value { font-size:1.75rem; font-weight:800; color:var(--text); }
.summary-card .sub { color:var(--text-soft); margin-top:8px; font-size:0.9rem; }
.summary-card::before { content:''; position:absolute; right:-30px; top:-30px; width:160px; height:160px; opacity:0.12; transform:rotate(20deg); }
.card-revenue::before { background:var(--grad-1); }
.card-cost::before { background:var(--grad-2); }
.card-profit::before { background:var(--grad-3); }
.currency-badge { display:inline-block; margin-left:8px; font-weight:700; color:var(--muted); font-size:0.85rem; }

/* table with sticky header and scrollable body */













.table-wrapper { border-radius:12px; overflow:hidden; box-shadow:var(--shadow-1); background:var(--surface); }
.table-scroll {
  max-height: 520px; /* adjust as needed */
  overflow: auto;
}
.table { width:100%; border-collapse:collapse; min-width: 800px; }
.table thead th {
  position: sticky;
  top: 0;
  background: var(--surface-2);
  z-index: 4;
  padding:10px 12px;
  border-bottom:1px solid var(--border);
  text-align:left;
}
.table tbody td { padding:10px 12px; border-bottom:1px solid rgba(0,0,0,0.04); vertical-align:middle; }
.table tfoot td { padding:10px 12px; border-top:1px solid var(--border); background:var(--surface-2); }

/* profit badge */
.badge-profit { font-weight:700; padding:6px 8px; border-radius:6px; display:inline-block; }
.badge-profit.positive { background:rgba(16,185,129,0.08); color:#075928; border:1px solid rgba(16,185,129,0.12); }
.badge-profit.negative { background:rgba(239,68,68,0.06); color:#7f1d1d; border:1px solid rgba(239,68,68,0.12); }

/* modal lite */
.modal-backdrop-lite { position:fixed; left:0; top:0; right:0; bottom:0; background:rgba(2,6,23,0.5); display:none; align-items:center; justify-content:center; z-index:9999; padding:16px; }
.modal-card { background:var(--surface); border-radius:12px; max-width:980px; width:100%; max-height:85vh; overflow:auto; padding:18px; box-shadow:var(--shadow-2); }

/* responsive */
@media (max-width:900px) { .summary-cards { grid-template-columns: repeat(2, 1fr); } .table { min-width: 700px; } }
@media (max-width:640px) { .summary-cards { grid-template-columns: 1fr; } .page-header { flex-direction:column; align-items:flex-start; gap:6px; } .table { min-width: 600px; } }
</style>

<div class="container-fluid">
    <div class="page-header">
        <h3><i class="fas fa-file-invoice-dollar"></i> تقرير الربح — ملخص الفواتير</h3>
        <div>
            <div class="small-muted">الأسعار مأخوذة من بنود الفاتورة نفسها</div>
            <div style="margin-top:6px;text-align:right">
                <a href="<?php echo htmlspecialchars(BASE_URL . 'user/welcome.php'); ?>" class="btn btn-sm btn-light">← العودة</a>
                <button id="printBtn" class="btn btn-sm btn-primary">طباعة</button>
            </div>
        </div>
    </div>

    <?php echo $message; ?>

    <div class="card filter-card">
        <div class="card-body p-2">
            <form id="filterForm" method="get" class="form-row">
                <div style="display:flex;align-items:center;gap:12px;">
                    <div class="periods" role="tablist" aria-label="فترات سريعة">
                        <button type="button" data-period="day" id="btnDay">يوم</button>
                        <button type="button" data-period="week" id="btnWeek">أسبوع</button>
                        <button type="button" data-period="month" id="btnMonth">شهر</button>
                        <button type="button" data-period="custom" id="btnCustom">مخصص</button>
                    </div>
                    <input type="hidden" name="period" id="periodInput" value="<?php echo e($_GET['period'] ?? 'day'); ?>">
                </div>

                <div>
                    <label class="form-label">من تاريخ</label>
                    <input type="date" name="start_date" id="start_date" class="form-control" value="<?php echo e($start_date_filter); ?>" required>
                </div>

                <div>
                    <label class="form-label">إلى تاريخ</label>
                    <input type="date" name="end_date" id="end_date" class="form-control" value="<?php echo e($end_date_filter); ?>" required>
                </div>

                <div style="display:flex;gap:8px;align-items:center;">
                    <button type="submit" class="btn btn-primary">عرض</button>
                    <button type="button" id="todayQuick" class="btn btn-outline-secondary">اليوم</button>
                </div>

                <div style="margin-left:auto;color:var(--muted);font-size:0.9rem;">
                    <small>عرض الفواتير المسلمة فقط (delivered = yes)</small>
                </div>
            </form>
        </div>
    </div>

    <?php if ($report_generated): ?>
        <div class="summary-cards" aria-hidden="<?php echo $report_generated ? 'false' : 'true'; ?>">
            <div class="summary-card card-revenue">
                <div class="title">إجمالي الإيرادات</div>
                <div class="value"><?php echo number_format($grand_total_revenue ?? 0,2); ?> <span class="currency-badge">ج.م</span></div>
                <div class="sub">مجموع أسعار البيع المسجلة في الفواتير خلال الفترة</div>
            </div>

            <div class="summary-card card-cost">
                <div class="title">تكلفة البضاعة المباعة</div>
                <div class="value"><?php echo number_format($grand_total_cost ?? 0,2); ?> <span class="currency-badge">ج.م</span></div>
                <div class="sub">مجموع (الكمية × سعر التكلفة) كما سجل في البنود</div>
            </div>

            <div class="summary-card card-profit">
                <div class="title">صافي الربح</div>
                <div class="value"><?php echo number_format($grand_total_profit ?? 0,2); ?> <span class="currency-badge">ج.م</span></div>
                <div class="sub">نسبة الربح: <span class="profit-percent <?php echo (($grand_total_profit ?? 0) >= 0) ? 'positive' : 'negative'; ?>"><?php echo round($profit_percent ?? 0,2); ?>%</span></div>
            </div>
        </div>

        <?php if (empty($summaries)): ?>
            <div class="alert alert-info">لا توجد فواتير مسلّمة خلال الفترة المحددة.</div>
        <?php else: ?>
            <div class="table-wrapper mb-3">
                <div class="table-scroll">
                    <table class="table mb-0" id="reportTable">
                        <thead>
                            <tr>
                                <th style="width:90px"># فاتورة</th>
                                <th style="width:160px">التاريخ</th>
                                <th>العميل</th>
                                <th style="width:130px" class="text-end">إجمالي بيع</th>
                                <th style="width:140px" class="text-end">إجمالي تكلفة</th>
                                <th style="width:120px" class="text-end">الربح</th>
                                <th style="width:140px" class="text-center">تفاصيل / صفحة</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($summaries as $s):
                                $profit_class = $s['profit'] >= 0 ? 'positive' : 'negative';
                            ?>
                                <tr class="invoice-row" data-invoice-id="<?php echo intval($s['invoice_id']); ?>">
                                    <td><strong>#<?php echo intval($s['invoice_id']); ?></strong></td>
                                    <td><?php echo e($s['invoice_created_at']); ?></td>
                                    <td><?php echo e($s['customer_name'] ?: 'عميل غير محدد'); ?></td>
                                    <td class="text-end"><?php echo number_format($s['total_sold'],2); ?> ج.م</td>
                                    <td class="text-end"><?php echo number_format($s['total_cost'],2); ?> ج.م</td>
                                    <td class="text-end"><span class="badge-profit <?php echo $profit_class; ?>"><?php echo number_format($s['profit'],2); ?> ج.م</span></td>
                                    <td class="text-center">
                                        <button type="button" class="btn btn-sm btn-outline-primary details-btn" data-invoice-id="<?php echo intval($s['invoice_id']); ?>">عرض</button>
                                        <!-- <a href="<?php echo BASE_URL; ?>invoices_out/view_invoice_detaiels.php?id=<?php echo intval($s['invoice_id']); ?>" class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener">فتح الصفحة</a> -->
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <?php
                            $grand_sold = 0; $grand_cost = 0; $grand_qty = 0;
                            foreach ($summaries as $ss) { $grand_sold += $ss['total_sold']; $grand_cost += $ss['total_cost']; $grand_qty += $ss['total_qty']; }
                            $grand_profit = $grand_sold - $grand_cost;
                            ?>
                            <tr>
                                <td colspan="3" class="text-end"><strong>الإجمالي الكلي للفواتير المعروضة:</strong></td>
                                <td class="text-end"><strong><?php echo number_format($grand_sold,2); ?> ج.م</strong></td>
                                <td class="text-end"><strong><?php echo number_format($grand_cost,2); ?> ج.م</strong></td>
                                <td class="text-end"><strong><?php echo number_format($grand_profit,2); ?> ج.م</strong></td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <div class="card" style="box-shadow:var(--shadow-1);border-radius:12px;padding:12px;">
            <div class="card-body small-muted">
                <strong>كيف تم الحساب؟</strong>
                <ul>
                    <li>الإيرادات = مجموع أسعار البيع كما سُجلت في بنود الفاتورة.</li>
                    <li>تكلفة البضاعة = مجموع (الكمية × سعر التكلفة المسجل بنفس بند الفاتورة).</li>
                    <li>الربح = الإيرادات − التكلفة. ونسبة الربح = (الربح ÷ الإيرادات) × 100.</li>
                    <li>اضغط "عرض" لأي فاتورة لعرض بنودها داخل النافذة، أو "فتح الصفحة" لفتح صفحة الفاتورة كاملة.</li>
                </ul>
            </div>
        </div>
    <?php endif; ?>

</div>

<!-- Modal (خفيف) -->
<div id="modalBackdrop" class="modal-backdrop-lite" role="dialog" aria-hidden="true">
    <div class="modal-card" role="document" aria-modal="true">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <h5 id="modalTitle">تفاصيل الفاتورة</h5>
            <div>
                <a id="openInvoicePage" href="#" target="_blank" class="btn btn-sm btn-outline-secondary" style="margin-left:6px;">فتح الصفحة</a>
                <button id="closeModal" class="btn btn-sm btn-light">✖</button>
            </div>
        </div>
        <div id="modalBody">
            <div class="small-muted mb-2" id="modalInvoiceInfo"></div>
            <div id="modalContent">جارٍ التحميل...</div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function(){
    // helpers
    function qs(s){ return document.querySelector(s); }
    function qsa(s){ return Array.from(document.querySelectorAll(s)); }
    function escapeHtml(s){ if(!s && s!==0) return ''; return String(s).replace(/[&<>"']/g,function(m){ return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[m]; }); }

    // period buttons behavior
    const btnDay = qs('#btnDay'), btnWeek = qs('#btnWeek'), btnMonth = qs('#btnMonth'), btnCustom = qs('#btnCustom');
    const startIn = qs('#start_date'), endIn = qs('#end_date'), periodInput = qs('#periodInput'), filterForm = qs('#filterForm');
    const todayQuick = qs('#todayQuick');

    function formatDate(d){ return d.toISOString().slice(0,10); }
    const now = new Date();
    if (!startIn.value) startIn.value = formatDate(now);
    if (!endIn.value) endIn.value = formatDate(now);

    function setPeriod(p, autoSubmit = false){
        [btnDay,btnWeek,btnMonth,btnCustom].forEach(b=> b.classList.remove('active'));
        const today = new Date();
        let s = new Date(), e = new Date();
        if (p === 'day') {
            s = new Date(today); e = new Date(today);
            btnDay.classList.add('active');
        } else if (p === 'week') {
            const day = today.getDay() || 7;
            s = new Date(today); s.setDate(today.getDate() - (day - 1));
            e = new Date(s); e.setDate(s.getDate() + 6);
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
        if (autoSubmit) { setTimeout(()=> filterForm.submit(), 120); }
    }

    // initial: if user provided period via GET, respect it; else default day
    const initialPeriod = '<?php echo e($_GET['period'] ?? 'day'); ?>';
    setPeriod(initialPeriod || 'day', false);

    btnDay?.addEventListener('click', ()=> setPeriod('day', true));
    btnWeek?.addEventListener('click', ()=> setPeriod('week', true));
    btnMonth?.addEventListener('click', ()=> setPeriod('month', true));
    btnCustom?.addEventListener('click', ()=> setPeriod('custom', false));

    todayQuick?.addEventListener('click', function(){ setPeriod('day', true); });

    // modal behavior (details)
    const modal = qs('#modalBackdrop');
    const modalTitle = qs('#modalTitle');
    const modalContent = qs('#modalContent');
    const modalInvoiceInfo = qs('#modalInvoiceInfo');
    const closeModal = qs('#closeModal');
    const openInvoicePage = qs('#openInvoicePage');

    async function fetchInvoiceItems(id){
        modalContent.innerHTML = 'جارٍ التحميل...';
        try {
            const params = new URLSearchParams({ action: 'get_invoice_items', id: id });
            const res = await fetch(location.pathname + '?' + params.toString(), { credentials: 'same-origin' });
            const data = await res.json();
            if (!data.ok) {
                modalContent.innerHTML = '<div class="alert alert-danger">خطأ: ' + escapeHtml(data.msg||'فشل التحميل') + '</div>';
                return;
            }
            const items = data.items || [];
            if (!items.length) {
                modalContent.innerHTML = '<div class="p-2">لا توجد بنود في هذه الفاتورة.</div>';
                return;
            }
            // Build table
            let html = '<div class="table-responsive"><table class="table table-sm table-striped"><thead><tr><th>المنتج</th><th style="width:90px">الكمية</th><th style="width:110px">سعر التكلفة</th><th style="width:110px">إجمالي التكلفة</th><th style="width:110px">سعر البيع</th><th style="width:110px">إجمالي البيع</th><th style="width:110px">صافي الربح</th></tr></thead><tbody>';
            let sumSell = 0, sumCost = 0, sumProfit = 0;
            for (const it of items) {
                const qty = parseFloat(it.quantity || 0);
                const selling = parseFloat(it.selling_price || 0);
                const total = parseFloat(it.total_price || 0);
                const costu = parseFloat(it.cost_price_per_unit || 0);
                const lineCogs = +(qty * costu);
                const lineProfit = +(total - lineCogs);
                sumSell += total; sumCost += lineCogs; sumProfit += lineProfit;
                html += `<tr>
                    <td>${escapeHtml(it.product_name || ('#'+it.product_id))}</td>
                    <td class="text-end">${qty.toFixed(2)}</td>
                
                    <td class="text-end">${costu.toFixed(2)}</td>
                    <td class="text-end">${lineCogs.toFixed(2)}</td>
                        <td class="text-end">${selling.toFixed(2)}</td>
                    <td class="text-end">${total.toFixed(2)}</td>
                    <td class="text-end">${lineProfit.toFixed(2)}</td>
                </tr>`;
            }
            html += `</tbody><tfoot class="table-light"><tr>
                <th>المجموع</th><th></th><th>
                </th><th class="text-end">${sumCost.toFixed(2)}</th>
                <th></th><th class="text-end">${sumSell.toFixed(2)}</th><th class="text-end">${sumProfit.toFixed(2)}</th>
            </tr></tfoot></table></div>`;
            modalContent.innerHTML = html;
        } catch (err) {
            console.error(err);
            modalContent.innerHTML = '<div class="alert alert-danger">خطأ في الاتصال بالخادم.</div>';
        }
    }

    function openModal(invoiceId, invoiceCreatedAt) {
        modalTitle.textContent = 'تفاصيل الفاتورة #' + invoiceId;
        modalInvoiceInfo.textContent = 'تاريخ الفاتورة: ' + invoiceCreatedAt;
        openInvoicePage.href = '<?php echo BASE_URL; ?>invoices_out/view_invoice_detaiels.php?id=' + encodeURIComponent(invoiceId);
        modal.style.display = 'flex';
        modal.setAttribute('aria-hidden','false');
        fetchInvoiceItems(invoiceId);
    }
    function closeModalFn(){ modal.style.display = 'none'; modal.setAttribute('aria-hidden','true'); modalContent.innerHTML = ''; }

    qsa('.details-btn').forEach(btn=>{
        btn.addEventListener('click', function(){
            const id = this.dataset.invoiceId;
            const row = this.closest('tr');
            const date = row ? row.cells[1].innerText : '';
            openModal(id, date);
        });
    });

    closeModal.addEventListener('click', closeModalFn);
    modal.addEventListener('click', function(e){ if (e.target === modal) closeModalFn(); });

    // PRINT: only print period + table + totals
    qs('#printBtn')?.addEventListener('click', function(){
        const periodLabel = (qs('.page-header .subtitle') ? qs('.page-header .subtitle').innerText : (startIn.value + ' → ' + endIn.value));
        const rows = [];
        let grand = 0;
        qsa('#reportTable tbody tr').forEach(tr=>{
            const tds = tr.querySelectorAll('td');
            if (!tds || tds.length < 5) return;
            const idx = tds[0].innerText.trim();
            const inv = tds[0] ? tds[0].innerText.trim() : '';
            const dt = tds[1] ? tds[1].innerText.trim() : '';
            const cust = tds[2] ? tds[2].innerText.trim() : '';
            const totalTxt = tds[3] ? tds[3].innerText.replace(/[^\d.,-]/g,'').replace(/,/g,'').trim() : '0';
            const totalVal = parseFloat(totalTxt) || 0;
            grand += totalVal;
            rows.push([idx, inv, dt, cust, totalVal.toFixed(2)]);
        });

        let html = `<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><title>طباعة تقرير المبيعات</title>
            <style>body{font-family:Arial;padding:18px;color:#111} h2{margin:0 0 8px} .meta{color:#555;margin-bottom:12px} table{width:100%;border-collapse:collapse;margin-top:8px} th,td{border:1px solid #ddd;padding:8px;text-align:right} thead th{background:#f6f8fb} tfoot td{font-weight:700}</style>
            </head><body>`;
        html += `<h2>تقرير الربح — ملخص الفواتير</h2><div class="meta">الفترة: <strong>${escapeHtml(startIn.value)} → ${escapeHtml(endIn.value)}</strong></div>`;
        if (rows.length === 0) {
            html += '<div>لا توجد فواتير لعرضها.</div>';
        } else {
            html += `<table><thead><tr><th>#</th><th>رقم الفاتورة</th><th>التاريخ</th><th>العميل</th><th>إجمالي الفاتورة</th></tr></thead><tbody>`;
            rows.forEach(r => {
                html += `<tr><td>${escapeHtml(r[0])}</td><td>${escapeHtml(r[1])}</td><td>${escapeHtml(r[2])}</td><td>${escapeHtml(r[3])}</td><td style="text-align:right">${escapeHtml(r[4])} ج.م</td></tr>`;
            });
            html += `</tbody><tfoot><tr><td colspan="4" style="text-align:right">الإجمالي الكلي:</td><td style="text-align:right">${grand.toFixed(2)} ج.م</td></tr></tfoot></table>`;
        }
        html += `<div style="margin-top:18px;color:#666;font-size:13px">طُبع في: ${new Date().toLocaleString('ar-EG')}</div>`;
        html += `</body></html>`;

        const w = window.open('', '_blank', 'toolbar=0,location=0,menubar=0');
        if (!w) { alert('يرجى السماح بفتح النوافذ المنبثقة للطباعة'); return; }
        w.document.open();
        w.document.write(html);
        w.document.close();
        w.focus();
        setTimeout(()=> { w.print(); }, 350);
    });

});
</script>

<?php
require_once BASE_DIR . 'partials/footer.php';
$conn->close();
?>
