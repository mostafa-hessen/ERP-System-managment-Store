<?php
$page_title = "تفاصيل الفاتورة";
// $class1 = "active"; // تأكد من أن هذا المتغير يُستخدم بشكل صحيح في navbar.php

// تحديد المسار لـ config.php بشكل صحيح
if (file_exists(dirname(__DIR__) . '/config.php')) {
    require_once dirname(__DIR__) . '/config.php';
} else {
    // محاولة مسار بديل إذا كان الهيكل مختلفاً قليلاً (مثلاً، إذا كانت view.php في مجلد invoices وهو داخل مجلد رئيسي آخر)
    if (file_exists(dirname(dirname(__DIR__)) . '/config.php')) {
         require_once dirname(dirname(__DIR__)) . '/config.php';
    } else {
        die("ملف config.php غير موجود!");
    }
}

// التحقق الأساسي من تسجيل الدخول
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("Location: " . BASE_URL . "auth/login.php");
    exit;
}

// تحديد صلاحيات الوصول لهذه الصفحة (يمكن تعديلها حسب الحاجة)
// حالياً، سنفترض أن أي مستخدم مسجل يمكنه الوصول إذا كان هو منشئ الفاتورة أو مدير
// ولكن إضافة/حذف البنود ستكون مشروطة أكثر بالداخل


require_once BASE_DIR . 'partials/header.php';
require_once BASE_DIR . 'partials/navbar.php';

$message = "";
$invoice_id = 0;
$invoice_data = null;
$invoice_items = [];
$products_list = [];
$invoice_total_amount = 0;
$can_edit_invoice_header = false;
$can_manage_invoice_items = false;

// --- جلب الرسالة من الجلسة (إن وجدت) ---
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    unset($_SESSION['message']);
}

// جلب توكن CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// --- جلب ID الفاتورة من GET ---
if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $invoice_id = intval($_GET['id']);
} else {
    $_SESSION['message'] = "<div class='alert alert-danger'>رقم الفاتورة غير محدد أو غير صالح.</div>";
    header("Location: " . BASE_URL . (isset($_SESSION['role']) && $_SESSION['role'] == 'admin' ? 'admin/pending_invoices.php' : 'show_customer.php'));
    exit;
}

// --- أولاً: جلب بيانات الفاتورة الرئيسية لتحديد الصلاحيات ---
$sql_invoice_auth = "SELECT id, customer_id, delivered, invoice_group, created_at, updated_at, created_by FROM invoices_out WHERE id = ?";
if ($stmt_auth = $conn->prepare($sql_invoice_auth)) {
    $stmt_auth->bind_param("i", $invoice_id);
    if ($stmt_auth->execute()) {
        $result_auth = $stmt_auth->get_result();
        if ($result_auth->num_rows === 1) {
            $temp_invoice_data_for_auth = $result_auth->fetch_assoc(); // استخدام متغير مؤقت هنا

            if ($_SESSION['role'] !== 'admin' && $temp_invoice_data_for_auth['created_by'] !== $_SESSION['id']) {
                $_SESSION['message'] = "<div class='alert alert-danger'>ليس لديك الصلاحية لعرض هذه الفاتورة.</div>";
                header("Location: " . BASE_URL . 'show_customer.php');
                exit;
            }
            if (isset($_SESSION['role']) && $_SESSION['role'] == 'admin') {
                $can_edit_invoice_header = true;
            }
            if ((isset($_SESSION['role']) && $_SESSION['role'] == 'admin') || (isset($temp_invoice_data_for_auth['created_by']) && $temp_invoice_data_for_auth['created_by'] == $_SESSION['id'])) {
                $can_manage_invoice_items = true;
            }
        } else {
             $_SESSION['message'] = "<div class='alert alert-danger'>لم يتم العثور على الفاتورة المطلوبة (رقم: {$invoice_id}).</div>";
             header("Location: " . BASE_URL . (isset($_SESSION['role']) && $_SESSION['role'] == 'admin' ? 'admin/pending_invoices.php' : 'show_customer.php'));
             exit;
        }
    } else {
        $_SESSION['message'] = "<div class='alert alert-danger'>خطأ أثناء جلب بيانات الفاتورة للتحقق.</div>";
        header("Location: " . BASE_URL . (isset($_SESSION['role']) && $_SESSION['role'] == 'admin' ? 'admin/pending_invoices.php' : 'show_customer.php'));
        exit;
    }
    $stmt_auth->close();
} else {
    $_SESSION['message'] = "<div class='alert alert-danger'>خطأ في تحضير استعلام الفاتورة للتحقق.</div>";
    header("Location: " . BASE_URL . (isset($_SESSION['role']) && $_SESSION['role'] == 'admin' ? 'admin/pending_invoices.php' : 'show_customer.php'));
    exit;
}


