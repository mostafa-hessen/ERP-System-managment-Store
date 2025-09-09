<?php
// invoices_out/view.php
// إصدار مُحدَّث: إصلاحات create_customer bind_param، منع إضافة منتجات منتهية الرصيد، تفريغ نموذج الإضافة، تحديث المخزون في الواجهة بعد الإتمام، شرح location.pathname usage.
// خذ نسخة احتياطية قبل الاستبدال.

$page_title = "تفاصيل الفاتورة - كاشير سريع";
$page_css = "invoice_out.css";

ob_start();

if (file_exists(dirname(__DIR__) . '/config.php')) {
    require_once dirname(__DIR__) . '/config.php';
} elseif (file_exists(dirname(dirname(__DIR__)) . '/config.php')) {
    require_once dirname(dirname(__DIR__)) . '/config.php';
} else {
    error_log('config.php missing in invoices_out/view.php');
    http_response_code(500);
    echo "خطأ داخلي: إعدادات غير موجودة.";
    exit;
}
if (!isset($conn) || !$conn) { echo "خطأ في الاتصال بقاعدة البيانات."; exit; }

function e($s){ return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

// CSRF
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf_token = $_SESSION['csrf_token'];

// readonly mode
$readonly = isset($_GET['readonly']) && $_GET['readonly'] == '1';

// Fixed cash customer ID (you specified ID 8)
$FIXED_CASH_ID = 8;
$CASH_CUSTOMER_ID = null;
$CASH_CUSTOMER_NAME = 'عميل نقدي';
try {
    // Prefer given ID if exists
    $st = $conn->prepare("SELECT id, name FROM customers WHERE id = ? LIMIT 1");
    if ($st) {
        $st->bind_param("i", $FIXED_CASH_ID);
        $st->execute();
        $res = $st->get_result();
        if ($row = $res->fetch_assoc()) {
            $CASH_CUSTOMER_ID = intval($row['id']);
            $CASH_CUSTOMER_NAME = $row['name'] ?: $CASH_CUSTOMER_NAME;
        }
        $st->close();
    }
    // if not found by ID, try by name or create fallback (to be safe)
    if (!$CASH_CUSTOMER_ID) {
        $st2 = $conn->prepare("SELECT id, name FROM customers WHERE name = ? LIMIT 1");
        if ($st2) {
            $st2->bind_param("s", $CASH_CUSTOMER_NAME);
            $st2->execute();
            $r2 = $st2->get_result();
            if ($row2 = $r2->fetch_assoc()) {
                $CASH_CUSTOMER_ID = intval($row2['id']);
                $CASH_CUSTOMER_NAME = $row2['name'] ?: $CASH_CUSTOMER_NAME;
            }
            $st2->close();
        }
    }
    if (!$CASH_CUSTOMER_ID) {
        $ins = $conn->prepare("INSERT INTO customers (name,mobile,city,address,created_by,created_at) VALUES (?, '', '', '', 0, NOW())");
        if ($ins) {
            $ins->bind_param("s", $CASH_CUSTOMER_NAME);
            $ins->execute();
            $CASH_CUSTOMER_ID = $ins->insert_id;
            $ins->close();
        }
    }
} catch (Exception $ex) {
    error_log("CASH customer check failed: " . $ex->getMessage());
}

// Helpers
function get_next_invoice_id($conn) {
    $db = null;
    $res = $conn->query("SELECT DATABASE() as db");
    if ($res) { $row = $res->fetch_assoc(); $db = $row['db']; $res->free(); }
    if (!$db) return null;
    $stmt = $conn->prepare("SELECT AUTO_INCREMENT FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'invoices_out' LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param("s", $db); $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc(); $stmt->close();
    return $r['AUTO_INCREMENT'] ?? null;
}
function invoices_has_notes($conn) {
    $ok = false;
    $stmt = $conn->prepare("SHOW COLUMNS FROM invoices_out LIKE 'notes'");
    if ($stmt) { $stmt->execute(); $res = $stmt->get_result(); if ($res && $res->num_rows>0) $ok = true; $stmt->close(); }
    return $ok;
}
$has_notes_col = invoices_has_notes($conn);

// AJAX endpoints
if (isset($_GET['action']) && $_GET['action'] === 'search_customers') {
    header('Content-Type: application/json; charset=utf-8');
    $q = trim($_GET['q'] ?? '');
    $out = [];
    if ($q === '') {
        $stmt = $conn->prepare("SELECT id, name, mobile, city, address FROM customers WHERE id <> ? ORDER BY id DESC LIMIT 200");
        $stmt->bind_param("i", $CASH_CUSTOMER_ID);
        $stmt->execute(); $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) $out[] = $r; $stmt->close();
    } else {
        $like = "%$q%";
        $stmt = $conn->prepare("SELECT id, name, mobile, city, address FROM customers WHERE (name LIKE ? OR mobile LIKE ?) AND id <> ? LIMIT 200");
        $stmt->bind_param("ssi", $like, $like, $CASH_CUSTOMER_ID);
        $stmt->execute(); $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) $out[] = $r; $stmt->close();
    }
    echo json_encode(['ok'=>true,'results'=>$out], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_customer') {
    header('Content-Type: application/json; charset=utf-8');
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) { echo json_encode(['ok'=>false,'msg'=>'CSRF error']); exit; }
    $name = trim($_POST['name'] ?? ''); $mobile = trim($_POST['mobile'] ?? ''); $city = trim($_POST['city'] ?? ''); $address = trim($_POST['address'] ?? '');
    if ($name === '') { echo json_encode(['ok'=>false,'msg'=>'الاسم مطلوب']); exit; }
    if ($mobile !== '' && !preg_match('/^[0-9]{11}$/', $mobile)) { echo json_encode(['ok'=>false,'msg'=>'الموبايل يجب أن يتكون من 11 رقمًا']); exit; }
    if ($mobile !== '') {
        $stmt = $conn->prepare("SELECT id FROM customers WHERE mobile = ? LIMIT 1"); $stmt->bind_param("s",$mobile); $stmt->execute(); $stmt->store_result();
        if ($stmt->num_rows>0) { $stmt->close(); echo json_encode(['ok'=>false,'msg'=>'رقم الموبايل مسجل سابقاً']); exit; }
        $stmt->close();
    }
    $created_by = $_SESSION['id'] ?? 0;
    // <-- FIX: correct bind types: 4 strings then integer -> "ssssi"
    $stmt = $conn->prepare("INSERT INTO customers (name,mobile,city,address,created_by,created_at) VALUES (?, ?, ?, ?, ?, NOW())");
    if (!$stmt) { echo json_encode(['ok'=>false,'msg'=>'DB prepare error']); exit; }
    $stmt->bind_param("ssssi", $name, $mobile, $city, $address, $created_by);
    if (!$stmt->execute()) { echo json_encode(['ok'=>false,'msg'=>'فشل الإنشاء: '.$stmt->error]); $stmt->close(); exit; }
    $new_id = $stmt->insert_id; $stmt->close();
    $stmt2 = $conn->prepare("SELECT id,name,mobile,city,address FROM customers WHERE id = ? LIMIT 1"); $stmt2->bind_param("i",$new_id); $stmt2->execute(); $row = $stmt2->get_result()->fetch_assoc(); $stmt2->close();
    echo json_encode(['ok'=>true,'customer'=>$row], JSON_UNESCAPED_UNICODE);
    exit;
}

