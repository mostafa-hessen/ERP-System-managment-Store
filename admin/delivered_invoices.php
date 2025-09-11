<?php
// admin/delivered_invoices.php
$page_title = "الفواتير المستلمة";
$class_dashboard = "active";

require_once dirname(__DIR__) . '/config.php';
require_once BASE_DIR . 'partials/session_admin.php';

// ---------- Helpers ----------
function e($s){ return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function json_out($arr){ header('Content-Type: application/json; charset=utf-8'); echo json_encode($arr, JSON_UNESCAPED_UNICODE); exit; }

// CSRF token
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf_token = $_SESSION['csrf_token'];

// ------------------ AJAX endpoint: fetch invoice details (returns JSON) ------------------
if (isset($_GET['action']) && $_GET['action'] === 'fetch_invoice_details' && isset($_GET['id'])) {
    $inv_id = intval($_GET['id']);
    if ($inv_id <= 0) json_out(['success' => false, 'message' => 'invoice id invalid']);

    // Invoice header
    $st = $conn->prepare("
        SELECT io.*, COALESCE(c.name,'(عميل نقدي)') AS customer_name, c.mobile AS customer_mobile, c.city AS customer_city, c.address AS customer_address,
               u.username AS creator_name, u2.username AS updater_name
        FROM invoices_out io
        LEFT JOIN customers c ON io.customer_id = c.id
        LEFT JOIN users u ON io.created_by = u.id
        LEFT JOIN users u2 ON io.updated_by = u2.id
        WHERE io.id = ? LIMIT 1
    ");
    if (!$st) json_out(['success' => false, 'message' => 'prepare failed: '.$conn->error]);
    $st->bind_param("i", $inv_id);
    $st->execute();
    $h = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$h) json_out(['success' => false, 'message' => 'الفاتورة غير موجودة']);

    // Items
    $it = [];
    $s2 = $conn->prepare("SELECT i.*, p.name AS product_name, p.product_code FROM invoice_out_items i LEFT JOIN products p ON i.product_id = p.id WHERE i.invoice_out_id = ?");
    if ($s2) {
        $s2->bind_param("i", $inv_id);
        $s2->execute();
        $res2 = $s2->get_result();
        while ($r = $res2->fetch_assoc()) $it[] = $r;
        $s2->close();
    }

    json_out(['success' => true, 'invoice' => $h, 'items' => $it]);
}

// ------------------ page rendering ------------------
require_once BASE_DIR . 'partials/header.php';

$message = "";
$selected_group = "";
$result = null;
$grand_total_all_delivered = 0;
$displayed_invoices_sum = 0;

// receive PRG messages
if (isset($_SESSION['message'])) { $message = $_SESSION['message']; unset($_SESSION['message']); }

// ========== mark as pending (admin only) ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_pending'])) {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['message'] = "<div class='alert alert-danger'>خطأ: طلب غير صالح (CSRF).</div>";
        header("Location: " . BASE_URL . 'admin/delivered_invoices.php'); exit;
    }
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
        $_SESSION['message'] = "<div class='alert alert-danger'>ليس لديك صلاحية لتنفيذ هذا الإجراء.</div>";
        header("Location: " . BASE_URL . 'admin/delivered_invoices.php'); exit;
    }

    $invoice_id = intval($_POST['invoice_id'] ?? 0);
    if ($invoice_id <= 0) {
        $_SESSION['message'] = "<div class='alert alert-warning'>رقم فاتورة غير صالح.</div>";
        header("Location: " . BASE_URL . 'admin/delivered_invoices.php'); exit;
    }

    $updated_by = intval($_SESSION['id'] ?? 0);
    $sql_update = "UPDATE invoices_out SET delivered = 'no', updated_by = ?, updated_at = NOW() WHERE id = ? AND delivered = 'yes'";
    if ($stmt = $conn->prepare($sql_update)) {
        $stmt->bind_param("ii", $updated_by, $invoice_id);
        if ($stmt->execute()) {
            if ($stmt->affected_rows > 0) {
                $_SESSION['message'] = "<div class='alert alert-success'>تم إرجاع الفاتورة #{$invoice_id} إلى الفواتير المؤجلة بنجاح.</div>";
            } else {
                $_SESSION['message'] = "<div class='alert alert-warning'>لم يتم تعديل حالة الفاتورة — ربما كانت مُؤجلة بالفعل أو غير موجودة.</div>";
            }
        } else {
            $_SESSION['message'] = "<div class='alert alert-danger'>حدث خطأ أثناء تحديث الحالة: " . e($stmt->error) . "</div>";
        }
        $stmt->close();
    } else {
        $_SESSION['message'] = "<div class='alert alert-danger'>خطأ في تحضير استعلام التحديث: " . e($conn->error) . "</div>";
    }

    header("Location: " . BASE_URL . 'admin/delivered_invoices.php'); exit;
}

