<?php

require_once dirname(__DIR__) . '/config.php';
require_once BASE_DIR . 'partials/session_admin.php'; // أو session_user حسب إعدادك
require_once BASE_DIR . 'partials/header.php';
require_once BASE_DIR . 'partials/sidebar.php';

if (!isset($conn) || !$conn) {
    echo "<div class='container mt-5'><div class='alert alert-danger'>خطأ: اتصال قاعدة البيانات غير متوفر.</div></div>";
    require_once BASE_DIR . 'partials/footer.php';
    exit;
}

function e($s){ return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

// الحصول على id من GET
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

// جلب بنود الفاتورة مع اسم المنتج وكوده
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

// حساب الإجمالي النهائي
$total = 0.0;
foreach ($items as $it) {
    $total += floatval($it['total_price'] ?? 0);
}

// تنسيق التاريخ بطريقة آمنة (تجنّب 1970 إذا كانت القيمة غير صالحة)
function fmt_dt($raw) {
    if (!$raw) return '—';
    try {
        $d = new DateTime($raw);
        return $d->format('Y-m-d h:i A');
    } catch(Exception $e) {
        return htmlspecialchars($raw);
    }
}

// اختر رابط العودة المناسب (المرجع)
$back_link = $_SERVER['HTTP_REFERER'] ?? (BASE_URL . 'admin/pending_invoices.php');

?>
<div class="container mt-5 pt-3">
    <div class="card shadow-lg mb-4">
        <div class="card-header bg-dark text-white d-flex flex-column flex-md-row justify-content-between align-items-center">
            <h3 class="mb-2 mb-md-0"><i class="fas fa-file-invoice"></i> تفاصيل الفاتورة رقم: #<?php echo e($invoice['id']); ?></h3>
            <div>
                <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
                    <a href="<?php echo e(BASE_URL . 'invoices_out/edit.php?id=' . intval($invoice['id'])); ?>" class="btn btn-warning btn-sm me-2"><i class="fas fa-edit"></i> تعديل بيانات الفاتورة</a>
                <?php endif; ?>
                <button id="btnPrintInvoice" class="btn btn-secondary btn-sm"><i class="fas fa-print"></i> طباعة</button>
            </div>
        </div>

        <div class="card-body p-4">
            <div class="row">
                <div class="col-lg-6 mb-4" id="invoiceHeaderInfoCard">
                    <div class="card h-100">
                        <div class="card-header"><i class="fas fa-info-circle"></i> معلومات الفاتورة</div>
                        <ul class="list-group list-group-flush">
                            <li class="list-group-item"><b>رقم الفاتورة:</b> <?php echo e($invoice['id']); ?></li>
                            <li class="list-group-item"><b>المجموعة:</b> <?php echo e($invoice['invoice_group'] ?: '—'); ?></li>
                            <li class="list-group-item"><b>حالة التسليم:</b>
                                <?php if ($invoice['delivered']==='yes'): ?>
                                    <span class="badge bg-success">تم التسليم</span>
                                <?php else: ?>
                                    <span class="badge bg-warning text-dark">مؤجل</span>
                                <?php endif; ?>
                            </li>
                            <li class="list-group-item"><b>تاريخ الإنشاء:</b> <?php echo e(fmt_dt($invoice['created_at'] ?? '')); ?></li>
                            <li class="list-group-item"><b>تم الإنشاء بواسطة:</b> <?php echo e($invoice['creator_username'] ?? 'غير معروف'); ?></li>
                            <li class="list-group-item"><b>آخر تحديث لبيانات الفاتورة:</b> <?php echo e(fmt_dt($invoice['updated_at'] ?? $invoice['created_at'] ?? '')); ?></li>
                        </ul>
                    </div>
                </div>

                <div class="col-lg-6 mb-4">
                    <div class="card h-100">
                        <div class="card-header"><i class="fas fa-user-tag"></i> معلومات العميل</div>
                        <ul class="list-group list-group-flush">
                            <?php
                                $custName = $invoice['customer_name'] ?? '';
                                $custMobile = $invoice['customer_mobile'] ?? '';
                                $custCity = $invoice['customer_city'] ?? '';
                                $custAddress = $invoice['customer_address'] ?? '';
                                // اذا لم يوجد اسم عميل ربما تكون فاتورة نقديّة أو عميل محذوف
                                if (empty($custName)) {
                                    // إذا كانت هناك ملاحظة داخل notes تذكر "عميل نقدي"، نعرض ذلك
                                    $notes_lower = mb_strtolower($invoice['notes'] ?? '');
                                    if (strpos($notes_lower, 'عميل نقدي') !== false) {
                                        $custName = 'عميل نقدي';
                                    } else {
                                        $custName = 'غير محدد';
                                    }
                                }
                            ?>
                            <li class="list-group-item"><b>الاسم:</b> <?php echo e($custName); ?></li>
                            <li class="list-group-item"><b>الموبايل:</b> <?php echo e($custMobile ?: '—'); ?></li>
                            <li class="list-group-item"><b>المدينة:</b> <?php echo e($custCity ?: '—'); ?></li>
                            <li class="list-group-item"><b>العنوان:</b> <?php echo e($custAddress ?: '—'); ?></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- بنود الفاتورة -->
    <div class="card shadow-lg mb-4">
        <div class="card-header bg-light d-flex justify-content-between align-items-center">
            <h4><i class="fas fa-box-open"></i> بنود الفاتورة</h4>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped table-hover mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th>#</th>
                            <th>كود المنتج</th>
                            <th>اسم المنتج</th>
                            <th class="text-center">الكمية</th>
                            <th class="text-end">سعر الوحدة</th>
                            <th class="text-end">الإجمالي</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($items)): $idx = 0; ?>
                            <?php foreach ($items as $it): $idx++; ?>
                                <tr>
                                    <td><?php echo $idx; ?></td>
                                    <td><?php echo e($it['product_code'] ?: ('#' . intval($it['product_id']))); ?></td>
                                    <td><?php echo e($it['product_name'] ?: ('منتج #' . intval($it['product_id'])) ); ?></td>
                                    <td class="text-center"><?php echo number_format(floatval($it['quantity']), 2); ?></td>
                                    <td class="text-end"><?php echo number_format(floatval($it['selling_price']), 2); ?> ج.م</td>
                                    <td class="text-end fw-bold"><?php echo number_format(floatval($it['total_price']), 2); ?> ج.م</td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="6" class="text-center p-3">لا توجد بنود لهذه الفاتورة.</td></tr>
                        <?php endif; ?>
                    </tbody>
                    <tfoot>
                        <tr class="table-light">
                            <td colspan="5" class="text-end fw-bold fs-5">الإجمالي الكلي للفاتورة:</td>
                            <td class="text-end fw-bold fs-5"><?php echo number_format($total, 2); ?> ج.م</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <!-- ملاحظات الفاتورة -->
    <div class="card shadow-sm mb-4">
        <div class="card-header"><i class="fas fa-sticky-note"></i> ملاحظات الفاتورة</div>
        <div class="card-body">
            <?php if (!empty($invoice['notes'])): ?>
                <div class="mb-3"><?php echo nl2br(e($invoice['notes'])); ?></div>
                <button id="copyNotesBtn" class="btn btn-outline-secondary btn-sm">نسخ الملاحظات</button>
            <?php else: ?>
                <div class="text-muted">لا توجد ملاحظات لهذه الفاتورة.</div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card-footer text-muted text-center mt-4">
        <a href="<?php echo e($back_link); ?>" class="btn btn-outline-secondary"><i class="fas fa-arrow-left"></i> العودة للقائمة</a>
    </div>
