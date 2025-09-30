<?php
// create_invoice.php (مُحدّث)
// إنشاء فاتورة — يدعم FIFO allocations, CSRF (meta + JS), اختيار عميل مثبت، إضافة عميل، created_by tracking.

// ========== BOOT (config + session) ==========
$page_title = "إنشاء فاتورة بيع";
$class_dashboard = "active";
require_once dirname(__DIR__) . '/config.php';
require_once BASE_DIR . 'partials/session_admin.php'; // تأكد أن session_start() هنا وأن $_SESSION['user_id'] متوفر
ob_start();
// fallback PDO if not provided by config
if (!isset($pdo)) {
  try {
    $db_host = '127.0.0.1';
    $db_name = 'saied_db';
    $db_user = 'root';
    $db_pass = '';
    $dsn = "mysql:host={$db_host};dbname={$db_name};charset=utf8mb4";
    $pdo = new PDO($dsn, $db_user, $db_pass, [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
  } catch (Exception $e) {
    http_response_code(500);
    echo "DB connection failed: " . htmlspecialchars($e->getMessage());
    exit;
  }
}

// CSRF token
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// if (session_status() == PHP_SESSION_NONE) session_start();
if (empty($_SESSION['id'])) {
  error_log("create_invoice: no session user_id. Session keys: " . json_encode(array_keys($_SESSION)));
  jsonOut(['ok'=>false,'error'=>'المستخدم غير معرف. الرجاء تسجيل الدخول مجدداً.']);
}
$created_by = (int)$_SESSION['id'];


// Helper JSON
function jsonOut($payload)
{
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

/* =========================
   AJAX endpoints
   Must run before any HTML output
   ========================= */
if (isset($_REQUEST['action'])) {
  $action = $_REQUEST['action'];

  // 0) sync_consumed
  if ($action === 'sync_consumed') {
    try {
      $stmt = $pdo->prepare("UPDATE batches SET status = 'consumed', updated_at = NOW() WHERE status = 'active' AND COALESCE(remaining,0) <= 0");
      $stmt->execute();
      jsonOut(['ok' => true, 'updated' => $stmt->rowCount()]);
    } catch (Exception $e) {
      jsonOut(['ok' => false, 'error' => 'فشل تحديث حالات الدفعات.']);
    }
  }

  // 1) products (with aggregates)
  if ($action === 'products') {
    $q = trim($_GET['q'] ?? '');
    $params = [];
    $where = '';
    if ($q !== '') {
      $where = " WHERE (p.name LIKE ? OR p.product_code LIKE ? OR p.id = ?)";
      $params[] = "%$q%";
      $params[] = "%$q%";
      $params[] = is_numeric($q) ? (int)$q : 0;
    }
    $sql = "
            SELECT p.id, p.product_code, p.name, p.unit_of_measure, p.current_stock, p.reorder_level,
                   COALESCE(b.rem_sum,0) AS remaining_active,
                   COALESCE(b.val_sum,0) AS stock_value_active,
                   (SELECT b2.unit_cost FROM batches b2 WHERE b2.product_id = p.id AND b2.status IN ('active','consumed') ORDER BY b2.received_at DESC, b2.created_at DESC LIMIT 1) AS last_purchase_price,
                   (SELECT b2.sale_price FROM batches b2 WHERE b2.product_id = p.id AND b2.status IN ('active','consumed') ORDER BY b2.received_at DESC, b2.created_at DESC LIMIT 1) AS last_sale_price,
                   (SELECT b2.received_at FROM batches b2 WHERE b2.product_id = p.id AND b2.status IN ('active','consumed') ORDER BY b2.received_at DESC, b2.created_at DESC LIMIT 1) AS last_batch_date
            FROM products p
            LEFT JOIN (
               SELECT product_id, SUM(remaining) AS rem_sum, SUM(remaining * unit_cost) AS val_sum
               FROM batches
               WHERE status = 'active' AND remaining > 0
               GROUP BY product_id
            ) b ON b.product_id = p.id
            {$where}
            ORDER BY p.id DESC
            LIMIT 2000
        ";
    try {
      $stmt = $pdo->prepare($sql);
      $stmt->execute($params);
      $rows = $stmt->fetchAll();
      jsonOut(['ok' => true, 'products' => $rows]);
    } catch (Exception $e) {
      jsonOut(['ok' => false, 'error' => 'فشل جلب المنتجات.']);
    }
  }

  // 2) batches list for a product
  if ($action === 'batches' && isset($_GET['product_id'])) {
    $product_id = (int)$_GET['product_id'];
    try {
      $stmt = $pdo->prepare("SELECT id, product_id, qty, remaining, original_qty, unit_cost, sale_price, received_at, expiry, notes, source_invoice_id, source_item_id, created_by, adjusted_by, adjusted_at, created_at, updated_at, revert_reason, cancel_reason, status FROM batches WHERE product_id = ? ORDER BY received_at DESC, created_at DESC, id DESC");
      $stmt->execute([$product_id]);
      $batches = $stmt->fetchAll();
      $pstmt = $pdo->prepare("SELECT id, name, product_code FROM products WHERE id = ?");
      $pstmt->execute([$product_id]);
      $prod = $pstmt->fetch();
      jsonOut(['ok' => true, 'batches' => $batches, 'product' => $prod]);
    } catch (Exception $e) {
      jsonOut(['ok' => false, 'error' => 'فشل جلب الدفعات.']);
    }
  }

  // 3) customers list/search
  if ($action === 'customers') {
    $q = trim($_GET['q'] ?? '');
    try {
      if ($q === '') {
        $stmt = $pdo->query("SELECT id,name,mobile,city,address FROM customers ORDER BY name LIMIT 200");
        $rows = $stmt->fetchAll();
      } else {
        $stmt = $pdo->prepare("SELECT id,name,mobile,city,address FROM customers WHERE name LIKE ? OR mobile LIKE ? ORDER BY name LIMIT 200");
        $like = "%$q%";
        $stmt->execute([$like, $like]);
        $rows = $stmt->fetchAll();
      }
      jsonOut(['ok' => true, 'customers' => $rows]);
    } catch (Exception $e) {
      jsonOut(['ok' => false, 'error' => 'فشل جلب العملاء.']);
    }
  }

  // 4) add customer (POST)
  // if ($action === 'add_customer' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  //     $token = $_POST['csrf_token'] ?? '';ٍ
  //     if (!hash_equals($_SESSION['csrf_token'], (string)$token)) jsonOut(['ok'=>false,'error'=>'رمز التحقق (CSRF) غير صالح. أعد تحميل الصفحة وحاول مجدداً.']);
  //     $name = trim($_POST['name'] ?? '');
  //     $mobile = trim($_POST['mobile'] ?? '');
  //     $city = trim($_POST['city'] ?? '');
  //     $address = trim($_POST['address'] ?? '');
  //     $notes = trim($_POST['notes'] ?? '');
  //     if ($name === '') jsonOut(['ok'=>false,'error'=>'الرجاء إدخال اسم العميل.']);
  //     try {
  //         $stmt = $pdo->prepare("INSERT INTO customers (name,mobile,city,address,notes,created_by,created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
  //         $stmt->execute([$name,$mobile,$city,$address,$notes,$created_by]);
  //         $newId = (int)$pdo->lastInsertId();
  //         $pstmt = $pdo->prepare("SELECT id,name,mobile,city,address FROM customers WHERE id = ?");
  //         $pstmt->execute([$newId]);
  //         $new = $pstmt->fetch();
  //         jsonOut(['ok'=>true,'msg'=>'تم إضافة العميل','customer'=>$new]);
  //     } catch (PDOException $e) {
  //         if ($e->errorInfo[1] == 1062) jsonOut(['ok'=>false,'error'=>'العميل موجود مسبقاً.']);
  //         jsonOut(['ok'=>false,'error'=>'فشل إضافة العميل.']);
  //     } catch (Exception $e) {
  //         jsonOut(['ok'=>false,'error'=>'فشل إضافة العميل.']);
  //     }
  // }

  // 4) add customer (POST)
  if ($action === 'add_customer' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], (string)$token)) {
      jsonOut(['ok' => false, 'error' => 'رمز التحقق (CSRF) غير صالح. أعد تحميل الصفحة وحاول مجدداً.']);
    }

    $name = trim($_POST['name'] ?? '');
    $mobile = trim($_POST['mobile'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    // 1) التحقق من الاسم
    if ($name === '') {
      jsonOut(['ok' => false, 'error' => 'الرجاء إدخال اسم العميل.']);
    }

    // 2) التحقق من رقم الموبايل موجود أم لا
    if ($mobile === '') {
      jsonOut(['ok' => false, 'error' => 'الرجاء إدخال رقم الموبايل.']);
    }

    // 3) تنظيف و/أو تحقق بسيط لصيغة الموبايل (أرقام فقط)
    $mobile_digits = preg_replace('/\D+/', '', $mobile); // احذف أي شيء غير رقم
    if (strlen($mobile_digits) < 7 || strlen($mobile_digits) > 15) {
      jsonOut(['ok' => false, 'error' => 'رقم الموبايل غير صحيح. الرجاء إدخال رقم صالح.']);
    }
    // استخدم النسخة المنقّحة لِحفظها/مقارنتها
    $mobile_clean = $mobile_digits;

    try {
      // 4) فحص التكرار قبل الإدراج (حسب رقم الموبايل)
      $chk = $pdo->prepare("SELECT id, name FROM customers WHERE mobile = ? LIMIT 1");
      $chk->execute([$mobile_clean]);
      $exists = $chk->fetch();
      if ($exists) {
        // رسالة واضحة عند التكرار
        jsonOut(['ok' => false, 'error' => "رقم الموبايل مسجل بالفعل للعميل \"{$exists['name']}\"."]);
      }

      // 5) تنفيذ الإدراج
      $stmt = $pdo->prepare("INSERT INTO customers (name,mobile,city,address,notes,created_by,created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
      $stmt->execute([$name, $mobile_clean, $city, $address, $notes, $created_by]);

      $newId = (int)$pdo->lastInsertId();
      $pstmt = $pdo->prepare("SELECT id,name,mobile,city,address FROM customers WHERE id = ?");
      $pstmt->execute([$newId]);
      $new = $pstmt->fetch();

      jsonOut(['ok' => true, 'msg' => 'تم إضافة العميل', 'customer' => $new]);
    } catch (PDOException $e) {
      // تعامل مع أخطاء الـ PDO بشكل آمن
      // إذا كان خطأ قيد فريد (1062) ظهر رغم الفحص، نرجع رسالة مفهومة
      $sqlErrNo = $e->errorInfo[1] ?? null;
      if ($sqlErrNo == 1062) {
        jsonOut(['ok' => false, 'error' => 'قيمة مكررة — رقم الموبايل مستخدم بالفعل.']);
      }
      // سجل الخطأ للخادم وارجع رسالة عامة للمستخدم
      error_log("PDO error add_customer: " . $e->getMessage());
      jsonOut(['ok' => false, 'error' => 'فشل إضافة العميل. حاول مرة أخرى.']);
    } catch (Exception $e) {
      error_log("Error add_customer: " . $e->getMessage());
      jsonOut(['ok' => false, 'error' => 'فشل إضافة العميل. حاول مرة أخرى.']);
    }
  }

  // 5) select customer (store in session) - POST
  if ($action === 'select_customer' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], (string)$token)) jsonOut(['ok' => false, 'error' => 'رمز التحقق (CSRF) غير صالح.']);
    $cid = (int)($_POST['customer_id'] ?? 0);
    if ($cid <= 0) {
      unset($_SESSION['selected_customer']);
      jsonOut(['ok' => true, 'msg' => 'تم إلغاء اختيار العميل']);
    }
    try {
      $stmt = $pdo->prepare("SELECT id,name,mobile,city,address FROM customers WHERE id = ?");
      $stmt->execute([$cid]);
      $cust = $stmt->fetch();
      if (!$cust) jsonOut(['ok' => false, 'error' => 'العميل غير موجود']);
      $_SESSION['selected_customer'] = $cust;
      jsonOut(['ok' => true, 'customer' => $cust]);
    } catch (Exception $e) {
      jsonOut(['ok' => false, 'error' => 'تعذر اختيار العميل.']);
    }
  }

  // 6) save_invoice (POST)
  if ($action === 'save_invoice' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], (string)$token)) {
      jsonOut(['ok' => false, 'error' => 'رمز التحقق (CSRF) غير صالح. أعد تحميل الصفحة وحاول مجدداً.']);
    }
    $customer_id = (int)($_POST['customer_id'] ?? 0);
    $status = ($_POST['status'] ?? 'pending') === 'paid' ? 'paid' : 'pending';
    $items_json = $_POST['items'] ?? '';
    $notes = trim($_POST['notes'] ?? '');
    $created_by = $_SESSION['id'] ?? null;

    if ($customer_id <= 0) jsonOut(['ok' => false, 'error' => 'الرجاء اختيار عميل.']);
    if (empty($items_json)) jsonOut(['ok' => false, 'error' => 'لا توجد بنود لإضافة الفاتورة.']);

    $items = json_decode($items_json, true);
    if (!is_array($items) || count($items) === 0) jsonOut(['ok' => false, 'error' => 'بنود الفاتورة غير صالحة.']);

    try {
      $pdo->beginTransaction();

      // insert invoice header
      $delivered = ($status === 'paid') ? 'yes' : 'no';
      $invoice_group = 'group1';
      $stmt = $pdo->prepare("INSERT INTO invoices_out (customer_id, delivered, invoice_group, created_by, created_at, notes) VALUES (?, ?, ?, ?, NOW(), ?)");
      $stmt->execute([$customer_id, $delivered, $invoice_group, $created_by, $notes]);
      $invoice_id = (int)$pdo->lastInsertId();

      $totalRevenue = 0.0;
      $totalCOGS = 0.0;

      $insertItemStmt = $pdo->prepare("INSERT INTO invoice_out_items (invoice_out_id, product_id, quantity, total_price, cost_price_per_unit, selling_price, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
      $insertAllocStmt = $pdo->prepare("INSERT INTO sale_item_allocations (sale_item_id, batch_id, qty, unit_cost, line_cost, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
      $updateBatchStmt = $pdo->prepare("UPDATE batches SET remaining = ?, status = ?, adjusted_at = NOW(), adjusted_by = ? WHERE id = ?");
      $selectBatchesStmt = $pdo->prepare("SELECT id, remaining, unit_cost FROM batches WHERE product_id = ? AND status = 'active' AND remaining > 0 ORDER BY received_at ASC, created_at ASC, id ASC FOR UPDATE");

      foreach ($items as $it) {
        $product_id = (int)($it['product_id'] ?? 0);
        $qty = (float)($it['qty'] ?? 0);
        $selling_price = (float)($it['selling_price'] ?? 0);
        if ($product_id <= 0 || $qty <= 0) {
          $pdo->rollBack();
          jsonOut(['ok' => false, 'error' => "بند غير صالح (معرف/كمية)."]);
        }

        // allocate FIFO
        $selectBatchesStmt->execute([$product_id]);
        $availableBatches = $selectBatchesStmt->fetchAll();
        $need = $qty;
        $allocations = [];
        foreach ($availableBatches as $b) {
          if ($need <= 0) break;
          $avail = (float)$b['remaining'];
          if ($avail <= 0) continue;
          $take = min($avail, $need);
          $allocations[] = ['batch_id' => (int)$b['id'], 'take' => $take, 'unit_cost' => (float)$b['unit_cost']];
          $need -= $take;
        }
        if ($need > 0.00001) {
          $pdo->rollBack();
          jsonOut(['ok' => false, 'error' => "الرصيد غير كافٍ للمنتج (ID: {$product_id})."]);
        }
        $itemTotalCost = 0.0;
        foreach ($allocations as $a) $itemTotalCost += $a['take'] * $a['unit_cost'];
        $cost_price_per_unit = ($qty > 0) ? ($itemTotalCost / $qty) : 0.0;
        $lineTotalPrice = $qty * $selling_price;

        // insert invoice item
        $insertItemStmt->execute([$invoice_id, $product_id, $qty, $lineTotalPrice, $cost_price_per_unit, $selling_price]);
        $invoice_item_id = (int)$pdo->lastInsertId();

        // apply allocations and update batches
        foreach ($allocations as $a) {
          // lock & get current remaining
          $stmtCur = $pdo->prepare("SELECT remaining FROM batches WHERE id = ? FOR UPDATE");
          $stmtCur->execute([$a['batch_id']]);
          $curRow = $stmtCur->fetch();
          $curRem = $curRow ? (float)$curRow['remaining'] : 0.0;
          $newRem = max(0.0, $curRem - $a['take']);
          $newStatus = ($newRem <= 0) ? 'consumed' : 'active';
          $updateBatchStmt->execute([$newRem, $newStatus, $created_by, $a['batch_id']]);

          $lineCost = $a['take'] * $a['unit_cost'];
          $insertAllocStmt->execute([$invoice_item_id, $a['batch_id'], $a['take'], $a['unit_cost'], $lineCost, $created_by]);
        }

        $totalRevenue += $lineTotalPrice;
        $totalCOGS += $itemTotalCost;
      }

      $pdo->commit();
      jsonOut([
        'ok' => true,
        'msg' => 'تم إنشاء الفاتورة بنجاح.',
        'invoice_id' => $invoice_id,
        'invoice_number' => $invoice_id, // أو أي حقل آخر يمثل رقم الفاتورة
        'total_revenue' => round($totalRevenue, 2),
        'total_cogs' => round($totalCOGS, 2)
      ]);

      // jsonOut(['ok'=>true,'msg'=>'تم إنشاء الفاتورة بنجاح.','invoice_id'=>$invoice_id,'total_revenue'=>round($totalRevenue,2),'total_cogs'=>round($totalCOGS,2)]);
    } catch (PDOException $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      $err = $e->errorInfo[1] ?? null;
      if ($err == 1062) jsonOut(['ok' => false, 'error' => 'قيمة مكررة: تحقق من الحقول الفريدة.']);
      error_log("PDO Error save_invoice: " . $e->getMessage());
      jsonOut(['ok' => false, 'error' => 'حدث خطأ أثناء حفظ الفاتورة.']);
    } catch (Exception $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      error_log("Error save_invoice: " . $e->getMessage());
      jsonOut(['ok' => false, 'error' => 'حدث خطأ أثناء حفظ الفاتورة.']);
    }
  }

  // unknown action
  jsonOut(['ok' => false, 'error' => 'action غير معروف']);
}
// end AJAX handling