// --- معالجة إضافة بند جديد للفاتورة ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_invoice_item'])) {
    if (!$can_manage_invoice_items) {
        $_SESSION['message'] = "<div class='alert alert-danger'>ليس لديك الصلاحية لإضافة بنود لهذه الفاتورة.</div>";
    } elseif (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['message'] = "<div class='alert alert-danger'>خطأ: طلب غير صالح (CSRF).</div>";
    } else {
        $product_id_to_add = intval($_POST['product_id']);
        $quantity_to_add = floatval($_POST['quantity']); // <-- تعديل لـ floatval
        $unit_price_to_add = floatval($_POST['unit_price']); // <-- تعديل لـ floatval

        if ($product_id_to_add <= 0) { $_SESSION['message'] = "<div class='alert alert-danger'>الرجاء اختيار منتج صحيح.</div>"; }
        elseif ($quantity_to_add <= 0) { $_SESSION['message'] = "<div class='alert alert-danger'>الرجاء إدخال كمية صحيحة (أكبر من صفر).</div>"; }
        elseif ($unit_price_to_add < 0) { $_SESSION['message'] = "<div class='alert alert-danger'>الرجاء إدخال سعر وحدة صحيح.</div>"; }
        else {
            $sql_check_stock = "SELECT name, current_stock FROM products WHERE id = ?";
            $stmt_check_stock = $conn->prepare($sql_check_stock);
            $stmt_check_stock->bind_param("i", $product_id_to_add);
            $stmt_check_stock->execute();
            $result_stock = $stmt_check_stock->get_result();
            $product_stock_data = $result_stock->fetch_assoc();
            $stmt_check_stock->close();

            if (!$product_stock_data) { $_SESSION['message'] = "<div class='alert alert-danger'>المنتج المختار غير موجود.</div>"; }
            elseif (floatval($product_stock_data['current_stock']) < $quantity_to_add) { // <-- تعديل لـ floatval
                $_SESSION['message'] = "<div class='alert alert-danger'>الكمية المطلوبة للمنتج \"".htmlspecialchars($product_stock_data['name'])."\" غير متوفرة. الرصيد الحالي: ".floatval($product_stock_data['current_stock']).".</div>";
            } else {
                $total_price_for_item = $quantity_to_add * $unit_price_to_add;
                $sql_insert_item = "INSERT INTO invoice_out_items (invoice_out_id, product_id, quantity, unit_price, total_price) VALUES (?, ?, ?, ?, ?)";
                $stmt_insert_item = $conn->prepare($sql_insert_item);
                // تعديل أنواع bind_param: i, i, d, d, d
                $stmt_insert_item->bind_param("iiddd", $invoice_id, $product_id_to_add, $quantity_to_add, $unit_price_to_add, $total_price_for_item); // <<< تغيير هنا

                if ($stmt_insert_item->execute()) {
                    $new_stock = floatval($product_stock_data['current_stock']) - $quantity_to_add; // <-- تعديل لـ floatval
                    $sql_update_stock = "UPDATE products SET current_stock = ? WHERE id = ?";
                    $stmt_update_stock = $conn->prepare($sql_update_stock);
                    // تعديل أنواع bind_param: d, i
                    $stmt_update_stock->bind_param("di", $new_stock, $product_id_to_add); // <<< تغيير هنا
                    $stmt_update_stock->execute();
                    $stmt_update_stock->close();
                    $_SESSION['message'] = "<div class='alert alert-success'>تم إضافة المنتج للفاتورة بنجاح.</div>";
                } else { $_SESSION['message'] = "<div class='alert alert-danger'>حدث خطأ أثناء إضافة المنتج للفاتورة: " . $stmt_insert_item->error . "</div>"; }
                $stmt_insert_item->close();
            }
        }
    }
    // تحديد مسار view.php بشكل صحيح، نفترض أنه في مجلد invoices
    header("Location: " . BASE_URL . "invoices_out/view.php?id=" . $invoice_id);
    exit;
}