// Load products and next invoice id
$products_list = [];
$sqlP = "SELECT id, product_code, name, selling_price, cost_price, current_stock FROM products ORDER BY name LIMIT 3000";
if ($resP = $conn->query($sqlP)) { while ($r = $resP->fetch_assoc()) $products_list[] = $r; $resP->free(); }
$next_invoice_id = get_next_invoice_id($conn);

// Load invoice if id given
$invoice_id = 0; $invoice = null; $items = [];
if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $invoice_id = intval($_GET['id']);
    $stmt = $conn->prepare("SELECT * FROM invoices_out WHERE id = ? LIMIT 1");
    if ($stmt) { $stmt->bind_param("i",$invoice_id); $stmt->execute(); $invoice = $stmt->get_result()->fetch_assoc(); $stmt->close(); }
    if ($invoice) {
        $s2 = $conn->prepare("SELECT id, product_id, quantity, selling_price, cost_price_per_unit, total_price FROM invoice_out_items WHERE invoice_out_id = ?");
        if ($s2) { $s2->bind_param("i",$invoice_id); $s2->execute(); $res2 = $s2->get_result(); while ($r = $res2->fetch_assoc()) $items[] = $r; $s2->close(); }
    }
}
// --- default delivered value handling (supports 'yes'/'no', 1/0, true/false)
// put this after you load $invoice from DB
$delivered_val = 'no'; // default = مؤجل
if (!empty($invoice) && array_key_exists('delivered', $invoice)) {
    $d = $invoice['delivered'];
    if ($d === 'yes' || $d === '1' || $d === 1 || $d === 'true' || $d === true) {
        $delivered_val = 'yes';
    } else {
        $delivered_val = 'no';
    }
}
// If the form was submitted and we re-render (validation fail), prefer posted value
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delivered'])) {
    $delivered_val = ($_POST['delivered'] === 'yes') ? 'yes' : 'no';
}

// Selected customer if invoice
$session_customer_id = 0;
$session_notes = '';
if ($invoice) {
    $session_customer_id = $invoice['customer_id'] ?? 0;
    $session_notes = $invoice['notes'] ?? '';
}
$customer = null;
if ($session_customer_id > 0) {
    $st = $conn->prepare("SELECT id,name,mobile,city,address FROM customers WHERE id = ? LIMIT 1");
    if ($st) { $st->bind_param("i",$session_customer_id); $st->execute(); $customer = $st->get_result()->fetch_assoc(); $st->close(); }
}

// Stock helper
function check_stock_for_items($conn, $items) {
    $bad = [];
    foreach ($items as $it) {
        $pid = intval($it['product_id'] ?? 0);
        $qty = floatval($it['quantity'] ?? 0);
        if ($pid <= 0) continue;
        $stmt = $conn->prepare("SELECT current_stock FROM products WHERE id = ? LIMIT 1");
        if (!$stmt) { $bad[] = "خطأ فني عند التحقق من المخزون"; continue; }
        $stmt->bind_param("i",$pid); $stmt->execute(); $r = $stmt->get_result()->fetch_assoc(); $stmt->close();
        $stock = floatval($r['current_stock'] ?? 0);
        if ($qty > $stock) $bad[] = "المخزون غير كافٍ للمنتج ID: $pid (المطلوب $qty — المتاح $stock)";
    }
    return $bad;
}

// Handle confirm (AJAX JSON)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_complete'])) {
    $errors = [];
    header('Content-Type: application/json; charset=utf-8');
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        echo json_encode(['ok'=>false,'errors'=>["طلب غير صالح (CSRF)"]]); exit;
    }
    $posted_items = $_POST['items'] ?? [];
    $valid_items = [];
    foreach ($posted_items as $it) {
        $pid = intval($it['product_id'] ?? 0);
        $qty = floatval($it['quantity'] ?? 0);
        $sp = floatval($it['selling_price'] ?? 0);
        $cp = floatval($it['cost_price_per_unit'] ?? 0);
        if ($qty <= 0) continue;
        $valid_items[] = ['product_id'=>$pid,'quantity'=>$qty,'selling_price'=>$sp,'cost_price_per_unit'=>$cp,'total_price'=>round($qty*$sp,2)];
    }

    $posted_customer_id = intval($_POST['customer_id'] ?? 0);
    $posted_temp_name = trim($_POST['temp_customer_name'] ?? '');
    if ($posted_customer_id <= 0) $errors[] = "يجب اختيار عميل قبل الإتمام.";
    if (empty($valid_items)) $errors[] = "لا توجد بنود صالحة لإتمام الفاتورة.";

    $stock_issues = check_stock_for_items($conn, $valid_items);
    if (!empty($stock_issues)) { foreach($stock_issues as $s) $errors[] = $s; }

    $delivered_value = (isset($_POST['delivered']) && $_POST['delivered']==='yes') ? 'yes' : 'no';
    $invoice_group = $_POST['invoice_group'] ?? 'group1';
    $notes = trim($_POST['notes'] ?? '');

    if (!empty($errors)) { echo json_encode(['ok'=>false,'errors'=>$errors]); exit; }

    try {
        $conn->begin_transaction();

        if ($posted_customer_id === $CASH_CUSTOMER_ID) {
            $notes = trim($notes . "\n(عميل نقدي)");
        }

       // ----------------- replace existing INSERT block with this ده لان مكنش بيبعت بيانات التسليم اذا كانت نقدي -----------------
        $created_by = $_SESSION['id'] ?? 0;
        $updated_by = ($delivered_value === 'yes') ? $created_by : null;

        if ($has_notes_col) {
            if ($delivered_value === 'yes') {
                $stmt = $conn->prepare("INSERT INTO invoices_out (customer_id, delivered, invoice_group, created_by, created_at, updated_by, updated_at, notes) VALUES (?, ?, ?, ?, NOW(), ?, NOW(), ?)");
                if (!$stmt) throw new Exception("تحضير إدخال الفاتورة فشل: " . $conn->error);
                $stmt->bind_param("issiis", $posted_customer_id, $delivered_value, $invoice_group, $created_by, $updated_by, $notes);
            } else {
                $stmt = $conn->prepare("INSERT INTO invoices_out (customer_id, delivered, invoice_group, created_by, created_at, notes) VALUES (?, ?, ?, ?, NOW(), ?)");
                if (!$stmt) throw new Exception("تحضير إدخال الفاتورة فشل: " . $conn->error);
                $stmt->bind_param("issis", $posted_customer_id, $delivered_value, $invoice_group, $created_by, $notes);
            }
        } else {
            if ($delivered_value === 'yes') {
                $stmt = $conn->prepare("INSERT INTO invoices_out (customer_id, delivered, invoice_group, created_by, created_at, updated_by, updated_at) VALUES (?, ?, ?, ?, NOW(), ?, NOW())");
                if (!$stmt) throw new Exception("تحضير إدخال الفاتورة فشل: " . $conn->error);
                $stmt->bind_param("issii", $posted_customer_id, $delivered_value, $invoice_group, $created_by, $updated_by);
            } else {
                $stmt = $conn->prepare("INSERT INTO invoices_out (customer_id, delivered, invoice_group, created_by, created_at) VALUES (?, ?, ?, ?, NOW())");
                if (!$stmt) throw new Exception("تحضير إدخال الفاتورة فشل: " . $conn->error);
                $stmt->bind_param("issi", $posted_customer_id, $delivered_value, $invoice_group, $created_by);
            }
        }
       // -----------------end replace existing INSERT block -----------------

        if (!$stmt->execute()) throw new Exception("تنفيذ إدخال الفاتورة فشل: " . $stmt->error);
        $new_invoice_id = $stmt->insert_id; $stmt->close();

        $insItem = $conn->prepare("INSERT INTO invoice_out_items (invoice_out_id, product_id, quantity, total_price, cost_price_per_unit, selling_price, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
        if (!$insItem) throw new Exception("تحضير إدخال البنود فشل: " . $conn->error);
        $updStock = $conn->prepare("UPDATE products SET current_stock = current_stock - ? WHERE id = ? AND current_stock >= ?");
        if (!$updStock) throw new Exception("تحضير تحديث المخزون فشل: " . $conn->error);

        foreach ($valid_items as $it) {
            $iid = $new_invoice_id; $pid = $it['product_id']; $qty = $it['quantity']; $total = $it['total_price']; $cp = $it['cost_price_per_unit']; $sp = $it['selling_price'];
            if (!$insItem->bind_param("iidddd", $iid, $pid, $qty, $total, $cp, $sp)) throw new Exception("ربط بيانات إدخال بند فشل");
            if (!$insItem->execute()) throw new Exception("فشل إدخال بند: " . $insItem->error);
            if ($pid > 0) {
                if (!$updStock->bind_param("did", $qty, $pid, $qty)) throw new Exception("ربط بيانات تحديث المخزون فشل");
                if (!$updStock->execute()) throw new Exception("فشل تحديث المخزون: " . $updStock->error);
                if ($updStock->affected_rows == 0) throw new Exception("الرصيد غير كافٍ للمنتج ID: $pid");
            }
        }

        $insItem->close(); $updStock->close();
        $conn->commit();

        // respond with new invoice id (AJAX client will update next invoice number)
        echo json_encode(['ok'=>true,'invoice_id'=>$new_invoice_id,'message'=>"تم إتمام الفاتورة #$new_invoice_id"]);
        exit;
    } catch (Exception $ex) {
        $conn->rollback();
        echo json_encode(['ok'=>false,'errors'=>["فشل إتمام الفاتورة: " . $ex->getMessage()]]);
        exit;
    }
}

