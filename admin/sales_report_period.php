<?php
// report_day.php — تقرير مبيعات اليوم (بشكل ابتدائي)
$page_title = "تقرير مبيعات اليوم";
$class_dashboard = "active";

require_once dirname(__DIR__) . '/config.php';
require_once BASE_DIR . 'partials/session_admin.php'; // صلاحيات المدير فقط

// ضبط التوقيت بحسب المنطقة (اختياري لكن مفيد لعرض "اليوم" بدقة)
date_default_timezone_set('Africa/Cairo');

$message = "";
$sales_data = [];
$total_invoices_period = 0;
$total_sales_amount_period = 0;

// إذا لم يرسل المستخدم تواريخ، اجعل الافتراضي هو اليوم
$today = date('Y-m-d');
$start_date_filter = isset($_GET['start_date']) && trim($_GET['start_date']) !== '' ? trim($_GET['start_date']) : $today;
$end_date_filter   = isset($_GET['end_date'])   && trim($_GET['end_date'])   !== '' ? trim($_GET['end_date'])   : $today;

// تحقق من صحة التاريخ (بسيط)
$start_ok = DateTime::createFromFormat('Y-m-d', $start_date_filter) !== false;
$end_ok   = DateTime::createFromFormat('Y-m-d', $end_date_filter) !== false;

if (!$start_ok || !$end_ok) {
    $message = "<div class='alert alert-danger'>صيغة التاريخ غير صحيحة. الرجاء استخدام YYYY-MM-DD.</div>";
} else {
    if ($start_date_filter > $end_date_filter) {
        $message = "<div class='alert alert-danger'>تاريخ البدء لا يمكن أن يكون بعد تاريخ الانتهاء.</div>";
    } else {
        // اجعل نهاية اليوم تشمل الوقت كاملاً
        $start_date_sql = $start_date_filter . " 00:00:00";
        $end_date_sql   = $end_date_filter . " 23:59:59";

        // استعلام مبسط: نحسب إجمالي كل فاتورة ونسترجع اسم العميل
        $sql = "SELECT
                    io.id as invoice_id,
                    io.created_at as invoice_date,
                    COALESCE(c.name, '—') as customer_name,
                    (SELECT IFNULL(SUM(ioi.total_price),0) FROM invoice_out_items ioi WHERE ioi.invoice_out_id = io.id) as invoice_total
                FROM invoices_out io
                LEFT JOIN customers c ON io.customer_id = c.id
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
                if ($total_invoices_period == 0) {
                    $message = "<div class='alert alert-info'>لا توجد فواتير مُسلمة اليوم.</div>";
                }
            } else {
                $message = "<div class='alert alert-danger'>خطأ أثناء تنفيذ استعلام المبيعات: " . htmlspecialchars($stmt->error) . "</div>";
            }
            $stmt->close();
        } else {
            $message = "<div class='alert alert-danger'>خطأ في تحضير استعلام المبيعات: " . htmlspecialchars($conn->error) . "</div>";
        }
    }
}

require_once BASE_DIR . 'partials/header.php';
require_once BASE_DIR . 'partials/sidebar.php';
?>

<!-- START: Themed report UI (replace between header/footer) -->
<!-- START: Themed report UI (updated per request) -->
<style>
/* Theme-aware, RTL-ready styles (scoped) */
.report-wrap { padding: 18px 0; }

/* HERO */
.hero {
  display:flex; justify-content:space-between; align-items:center; gap:12px;
  background: linear-gradient(90deg, rgba(11,132,255,0.04), rgba(99,102,241,0.02));
  padding:14px; border-radius:12px; box-shadow:var(--shadow-1);
}
.hero .title { font-weight:700; color:var(--text); font-size:1.1rem; }
.hero .subtitle { color:var(--muted); font-size:0.95rem; }

