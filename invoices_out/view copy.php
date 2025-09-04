<?php
// invoices_out/view.php
$page_title = "تفاصيل الفاتورة - كاشير سريع";

// تحميل الإعدادات (اضبط المسار إذا لزم)
if (file_exists(dirname(__DIR__) . '/config.php')) {
    require_once dirname(__DIR__) . '/config.php';
} else {
    if (file_exists(dirname(dirname(__DIR__)) . '/config.php')) {
        require_once dirname(dirname(__DIR__)) . '/config.php';
    } else {
        die("ملف config.php غير موجود!");
    }
}

// تأكد تسجيل الدخول
session_start();
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("Location: " . BASE_URL . "auth/login.php");
    exit;
}

// رأس الصفحة (partials)
require_once BASE_DIR . 'partials/header.php';
require_once BASE_DIR . 'partials/navbar.php';

// إعداد متغيرات
$message = "";
$invoice_id = 0;
$invoice_data = null;
$invoice_items = [];
$products_list = [];
$customers_list = [];
$invoice_total_amount = 0;
$can_edit_invoice_header = false;
$can_manage_invoice_items = false;

// رسائل من الجلسة
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    unset($_SESSION['message']);
}

// CSRF token
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf_token = $_SESSION['csrf_token'];

// invoice id من GET (اختياري لو تعمل فاتورة جديدة اجعل id فارغ)
if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $invoice_id = intval($_GET['id']);
}

// --- صلاحيات: جلب بيانات الفاتورة (إذا id موجود) لتحديد الصلاحيات ---
if ($invoice_id) {
    $sql_invoice_auth = "SELECT id, customer_id, delivered, invoice_group, created_at, updated_at, created_by FROM invoices_out WHERE id = ?";
    if ($stmt_auth = $conn->prepare($sql_invoice_auth)) {
        $stmt_auth->bind_param("i", $invoice_id);
        $stmt_auth->execute();
        $result_auth = $stmt_auth->get_result();
        if ($result_auth->num_rows === 1) {
            $temp_invoice_data_for_auth = $result_auth->fetch_assoc();
            if ($_SESSION['role'] !== 'admin' && $temp_invoice_data_for_auth['created_by'] !== $_SESSION['id']) {
                $_SESSION['message'] = "<div class='alert alert-danger'>ليس لديك الصلاحية لعرض هذه الفاتورة.</div>";
                header("Location: " . BASE_URL . 'show_customer.php');
                exit;
            }
            if (isset($_SESSION['role']) && $_SESSION['role'] == 'admin') $can_edit_invoice_header = true;
            if ((isset($_SESSION['role']) && $_SESSION['role'] == 'admin') || (isset($temp_invoice_data_for_auth['created_by']) && $temp_invoice_data_for_auth['created_by'] == $_SESSION['id'])) {
                $can_manage_invoice_items = true;
            }
        } else {
            $_SESSION['message'] = "<div class='alert alert-danger'>لم يتم العثور على الفاتورة المطلوبة.</div>";
        }
        $stmt_auth->close();
    }
}

// --- جلب العملاء (لـ select) ---
$sql_customers = "SELECT id, name, mobile FROM customers ORDER BY name ASC LIMIT 500";
if ($res_cust = $conn->query($sql_customers)) {
    while ($c = $res_cust->fetch_assoc()) $customers_list[] = $c;
}

// --- جلب المنتجات (للقائمة الجانبية) ---
$sql_products = "SELECT id, product_code, name, selling_price, current_stock FROM products ORDER BY name ASC LIMIT 1000";
if ($res_prod = $conn->query($sql_products)) {
    while ($p = $res_prod->fetch_assoc()) $products_list[] = $p;
}

// --- جلب بيانات الفاتورة وبنودها إن وُجد id ---
if ($invoice_id) {
    $sql_complete_invoice_data = "SELECT i.id, i.customer_id, i.delivered, i.invoice_group, i.created_at, i.updated_at, i.created_by,
                   c.name as customer_name, c.mobile as customer_mobile, c.address as customer_address, c.city as customer_city,
                   u_creator.username as creator_name
            FROM invoices_out i
            LEFT JOIN customers c ON i.customer_id = c.id
            LEFT JOIN users u_creator ON i.created_by = u_creator.id
            WHERE i.id = ?";
    if ($stmt_complete = $conn->prepare($sql_complete_invoice_data)) {
        $stmt_complete->bind_param("i", $invoice_id);
        $stmt_complete->execute();
        $result_complete = $stmt_complete->get_result();
        if ($result_complete->num_rows === 1) $invoice_data = $result_complete->fetch_assoc();
        $stmt_complete->close();
    }

    // بنود الفاتورة
    $invoice_items = [];
    $invoice_total_amount = 0;
    $sql_items = "SELECT item.id as item_id, item.product_id, item.quantity, item.selling_price, item.total_price,
                         p.product_code, p.name as product_name, p.unit_of_measure
                  FROM invoice_out_items item
                  LEFT JOIN products p ON item.product_id = p.id
                  WHERE item.invoice_out_id = ?
                  ORDER BY item.id ASC";
    if ($stmt_items = $conn->prepare($sql_items)) {
        $stmt_items->bind_param("i", $invoice_id);
        $stmt_items->execute();
        $res_items = $stmt_items->get_result();
        while ($row = $res_items->fetch_assoc()) {
            $invoice_items[] = $row;
            $invoice_total_amount += floatval($row['total_price']);
        }
        $stmt_items->close();
    }
}