// include UI parts
require_once BASE_DIR . 'partials/header.php';
require_once BASE_DIR . 'partials/sidebar.php';
?>

<style>

</style>
<div class="invoice-out">
  
 <div id="topMsg" role="status"></div>

 <div class="wrap print-area">
  <div class="hdr">
    <div>
      <h2 style="margin:0"><?php echo e($page_title); ?></h2>
      <div class="small-muted">رقم الفاتورة المتوقع: <strong id="nextInvoiceId">#<?php echo e($next_invoice_id ?: '—'); ?></strong></div>
      <?php if ($invoice): ?>
        <div class="small-muted">عرض فاتورة: #<?php echo intval($invoice['id']); ?></div>
      <?php endif; ?>
    </div>
    <div>
      <?php if (!$readonly): ?>
        <button id="btnPrint" class="btn primary no-print">طباعة الفاتورة</button>
      <?php else: ?>
        <button id="btnPrint" class="btn primary">طباعة الفاتورة</button>
      <?php endif; ?>
    </div>
  </div>

  <?php if(!empty($_SESSION['message'])){ echo $_SESSION['message']; unset($_SESSION['message']); } ?>

  <?php if ($readonly && $invoice): ?>
    <div class="col" style="padding:18px">
      <h3>عرض الفاتورة رقم #<?= intval($invoice['id']) ?></h3>
      <?php
        $created_at_raw = $invoice['created_at'] ?? '';
        $created_at_display = '';
        if ($created_at_raw) {
          try {
            $dt = new DateTime($created_at_raw);
            $created_at_display = $dt->format('Y-m-d h:i A');
          } catch(Exception $e) {
            $created_at_display = e($created_at_raw);
          }
        }
      ?>
      <div style="margin-top:8px"><strong>التاريخ:</strong> <?= e($created_at_display ?: '—') ?></div>
      <div style="margin-top:6px"><strong>الحالة:</strong> <?= e($invoice['delivered'] === 'yes' ? 'تم الدفع' : 'مؤجل') ?></div>

      <div style="margin-top:12px"><strong>العميل:</strong>
        <?php
          if (!empty($invoice['customer_id'])) {
            $st = $conn->prepare("SELECT name,mobile FROM customers WHERE id = ? LIMIT 1");
            if ($st) { $st->bind_param("i",$invoice['customer_id']); $st->execute(); $c = $st->get_result()->fetch_assoc(); $st->close(); }
            echo '<div class="small-muted">'.e($c['name'] ?? '—').' — '.e($c['mobile'] ?? '').'</div>';
          } else {
            echo '<div class="small-muted">غير محدد</div>';
          }
        ?>
      </div>

      <div style="margin-top:12px"><strong>ملاحظة الفاتورة:</strong>
        <div class="small-muted"><?= nl2br(e($invoice['notes'] ?? '')) ?></div>
      </div>

      <h4 style="margin-top:14px">الأصناف</h4>
      <table>
        <thead><tr><th>المنتج</th><th>الكمية</th><th>سعر البيع</th><th>الإجمالي</th></tr></thead>
        <tbody>
          <?php foreach ($items as $it):
            $pn = '';
            foreach ($products_list as $pp) if ($pp['id']==$it['product_id']) { $pn = $pp['name']; break; }
          ?>
            <tr>
              <td><?= e($pn ?: ('#' . intval($it['product_id']))) ?></td>
              <td><?= e($it['quantity']) ?></td>
              <td><?= number_format($it['selling_price'],2) ?></td>
              <td><?= number_format($it['total_price'],2) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php else: ?>

  <form id="invoiceForm" method="post" action="<?php echo e($_SERVER['PHP_SELF']); ?>">
    <input type="hidden" name="csrf_token" value="<?php echo e($csrf_token); ?>">
    <input type="hidden" id="customer_id" name="customer_id" value="<?php echo e($customer['id'] ?? $session_customer_id ?? ''); ?>">
    <input type="hidden" id="temp_customer_name" name="temp_customer_name" value="">
    <input type="hidden" name="invoice_group" value="<?php echo e($invoice['invoice_group'] ?? 'group1'); ?>">
    <input type="hidden" name="confirm_complete" id="confirm_complete" value="1">

    <div class="columns">
      <!-- PRODUCTS -->
      <div class="col products">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
          <strong>المنتجات</strong>
          <input type="text" id="product_search" placeholder="بحث باسم أو كود..." style="padding:6px;border:1px solid #ddd;border-radius:6px;width:55%">
        </div>
        <div id="product_list">
          <?php foreach($products_list as $p):
            $out = floatval($p['current_stock']) <= 0 ? ' out-of-stock' : '';
          ?>
            <div class="product-item<?php echo $out; ?>" data-id="<?php echo (int)$p['id']; ?>"
                 data-name="<?php echo e($p['name']); ?>"
                 data-code="<?php echo e($p['product_code']); ?>"
                 data-price="<?php echo floatval($p['selling_price']); ?>"
                 data-cost="<?php echo floatval($p['cost_price']); ?>"
                 data-stock="<?php echo floatval($p['current_stock']); ?>">
              <div>
                <div style="font-weight:700"><?php echo e($p['name']); ?></div>
                <div class="small-muted">كود: <?php echo e($p['product_code']); ?> — رصيد: <span class="stock-number"><?php echo e($p['current_stock']); ?></span></div>
              </div>
              <div style="text-align:left;font-weight:700">
                <?php echo number_format($p['selling_price'],2); ?> ج.م
                <?php if(floatval($p['current_stock']) <= 0): ?>
                  <div class="badge-out">نفذ</div>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- ITEMS -->
      <div class="col items">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
          <div>
            <strong>بنود الفاتورة</strong><br>
            <span class="small-muted">اضغط على المنتج لإضافته — اختيار مكرر يزيد الكمية</span>
          </div>
          <div style="display:flex;gap:8px;align-items:center">
            <!-- <label>حالة الفاتورة</laظbel> -->
            <!-- <select id="delivered" name="delivered" style="padding:6px;border-radius:6px;border:1px solid #ddd">
              <option value="no">مؤجل</option>
              <option value="yes">تم الدفع</option>
            </select> -->
