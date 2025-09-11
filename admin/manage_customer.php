<?php
// admin/manage_customers.php
$page_title = "إدارة العملاء";
$class_dashboard = "active";
require_once dirname(__DIR__) . '/config.php';
require_once BASE_DIR . 'partials/session_admin.php';
require_once BASE_DIR . 'partials/header.php';

$message = "";
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    unset($_SESSION['message']);
}

function e($s) {
    return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// --- إعدادات ---
// عملاء محميون من الحذف (غير قابلين للحذف). عدّل الأرقام حسب حاجتك.
$protected_customers = [8];

// --- معالجة حذف عميل (آمنة) ---
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['delete_customer'])) {
    // تحقق CSRF
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['message'] = "<div class='alert alert-danger'>خطأ: طلب غير صالح (CSRF).</div>";
        header("Location: " . htmlspecialchars($_SERVER['PHP_SELF']));
        exit;
    }

    $customer_id_to_delete = intval($_POST['customer_id_to_delete'] ?? 0);
    if ($customer_id_to_delete <= 0) {
        $_SESSION['message'] = "<div class='alert alert-danger'>معرّف العميل غير صالح.</div>";
        header("Location: " . htmlspecialchars($_SERVER['PHP_SELF']));
        exit;
    }

    // منع حذف العملاء المحميين
    if (in_array($customer_id_to_delete, $protected_customers, true)) {
        $_SESSION['message'] = "<div class='alert alert-warning'><strong>غير مسموح.</strong> هذا العميل محمي من النظام ولا يمكن حذفه.</div>";
        header("Location: " . htmlspecialchars($_SERVER['PHP_SELF']));
        exit;
    }

    // نتحقق من وجود فواتير مرتبطة - نعد العد للفواتير (بأي حالة)
    $linked_count = 0;
    $sql_chk = "SELECT COUNT(*) AS cnt FROM invoices_out WHERE customer_id = ?";
    if ($chk = $conn->prepare($sql_chk)) {
        $chk->bind_param("i", $customer_id_to_delete);
        $chk->execute();
        $res = $chk->get_result();
        if ($res && $row = $res->fetch_assoc()) {
            $linked_count = intval($row['cnt']);
        }
        $chk->close();
    } else {
        error_log("Prepare check invoices_out failed: " . $conn->error);
    }

    if ($linked_count > 0) {
        // لا نحذف، نعرض رسالة مع اقتراح عرض الفواتير المرتبطة
        $pending_link = BASE_URL . "admin/pending_invoices.php?customer_id=" . urlencode($customer_id_to_delete);
        $delivered_link = BASE_URL . "admin/delivered_invoices.php?customer_id=" . urlencode($customer_id_to_delete);

        $_SESSION['message'] = "
            <div class='alert alert-warning'>
                <strong>غير مسموح بالحذف</strong> — يوجد <strong>{$linked_count}</strong> فاتورة/فواتير مرتبطة بهذا العميل.
                <br>الاقتراحات: عرض الفواتير المرتبطة، أو نقل/حذف الفواتير أولاً إذا كنت متأكداً.
                <hr style='margin:6px 0;'/>
                <div>عرض الفواتير: 
                    <a href='{$pending_link}'>الفواتير المؤجلة</a> |
                    <a href='{$delivered_link}'>الفواتير المستلمة</a>
                </div>
            </div>
        ";
        header("Location: " . htmlspecialchars($_SERVER['PHP_SELF']));
        exit;
    }

    // لا سجلات مرتبطة ولا هو محمي -> تنفيذ الحذف
    $sql_delete = "DELETE FROM customers WHERE id = ? LIMIT 1";
    if ($stmt_delete = $conn->prepare($sql_delete)) {
        $stmt_delete->bind_param("i", $customer_id_to_delete);
        if ($stmt_delete->execute()) {
            $_SESSION['message'] = ($stmt_delete->affected_rows > 0)
                ? "<div class='alert alert-success'>تم حذف العميل بنجاح.</div>"
                : "<div class='alert alert-warning'>لم يتم العثور على العميل.</div>";
        } else {
            error_log("Delete customer error: " . $stmt_delete->error);
            $_SESSION['message'] = "<div class='alert alert-danger'>حدث خطأ أثناء الحذف. تم تسجيل الخطأ لدى النظام.</div>";
        }
        $stmt_delete->close();
    } else {
        error_log("Prepare delete customer failed: " . $conn->error);
        $_SESSION['message'] = "<div class='alert alert-danger'>حدث خطأ داخلي. الرجاء المحاولة لاحقاً.</div>";
    }

    header("Location: " . htmlspecialchars($_SERVER['PHP_SELF']));
    exit;
}

