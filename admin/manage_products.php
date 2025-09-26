<?php
// manage_product.php
// تحسينات: includes, CSRF, toasts, sync_consumed, print enabled, Arabic labels, better UI

// ========== إدرج هذه الأسطر كما طلبت ==========
$page_title = "إدارة المنتجات";
$class_dashboard = "active";
require_once dirname(__DIR__) . '/config.php';
require_once BASE_DIR . 'partials/session_admin.php';
// ==============================================

/* رسالة جلسة (Message) */
$message = "";
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    unset($_SESSION['message']);
}

/* CSRF token */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

/* -------------------------------------------
   DB fallback (in case config.php didn't set $pdo)
   ------------------------------------------- */
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
        die("DB connection failed: " . htmlspecialchars($e->getMessage()));
    }
}

/* helper JSON output */
function jsonOut($data) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/* =========================
   AJAX endpoints
   ========================= */
if (isset($_REQUEST['action'])) {
    $action = $_REQUEST['action'];

    // 0) sync consumed: convert batches with remaining <= 0 from active -> consumed
    if ($action === 'sync_consumed') {
        try {
            $stmt = $pdo->prepare("UPDATE batches SET status = 'consumed', updated_at = NOW() WHERE status = 'active' AND COALESCE(remaining,0) <= 0");
            $stmt->execute();
            jsonOut(['ok'=>true, 'updated' => $stmt->rowCount()]);
        } catch (Exception $e) {
            jsonOut(['ok'=>false,'error'=>$e->getMessage()]);
        }
    }

    // 1) products list (with aggregates directly from batches). Server-side search by q
    if ($action === 'products') {
        $search = isset($_GET['q']) ? trim($_GET['q']) : '';
        $params = [];
        $where = '';
        if ($search !== '') {
            $where = " WHERE (p.name LIKE ? OR p.product_code LIKE ?)";
            $params[] = "%$search%"; $params[] = "%$search%";
        }
        $sql = "
            SELECT p.id, p.product_code, p.name, p.unit_of_measure, p.current_stock, p.reorder_level, p.created_at,
                   p.cost_price AS base_cost_price, p.selling_price AS base_selling_price,
                   COALESCE(b.rem_sum,0) AS remaining_active,
                   COALESCE(b.val_sum,0) AS stock_value_active,
                   -- last purchase only from active or consumed (exclude reverted/cancelled)
                   (SELECT b2.unit_cost FROM batches b2 WHERE b2.product_id = p.id AND b2.status IN ('active','consumed') ORDER BY b2.received_at DESC, b2.created_at DESC, b2.id DESC LIMIT 1) AS last_purchase_price,
                   (SELECT b2.sale_price FROM batches b2 WHERE b2.product_id = p.id AND b2.status IN ('active','consumed') ORDER BY b2.received_at DESC, b2.created_at DESC, b2.id DESC LIMIT 1) AS last_batch_sale_price,
                   (SELECT b2.received_at FROM batches b2 WHERE b2.product_id = p.id AND b2.status IN ('active','consumed') ORDER BY b2.received_at DESC, b2.created_at DESC, b2.id DESC LIMIT 1) AS last_batch_date
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
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        jsonOut(['ok'=>true,'products'=>$rows]);
    }

    // 2) batches list for a product
    if ($action === 'batches' && isset($_GET['product_id'])) {
        $product_id = (int)$_GET['product_id'];
        $sql = "SELECT id, product_id, qty, remaining, original_qty, unit_cost, sale_price, received_at, expiry, notes, source_invoice_id, source_item_id, created_by, adjusted_by, adjusted_at, created_at, updated_at, revert_reason, cancel_reason, status FROM batches WHERE product_id = ? ORDER BY received_at DESC, created_at DESC, id DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$product_id]);
        $rows = $stmt->fetchAll();
        $pstmt = $pdo->prepare("SELECT name, product_code FROM products WHERE id = ?");
        $pstmt->execute([$product_id]);
        $prod = $pstmt->fetch();
        jsonOut(['ok'=>true,'batches'=>$rows,'product'=>$prod]);
    }

    // 3) save product (create or update)
  // 3) save product (create or update) - robust duplicate check + friendly errors
if ($action === 'save_product' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $code = isset($_POST['product_code']) ? trim($_POST['product_code']) : '';
    $name = isset($_POST['name']) ? trim($_POST['name']) : '';
    $unit = isset($_POST['unit_of_measure']) ? trim($_POST['unit_of_measure']) : '';
    $cost = isset($_POST['cost_price']) ? (float)$_POST['cost_price'] : 0;
    $sell = isset($_POST['selling_price']) ? (float)$_POST['selling_price'] : 0;
    $reorder = isset($_POST['reorder_level']) ? (float)$_POST['reorder_level'] : 0;
    $desc = isset($_POST['description']) ? trim($_POST['description']) : null;

    if ($code === '' || $name === '' || $unit === '') {
        jsonOut(['ok'=>false,'error'=>'الرجاء إكمال الحقول المطلوبة: كود المنتج، الاسم، والوحدة.']);
    }

    try {
        // فحص مبدئي إذا كان الكود موجوداً مسبقاً (لتقديم رسالة واضحة)
        $dupStmt = $pdo->prepare("SELECT id FROM products WHERE product_code = ? LIMIT 1");
        $dupStmt->execute([$code]);
        $dup = $dupStmt->fetch();
        if ($dup && ($id === 0 || $dup['id'] != $id)) {
            jsonOut(['ok'=>false,'error'=>'كود المنتج هذا مسجل بالفعل. الرجاء اختيار كود آخر أو تعديل المنتج الموجود.']);
        }

        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE products SET product_code=?, name=?, unit_of_measure=?, cost_price=?, selling_price=?, reorder_level=?, description=?, updated_at = NOW() WHERE id=?");
            $stmt->execute([$code,$name,$unit,$cost,$sell,$reorder,$desc,$id]);
            jsonOut(['ok'=>true,'msg'=>'تم تعديل المنتج']);
        } else {
            $stmt = $pdo->prepare("INSERT INTO products (product_code,name,unit_of_measure,current_stock,reorder_level,created_at,cost_price,selling_price,description) VALUES (?, ?, ?, 0, ?, NOW(), ?, ?, ?)");
            $stmt->execute([$code,$name,$unit,$reorder,$cost,$sell,$desc]);
            $newId = $pdo->lastInsertId();
            jsonOut(['ok'=>true,'msg'=>'تم إضافة المنتج','id'=>$newId]);
        }
    } catch (PDOException $pdoEx) {
        // التقاط تكرار إدخال في حال حدوث ريسس (race) أو أي قيد قاعدة بيانات
        if ($pdoEx->getCode() === '23000') {
            jsonOut(['ok'=>false,'error'=>'كود المنتج مستخدم بالفعل (تعارض).']);
        }
        error_log("save_product PDO error: " . $pdoEx->getMessage());
        jsonOut(['ok'=>false,'error'=>'حدث خطأ أثناء حفظ المنتج. حاول مرة أخرى أو تواصل مع الدعم.']);
    } catch (Exception $e) {
        error_log("save_product error: " . $e->getMessage());
        jsonOut(['ok'=>false,'error'=>'حدث خطأ أثناء حفظ المنتج.']);
    }
}


    // 4) delete product (AJAX POST) with checks
    // 4) delete product (AJAX POST) with checks