// ================= read filters/search =================
$search_invoice = isset($_REQUEST['q_invoice']) ? trim((string)$_REQUEST['q_invoice']) : '';
$search_mobile  = isset($_REQUEST['q_mobile'])  ? trim((string)$_REQUEST['q_mobile'])  : '';
$customer_filter = isset($_GET['customer_id']) ? intval($_GET['customer_id']) : 0;

if (!empty($_REQUEST['invoice_group_filter'])) {
    $selected_group = trim($_REQUEST['invoice_group_filter']);
} elseif (isset($_GET['filter_group_val'])) {
    $selected_group = trim($_GET['filter_group_val']);
}

// grand total (delivered)
$sql_grand_total = "SELECT COALESCE(SUM(ioi.total_price),0) AS grand_total
                    FROM invoice_out_items ioi
                    JOIN invoices_out io ON ioi.invoice_out_id = io.id
                    WHERE io.delivered = 'yes'";
$res_gt = $conn->query($sql_grand_total);
if ($res_gt) { $row_gt = $res_gt->fetch_assoc(); $grand_total_all_delivered = floatval($row_gt['grand_total'] ?? 0); $res_gt->free(); }

// build select
$sql_select_base = "
    SELECT i.id, i.invoice_group, i.created_at, i.delivered, i.updated_at as delivery_date,
           COALESCE(c.name, '(عميل نقدي)') as customer_name, COALESCE(c.mobile,'-') as customer_mobile, COALESCE(c.city,'-') as customer_city,
           u.username as creator_name,
           u_updater.username as delivered_by_user,
           COALESCE((SELECT SUM(item.total_price) FROM invoice_out_items item WHERE item.invoice_out_id = i.id),0) as invoice_total,
           i.customer_id
    FROM invoices_out i
    LEFT JOIN customers c ON i.customer_id = c.id
    LEFT JOIN users u ON i.created_by = u.id
    LEFT JOIN users u_updater ON i.updated_by = u_updater.id
    WHERE i.delivered = 'yes'
";

$params = []; $types = "";

// filters
if ($selected_group !== '') { $sql_select_base .= " AND i.invoice_group = ? "; $params[] = $selected_group; $types .= "s"; }
if ($customer_filter > 0) { $sql_select_base .= " AND i.customer_id = ? "; $params[] = $customer_filter; $types .= "i"; }

if ($search_invoice !== '') {
    $inv = preg_replace('/[^0-9]/', '', $search_invoice);
    if ($inv !== '') { $sql_select_base .= " AND i.id = ? "; $params[] = intval($inv); $types .= "i"; }
}
if ($search_mobile !== '') { $sql_select_base .= " AND COALESCE(c.mobile,'') LIKE ? "; $params[] = "%{$search_mobile}%"; $types .= "s"; }

$sql_select_base .= " ORDER BY i.updated_at DESC, i.id DESC LIMIT 1000";

if ($stmt_select = $conn->prepare($sql_select_base)) {
    if (!empty($params)) {
        // bind dynamically
        $stmt_select->bind_param($types, ...$params);
    }
    if ($stmt_select->execute()) {
        $result = $stmt_select->get_result();
    } else {
        $message .= "<div class='alert alert-danger'>خطأ أثناء تنفيذ البحث: " . e($stmt_select->error) . "</div>";
    }
    $stmt_select->close();
} else {
    $message .= "<div class='alert alert-danger'>خطأ في تحضير استعلام الفواتير: " . e($conn->error) . "</div>";
}

// links
$view_invoice_page_link = BASE_URL . "invoices_out/view_invoice_detaiels.php";
$pending_invoices_link = BASE_URL . "admin/pending_invoices.php";
$current_page_link = BASE_URL . 'admin/delivered_invoices.php';