// --- البحث / جلب العملاء ---
// نقرأ q من GET
$q = '';
if (isset($_GET['q'])) {
    $q = trim((string) $_GET['q']);
    if (mb_strlen($q) > 255) $q = mb_substr($q, 0, 255);
}

$customers = []; // سنملأ هذا بالمصفوفة بدل الاعتماد على mysqli_result مؤقتاً
if ($q !== '') {
    $sql_select = "SELECT c.id, c.name, c.mobile, c.city, c.address, c.notes, c.created_at, u.username as creator_name
                   FROM customers c
                   LEFT JOIN users u ON c.created_by = u.id
                   WHERE (c.name LIKE ? OR c.mobile LIKE ? OR c.city LIKE ? OR c.address LIKE ? OR c.notes LIKE ?)
                   ORDER BY c.id DESC";
    $like = '%' . $q . '%';
    if ($stmt = $conn->prepare($sql_select)) {
        $stmt->bind_param('sssss', $like, $like, $like, $like, $like);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) $customers[] = $r;
        $stmt->close();
    } else {
        $message .= "<div class='alert alert-danger'>خطأ في جلب العملاء: " . e($conn->error) . "</div>";
    }
} else {
    $sql_select = "SELECT c.id, c.name, c.mobile, c.city, c.address, c.notes, c.created_at, u.username as creator_name
                   FROM customers c
                   LEFT JOIN users u ON c.created_by = u.id
                   ORDER BY c.id DESC";
    if ($res = $conn->query($sql_select)) {
        while ($r = $res->fetch_assoc()) $customers[] = $r;
        $res->free();
    } else {
        $message .= "<div class='alert alert-danger'>خطأ في جلب العملاء: " . e($conn->error) . "</div>";
    }
}

// --- إذا هناك عملاء: جلب أعداد الفواتير المرتبطة (مقسمة حسب delivered yes/no) دفعة واحدة ---
$invoice_counts = []; // [customer_id => ['yes'=>n,'no'=>m,'total'=>t]]
if (!empty($customers)) {
    $ids = array_map(function($c){ return intval($c['id']); }, $customers);
    // امن: العناصر أرقام صحيحة
    $ids_csv = implode(',', $ids);
    $sql_counts = "SELECT customer_id, delivered, COUNT(*) AS cnt FROM invoices_out WHERE customer_id IN ($ids_csv) GROUP BY customer_id, delivered";
    if ($res2 = $conn->query($sql_counts)) {
        while ($rowc = $res2->fetch_assoc()) {
            $cid = intval($rowc['customer_id']);
            $del = ($rowc['delivered'] === 'yes') ? 'yes' : 'no';
            $cnt = intval($rowc['cnt']);
            if (!isset($invoice_counts[$cid])) $invoice_counts[$cid] = ['yes'=>0,'no'=>0,'total'=>0];
            $invoice_counts[$cid][$del] = $cnt;
            $invoice_counts[$cid]['total'] += $cnt;
        }
        $res2->free();
    } else {
        error_log("Failed to fetch invoice counts: " . $conn->error);
    }
}
require_once BASE_DIR . 'partials/sidebar.php';