if ($action === 'delete_product' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $pid = isset($_POST['product_id']) ? (int)$_POST['product_id'] : 0;
    if ($pid <= 0) jsonOut(['ok'=>false,'error'=>'معرف المنتج غير صالح.']);

    try {
        // 1) فحص ارتباطات المنتج (دفعات أو بنود فواتير) -> قراءة فقط (آمن)
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM batches WHERE product_id = ?");
        $stmt->execute([$pid]);
        $cntB = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM invoice_out_items WHERE product_id = ?");
        $stmt->execute([$pid]);
        $cntS = (int)$stmt->fetchColumn();

        if ($cntB > 0 || $cntS > 0) {
            jsonOut(['ok'=>false,'error'=>'لا يمكن حذف المنتج: مرتبط بفاتورة أو له دفعات مسجلة.']);
        }

        // 2) الآن الفحص الأمني للـ CSRF قبل أي عملية حذف (state-changing)
        $token = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
        if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)$token)) {
            jsonOut(['ok'=>false,'error'=>'رمز التحقق (CSRF) غير صالح. أعد تحميل الصفحة وحاول مجدداً.']);
        }

        // 3) تنفيذ الحذف
        $stmt = $pdo->prepare("DELETE FROM products WHERE id = ?");
        $stmt->execute([$pid]);
        jsonOut(['ok'=>true,'msg'=>'تم حذف المنتج بنجاح']);
    } catch (Exception $e) {
        error_log("delete_product error: " . $e->getMessage());
        jsonOut(['ok'=>false,'error'=>'حدث خطأ أثناء حذف المنتج.']);
    }
}


    // default
    jsonOut(['ok'=>false,'error'=>'action غير معروف']);
}

/* ===========================
   إذا لم يكن AJAX — عرض الواجهة
   =========================== */
require_once BASE_DIR . 'partials/header.php';
require_once BASE_DIR . 'partials/sidebar.php';


?>