// Read selected customer from session (if any) to pre-fill UI
$selected_customer_js = 'null';
if (!empty($_SESSION['selected_customer']) && is_array($_SESSION['selected_customer'])) {
  $sc = $_SESSION['selected_customer'];
  $selected_customer_js = json_encode($sc, JSON_UNESCAPED_UNICODE);
}

// If user session id not set, created_by will be null - but we try:

// After this point, safe to include header and render HTML
require_once BASE_DIR . 'partials/header.php';
?>
<!-- put csrf token in meta so JS reads it reliably -->
<meta name="csrf-token" content="<?php echo htmlspecialchars($csrf_token); ?>">
<?php require_once BASE_DIR . 'partials/sidebar.php'; ?>

<!-- ========================= HTML / UI ========================= -->
<style>
  :root {
    --primary: #0b84ff;
    --accent: #7c3aed;
    --teal: #10b981;
    --amber: #f59e0b;
    --rose: #ef4444;
    --bg: #f6f8fc;
    --surface: #fff;
    --text: #0b1220;
    --muted: #64748b;
    --border: rgba(2, 6, 23, 0.06);
  }

  [data-theme="dark"] {
    --bg: #0b1220;
    --surface: #0f1626;
    --text: #e6eef8;
    --muted: #94a3b8;
    --border: rgba(148, 163, 184, 0.12);
  }

  body {
    background: var(--bg);
    color: var(--text);
  }

  .container-inv {
    padding: 18px;
    font-family: Inter, 'Noto Naskh Arabic', Tahoma, Arial;
  }

  .grid {
    display: grid;
    grid-template-columns: 360px 1fr 320px;
    gap: 16px;
    height: calc(100vh - 160px);
  }

  .panel {
    background: var(--surface);
    padding: 12px;
    border-radius: 12px;
    box-shadow: 0 10px 24px rgba(2, 6, 23, 0.06);
    overflow: auto;
  }

  .prod-card {
    display: flex;
    justify-content: space-between;
    gap: 10px;
    padding: 10px;
    border: 1px solid var(--border);
    border-radius: 10px;
    margin-bottom: 10px;
    background: var(--surface);
  }

  .badge {
    padding: 6px 10px;
    border-radius: 999px;
    font-weight: 700;
  }

  .badge.warn {
    background: rgba(250, 204, 21, 0.12);
    color: #7a4f00;
  }

  /* تحذير */
  .badge.green {
    background: rgba(16, 185, 129, 0.12);
    color: var(--teal);
  }

  /* فعال */
  .badge.red {
    background: rgba(239, 68, 68, 0.13);
    color: #b91c1c;
  }

  /* ملغي */
  .badge.gray {
    background: rgba(120, 120, 120, 0.13);
    color: #374151;
  }

  /* مستهلك */
  .badge.purple {
    background: rgba(168, 85, 247, 0.13);
    color: #7c3aed;
  }

  /* مرتجع */
  .btn {
    padding: 8px 10px;
    border-radius: 8px;
    border: none;
    cursor: pointer;
  }

  .btn.primary {
    background: linear-gradient(90deg, var(--primary), var(--accent));
    color: #fff;
  }

  .btn.ghost {
    background: transparent;
    border: 1px solid var(--border);
    color: var(--text);
  }

  .table {
    width: 100%;
    border-collapse: collapse;
  }

  .table th,
  .table td {
    padding: 8px;
    border-bottom: 1px solid var(--border);
    text-align: center;
  }

  .safe-hidden {
    display: none;
  }

  .modal-backdrop {
    position: fixed;
    inset: 0;
    display: none;
    align-items: center;
    justify-content: center;
    background: rgba(2, 6, 23, 0.55);
    z-index: 1200;
  }

  .mymodal {
    width: 100%;
    max-width: 1000px;
    background: var(--surface);
    padding: 16px;
    border-radius: 12px;
    max-height: 86vh;
    overflow: auto;
  }

  .toast-wrap {
    position: fixed;
    top: 50px;
    left: 30%;
    /* transform: translateX(-30%); */
    z-index: 2000;
    display: flex;
    flex-direction: column;
    gap: 8px;
  }

  .toast {
    display: flex !important;
    padding: 10px 14px;
    border-radius: 8px;
    color: #fff;
    box-shadow: 0 8px 20px rgba(2, 6, 23, 0.12);
  }

  .toast.success {
    background: linear-gradient(90deg, #10b981, #06b6d4);
  }

  .toast.error {
    background: linear-gradient(90deg, #ef4444, #f97316);
  }

  .cust-card {
    border: 1px solid var(--border);
    padding: 8px;
    border-radius: 8px;
    margin-bottom: 8px;
    display: flex;
    justify-content: space-between;
    align-items: center;
  }

  .small-muted {
    font-size: 13px;
    color: var(--muted);
  }

  @media (max-width:1100px) {
    .grid {
      grid-template-columns: 1fr;
      height: auto
    }
  }

  .invoice-table.custom-table-wrapper {
    max-height: 50vh;
  }

  #productSearchInput{
    background-color: var(--bg);
    color: var(--text);
  }

  /* خاصين عند اختيار عميل جعله selected */
.invoice-out .customer-card { transition: all .15s ease; }
.invoice-out .customer-card.selected { border-color: var(--primary); background: rgba(59,130,246,0.06); box-shadow: 0 4px 10px rgba(59,130,246,0.06); }
.invoice-out .customer-card.dim { opacity: 0.45; pointer-events: none; }
</style>

<div class="container invoice-out">


  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
    <div style="font-weight:900;font-size:20px">إنشاء فاتورة </div>
    <div id="currentInvoiceNumber" style="font-weight:700;color:var(--muted)">فاتورة: —</div>

    <div style="display:flex;gap:8px;align-items:center">
      <!-- theme toggle left for user, we won't auto include it in ajax -->
      <button id="toggleThemeBtn" class="btn ghost" type="button">تبديل الثيم</button>
    </div>
  </div>

  <div class="grid" role="main">
    <!-- Products Column -->
    <div class="panel" aria-label="Products">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
        <div style="font-weight:800">المنتجات</div>
        <input id="productSearchInput" placeholder="بحث باسم أو كود أو id..." style="padding:6px;border-radius:8px;border:1px solid var(--border);min-width:160px">
      </div>
      <div id="productsList" style="padding-bottom:12px"></div>
    </div>

    <!-- Invoice Column -->
    <div class="panel" aria-label="Invoice">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
        <div>
          <label><input type="radio" name="invoice_state" value="pending" checked> مؤجل</label>
          <label style="margin-left:10px"><input type="radio" name="invoice_state" value="paid"> تم الدفع</label>
        </div>
        <strong>فاتورة جديدة</strong>
      </div>

      <div class="custom-table-wrapper invoice-table">
        <table class="tabl custom-table" id="invoiceTable" aria-label="Invoice items">
          <thead class="center">
            <tr>
              <th>المنتج</th>
              <th>كمية</th>
              <th>سعر بيع</th>
              <th>تفاصيل FIFO</th>
              <th>الإجمالي</th>
              <th>حذف</th>
            </tr>
          </thead>
          <tbody id="invoiceTbody"></tbody>
        </table>
      </div>

      <div style="margin-top:10px;display:flex;gap:8px;align-items:center">
        <textarea id="invoiceNotes" placeholder="ملاحظات (لن تُطبع)" style="flex:1;padding:8px;border-radius:8px;border:1px solid var(--border)"></textarea>
      </div>

      <div style="display:flex;justify-content:space-between;align-items:center;margin-top:12px">
        <div><strong>إجمالي الكمية:</strong> <span id="sumQty">0</span></div>
        <div><strong>إجمالي البيع:</strong> <span id="sumSell">0.00</span> ج</div>
        <div style="display:flex;gap:8px">
          <button id="clearBtn" class="btn ghost">تفريغ</button>
          <button id="previewBtn" class="btn ghost">معاينة</button>
          <button id="confirmBtn" class="btn primary">تأكيد الفاتورة</button>
        </div>
      </div>
    </div>

    <!-- Customers Column -->
    <div class="panel" aria-label="Customers">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
        <strong>العملاء</strong>
        <div style="display:flex;gap:6px">
          <button id="openAddCustomerBtn" class="btn ghost" type="button">إضافة</button>
        </div>
      </div>

      <div style="margin-bottom:8px;display:flex;gap:6px;align-items:center">
        <input type="text" id="customerSearchInput" placeholder="ابحث باسم أو موبايل..." style="padding:6px;border:1px solid var(--border);border-radius:6px;width:100%">
      </div>

      <div style="margin-top:12px;display:flex;flex-direction:column;gap:8px">
        <button id="cashCustomerBtn" class="btn primary" type="button">نقدي (ثابت)</button>
        <div id="selectedCustomerBox" class="small-muted" style="padding:8px;border:1px solid var(--border);border-radius:8px;">
          <div>العميل الحالي: <strong id="selectedCustomerName">لم يتم الاختيار</strong></div>
          <div id="selectedCustomerDetails" class="small-muted"></div>
          <div style="margin-top:6px"><button id="btnUnselectCustomer" type="button" class="btn ghost">إلغاء اختيار العميل</button></div>
        </div>
      </div>

      <div id="customersList" style="margin-top:12px"></div>
      
    </div>
  </div>
</div>

<!-- Batches list modal (renamed not to conflict) -->
<div id="batchesModal_backdrop" class="modal-backdrop">
  <div class="mymodal">
    <div style="display:flex;justify-content:space-between;align-items:center">
      <div><strong id="batchesTitle">دفعات</strong>
        <div class="small" id="batchesInfo"></div>
      </div>
      <div><button id="closeBatchesBtn" class="btn ghost">إغلاق</button></div>
    </div>
    <div id="batchesTable" class=" custom-table-wrapper" style="margin-top:10px"></div>
  </div>
</div>

<!-- Batch detail modal -->
<div id="batchDetailModal_backdrop" class="modal-backdrop">
  <div class="mymodal">
    <div style="display:flex;justify-content:space-between;align-items:center">
      <div><strong id="batchTitle">تفاصيل الدفعة</strong></div>
      <div><button id="closeBatchDetailBtn" class="btn ghost">إغلاق</button></div>
    </div>
    <div id="batchDetailBody" class="custom-table-wrapper" style="margin-top:10px"></div>
  </div>
</div>

<!-- Add Customer modal (avoid bootstrap name) -->
<div id="addCustomer_backdrop" class="modal-backdrop">
  <div class="mymodal">
    <h3>إضافة عميل جديد</h3>
    <div id="addCustMsg"></div>
    <div style="display:grid;gap:8px;margin-top:8px">
      <input id="new_name" placeholder="الاسم" class="note-box" style="padding:8px;border:1px solid var(--border);border-radius:8px">
      <input id="new_mobile" placeholder="رقم الموبايل (11 رقم)" class="note-box" style="padding:8px;border:1px solid var(--border);border-radius:8px">
      <input id="new_city" placeholder="المدينة" class="note-box" style="padding:8px;border:1px solid var(--border);border-radius:8px">
      <input id="new_address" placeholder="العنوان" class="note-box" style="padding:8px;border:1px solid var(--border);border-radius:8px">
      <textarea id="new_notes" placeholder="ملاحظات عن العميل (اختياري)" class="note-box" rows="3" style="padding:8px;border:1px solid var(--border);border-radius:8px"></textarea>
      <div style="display:flex;justify-content:flex-end;gap:8px">
        <button id="closeAddCust" type="button" class="btn ghost">إلغاء</button>
        <button id="submitAddCust" type="button" class="btn primary">حفظ وإختيار</button>
      </div>
    </div>
  </div>
</div>

<!-- Confirm modal -->
<div id="confirmModal_backdrop" class="modal-backdrop">
  <div class="mymodal">
    <h3>تأكيد إتمام الفاتورة</h3>
    <div id="confirmClientPreview"></div>
    <div id="confirmItemsPreview" style="margin-top:8px"></div>
    <div style="display:flex;justify-content:space-between;align-items:center;margin-top:12px">
      <div><button id="confirmCancel" type="button" class="btn ghost">إلغاء</button><button id="confirmSend" type="button" class="btn primary" style="margin-left:8px">تأكيد وإرسال</button></div>
      <div><strong>الإجمالي:</strong> <span id="confirm_total_before">0.00</span></div>
    </div>
  </div>
</div>

<!-- Toasts -->
<div class="toast-wrap" id="toastWrap" aria-live="polite" aria-atomic="true"></div>

<script>
  document.addEventListener('DOMContentLoaded', function() {
    // small helpers
    const $ = id => document.getElementById(id);

    function onId(id, fn) {
      const el = document.getElementById(id);
      if (el) fn(el);
      return el;
    }

    function getCsrfToken() {
      const m = document.querySelector('meta[name="csrf-token"]');
      return m ? m.getAttribute('content') : '';
    }

    function showToast(msg, type = 'success', timeout = 2000) {
      const wrap = $('toastWrap');
      if (!wrap) return console.warn('no toastWrap');
      const el = document.createElement('div');
      el.className = 'toast ' + (type === 'error' ? 'error' : 'success');
      el.textContent = msg;
      wrap.appendChild(el);
      setTimeout(() => {
        el.style.opacity = 0;
        setTimeout(() => el.remove(), 500);
      }, timeout);
    }

    function esc(s) {
      return (s == null) ? '' : String(s).replace(/[&<>"']/g, m => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
      } [m]));
    }

    function fmt(n) {
      return Number(n || 0).toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
      });
    }

    function debounce(fn, t = 250) {
      let to;
      return (...a) => {
        clearTimeout(to);
        to = setTimeout(() => fn.apply(this, a), t);
      }
    }

    // safe fetchJson that throws on non-json
    async function fetchJson(url, opts) {
      const res = await fetch(url, opts);
      const txt = await res.text();
      try {
        return JSON.parse(txt);
      } catch (e) {
        console.error('Invalid JSON response:', txt);
        throw new Error('Invalid JSON from server');
      }
    }

    // state
    let products = [],
      customers = [],
      invoiceItems = [];
    let selectedCustomer = <?php echo $selected_customer_js; ?> || null;

    // --------- load products ----------
    async function loadProducts(q = '') {
      try {
        const json = await fetchJson(location.pathname + '?action=products' + (q ? '&q=' + encodeURIComponent(q) : ''), {
          credentials: 'same-origin'
        });
        if (!json.ok) {
          showToast(json.error || 'فشل جلب المنتجات', 'error');
          return;
        }
        products = json.products || [];
        renderProducts();
      } catch (e) {
        console.error(e);
        showToast('تعذر جلب المنتجات', 'error');
      }
    }

    function updateTotals() {
      // حدّث كل سطر إجمالي ويحسب الإجمالي العام
      let total = 0;
      document.querySelectorAll('#invoiceTbody tr').forEach((tr, i) => {
        const it = invoiceItems[i] || {
          qty: 0,
          selling_price: 0
        };
        const line = (Number(it.qty) || 0) * (Number(it.selling_price) || 0);
        const cell = tr.querySelector('.line-total');
        if (cell) cell.textContent = fmt(line);
        total += line;
      });
      // حدّث أي عنصر يعرض الإجمالي العام (مثال: an element with id totalDisplay)
      const td = document.getElementById('invoiceGrandTotal');
      if (td) td.textContent = fmt(total);
    }

    function renderProducts() {
      const wrap = $('productsList');
      if (!wrap) return;
      wrap.innerHTML = '';
      products.forEach(p => {
        const rem = parseFloat(p.remaining_active || 0);
        const consumed = rem <= 0;
        const div = document.createElement('div');
        div.className = 'prod-card';
        div.innerHTML = `<div>
          <div style="font-weight:800">${esc(p.name)}</div>
          <div class="small-muted">كود • #${esc(p.product_code)} • ID:${p.id}</div>
          <div class="small-muted">رصيد دخل: ${fmt(p.current_stock)}</div>
          <div class="small-muted">متبقي (Active): ${fmt(rem)}</div>
          <div class="small-muted">آخر شراء: ${esc(p.last_batch_date||'-')} • ${fmt(p.last_purchase_price||0)} جنيه</div>
        </div>
        <div style="display:flex;flex-direction:column;gap:6px;align-items:flex-end">
          ${consumed ? '<div class="badge warn">مستهلك</div>' : `<button class="btn primary add-btn" data-id="${p.id}" data-name="${esc(p.name)}" data-sale="${p.last_sale_price||0}">أضف</button>`}
          <button class="btn ghost batches-btn" data-id="${p.id}">دفعات</button>
        </div>`;
        wrap.appendChild(div);
      });
      // attach
      document.querySelectorAll('.add-btn').forEach(b => b.addEventListener('click', e => {
        const id = b.dataset.id;
        const name = b.dataset.name;
        const sale = parseFloat(b.dataset.sale || 0);
        addInvoiceItem({
          product_id: id,
          product_name: name,
          qty: 1,
          selling_price: sale
        });
      }));
      document.querySelectorAll('.batches-btn').forEach(b => b.addEventListener('click', e => {
        openBatchesModal(parseInt(b.dataset.id));
      }));
    }

    // search product input
    onId('productSearchInput', el => el.addEventListener('input', debounce(() => loadProducts(el.value.trim()), 400)));

    // -------- invoice items handling ----------
    function addInvoiceItem(item) {
      const idx = invoiceItems.findIndex(x => x.product_id == item.product_id);
      if (idx >= 0) invoiceItems[idx].qty = Number(invoiceItems[idx].qty) + Number(item.qty);
      else invoiceItems.push({
        ...item
      });
      renderInvoice();
    }

    function renderInvoice() {
      const tbody = $('invoiceTbody');
      if (!tbody) return;
      tbody.innerHTML = '';
      invoiceItems.forEach((it, i) => {
        const tr = document.createElement('tr');
        tr.innerHTML = `<td style="text-align:right">${esc(it.product_name)}</td>
        <td><input type="number" class="qty" data-idx="${i}" value="${it.qty}" step="0.0001" style="width:100px"></td>
        <td><input type="number" class="price" data-idx="${i}" value="${Number(it.selling_price).toFixed(2)}" step="0.01" style="width:110px"></td>
        <td><button class="btn ghost fifo-btn" data-idx="${i}">تفاصيل FIFO</button></td>
        <td class="line-total">${fmt(it.qty * it.selling_price)}</td>
        <td><button class="btn ghost remove-btn" data-idx="${i}">حذف</button></td>`;
        tbody.appendChild(tr);
      });
      // bind
      // document.querySelectorAll('.qty').forEach(el => el.addEventListener('input', e=>{
      //   const idx = e.target.dataset.idx; invoiceItems[idx].qty = parseFloat(e.target.value || 0); renderInvoice();
      // }));
      // document.querySelectorAll('.price').forEach(el => el.addEventListener('input', e=>{
      //   const idx = e.target.dataset.idx; invoiceItems[idx].selling_price = parseFloat(e.target.value || 0); renderInvoice();
      // }));

      // جديد — استخدام debounce لتقليل عدد إعادة البناء أثناء الطباعة
      const debouncedQtyUpdate = debounce(function(e) {
        const idx = e.target.dataset.idx;
        invoiceItems[idx].qty = parseFloat(e.target.value || 0);
        updateTotals(); // فقط حدّث المجاميع وخلايا الإجمالي دون إعادة بناء كامل
      }, 300);
      const debouncedPriceUpdate = debounce(function(e) {
        const idx = e.target.dataset.idx;
        invoiceItems[idx].selling_price = parseFloat(e.target.value || 0);
        updateTotals();
      }, 300);

      document.querySelectorAll('.qty').forEach(el => el.addEventListener('input', debouncedQtyUpdate));
      document.querySelectorAll('.price').forEach(el => el.addEventListener('input', debouncedPriceUpdate));

      document.querySelectorAll('.remove-btn').forEach(b => b.addEventListener('click', e => {
        const idx = b.dataset.idx;
        invoiceItems.splice(idx, 1);
        renderInvoice();
      }));
      document.querySelectorAll('.fifo-btn').forEach(b => b.addEventListener('click', e => {
        openFifoPreview(parseInt(b.dataset.idx));
      }));

      // totals
      let sumQ = 0,
        sumS = 0;
      invoiceItems.forEach(it => {
        sumQ += Number(it.qty || 0);
        sumS += Number(it.qty || 0) * Number(it.selling_price || 0);
      });
      onId('sumQty', el => el.textContent = sumQ);
      onId('sumSell', el => el.textContent = fmt(sumS));
    }

    onId('clearBtn', el => el.addEventListener('click', () => {
      if (!confirm('هل تريد تفريغ بنود الفاتورة؟')) return;
      invoiceItems = [];
      renderInvoice();
    }));

    onId('previewBtn', el => el.addEventListener('click', () => {
      if (invoiceItems.length === 0) return showToast('لا توجد بنود للمعاينة', 'error');
      let html = `<h3>معاينة الفاتورة</h3><table   style="width:100%;border-collapse:collapse"><thead><tr><th>المنتج</th><th>الكمية</th><th>سعر البيع</th><th>الإجمالي</th></tr></thead><tbody>`;
      let total = 0;
      invoiceItems.forEach(it => {
        const line = (it.qty || 0) * (it.selling_price || 0);
        total += line;
        html += `<tr><td>${esc(it.product_name)}</td><td>${fmt(it.qty)}</td><td>${fmt(it.selling_price)}</td><td>${fmt(line)}</td></tr>`
      });
      html += `</tbody></table><div style="margin-top:8px"><strong>الإجمالي: ${fmt(total)}</strong></div>`;
      onId('batchDetailBody', el => el.innerHTML = html);
      onId('batchTitle', el => el.textContent = 'معاينة الفاتورة');
      onId('batchDetailModal_backdrop', el => el.style.display = 'flex');
    }));

    // confirm modal open
    onId('confirmBtn', el => el.addEventListener('click', () => {
      if (!selectedCustomer) return showToast('الرجاء اختيار عميل', 'error');
      if (invoiceItems.length === 0) return showToast('لا توجد بنود لحفظ الفاتورة', 'error');
      // build preview
      onId('confirmClientPreview', el => el.innerHTML = `<div class="cust-card"><div><strong>👤 ${esc(selectedCustomer.name)}</strong><div class="small-muted">📞 ${esc(selectedCustomer.mobile)} • ${esc(selectedCustomer.city)}</div><div class="small-muted">📍 ${esc(selectedCustomer.address)}</div></div></div>`);
      let html = `<div style="max-height:360px;overflow:auto"><table style="width:100%"><thead><tr><th>المنتج</th><th>الكمية</th><th>سعر البيع</th><th>الإجمالي</th></tr></thead><tbody>`;
      let total = 0;
      invoiceItems.forEach(it => {
        const line = (it.qty || 0) * (it.selling_price || 0);
        total += line;
        html += `<tr><td>${esc(it.product_name)}</td><td>${fmt(it.qty)}</td><td>${fmt(it.selling_price)}</td><td>${fmt(line)}</td></tr>`;
      });
      html += `</tbody></table></div>`;
      onId('confirmItemsPreview', el => el.innerHTML = html);
      onId('confirm_total_before', el => el.textContent = fmt(total));
      onId('confirmModal_backdrop', el => el.style.display = 'flex');
    }));

    // confirm send
    onId('confirmSend', btn => btn.addEventListener('click', async () => {
      // prepare payload
      const payload = invoiceItems.map(it => ({
        product_id: it.product_id,
        qty: Number(it.qty),
        selling_price: Number(it.selling_price)
      }));
      const fd = new FormData();
      fd.append('action', 'save_invoice');
      fd.append('csrf_token', getCsrfToken());
      fd.append('customer_id', selectedCustomer.id);
      fd.append('status', document.querySelector('input[name="invoice_state"]:checked').value);
      fd.append('notes', $('invoiceNotes') ? $('invoiceNotes').value : '');
      fd.append('items', JSON.stringify(payload));
      try {
        const res = await fetch(location.pathname + '?action=save_invoice', {
          method: 'POST',
          body: fd,
          credentials: 'same-origin'
        });
        const txt = await res.text();
        let json;
        try {
          json = JSON.parse(txt);


        } catch (e) {
          console.error('Invalid response', txt);
          throw new Error('Invalid JSON');
        }
        if (!json.ok) {
          showToast(json.error || 'فشل الحفظ', 'error');
          return;
        }
        showToast(json.msg || 'تم الحفظ', 'success');
        // reset
        invoiceItems = [];
        renderInvoice();
        loadProducts();
        // بعد حصول json
// if (json.invoice_id) {
//   // عرض فوق الصفحة
//   const topEl = document.getElementById('currentInvoiceNumber');
//   if (topEl) topEl.textContent = 'رقم الفاتورة: ' + json.invoice_number;
//   // في الـ confirm preview أيضاً تأكَّد من عرضه
//   const confNum = document.getElementById('confirmInvoiceId');
//   if (confNum) confNum.textContent = 'فاتورة #' + json.invoice_number;
// }

        onId('confirmModal_backdrop', el => el.style.display = 'none');
        // show options: new invoice or go to invoices
        setTimeout(() => {
          if (confirm('تمت إضافة الفاتورة بنجاح. هل تريد إنشاء فاتورة جديدة الآن؟ (إلغاء للانتقال لعرض الفاتورة)')) {
            // new invoice: clear UI
            invoiceItems = [];
            renderInvoice();
            onId('invoiceNotes', n => n.value = '');
            window.reload()
          } else {
            // go to invoices page based on status (dynamic path)
            const st = document.querySelector('input[name="invoice_state"]:checked').value;
            // Use dynamic path based on current location
            const base = location.pathname.replace(/\/invoices_out\/create_invoice\.php.*$/, '/admin');
            if (st === 'paid') window.location.href = base + '/delivered_invoices.php';
            else window.location.href = base + '/pending_invoices.php';
          }
        }, 300);
      } catch (e) {
        console.error(e);
        showToast('خطأ في الاتصال أو استجابة غير صحيحة', 'error');
      }
    }));

    onId('confirmCancel', btn => btn.addEventListener('click', () => onId('confirmModal_backdrop', m => m.style.display = 'none')));

    // ---------- FIFO preview ----------
    async function openFifoPreview(idx) {
      const it = invoiceItems[idx];
      if (!it) return;
      try {
        const json = await fetchJson(location.pathname + '?action=batches&product_id=' + encodeURIComponent(it.product_id));
        if (!json.ok) return showToast(json.error || 'خطأ في جلب الدفعات', 'error');
        const batches = (json.batches || []).slice().sort((a, b) => (a.received_at || a.created_at || '') > (b.received_at || b.created_at || '') ? 1 : -1);
        let need = Number(it.qty || 0);
        let html = `<h4>تفاصيل FIFO — ${esc(it.product_name)}</h4><table class="custom-table" style="width:100%;border-collapse:collapse"><thead class="center"><tr><th>رقم الدفعة</th><th>التاريخ</th><th>المتبقي</th><th>سعر الشراء</th><th>مأخوذ</th><th>تكلفة</th></tr></thead><tbody>`;
        let totalCost = 0;
        for (const b of batches) {
          if (need <= 0) break;
          if (b.status !== 'active' || (parseFloat(b.remaining || 0) <= 0)) continue;
          const avail = parseFloat(b.remaining || 0);
          const take = Math.min(avail, need);
          const cost = take * parseFloat(b.unit_cost || 0);
          totalCost += cost;
          html += `<tr><td class="monos">${b.id}</td><td>${esc(b.received_at||b.created_at||'-')}</td><td>${fmt(b.remaining)}</td><td>${fmt(b.unit_cost)}</td><td>${fmt(take)}</td><td>${fmt(cost)}</td></tr>`;
          need -= take;
        }
        if (need > 0) html += `<tr><td colspan="6" style="color:#b91c1c">تحذير: الرصيد غير كافٍ.</td></tr>`;
        html += `</tbody></table><div style="margin-top:8px"><strong>إجمالي تكلفة البند:</strong> ${fmt(totalCost)} ج</div>`;
        onId('batchDetailBody', el => el.innerHTML = html);
        onId('batchTitle', el => el.textContent = 'تفاصيل FIFO');
        onId('batchDetailModal_backdrop', el => el.style.display = 'flex');
      } catch (e) {
        console.error(e);
        showToast('تعذر جلب الدفعات', 'error');
      }
    }

    // batches modal (full)
    async function openBatchesModal(productId) {
      try {
        await fetchJson(location.pathname + '?action=sync_consumed').catch(() => {}); // sync best-effort
        const json = await fetchJson(location.pathname + '?action=batches&product_id=' + productId);
        if (!json.ok) return showToast(json.error || 'خطأ في جلب الدفعات', 'error');
        const p = json.product || {};
        onId('batchesTitle', el => el.textContent = `دفعات — ${p.name || ''}`);
        onId('batchesInfo', el => el.textContent = `${p.product_code || ''}`);
        const rows = json.batches || [];
        if (!rows.length) {
          onId('batchesTable', el => el.innerHTML = '<div class="small-muted">لا توجد دفعات.</div>');
          onId('batchesModal_backdrop', m => m.style.display = 'flex');
          return;
        }
        let html = `<table class="custom-table" style="width:100%;border-collapse:collapse"><thead class="center"><tr><th>رقم الدفعة</th><th>التاريخ</th><th>كمية</th><th>المتبقي</th><th>سعر الشراء</th><th>سعر البيع</th><th>رقم الفاتورة</th><th>ملاحظات</th><th>الحالة</th><th>عرض</th></tr></thead><tbody>`;
        rows.forEach(b => {
          const st = b.status === 'active' ? '<span class="badge green">فعال</span>' : (b.status === 'consumed' ? '<span class="badge warn">مستهلك</span>' : (b.status === 'reverted' ? '<span class="badge purple">مرجع</span>' : '<span class="badge red">ملغى</span>'));
          html += `<tr><td class="monos">${b.id}</td><td class="small monos">${b.received_at||b.created_at||'-'}</td><td>${fmt(b.qty)}</td><td>${fmt(b.remaining)}</td><td>${fmt(b.unit_cost)}</td><td>${fmt(b.sale_price)}</td><td class="monos">${b.source_invoice_id||'-'}</td><td class="small">${esc(b.notes||'-')}</td><td>${st}</td><td><button class="btn ghost view-batch" data-id="${b.id}">عرض</button></td></tr>`;
        });
        html += `</tbody></table>`;
        onId('batchesTable', el => el.innerHTML = html);
        // attach view handlers
        document.querySelectorAll('.view-batch').forEach(btn => btn.addEventListener('click', () => {
          const id = btn.dataset.id;
          const row = rows.find(r => r.id == id);
          if (!row) return;
          const st = row.status === 'active' ? 'فعال' : (row.status === 'consumed' ? 'مستهلك' : (row.status === 'reverted' ? 'مرجع' : 'ملغى'));
          let html = `<table style="width:100%"><tbody>
          <tr><td>رقم الدفعة</td><td class="monos">${row.id}</td></tr>
          <tr><td>الكمية الأصلية</td><td>${fmt(row.qty)}</td></tr>
          <tr><td>المتبقي</td><td>${fmt(row.remaining)}</td></tr>
          <tr><td>سعر الشراء</td><td>${fmt(row.unit_cost)}</td></tr>
          <tr><td>سعر البيع</td><td>${fmt(row.sale_price)}</td></tr>
          <tr><td>تاريخ الاستلام</td><td>${esc(row.received_at||row.created_at||'-')}</td></tr>
          <tr><td>رقم الفاتورة المرتبطة</td><td>${row.source_invoice_id||'-'}</td></tr>
          <tr><td>ملاحظات</td><td>${esc(row.notes||'-')}</td></tr>
          <tr><td>حالة</td><td>${esc(st)}</td></tr>
          <tr><td>سبب الإلغاء</td><td>${row.status==='cancelled'?esc(row.cancel_reason||'-'):'-'}</td></tr>
          <tr><td>سبب الإرجاع</td><td>${row.status==='reverted'?esc(row.revert_reason||'-'):'-'}</td></tr>
        </tbody></table>`;
          onId('batchDetailBody', el => el.innerHTML = html);
          onId('batchTitle', el => el.textContent = 'تفاصيل الدفعة');
          onId('batchDetailModal_backdrop', m => m.style.display = 'flex');
        }));
        onId('batchesModal_backdrop', m => m.style.display = 'flex');
      } catch (e) {
        console.error(e);
        showToast('خطأ في فتح الدفعات', 'error');
      }
    }