require_once BASE_DIR . 'partials/sidebar.php';
?>

<style>
        #invoiceModal .mymodal {
        max-height: 570px;
        min-width: 60%;
        min-height: fit-content;
    }
.ipc-toast { position: fixed; right: 20px; bottom: 20px; background: #111827; color: #fff; padding: 8px 12px; border-radius: 8px; z-index: 16000; opacity: 0; transform: translateY(8px); transition: all .28s; }
.ipc-toast.show { opacity: 1; transform: translateY(0); }
/* .no-print { display: inline-block; } */
@media print {
  .no-print { display: none !important; }
}
</style>

<div class="container mt-5 pt-3">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1><i class="fas fa-check-double"></i> الفواتير المستلمة</h1>
            <?php if ($customer_filter > 0): ?>
                <div class="small-muted">عرض فواتير العميل رقم: <strong><?php echo e($customer_filter); ?></strong></div>
            <?php endif; ?>
        </div>

        <div class="d-flex gap-2">
            <button type="button" class="btn btn-outline-secondary" onclick="history.back();"><i class="fas fa-arrow-left"></i> عودة</button>
            <a href="<?php echo $pending_invoices_link; ?>" class="btn btn-warning"><i class="fas fa-truck-loading"></i> عرض الفواتير غير المستلمة</a>
        </div>
    </div>

    <?php echo $message; ?>

    <!-- search form -->
    <div class="card mb-4 shadow-sm">
        <div class="card-body">
            <form action="<?php echo $current_page_link; ?>" method="get" class="row gx-2 gy-2 align-items-center">
                <div class="col-md-3 mt-2">
                    <label class="form-label small mb-1" for="q_invoice">بحث برقم الفاتورة</label>
                    <input type="text" name="q_invoice" id="q_invoice" class="form-control" placeholder="مثال: 123 أو #123" value="<?php echo e($search_invoice); ?>">
                </div>
                <div class="col-md-3 mt-2">
                    <label class="form-label small mb-1" for="q_mobile">بحث برقم هاتف العميل</label>
                    <input type="text" name="q_mobile" id="q_mobile" class="form-control" placeholder="مثال: 011xxxxxxxx" value="<?php echo e($search_mobile); ?>">
                </div>
                <div class="col-md-3 mt-2">
                    <label class="form-label small mb-1" for="customer_id">فلتر حسب عميل (ID)</label>
                    <input type="number" name="customer_id" id="customer_id" class="form-control" placeholder="رقم العميل (مثال: 8)" value="<?php echo $customer_filter ? (int)$customer_filter : ''; ?>">
                </div>

                <div class="col-md-3 d-flex gap-2 align-items-end mt-3">
                    <button type="submit" name="search" class="btn btn-primary w-100"><i class="fas fa-search me-2"></i>بحث / تصفية</button>
                    <a href="<?php echo $current_page_link; ?>" class="btn btn-outline-secondary w-100"><i class="fas fa-undo me-2"></i>مسح</a>
                </div>
            </form>
            <div class="small mt-2 note-text">يمكنك البحث بالرقم الدقيق للفاتورة، جزء من رقم الموبايل أو فلترة حسب رقم العميل.</div>
        </div>
    </div>

    <!-- results table -->
    <div class="card shadow">
        <div class="card-header">
            قائمة الفواتير التي تم تسليمها
            <?php if(!empty($selected_group)) { echo " (المجموعة: " . e($selected_group) . ")"; } ?>
            <?php if($search_invoice || $search_mobile || $customer_filter): ?>
                <span class="badge bg-info ms-2">نتائج البحث</span>
            <?php endif; ?>
        </div>

        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped table-hover table-bordered align-middle mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th>رقم الفاتورة</th>
                            <th>اسم العميل</th>
                            <th>الموبايل</th>
                            <th>مجموعة الفاتورة</th>
                            <th class="d-none d-md-table-cell">تم التسليم بواسطة</th>
                            <th class="d-none d-md-table-cell">تاريخ التسليم</th>
                            <th class="text-end">إجمالي الفاتورة</th>
                            <th class="text-center">إجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result && $result->num_rows > 0): ?>
                            <?php while($row = $result->fetch_assoc()):
                                $current_invoice_total_for_row = floatval($row["invoice_total"] ?? 0);
                                $displayed_invoices_sum += $current_invoice_total_for_row;
                                $delivered_by = $row["delivered_by_user"] ?: $row["creator_name"] ?: '-';
                                $delivery_date = !empty($row["delivery_date"]) ? date('Y-m-d H:i A', strtotime($row["delivery_date"])) : '-';
                            ?>
                                <tr>
                                    <td>#<?php echo e($row["id"]); ?></td>
                                    <td>
                                        <?php echo e($row["customer_name"]); ?>
                                        <?php if(intval($row['customer_id']) === 0): ?><span class="small text-muted"> (نقدي)</span><?php endif; ?>
                                    </td>
                                    <td><?php echo e($row["customer_mobile"]); ?></td>
                                    <td><span class="badge bg-info"><?php echo e($row["invoice_group"]); ?></span></td>
                                    <td class="d-none d-md-table-cell"><?php echo e($delivered_by); ?></td>
                                    <td class="d-none d-md-table-cell"><?php echo e($delivery_date); ?></td>
                                    <td class="text-end fw-bold"><?php echo number_format($current_invoice_total_for_row, 2); ?> ج.م</td>
                                    <td class="text-center">
                                        <!-- open modal: uses AJAX to fetch details -->
                                        <button type="button" class="btn btn-info btn-sm btn-open-modal" data-invoice-id="<?php echo e($row["id"]); ?>" title="عرض التفاصيل"><i class="fas fa-eye"></i></button>

                                        <?php if (isset($_SESSION['role']) && $_SESSION['role'] == 'admin'): ?>
                                            <!-- return to pending -->
                                            <form method="post" action="<?php echo $current_page_link; ?>" class="d-inline ms-1" style="display:inline-block" onsubmit="return confirm('سيتم إرجاع الفاتورة #<?php echo e($row['id']); ?> إلى الفواتير المؤجلة. هل أنت متأكد؟');">
                                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                                <input type="hidden" name="invoice_id" value="<?php echo e($row["id"]); ?>">
                                                <button type="submit" name="mark_pending" class="btn btn-outline-secondary btn-sm" title="إرجاع للمؤجلة"><i class="fas fa-undo"></i></button>
                                            </form>

                                            <!-- delete -> calls delete_sales_invoice.php (keeps delivered->pending behaviour outside) -->
                                            <form action="<?php echo BASE_URL; ?>admin/delete_sales_invoice.php" method="post" class="d-inline ms-1" style="display:inline-block" onsubmit="return confirm('هل أنت متأكد من حذف الفاتورة #<?php echo e($row['id']); ?>؟ سيتم إعادة الكميات للمخزون.');">
                                                <input type="hidden" name="invoice_out_id_to_delete" value="<?php echo e($row["id"]); ?>">
                                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                                <input type="hidden" name="redirect_to" value="delivered">
                                                <button type="submit" name="delete_sales_invoice" class="btn btn-danger btn-sm" title="حذف"><i class="fas fa-trash"></i></button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8" class="text-center py-4">
                                    <?php
                                        if ($search_invoice || $search_mobile || $customer_filter) {
                                            echo "لا توجد نتائج مطابقة لبحثك.";
                                        } elseif (!empty($selected_group)) {
                                            echo "لا توجد فواتير مستلمة تطابق هذه المجموعة.";
                                        } else {
                                            echo "لا توجد فواتير مستلمة حالياً.";
                                        }
                                    ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- totals -->
    <div class="row mt-4">
        <div class="col-md-6 offset-md-6">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title text-center mb-3 note-text">ملخص الإجماليات</h5>
                    <ul class="list-group list-group-flush rounded">
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <strong>إجمالي الفواتير المعروضة حالياً:</strong>
                            <span class="badge bg-primary rounded-pill fs-6"><?php echo number_format($displayed_invoices_sum, 2); ?> ج.م</span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <strong>الإجمالي الكلي لجميع الفواتير المستلمة:</strong>
                            <span class="badge bg-success rounded-pill fs-6"><?php echo number_format($grand_total_all_delivered, 2); ?> ج.م</span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