<!-- ضع هذا داخل <body> بعد header.php -->
<div id="mp-manage-products">
  <style>
  /* ============================
     Scoped styles for manage-products
     ============================ */
  #mp-manage-products { --primary:#0b84ff; --accent:#7c3aed; --teal:#10b981; --amber:#f59e0b; --rose:#ef4444;
    --bg:#f6f8fc; --surface:#fff; --surface-2:#f9fbff; --text:#0b1220; --muted:#6b7280; --border:rgba(2,6,23,0.06);
    --radius:12px; --shadow:0 14px 30px rgba(2,6,23,0.06); --header-h:72px;
    font-family:Inter, 'Noto Naskh Arabic', Tahoma, Arial;
  }
  [data-app][data-theme="dark"] #mp-manage-products {
    --bg:#0b1220; --surface:#0f1626; --surface-2:#071224; --text:#e6eef8; --muted:#94a3b8; --border:rgba(148,163,184,0.12);
  }

  #mp-manage-products * { box-sizing:border-box; }
  #mp-manage-products html, #mp-manage-products body { height:100%; }
  #mp-manage-products .app-wrap{display:flex;flex-direction:column;height:100vh;padding:18px;background:var(--bg);color:var(--text)}
  #mp-manage-products .header{display:flex;align-items:center;justify-content:space-between;gap:12px}
  #mp-manage-products .brand{display:flex;align-items:center;gap:12px}
  #mp-manage-products .logo{width:56px;height:56px;border-radius:12px;background:linear-gradient(135deg,var(--primary),var(--accent));display:flex;align-items:center;justify-content:center;color:#fff;font-weight:800}
  #mp-manage-products .header .controls{display:flex;gap:8px;align-items:center}
  #mp-manage-products .input, #mp-manage-products .input-sm{padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--surface);min-width:200px;color:var(--text)}
  #mp-manage-products .btn{padding:10px 14px;border-radius:10px;border:none;cursor:pointer;font-weight:700}
  #mp-manage-products .btn-ghost{background:transparent;border:1px solid var(--border);color:var(--text)}
  #mp-manage-products .btn-primary{background:linear-gradient(90deg,var(--primary),var(--accent));color:#fff;box-shadow:var(--shadow)}
  #mp-manage-products .main{display:flex;flex:1;gap:16px;margin-top:14px;align-items:stretch;overflow:hidden}
  #mp-manage-products .content{flex:1;display:flex;flex-direction:column;background:var(--surface);border-radius:14px;padding:14px;box-shadow:var(--shadow);overflow:hidden}
  #mp-manage-products .table-wrap{flex:1;display:flex;flex-direction:column;overflow:hidden}
  #mp-manage-products .table-head{display:flex;align-items:center;justify-content:space-between;padding:8px 6px;border-bottom:1px solid var(--border)}
  #mp-manage-products .summary{display:flex;gap:12px;align-items:center;flex-wrap:wrap}
  #mp-manage-products .stat{padding:10px;border-radius:10px;background:linear-gradient(180deg,rgba(11,132,255,0.05),rgba(124,58,237,0.03));min-width:170px}
  #mp-manage-products .stat .lbl{font-size:12px;color:var(--muted)}
  #mp-manage-products .stat .val{font-size:16px;font-weight:800;margin-top:6px}
  #mp-manage-products .table-scroller{overflow:auto;flex:1}
  /* keep using .my-table(bootstrap) but scope header/td styles so we don't override global .my-table*/
  #mp-manage-products table.my-tabel{width:100%;border-collapse:collapse;min-width:1000px}
  #mp-manage-products table.my-table th, #mp-manage-products table.my-tabletd{padding:12px;text-align:center;border-bottom:1px solid var(--border);vertical-align:middle}
  #mp-manage-products table.my-table th{position:sticky;top:0;background:var(--surface);z-index:4;color:var(--muted);font-weight:800}
  #mp-manage-products .name-col{text-align:right;font-weight:700}
  #mp-manage-products .badge{padding:6px 10px;border-radius:999px;font-weight:700}
  #mp-manage-products .badge.green{background:rgba(16,185,129,0.12);color:var(--teal)}
  #mp-manage-products .badge.orange{background:rgba(245,158,11,0.08);color:var(--amber)}
  #mp-manage-products .badge.red{background:rgba(239,68,68,0.08);color:var(--rose)}
  #mp-manage-products .badge-muted{background:rgba(148,163,184,0.08);color:var(--muted)}
  #mp-manage-products .small{font-size:13px;color:var(--muted)}
  #mp-manage-products .actions{display:flex;gap:6px;justify-content:center}
  #mp-manage-products .warn-low{background:rgba(255,80,80,0.08);color:var(--rose);padding:6px;border-radius:8px;font-weight:700}

  /* renamed modal to mp-modal to avoid conflicts with bootstrap .modal */
  #mp-manage-products .mp-modal-backdrop{position:fixed;inset:0;background:rgba(2,6,23,0.55);display:none;align-items:center;justify-content:center;z-index:1200;padding:20px}
  #mp-manage-products .mp-modal{width:100%;max-width:1400px;background:var(--surface);border-radius:12px;padding:16px;max-height:88vh;overflow:auto}

  #mp-manage-products .form-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}
  @media (max-width:900px){ #mp-manage-products .form-grid{grid-template-columns:repeat(2,1fr)} }
  @media (max-width:600px){ #mp-manage-products .form-grid{grid-template-columns:1fr} }
  #mp-manage-products .kv{font-weight:700;color:var(--text)}
  #mp-manage-products .filter-row{display:flex;gap:8px;align-items:center;margin-bottom:12px}
  #mp-manage-products .pill{padding:8px 12px;border-radius:999px;background:var(--surface-2);border:1px solid var(--border)}
  #mp-manage-products .monos{font-family:monospace;color:var(--text)}
  /* renamed toast-wrap to mp-toast-wrap */
  #mp-manage-products .mp-toast-wrap{position:fixed;bottom:24px;left:24px;z-index:2000;display:flex;flex-direction:column;gap:8px}
  #mp-manage-products .mp-toast{padding:10px 14px;border-radius:8px;color:#fff;box-shadow:0 8px 20px rgba(2,6,23,0.2)}
  #mp-manage-products .mp-toast.success{background:linear-gradient(90deg,#10b981,#06b6d4)}
  #mp-manage-products .mp-toast.error{background:linear-gradient(90deg,#ef4444,#f97316)}
  #mp-manage-products .head-strong{font-weight:900}

  /* small: sticky header support class */
  #mp-manage-products .my-table th.sticky { position: sticky; top: 0; z-index: 6; background: var(--surface); }
  </style>
<input type="hidden" id="mp_csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

  <div class="app-wrap">
    <div class="header">
      <div class="brand">
        <div class="logo">INV</div>
        <div>
          <div style="font-size:20px;font-weight:900">إدارة المنتجات</div>
          <div class="small">نظام المخزون — دفعات · معاينة </div>
        </div>
      </div>

      <div class="controls" role="toolbar" aria-label="Actions">
        <input id="searchInput" class="input" placeholder="ابحث باسم المنتج أو الكود...">
        <!-- <button id="refreshBtn" class="btn btn-ghost" title="تحديث"><i class="fa fa-sync"></i></button> -->
        <button id="addProductBtn" class="btn btn-primary" title="إضافة منتج"><i class="fa fa-plus"></i> إضافة</button>
        <!-- <button id="themeToggle" class="btn btn-ghost" title="Dark/Light"><i class="fa fa-moon"></i></button> -->
      </div>
    </div>

    <div class="main">
      <div class="content" role="main">
        <div class="table-head">
          <div class="summary">
            <div class="stat"><div class="lbl">قيمة المتبقي (Active)</div><div id="totalValueActive" class="val">-</div></div>
            <div class="stat"><div class="lbl">إجمالي كمية المتبقي</div><div id="totalUnitsActive" class="val">-</div></div>
            <div class="stat"><div class="lbl">إجمالي المنتجات</div><div id="totalProducts" class="val">-</div></div>
          </div>
          <div class="small">القيم أعلاه تحسب من الدفعات التي حالتها <strong>فعال</strong> فقط.</div>
        </div>

        <div class="table-wrap">
          <div class="table-scroller" id="tableScroller">
            <table class="my-table" id="productsTable" role="table" aria-label="Products table">
              <thead >
                <tr>
                  <th>#</th>
                  <th>كود</th>
                  <th class="name-col">الاسم</th>
                  <th>الوحدة</th>
                  <th>رصيد (دخل)</th>
                  <th>متبقي (Active)</th>
                  <th>حد الطلب</th>
                  <th>سعر شراء أساسي</th>
                  <th>سعر بيع أساسي</th>
                  <th>آخر شراء (تاريخ و سعر)</th>
                  <th>قيمة المتبقي</th>
                  <th>إجراءات</th>
                </tr>
              </thead>
              <tbody id="productsTbody"></tbody>
            </table>
          </div>
        </div>

      </div>
    </div>
  </div>

  <!-- Product modal (add/edit) -->
  <div id="productModal" class="mp-modal-backdrop" aria-hidden="true">
    <div class="mp-modal" role="dialog" aria-modal="true">
      <h3 id="pModalTitle">إضافة منتج</h3>
      <form id="productForm">
        <input type="hidden" id="p_id" name="id" value="0">
        <div class="form-grid" style="margin-top:12px">
          <div><label class="kv">كود المنتج</label><input id="p_code" name="product_code" class="input-sm" required></div>
          <div><label class="kv">اسم المنتج</label><input id="p_name" name="name" class="input-sm" required></div>
          <div><label class="kv">الوحدة</label><input id="p_unit" name="unit_of_measure" class="input-sm" required></div>
          <div><label class="kv">سعر الشراء الأساسي</label><input id="p_cost" name="cost_price" type="number" step="0.01" class="input-sm"></div>
          <div><label class="kv">سعر البيع الأساسي</label><input id="p_sell" name="selling_price" type="number" step="0.01" class="input-sm"></div>
          <div><label class="kv">حد إعادة الطلب</label><input id="p_reorder" name="reorder_level" type="number" step="0.01" class="input-sm"></div>
          <div style="grid-column:1/-1"><label class="kv">الوصف</label><textarea id="p_desc" name="description" class="input-sm" style="height:90px"></textarea></div>
        </div>
        <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:12px">
          <button type="button" class="btn btn-ghost" onclick="closeProductModal()">إلغاء</button>
          <button type="submit" class="btn btn-primary">حفظ</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Batches modal -->
  <div id="batchesModal" class="mp-modal-backdrop" aria-hidden="true">
    <div class="mp-modal" role="dialog" aria-modal="true">
      <div style="display:flex;justify-content:space-between;align-items:center">
        <div>
          <h3 id="batchesModalTitle">دفعات</h3>
          <div id="batchesProductName" class="small"></div>
        </div>
        <div style="display:flex;gap:8px;align-items:center">
          <div id="batchesModalTotals" class="small monos">المتبقي: - • قيمة: - ج</div>
          <select id="batchFilter" class="input-sm" aria-label="Filter batches">
            <option value="all">كل الحالات</option>
            <option value="active">فعال</option>
            <option value="consumed">مستهلك</option>
            <option value="reverted">مرجع</option>
            <option value="cancelled">ملغى</option>
          </select>
          <button id="batchPrint" class="btn btn-ghost" title="طباعة"><i class="fa fa-print"></i></button>
          <button id="closeBatchesBtn" class="btn btn-primary">إغلاق</button>
        </div>
      </div>

      <div style="margin-top:12px" id="batchesContent">
        <div class="filter-row"><span class="small">فلتر: </span></div>
        <div id="batchesTableWrap" style="overflow:auto;max-height:65vh"></div>
      </div>
    </div>
  </div>

  <!-- Batch detail modal -->
  <div id="batchDetailModal" class="mp-modal-backdrop" aria-hidden="true">
    <div class="mp-modal" role="dialog" aria-modal="true">
      <h3 id="batchDetailTitle">تفاصيل الدفعة</h3>
      <div id="batchDetailBody"></div>
      <div style="display:flex;justify-content:flex-end;margin-top:12px">
        <button class="btn btn-primary" onclick="closeBatchDetail()">إغلاق</button>
      </div>
    </div>
  </div>

  <!-- toasts -->
  <div class="mp-toast-wrap" id="mpToastWrap" aria-live="polite" aria-atomic="true"></div>
</div>

<script>
  // ضع هذا في أعلى ملف JS
function getCsrf() {
  // أولاً ابحث عن meta (لو ضفْتَه في header.php)
  const m = document.querySelector('meta[name="csrf-token"]');
  if (m && m.getAttribute('content')) return m.getAttribute('content');
  // fallback: الحقل المخفي الذي أضفناه داخل body
  const h = document.getElementById('mp_csrf_token');
  if (h && h.value) return h.value;
  return '';
}


// مثال doDeleteProduct
async function doDeleteProduct(id) {
  if (!confirm('هل أنت متأكد من حذف المنتج؟')) return;
  const fd = new FormData();
  fd.append('action', 'delete_product');
  fd.append('product_id', id);
  fd.append('csrf_token', getCsrf());

  try {
    const res = await fetch('?action=delete_product', { method: 'POST', body: fd });
    const json = await res.json();
    if (!json.ok) {
      showToast(json.error || 'تعذر الحذف', 'error');
      return;
    }
    showToast(json.msg || 'تم الحذف', 'success');
    fetchProducts();
  } catch (e) {
    console.error(e);
    showToast('خطأ في الاتصال', 'error');
  }
}

/* ===== Helpers & UI (fixed & hardened) ===== */
const $ = id => document.getElementById(id);
let products = [], currentProduct = null, batchesCache = [];

/* Toasts - use mpToastWrap + mp-toast to match scoped CSS */
function showToast(text, type='success', timeout=3500){
  try {
    const wrap = $('mpToastWrap') || document.querySelector('.mp-toast-wrap');
    if (!wrap) {
      console.warn('Toast wrapper not found');
      return;
    }
    const div = document.createElement('div');
    div.className = 'mp-toast ' + (type === 'error' ? 'error' : 'success');
    div.textContent = text;
    wrap.appendChild(div);
    // animate-out after timeout
    setTimeout(()=> {
      div.style.transition = 'opacity 0.25s ease';
      div.style.opacity = '0';
      setTimeout(()=> { try{ wrap.removeChild(div); }catch(e){} }, 300);
    }, timeout);
  } catch(e) { console.error('showToast error', e); }
}

function formatNum(n, opts = {min:0, max:2}){
  const v = Number(n || 0);
  return v.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}/* format numbers (Western digits) */


/* escape HTML */
function escapeHtml(s){ if (s == null) return ''; return String(s).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m])); }