document.addEventListener('click', function(e){
  if (e.target.matches('.select-customer')) {
    const li = e.target.closest('.customer-item');
    if (!li) return;
    const cid = li.dataset.customerId;
    // Move selected li to top of list
    const ul = document.getElementById('customersList');
    ul.prepend(li);

    // mark active + enable/disable others
    document.querySelectorAll('#customersList .customer-item').forEach(item=>{
      if (item === li) {
        item.classList.add('active');
        // if it's a button-based UI, ensure only active is enabled
        item.querySelectorAll('button, input, a').forEach(el=>{
          el.disabled = false;
        });
      } else {
        item.classList.remove('active');
        // disable interactions for others
        item.querySelectorAll('button, input, a').forEach(el=>{
          el.disabled = true;
        });
      }
    });

    // set hidden field
    document.getElementById('selected_customer_id').value = cid;

    // optional: visually focus/scroll
    li.scrollIntoView({behavior:'smooth', block:'start'});
  }
});

    // close modal handlers
    onId('closeBatchesBtn', btn => btn.addEventListener('click', () => onId('batchesModal_backdrop', m => m.style.display = 'none')));
    onId('closeBatchDetailBtn', btn => btn.addEventListener('click', () => onId('batchDetailModal_backdrop', m => m.style.display = 'none')));
    onId('batchDetailModal_backdrop', el => el.addEventListener('click', e => {
      if (e.target === el) el.style.display = 'none';
    }));
    onId('batchesModal_backdrop', el => el.addEventListener('click', e => {
      if (e.target === el) el.style.display = 'none';
    }));

    // sync button
    onId('syncBtn', btn => btn.addEventListener('click', async () => {
      try {
        const json = await fetchJson(location.pathname + '?action=sync_consumed');
        if (json.ok) showToast('تم مزامنة الدفعات', 'success');
        loadProducts();
      } catch (e) {
        showToast('خطأ في المزامنة', 'error');
      }
    }));

    // ---------- customers ----------
    
    async function loadCustomers(q = '') {
      try {
        const json = await fetchJson(location.pathname + '?action=customers' + (q ? ('&q=' + encodeURIComponent(q)) : ''), {
          credentials: 'same-origin'
        });
        if (!json.ok) {
          console.warn(json.error);
          return;
        }
        customers = json.customers || [];
        const wrap = $('customersList');
        if (!wrap) return;
        wrap.innerHTML = '';
        customers.forEach(c => {
          const d = document.createElement('div');
          d.className = 'cust-card';
          d.innerHTML = `<div><strong>${esc(c.name)}</strong><div class="small-muted">${esc(c.mobile)} — ${esc(c.city||'')}</div></div><div><button class="btn ghost choose-cust" data-id="${c.id}">اختر</button></div>`;
          wrap.appendChild(d);
        });
        // attach choose
        document.querySelectorAll('.choose-cust').forEach(btn => btn.addEventListener('click', async () => {
          const cid = btn.dataset.id;
          try {
            const fd = new FormData();
            fd.append('action', 'select_customer');
            fd.append('csrf_token', getCsrfToken());
            fd.append('customer_id', cid);
            const res = await fetch(location.pathname + '?action=select_customer', {
              method: 'POST',
              body: fd,
              credentials: 'same-origin'
            });
            const txt = await res.text();
            let json;
            try {
              json = JSON.parse(txt);
            } catch (e) {
              showToast('استجابة غير متوقعة', 'error');
              return;
            }
            if (!json.ok) {
              showToast(json.error || 'فشل اختيار العميل', 'error');
              return;
            }
            selectedCustomer = json.customer;
            renderSelectedCustomer();
            showToast('تم اختيار العميل', 'success');
          } catch (e) {
            console.error(e);
            showToast('خطأ في الاتصال', 'error');
          }
        }));
      } catch (e) {
        console.error(e);
      }
    }

    onId('customerSearchInput', el => el.addEventListener('input', debounce(() => loadCustomers(el.value.trim()), 250)));

    function renderSelectedCustomer() {
      if (!selectedCustomer) {
        onId('selectedCustomerName', el => el.textContent = 'لم يتم الاختيار');
        onId('selectedCustomerDetails', el => el.innerHTML = '');
        return;
      }
      onId('selectedCustomerName', el => el.textContent = selectedCustomer.name || '—');
      onId('selectedCustomerDetails', el => elinner = el.innerHTML = `📞 ${esc(selectedCustomer.mobile||'-')} <br> 🏙️ ${esc(selectedCustomer.city||'-')} <div class="small-muted">📍 ${esc(selectedCustomer.address||'-')}</div>`);
    }

    // cash customer button (fixed)
    onId('cashCustomerBtn', btn => btn.addEventListener('click', async () => {
      // try to find a customer named "عميل نقدي" or id 8 in your DB; we'll set selectedCustomer to the matching one if exists
      try {
        const json = await fetchJson(location.pathname + '?action=customers&q=عميل نقدي');
        if (!json.ok) {
          showToast('خطأ في جلب العملاء', 'error');
          return;
        }
        const found = (json.customers || []).find(c => c.name && c.name.includes('نقد') || c.name === 'عميل نقدي') || (json.customers || [])[0] || null;
        if (found) {
          // select via session endpoint
          const fd = new FormData();
          fd.append('action', 'select_customer');
          fd.append('csrf_token', getCsrfToken());
          fd.append('customer_id', found.id);
          const res = await fetch(location.pathname + '?action=select_customer', {
            method: 'POST',
            body: fd,
            credentials: 'same-origin'
          });
          const txt = await res.text();
          let sel;
          try {
            sel = JSON.parse(txt);
          } catch (e) {
            showToast('استجابة غير متوقعة', 'error');
            return;
          }
          if (!sel.ok) {
            showToast(sel.error || 'تعذر اختيار العميل', 'error');
            return;
          }
          selectedCustomer = sel.customer;
          renderSelectedCustomer();
          showToast('تم اختيار العميل النقدي', 'success');
        } else showToast('لم يتم العثور على حساب نقدي', 'error');
      } catch (e) {
        console.error(e);
        showToast('خطأ في الاتصال', 'error');
      }
    }));

    onId('btnUnselectCustomer', btn => btn.addEventListener('click', async () => {
      try {
        const fd = new FormData();
        fd.append('action', 'select_customer');
        fd.append('csrf_token', getCsrfToken());
        fd.append('customer_id', 0);
        const res = await fetch(location.pathname + '?action=select_customer', {
          method: 'POST',
          body: fd,
          credentials: 'same-origin'
        });
        const txt = await res.text();
        let json;
        try {
          json = JSON.parse(txt);
        } catch (e) {
          showToast('استجابة غير متوقعة', 'error');
          return;
        }
        selectedCustomer = null;
        renderSelectedCustomer();
        showToast('تم إلغاء اختيار العميل', 'success');
      } catch (e) {
        console.error(e);
        showToast('خطأ في الاتصال', 'error');
      }
    }));

    // add customer modal handlers
    onId('openAddCustomerBtn', btn => btn.addEventListener('click', () => onId('addCustomer_backdrop', m => m.style.display = 'flex')));
    onId('closeAddCust', btn => btn.addEventListener('click', () => onId('addCustomer_backdrop', m => m.style.display = 'none')));
    onId('submitAddCust', btn => btn.addEventListener('click', async () => {
      const name = $('new_name') ? $('new_name').value.trim() : '';
      const mobile = $('new_mobile') ? $('new_mobile').value.trim() : '';
      const city = $('new_city') ? $('new_city').value.trim() : '';
      const addr = $('new_address') ? $('new_address').value.trim() : '';
      const notes = $('new_notes') ? $('new_notes').value.trim() : '';
      if (!name) return showToast('الرجاء إدخال اسم العميل', 'error');
      const fd = new FormData();
      fd.append('action', 'add_customer');
      fd.append('csrf_token', getCsrfToken());
      fd.append('name', name);
      fd.append('mobile', mobile);
      fd.append('city', city);
      fd.append('address', addr);
      fd.append('notes', notes);
      try {
        const res = await fetch(location.pathname + '?action=add_customer', {
          method: 'POST',
          body: fd,
          credentials: 'same-origin'
        });
        const txt = await res.text();
        let json;
        try {
          json = JSON.parse(txt);
        } catch (e) {
          showToast('استجابة غير متوقعة', 'error');
          return;
        }
        if (!json.ok) return showToast(json.error || 'فشل إضافة العميل', 'error');
        // auto-select newly created
        selectedCustomer = json.customer;
        renderSelectedCustomer();
        onId('addCustomer_backdrop', m => m.style.display = 'none');
        showToast('تم إضافة العميل واختياره', 'success');
        loadCustomers(); // refresh list
      } catch (e) {
        console.error(e);
        showToast('خطأ في الاتصال', 'error');
      }
    }));

    // theme toggle
    onId('toggleThemeBtn', btn => btn.addEventListener('click', () => {
      const el = document.documentElement;
      const cur = el.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
      el.setAttribute('data-theme', cur === 'dark' ? 'dark' : 'light');
    }));

    // initial load
    (async function init() {
      try {
        await fetchJson(location.pathname + '?action=sync_consumed').catch(() => {});
      } catch (e) {}
      loadProducts();
      loadCustomers();
      renderSelectedCustomer();
    })();

  }); // DOMContentLoaded
</script>

<?php
require_once BASE_DIR . 'partials/footer.php';
?>