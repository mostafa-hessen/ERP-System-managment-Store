<?php
// create_invoice.php  (مُصحَّح)
// يعتمد على: config.php, partials/session_admin.php
// IMPORTANT: AJAX endpoints are handled BEFORE including header/footer to ensure pure JSON responses.

// ========== BOOT (config + session) ==========
$page_title = "إنشاء فاتورة بيع";
$class_dashboard = "active";
require_once dirname(__DIR__) . '/config.php';
require_once BASE_DIR . 'partials/session_admin.php'; // يجب أن يبدأ الجلسة ويعرّف user_id إن لزم

// ensure PDO exists (fallback)
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

/* ---------- helper to output JSON and exit ---------- */
function jsonOut($payload) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/* =========================
   AJAX handling - MUST run BEFORE any HTML output (header/footer)
   ========================= */
if (isset($_REQUEST['action'])) {
    $action = $_REQUEST['action'];

    // 0) sync_consumed
    if ($action === 'sync_consumed') {
        try {
            $stmt = $pdo->prepare("UPDATE batches SET status = 'consumed', updated_at = NOW() WHERE status = 'active' AND COALESCE(remaining,0) <= 0");
            $stmt->execute();
            jsonOut(['ok'=>true,'updated'=>$stmt->rowCount()]);
        } catch (Exception $e) {
            jsonOut(['ok'=>false,'error'=>'فشل تحديث حالات الدفعات.']);
        }
    }

    // 1) products list (with aggregates)
    if ($action === 'products') {
        $q = trim($_GET['q'] ?? '');
        $params = [];
        $where = '';
        if ($q !== '') {
            $where = " WHERE (p.name LIKE ? OR p.product_code LIKE ? OR p.id = ?)";
            $params[] = "%$q%";
            $params[] = "%$q%";
            // if $q numeric, also match id
            if (is_numeric($q)) $params[] = (int)$q; else $params[] = 0;
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
            jsonOut(['ok'=>true,'products'=>$rows]);
        } catch (Exception $e) {
            jsonOut(['ok'=>false,'error'=>'فشل جلب المنتجات.']);
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
            jsonOut(['ok'=>true,'batches'=>$batches,'product'=>$prod]);
        } catch (Exception $e) {
            jsonOut(['ok'=>false,'error'=>'فشل جلب الدفعات.']);
        }
    }

    // 3) customers list/search
    if ($action === 'customers') {
        $q = trim($_GET['q'] ?? '');
        try {
            if ($q === '') {
                $stmt = $pdo->query("SELECT id,name,mobile FROM customers ORDER BY name LIMIT 50");
                $rows = $stmt->fetchAll();
            } else {
                $stmt = $pdo->prepare("SELECT id,name,mobile FROM customers WHERE name LIKE ? OR mobile LIKE ? ORDER BY name LIMIT 50");
                $like = "%$q%";
                $stmt->execute([$like,$like]);
                $rows = $stmt->fetchAll();
            }
            jsonOut(['ok'=>true,'customers'=>$rows]);
        } catch (Exception $e) {
            jsonOut(['ok'=>false,'error'=>'فشل جلب العملاء.']);
        }
    }

    // 4) save_invoice (POST) - creates invoice, invoice_out_items, sale_item_allocations; FIFO allocations
    if ($action === 'save_invoice' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf_token'] ?? '';
        if (!hash_equals($_SESSION['csrf_token'], (string)$token)) {
            jsonOut(['ok'=>false,'error'=>'رمز التحقق (CSRF) غير صالح. أعد تحميل الصفحة وحاول مجدداً.']);
        }
        $customer_id = (int)($_POST['customer_id'] ?? 0);
        $status = ($_POST['status'] ?? 'pending') === 'paid' ? 'paid' : 'pending';
        $items_json = $_POST['items'] ?? '';
        $notes = trim($_POST['notes'] ?? '');
        $created_by = $_SESSION['user_id'] ?? null;

        if ($customer_id <= 0) jsonOut(['ok'=>false,'error'=>'الرجاء اختيار عميل.']);
        if (empty($items_json)) jsonOut(['ok'=>false,'error'=>'لا توجد بنود لإضافة الفاتورة.']);

        $items = json_decode($items_json, true);
        if (!is_array($items) || count($items) === 0) jsonOut(['ok'=>false,'error'=>'بنود الفاتورة غير صالحة.']);

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
                    jsonOut(['ok'=>false,'error'=>"بند غير صالح (معرف/كمية)."]);
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
                    $allocations[] = ['batch_id'=>(int)$b['id'],'take'=>$take,'unit_cost'=>(float)$b['unit_cost']];
                    $need -= $take;
                }
                if ($need > 0.00001) {
                    $pdo->rollBack();
                    jsonOut(['ok'=>false,'error'=>"الرصيد غير كافٍ للمنتج (ID: {$product_id})."]);
                }
                $itemTotalCost = 0.0;
                foreach ($allocations as $a) $itemTotalCost += $a['take'] * $a['unit_cost'];
                $cost_price_per_unit = ($qty>0) ? ($itemTotalCost / $qty) : 0.0;
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
            jsonOut(['ok'=>true,'msg'=>'تم إنشاء الفاتورة بنجاح.','invoice_id'=>$invoice_id,'total_revenue'=>round($totalRevenue,2),'total_cogs'=>round($totalCOGS,2)]);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $msg = $e->getMessage();
            if (strpos($msg, 'Duplicate entry') !== false) {
                jsonOut(['ok'=>false,'error'=>'قيمة مكررة: تحقق من الكود أو الحقول المطلوبة.']);
            }
            jsonOut(['ok'=>false,'error'=>'حدث خطأ أثناء حفظ الفاتورة.']);
        }
    }

    // unknown action
    jsonOut(['ok'=>false,'error'=>'action غير معروف']);
} // end AJAX handler