?>
<div class="container mt-5 pt-3">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1><i class="fas fa-address-book"></i> إدارة العملاء</h1>
        <div>
            <a href="<?php echo BASE_URL; ?>customer/insert.php" class="btn btn-success">
                <i class="fas fa-plus-circle"></i> إضافة عميل جديد
            </a>
            <a href="<?php echo BASE_URL; ?>user/welcome.php" class="btn btn-outline-secondary ms-2">
                <i class="fas fa-arrow-left"></i> عودة
            </a>
        </div>
    </div>

    <?php echo $message; ?>

    <!-- البحث -->
    <div class="card mb-3">
        <div class="card-body">
            <form class="row g-2 align-items-center" method="get" action="<?php echo e($_SERVER['PHP_SELF']); ?>">
                <div class="col" style="flex:1;">
                    <input id="q" name="q" type="search" class="form-control"
                           placeholder="ابحث بالاسم أو الموبايل أو المدينة أو العنوان أو الملاحظات"
                           value="<?php echo e($q); ?>">
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> بحث</button>
                    <a href="<?php echo e($_SERVER['PHP_SELF']); ?>" class="btn btn-outline-secondary ms-1">إظهار الكل</a>
                </div>
            </form>
        </div>
    </div>

    <!-- جدول العملاء -->
    <div class="card">
        <div class="card-header">قائمة العملاء</div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped table-hover table-bordered align-middle">
                    <thead class="table-dark">
                        <tr>
                            <th>#</th>
                            <th>الاسم</th>
                            <th>الموبايل</th>
                            <th>المدينة</th>
                            <th>ملاحظات (مقتطف)</th>
                            <th class="text-center">إجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($customers)): ?>
                            <?php foreach ($customers as $row): 
                                $cid = intval($row['id']);
                                $preview_notes = !empty($row["notes"]) ? mb_substr($row["notes"], 0, 30) . (mb_strlen($row["notes"])>30?'...':'') : '-';
                                $counts = $invoice_counts[$cid] ?? ['yes'=>0,'no'=>0,'total'=>0];
                                $is_protected = in_array($cid, $protected_customers, true);
                                ?>
                                <tr data-customer='<?php echo e(json_encode($row)); ?>'>
                                    <td><?php echo e($row["id"]); ?></td>
                                    <td><?php echo e($row["name"]); ?></td>
                                    <td><?php echo e($row["mobile"]); ?></td>
                                    <td><?php echo e($row["city"]); ?></td>
                                    <td><?php echo e($preview_notes); ?></td>
                                    <td class="text-center">
                                        <button type="button" class="btn btn-info btn-sm btn-view" title="عرض">
                                            <i class="fas fa-eye"></i>
                                        </button>

                                        <form action="<?php echo BASE_URL; ?>admin/edit_customer.php" method="post" class="d-inline">
                                            <input type="hidden" name="customer_id_to_edit" value="<?php echo e($row["id"]); ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                            <button type="submit" class="btn btn-warning btn-sm" title="تعديل">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                        </form>

                                        <!-- عرض أزرار الفواتير إذا وُجدت -->
                                        <?php if ($counts['total'] > 0): ?>
                                            <a href="<?php echo BASE_URL; ?>admin/pending_invoices.php?customer_id=<?php echo $cid; ?>" class="btn btn-outline-primary btn-sm ms-1" title="عرض الفواتير المؤجلة">
                                                <i class="fas fa-hourglass-half"></i> <?php echo $counts['no']; ?>
                                            </a>
                                            <a href="<?php echo BASE_URL; ?>admin/delivered_invoices.php?customer_id=<?php echo $cid; ?>" class="btn btn-outline-success btn-sm ms-1" title="عرض الفواتير المستلمة">
                                                <i class="fas fa-check-circle"></i> <?php echo $counts['yes']; ?>
                                            </a>
                                        <?php endif; ?>

                                        <!-- زر الحذف أو بدائله -->
                                        <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post" class="d-inline ms-2">
                                            <input type="hidden" name="customer_id_to_delete" value="<?php echo e($row["id"]); ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                            <?php if ($is_protected): ?>
                                                <button type="button" class="btn btn-danger btn-sm" disabled title="هذا العميل محمي من الحذف">
                                                    <i class="fas fa-trash-alt"></i>
                                                </button>
                                            <?php elseif ($counts['total'] > 0): ?>
                                                <button type="button" class="btn btn-outline-warning btn-sm"  title="يوجد سجلات مرتبطة — لا يمكن الحذف">
                                                    <i class="fas fa-exclamation-triangle"></i> <?php echo $counts['total']; ?>
                                                </button>
                                            <?php else: ?>
                                                <button type="submit" name="delete_customer" class="btn btn-danger btn-sm"
                                                        onclick="return confirm('هل أنت متأكد من حذف هذا العميل؟');" title="حذف">
                                                    <i class="fas fa-trash-alt"></i>
                                                </button>
                                            <?php endif; ?>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="6" class="text-center">لا يوجد عملاء.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Modal عرض العميل -->