// --- جلب بيانات الفاتورة الرئيسية والعميل (بعد التحقق من الصلاحيات) ---
$sql_complete_invoice_data = "SELECT i.id, i.customer_id, i.delivered, i.invoice_group, i.created_at, i.updated_at, i.created_by,
                   c.name as customer_name, c.mobile as customer_mobile, c.address as customer_address, c.city as customer_city,
                   u_creator.username as creator_name
            FROM invoices_out i
            JOIN customers c ON i.customer_id = c.id
            LEFT JOIN users u_creator ON i.created_by = u_creator.id
            WHERE i.id = ?";
if($stmt_complete = $conn->prepare($sql_complete_invoice_data)) {
    $stmt_complete->bind_param("i", $invoice_id);
    $stmt_complete->execute();
    $result_complete = $stmt_complete->get_result();
    if($result_complete->num_rows === 1) {
        $invoice_data = $result_complete->fetch_assoc();
    } else {
        // هذا لا يجب أن يحدث إذا مر التحقق الأولي من الصلاحية
        $invoice_data = null;
        if(empty($message)) $message = "<div class='alert alert-danger'>خطأ: الفاتورة غير موجودة بعد التحقق من الصلاحية.</div>";
    }
    $stmt_complete->close();
} else {
    $invoice_data = null;
    $message = "<div class='alert alert-danger'>خطأ في تحضير استعلام بيانات الفاتورة الكاملة.</div>";
}

// جلب بنود الفاتورة فقط إذا تم العثور على بيانات الفاتورة الرئيسية
if ($invoice_data) {
    $sql_items = "SELECT item.id as item_id, item.product_id, item.quantity, item.unit_price, item.total_price,
                         p.product_code, p.name as product_name, p.unit_of_measure
                  FROM invoice_out_items item
                  JOIN products p ON item.product_id = p.id
                  WHERE item.invoice_out_id = ?
                  ORDER BY item.id ASC";
    if ($stmt_items = $conn->prepare($sql_items)) {
        $stmt_items->bind_param("i", $invoice_id);
        $stmt_items->execute();
        $result_items = $stmt_items->get_result();
        while ($row_item = $result_items->fetch_assoc()) {
            $invoice_items[] = $row_item;
            $invoice_total_amount += floatval($row_item['total_price']); // <-- تعديل لـ floatval
        }
        $stmt_items->close();
    } else { $message .= "<div class='alert alert-warning'>خطأ في جلب بنود الفاتورة: " . $conn->error . "</div>"; }

    if ($can_manage_invoice_items) {
        $sql_products = "SELECT id, product_code, name, current_stock, unit_of_measure FROM products WHERE current_stock > 0 ORDER BY name ASC";
        $result_products_query = $conn->query($sql_products); // استخدام متغير مختلف لتجنب التعارض
        if ($result_products_query) {
            while ($row_prod = $result_products_query->fetch_assoc()) {
                $products_list[] = $row_prod;
            }
        } else {
             $message .= "<div class='alert alert-warning'>خطأ في جلب قائمة المنتجات: " . $conn->error . "</div>";
        }
    }
}

// تحديد مسار لزر تعديل الفاتورة الرئيسية (نفترض أنه في مجلد admin)
$edit_invoice_main_link = BASE_URL . "invoices_out/edit.php?id=" . $invoice_id;
// تحديد مسار لحذف بند الفاتورة (نفترض أنه في مجلد invoices)
$delete_item_link = BASE_URL . "invoices_out/delete_invoice_item.php";

?>