/* debounce */
function debounce(fn, wait=300){
  let t;
  return function(...args){ clearTimeout(t); t = setTimeout(()=> fn.apply(this,args), wait); };
}

/* fetchJson util */
async function fetchJson(url, opts){
  const res = await fetch(url, opts);
  // guard: if response is not json, throw
  const text = await res.text();
  try {
    return JSON.parse(text);
  } catch(e) {
    console.error('Invalid JSON response from', url, text.slice(0,200));
    throw new Error('Invalid JSON response');
  }
}

/* =========================
   Sync consumed on load
   ========================= */
async function syncConsumed(){
  try {
    const json = await fetchJson('?action=sync_consumed');
    if (json && json.ok && json.updated && json.updated > 0) {
      showToast('تم تحديث حالات دفعات استهلكت مخزونها', 'success', 3000);
    }
  } catch (e) {
    console.info('syncConsumed: silent fail', e);
  }
}

/* =========================
   Products fetching + rendering
   ========================= */
async function fetchProducts(q=''){
  const url = '?action=products' + (q ? '&q=' + encodeURIComponent(q) : '');
  try {
    const json = await fetchJson(url);
    if (!json.ok) {
      showToast(json.error || 'خطأ في جلب المنتجات', 'error');
      return;
    }
    products = json.products || [];
    renderProducts();
    // totals
    let totalVal = 0, totalUnits = 0;
    products.forEach(p => { totalVal += parseFloat(p.stock_value_active || 0); totalUnits += parseFloat(p.remaining_active || 0); });
    const elVal = $('totalValueActive');
    const elUnits = $('totalUnitsActive');
    const elProducts = $('totalProducts');
    if (elVal) elVal.textContent = formatNum(totalVal) + ' ج';
    if (elUnits) elUnits.textContent = formatNum(totalUnits);
    if (elProducts) elProducts.textContent = (products.length || 0);
  } catch (e) {
    console.error('fetchProducts', e);
    showToast('تعذر الاتصال بالخادم', 'error');
  }
}