// --- HTML output: واجهة الكاشير (مبسطة) ---
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>كاشير — فاتورة</title>
  <!-- رابط CSS خارجي: ضع الملف assets/css/cashier.css -->
  <!-- <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/print_invoice.css"> -->
  <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/cashier.css">
</head>
<body>
  <div class="app">
    <?php echo $message; ?>
    <header>
      <div class="brand"><div class="logo">م</div><div><h1>كاشير — واجهة سريعة</h1><div class="muted">أضف منتجات بسرعة، احفظ الفاتورة أو أكملها</div></div></div>
      <div class="invoice-actions">
        <button class="small-btn" id="btn-clear">تفريغ</button>
        <button class="small-btn" id="btn-save-local">حفظ مؤقت</button>
      </div>
    </header>

    <div class="layout">
      <!-- LEFT: Products list searchable -->
      <div class="panel">
        <h3>المنتجات</h3>
        <div class="product-search">
          <input id="product_search_input" placeholder="ابحث بالاسم أو الكود" />
          <button id="btn-search" class="small-btn">بحث</button>
        </div>

        <div class="product-list" id="product_list">
          <?php foreach ($products_list as $p): 
            $pid = (int)$p['id'];
            $pcode = htmlspecialchars($p['product_code']);
            $pname = htmlspecialchars($p['name']);
            $pprice = number_format((float)$p['selling_price'],2);
            $pstock = number_format((float)$p['current_stock'],2);
          ?>
            <div class="product-item" data-id="<?php echo $pid;?>" data-code="<?php echo $pcode;?>" data-name="<?php echo $pname;?>" data-price="<?php echo $pprice;?>" data-stock="<?php echo $pstock;?>">
              <div>
                <div><strong><?php echo $pname;?></strong></div>
                <div class="meta">كود: <?php echo $pcode;?> — رصيد: <?php echo $pstock;?></div>
              </div>
              <div class="meta"><?php echo $pprice;?> ج.م</div>
            </div>
          <?php endforeach; ?>
        </div>

        <div style="margin-top:8px;display:flex;gap:8px;align-items:center">
          <input id="quick_qty" type="number" step="0.01" min="0.01" value="1" style="width:100px;padding:8px;border-radius:8px;border:1px solid #e6e9ee" />
          <button id="btn-add-selected" class="btn-primary">أضف للفاتورة</button>
        </div>

        <div style="margin-top:12px;font-size:13px;color:var(--muted)">اختصار: اضغط منتج من القائمة لتحديده، أو اكتب الكود واضغط Enter.</div>
      </div>

      <!-- CENTER: Invoice -->
      <div class="panel invoice-area">
        <div class="invoice-header">
          <div><strong>فاتورة جديدة</strong><div class="muted">سطر واحد = منتج</div></div>
          <div class="muted">رقم الفاتورة: <span id="invoice_number"><?php echo $invoice_id ? '#'.$invoice_id : '#NEW'; ?></span></div>
        </div>

        <div style="overflow:auto">
          <table class="invoice-table" id="invoice_table">
            <thead>
              <tr>
                <th>#</th>
                <th>المنتج</th>
                <th>الكود</th>
                <th style="width:120px">الكمية</th>
                <th style="width:120px">سعر الوحدة</th>
                <th style="width:140px">الإجمالي</th>
                <th style="width:90px">حذف</th>
              </tr>
            </thead>
            <tbody id="invoice_body">
              <?php
                // إن كان هناك بنود قادمة من DB عرضها
                if (!empty($invoice_items)) {
                  $counter = 1;
                  foreach ($invoice_items as $it) {
                    $line_total = number_format(floatval($it['total_price']),2);
                    echo "<tr>
                            <td>{$counter}</td>
                            <td>".htmlspecialchars($it['product_name'] ?? 'سطر')."</td>
                            <td>".htmlspecialchars($it['product_code'] ?? '')."</td>
                            <td class='text-center'>".number_format(floatval($it['quantity']),2)."</td>
                            <td class='text-end'>".number_format(floatval($it['selling_price']),2)." ج.م</td>
                            <td class='text-end fw-bold'>{$line_total} ج.م</td>
                            <td class='text-center'>-</td>
                          </tr>";
                    $counter++;
                  }
                }
              ?>
            </tbody>
          </table>
        </div>

        <div class="totals">
          <div class="card">
            <div class="row"><div class="muted">المجموع الفرعي</div><div id="subtotal"><?php echo number_format($invoice_total_amount,2);?> ج.م</div></div>
            <div class="row"><div class="muted">الضريبة (14%)</div><div id="tax"><?php echo number_format($invoice_total_amount * 0.14,2);?> ج.م</div></div>
            <div style="height:1px;background:#f1f5f9;margin:8px 0"></div>
            <div class="row" style="font-weight:700;font-size:16px"><div>المبلغ النهائي</div><div id="total"><?php echo number_format($invoice_total_amount * 1.14,2);?> ج.م</div></div>
            <div style="margin-top:8px;text-align:center">
              <button id="btn-complete" class="btn-success">إتمام الفاتورة وحفظها</button>
            </div>
          </div>
        </div>

        <div class="footer-actions">
          <div class="muted">المستخدم: <?php echo htmlspecialchars($_SESSION['username'] ?? 'ضيف'); ?></div>
          <div>
            <button id="btn-print" class="small-btn">طباعة</button>
          </div>
        </div>
      </div>

      <!-- RIGHT: Quick add + customer info -->
      <div class="panel quick-add">
        <div class="quick-card">
          <h4 style="margin:0 0 6px 0">عميل الفاتورة</h4>
          <div class="muted">يمكنك اختيار عميل أو تركها "عميل نقدي"</div>
          <select id="customer_select" style="width:100%;padding:8px;border-radius:8px;border:1px solid #e6e9ee;margin-top:8px">
            <option value="">-- عميل نقدي --</option>
            <?php foreach ($customers_list as $c): ?>
              <option value="<?php echo (int)$c['id']; ?>" <?php echo (isset($invoice_data['customer_id']) && $invoice_data['customer_id']==$c['id'])?'selected':'';?>>
                <?php echo htmlspecialchars($c['name'].' — '.$c['mobile']);?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <!-- <div class="quick-card">
          <h4 style="margin:0 0 6px 0">إضافة سطر سريع</h4>
          <input id="quick_code" placeholder="أدخل كود المنتج ثم Enter" style="width:100%;padding:8px;border-radius:8px;border:1px solid #e6e9ee;margin-bottom:8px" />
          <div style="display:flex;gap:8px">
            <input id="quick_price" placeholder="سعر" style="flex:1;padding:8px;border-radius:8px;border:1px solid #e6e9ee" />
            <input id="quick_qty2" placeholder="كمية" style="width:110px;padding:8px;border-radius:8px;border:1px solid #e6e9ee" value="1" />
          </div>
          <div style="margin-top:8px;display:flex;gap:8px">
            <button id="btn-quick-add" class="btn-primary" style="flex:1">أضف سطر سريع</button>
            <button id="btn-scan" class="small-btn">مسح باركود</button>
          </div>
        </div> -->

        <div class="quick-card">
          <h4 style="margin:0 0 6px 0">ملاحظات سريعة</h4>
          <textarea id="invoice_note" rows="4" style="width:100%;padding:8px;border-radius:8px;border:1px solid #e6e9ee"><?php echo htmlspecialchars($invoice_data['note'] ?? '');?></textarea>
        </div>
      </div>
    </div>
  </div>

 <script>
  // عناصر أساسية (آمنة: تحقق من وجودها قبل الاستخدام)
  const productListEl = document.getElementById('product_list');
  const searchInput = document.getElementById('product_search_input');
  const btnAddSelected = document.getElementById('btn-add-selected');
  const btnComplete = document.getElementById('btn-complete');
  const btnClear = document.getElementById('btn-clear');
  const btnPrint = document.getElementById('btn-print');
  const quickQty = document.getElementById('quick_qty');
  const customerSelect = document.getElementById('customer_select');
  const invoiceNote = document.getElementById('invoice_note');
  const quickCode = document.getElementById('quick_code'); // قد يكون موجوداً أو لا
  const btnQuickAdd = document.getElementById('btn-quick-add'); // قد يكون معلقًا

  // القيم المولدة من السيرفر
  const AJAX_SEARCH_URL = '<?php echo BASE_URL; ?>ajax_search_products.php';
  const AJAX_COMPLETE_URL = '<?php echo BASE_URL; ?>invoices_out/ajax_complete_invoice.php';
  const CSRF_TOKEN = '<?php echo $csrf_token; ?>';
  const CURRENT_INVOICE_ID = <?php echo $invoice_id ? $invoice_id : 'null'; ?>;

  let selectedProductEl = null;
  let invoiceLines = []; // مصفوفة السطور في الفاتورة

  // ===== helper functions =====
  function numberFormat(n){ return parseFloat(n || 0).toFixed(2); }
  function escapeHtml(s){ return (s+'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

  // ===== اختيار منتج من القائمة =====
  if (productListEl) {
    productListEl.addEventListener('click', (e) => {
      const item = e.target.closest('.product-item');
      if (!item) return;
      if (selectedProductEl) selectedProductEl.classList.remove('selected');
      selectedProductEl = item;
      item.classList.add('selected');
      if (quickQty) quickQty.value = 1;
    });
  }

  // ===== بحث live عبر AJAX (debounced) =====
  let searchTimer = null;
  if (searchInput && productListEl) {
    searchInput.addEventListener('input', () => {
      const q = searchInput.value.trim();
      if (searchTimer) clearTimeout(searchTimer);
      if (q.length < 2) return;
      searchTimer = setTimeout(() => {
        fetch(AJAX_SEARCH_URL + '?term=' + encodeURIComponent(q), { credentials: 'same-origin' })
          .then(r => r.json())
          .then(data => {
            productListEl.innerHTML = '';
            data.forEach(it => {
              const div = document.createElement('div');
              div.className = 'product-item';
              div.setAttribute('data-id', it.id);
              div.setAttribute('data-code', it.code);
              div.setAttribute('data-name', it.name);
              div.setAttribute('data-price', parseFloat(it.price).toFixed(2));
              div.setAttribute('data-stock', parseFloat(it.stock).toFixed(2));
              div.innerHTML = `<div><div><strong>${escapeHtml(it.name)}</strong></div><div class="meta">كود: ${escapeHtml(it.code)} — رصيد: ${parseFloat(it.stock).toFixed(2)}</div></div><div class="meta">${parseFloat(it.price).toFixed(2)} ج.م</div>`;
              productListEl.appendChild(div);
            });
          })
          .catch(err => {
            console.error('خطأ في جلب نتائج البحث:', err);
          });
      }, 250);
    });
  }

  // ===== إضافة منتج محدد =====
  if (btnAddSelected) {
    btnAddSelected.addEventListener('click', () => {
      if (!selectedProductEl) { alert('اختر منتجاً أولاً من القائمة'); return; }
      const id = selectedProductEl.getAttribute('data-id');
      const name = selectedProductEl.getAttribute('data-name');
      const code = selectedProductEl.getAttribute('data-code');
      const price = parseFloat(selectedProductEl.getAttribute('data-price')) || 0;
      const stock = parseFloat(selectedProductEl.getAttribute('data-stock')) || 0;
      const qty = parseFloat((quickQty && quickQty.value) || 1) || 1;
      if (qty <= 0) { alert('الكمية يجب أن تكون أكبر من صفر'); return; }
      if (qty > stock) if(!confirm('الكمية أكبر من الرصيد المتاح. الاستمرار؟')) return;
      addInvoiceRow({id,name,code,price,qty});
    });
  }

  // ===== سطر سريع (إن وُجِد الزر) =====
  if (btnQuickAdd) {
    btnQuickAdd.addEventListener('click', () => {
      const code = (document.getElementById('quick_code')?.value || '').trim();
      const qty = parseFloat(document.getElementById('quick_qty2')?.value || 1) || 1;
      const price = parseFloat(document.getElementById('quick_price')?.value || 0) || 0;
      if (!code) { alert('أدخل كود المنتج'); return; }
      const found = Array.from(productListEl.querySelectorAll('.product-item')).find(div => div.getAttribute('data-code') === code);
      if (found) addInvoiceRow({id: found.getAttribute('data-id'), name: found.getAttribute('data-name'), code, price: parseFloat(found.getAttribute('data-price')) || price, qty});
      else addInvoiceRow({id: null, name: 'سطر سريع: ' + code, code, price, qty});
      if (document.getElementById('quick_code')) document.getElementById('quick_code').value = '';
      if (document.getElementById('quick_price')) document.getElementById('quick_price').value = '';
      if (document.getElementById('quick_qty2')) document.getElementById('quick_qty2').value = 1;
    });
  }

  // دعم Enter في حقل الكود السريع (إن وُجد)
  if (quickCode) {
    quickCode.addEventListener('keydown', function(e){ if(e.key === 'Enter'){ btnQuickAdd?.click(); }});
  }

  // ===== إدارة سطور الفاتورة =====
  const invoiceBody = document.getElementById('invoice_body');
  function addInvoiceRow(item) {
    const existing = invoiceLines.find(r => r.id && r.id == item.id) || invoiceLines.find(r => !r.id && r.code === item.code);
    if (existing) existing.qty = parseFloat(existing.qty) + parseFloat(item.qty);
    else invoiceLines.push({lineId: Date.now(), ...item});
    renderInvoice();
  }
  function renderInvoice() {
    if (!invoiceBody) return;
    invoiceBody.innerHTML = '';
    let idx = 1; let subtotal = 0;
    invoiceLines.forEach(line => {
      const lineTotal = (parseFloat(line.price) || 0) * (parseFloat(line.qty) || 0);
      subtotal += lineTotal;
      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td>${idx++}</td>
        <td>${escapeHtml(line.name)}</td>
        <td>${escapeHtml(line.code || '')}</td>
        <td><input data-lineid="${line.lineId}" class="qty-input" value="${numberFormat(line.qty)}" /></td>
        <td><input data-lineid-price="${line.lineId}" class="qty-input" value="${numberFormat(line.price)}" /></td>
        <td class="line-total">${numberFormat(lineTotal)} ج.م</td>
        <td><button data-delete="${line.lineId}" class="small-btn">حذف</button></td>
      `;
      invoiceBody.appendChild(tr);
    });
    document.getElementById('subtotal').textContent = numberFormat(subtotal) + ' ج.م';
    const tax = subtotal * 0.14; document.getElementById('tax').textContent = numberFormat(tax) + ' ج.م';
    document.getElementById('total').textContent = numberFormat(subtotal + tax) + ' ج.م';
    attachRowHandlers();
  }
  function attachRowHandlers(){
    if (!invoiceBody) return;
    invoiceBody.querySelectorAll('[data-delete]').forEach(btn => btn.onclick = function(){
      const id = this.getAttribute('data-delete');
      invoiceLines = invoiceLines.filter(l => String(l.lineId) !== id);
      renderInvoice();
    });
    invoiceBody.querySelectorAll('input[data-lineid]').forEach(inp => {
      inp.onchange = function(){
        const id = this.getAttribute('data-lineid'); const val = parseFloat(this.value) || 0;
        const line = invoiceLines.find(l => String(l.lineId) === id);
        if (line) { line.qty = val; renderInvoice(); }
      }
    });
    invoiceBody.querySelectorAll('input[data-lineid-price]').forEach(inp => {
      inp.onchange = function(){
        const id = this.getAttribute('data-lineid-price'); const val = parseFloat(this.value) || 0;
        const line = invoiceLines.find(l => String(l.lineId) === id);
        if (line) { line.price = val; renderInvoice(); }
      }
    });
  }

  // ===== إتمام الفاتورة (AJAX) مع CSRF ومسار صحيح =====
  if (btnComplete) {
    btnComplete.addEventListener('click', () => {
      if (invoiceLines.length === 0) { alert('لا توجد بنود في الفاتورة'); return; }
      const payload = {
        invoice_id: CURRENT_INVOICE_ID,
        customer_id: (customerSelect ? customerSelect.value : null) || null,
        note: (invoiceNote ? invoiceNote.value : '') || '',
        lines: invoiceLines
      };
      fetch(AJAX_COMPLETE_URL, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': CSRF_TOKEN
        },
        body: JSON.stringify(payload)
      }).then(r => r.json()).then(resp => {
        if (resp.success) {
          alert('تم حفظ الفاتورة بنجاح. رقم الفاتورة: ' + (resp.invoice_number || resp.invoice_id));
          invoiceLines = []; renderInvoice();
          if (resp.redirect) window.location.href = resp.redirect;
        } else {
          alert('خطأ: ' + (resp.error || 'فشل الحفظ'));
        }
      }).catch(err => { alert('خطأ فني'); console.error(err); });
    });
  }

  // مساعدة: تفريغ وطباعة
  if (btnClear) btnClear.addEventListener('click', ()=>{ if(confirm('مسح كل البنود؟')){ invoiceLines=[]; renderInvoice(); }});
  if (btnPrint) btnPrint.addEventListener('click', ()=> window.print());

  // بدء العرض
  renderInvoice();
</script>


</body>
</html>

<?php
require_once BASE_DIR . 'partials/footer.php';
$conn->close();
?>