</div>

<!-- منطقة الطباعة (مخفية عن الشاشة، تُستخدم عند طباعة الفاتورة) -->
<div id="invoicePrintableArea" style="display:none;">
    <div style="font-family: Arial, Helvetica, sans-serif; direction: rtl; text-align: right; padding:12px;">
        <h2>فاتورة مبيعات — رقم <?php echo e($invoice['id']); ?></h2>
        <div><strong>التاريخ:</strong> <?php echo e(fmt_dt($invoice['created_at'])); ?></div>
        <div style="margin-top:8px;"><strong>العميل:</strong> <?php echo e($custName); ?> — <?php echo e($custMobile ?: '—'); ?></div>
        <hr>
        <table style="width:100%; border-collapse: collapse;">
            <thead>
                <tr><th style="border:1px solid #ccc;padding:6px;text-align:right">المنتج</th>
                    <th style="border:1px solid #ccc;padding:6px;text-align:center">الكمية</th>
                    <th style="border:1px solid #ccc;padding:6px;text-align:right">سعر الوحدة</th>
                    <th style="border:1px solid #ccc;padding:6px;text-align:right">الإجمالي</th></tr>
            </thead>
            <tbody>
                <?php foreach ($items as $it): ?>
                    <tr>
                        <td style="border:1px solid #ccc;padding:6px;text-align:right"><?php echo e($it['product_name'] ?: ('#' . intval($it['product_id']))); ?></td>
                        <td style="border:1px solid #ccc;padding:6px;text-align:center"><?php echo number_format(floatval($it['quantity']),2); ?></td>
                        <td style="border:1px solid #ccc;padding:6px;text-align:right"><?php echo number_format(floatval($it['selling_price']),2); ?></td>
                        <td style="border:1px solid #ccc;padding:6px;text-align:right"><?php echo number_format(floatval($it['total_price']),2); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr><td colspan="3" style="text-align:right;padding:8px;border:1px solid #ccc"><strong>الإجمالي الكلي</strong></td>
                    <td style="text-align:right;padding:8px;border:1px solid #ccc"><strong><?php echo number_format($total,2); ?> ج.م</strong></td></tr>
            </tfoot>
        </table>
        <hr>
        <div><strong>ملاحظات:</strong></div>
        <div><?php echo nl2br(e($invoice['notes'] ?? '—')); ?></div>
    </div>