function renderProducts(){
  const tbody = document.querySelector('#productsTable tbody');
  if (!tbody) return;
  tbody.innerHTML = '';
  const q = ( $('searchInput') && $('searchInput').value.trim().toLowerCase() ) || '';
  products.forEach(p => {
    // server should provide p.remaining_active (numeric) and p.stock_value_active
    const rem = parseFloat(p.remaining_active || 0);
    const reorder = (p.reorder_level !== null && p.reorder_level !== undefined) ? parseFloat(p.reorder_level) : 0;
    const lowWarn = (reorder > 0 && rem < reorder);
    // last purchase info
    let lastInfo = '-';
    if (p.last_batch_date && p.last_purchase_price) {
      lastInfo = ` ${formatNum(p.last_purchase_price)}`;
      // lastInfo = `${escapeHtml(p.last_batch_date)} • ${formatNum(p.last_purchase_price)}`;
    }
    if (q && !((p.name + ' ' + p.product_code).toLowerCase().includes(q))) return;
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td class="monos">${escapeHtml(p.id)}</td>
      <td class="monos p-2">${escapeHtml(p.product_code)}</td>
      <td class="name-col">${escapeHtml(p.name)}</td>
      <td>${escapeHtml(p.unit_of_measure || '-')}</td>
      <td class="monos">${formatNum(p.current_stock || 0)}</td>
      <td>${ lowWarn ? '<span class="warn-low">' + formatNum(rem, {min:4,max:4}) + '</span>' : '<span class="badge green">' + formatNum(rem, {min:4,max:4}) + '</span>' }</td>
      <td>${formatNum(reorder)}</td>
      <td class="monos">${formatNum(p.base_cost_price || p.cost_price || 0)}</td>
      <td class="monos">${formatNum(p.base_selling_price || p.selling_price || 0)}</td>
      <td class="small monos">${lastInfo}</td>
      <td class="monos">${formatNum(p.stock_value_active || 0)}</td>
      <td class="actions">
        <button class="btn btn-ghost" onclick="openProductModal(${p.id})" title="تعديل"><i class="fa fa-edit"></i></button>
        <button class="btn btn-ghost" onclick="openBatches(${p.id})" title="دفعات"><i class="fa fa-boxes-stacked"></i></button>
        <button class="btn btn-ghost" onclick="doDeleteProduct(${p.id})" title="حذف"><i class="fa fa-trash"></i></button>
      </td>
    `;
    tbody.appendChild(tr);
  });
}

/* Search */
(function attachSearch(){
  const si = $('searchInput');
  if (!si) return;
  const onSearch = debounce(()=> {
    const q = si.value.trim();
    fetchProducts(q);
  }, 400);
  si.addEventListener('input', onSearch);
})();

/* Refresh */
const refreshBtn = $('refreshBtn');
if (refreshBtn) refreshBtn.addEventListener('click', ()=> fetchProducts());

/* Theme toggle */
const themeToggle = $('themeToggle');
if (themeToggle) themeToggle.addEventListener('click', ()=> {
  const el = document.documentElement;
  const cur = el.getAttribute('data-theme') || 'light';
  el.setAttribute('data-theme', cur === 'dark' ? 'light' : 'dark');
});

/* =========================
   Product modal (open/close/save)
   ========================= */
function openProductModal(id=0){
  const modal = $('productModal');
  if (!modal) return;
  if (id > 0) {
    const p = products.find(x => Number(x.id) === Number(id));
    if (!p) { showToast('منتج غير موجود','error'); return; }
    $('pModalTitle').textContent = 'تعديل المنتج';
    $('p_id').value = p.id;
    $('p_code').value = p.product_code || '';
    $('p_name').value = p.name || '';
    $('p_unit').value = p.unit_of_measure || '';
    $('p_cost').value = p.base_cost_price || p.cost_price || '';
    $('p_sell').value = p.base_selling_price || p.selling_price || '';
    $('p_reorder').value = p.reorder_level || '';
    $('p_desc').value = p.description || '';
  } else {
    $('pModalTitle').textContent = 'إضافة منتج';
    const form = $('productForm');
    if (form) form.reset();
    if ($('p_id')) $('p_id').value = 0;
  }
  modal.style.display = 'flex';
}
function closeProductModal(){ const m = $('productModal'); if (m) m.style.display = 'none'; }

/* Save product (AJAX) */
const productForm = $('productForm');
if (productForm) {
  productForm.addEventListener('submit', async function(e){
    e.preventDefault();
    const fd = new FormData(this);
    // server expects action=save_product
    fd.append('action','save_product');
    try {
      const res = await fetch('?action=save_product', { method: 'POST', body: fd });
      const json = await res.json();
      if (!json.ok) { showToast(json.error || 'خطأ في الحفظ', 'error'); return; }
      showToast(json.msg || 'تم الحفظ', 'success');
      closeProductModal();
      await fetchProducts();
    } catch (err) {
      console.error('save product', err);
      showToast('خطأ في الاتصال', 'error');
    }
  });
}

/* Delete product (AJAX) */
// async function doDeleteProduct(id){
//   if (!confirm('هل تريد حذف المنتج؟')) return;
//   const fd = new FormData();
//   fd.append('action','delete_product');
//   fd.append('product_id', id);
//   // CSRF token must be present in page (PHP injected)
//   const tokenEl = document.querySelector('input[name="csrf_token"], meta[name="csrf-token"]');
//   const token = tokenEl ? tokenEl.value || tokenEl.getAttribute('content') : null;
//   if (token) fd.append('csrf_token', token);
//   try {
//     const res = await fetch('?action=delete_product', { method: 'POST', body: fd });
//     const json = await res.json();
//     if (!json.ok) return showToast(json.error || 'تعذر الحذف', 'error');
//     showToast(json.msg || 'تم الحذف', 'success');
//     await fetchProducts();
//   } catch (err) {
//     console.error('delete', err);
//     showToast('خطأ في الاتصال', 'error');
//   }
// }

/* =========================
   Batches modal
   ========================= */
async function openBatches(productId){
  try {
    await syncConsumed();
    const json = await fetchJson(`?action=batches&product_id=${encodeURIComponent(productId)}`);
    if (!json.ok) { showToast(json.error || 'خطأ في جلب الدفعات', 'error'); return; }
    batchesCache = json.batches || [];
    currentProduct = json.product || (products.find(p => Number(p.id) === Number(productId)) || null);
    const titleEl = $('batchesModalTitle');
    if (titleEl) titleEl.textContent = `دفعات — ${currentProduct ? currentProduct.name : ''}`;
    const prodNameEl = $('batchesProductName');
    if (prodNameEl) prodNameEl.textContent = currentProduct ? `${currentProduct.name} — ${currentProduct.product_code || ''}` : '';
    renderBatchesTable('all');
    const totalRemaining = batchesCache.reduce((s,b) => s + (b.status === 'active' ? parseFloat(b.remaining||0) : 0), 0);
    const totalValue = batchesCache.reduce((s,b) => s + (b.status === 'active' ? (parseFloat(b.remaining||0) * parseFloat(b.unit_cost||0)) : 0), 0);
    const totalsEl = $('batchesModalTotals');
    if (totalsEl) totalsEl.textContent = `المتبقي: ${formatNum(totalRemaining)} • قيمة: ${formatNum(totalValue)} ج`;
    const modal = $('batchesModal');
    if (modal) modal.style.display = 'flex';
  } catch (err) {
    console.error('openBatches', err);
    showToast('تعذر تحميل الدفعات', 'error');
  }
}

function renderBatchesTable(filter){
  const wrap = $('batchesTableWrap');
  if (!wrap) return;
  let rows = Array.isArray(batchesCache) ? batchesCache.slice() : [];
  if (filter && filter !== 'all') rows = rows.filter(r=> r.status === filter);
  if (!rows.length) { wrap.innerHTML = '<div class="small">لا توجد دفعات.</div>'; return; }
  let html = `<table class="my-table mb-1" style="width:100%;border-collapse:collapse"><thead><tr style="color:var(--muted)"><th>رقم الدفعة</th><th>المنتج</th><th>التاريخ</th><th>الكمية</th><th>المتبقي</th><th>سعر الشراء</th><th>سعر البيع</th><th>رقم الفاتورة المرتبطة</th><th>ملاحظات</th><th>سبب الإلغاء</th><th>سبب الإرجاع</th><th>الحالة</th><th>إجراءات</th></tr></thead><tbody>`;
  rows.forEach(b => {
    const stMap = { 'active':'فعال', 'consumed':'مستهلك', 'reverted':'مرجع', 'cancelled':'ملغى' };
    const stText = stMap[b.status] || b.status || '-';
    const stClass = b.status === 'active' ? 'badge green' : (b.status === 'cancelled' ? 'badge red' : (b.status === 'reverted' ? 'badge orange' : 'badge-muted'));
    const cancelReason = (b.status === 'cancelled' ? escapeHtml(b.cancel_reason || '-') : '-');
    const revertReason = (b.status === 'reverted' ? escapeHtml(b.revert_reason || '-') : '-');
    html += `<tr>
      <td class="monos">${escapeHtml(b.id)}</td>
      <td>${escapeHtml(currentProduct ? currentProduct.name : '')}</td>
      <td class="monos small ">${escapeHtml(b.received_at || b.created_at || '-')}</td>
      <td>${formatNum(b.qty || 0, {min:4,max:4})}</td>
      <td>${formatNum(b.remaining || 0, {min:4,max:4})}</td>
      <td class="monos">${formatNum(b.unit_cost || 0)}</td>
      <td class="monos">${formatNum(b.sale_price || 0)}</td>
      <td class="monos">${b.source_invoice_id ? escapeHtml(b.source_invoice_id) : '-'}</td>
      <td class="small">${escapeHtml(b.notes || '-')}</td>
      <td class="small">${cancelReason}</td>
      <td class="small">${revertReason}</td>
      <td>${'<span class="' + stClass + '">' + stText + '</span>'}</td>
      <td><button class="btn btn-ghost" onclick="openBatchDetail(${escapeHtml(b.id)})">عرض</button></td>
    </tr>`;
  });
  html += '</tbody></table>';
  wrap.innerHTML = html;
}

const batchFilterEl = $('batchFilter');
if (batchFilterEl) batchFilterEl.addEventListener('change', ()=> renderBatchesTable(batchFilterEl.value));
const closeBatchesBtn = $('closeBatchesBtn');
if (closeBatchesBtn) closeBatchesBtn.addEventListener('click', ()=> { const m = $('batchesModal'); if (m) m.style.display = 'none'; });

const batchPrintBtn = $('batchPrint');
if (batchPrintBtn) batchPrintBtn.addEventListener('click', ()=> {
  const content = $('batchesTableWrap') ? $('batchesTableWrap').innerHTML : '';
  const w = window.open('', '_blank', 'width=900,height=700');
  const css = `<style>body{font-family:Arial,Helvetica,sans-serif;direction:rtl;text-align:right} table{width:100%;border-collapse:collapse} th,td{border:1px solid #ddd;padding:6px}</style>`;
  w.document.write(`<html><head><title>طباعة الدفعات — ${currentProduct ? escapeHtml(currentProduct.name) : ''}</title>${css}</head><body><h3>دفعات ${currentProduct ? escapeHtml(currentProduct.name) : ''}</h3>` + content + `</body></html>`);
  w.document.close();
  setTimeout(()=> w.print(), 500);
});

/* Batch detail */
function openBatchDetail(batchId){
  const b = batchesCache.find(x => String(x.id) === String(batchId));
  if (!b) { showToast('دفعة غير موجودة', 'error'); return; }
  const modal = $('batchDetailModal');
  if (!modal) return;
  $('batchDetailTitle').textContent = 'دفعة #' + b.id + ' — تفاصيل';
  const stText = b.status === 'active' ? 'فعال' : b.status === 'consumed' ? 'مستهلك' : b.status === 'reverted' ? 'مرجع' : b.status === 'cancelled' ? 'ملغى' : b.status;
  let html = `<table style="width:100%"><tbody>
    <tr><td class="kv">رقم الدفعة</td><td class="monos">${escapeHtml(b.id)}</td></tr>
    <tr><td class="kv">المنتج</td><td>${escapeHtml(currentProduct ? currentProduct.name : '')}</td></tr>
    <tr><td class="kv">الكمية الأصلية</td><td>${formatNum(b.qty || 0, {min:4,max:4})}</td></tr>
    <tr><td class="kv">المتبقي</td><td>${formatNum(b.remaining || 0, {min:4,max:4})}</td></tr>
    <tr><td class="kv">سعر الشراء</td><td>${formatNum(b.unit_cost || 0)}</td></tr>
    <tr><td class="kv">سعر البيع (الدفعة)</td><td>${formatNum(b.sale_price || 0)}</td></tr>
    <tr><td class="kv">تاريخ الاستلام</td><td>${escapeHtml(b.received_at || b.created_at || '-')}</td></tr>
    <tr><td class="kv">تاريخ الانتهاء</td><td>${escapeHtml(b.expiry || '-')}</td></tr>
    <tr><td class="kv">رقم الفاتورة المرتبطة</td><td>${b.source_invoice_id ? escapeHtml(b.source_invoice_id) : '-'}</td></tr>
    <tr><td class="kv">ملاحظات</td><td>${escapeHtml(b.notes || '-')}</td></tr>
    <tr><td class="kv">سبب الإلغاء</td><td>${b.status === 'cancelled' ? escapeHtml(b.cancel_reason || '-') : '-'}</td></tr>
    <tr><td class="kv">سبب الإرجاع</td><td>${b.status === 'reverted' ? escapeHtml(b.revert_reason || '-') : '-'}</td></tr>
    <tr><td class="kv">الحالة</td><td>${escapeHtml(stText)}</td></tr>
  </tbody></table>`;
  $('batchDetailBody').innerHTML = html;
  modal.style.display = 'flex';
}
function closeBatchDetail(){ const m = $('batchDetailModal'); if (m) m.style.display = 'none'; }

/* =========================
   Initialization
   ========================= */
(async function init(){
  try {
    await syncConsumed();
  } catch(e){}
  await fetchProducts();
  // show PHP message if any (ensure server sets JS-friendly string)
  <?php if (!empty($message)): ?>
    try { showToast("<?php echo addslashes($message); ?>", 'success', 3500); } catch(e){}
  <?php endif; ?>
})();

/* Buttons handlers */
const addBtn = $('addProductBtn');
if (addBtn) addBtn.addEventListener('click', ()=> openProductModal(0));
</script>


<?php
// include sidebar as you requested
require_once BASE_DIR . 'partials/footer.php';
?>