<div id="customerModal" class="modal-backdrop" aria-hidden="true">
  <div class="mymodal">
    <h3>تفاصيل العميل</h3>
    <div id="modalCustomerBody">
      <p class="muted-small">اختر صفًا لعرض التفاصيل.</p>
    </div>
    <div class="modal-footer">
      <div>
        <button id="modalClose" type="button" class="btn btn-secondary">إغلاق</button>
      </div>
    </div>
  </div>
</div>

<style>
.modal-backdrop{ position:fixed; left:0; top:0; right:0; bottom:0; display:none; align-items:center; justify-content:center; background:rgba(2,6,23,0.4); z-index:9999; }
.modal-backdrop.open{ display:flex; }
.mymodal{ background:#fff; padding:16px; border-radius:8px; max-width:720px; width:95%; }
.note-box{ max-height:220px; overflow:auto; padding:8px; background:#f8f9fa; border-radius:6px; }
</style>

<script>
document.addEventListener('DOMContentLoaded', function(){
  const modal = document.getElementById('customerModal');
  const modalBody = document.getElementById('modalCustomerBody');
  const btnClose = document.getElementById('modalClose');

  function escapeHtml(str) {
    if (str === undefined || str === null) return '';
    return String(str)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function renderDetailsInModal(data) {
    const created = data.created_at ? (new Date(data.created_at)).toLocaleString() : '-';
    const creator = data.creator_name || '-';
    const address = data.address || '-';
    const notes = data.notes || '-';

    modalBody.innerHTML = `
      <div><strong>الاسم:</strong> ${escapeHtml(data.name)}</div>
      <div><strong>الموبايل:</strong> ${escapeHtml(data.mobile || '-')}</div>
      <div><strong>المدينة:</strong> ${escapeHtml(data.city || '-')}</div>
      <div><strong>العنوان:</strong> ${escapeHtml(address)}</div>
      <hr/>
      <div><strong>الملاحظات:</strong></div>
      <div class="note-box">${escapeHtml(notes).replace(/\\n/g, '<br>')}</div>
      <hr/>
      <div class="muted-small"><strong>أضيف بواسطة:</strong> ${escapeHtml(creator)}<br>
      <strong>تاريخ الإضافة:</strong> ${escapeHtml(created)}</div>
    `;
    modal.classList.add('open');
  }

  document.querySelectorAll('.btn-view').forEach(btn => {
    btn.addEventListener('click', e => {
      const tr = e.currentTarget.closest('tr');
      const raw = tr.getAttribute('data-customer');
      if (!raw) return;
      try {
        const data = JSON.parse(raw);
        renderDetailsInModal(data);
      } catch(err) { console.error(err); }
    });
  });

  btnClose.addEventListener('click', () => modal.classList.remove('open'));
});
</script>

<?php
$conn->close();
require_once BASE_DIR . 'partials/footer.php';