<div class="container mt-5 pt-3">

    <?php echo $message; ?>

    <?php if ($invoice_data): ?>
        <div class="card shadow-lg mb-4">
            <div class="card-header bg-dark text-white d-flex flex-column flex-md-row justify-content-between align-items-center">
                <h3 class="mb-2 mb-md-0"><i class="fas fa-file-invoice"></i> تفاصيل الفاتورة رقم: #<?php echo $invoice_data['id']; ?></h3>
                <div>
                    <?php if ($can_edit_invoice_header): ?>
                        <a href="<?php echo $edit_invoice_main_link; ?>" class="btn btn-warning btn-sm me-2"><i class="fas fa-edit"></i> تعديل بيانات الفاتورة</a>
                    <?php endif; ?>
                    <a href="#" onclick="window.print(); return false;" class="btn btn-secondary btn-sm"><i class="fas fa-print"></i> طباعة</a>                </div>
            </div>
            <div class="card-body p-4">
                <div class="row">
                    <div class="col-lg-6 mb-4" id="invoiceHeaderInfoCard">
                        <div class="card h-100">
                            <div class="card-header"><i class="fas fa-info-circle"></i> معلومات الفاتورة</div>
                            <ul class="list-group list-group-flush">
                                <li class="list-group-item"><b>رقم الفاتورة:</b> <?php echo $invoice_data['id']; ?></li>
                                <li class="list-group-item"><b>المجموعة:</b> <?php echo htmlspecialchars($invoice_data['invoice_group']); ?></li>
                                <li class="list-group-item"><b>حالة التسليم:</b>
                                    <span class="badge <?php echo ($invoice_data['delivered'] == 'yes') ? 'bg-success' : 'bg-danger'; ?>">
                                        <?php echo ($invoice_data['delivered'] == 'yes') ? 'تم التسليم' : 'لم يتم التسليم'; ?>
                                    </span>
                                </li>
                                <li class="list-group-item"><b>تاريخ الإنشاء:</b> <?php echo date('Y-m-d H:i A', strtotime($invoice_data['created_at'])); ?></li>
                                <li class="list-group-item"><b>تم الإنشاء بواسطة:</b> <?php echo htmlspecialchars($invoice_data['creator_name'] ?? 'غير معروف'); ?></li>
                                <li class="list-group-item"><b>آخر تحديث لبيانات الفاتورة:</b> <?php echo !empty($invoice_data['updated_at']) ? date('Y-m-d H:i A', strtotime($invoice_data['updated_at'])) : '-'; ?></li>
                            </ul>
                        </div>
                    </div>
                    <div class="col-lg-6 mb-4">
                         <div class="card h-100">
                            <div class="card-header"><i class="fas fa-user-tag"></i> معلومات العميل</div>
                            <ul class="list-group list-group-flush">
                                <li class="list-group-item"><b>الاسم:</b> <?php echo htmlspecialchars($invoice_data['customer_name']); ?></li>
                                <li class="list-group-item"><b>الموبايل:</b> <?php echo htmlspecialchars($invoice_data['customer_mobile']); ?></li>
                                <li class="list-group-item"><b>المدينة:</b> <?php echo htmlspecialchars($invoice_data['customer_city']); ?></li>
                                <li class="list-group-item"><b>العنوان:</b> <?php echo !empty($invoice_data['customer_address']) ? htmlspecialchars($invoice_data['customer_address']) : '-'; ?></li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow-lg mb-4">
            <div class="card-header bg-light d-flex justify-content-between align-items-center">
                <h4><i class="fas fa-box-open"></i> بنود الفاتورة</h4>
            </div>
            <div class="card-body p-0">
                <?php if (!empty($invoice_items)): ?>
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
                                    <?php if ($can_manage_invoice_items && $invoice_data['delivered'] == 'no'): ?>
                                    <th class="text-center" id="invoiceItemActionsHeader">إجراء</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $item_counter = 1; ?>
                                <?php foreach ($invoice_items as $item): ?>
                                    <tr>
                                        <td><?php echo $item_counter++; ?></td>
                                        <td><?php echo htmlspecialchars($item['product_code']); ?></td>
                                        <td><?php echo htmlspecialchars($item['product_name']); ?> (<?php echo htmlspecialchars($item['unit_of_measure']); ?>)</td>
                                        <td class="text-center"><?php echo number_format(floatval($item['quantity']), 2); // <-- عرض عشري ?></td>
                                        <td class="text-end"><?php echo number_format(floatval($item['unit_price']), 2); ?> ج.م</td>
                                        <td class="text-end fw-bold"><?php echo number_format(floatval($item['total_price']), 2); ?> ج.م</td>
                                        <?php if ($can_manage_invoice_items && $invoice_data['delivered'] == 'no'): ?>
                                        <td class="text-center invoice-item-actions-cell">
                                            <form action="<?php echo $delete_item_link; ?>" method="post" class="d-inline" onsubmit="return confirm('هل أنت متأكد من حذف هذا البند؟ سيتم إعادة الكمية للمخزون.');">
                                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                                <input type="hidden" name="item_id_to_delete" value="<?php echo $item['item_id']; ?>">
                                                <input type="hidden" name="invoice_id" value="<?php echo $invoice_id; ?>">
                                                <input type="hidden" name="product_id_to_return" value="<?php echo $item['product_id']; ?>">
                                                <input type="hidden" name="quantity_to_return" value="<?php echo floatval($item['quantity']); // <-- تعديل لـ floatval ?>">
                                                <button type="submit" name="delete_item" class="btn btn-danger btn-sm" title="حذف البند">
                                                    <i class="fas fa-trash-alt"></i>
                                                </button>
                                            </form>
                                        </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr class="table-light">
                                    <td colspan="<?php echo ($can_manage_invoice_items && $invoice_data['delivered'] == 'no') ? '5' : '5'; ?>" class="text-end fw-bold fs-5">الإجمالي الكلي للفاتورة:</td>
                                    <td class="text-end fw-bold fs-5"><?php echo number_format(floatval($invoice_total_amount), 2); // <-- تعديل لـ floatval ?> ج.م</td>
                                    <?php if ($can_manage_invoice_items && $invoice_data['delivered'] == 'no'): ?>
                                    <td invoiceItemActionsFooter></td>
                                    <?php endif; ?>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="text-center p-3">لا توجد بنود في هذه الفاتورة حتى الآن.</p>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($can_manage_invoice_items && $invoice_data['delivered'] == 'no'): ?>
        <div class="card shadow-lg mt-4">
            <div class="card-header bg-success text-white">
                <h4><i class="fas fa-cart-plus"></i> إضافة منتج جديد للفاتورة</h4>
            </div>
            <div class="card-body p-4">
                <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>?id=<?php echo $invoice_id; ?>" method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <div class="row">
                        <div class="col-md-5 mb-3">
                            <label for="product_id" class="form-label">اختر المنتج:</label>
                            <select name="product_id" id="product_id" class="form-select" required onchange="updateUnitPriceAndStock(this)">
                                <option value="">-- اختر منتجاً --</option>
                                <?php if (!empty($products_list)): ?>
                                    <?php foreach ($products_list as $product): ?>
                                        <option value="<?php echo $product['id']; ?>" data-stock="<?php echo floatval($product['current_stock']); // <-- تعديل لـ floatval ?>" data-unit="<?php echo htmlspecialchars($product['unit_of_measure']); ?>">
                                            <?php echo htmlspecialchars($product['product_code']); ?> - <?php echo htmlspecialchars($product['name']); ?> (الرصيد: <?php echo floatval($product['current_stock']); // <-- تعديل لـ floatval ?>)
                                        </option>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <option value="" disabled>لا توجد منتجات متاحة للإضافة (أو رصيدها صفر)</option>
                                <?php endif; ?>
                            </select>

                            
                        </div>
                        <div class="col-md-2 mb-3">
                            <label for="quantity" class="form-label">الكمية:</label>
                            <input type="number" name="quantity" id="quantity" class="form-control" step="0.01" min="0.01" value="1.00" required>
                            <small id="unit_display" class="form-text text-muted"></small>
                            <small id="stock_warning" class="form-text text-danger d-none">الكمية المطلوبة أكبر من الرصيد المتاح!</small>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="unit_price" class="form-label">سعر الوحدة:</label>
                            <input type="number" name="unit_price" id="unit_price" class="form-control" step="0.01" min="0" value="0.00" required>
                        </div>
                        <div class="col-md-2 mb-3 d-flex align-items-end">
                            <button type="submit" name="add_invoice_item" id="add_item_btn" class="btn btn-success w-100"><i class="fas fa-plus"></i> إضافة</button>
                        </div>
                    </div>

                    <!-- <input type="hidden" name="product_id" id="product_id" required>
<div class="mb-3 position-relative">
  <label for="product_search" class="form-label">ابحث عن المنتج (بالكود أو الاسم):</label>
  <input type="text" id="product_search" class="form-control" placeholder="اكتب للبحث..." autocomplete="off">
  <div id="search_results" class="list-group position-absolute w-100" style="z-index:1000;"></div>
</div> -->
<!-- 
<div class="row">
  <div class="col-md-2 mb-3">
    <label for="quantity" class="form-label">الكمية:</label>
    <input type="number" name="quantity" id="quantity" class="form-control" step="0.01" min="0.01" value="1.00" required>
    <small id="unit_display" class="form-text text-muted"></small>
    <small id="stock_warning" class="form-text text-danger d-none"></small>
  </div>
  <div class="col-md-3 mb-3">
    <label for="unit_price" class="form-label">سعر الوحدة:</label>
    <input type="number" name="unit_price" id="unit_price" class="form-control" step="0.01" min="0" value="0.00" required>
  </div>
  <div class="col-md-2 mb-3 d-flex align-items-end">
    <button type="submit" name="add_invoice_item" id="add_item_btn" class="btn btn-success w-100"><i class="fas fa-plus"></i> إضافة</button>
  </div>
</div> -->

                </form>
            </div>
        </div>
        <?php endif; ?>

        <div class="card-footer text-muted text-center mt-4">
             <a href="<?php echo BASE_URL . (isset($_SESSION['role']) && $_SESSION['role'] == 'admin' ? 'admin/pending_invoices.php' : 'show_customer.php'); ?>" class="btn btn-outline-secondary"><i class="fas fa-arrow-left"></i> العودة للقائمة</a>
        </div>

    <?php elseif(empty($message)): ?>
        <div class="alert alert-warning text-center">لم يتم العثور على الفاتورة المطلوبة أو ليس لديك صلاحية لعرضها.
            <a href="<?php echo BASE_URL . (isset($_SESSION['role']) && $_SESSION['role'] == 'admin' ? 'admin/pending_invoices.php' : 'show_customer.php'); ?>">العودة للقائمة</a>.
        </div>
    <?php endif; ?>

</div>
<script>
function updateUnitPriceAndStock(selectElement) {
    const selectedOption = selectElement.options[selectElement.selectedIndex];
    const stock = parseFloat(selectedOption.getAttribute('data-stock'));
    const unit = selectedOption.getAttribute('data-unit');
    const quantityInput = document.getElementById('quantity');
    const stockWarning = document.getElementById('stock_warning');
    const addItemBtn = document.getElementById('add_item_btn');

    quantityInput.max = stock.toFixed(2); // تحديد الحد الأقصى للكمية مع كسور
    document.getElementById('unit_display').textContent = 'وحدة القياس: ' + (unit || '');

    if (stock === 0) {
        stockWarning.textContent = 'تنبيه: رصيد هذا المنتج هو صفر!';
        stockWarning.classList.remove('d-none');
        addItemBtn.disabled = true;
        quantityInput.value = ''; // أفرغ الكمية
    } else {
        stockWarning.classList.add('d-none');
        addItemBtn.disabled = false;
    }

    quantityInput.oninput = function() {
        const currentQuantity = parseFloat(this.value);
        if (currentQuantity > stock) {
            stockWarning.textContent = 'الكمية المطلوبة ('+ this.value +') أكبر من الرصيد ('+ stock.toFixed(2) +')!';
            stockWarning.classList.remove('d-none');
            addItemBtn.disabled = true;
        } else if (currentQuantity <= 0 && this.value !== "") {
             stockWarning.textContent = 'الكمية يجب أن تكون أكبر من صفر.';
             stockWarning.classList.remove('d-none');
             addItemBtn.disabled = true;
        } else {
            stockWarning.classList.add('d-none');
            addItemBtn.disabled = false;
        }
    };
    // استدعاء oninput يدوياً للتحقق من القيمة الأولية للكمية إذا كانت 1 والرصيد 0
    if (quantityInput.value) {
      const event = new Event('input', { bubbles: true });
      quantityInput.dispatchEvent(event);
    }
}


// <script> اول كود البحث
const searchInput = document.getElementById("product_search");
const resultsDiv  = document.getElementById("search_results");
const hiddenInput = document.getElementById("product_id");
const quantityInput = document.getElementById("quantity");
const unitDisplay = document.getElementById("unit_display");
const stockWarning = document.getElementById("stock_warning");
const addItemBtn = document.getElementById("add_item_btn");

let selectedStock = 0;

searchInput.addEventListener("keyup", function() {
    let term = this.value.trim();
    if (term?.length < 2) {
        resultsDiv.innerHTML = "";
        return;
    }

    fetch("ajax_search_products.php?term=" + encodeURIComponent(term))
        .then(res => res.json())
        .then(data => {
            resultsDiv.innerHTML = "";
            data.forEach(item => {
                let option = document.createElement("a");
                option.href = "#";
                option.classList.add("list-group-item", "list-group-item-action");
                option.textContent = item.label;

                option.onclick = function(e) {
                    e.preventDefault();
                    searchInput.value = item.label;
                    hiddenInput.value = item.id;
                    selectedStock = parseFloat(item.stock);
                    unitDisplay.textContent = "الوحدة: " + item.unit + " | الرصيد: " + selectedStock;

                    resultsDiv.innerHTML = "";
                };

                resultsDiv.appendChild(option);
            });
        });
});

quantityInput.addEventListener("input", function() {
    const currentQuantity = parseFloat(this.value);
    if (currentQuantity > selectedStock) {
        stockWarning.textContent = "الكمية المطلوبة ("+ this.value +") أكبر من الرصيد ("+ selectedStock +")!";
        stockWarning.classList.remove("d-none");
        addItemBtn.disabled = true;
    } else if (currentQuantity <= 0 && this.value !== "") {
        stockWarning.textContent = "الكمية يجب أن تكون أكبر من صفر.";
        stockWarning.classList.remove("d-none");
        addItemBtn.disabled = true;
    } else {
        stockWarning.classList.add("d-none");
        addItemBtn.disabled = false;
    }
});
// اخر كود البحث

// استدعاء الدالة عند تحميل الصفحة للتأكد من حالة زر الإضافة إذا كان المنتج الأول رصيده صفر
document.addEventListener('DOMContentLoaded', function() {
    const productSelect = document.getElementById('product_id');
    if (productSelect && productSelect.value) { // تأكد أن productSelect موجود وأن هناك قيمة مختارة (عادة لا يكون عند التحميل الأول)
        // إذا أردت تحديث الحالة بناءً على المنتج المختار افتراضياً (إذا وجد)
        // updateUnitPriceAndStock(productSelect);
    } else if (productSelect && productSelect.options.length > 1 && productSelect.options[1]) {
        // تحقق من المنتج الأول في القائمة إذا لم يكن هناك شيء محدد
        const firstProductStock = parseFloat(productSelect.options[1].getAttribute('data-stock'));
        if (firstProductStock === 0 && productSelect.selectedIndex <=0) { // إذا لم يتم اختيار شيء أو تم اختيار "--اختر--"
             // يمكن تعطيل زر الإضافة مبدئياً إذا كان أول منتج متاح رصيده صفر ولم يتم اختيار شيء
             // لكن من الأفضل تركه لـ onchange ليعمل عند اختيار المستخدم
        }
    }
});
</script>
<?php
$conn->close();
require_once BASE_DIR . 'partials/footer.php';
?>