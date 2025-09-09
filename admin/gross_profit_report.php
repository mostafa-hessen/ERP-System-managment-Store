<?php
// reports/profit_report_invoices_summary.responsive.php
// نسخة محسّنة: تصميم responsive وحديث، يستخدم متغيرات الألوان المرسلة
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

$start_date_filter = isset($_GET['start_date']) ? trim($_GET['start_date']) : date('Y-m-01');
$end_date_filter   = isset($_GET['end_date']) ? trim($_GET['end_date']) : date('Y-m-t');

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

// ====== حساب ابتدائي لأرباح اليوم (تُعرض دائماً) ======
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
  /* base palette (user-provided tokens) */
  --primary: #0b84ff;
  --primary-600: #0a6be0;
  --primary-700: #0a58c8;

  --accent: #7c3aed;
  --accent-600: #6d28d9;
  --teal: #10b981;
  --amber: #f59e0b;
  --rose: #ef4444;
  --const-white: #ffffff;
  --bg: #f6f8fc;
  --surface: #ffffff;
  --surface-2: #f9fbff;
  --text: #0f172a;
  --text-soft: #334155;
  --muted: #64748b;
  --border: rgba(2,6,23,0.08);

  --radius: 14px;
  --radius-sm: 10px;
  --radius-lg: 20px;

  --shadow-1: 0 10px 24px rgba(15,23,42,0.06);
  --shadow-2: 0 12px 28px rgba(11,132,255,0.14);
  --ring: 0 0 0 4px rgba(11,132,255,0.18);

  --header-h: 64px;

  --grad-1: linear-gradient(135deg, #0b84ff, #7c3aed);
  --grad-2: linear-gradient(135deg, #10b981, #0ea5e9);
  --grad-3: linear-gradient(135deg, #f59e0b, #ef4444);
}

[data-app][data-theme="dark"] {
  --bg: #0c1222;
  --surface: #0f162d;
  --surface-2: #0a1122;
  --text: #e5e7eb;
  --text-soft: #cbd5e1;
  --muted: #94a3b8;
  --border: rgba(148,163,184,0.15);
  --shadow-1: 0 12px 30px rgba(0,0,0,0.45);
  --shadow-2: 0 18px 40px rgba(11,132,255,0.22);
  --ring: 0 0 0 4px rgba(11,132,255,0.24);
}

/* Layout container */
.container { max-width:1100px; margin:0 auto; padding:18px; }

/* Header */
.page-header { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:12px; }
.page-header h3 { margin:0; font-size:1.25rem; color:var(--text); }
.small-muted { color:var(--text-soft); }

/* Summary cards grid */
.summary-cards { display:grid; grid-template-columns: repeat(3, 1fr); gap:16px; margin-bottom:20px; }
.summary-card {
  background: var(--surface);
  border-radius: var(--radius);
  padding:16px 18px;
  box-shadow: var(--shadow-1);
  position:relative;
  overflow:hidden;
  transition: transform var(--fast), box-shadow var(--fast);
}
.summary-card:hover { transform: translateY(-6px); box-shadow: var(--shadow-2); }
.summary-card .title { color:var(--muted); font-size:0.95rem; margin-bottom:8px; }
.summary-card .value { font-size:1.75rem; font-weight:800; color:var(--text); }
.summary-card .sub { color:var(--text-soft); margin-top:8px; font-size:0.9rem; }

/* Accent strips and icons */
.summary-card::before { content:''; position:absolute; right:-30px; top:-30px; width:160px; height:160px; opacity:0.12; transform:rotate(20deg); }
.card-revenue::before { background:var(--grad-1); }
.card-cost::before { background:var(--grad-2); }
.card-profit::before { background:var(--grad-3); }
.card-today::before { background: linear-gradient(135deg, rgba(11,132,255,0.9), rgba(124,58,237,0.9)); }

/* tiny badge */
.currency-badge { display:inline-block; margin-left:8px; font-weight:700; color:var(--muted); font-size:0.85rem; }

/* profit percent */
.profit-percent { display:inline-flex; align-items:center; gap:8px; padding:6px 10px; border-radius:10px; font-weight:700; }
.profit-percent.positive { color:#075928; background:rgba(16,185,129,0.08); border:1px solid rgba(16,185,129,0.12); }
.profit-percent.negative { color:#7f1d1d; background:rgba(239,68,68,0.06); border:1px solid rgba(239,68,68,0.12); }

/* Table */
.table-responsive { overflow:auto; }
.table { width:100%; border-collapse:collapse; }
.table th, .table td { padding:10px 12px; border-bottom:1px solid rgba(0,0,0,0.04); }
.table thead th { background:var(--surface-2); text-align:left; }

.badge-profit { font-weight:700; padding:6px 8px; border-radius:6px; display:inline-block; }
.badge-profit.positive { background:rgba(16,185,129,0.08); color:#075928; border:1px solid rgba(16,185,129,0.12); }
.badge-profit.negative { background:rgba(239,68,68,0.06); color:#7f1d1d; border:1px solid rgba(239,68,68,0.12); }

/* Modal */
.modal-backdrop-lite { position:fixed; left:0; top:0; right:0; bottom:0; background:rgba(2,6,23,0.5); display:none; align-items:center; justify-content:center; z-index:9999; padding:16px; }
.modal-card { background:var(--surface); border-radius:12px; max-width:980px; width:100%; max-height:85vh; overflow:auto; padding:18px; box-shadow:var(--shadow-2); }

/* Responsive adjustments */
@media (max-width: 900px) {
  .summary-cards { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 640px) {
  .summary-cards { grid-template-columns: 1fr; }
  .page-header { flex-direction:column; align-items:flex-start; gap:6px; }
  .table th, .table td { padding:8px; font-size:0.95rem; }
}
</style>

<div class="container">
    <div class="page-header">
        <h3><i class="fas fa-file-invoice-dollar"></i> تقرير الربح — ملخّص الفواتير</h3>
        <div class="small-muted">الأسعار المستخدمة مأخوذة من بنود الفاتورة نفسها</div>
    </div>

    <?php echo $message; ?>

    <div class="card mb-3" style="background:var(--surface);border-radius:12px;padding:12px;margin-bottom:16px;box-shadow:var(--shadow-1);">
        <div class="card-body p-0">
            <form method="get" class="row gx-2 gy-2 align-items-end">
                <div class="col-md-4">
                    <label class="form-label">من تاريخ</label>
                    <input type="date" name="start_date" class="form-control" value="<?php echo e($start_date_filter); ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">إلى تاريخ</label>
                    <input type="date" name="end_date" class="form-control" value="<?php echo e($end_date_filter); ?>" required>
                </div>
                <div class="col-md-2 d-grid">
                    <button class="btn btn-primary">عرض</button>
                </div>
                <div class="col-md-2 text-end small-muted" style="align-self:center;">
                    <small>عرض الفواتير التي تم دفعها (delivered = yes)</small>
                </div>
            </form>
        </div>
    </div>

    <?php if ($report_generated): ?>
        <!-- بطاقات الملخص -->
        <div class="summary-cards">
            <div class="summary-card card-revenue">
                <div class="title">إجمالي الإيرادات</div>
                <div class="value"><?php echo number_format($grand_total_revenue ?? 0,2); ?> <span class="currency-badge">ج.م</span></div>
                <div class="sub">مجموع ما سجلته الفواتير كسعر بيع خلال الفترة</div>
            </div>
            <div class="summary-card card-cost">
                <div class="title">تكلفة البضاعة المباعة</div>
                <div class="value"><?php echo number_format($grand_total_cost ?? 0,2); ?> <span class="currency-badge">ج.م</span></div>
                <div class="sub">مجموع  تكلفة البضاعة المباعة في الفترة</div>
            </div>
            <div class="summary-card card-profit">
                <div class="title">صافي الربح</div>
                <div class="value"><?php echo number_format($grand_total_profit ?? 0,2); ?> <span class="currency-badge">ج.م</span></div>
                <div class="sub">نسبة الربح: <span class="profit-percent <?php echo (($grand_total_profit ?? 0) >= 0) ? 'positive' : 'negative'; ?>"><?php echo round($profit_percent ?? 0,2); ?>%</span></div>
            </div>
            <!-- بطاقة أرباح اليوم (قيمة ابتدائية) -->
            <div class="summary-card card-today">
                <div class="title">أرباح اليوم (مبدئياً)</div>
                <?php $today_class = ($today_profit >= 0) ? 'positive' : 'negative'; ?>
                <div class="value"><?php echo number_format($today_profit,2); ?> <span class="currency-badge">ج.م</span></div>
                <div class="sub">إيراد اليوم: <?php echo number_format($today_revenue,2); ?>، التكلفة: <?php echo number_format($today_cost,2); ?> — <span class="profit-percent <?php echo $today_class; ?>"><?php echo round(($today_revenue>0?($today_profit/$today_revenue*100):0),2); ?>%</span></div>
            </div>
        </div>

        <?php if (empty($summaries)): ?>
            <div class="alert alert-info">لا توجد فواتير مسلّمة خلال الفترة المحددة.</div>
        <?php else: ?>
            <div class="card mb-3" style="box-shadow:var(--shadow-1);border-radius:12px;overflow:hidden;">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-striped mb-0 profit-summary-table table-fixed">
                            <thead class="table-light">
                                <tr>
                                    <th style="width:90px"># فاتورة</th>
                                    <th style="width:160px">التاريخ</th>
                                    <th>العميل</th>
                                    <th style="width:130px" class="text-end">إجمالي بيع</th>
                                    <th style="width:140px" class="text-end">إجمالي تكلفة</th>
                                    <th style="width:120px" class="text-end">الربح</th>
                                    <th style="width:110px" class="text-center">تفاصيل</th>
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
                                            <button type="button" class="btn btn-sm btn-outline-primary details-btn" data-invoice-id="<?php echo intval($s['invoice_id']); ?>">عرض التفاصيل</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-light">
                                <?php
                                $grand_sold = 0; $grand_cost = 0; $grand_qty = 0;
                                foreach ($summaries as $ss) { $grand_sold += $ss['total_sold']; $grand_cost += $ss['total_cost']; $grand_qty += $ss['total_qty']; }
                                $grand_profit = $grand_sold - $grand_cost;
                                ?>
                                <tr>
                                    <th colspan="3" class="text-end">الإجمالي الكلي للفواتير المعروضة:</th>
                                    <th class="text-end"><?php echo number_format($grand_sold,2); ?> ج.م</th>
                                    <th class="text-end"><?php echo number_format($grand_cost,2); ?> ج.م</th>
                                    <th class="text-end"><strong><?php echo number_format($grand_profit,2); ?> ج.م</strong></th>
                                    <th></th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- شرح مبسط وواضح -->
        <div class="card" style="box-shadow:var(--shadow-1);border-radius:12px;padding:12px;">
            <div class="card-body small-muted">
                <strong>كيف تم الحساب؟</strong>
                <ul>
                    <li>الإيرادات = مجموع أسعار البيع كما سُجلت في بنود الفاتورة (ما دفعه العميل وفق الفاتورة).</li>
                    <li>تكلفة البضاعة = مجموع (الكمية × سعر التكلفة المسجل بنفس بند الفاتورة).</li>
                    <li>الربح = الإيرادات − التكلفة. ونسبة الربح = (الربح ÷ الإيرادات) × 100.</li>
                    <li>اضغط "عرض التفاصيل" لأي فاتورة لتعرف سعر بيع كل منتج، وسعر التكلفة المستخدم، وكم ربحت من كل بند.</li>
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
            <button id="closeModal" class="btn btn-sm btn-light">✖</button>
        </div>
        <div id="modalBody">
            <div class="small-muted mb-2" id="modalInvoiceInfo"></div>
            <div id="modalContent">جارٍ التحميل...</div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function(){
    const modal = document.getElementById('modalBackdrop');
    const modalTitle = document.getElementById('modalTitle');
    const modalContent = document.getElementById('modalContent');
    const modalInvoiceInfo = document.getElementById('modalInvoiceInfo');
    const closeModal = document.getElementById('closeModal');

    async function fetchInvoiceItems(id){
        modalContent.innerHTML = 'جارٍ التحميل...';
        try {
            const params = new URLSearchParams({ action: 'get_invoice_items', id: id });
            const res = await fetch(location.pathname + '?' + params.toString(), { credentials: 'same-origin' });
            const data = await res.json();
            if (!data.ok) {
                modalContent.innerHTML = '<div class="alert alert-danger">خطأ: ' + (data.msg||'فشل التحميل') + '</div>';
                return;
            }
            const items = data.items || [];
            if (!items.length) {
                modalContent.innerHTML = '<div class="p-2">لا توجد بنود في هذه الفاتورة.</div>';
                return;
            }
            // Build table
            let html = '<div class="table-responsive"><table class="table table-sm table-striped">';
            html += '<thead><tr><th>المنتج</th><th style="width:90px">الكمية</th><th style="width:120px">سعر البيع</th><th style="width:120px">إجمالي البيع</th><th style="width:120px">سعر التكلفة</th><th style="width:120px">اجمالي سعر التكلفه</th><th style="width:120px">صافي الربح</th></tr></thead><tbody>';
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
                    <td class="text-end">${selling.toFixed(2)}</td>
                    <td class="text-end">${total.toFixed(2)}</td>
                    <td class="text-end">${costu.toFixed(2)}</td>
                    <td class="text-end">${lineCogs.toFixed(2)}</td>
                    <td class="text-end">${lineProfit.toFixed(2)}</td>
                </tr>`;
            }
            html += `</tbody><tfoot class="table-light"><tr>
                <th>المجموع</th>
                <th></th>
                <th></th>
                <th class="text-end">${sumSell.toFixed(2)}</th>
                <th></th>
                <th class="text-end">${sumCost.toFixed(2)}</th>
                <th class="text-end">${sumProfit.toFixed(2)}</th>
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
        modal.style.display = 'flex';
        modal.setAttribute('aria-hidden','false');
        fetchInvoiceItems(invoiceId);
    }
    function closeModalFn(){
        modal.style.display = 'none';
        modal.setAttribute('aria-hidden','true');
        modalContent.innerHTML = '';
    }

    document.querySelectorAll('.details-btn').forEach(btn=>{
        btn.addEventListener('click', function(){
            const id = this.dataset.invoiceId;
            const row = this.closest('tr');
            const date = row ? row.cells[1].innerText : '';
            openModal(id, date);
        });
    });

    closeModal.addEventListener('click', closeModalFn);
    modal.addEventListener('click', function(e){ if (e.target === modal) closeModalFn(); });

    function escapeHtml(s){ if(!s && s!==0) return ''; return String(s).replace(/[&<>\"']/g,function(m){ return ({'&':'&amp;','<':'&lt;','>':'&gt;','\"':'&quot;',"'":'&#39;'})[m]; }); }
});
</script>

<?php
require_once BASE_DIR . 'partials/footer.php';
$conn->close();
?>
