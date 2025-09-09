<?php
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

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// حذف عميل
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_customer'])) {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $message = "<div class='alert alert-danger'>خطأ: طلب غير صالح.</div>";
    } else {
        $customer_id_to_delete = intval($_POST['customer_id_to_delete']);
        $sql_delete = "DELETE FROM customers WHERE id = ?";
        if ($stmt_delete = $conn->prepare($sql_delete)) {
            $stmt_delete->bind_param("i", $customer_id_to_delete);
            if ($stmt_delete->execute()) {
                $message = ($stmt_delete->affected_rows > 0)
                    ? "<div class='alert alert-success'>تم حذف العميل بنجاح.</div>"
                    : "<div class='alert alert-warning'>لم يتم العثور على العميل.</div>";
            } else {
                $message = "<div class='alert alert-danger'>خطأ أثناء الحذف: " . e($stmt_delete->error) . "</div>";
            }
            $stmt_delete->close();
        }
    }
}

// البحث
$q = '';
if (isset($_GET['q'])) {
    $q = trim((string) $_GET['q']);
    if (mb_strlen($q) > 255) $q = mb_substr($q, 0, 255);
}

if ($q !== '') {
    $sql_select = "SELECT c.id, c.name, c.mobile, c.city, c.address, c.notes, c.created_at, u.username as creator_name
                   FROM customers c
                   LEFT JOIN users u ON c.created_by = u.id
                   WHERE (c.name LIKE ? OR c.mobile LIKE ? OR c.city LIKE ? OR c.address LIKE ? OR c.notes LIKE ?)
                   ORDER BY c.id DESC";
    $like = '%' . $q . '%';
    $customers_stmt = $conn->prepare($sql_select);
    $customers_stmt->bind_param('sssss', $like, $like, $like, $like, $like);
    $customers_stmt->execute();
    $result = $customers_stmt->get_result();
} else {
    $sql_select = "SELECT c.id, c.name, c.mobile, c.city, c.address, c.notes, c.created_at, u.username as creator_name
                   FROM customers c
                   LEFT JOIN users u ON c.created_by = u.id
                   ORDER BY c.id DESC";
    $result = $conn->query($sql_select);
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
                        <?php if ($result && $result->num_rows > 0): ?>
                            <?php while($row = $result->fetch_assoc()): ?>
                                <?php
                                    $preview_notes = !empty($row["notes"]) ? mb_substr($row["notes"], 0, 30) . (mb_strlen($row["notes"])>30?'...':'') : '-';
                                    $full_data = htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8');
                                ?>
                                <tr data-customer='<?php echo $full_data; ?>'>
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
                                        <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post" class="d-inline ms-2">
                                            <input type="hidden" name="customer_id_to_delete" value="<?php echo e($row["id"]); ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                            <button type="submit" name="delete_customer" class="btn btn-danger btn-sm"
                                                    onclick="return confirm('هل أنت متأكد من حذف هذا العميل؟');" title="حذف">
                                                <i class="fas fa-trash-alt"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                            <?php if (isset($customers_stmt)) $customers_stmt->close(); ?>
                        <?php else: ?>
                            <tr><td colspan="6" class="text-center">لا يوجد عملاء.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- المودال -->
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



</style>

<script>
document.addEventListener('DOMContentLoaded', function(){
  const modal = document.getElementById('customerModal');
  const modalBody = document.getElementById('modalCustomerBody');
  const btnClose = document.getElementById('modalClose');

  function escapeHtml(str) {
    if (!str) return '';
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

<?php $conn->close(); ?>
<?php require_once BASE_DIR . 'partials/footer.php'; ?>