</div>

<!-- ======= Enhanced modal (reuses AJAX endpoint above) ======= -->
<div id="invoiceModal" class="modal-backdrop" aria-hidden="true" aria-labelledby="modalTitle" role="dialog">
    <div class="modal-card mymodal" role="document" id="invoiceModalCard">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
            <h4 id="modalTitle">تفاصيل الفاتورة</h4>
            <div style="display:flex;gap:8px;align-items:center;">
                <div id="modalTotal" class="fw-bold" style="min-width:160px;text-align:left;"></div>

                <button id="modalPrintBtn" class="btn btn-secondary btn-sm" title="طباعة"><i class="fas fa-print"></i></button>

                <form id="modalDeliverForm" method="post" style="display:inline-block;">
                    <input type="hidden" name="invoice_id" id="modal_invoice_id_deliver" value="">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="mark_pending" value="1">
                    <button type="submit" name="do_mark_pending" class="btn btn-outline-secondary" id="modalDeliverBtn"><i class="fas fa-undo"></i> إعادة للمؤجلة</button>
                </form>

                <form id="modalDeleteForm" method="post" action="<?php echo BASE_URL; ?>admin/delete_sales_invoice.php" style="display:inline-block;" onsubmit="return confirm('تأكيد حذف الفاتورة؟ سيتم إعادة الكميات للمخزون.');">
                    <input type="hidden" name="invoice_out_id_to_delete" id="modal_invoice_id_delete" value="">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="redirect_to" value="delivered">
                    <button type="submit" name="delete_sales_invoice" class="btn btn-danger" id="modalDeleteBtn"><i class="fas fa-trash"></i> حذف</button>
                </form>
            </div>
        </div>

        <div id="modalContentArea">
            <div style="padding:20px;text-align:center;color:#6b7280;">جارٍ التحميل...</div>
        </div>

        <button id="modalClose" class="text-left mt-4 btn btn-outline-secondary btn-sm">إغلاق</button>
    </div>
