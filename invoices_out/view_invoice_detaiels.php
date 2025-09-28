<?php

require_once dirname(__DIR__) . '/config.php';
require_once BASE_DIR . 'partials/session_admin.php';
require_once BASE_DIR . 'partials/header.php';
require_once BASE_DIR . 'partials/sidebar.php';

if (!isset($conn) || !$conn) {
    echo "<div class='container mt-5'><div class='alert alert-danger'>خطأ: اتصال قاعدة البيانات غير متوفر.</div></div>";
    require_once BASE_DIR . 'partials/footer.php';
    exit;
}

function e($s){ return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

// id
$invoice_id = isset($_GET['id']) && is_numeric($_GET['id']) ? intval($_GET['id']) : 0;
if ($invoice_id <= 0) {
    echo "<div class='container mt-5'><div class='alert alert-warning'>معرّف الفاتورة غير صحيح.</div>
          <a href='javascript:history.back()' class='btn btn-outline-secondary mt-3'>العودة</a></div>";
    require_once BASE_DIR . 'partials/footer.php';
    exit;
}

// جلب بيانات الفاتورة
$invoice = null;
$stmt = $conn->prepare("
    SELECT i.*, 
           c.name AS customer_name, c.mobile AS customer_mobile, c.city AS customer_city, c.address AS customer_address,
           u.username AS creator_username,
           u2.username AS updater_username
    FROM invoices_out i
    LEFT JOIN customers c ON i.customer_id = c.id
    LEFT JOIN users u ON i.created_by = u.id
    LEFT JOIN users u2 ON i.updated_by = u2.id
    WHERE i.id = ? LIMIT 1
");
if ($stmt) {
    $stmt->bind_param("i", $invoice_id);
    $stmt->execute();
    $invoice = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if (!$invoice) {
    echo "<div class='container mt-5'><div class='alert alert-warning'>لم تستطع النظام إيجاد الفاتورة المطلوبة (#" . e($invoice_id) . ").</div>
          <a href='javascript:history.back()' class='btn btn-outline-secondary mt-3'>العودة</a></div>";
    require_once BASE_DIR . 'partials/footer.php';
    exit;
}

// بنود الفاتورة
$items = [];
$stmt2 = $conn->prepare("
    SELECT ii.*, p.product_code, p.name AS product_name
    FROM invoice_out_items ii
    LEFT JOIN products p ON ii.product_id = p.id
    WHERE ii.invoice_out_id = ?
    ORDER BY ii.id ASC
");
if ($stmt2) {
    $stmt2->bind_param("i", $invoice_id);
    $stmt2->execute();
    $res2 = $stmt2->get_result();
    while ($r = $res2->fetch_assoc()) $items[] = $r;
    $stmt2->close();
}

$total = 0.0;
foreach ($items as $it) {
    $total += floatval($it['total_price'] ?? 0);
}

function fmt_dt($raw) {
    if (!$raw) return '—';
    try {
        $d = new DateTime($raw);
        return $d->format('Y-m-d h:i A');
    } catch(Exception $e) {
        return htmlspecialchars($raw);
    }
}

$back_link = $_SERVER['HTTP_REFERER'] ?? (BASE_URL . 'admin/pending_invoices.php');

?>
<!-- ===================== تصميم و CSS محسّن ===================== -->
<style>


/* page layout */
/* body { background: var(--bg); color: var(--text); font-family: "Segoe UI", Tahoma, Arial; direction: rtl; } */
/* container */
.container { 
  max-width: 1100px; 
  margin: 0 auto; 
  padding: 22px; 
  color: var(--text);
}

/* card */
.card {
  background: linear-gradient(180deg, rgba(255,255,255,0.6), rgba(255,255,255,0.25));
  border-radius: var(--radius);
  box-shadow: var(--shadow-1);
  border: 1px solid var(--border);
  overflow: hidden;
  margin-bottom: 22px;
  transition: background var(--fast), box-shadow var(--fast);
}
[data-app][data-theme="dark"] .card {
  background: var(--surface);
  border: 1px solid rgba(255,255,255,0.03);
}

/* header */
.card-header {
  padding: 18px 20px;
  background: var(--surface-2);
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:12px;
  color: var(--text);
}
.header-title { 
  display:flex; 
  align-items:center; 
  gap:12px; 
  font-size:1.15rem; 
  font-weight:600; 
  color: var(--text);
}
.logo-badge {
  min-width:46px; height:46px; border-radius:10px;
  background: var(--grad-1); 
  display:flex; 
  align-items:center; 
  justify-content:center;
  color:white; 
  font-weight:700; 
  box-shadow: var(--shadow-2);
}

/* info cards */
.info-card { 
  padding:14px; 
  border-radius: var(--radius-sm); 
  background: linear-gradient(180deg, rgba(255,255,255,0.6), rgba(255,255,255,0.2)); 
  color: var(--text);
}
[data-app][data-theme="dark"] .info-card { 
  background: transparent; 
  border: 1px solid rgba(255,255,255,0.05);
  color: var(--text);
}

/* badges */
.badge {
  display:inline-block; 
  padding:6px 10px; 
  border-radius:8px; 
  font-weight:600;
  font-size: 0.85rem;
}

/* table */
.table { 
  width:100%; 
  border-collapse: collapse; 
  font-size:0.96rem; 
  /* ترك خلايا الجدول بدون تعديل */
}
.table thead th { 
  background: linear-gradient(90deg,
   rgba(0,0,0,0.03), rgba(0,0,0,0.01)); 
  padding:12px; 
  text-align:right; 
  font-weight:700; 
  color: var(--text-soft);
}
.table tbody td { 
  padding:12px; 
  border-bottom:1px solid rgba(0,0,0,0.04); 
  vertical-align:middle; 
}
[data-app][data-theme="dark"] .table tbody td { 
  border-bottom:1px solid rgba(255,255,255,0.03); 
}
.table-striped tbody tr:nth-child(even) {
  background: rgba(0,0,0,0.02);
}
[data-app][data-theme="dark"] .table-striped tbody tr:nth-child(even) {
  background: rgba(255,255,255,0.02);
}

/* totals */
.totals { 
  display:flex; 
  justify-content:flex-end; 
  gap:18px; 
  align-items:center; 
  padding:16px; 
  font-weight:700; 
  font-size: 1.05rem;
  color: var(--primary-700);
}
[data-app][data-theme="dark"] .totals {
  color: var(--primary);
}

/* footer */
.card-footer {
  padding: 16px;
  background: var(--surface-2);
  text-align: center;
  color: var(--text);
}

/* override لكل النصوص داخل container (ماعدا table cells) */
.container :not(.table *) {
  color: var(--text);
}

/* dark mode */
[data-app][data-theme="dark"] .container :not(.table *) {
  color: var(--text);
}


.invoice-detiales .badge {
  display: inline-block;
  padding: 6px 12px;
  border-radius: 30px;
  font-size: 14px;
  font-weight: 600;
  letter-spacing: 0.3px;
  color: white;
  box-shadow: 0 4px 10px rgba(0,0,0,0.08);
  transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.invoice-detiales .badge:hover {
  transform: translateY(-2px);
  box-shadow: 0 6px 14px rgba(0,0,0,0.15);
}

/* الحالات */
.invoice-detiales .badge.badge-success {
  background: linear-gradient(135deg, #22c55e, #16a34a); /* أخضر */
}

.invoice-detiales .badge.badge-warning {
  background: linear-gradient(135deg, #fbbf24, #f59e0b); /* أصفر */
}


/* info cards */

/* print styles (for iframe print we include similar CSS) */
@media print {
  body { background: white; color: black; }
  .no-print { display:none !important; }
}

/* small responsive tweaks */
@media (max-width: 768px) {
  .container { padding:12px; }
  .card-header { flex-direction:column; align-items:flex-start; gap:8px; }
}
</style>
<div class="invoice-detiales">

<div class="container mt-4">
  <div class="card shadow-lg mb-4">
    <div class="card-header">
      <div class="header-title">
        <div class="logo-badge">فات</div>
        <div>
          <div style="font-size:1.05rem;">تفاصيل الفاتورة — <span style="color:var(--primary);">#<?php echo e($invoice['id']); ?></span></div>
          <div style="font-size:0.85rem;color:var(--muted);">تاريخ الإنشاء: <?php echo e(fmt_dt($invoice['created_at'] ?? '')); ?></div>
        </div>
      </div>

      <div style="display:flex; gap:8px; align-items:center;">
        <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
          <!-- <a href="<?php echo e(BASE_URL . 'invoices_out/edit.php?id=' . intval($invoice['id'])); ?>" class="btn btn-sm" style="padding:8px 12px;background:var(--amber);color:#fff;border-radius:10px; text-decoration:none;">تعديل</a> -->
        <?php endif; ?>
        <button id="btnPrintInvoice" class="btn btn-sm" style="padding:8px 12px;background:transparent;border:1px solid var(--border);border-radius:10px;">طباعة</button>
        <a href="<?php echo e($back_link); ?>" class="btn btn-sm" style="padding:8px 12px;background:transparent;border:1px solid var(--border);border-radius:10px;">العودة</a>
      </div>
    </div>

    <div class="card-body p-4">
      <div class="row" style="display:flex; gap:16px; flex-wrap:wrap;">
        <div style="flex:1; min-width:260px;">
          <div class="info-card">
            <h4 style="margin:0 0 8px 0;">معلومات الفاتورة</h4>
            <div style="display:flex; flex-direction:column; gap:8px;">
              <div><strong>المجموعة:</strong> <?php echo e($invoice['invoice_group'] ?: '—'); ?></div>
              <div>
                <strong>حالة:</strong>
                <?php if ($invoice['delivered'] === 'yes'): ?>
                  <span class="badge badge-success">تم الدفع</span>
                <?php else: ?>
                  <span class="badge badge-warning">مؤجل</span>
                <?php endif; ?>
              </div>
              <div><strong>تم الإنشاء بواسطة:</strong> <?php echo e($invoice['creator_username'] ?? 'غير معروف'); ?></div>
              <div><strong>آخر تحديث:</strong> <?php echo e(fmt_dt($invoice['updated_at'] ?? $invoice['created_at'] ?? '')); ?></div>
            </div>
          </div>
        </div>

        <div style="flex:1; min-width:260px;">
          <div class="info-card">
            <h4 style="margin:0 0 8px 0;">معلومات العميل</h4>
            <?php
              $custName = $invoice['customer_name'] ?? '';
              $custMobile = $invoice['customer_mobile'] ?? '';
              $custCity = $invoice['customer_city'] ?? '';
              $custAddress = $invoice['customer_address'] ?? '';
              if (empty($custName)) {
                  $notes_lower = mb_strtolower($invoice['notes'] ?? '');
                  if (strpos($notes_lower, 'عميل نقدي') !== false) {
                      $custName = 'عميل نقدي';
                  } else {
                      $custName = 'غير محدد';
                  }
              }
            ?>
            <div style="display:flex; flex-direction:column; gap:6px;">
              <div><strong>الاسم:</strong> <?php echo e($custName); ?></div>
              <div><strong>الموبايل:</strong> <?php echo e($custMobile ?: '—'); ?></div>
              <div><strong>المدينة:</strong> <?php echo e($custCity ?: '—'); ?></div>
              <div><strong>العنوان:</strong> <?php echo e($custAddress ?: '—'); ?></div>
            </div>
          </div>
        </div>
      </div>

      <!-- بنود -->
      <div style="margin-top:18px;">
        <div style="border-radius:10px; overflow:hidden; border:1px solid var(--border);" class="custom-table-wrapper">
          <table class="ta=ble  custom-table" aria-labelledby="itemsTitle">
            <thead class="center">
              <tr>
                <th style="width:40px;">#</th>
                <th>اسم / كود</th>
                <th class="text-center">كمية</th>
                <th class="text-end">سعر البيع</th>
                <th class="text-end">الإجمالي</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!empty($items)): $idx = 0; ?>
                <?php foreach ($items as $it): $idx++; ?>
                  <tr>
                    <td><?php echo $idx; ?></td>
                    <td style="text-align:right;"><?php echo e(($it['product_name'] ?: ('#' . intval($it['product_id']))) . ' — ' . ($it['product_code'] ?: '')); ?></td>
                    <td class="text-center"><?php echo number_format(floatval($it['quantity']), 2); ?></td>
                    <td class="text-end"><?php echo number_format(floatval($it['selling_price']), 2); ?> ج.م</td>
                    <td class="text-end fw-bold"><?php echo number_format(floatval($it['total_price']), 2); ?> ج.م</td>
                  </tr>
                <?php endforeach; ?>
              <?php else: ?>
                <tr><td colspan="5" class="text-center p-3">لا توجد بنود لهذه الفاتورة.</td></tr>
              <?php endif; ?>
            </tbody>
            <tfoot>
              <tr>
                <td colspan="4" class="text-end" style="padding:14px;"><strong>الإجمالي الكلي</strong></td>
                <td class="text-end" style="padding:14px;"><strong><?php echo number_format($total, 2); ?> ج.م</strong></td>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>

      <!-- ملاحظات (لا تُطبع) -->
      <div class="card shadow-sm mt-4 no-print" style="padding:12px;">
        <div style="font-weight:700;margin-bottom:8px;"><i class="fas fa-sticky-note"></i> ملاحظات الفاتورة</div>
        <div>
          <?php if (!empty($invoice['notes'])): ?>
            <div class="mb-2" style="white-space:pre-wrap;"><?php echo nl2br(e($invoice['notes'])); ?></div>
            <button id="copyNotesBtn" class="btn" style="padding:6px 10px;border:1px solid var(--border);border-radius:8px;">نسخ الملاحظات</button>
          <?php else: ?>
            <div class="custom-text">لا توجد ملاحظات.</div>
          <?php endif; ?>
        </div>
      </div>

    </div>

    <div class="card-footer text-muted text-center mt-3 no-print" style="padding:12px;">
      <small>عرض الفاتورة رقم <?php echo e($invoice['id']); ?></small>
    </div>
  </div>
</div>
</div>

<!-- لا نعرض منطقة طباعة خام في الـ DOM، لأننا نستخدم IFRAME ديناميكي -->
<script>
(function(){
  // نسخ الملاحظات
  document.getElementById('copyNotesBtn')?.addEventListener('click', function(){
    const notes = <?php echo json_encode($invoice['notes'] ?? ''); ?>;
    if (!notes) return alert('لا توجد ملاحظات للنسخ.');
    navigator.clipboard?.writeText(notes).then(()=> { alert('تم نسخ الملاحظات.'); })
      .catch(()=> {
        const ta = document.createElement('textarea'); ta.value = notes; document.body.appendChild(ta); ta.select();
        try { document.execCommand('copy'); alert('تم نسخ الملاحظات.'); } catch(e){ alert('نسخ فشل'); }
        ta.remove();
      });
  });

  // وظيفة الطباعة باستخدام IFRAME (تمنع تهنيج الصفحة)
  document.getElementById('btnPrintInvoice')?.addEventListener('click', function(){
    try {
      // بناء HTML للطباعة (نستبعد الملاحظات عمداً)
      const invoiceHtml = `
        <!doctype html>
        <html lang="ar" dir="rtl">
        <head>
          <meta charset="utf-8">
          <title>طباعة الفاتورة #<?php echo e($invoice['id']); ?></title>
          <meta name="viewport" content="width=device-width,initial-scale=1">
          <style>
            :root {
              --text: #0f172a; --muted: #64748b; --border: #ddd;
            }
            body{font-family:Arial, Helvetica, sans-serif; direction:rtl; padding:18px; color:var(--text);}
            .header{display:flex; justify-content:space-between; align-items:center; gap:12px; margin-bottom:12px;}
            .header .title {font-weight:700; font-size:18px;}
            .badge {display:inline-block;padding:6px 10px;border-radius:8px;font-weight:700;}
            .badge-success{background:#e6fffa;color:#047857;border:1px solid rgba(16,185,129,0.12);}
            .badge-warning{background:#fff7ed;color:#92400e;border:1px solid rgba(245,158,11,0.12);}
            table{width:100%;border-collapse:collapse;margin-top:8px;}
            th, td {border:1px solid var(--border); padding:8px; text-align:right;}
            th{background:#f3f4f6;font-weight:700;}
            tfoot td{font-weight:800;}
          </style>
        </head>
        <body>
          <div class="header">
            <div>
              <div class="title">فاتورة مبيعات — رقم <?php echo e($invoice['id']); ?></div>
              <div style="font-size:13px;color:var(--muted);">التاريخ: <?php echo e(fmt_dt($invoice['created_at'])); ?></div>
              <div style="font-size:13px;color:var(--muted);">العميل: <?php echo e($custName); ?> — <?php echo e($custMobile ?: '—'); ?></div>
            </div>
            <div>
              <!-- حالة الفاتورة -->
              <?php if ($invoice['delivered'] === 'yes'): ?>
                <div class="badge badge-success">تم الدفع</div>
              <?php else: ?>
                <div class="badge badge-warning">مؤجل</div>
              <?php endif; ?>
            </div>
          </div>

          <table>
            <thead><tr>
              <th>المنتج</th><th style="width:80px">الكمية</th><th style="width:110px">سعر الوحدة</th><th style="width:120px">الإجمالي</th>
            </tr></thead>
            <tbody>
              <?php foreach ($items as $it): ?>
                <tr>
                  <td><?php echo e($it['product_name'] ?: ('#' . intval($it['product_id']))); ?></td>
                  <td style="text-align:center"><?php echo number_format(floatval($it['quantity']),2); ?></td>
                  <td style="text-align:right"><?php echo number_format(floatval($it['selling_price']),2); ?></td>
                  <td style="text-align:right"><?php echo number_format(floatval($it['total_price']),2); ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
            <tfoot>
              <tr>
                <td colspan="3" style="text-align:right">الإجمالي الكلي</td>
                <td style="text-align:right"><?php echo number_format($total,2); ?> ج.م</td>
              </tr>
            </tfoot>
          </table>

          <!-- ملاحظة: الملاحظات لا تُطبَع حسب المطلوب -->
        </body>
        </html>
      `;

      // انشاء iframe مخفي
      const iframe = document.createElement('iframe');
      iframe.style.position = 'fixed';
      iframe.style.right = '0';
      iframe.style.bottom = '0';
      iframe.style.width = '0';
      iframe.style.height = '0';
      iframe.style.border = '0';
      document.body.appendChild(iframe);

      const doc = iframe.contentWindow.document;
      doc.open();
      doc.write(invoiceHtml);
      doc.close();

      // انتظر تحميل المحتوى ثم اطبع ثم ازل الـ iframe
      iframe.onload = function(){
        try {
          iframe.contentWindow.focus();
          // بعض المتصفحات تحتاج وقت بسيط لتجهيز قبل الاستدعاء
          setTimeout(function(){
            iframe.contentWindow.print();
            // بعد الطباعة نزال iframe
            setTimeout(function(){ document.body.removeChild(iframe); }, 500);
          }, 200);
        } catch (err) {
          console.error('طباعة فشلت', err);
          document.body.removeChild(iframe);
          alert('حدث خطأ أثناء الطباعة.');
        }
      };

      // حماية: اذا لم يحدث onload خلال 1.5 ثانية نُحاول الطباعة أيضاً
      setTimeout(function(){
        if (document.body.contains(iframe)) {
          try { iframe.contentWindow.focus(); iframe.contentWindow.print(); document.body.removeChild(iframe); }
          catch(e){ console.error(e); }
        }
      }, 1500);

    } catch (ex) {
      console.error(ex);
      alert('حدث خطأ أثناء تجهيز الطباعة.');
    }
  });
})();
</script>

<?php
require_once BASE_DIR . 'partials/footer.php';
?>