<div class="radio-group ">
  <strong>الحالة:</strong>

  <label class="radio-wrapper   " for="del_yes">
    <input id="del_yes" type="radio" name="delivered" value="yes" <?php echo ($delivered_val === 'yes') ? 'checked' : ''; ?>>
    <span>تم الدفع</span>
  </label>

  <label class="radio-wrapper mx-1" for="del_no">
    <input id="del_no" type="radio" name="delivered" value="no" <?php echo ($delivered_val === 'no') ? 'checked' : ''; ?>>
    <span>مؤجل</span>
  </label>
</div>

          </div>
        </div>

        <table>
          <thead><tr><th>المنتج</th><th class="qtycol">الكمية</th><th class="unitpricecol">سعر البيع</th><th class="uniteCost">سعر الشراء</th><th>الإجمالي</th><th class="no-print">حذف</th></tr></thead>
          <tbody id="itemsTableBody">
            <?php if(!empty($items)): foreach($items as $idx=>$it):
                $pn=''; foreach($products_list as $pp) if($pp['id']==$it['product_id']) { $pn=$pp['name']; break; }
            ?>
              <tr data-product-id="<?php echo (int)$it['product_id']; ?>">
                <td>
                  <input type="hidden" name="items[<?php echo $idx;?>][product_id]" value="<?php echo (int)$it['product_id']; ?>">
                  <?php echo e($pn ?: ('#'.(int)$it['product_id'])); ?>
                </td>
                <td class="qtycol"><input name="items[<?php echo $idx;?>][quantity]" class="qty" type="number" step="any" min="0" value="<?php echo e($it['quantity']); ?>"></td>
                <td class="unitpricecol"><input name="items[<?php echo $idx;?>][selling_price]" class="unit-price" type="number" step="0.01" min="0" value="<?php echo e($it['selling_price']); ?>"></td>
                <td><input name="items[<?php echo $idx;?>][cost_price_per_unit]" class="cost-price" type="number" step="0.01" min="0" value="<?php echo e($it['cost_price_per_unit']); ?>"></td>
                <td class="line-total"><?php echo number_format($it['total_price'],2); ?></td>
                <td class="no-print"><button type="button" class="btn ghost remove-row">حذف</button></td>
              </tr>
            <?php endforeach; else: ?>
              <tr id="no-items-row"><td colspan="6" class="no-items">لا توجد بنود بعد — اختر منتجاً لإضافته.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>

        <div style="display:flex;justify-content:space-between;align-items:center;margin-top:12px">
          <div>
            <button type="button" id="openConfirm" class="btn success">إتمام الفاتورة</button>
            <button type="button" id="clearDraft" class="btn ghost">تفريغ البنود</button>
          </div>
          <div>
            <div><strong>الإجمالي:</strong> <span id="total_before">0.00</span> ج.م</div>
          </div>
        </div>

        <div style="margin-top:12px">
          <label>ملاحظات الفاتورة (اختياري)</label>
          <textarea name="notes" id="notes" class="note-box" rows="3"><?php echo e($session_notes ?? ''); ?></textarea>
        </div>
      </div>

      <!-- CUSTOMERS -->
      <div class="col customers">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
          <strong>العملاء</strong>
          <div style="display:flex;gap:6px">
            <button type="button" id="openAddCustomerModal" class="btn ghost">إضافة</button>
            <!-- <a href="<?php echo e(BASE_URL); ?>customer/insert.php" class="btn ghost" target="_blank">صفحة الإضافة</a> -->
          </div>
        </div>

        <div style="margin-bottom:8px;display:flex;gap:6px;align-items:center">
          <input type="text" id="customer_search" placeholder="ابحث باسم أو موبايل..." style="padding:6px;border:1px solid #ddd;border-radius:6px;width:100%">
        </div>

        <div style="margin-top:12px;display:flex;flex-direction:column;gap:8px">
          <button type="button" id="cashCustomerBtn" class="btn">نقدي (ثابت)</button>
          <div id="selectedCustomerBox" class="small-muted">
            <?php if($customer): ?>
              <div>العميل الحالي: <strong><?php echo e($customer['name']); ?></strong> — <?php echo e($customer['mobile']); ?></div>
              <div class="small-muted"><?php echo e($customer['city']); ?> — <?php echo e($customer['address']); ?></div>
            <?php else: ?>
              <div>لا يوجد عميل محدد.</div>
            <?php endif; ?>
          </div>
          <div class="small-muted">اضغط على "اختر" عند العميل لتحديده، أو استخدم "نقدي (ثابت)" لوضع العميل النقدي مباشرة في الفاتورة.</div>
        </div>

        <div id="customer_list"><div class="small-muted">تحميل العملاء...</div></div>
      </div>
    </div>
  </form>

  <?php endif; ?>

</div>

<!-- Add Customer Modal -->
<!-- Toast Container -->

<!-- Add Customer Modal -->
<div id="modalAddCustomer" class="modal-backdrop" aria-hidden="true">
  <div class="mymodal">
    <h3>إضافة عميل جديد</h3>
    <div id="addCustMsg"></div>
    <div class="form-group">
      <input id="new_name" placeholder="الاسم" class="note-box">
      <input id="new_mobile" placeholder="رقم الموبايل (11 رقم)" class="note-box">
      <input id="new_city" placeholder="المدينة" class="note-box">
      <input id="new_address" placeholder="العنوان" class="note-box">
      <div class="actions">
        <button id="closeAddCust" type="button" class="btn ghost">إلغاء</button>
        <button id="submitAddCust" type="button" class="btn primary">حفظ وإختيار</button>
      </div>
    </div>
  </div>