</div>

<div id="ipc_toast_holder"></div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('invoiceModal');
    const modalCard = document.getElementById('invoiceModalCard');
    const modalClose = document.getElementById('modalClose');
    const modalContent = document.getElementById('modalContentArea');
    const modalTotal = document.getElementById('modalTotal');
    const deliverIdInput = document.getElementById('modal_invoice_id_deliver');
    const deleteIdInput = document.getElementById('modal_invoice_id_delete');
    const printBtn = document.getElementById('modalPrintBtn');
    const toastHolder = document.getElementById('ipc_toast_holder');

    function showModal(){ modal.style.display = 'flex'; modal.setAttribute('aria-hidden','false'); window.scrollTo({top:0,behavior:'smooth'}); }
    function hideModal(){ modal.style.display = 'none'; modal.setAttribute('aria-hidden','true'); modalContent.innerHTML=''; modalTotal.innerText=''; deliverIdInput.value=''; deleteIdInput.value=''; }

    modalClose.addEventListener('click', hideModal);
    modal.addEventListener('click', function(e){ if (e.target === modal) hideModal(); });

    function showToast(msg, type='info', ms=3000){
        const t = document.createElement('div');
        t.className = 'ipc-toast';
        if (type === 'success') t.style.background = 'linear-gradient(90deg,#10b981,#059669)';
        if (type === 'error') t.style.background = 'linear-gradient(90deg,#ef4444,#dc2626)';
        t.innerText = msg;
        toastHolder.appendChild(t);
        requestAnimationFrame(()=> t.classList.add('show'));
        setTimeout(()=> { t.classList.remove('show'); setTimeout(()=> t.remove(), 350); }, ms);
    }

    document.querySelectorAll('.btn-open-modal').forEach(btn=>{
        btn.addEventListener('click', async function(){
            const invId = parseInt(this.dataset.invoiceId || 0,10);
            if (!invId) { showToast('معرّف الفاتورة غير صالح','error'); return; }
            modalContent.innerHTML = '<div style="padding:30px;text-align:center;color:#6b7280">جارٍ التحميل...</div>';
            showModal();

            try {
                const url = location.pathname + '?action=fetch_invoice_details&id=' + encodeURIComponent(invId);
                const res = await fetch(url, { credentials: 'same-origin' });
                const contentType = res.headers.get('content-type') || '';
                const txt = await res.text();

                if (contentType.includes('application/json')) {
                    const data = JSON.parse(txt);
                    if (!data.success) { showToast(data.message || 'خطأ عند جلب التفاصيل','error'); modalContent.innerHTML = '<div style="padding:20px;color:#b91c1c">الفاتورة غير موجودة أو حدث خطأ.</div>'; return; }
                    buildModalFromJson(data.invoice, data.items);
                } else {
                    console.error('Non-JSON response:', txt);
                    modalContent.innerHTML = '<div style="padding:20px;color:#b91c1c">استجابة غير متوقعة من السيرفر. افتح Console لرؤية التفاصيل.</div>';
                }
            } catch (err) {
                console.error('fetch error:', err);
                modalContent.innerHTML = '<div style="padding:20px;color:#b91c1c">خطأ في الاتصال عند جلب تفاصيل الفاتورة.</div>';
            }
        });
    });

    function buildModalFromJson(inv, items){
        // header
        const titleHtml = `
          <div style="display:flex;justify-content:space-between;gap:12px;align-items:center;">
            <div style="flex:1">
              <div style="font-weight:700;font-size:1.05rem">فاتورة مبيعات — <span style="color:var(--bs-primary,#0d6efd)">#${escapeHtml(inv.id)}</span></div>
              <div style="font-size:0.85rem;color:#6b7280">تاريخ الإنشاء: ${escapeHtml(fmt_dt(inv.created_at))}</div>
            </div>
            <div style="text-align:left">
              ${inv.delivered === 'yes' ? '<span style="display:inline-block;padding:6px 12px;border-radius:24px;background:linear-gradient(135deg,#22c55e,#16a34a);color:#fff">تم الدفع</span>' : '<span style="display:inline-block;padding:6px 12px;border-radius:24px;background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff">مؤجل</span>'}
            </div>
          </div>
        `;

        // info cards
        const infoHtml = `
          <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:12px;">
            <div style="flex:1;min-width:220px;padding:12px;border-radius:10px;background:var(--card-bg,rgba(0,0,0,0.03))">
              <div style="font-weight:700;margin-bottom:6px">معلومات الفاتورة</div>
              <div><strong>المجموعة:</strong> ${escapeHtml(inv.invoice_group || '—')}</div>
              <div><strong>منشأ الفاتورة:</strong> ${escapeHtml(inv.creator_name || '-')}</div>
              <div><strong>آخر تحديث:</strong> ${escapeHtml(fmt_dt(inv.updated_at || inv.created_at))}</div>
            </div>
            <div style="flex:1;min-width:220px;padding:12px;border-radius:10px;background:var(--card-bg,rgba(0,0,0,0.03))">
              <div style="font-weight:700;margin-bottom:6px">معلومات العميل</div>
              <div><strong>الاسم:</strong> ${escapeHtml(inv.customer_name || 'غير محدد')}</div>
              <div><strong>الموبايل:</strong> ${escapeHtml(inv.customer_mobile || '—')}</div>
              <div><strong>المدينة:</strong> ${escapeHtml(inv.customer_city || '—')}</div>
            </div>
          </div>
        `;

        // items table
        let itemsHtml = `<div style="margin-top:14px;border-radius:10px;overflow:hidden;border:1px solid rgba(0,0,0,0.06)"><table style="width:100%;border-collapse:collapse;font-size:0.95rem;"><thead style="background:rgba(0,0,0,0.03);font-weight:700;"><tr><th style="padding:10px;width:40px">#</th><th style="padding:10px;text-align:right">اسم / كود</th><th style="padding:10px;width:100px;text-align:center">كمية</th><th style="padding:10px;width:120px;text-align:right">سعر الوحدة</th><th style="padding:10px;width:120px;text-align:right">الإجمالي</th></tr></thead><tbody>`;
        let total = 0;
        if (items && items.length) {
            items.forEach((it, idx) => {
                const name = it.product_name ? (it.product_name + ' — ' + (it.product_code || '')) : ('#' + it.product_id);
                const qty = parseFloat(it.quantity || 0).toFixed(2);
                const price = parseFloat(it.selling_price || it.cost_price_per_unit || 0).toFixed(2);
                const line = parseFloat(it.total_price || 0).toFixed(2);
                total += parseFloat(line);
                itemsHtml += `<tr><td style="padding:10px">${idx+1}</td><td style="padding:10px;text-align:right">${escapeHtml(name)}</td><td style="padding:10px;text-align:center">${qty}</td><td style="padding:10px;text-align:right">${price} ج.م</td><td style="padding:10px;text-align:right;font-weight:700">${line} ج.م</td></tr>`;
            });
        } else {
            itemsHtml += `<tr><td colspan="5" style="padding:12px;text-align:center">لا توجد بنود.</td></tr>`;
        }
        itemsHtml += `</tbody><tfoot><tr><td colspan="4" style="padding:12px;text-align:right;font-weight:700">الإجمالي الكلي</td><td style="padding:12px;text-align:right;font-weight:800">${total.toFixed(2)} ج.م</td></tr></tfoot></table></div>`;

        // notes (add .no-print so not printed)
        let notesHtml = '';
        if (inv.notes && inv.notes.trim() !== '') {
            notesHtml = `<div class="no-print" style="margin-top:12px;padding:12px;border-radius:8px;background:rgba(0,0,0,0.02)"><div style="font-weight:700;margin-bottom:8px">ملاحظات</div><div style="white-space:pre-wrap;">${escapeHtml(inv.notes).replace(/\n/g,'<br>')}</div><div style="margin-top:8px"><button class="btn-copy-notes btn btn-outline-secondary btn-sm" data-notes="${escapeHtml(inv.notes)}">نسخ الملاحظات</button></div></div>`;
        }

        modalContent.innerHTML = titleHtml + infoHtml + itemsHtml + notesHtml;

        // set forms
        deliverIdInput.value = inv.id;
        deleteIdInput.value = inv.id;
        modalTotal.innerText = 'الإجمالي: ' + total.toFixed(2) + ' ج.م';

        // copy notes handler
        const copyBtn = modalContent.querySelector('.btn-copy-notes');
        if (copyBtn) copyBtn.addEventListener('click', function(){ const notes = this.dataset.notes || ''; if(!notes) return showToast('لا توجد ملاحظات','error'); navigator.clipboard?.writeText(notes).then(()=> showToast('تم نسخ الملاحظات','success')).catch(()=> alert('نسخ فشل')); });

        showModal();
    }

    // print modal content excluding .no-print elements
    printBtn.addEventListener('click', function(){
        try {
            const clone = modalContent.cloneNode(true);
            // remove all no-print elements from clone
            clone.querySelectorAll('.no-print').forEach(n=>n.remove());
            const html = `<html lang="ar" dir="rtl"><head><meta charset="utf-8"><title>طباعة فاتورة</title><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{font-family:Arial,Helvetica,sans-serif;direction:rtl;padding:18px;} table{width:100%;border-collapse:collapse;} th,td{border:1px solid #ddd;padding:8px;text-align:right;} th{background:#f3f4f6;font-weight:700;} tfoot td{font-weight:800;}</style></head><body>${clone.innerHTML}</body></html>`;
            const iframe = document.createElement('iframe');
            iframe.style.position = 'fixed'; iframe.style.right = '0'; iframe.style.bottom = '0'; iframe.style.width = '0'; iframe.style.height = '0'; iframe.style.border = '0';
            document.body.appendChild(iframe);
            const d = iframe.contentWindow.document; d.open(); d.write(html); d.close();
            iframe.onload = function(){ try { iframe.contentWindow.focus(); setTimeout(()=>{ iframe.contentWindow.print(); setTimeout(()=>document.body.removeChild(iframe),500); }, 200); } catch(e){ document.body.removeChild(iframe); } };
            setTimeout(()=>{ if (document.body.contains(iframe)){ try{ iframe.contentWindow.print(); document.body.removeChild(iframe); }catch(e){ if(document.body.contains(iframe)) document.body.removeChild(iframe);} } }, 1500);
        } catch (e) { console.error('print error', e); alert('حدث خطأ أثناء الطباعة'); }
    });

    // util
    function escapeHtml(s){ if (s === null || s === undefined) return ''; return String(s).replace(/[&<>"']/g, function(m){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]; }); }
    function fmt_dt(raw){ if (!raw) return '—'; try { const d = new Date(raw); return d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0') + ' ' + d.toLocaleTimeString(); } catch(e){ return raw; } }

    // expose
    window.openInvoiceModal = function(id){ const btn = document.querySelector('.btn-open-modal[data-invoice-id="'+id+'"]'); if (btn) btn.click(); }
});
</script>

<?php
// free resources
if ($result && is_object($result)) $result->free();
$conn->close();
require_once BASE_DIR . 'partials/footer.php';
?>