/* toolbar */
.toolbar { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
.btn-smth {
  border-radius:10px; padding:8px 12px; border:1px solid var(--border);
  background:transparent; color:var(--text); cursor:pointer; display:inline-flex; gap:8px; align-items:center;
  transition: transform .12s ease, box-shadow .12s ease;
}
.btn-smth.primary { background: linear-gradient(90deg,var(--primary), #5b9aff); color:#fff; border:none; box-shadow:0 8px 22px rgba(59,130,246,0.12); }
.btn-smth:active { transform: translateY(1px); }

/* PERIODS */
.periods { display:flex; gap:8px; align-items:center; }
.periods button {
  background:transparent; border:1px solid var(--border); padding:8px 10px; border-radius:8px; cursor:pointer; color:var(--text);
}
.periods button.active { background:var(--primary); color:#fff; box-shadow:0 6px 18px rgba(59,130,246,0.12); transform:translateY(-2px); }

/* SUMMARY CARDS (two cards) */
.kpis-wrap { display:flex; gap:12px; margin:14px 0; flex-wrap:wrap; }
.summary-card {
  position:relative;
  flex:1 1 260px;
  border-radius:12px;
  padding:18px;
  overflow:hidden;
  background:var(--surface);
  box-shadow:var(--shadow-1);
}
.summary-card .title { font-size:0.95rem; color:var(--muted); margin-bottom:6px; }
.summary-card .value { font-size:1.7rem; font-weight:700; color:var(--text); display:flex; align-items:baseline; gap:8px; }
.summary-card .sub { color:var(--muted); margin-top:6px; font-size:0.95rem; }

/* decorative pseudo element */
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

/* specific gradients for each card */
.card-sales::before {
    background: linear-gradient(135deg, rgba(11, 132, 255, 0.9), rgba(124, 58, 237, 0.9));
}
.card-cost::before {
    background: linear-gradient(135deg, rgba(16, 185, 129, 0.9), rgba(34, 197, 94, 0.9));
}

/* TABLE card */
.table-card { border-radius:12px; overflow:hidden; box-shadow:0 10px 28px rgba(2,6,23,0.04); background:var(--surface); }
.table-card .table thead th { background: linear-gradient(90deg, rgba(11,132,255,0.03), rgba(99,102,241,0.01)); border-bottom:none; color:var(--text); }
.table-card tbody tr:hover { background: rgba(11,132,255,0.03); transform: translateX(2px); transition: all .12s ease; }

/* small */
.small-muted { color:var(--muted); }
@media (max-width:720px){ .hero { flex-direction:column; align-items:flex-start; gap:10px } .kpis-wrap { flex-direction:column } }

/* print fallback hides UI; we use JS to open minimal print window */
@media print {
  body * { visibility:hidden; }
  .print-only, .print-only * { visibility: visible; }
  .print-only { position: absolute; left:0; top:0; width:100%; }
}
</style>

<div class="container report-wrap">
  <!-- HERO -->
  <div class="hero" role="banner" aria-label="تقرير المبيعات">
    <div>
      <div class="title">تقرير المبيعات</div>
      <div class="subtitle">الفترة: <strong id="periodText"><?php echo htmlspecialchars($start_date_filter); ?> → <?php echo htmlspecialchars($end_date_filter); ?></strong></div>
    </div>
    <div class="toolbar" role="toolbar" aria-label="أدوات">
      <a href="<?php echo htmlspecialchars(BASE_URL); ?>" class="btn-smth" title="العودة لصفحة الترحيب"><i class="fas fa-home" aria-hidden="true"></i> <span class="d-none d-sm-inline">العودة</span></a>
      <button id="refreshBtn" class="btn-smth" title="تحديث"><i class="fas fa-sync-alt"></i></button>
      <button id="printBtn" class="btn-smth primary" title="طباعة الفواتير في الفترة"><i class="fas fa-print"></i> طباعة</button>
    </div>
  </div>

  <!-- FILTERS -->
  <div class="card mt-3 mb-3">
    <div class="card-body">
      <form id="filterForm" method="get" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" class="row gy-2 gx-2 align-items-end">
        <div class="col-auto">
          <label class="form-label small-muted">المدة السريعة</label>
          <div class="periods" role="tablist" aria-label="اختر المدة">
            <button type="button" data-period="day" id="pDay">يوم</button>
            <button type="button" data-period="week" id="pWeek">أسبوع</button>
            <button type="button" data-period="month" id="pMonth">شهر</button>
            <button type="button" data-period="custom" id="pCustom">مخصص</button>
          </div>
        </div>

        <div class="col-auto">
          <label class="form-label small-muted">من</label>
          <input type="date" id="start_date" name="start_date" class="form-control" value="<?php echo htmlspecialchars($start_date_filter); ?>" required>
        </div>

        <div class="col-auto">
          <label class="form-label small-muted">إلى</label>
          <input type="date" id="end_date" name="end_date" class="form-control" value="<?php echo htmlspecialchars($end_date_filter); ?>" required>
        </div>

        <div class="col-auto">
          <input type="hidden" name="period" id="periodInput" value="<?php echo htmlspecialchars($_GET['period'] ?? 'day'); ?>">
          <button type="submit" class="btn btn-primary">عرض</button>
        </div>
        <div class="col-auto">
          <button type="button" id="todayBtn" class="btn btn-outline-secondary">اليوم</button>
        </div>
      </form>
    </div>
  </div>

  <!-- TWO SUMMARY CARDS -->
  <div class="kpis-wrap">
    <div class="summary-card card-sales">
      <div class="title">عدد الفواتير (المسلمة)</div>
      <div class="value"><?php echo intval($total_invoices_period); ?></div>
      <div class="sub">عدد الفواتير المُسلمة خلال الفترة</div>
    </div>

    <div class="summary-card card-cost">
      <div class="title">إجمالي قيمة المبيعات</div>
      <div class="value"><?php echo number_format($total_sales_amount_period,2); ?> <span class="currency-badge">ج.م</span></div>
      <div class="sub">مجموع قيمة المبيعات خلال الفترة</div>
    </div>
  </div>

  <!-- TABLE -->
  <div class="table-card">
    <div class="table-responsive">
      <table id="reportTable" class="table table-hover table-striped mb-0 align-middle">
        <thead>
          <tr>
            <th>#</th>
            <th>رقم الفاتورة</th>
            <th>التاريخ</th>
            <th>اسم العميل</th>
            <th class="text-end">إجمالي الفاتورة</th>
            <th class="text-center">تفاصيل</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!empty($sales_data)): $c=1; foreach($sales_data as $inv): ?>
            <tr>
              <td><?php echo $c++; ?></td>
              <td>#<?php echo intval($inv['invoice_id']); ?></td>
              <td><?php echo date('Y-m-d H:i', strtotime($inv['invoice_date'])); ?></td>
              <td><?php echo htmlspecialchars($inv['customer_name']); ?></td>
              <td class="text-end fw-bold"><?php echo number_format(floatval($inv['invoice_total'] ?? 0),2); ?> ج.م</td>
              <td class="text-center">
                <a href="<?php echo BASE_URL; ?>invoices_out/view_invoice_detaiels.php?id=<?php echo intval($inv['invoice_id']); ?>" class="btn btn-sm btn-outline-info" title="تفاصيل الفاتورة"><i class="fas fa-eye"></i></a>
              </td>
            </tr>
          <?php endforeach; else: ?>
            <tr><td colspan="6" class="text-center small-muted p-4">لا توجد فواتير لعرضها في هذه الفترة</td></tr>
          <?php endif; ?>
        </tbody>
        <tfoot>
          <tr>
            <td colspan="4" class="text-end"><strong>الإجمالي الكلي:</strong></td>
            <td class="text-end fw-bold" id="table_total"><?php echo number_format($total_sales_amount_period,2); ?> ج.م</td>
            <td></td>
          </tr>
        </tfoot>
      </table>
    </div>
  </div>

  <div class="small-muted mt-3">تاريخ التحديث: <?php echo date('Y-m-d H:i'); ?></div>
</div>

<script>
(function(){
  // helpers
  const qs = s => document.querySelector(s);
  const qsa = s => Array.from(document.querySelectorAll(s));

  // Elements
  const pDay = qs('#pDay'), pWeek = qs('#pWeek'), pMonth = qs('#pMonth'), pCustom = qs('#pCustom');
  const startIn = qs('#start_date'), endIn = qs('#end_date'), periodInput = qs('#periodInput');
  const periodBtns = [pDay,pWeek,pMonth,pCustom];
  const filterForm = qs('#filterForm');

  // format date
  function formatDate(d){ return d.toISOString().slice(0,10); }
  const now = new Date();
  if (!startIn.value) startIn.value = formatDate(now);
  if (!endIn.value) endIn.value = formatDate(now);

  // set period and optionally auto-submit for day/week/month
  function setPeriod(period, autoSubmit = false){
    periodBtns.forEach(b=> b.classList.remove('active'));
    let now = new Date();
    let start = new Date(), end = new Date();

    if (period === 'day'){
      start = new Date(now); end = new Date(now);
      pDay.classList.add('active');
    } else if (period === 'week'){
      const day = now.getDay() || 7;
      start = new Date(now); start.setDate(now.getDate() - (day - 1));
      end = new Date(start); end.setDate(start.getDate() + 6);
      pWeek.classList.add('active');
    } else if (period === 'month'){
      start = new Date(now.getFullYear(), now.getMonth(), 1);
      end = new Date(now.getFullYear(), now.getMonth() + 1, 0);
      pMonth.classList.add('active');
    } else { // custom
      pCustom.classList.add('active');
      periodInput.value = 'custom';
      updatePeriodText();
      return;
    }
    startIn.value = formatDate(start);
    endIn.value = formatDate(end);
    periodInput.value = period;
    updatePeriodText();

    if (autoSubmit) {
      // submit the form immediately to load results
      setTimeout(()=> { filterForm.submit(); }, 120);
    }
  }

  function updatePeriodText(){
    const st = startIn.value || '';
    const ed = endIn.value || '';
    const el = document.getElementById('periodText');
    if (el) el.textContent = st + ' → ' + ed;
  }

  // initial activation based on GET or default 'day'
  const initialPeriod = '<?php echo addslashes($_GET['period'] ?? 'day'); ?>' || 'day';
  setPeriod(initialPeriod, false);

  // clicks: for day/week/month auto-submit; custom doesn't
  pDay.addEventListener('click', ()=> setPeriod('day', true));
  pWeek.addEventListener('click', ()=> setPeriod('week', true));
  pMonth.addEventListener('click', ()=> setPeriod('month', true));
  pCustom.addEventListener('click', ()=> { setPeriod('custom', false); startIn.focus(); });

  // today quick button
  qs('#todayBtn')?.addEventListener('click', ()=> { const t=formatDate(new Date()); startIn.value=t; endIn.value=t; periodInput.value='day'; setPeriod('day', true); });

  // update periodText when manually changing dates
  [startIn, endIn].forEach(el=> el.addEventListener('change', function(){
    periodInput.value = 'custom';
    setPeriod('custom', false);
    updatePeriodText();
  }));

  // refresh
  qs('#refreshBtn')?.addEventListener('click', ()=> location.reload());

  // calculate table total dynamically (in case server data differs)
  function calcTableTotal(){
    let total = 0;
    qsa('#reportTable tbody tr').forEach(tr=>{
      const tds = tr.querySelectorAll('td');
      if (!tds || tds.length < 5) return;
      const valText = tds[4].innerText.replace(/[^\d.,-]/g,'').replace(/,/g,'');
      const v = parseFloat(valText) || 0;
      total += v;
    });
    const outEl = qs('#table_total');
    if (outEl) outEl.textContent = total.toFixed(2) + ' ج.م';
  }
  calcTableTotal();

  // PRINT: build a minimal printable document that contains only period + invoices table + grand total
  qs('#printBtn')?.addEventListener('click', function(){
    const periodLabel = (document.getElementById('periodText')?.textContent) || (startIn.value + ' → ' + endIn.value);
    // collect rows
    const rows = [];
    let grand = 0;
    document.querySelectorAll('#reportTable tbody tr').forEach(tr=>{
      const tds = tr.querySelectorAll('td');
      if (!tds || tds.length < 5) return;
      const idx = tds[0].innerText.trim();
      const inv = tds[1].innerText.trim();
      const dt = tds[2].innerText.trim();
      const cust = tds[3].innerText.trim();
      const totalTxt = tds[4].innerText.replace(/[^\d.,-]/g,'').replace(/,/g,'').trim();
      const totalVal = parseFloat(totalTxt) || 0;
      grand += totalVal;
      rows.push([idx, inv, dt, cust, totalVal.toFixed(2)]);
    });

    // build html
    let html = `<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><title>طباعة تقرير المبيعات</title>
      <style>
        body{font-family:Arial, Helvetica, sans-serif;padding:18px;color:#111}
        h2{margin:0 0 8px}
        .meta{color:#555;margin-bottom:12px}
        table{width:100%;border-collapse:collapse;margin-top:8px}
        th,td{border:1px solid #ddd;padding:8px;text-align:right}
        thead th{background:#f6f8fb}
        tfoot td{font-weight:700}
      </style>
      </head><body>
    `;
    html += `<h2>تقرير المبيعات</h2><div class="meta">الفترة: <strong>${escapeHtml(periodLabel)}</strong></div>`;
    if (rows.length === 0) {
      html += '<div>لا توجد فواتير لعرضها.</div>';
    } else {
      html += `<table><thead><tr><th>#</th><th>رقم الفاتورة</th><th>التاريخ</th><th>العميل</th><th>إجمالي الفاتورة</th></tr></thead><tbody>`;
      rows.forEach(r=>{
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
    setTimeout(()=>{ w.print(); }, 350);
  });

  // escape helper
  function escapeHtml(s){ if (!s && s!==0) return ''; return String(s).replace(/[&<>"']/g,function(m){ return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[m]; }); }

  // highlight row UX
  qsa('#reportTable tbody tr').forEach(tr=> {
    tr.addEventListener('click', ()=> {
      qsa('#reportTable tbody tr').forEach(x=> x.style.outline='');
      tr.style.outline='2px solid rgba(11,132,255,0.06)';
    });
  });

})();
</script>
<!-- END: Themed report UI (updated) -->

<?php
$conn->close();
require_once BASE_DIR . 'partials/footer.php';
?>