/* =========================
   If we reach here -> render HTML UI (no action param)
   Now we can include header/footer safely.
   ========================= */
require_once BASE_DIR . 'partials/header.php';
/* sidebar may be included by your template later; include if necessary */
require_once BASE_DIR . 'partials/sidebar.php';
?>

<!-- =========================
     HTML UI
     ========================= -->
<style>
:root{
  --primary:#0b84ff; --accent:#7c3aed; --teal:#10b981; --amber:#f59e0b; --rose:#ef4444;
  --bg:#f6f8fc; --surface:#fff; --text:#0b1220; --muted:#64748b; --border:rgba(2,6,23,0.06);
}
[data-theme="dark"] {
  --bg:#0b1220; --surface:#0f1626; --text:#e6eef8; --muted:#94a3b8; --border:rgba(148,163,184,0.12);
}
body { background:var(--bg); color:var(--text); }
.container-inv { padding:18px; font-family:Inter, 'Noto Naskh Arabic', Tahoma, Arial; }
.grid { display:grid; grid-template-columns:360px 1fr 320px; gap:16px; height: calc(100vh - 160px); }
.panel { background:var(--surface); padding:12px; border-radius:12px; box-shadow:0 10px 24px rgba(2,6,23,0.06); overflow:auto; }
.prod-card { display:flex; justify-content:space-between; gap:10px; padding:10px; border:1px solid var(--border); border-radius:10px; margin-bottom:10px; background:var(--surface); }
.badge { padding:6px 10px; border-radius:999px; font-weight:700; }
.badge.warn { background:rgba(250,204,21,0.12); color:#7a4f00; }
.btn{ padding:8px 10px; border-radius:8px; border:none; cursor:pointer; }
.btn.primary{ background:linear-gradient(90deg,var(--primary),var(--accent)); color:#fff; }
.btn.ghost{ background:transparent; border:1px solid var(--border); color:var(--text); }
.table { width:100%; border-collapse:collapse; }
.table th, .table td { padding:8px; border-bottom:1px solid var(--border); text-align:center; }
.modal-backdrop{ position:fixed; inset:0; display:none; align-items:center; justify-content:center; background: rgba(2,6,23,0.55); z-index:1200; }
.modal { width:100%; max-width:1000px; background:var(--surface); padding:16px; border-radius:12px; max-height:86vh; overflow:auto; }
.toast-wrap{ position:fixed; top:18px; right:18px; z-index:2000; display:flex; flex-direction:column; gap:8px; }
.toast{ padding:10px 14px; border-radius:8px; color:#fff; box-shadow:0 8px 20px rgba(2,6,23,0.12);}
.toast.success{ background: linear-gradient(90deg,#10b981,#06b6d4); }
.toast.error{ background: linear-gradient(90deg,#ef4444,#f97316); }
@media (max-width:1100px) { .grid{ grid-template-columns: 1fr; height:auto } }
</style>

<div class="container-inv">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
    <div style="font-weight:900;font-size:20px">إنشاء فاتورة — FIFO</div>
    <div style="display:flex;gap:8px;align-items:center">
      <button id="toggleThemeBtn" class="btn ghost">تبديل الثيم</button>
      <button id="syncBtn" class="btn ghost">مزامنة الدفعات المستهلكة</button>
    </div>
  </div>

  <div class="grid" role="main">
    <!-- Products -->
    <div class="panel" aria-label="Products">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
        <div style="font-weight:800">المنتجات</div>
        <input id="productSearchInput" placeholder="بحث باسم أو كود أو id..." style="padding:6px;border-radius:8px;border:1px solid var(--border);min-width:160px">
      </div>
      <div id="productsList"></div>
    </div>

    <!-- Invoice -->
    <div class="panel" aria-label="Invoice">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
        <div>
          <label><input type="radio" name="invoice_state" value="pending" checked> مؤجل</label>
          <label style="margin-left:10px"><input type="radio" name="invoice_state" value="paid"> تم الدفع</label>
        </div>
        <strong>فاتورة جديدة</strong>
      </div>

      <div style="overflow:auto;max-height:56vh">
        <table class="table" id="invoiceTable" aria-label="Invoice items">
          <thead>
            <tr><th>المنتج</th><th>كمية</th><th>سعر بيع</th><th>تفاصيل FIFO</th><th>الإجمالي</th><th>حذف</th></tr>
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

    <!-- Customers -->
    <div class="panel" aria-label="Customers">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
        <strong>العملاء</strong>
        <input id="customerSearchInput" placeholder="بحث عميل..." style="padding:6px;border-radius:8px;border:1px solid var(--border);min-width:140px">
      </div>
      <div id="customersList" style="margin-bottom:8px"></div>
      <div id="selectedCustomer"><div class="small">لم يتم اختيار عميل</div></div>
    </div>
  </div>
</div>

<!-- Batches modal -->
<div id="batchesModal" class="modal-backdrop">
  <div class="mymodal">
    <div style="display:flex;justify-content:space-between;align-items:center">
      <div><strong id="batchesTitle">دفعات</strong><div class="small" id="batchesInfo"></div></div>
      <div><button id="closeBatchesBtn" class="btn ghost">إغلاق</button></div>
    </div>
    <div id="batchesTable" style="margin-top:10px"></div>
  </div>
</div>

<!-- Batch detail modal -->
<div id="batchDetailModal" class="modal-backdrop">
  <div class="mymodal">
    <div style="display:flex;justify-content:space-between;align-items:center">
      <div><strong id="batchTitle">تفاصيل الدفعة</strong></div>
      <div><button id="closeBatchDetailBtn" class="btn ghost">إغلاق</button></div>
    </div>
    <div id="batchDetailBody" style="margin-top:10px"></div>
  </div>
</div>

<!-- Toasts -->
<div class="toast-wrap" id="toastWrap"></div>

<script>
/* ========== DOM ready ========== */
document.addEventListener('DOMContentLoaded', function() {
  // helpers
  const $ = id => document.getElementById(id);
  function showToast(msg, type='success', timeout=3500){
    const wrap = $('toastWrap');
    if (!wrap) return console.warn('no toastWrap');
    const el = document.createElement('div');
    el.className = 'toast ' + (type==='error'?'error':'success');
    el.textContent = msg;
    wrap.appendChild(el);
    setTimeout(()=> { el.style.opacity = 0; setTimeout(()=> el.remove(), 300); }, timeout);
  }
  function fmt(n){ return Number(n||0).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}); }
  function esc(s){ return (s==null)?'':String(s).replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m])); }
  function debounce(fn, t=250){ let to; return function(...a){ clearTimeout(to); to=setTimeout(()=>fn.apply(this,a), t); } }

  // fetchJson safer: try response.json(), but handle non-json responses
  async function fetchJson(url, opts){
    const res = await fetch(url, opts);
    const text = await res.text();
    try { return JSON.parse(text); } catch(e) {
      console.error('Invalid JSON response:', text);
      throw new Error('Invalid JSON from server');
    }
  }

  // state
  let products = [], invoiceItems = [], customers = [], selectedCustomer = null;

  /* ---------- load products ---------- */
  async function loadProducts(q='') {
    try {
      const json = await fetchJson(location.pathname + '?action=products' + (q?('&q='+encodeURIComponent(q)):''), { credentials: 'same-origin' });
      if (!json.ok) { showToast(json.error || 'فشل جلب المنتجات', 'error'); return; }
      products = json.products || [];
      renderProducts();
      updateSummaryFromProducts();
    } catch (e) {
      console.error(e);
      showToast('تعذر الاتصال بالخادم أو استجابة غير صحيحة', 'error');
    }
  }

  function renderProducts() {
    const wrap = $('productsList'); wrap.innerHTML = '';
    products.forEach(p=>{
      const rem = parseFloat(p.remaining_active||0);
      const consumed = rem <= 0;
      const div = document.createElement('div');
      div.className = 'prod-card';
      div.innerHTML = `<div>
          <div style="font-weight:800">${esc(p.name)}</div>
          <div class="small">كود • #${esc(p.product_code)} • ID:${p.id}</div>
          <div class="small">رصيد دخل: ${fmt(p.current_stock)}</div>
          <div class="small">متبقي (Active): ${fmt(rem)}</div>
          <div class="small">آخر شراء: ${esc(p.last_batch_date||'-')} • ${fmt(p.last_purchase_price||0)}</div>
        </div>
        <div style="display:flex;flex-direction:column;gap:6px;align-items:flex-end">
          ${consumed ? '<div class="badge warn">مستهلك</div>' : `<button class="btn primary add-btn" data-id="${p.id}" data-name="${esc(p.name)}" data-sale="${p.last_sale_price||0}">أضف</button>`}
          <button class="btn ghost batches-btn" data-id="${p.id}">دفعات</button>
        </div>`;
      wrap.appendChild(div);
    });
    // attach listeners
    document.querySelectorAll('.add-btn').forEach(b=> b.addEventListener('click', ()=> {
      const id = b.dataset.id, name = b.dataset.name, sale = parseFloat(b.dataset.sale||0);
      addInvoiceItem({product_id:id, product_name:name, qty:1, selling_price:sale});
    }));
    document.querySelectorAll('.batches-btn').forEach(b=> b.addEventListener('click', ()=> openBatchesModal(parseInt(b.dataset.id))));
  }

  // search product
  $('productSearchInput').addEventListener('input', debounce(()=> loadProducts($('productSearchInput').value.trim()), 350));

  // initial load
  (async function init(){
    try { await fetchJson(location.pathname + '?action=sync_consumed'); } catch(e){} // try sync
    loadProducts();
    loadCustomers('');
  })();

  /* ---------- invoice list rendering ---------- */
  function addInvoiceItem(item){
    const existing = invoiceItems.find(i => i.product_id == item.product_id);
    if (existing) existing.qty = Number(existing.qty) + Number(item.qty);
    else invoiceItems.push({...item});
    renderInvoice();
  }
  function renderInvoice(){
    const tbody = $('invoiceTbody'); tbody.innerHTML = '';
    invoiceItems.forEach((it, idx)=> {
      const tr = document.createElement('tr');
      tr.innerHTML = `<td style="text-align:right">${esc(it.product_name)}</td>
        <td><input type="number" class="qty" data-idx="${idx}" value="${it.qty}" step="0.0001" style="width:100px"></td>
        <td><input type="number" class="price" data-idx="${idx}" value="${Number(it.selling_price).toFixed(2)}" step="0.01" style="width:110px"></td>
        <td><button class="btn ghost fifo-btn" data-idx="${idx}">تفاصيل FIFO</button></td>
        <td class="line-total">${fmt(it.qty * it.selling_price)}</td>
        <td><button class="btn ghost remove-btn" data-idx="${idx}">حذف</button></td>`;
      tbody.appendChild(tr);
    });
    // event binding
    document.querySelectorAll('.qty').forEach(el=> el.addEventListener('input', e=>{
      const idx = e.target.dataset.idx; invoiceItems[idx].qty = parseFloat(e.target.value || 0); renderInvoice();
    }));
    document.querySelectorAll('.price').forEach(el=> el.addEventListener('input', e=>{
      const idx = e.target.dataset.idx; invoiceItems[idx].selling_price = parseFloat(e.target.value || 0); renderInvoice();
    }));
    document.querySelectorAll('.remove-btn').forEach(b=> b.addEventListener('click', ()=> {
      const idx = b.dataset.idx; invoiceItems.splice(idx,1); renderInvoice();
    }));
    document.querySelectorAll('.fifo-btn').forEach(b=> b.addEventListener('click', ()=> openFifoPreview(parseInt(b.dataset.idx))));

    // totals
    let sumQ = 0, sumS = 0;
    invoiceItems.forEach(it=> { sumQ += Number(it.qty||0); sumS += Number(it.qty||0) * Number(it.selling_price||0); });
    $('sumQty').textContent = sumQ;
    $('sumSell').textContent = fmt(sumS);
  }

  // clear
  $('clearBtn').addEventListener('click', ()=> {
    if (!confirm('هل تريد تفريغ بنود الفاتورة؟')) return;
    invoiceItems = []; renderInvoice();
  });

  // preview (uses batch detail modal)
  $('previewBtn').addEventListener('click', ()=> {
    if (invoiceItems.length === 0) return showToast('لا توجد بنود للمعاينة','error');
    let html = `<h3>معاينة الفاتورة</h3><table style="width:100%;border-collapse:collapse"><thead><tr><th>المنتج</th><th>الكمية</th><th>سعر البيع</th><th>الإجمالي</th></tr></thead><tbody>`;
    let total = 0;
    invoiceItems.forEach(it=> { const line = (it.qty||0)*(it.selling_price||0); total += line; html += `<tr><td>${esc(it.product_name)}</td><td>${fmt(it.qty)}</td><td>${fmt(it.selling_price)}</td><td>${fmt(line)}</td></tr>` });
    html += `</tbody></table><div style="margin-top:8px"><strong>الإجمالي: ${fmt(total)}</strong></div>`;
    $('batchDetailBody').innerHTML = html;
    $('batchTitle').textContent = 'معاينة الفاتورة';
    $('batchDetailModal').style.display = 'flex';
  });

  // confirm: send to server
  $('confirmBtn').addEventListener('click', async ()=> {
    if (!selectedCustomer) return showToast('الرجاء اختيار عميل','error');
    if (invoiceItems.length === 0) return showToast('لا توجد بنود لحفظ الفاتورة','error');
    const payload = invoiceItems.map(it => ({ product_id: it.product_id, qty: Number(it.qty), selling_price: Number(it.selling_price) }));
    const fd = new FormData();
    fd.append('action','save_invoice');
    fd.append('csrf_token','<?php echo $csrf_token; ?>');
    fd.append('customer_id', selectedCustomer.id);
    fd.append('status', document.querySelector('input[name="invoice_state"]:checked').value);
    fd.append('notes', $('invoiceNotes').value);
    fd.append('items', JSON.stringify(payload));
    try {
      const res = await fetch(location.pathname + '?action=save_invoice', { method:'POST', body: fd, credentials: 'same-origin' });
      const txt = await res.text();
      let json;
      try { json = JSON.parse(txt); } catch (e) { console.error('Invalid response', txt); throw new Error('Invalid JSON'); }
      if (!json.ok) { showToast(json.error || 'فشل الحفظ', 'error'); return; }
      showToast(json.msg || 'تم الحفظ', 'success');
      invoiceItems = []; renderInvoice(); loadProducts();
    } catch (e) {
      console.error(e);
      showToast('خطأ في الاتصال أو استجابة غير صحيحة', 'error');
    }
  });

  /* ---------- FIFO preview for a line ---------- */
  async function openFifoPreview(idx) {
    const it = invoiceItems[idx];
    if (!it) return;
    try {
      const json = await fetchJson(location.pathname + '?action=batches&product_id=' + encodeURIComponent(it.product_id));
      if (!json.ok) return showToast(json.error || 'خطأ في جلب الدفعات', 'error');
      const batches = json.batches || [];
      let need = Number(it.qty || 0);
      let html = `<h4>تفاصيل FIFO — ${esc(it.product_name)}</h4><table style="width:100%;border-collapse:collapse"><thead><tr><th>رقم الدفعة</th><th>التاريخ</th><th>المتبقي</th><th>سعر الشراء</th><th>مأخوذ</th><th>تكلفة</th></tr></thead><tbody>`;
      let totalCost = 0;
      batches.sort((a,b)=> (a.received_at||'') > (b.received_at||'') ? 1 : -1);
      for (const b of batches) {
        if (need <= 0) break;
        if (b.status !== 'active' || (parseFloat(b.remaining||0) <= 0)) continue;
        const avail = parseFloat(b.remaining||0);
        const take = Math.min(avail, need);
        const cost = take * parseFloat(b.unit_cost||0);
        totalCost += cost;
        html += `<tr><td class="monos">${b.id}</td><td>${esc(b.received_at||b.created_at||'-')}</td><td>${fmt(b.remaining)}</td><td>${fmt(b.unit_cost)}</td><td>${fmt(take)}</td><td>${fmt(cost)}</td></tr>`;
        need -= take;
      }
      if (need > 0) html += `<tr><td colspan="6" style="color:#b91c1c">تحذير: الرصيد غير كافٍ.</td></tr>`;
      html += `</tbody></table><div style="margin-top:8px"><strong>إجمالي تكلفة البند:</strong> ${fmt(totalCost)} ج</div>`;
      $('batchDetailBody').innerHTML = html;
      $('batchTitle').textContent = 'تفاصيل FIFO';
      $('batchDetailModal').style.display = 'flex';
    } catch (e) {
      console.error(e);
      showToast('تعذر جلب الدفعات', 'error');
    }
  }

  /* ---------- batches modal (full) ---------- */
  async function openBatchesModal(productId) {
    try {
      await fetchJson(location.pathname + '?action=sync_consumed').catch(()=>{});
      const json = await fetchJson(location.pathname + '?action=batches&product_id=' + productId);
      if (!json.ok) return showToast(json.error || 'خطأ في جلب الدفعات','error');
      const p = json.product || {};
      $('batchesTitle').textContent = `دفعات — ${p.name || ''}`;
      $('batchesInfo').textContent = `${p.product_code || ''}`;
      const rows = json.batches || [];
      if (!rows.length) { $('batchesTable').innerHTML = '<div class="small">لا توجد دفعات.</div>'; $('batchesModal').style.display='flex'; return; }
      let html = `<table style="width:100%;border-collapse:collapse"><thead><tr><th>رقم الدفعة</th><th>التاريخ</th><th>كمية</th><th>المتبقي</th><th>سعر الشراء</th><th>سعر البيع</th><th>رقم الفاتورة</th><th>ملاحظات</th><th>الحالة</th><th>عرض</th></tr></thead><tbody>`;
      rows.forEach(b=>{
        const st = b.status === 'active' ? 'فعال' : (b.status==='consumed'?'مستهلك':(b.status==='reverted'?'مرجع':'ملغى'));
        html += `<tr><td class="monos">${b.id}</td><td class="small monos">${b.received_at||b.created_at||'-'}</td><td>${fmt(b.qty)}</td><td>${fmt(b.remaining)}</td><td>${fmt(b.unit_cost)}</td><td>${fmt(b.sale_price)}</td><td class="monos">${b.source_invoice_id||'-'}</td><td class="small">${esc(b.notes||'-')}</td><td>${st}</td><td><button class="btn ghost view-batch" data-id="${b.id}">عرض</button></td></tr>`;
      });
      html += `</tbody></table>`;
      $('batchesTable').innerHTML = html;
      document.querySelectorAll('.view-batch').forEach(btn => btn.addEventListener('click', ()=>{
        const id = btn.dataset.id;
        const row = rows.find(r=> r.id == id);
        if (!row) return;
        const st = row.status === 'active' ? 'فعال' : (row.status==='consumed'?'مستهلك':(row.status==='reverted'?'مرجع':'ملغى'));
        let html = `<table style="width:100%"><tbody>
          <tr><td>رقم الدفعة</td><td class="monos">${row.id}</td></tr>
          <tr><td>الكمية الأصلية</td><td>${fmt(row.qty)}</td></tr>
          <tr><td>المتبقي</td><td>${fmt(row.remaining)}</td></tr>
          <tr><td>سعر الشراء</td><td>${fmt(row.unit_cost)}</td></tr>
          <tr><td>سعر البيع</td><td>${fmt(row.sale_price)}</td></tr>
          <tr><td>تاريخ الاستلام</td><td>${esc(row.received_at||row.created_at||'-')}</td></tr>
          <tr><td>رقم الفاتورة المرتبطة</td><td>${row.source_invoice_id||'-'}</td></tr>
          <tr><td>ملاحظات</td><td>${esc(row.notes||'-')}</td></tr>
          <tr><td>حالة</td><td>${st}</td></tr>
          <tr><td>سبب الإلغاء</td><td>${row.status==='cancelled'?esc(row.cancel_reason||'-'):'-'}</td></tr>
          <tr><td>سبب الإرجاع</td><td>${row.status==='reverted'?esc(row.revert_reason||'-'):'-'}</td></tr>
        </tbody></table>`;
        $('batchDetailBody').innerHTML = html;
        $('batchTitle').textContent = 'تفاصيل الدفعة';
        $('batchDetailModal').style.display = 'flex';
      }));
      $('batchesModal').style.display = 'flex';
    } catch (e) {
      console.error(e);
      showToast('خطأ في فتح الدفعات', 'error');
    }
  }

  // close modal buttons
  $('closeBatchesBtn').addEventListener('click', ()=> $('batchesModal').style.display = 'none');
  $('closeBatchDetailBtn').addEventListener('click', ()=> $('batchDetailModal').style.display = 'none');
  $('syncBtn').addEventListener('click', async ()=> {
    try {
      const json = await fetchJson(location.pathname + '?action=sync_consumed');
      if (json.ok) showToast('تم مزامنة الدفعات', 'success');
      loadProducts();
    } catch (e) { showToast('خطأ في المزامنة', 'error'); }
  });

  /* ---------- customers ---------- */
  async function loadCustomers(q='') {
    try {
      const json = await fetchJson(location.pathname + '?action=customers' + (q?('&q='+encodeURIComponent(q)):''));
      if (!json.ok) { console.warn(json.error); return; }
      customers = json.customers || [];
      const wrap = $('customersList'); wrap.innerHTML = '';
      customers.forEach(c=>{
        const d = document.createElement('div');
        d.style.padding='6px'; d.style.borderBottom='1px solid var(--border)'; d.style.cursor='pointer';
        d.innerHTML = `<strong>${esc(c.name)}</strong><div class="small">${esc(c.mobile)}</div>`;
        d.addEventListener('click', ()=> {
          selectedCustomer = c; renderSelectedCustomer();
        });
        wrap.appendChild(d);
      });
    } catch (e) { console.error(e); }
  }
  $('customerSearchInput').addEventListener('input', debounce(()=> loadCustomers($('customerSearchInput').value.trim()), 250));
  function renderSelectedCustomer() {
    const wrap = $('selectedCustomer');
    if (!selectedCustomer) { wrap.innerHTML = '<div class="small">لم يتم اختيار عميل</div>'; return; }
    wrap.innerHTML = `<div><strong>${esc(selectedCustomer.name)}</strong></div><div class="small">الهاتف: ${esc(selectedCustomer.mobile)}</div>`;
  }
  loadCustomers('');

  // theme toggle
  $('toggleThemeBtn').addEventListener('click', ()=> {
    const el = document.documentElement;
    const cur = el.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
    el.setAttribute('data-theme', cur === 'dark' ? 'dark' : 'light');
  });

  // utility: update summary from products (optional)
  function updateSummaryFromProducts() {
    // you may compute aggregated totals here if desired
  }
}); // end DOMContentLoaded
</script>

<?php
// include footer if available
require_once BASE_DIR . 'partials/footer.php';
?>