</div>

<!-- Confirm Modal -->
<div id="modalConfirm" class="modal-backdrop" aria-hidden="true">
  <div class="mymodal">
    <h3>تأكيد إتمام الفاتورة</h3>
    <div id="confirmClientPreview"></div>
    <div id="confirmItemsPreview" class="modal-preview"></div>
    <div class="modal-footer">
      <div>
        <button id="confirmCancel" type="button" class="btn ghost">إلغاء</button>
        <button id="confirmSend" type="button" class="btn success">تأكيد وإرسال</button>
      </div>
      <div><strong>الإجمالي:</strong> <span id="confirm_total_before">0.00</span></div>
    </div>
  </div>
</div>


<script>
document.addEventListener('DOMContentLoaded', function(){

  function escapeHtml(s){ if(!s && s!==0) return ''; return String(s).replace(/[&<>"']/g,function(m){ return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[m]; }); }
  function debounce(fn,delay){ let t; return function(){ clearTimeout(t); t=setTimeout(()=>fn.apply(this,arguments),delay); }; }

  const topMsg = document.getElementById('topMsg');
  function showTopMsg(type, text, timeout=3500){
    topMsg.className = ''; topMsg.classList.add(type === 'success' ? 'success' : 'error');
    topMsg.textContent = text; topMsg.style.display = 'block';
    if (timeout>0) setTimeout(()=>{ topMsg.style.display = 'none'; }, timeout);
  }

  const customerListEl = document.getElementById('customer_list');
  const customerSearch = document.getElementById('customer_search');
  const selectedCustomerBox = document.getElementById('selectedCustomerBox');
  const customerIdInput = document.getElementById('customer_id');
  const tempCustomerInput = document.getElementById('temp_customer_name');
  const cashBtn = document.getElementById('cashCustomerBtn');
  const modalAdd = document.getElementById('modalAddCustomer');
  const openAddBtn = document.getElementById('openAddCustomerModal');
  const closeAddBtn = document.getElementById('closeAddCust');
  const submitAddBtn = document.getElementById('submitAddCust');
  const btnPrint = document.getElementById('btnPrint');
  const clearDraftBtn = document.getElementById('clearDraft');
  const openConfirmBtn = document.getElementById('openConfirm');
  const modalConfirm = document.getElementById('modalConfirm');
  const confirmCancel = document.getElementById('confirmCancel');
  const confirmSend = document.getElementById('confirmSend');

  const itemsBody = document.getElementById('itemsTableBody');
  const productList = Array.from(document.querySelectorAll('.product-item')).map(el=>({
    id: parseInt(el.dataset.id,10), name: el.dataset.name, code: el.dataset.code,
    price: parseFloat(el.dataset.price||0), cost: parseFloat(el.dataset.cost||0), stock: parseFloat(el.dataset.stock||0), el: el
  }));

  const csrf = '<?php echo e($csrf_token); ?>';
  const CASH_CUSTOMER_ID = <?php echo json_encode(intval($CASH_CUSTOMER_ID)); ?>;
  const CASH_CUSTOMER_NAME = <?php echo json_encode($CASH_CUSTOMER_NAME); ?>;

  // Helper to render stock number and out-of-stock class
  function renderProductStock(p) {
    try {
      const stockEl = p.el.querySelector('.stock-number');
      if (stockEl) stockEl.textContent = String(p.stock);
      if (p.stock <= 0) {
        p.el.classList.add('out-of-stock');
        if (!p.el.querySelector('.badge-out')) {
          const div = document.createElement('div'); div.className='badge-out'; div.textContent='نفذ'; p.el.appendChild(div);
        }
      } else {
        p.el.classList.remove('out-of-stock');
        const badge = p.el.querySelector('.badge-out'); if (badge) badge.remove();
      }
      p.el.dataset.stock = p.stock;
    } catch(e){ console.error(e); }
  }

  // initial render for stocks
  productList.forEach(p=> renderProductStock(p));

  // products add - block clicks on out-of-stock
  document.getElementById('product_list').addEventListener('click', function(e){
    const p = e.target.closest('.product-item'); if (!p) return;
    const pid = parseInt(p.dataset.id,10);
    const prod = productList.find(x=> x.id === pid);
    if (!prod) return;
    if (prod.stock <= 0) { showTopMsg('error','المنتج غير متاح (الرصيد صفر).',3000); return; }
    addOrIncreaseItem(pid);
  });

  const productSearch = document.getElementById('product_search');
  productSearch?.addEventListener('input', debounce(e=>{
    const q = e.target.value.trim().toLowerCase();
    productList.forEach(p=> { p.el.style.display = (!q || p.name.toLowerCase().includes(q) || (p.code||'').toLowerCase().includes(q)) ? '' : 'none'; });
  },150));

  function addOrIncreaseItem(pid, qty=1) {
    const p = productList.find(x=>x.id===pid);
    if (!p) return;
    if (p.stock <= 0) { showTopMsg('error','لا يمكن إضافة هذا المنتج — نفذ',3000); return; }
    const existing = Array.from(itemsBody.querySelectorAll('tr')).find(r=> parseInt(r.dataset.productId||0,10) === pid );
    if (existing) {
      const qel = existing.querySelector('.qty'); qel.value = (parseFloat(qel.value)||0) + qty; checkRowStock(existing, p.stock); recalcTotals(); return;
    }
    const noRow = document.getElementById('no-items-row'); if (noRow) noRow.remove();
    const idx = itemsBody.querySelectorAll('tr').length;
    const tr = document.createElement('tr'); tr.dataset.productId = pid;
    tr.innerHTML = `<td><input type="hidden" name="items[${idx}][product_id]" value="${pid}">${escapeHtml(p.name)}</td>
      <td class="qtycol"><input name="items[${idx}][quantity]" class="qty" type="number" step="any" min="0" value="${qty}"></td>
      <td class="unitpricecol"><input name="items[${idx}][selling_price]" class="unit-price" type="number" step="0.01" min="0" value="${p.price}"></td>
      <td><input name="items[${idx}][cost_price_per_unit]" class="cost-price" type="number" step="0.01" min="0" value="${p.cost}"></td>
      <td class="line-total">0.00</td>
      <td class="no-print"><button type="button" class="btn ghost remove-row">حذف</button></td>`;
    itemsBody.appendChild(tr);
    attachRowHandlers(tr);
    checkRowStock(tr, p.stock);
    recalcTotals();
  }

  function attachRowHandlers(tr){
    const qty = tr.querySelector('.qty'); const up = tr.querySelector('.unit-price'); const rm = tr.querySelector('.remove-row');
    if (qty) qty.addEventListener('input', function(){ const pid = parseInt(tr.dataset.productId||0,10); const p = productList.find(x=>x.id===pid); if (p) checkRowStock(tr, p.stock); recalcTotals(); });
    if (up) up.addEventListener('input', recalcTotals);
    if (rm) rm.addEventListener('click', function(){ tr.remove(); if (itemsBody.querySelectorAll('tr').length===0){ const r=document.createElement('tr'); r.id='no-items-row'; r.innerHTML='<td colspan=\"6\" class=\"no-items\">لا توجد بنود بعد — اختر منتجاً.</td>'; itemsBody.appendChild(r); } recalcTotals(); });
  }

  function checkRowStock(row, stock){
    const qty = parseFloat(row.querySelector('.qty').value)||0;
    row.classList.remove('insufficient');
    const prevWarn = row.querySelector('.stock-warn');
    if (prevWarn) prevWarn.remove();
    if (stock !== null && !isNaN(stock) && qty > stock) {
      row.classList.add('insufficient');
      const td = row.querySelector('td');
      const div = document.createElement('div'); div.className='stock-warn'; div.textContent = `المطلوب: ${qty} — المتاح: ${stock}`;
      td.appendChild(div);
    }
  }

  function recalcTotals(){
    let total = 0;
    itemsBody.querySelectorAll('tr').forEach(r=>{
      if (r.id==='no-items-row') return;
      const q = parseFloat(r.querySelector('.qty').value)||0; const p = parseFloat(r.querySelector('.unit-price').value)||0;
      const line = q*p; r.querySelector('.line-total').textContent = line.toFixed(2); total += line;
    });
    document.getElementById('total_before').textContent = total.toFixed(2);
  }

  itemsBody.querySelectorAll('tr').forEach(tr=>{ if (tr.id!=='no-items-row') attachRowHandlers(tr); });
  recalcTotals();

  // Customers
  async function loadCustomers(q=''){
    customerListEl.innerHTML = '<div class="small-muted">تحميل...</div>';
    try {
      const params = new URLSearchParams({action:'search_customers', q});
      // Using location.pathname sends request to the same script file (path only), which is acceptable for this in-page AJAX.
      const res = await fetch(location.pathname + '?' + params.toString());
      const data = await res.json();
      if (!data.ok){ customerListEl.innerHTML = '<div class="small-muted">خطأ في التحميل</div>'; return; }
      if (!data.results.length){ customerListEl.innerHTML = '<div class="small-muted">لا يوجد عملاء</div>'; return; }
      customerListEl.innerHTML = '';
      data.results.forEach(c=> customerListEl.appendChild(buildCustomerElement(c)));
    } catch(err) {
      customerListEl.innerHTML = '<div class="small-muted">خطأ في التحميل</div>';
      console.error(err);
    }
  }

  function buildCustomerElement(c){
    const el = document.createElement('div'); el.className = 'customer-item'; el.dataset.cid = c.id;
    el.innerHTML = `<div><div style="font-weight:700">${escapeHtml(c.name)}</div><div class="small-muted">${escapeHtml(c.mobile)} — ${escapeHtml(c.city)}</div></div>
                    <div style="min-width:80px"><button type="button" class="btn ghost choose-cust">اختر</button></div>`;
    el.querySelector('.choose-cust').addEventListener('click', function(e){
      e.preventDefault();
      markCustomerSelected(el, c);
      if (cashBtn) cashBtn.classList.remove('selected');
    });
    el.addEventListener('dblclick', ()=> el.querySelector('.choose-cust').click() );
    return el;
  }

  function markCustomerSelected(el, customerObj){
    document.querySelectorAll('#customer_list .customer-item').forEach(ci=>{ ci.classList.remove('selected'); ci.classList.remove('disabled'); });
    try { if (customerListEl && el.parentNode === customerListEl) customerListEl.insertBefore(el, customerListEl.firstChild); } catch(e){}
    el.classList.add('selected');
    document.querySelectorAll('#customer_list .customer-item').forEach(ci=>{ if (ci !== el) ci.classList.add('disabled'); });
    customerIdInput.value = customerObj.id; tempCustomerInput.value = '';
    selectedCustomerBox.innerHTML =
      `
      <div class="customer-selected">
  <div class="cust-line name">
    <span class="label">👤 العميل:</span>
    <strong>${escapeHtml(customerObj.name)}</strong>
  </div>
  <div class="cust-line phone">
    <span class="label">📞 الموبايل:</span>
    <span>${escapeHtml(customerObj.mobile)}</span>
  </div>
  <div class="cust-line small-muted city">
    <span class="label ">🏙️ المدينة:</span>
    <span>${escapeHtml(customerObj.city)}</span>
  </div>
  <div class="cust-line small-muted adress">
    <span class="label">📍 العنوان:</span>
    <span>${escapeHtml(customerObj.address || '')}</span>
  </div>
  <div class="cust-actions">
    <button id="btnUnselectCustomer" type="button" class="btn ghost">✖ إلغاء اختيار العميل</button>
  </div>
</div>
`;
    document.getElementById('btnUnselectCustomer').addEventListener('click', function(){
      document.querySelectorAll('#customer_list .customer-item').forEach(ci=>{ ci.classList.remove('selected'); ci.classList.remove('disabled'); });
      customerIdInput.value = ''; tempCustomerInput.value = '';
      selectedCustomerBox.innerHTML = '<div>لا يوجد عميل محدد.</div>';
      if (cashBtn) cashBtn.classList.remove('selected');
    });
  }

  customerSearch?.addEventListener('input', debounce(e=> loadCustomers(e.target.value.trim()),200));
  loadCustomers('');

  // Cash fixed button (no prompt)
  if (cashBtn) {
    cashBtn.addEventListener('click', function(){
      if (!CASH_CUSTOMER_ID) { showTopMsg('error','معرّف العميل النقدي غير متوفر', 5000); return; }
      customerIdInput.value = CASH_CUSTOMER_ID;
      tempCustomerInput.value = '';
      document.querySelectorAll('#customer_list .customer-item').forEach(ci=> ci.classList.add('disabled'));
      selectedCustomerBox.innerHTML = `<div>العميل الحالي: <strong>${escapeHtml(CASH_CUSTOMER_NAME || 'عميل نقدي')}</strong></div>
        <div style="margin-top:6px"><button id="btnUnselectCustomer" type="button" class="btn ghost">إلغاء اختيار العميل</button></div>`;
      cashBtn.classList.add('selected');
      document.getElementById('btnUnselectCustomer').addEventListener('click', function(){
        document.querySelectorAll('#customer_list .customer-item').forEach(ci=> ci.classList.remove('disabled'));
        customerIdInput.value = ''; tempCustomerInput.value = '';
        selectedCustomerBox.innerHTML = '<div>لا يوجد عميل محدد.</div>';
        cashBtn.classList.remove('selected');
      });
    });
  }

  // Add customer modal behavior (improved and clear inputs after success)
  if (openAddBtn && modalAdd && closeAddBtn && submitAddBtn) {
    openAddBtn.addEventListener('click', ()=> {
      modalAdd.classList.add('open'); modalAdd.style.display='flex'; modalAdd.setAttribute('aria-hidden','false');
      document.getElementById('addCustMsg').innerHTML = '';
    });

    closeAddBtn.addEventListener('click', ()=> {
      modalAdd.classList.remove('open'); modalAdd.style.display='none'; modalAdd.setAttribute('aria-hidden','true');
    });

    submitAddBtn.addEventListener('click', async function(){
      const name    = document.getElementById('new_name').value.trim();
      const mobile  = document.getElementById('new_mobile').value.trim();
      const city    = document.getElementById('new_city').value.trim();
      const address = document.getElementById('new_address').value.trim();
      const msg     = document.getElementById('addCustMsg');
      msg.innerHTML = '';

      if (!name || name.length < 3) { msg.innerHTML = '<div style="color:#a00">❌ الاسم مطلوب ويجب أن يكون 3 حروف على الأقل</div>'; return; }
      if (!/^(01[0-9]{9})$/.test(mobile)) { msg.innerHTML = '<div style="color:#a00">❌ الموبايل يجب أن يبدأ بـ 01 ويتكون من 11 رقماً</div>'; return; }
      if (!city) { msg.innerHTML = '<div style="color:#a00">❌ المدينة مطلوبة</div>'; return; }
      if (!address || address.length < 5) { msg.innerHTML = '<div style="color:#a00">❌ العنوان يجب أن يكون أوضح (5 حروف على الأقل)</div>'; return; }

      const form = new FormData();
      form.append('action','create_customer');
      form.append('csrf_token', csrf);
      form.append('name', name);
      form.append('mobile', mobile);
      form.append('city', city);
      form.append('address', address);

      try {
        const res = await fetch(location.pathname, { method:'POST', body: form });
        const data = await res.json();
        if (!data.ok) { msg.innerHTML = '<div style="color:#a00">'+escapeHtml(data.msg || '⚠ حدث خطأ في الحفظ')+'</div>'; return; }

        showTopMsg("success","تم اضافه عميل بنجاح");
        // close modal
        modalAdd.classList.remove('open'); modalAdd.style.display='none'; modalAdd.setAttribute('aria-hidden','true');

        // clear inputs so next time modal is empty
        document.getElementById('new_name').value = '';
        document.getElementById('new_mobile').value = '';
        document.getElementById('new_city').value = '';
        document.getElementById('new_address').value = '';

        await loadCustomers('');

        // select the created customer
        setTimeout(()=> {
          const createdEl = Array.from(document.querySelectorAll('#customer_list .customer-item'))
            .find(ci=> ci.dataset.cid == data.customer.id);
          if (createdEl) createdEl.querySelector('.choose-cust').click();
        }, 200);

      } catch(err) {
        msg.innerHTML = '<div style="color:#a00">🚫 خطأ في الاتصال بالخادم</div>';
        console.error(err);
      }
    });
  }

  // close modals on backdrop click
  document.querySelectorAll('.modal-backdrop').forEach(mb=> mb.addEventListener('click', function(ev){ if (ev.target === mb) { mb.classList.remove('open'); mb.style.display='none'; mb.setAttribute('aria-hidden','true'); } }));

  // clear items
  clearDraftBtn?.addEventListener('click', function(){
    if (!confirm('هل تريد تفريغ كل البنود؟')) return;
    document.querySelectorAll('#itemsTableBody tr').forEach(tr=>tr.remove());
    const r = document.createElement('tr'); r.id='no-items-row'; r.innerHTML = '<td colspan="6" class="no-items">لا توجد بنود بعد — اختر منتجاً.</td>'; itemsBody.appendChild(r);
    recalcTotals();
  });

  // Confirm modal open (existing)
  openConfirmBtn?.addEventListener('click', function () {
    const custId = parseInt(customerIdInput.value || 0, 10);
    if (!custId) { showTopMsg('error', 'يجب اختيار عميل قبل الإتمام', 3500); return; }
    const rows = Array.from(itemsBody.querySelectorAll('tr')).filter(r => r.id !== 'no-items-row');
    if (rows.length === 0) { showTopMsg('error', 'لا توجد بنود لإتمام الفاتورة', 3500); return; }

    // preview client
    let clientHtml = `<div class="cust-preview">`;
    if (custId === CASH_CUSTOMER_ID) {
      clientHtml += `<p><strong>👤 العميل:</strong> ${escapeHtml(CASH_CUSTOMER_NAME || 'عميل نقدي')}</p>`;
    } else {
      const selected = Array.from(document.querySelectorAll('#customer_list .customer-item')).find(ci => parseInt(ci.dataset.cid || 0, 10) === custId);
      if (selected) {
        const name = selected.querySelector('div > div').innerText;
        clientHtml += `<p><strong>👤 العميل:</strong> ${escapeHtml(name)}</p>`;
      } else {
        clientHtml += `<p><strong>👤 العميل:</strong> معرّف #${escapeHtml(String(custId))}</p>`;
      }
    }
    clientHtml += `</div>`;
    document.getElementById('confirmClientPreview').innerHTML = clientHtml;

    // preview items
    const preview = document.getElementById('confirmItemsPreview'); preview.innerHTML = '';
    rows.forEach(r => {
      const name = r.querySelector('td').innerText.trim();
      const qty = r.querySelector('.qty').value;
      const up = r.querySelector('.unit-price').value;
      const line = parseFloat(qty) * parseFloat(up);

      const div = document.createElement('div');
      div.className = 'modal-item';
      div.innerHTML = `
        <div class="item-info">
          <strong>${escapeHtml(name)}</strong>
          <div class="small-muted">الكمية: ${qty} — سعر البيع: ${parseFloat(up).toFixed(2)}</div>
        </div>
        <div class="item-price">${line.toFixed(2)}</div>
      `;
      preview.appendChild(div);
    });

    document.getElementById('confirm_total_before').textContent = document.getElementById('total_before').textContent;

    modalConfirm.classList.add('open'); modalConfirm.style.display='flex'; modalConfirm.setAttribute('aria-hidden','false');
  });

  confirmCancel?.addEventListener('click', function(){ modalConfirm.classList.remove('open'); modalConfirm.style.display='none'; modalConfirm.setAttribute('aria-hidden','true'); });

  // Confirm send via AJAX
  confirmSend?.addEventListener('click', async function(){
    const formEl = document.getElementById('invoiceForm');
    // build an array of used products so we can update local stock AFTER success
    const usedProducts = [];
    Array.from(itemsBody.querySelectorAll('tr')).forEach(r=>{
      if (r.id==='no-items-row') return;
      const pid = parseInt(r.dataset.productId||0,10);
      const qty = parseFloat(r.querySelector('.qty')?.value||0);
      if (pid && qty) usedProducts.push({product_id: pid, quantity: qty});
    });

    const fd = new FormData(formEl);
    fd.set('confirm_complete','1');
    try {
      const res = await fetch(location.pathname, {
        method: 'POST',
        headers: {'X-Requested-With':'XMLHttpRequest'},
        body: fd
      });
      const text = await res.text();
      let data = null;
      try { data = JSON.parse(text); } catch (err) {
        const snippet = text.length > 800 ? text.substring(0,800) + '...' : text;
        showTopMsg('error', 'استجابة غير متوقعة من الخادم — راجع سجلات PHP.', 8000);
        console.error('Unexpected response (not JSON):', snippet);
        return;
      }
      if (data && data.ok) {
        modalConfirm.classList.remove('open'); modalConfirm.style.display='none'; modalConfirm.setAttribute('aria-hidden','true');
        showTopMsg('success', data.message || 'تم إتمام الفاتورة', 4000);
        // update local stocks (decrement)
        try {
          usedProducts.forEach(u=>{
            const p = productList.find(x=> x.id === u.product_id);
            if (p) {
              p.stock = Math.max(0, (parseFloat(p.stock)||0) - parseFloat(u.quantity||0));
              renderProductStock(p);
            }
          });
        } catch(e){ console.error('update local stocks failed', e); }

        // clear UI
        document.querySelectorAll('#itemsTableBody tr').forEach(tr=>tr.remove());
        const r = document.createElement('tr'); r.id='no-items-row'; r.innerHTML = '<td colspan="6" class="no-items">لا توجد بنود بعد — اختر منتجاً.</td>'; itemsBody.appendChild(r);
        customerIdInput.value = '';
        tempCustomerInput.value = '';
        selectedCustomerBox.innerHTML = '<div>لا يوجد عميل محدد.</div>';
        document.getElementById('total_before').textContent = '0.00';
        document.getElementById('notes').value = '';
        document.querySelectorAll('#customer_list .customer-item').forEach(ci=>ci.classList.remove('disabled','selected'));
        document.getElementById('cashCustomerBtn')?.classList.remove('selected');

        // update next invoice id shown
        try {
          const nextEl = document.getElementById('nextInvoiceId');
          if (nextEl && data.invoice_id) {
            const newNext = parseInt(data.invoice_id,10) + 1;
            nextEl.textContent = '#' + newNext;
          }
        } catch(e){}
      } else {
        const errs = (data && data.errors) ? data.errors.join(' | ') : (data && data.msg ? data.msg : 'حصل خطأ');
        showTopMsg('error', 'فشل إتمام الفاتورة: ' + errs, 6000);
      }
    } catch (err) {
      showTopMsg('error', 'خطأ في الاتصال: ' + err.message, 5000);
    }
  });

  // Print (kept same behavior)
// طباعة — استبدل الـ handler القديم بهذا
btnPrint?.addEventListener('click', function(){
  const invoiceTitle = '<?php echo $invoice ? "فاتورة #".intval($invoice['id']) : "فاتورة "; ?>';
  const custBox = document.getElementById('selectedCustomerBox');
  let custHtml = '<div>عميل نقدي</div>'; // default fallback

  // 1) إذا كان العميل هو العميل النقدي الثابت (زر "نقدي (ثابت)" وضع customer_id)
  const custIdVal = parseInt(customerIdInput.value || 0, 10);
  if (custIdVal === CASH_CUSTOMER_ID) {
    custHtml = `<div><div><strong>${escapeHtml(CASH_CUSTOMER_NAME || 'عميل نقدي')}</strong></div></div>`;
  } else if (custBox) {
    // 2) حاول استخراج الاسم/الهاتف/العنوان بأكثر من طريقة (مرونة مع تغيّر HTML)
    let name = (custBox.querySelector('.name')?.innerText || '').trim();
    let phone = (custBox.querySelector('.phone')?.innerText || '').trim();
    let address = (custBox.querySelector('.adress')?.innerText || '').trim(); // لاحظ: 'adress' مستخدمة في كودك

    // fallback: إذا لم نجد name باستخدام الكلاسات، جرب أول <strong> داخل الصندوق
    if (!name) name = (custBox.querySelector('strong')?.innerText || '').trim();

    // fallback: حاول إيجاد رقم هاتف داخل نص الـ box (regex بسيط)
    if (!phone) {
      const txt = custBox.innerText || '';
      const m = txt.match(/(01[0-9]{9}|\+?\d{7,15})/);
      if (m) phone = m[0];
    }

    // fallback: إذا لم نوجد أي من الحقول، تحقق من نص "العميل الحالي: ... " (مثلاً عند cashBtn)
    if (!name && /العميل الحالي[:\s]/.test(custBox.innerText)) {
      // خذ النص بعد "العميل الحالي:"
      const after = custBox.innerText.split('العميل الحالي:')[1] || '';
      const parts = after.split('—').map(s=>s.trim()).filter(Boolean);
      if (parts.length) name = parts[0];
      if (!phone && parts.length > 1) phone = parts[1];
    }

    // بناء HTML للعميل إذا وجدنا بيانات فعلية
    if (name || phone || address) {
      custHtml = `<div>
                    <div><strong>${escapeHtml(name)}</strong> ${phone ? `<br/>${escapeHtml(phone)}` : ''}</div>
                    ${address ? `<div>${escapeHtml(address)}</div>` : ''}
                  </div>`;
    } else {
      // لا بيانات => نستخدم العميل النقدي كـ fallback
      custHtml = '<div>عميل نقدي</div>';
    }
  }

  // بناء جدول البنود كما كان
  let itemsHtml = '<table><thead><tr><th>المنتج</th><th>الكمية</th><th>سعر البيع</th><th>الإجمالي</th></tr></thead><tbody>';
  document.querySelectorAll('#itemsTableBody tr').forEach(tr=>{
    if (tr.id==='no-items-row') return;
    const name = tr.querySelector('td')?.innerText.trim() || '';
    const qty = tr.querySelector('.qty') ? tr.querySelector('.qty').value : '';
    const up = tr.querySelector('.unit-price') ? tr.querySelector('.unit-price').value : '';
    const line = tr.querySelector('.line-total') ? tr.querySelector('.line-total').innerText : '';
    itemsHtml += `<tr><td>${escapeHtml(name)}</td><td>${escapeHtml(qty)}</td><td>${escapeHtml(parseFloat(up||0).toFixed(2))}</td><td>${escapeHtml(line)}</td></tr>`;
  });
  itemsHtml += '</tbody></table>';

  const totBefore = document.getElementById('total_before')?.textContent || '0.00';
  const notes = document.getElementById('notes') ? escapeHtml(document.getElementById('notes').value) : '';

  const html = `<!doctype html><html dir="rtl" lang="ar"><head><meta charset="utf-8"><title>طباعة الفاتورة</title>
    <style>body{font-family:Arial;padding:12px} table{width:100%;border-collapse:collapse} th,td{padding:6px;border:1px solid #ccc;text-align:right} h2{margin-top:0}</style></head><body>
    <h2>${invoiceTitle}</h2><div>${custHtml}</div><hr>${itemsHtml}<hr>
    <div><strong>الإجمالي:</strong> ${totBefore} ج.م</div>
    </body></html>`;

  const iframe = document.createElement('iframe');
  iframe.style.position = 'fixed';
  iframe.style.right = '0';
  iframe.style.bottom = '0';
  iframe.style.width = '0';
  iframe.style.height = '0';
  iframe.style.border = '0';
  document.body.appendChild(iframe);
  const doc = iframe.contentWindow.document;
  doc.open(); doc.write(html); doc.close();
  iframe.onload = () => { try { iframe.contentWindow.focus(); iframe.contentWindow.print(); } catch(e){ console.error(e); } setTimeout(()=> document.body.removeChild(iframe), 1000); };
});

  // initial stock check for rows loaded from server
  document.querySelectorAll('#itemsTableBody tr').forEach(tr=>{
    if (tr.id === 'no-items-row') return;
    const pid = parseInt(tr.dataset.productId||0,10);
    const p = productList.find(x=>x.id===pid);
    if (p) checkRowStock(tr, p.stock);
  });

}); // DOMContentLoaded
</script>

<?php
require_once BASE_DIR . 'partials/footer.php';
ob_end_flush();
?>