</div>

<script>
// نسخ الملاحظات
document.getElementById('copyNotesBtn')?.addEventListener('click', function(){
    const notes = <?php echo json_encode($invoice['notes'] ?? ''); ?>;
    if (!notes) return alert('لا توجد ملاحظات للنسخ.');
    navigator.clipboard?.writeText(notes).then(()=> {
        alert('تم نسخ الملاحظات.');
    }).catch(()=> {
        // fallback
        const ta = document.createElement('textarea'); ta.value = notes; document.body.appendChild(ta); ta.select();
        try { document.execCommand('copy'); alert('تم نسخ الملاحظات.'); } catch(e){ alert('نسخ فشل'); }
        ta.remove();
    });
});

// طباعة محتوى الفاتورة فقط
document.getElementById('btnPrintInvoice')?.addEventListener('click', function(){
    const printHtml = document.getElementById('invoicePrintableArea').innerHTML;
    const w = window.open('', '_blank');
    const css = `<style>
        body{font-family:Arial, Helvetica, sans-serif; direction:rtl; padding:12px}
        table{width:100%;border-collapse:collapse}
        th,td{padding:6px;border:1px solid #ccc}
    </style>`;
    w.document.open();
    w.document.write(`<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><title>طباعة الفاتورة #<?php echo e($invoice['id']); ?></title>${css}</head><body>${printHtml}</body></html>`);
    w.document.close();
    // تأخير صغير للسماح للنافذة بالتحميل قبل الطباعة
    setTimeout(()=> { w.print(); w.close(); }, 300);
});
</script>

<?php
require_once BASE_DIR . 'partials/footer.php';
?>
