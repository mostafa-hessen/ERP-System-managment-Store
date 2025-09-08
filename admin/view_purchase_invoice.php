<?php
// view_purchase_invoice.php (معدل لتسهيل إضافة بنود + بحث منتجات + تغيير حالة الفاتورة)
$page_title = "تفاصيل فاتورة المشتريات";
$class_dashboard = "active";
require_once dirname(__DIR__) . '/config.php';
require_once BASE_DIR . 'partials/session_admin.php';
require_once BASE_DIR . 'partials/header.php';
require_once BASE_DIR . 'partials/sidebar.php';

$message = "";
$invoice_id = 0;
$purchase_invoice_data = null;
$purchase_invoice_items = [];
$products_list = [];
$invoice_total_calculated = 0;

// --- ترجمة الحالة ---
$status_labels = [
    'pending' => 'قيد الانتظار',
    'partial_received' => 'تم الاستلام جزئياً',
    'fully_received' => 'تم الاستلام بالكامل',
    'cancelled' => 'ملغاة'
];

// جلب رسالة من الجلسة (PRG)
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    unset($_SESSION['message']);
}

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// التحقق من id الفاتورة
if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $invoice_id = intval($_GET['id']);
} else {
    $_SESSION['message'] = "<div class='alert alert-danger'>رقم فاتورة المشتريات غير محدد أو غير صالح.</div>";
    header("Location: " . BASE_URL . "admin/manage_suppliers.php");
    exit;
}

/* ---------------------------------------------------------
   معالجة طلبات POST:
   - تغيير حالة الفاتورة (set_status)
   - إضافة بند جديد (add_purchase_item)
   ---------------------------------------------------------*/

// 1) تغيير حالة الفاتورة (مثلاً: إلى fully_received => نحدّث كميات المنتجات إذا لم تُحدَّث مسبقاً)
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['set_status'])) {
    // تحقّق CSRF و صلاحية
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['message'] = "<div class='alert alert-danger'>خطأ: طلب غير صالح (CSRF).</div>";
    } elseif (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
        $_SESSION['message'] = "<div class='alert alert-danger'>لا تملك صلاحية تغيير حالة الفاتورة.</div>";
    } else {
        $new_status = in_array($_POST['new_status'], array_keys($status_labels)) ? $_POST['new_status'] : null;
        if (!$new_status) {
            $_SESSION['message'] = "<div class='alert alert-warning'>حالة غير صحيحة.</div>";
        } else {
            // جلب الحالة الحالية
            $sql_old = "SELECT status FROM purchase_invoices WHERE id = ?";
            if ($stmt_old = $conn->prepare($sql_old)) {
                $stmt_old->bind_param("i", $invoice_id);
                $stmt_old->execute();
                $res_old = $stmt_old->get_result();
                $old_row = $res_old->fetch_assoc();
                $old_status = $old_row['status'] ?? null;
                $stmt_old->close();

                // إذا التحويل إلى fully_received من حالة ليست fully_received -> يجب تحديث أرصدة المنتجات
                $need_apply_stock = ($new_status === 'fully_received' && $old_status !== 'fully_received');

                $conn->begin_transaction();
                try {
                    $sql_update_invoice = "UPDATE purchase_invoices SET status = ?, updated_at = NOW(), updated_by = ? WHERE id = ?";
                    $updated_by = intval($_SESSION['id'] ?? 0);
                    if ($stmt_up = $conn->prepare($sql_update_invoice)) {
                        $stmt_up->bind_param("sii", $new_status, $updated_by, $invoice_id);
                        $stmt_up->execute();
                        $stmt_up->close();
                    } else {
                        throw new Exception("خطأ في تحضير استعلام تحديث حالة الفاتورة: " . $conn->error);
                    }

                    if ($need_apply_stock) {
                        // جلب كل البنود ثم تحديث المخزون وتحديث cost_price إذا لزم
                        $sql_items_apply = "SELECT product_id, quantity, cost_price_per_unit FROM purchase_invoice_items WHERE purchase_invoice_id = ?";
                        $stmt_items_apply = $conn->prepare($sql_items_apply);
                        $stmt_items_apply->bind_param("i", $invoice_id);
                        $stmt_items_apply->execute();
                        $res_items_apply = $stmt_items_apply->get_result();
                        $stmt_items_apply->close();

                        // تحضير استعلامات تحديث
                        $sql_update_stock = "UPDATE products SET current_stock = current_stock + ? WHERE id = ?";
                        $stmt_update_stock = $conn->prepare($sql_update_stock);
                        $sql_update_cost = "UPDATE products SET cost_price = ? WHERE id = ?";
                        $stmt_update_cost = $conn->prepare($sql_update_cost);

                        while ($rw = $res_items_apply->fetch_assoc()) {
                            $pid = intval($rw['product_id']);
                            $qty = floatval($rw['quantity']);
                            $cprice = floatval($rw['cost_price_per_unit']);

                            // تحديث الكمية
                            $stmt_update_stock->bind_param("di", $qty, $pid);
                            $stmt_update_stock->execute();

                            // تحديث آخر تكلفة كـ cost_price
                            $stmt_update_cost->bind_param("di", $cprice, $pid);
                            $stmt_update_cost->execute();
                        }
                        $stmt_update_stock->close();
                        $stmt_update_cost->close();
                    }

                    $conn->commit();
                    $_SESSION['message'] = "<div class='alert alert-success'>تم تغيير حالة الفاتورة إلى: {$status_labels[$new_status]}</div>";
                } catch (Exception $e) {
                    $conn->rollback();
                    $_SESSION['message'] = "<div class='alert alert-danger'>فشل تغيير الحالة: " . htmlspecialchars($e->getMessage()) . "</div>";
                }
            } else {
                $_SESSION['message'] = "<div class='alert alert-danger'>خطأ في جلب حالة الفاتورة: " . $conn->error . "</div>";
            }
        }
    }
    header("Location: " . BASE_URL . "admin/view_purchase_invoice.php?id=" . $invoice_id);
    exit;
}

// 2) إضافة بند جديد (مُحدّث): يسمح أيضاً بتحديث selling_price في products إذا طلب المستخدم ذلك
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['add_purchase_item'])) {
    // أولًا تحقق الحالة الحالية للفاتورة
    $sql_check_invoice_status_for_add = "SELECT status FROM purchase_invoices WHERE id = ?";
    $stmt_check_status_add = $conn->prepare($sql_check_invoice_status_for_add);
    $stmt_check_status_add->bind_param("i", $invoice_id);
    $stmt_check_status_add->execute();
    $res_status_add = $stmt_check_status_add->get_result();
    $invoice_status_data_add = $res_status_add->fetch_assoc();
    $stmt_check_status_add->close();

    if (!$invoice_status_data_add || $invoice_status_data_add['status'] === 'cancelled') {
        $_SESSION['message'] = "<div class='alert alert-warning'>لا يمكن إضافة بنود لهذه الفاتورة لأنها ملغاة أو غير موجودة.</div>";
    } elseif (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['message'] = "<div class='alert alert-danger'>خطأ: طلب غير صالح (CSRF).</div>";
    } else {
        $product_id_to_add = intval($_POST['product_id']);
        $quantity_received = floatval($_POST['quantity']);
        $cost_price_to_add = floatval($_POST['cost_price_per_unit']);
        $selling_price_provided = isset($_POST['selling_price']) ? floatval($_POST['selling_price']) : null;
        $update_selling_price = isset($_POST['update_selling_price']) && $_POST['update_selling_price'] === '1' ? true : false;

        // validations
        if ($product_id_to_add <= 0) {
            $_SESSION['message'] = "<div class='alert alert-danger'>الرجاء اختيار منتج صحيح.</div>";
        } elseif ($quantity_received <= 0) {
            $_SESSION['message'] = "<div class='alert alert-danger'>الرجاء إدخال كمية صحيحة أكبر من صفر.</div>";
        } elseif ($cost_price_to_add < 0) {
            $_SESSION['message'] = "<div class='alert alert-danger'>الرجاء إدخال سعر تكلفة صحيح.</div>";
        } else {
            $conn->begin_transaction();
            try {
                $total_cost_for_item = $quantity_received * $cost_price_to_add;
                $sql_insert_item = "INSERT INTO purchase_invoice_items
                                    (purchase_invoice_id, product_id, quantity, cost_price_per_unit, total_cost)
                                    VALUES (?, ?, ?, ?, ?)";
                $stmt_insert_item = $conn->prepare($sql_insert_item);
                if (!$stmt_insert_item) throw new Exception("خطأ تحضير إدخال البند: " . $conn->error);
                $stmt_insert_item->bind_param("iiddd", $invoice_id, $product_id_to_add, $quantity_received, $cost_price_to_add, $total_cost_for_item);
                $stmt_insert_item->execute();
                $stmt_insert_item->close();

                // تحديث المخزون و cost_price إذا كانت الفاتورة بالفعل fully_received
                if ($invoice_status_data_add['status'] === 'fully_received') {
                    $sql_update_stock = "UPDATE products SET current_stock = current_stock + ?, cost_price = ? WHERE id = ?";
                    $stmt_update_stock = $conn->prepare($sql_update_stock);
                    $stmt_update_stock->bind_param("dii", $quantity_received, $cost_price_to_add, $product_id_to_add);
                    $stmt_update_stock->execute();
                    $stmt_update_stock->close();
                }

                // تحديث سعر البيع في products إذا اختار المستخدم ذلك
                if ($update_selling_price && $selling_price_provided !== null && $selling_price_provided >= 0) {
                    $sql_update_selling = "UPDATE products SET selling_price = ? WHERE id = ?";
                    $stmt_up_selling = $conn->prepare($sql_update_selling);
                    $stmt_up_selling->bind_param("di", $selling_price_provided, $product_id_to_add);
                    $stmt_up_selling->execute();
                    $stmt_up_selling->close();
                }

                // إعادة حساب إجمالي الفاتورة
                $sql_sum_total = "SELECT SUM(total_cost) AS grand_total FROM purchase_invoice_items WHERE purchase_invoice_id = ?";
                $stmt_sum = $conn->prepare($sql_sum_total);
                $stmt_sum->bind_param("i", $invoice_id);
                $stmt_sum->execute();
                $result_sum = $stmt_sum->get_result();
                $row_sum = $result_sum->fetch_assoc();
                $invoice_total_calculated = floatval($row_sum['grand_total'] ?? 0);
                $stmt_sum->close();

                $sql_update_invoice_total = "UPDATE purchase_invoices SET total_amount = ? WHERE id = ?";
                $stmt_update_invoice_total = $conn->prepare($sql_update_invoice_total);
                $stmt_update_invoice_total->bind_param("di", $invoice_total_calculated, $invoice_id);
                $stmt_update_invoice_total->execute();
                $stmt_update_invoice_total->close();

                $conn->commit();
                $_SESSION['message'] = "<div class='alert alert-success'>تم إضافة البند بنجاح.</div>";
            } catch (Exception $ex) {
                $conn->rollback();
                $_SESSION['message'] = "<div class='alert alert-danger'>فشلت العملية: " . htmlspecialchars($ex->getMessage()) . "</div>";
            }
        }
    }
    header("Location: " . BASE_URL . "admin/view_purchase_invoice.php?id=" . $invoice_id);
    exit;
}

/* ---------------------------------------------------------
   جلب بيانات الفاتورة و البنود و قائمة المنتجات (للبحث client-side)
   ---------------------------------------------------------*/

// جلب رأس الفاتورة
$sql_invoice_header = "SELECT pi.*, s.name as supplier_name, u.username as creator_name
                       FROM purchase_invoices pi
                       JOIN suppliers s ON pi.supplier_id = s.id
                       LEFT JOIN users u ON pi.created_by = u.id
                       WHERE pi.id = ?";
$stmt_header = $conn->prepare($sql_invoice_header);
$stmt_header->bind_param("i", $invoice_id);
$stmt_header->execute();
$result_header = $stmt_header->get_result();
if ($result_header->num_rows === 1) {
    $purchase_invoice_data = $result_header->fetch_assoc();
} else {
    if (empty($message)) $message = "<div class='alert alert-danger'>لم يتم العثور على فاتورة المشتريات (رقم: {$invoice_id}).</div>";
}
$stmt_header->close();

// جلب البنود
if ($purchase_invoice_data) {
    $sql_items = "SELECT p_item.id as item_id_pk, p_item.product_id, p_item.quantity, p_item.cost_price_per_unit, p_item.total_cost,
                         p.product_code, p.name as product_name, p.unit_of_measure
                  FROM purchase_invoice_items p_item
                  JOIN products p ON p_item.product_id = p.id
                  WHERE p_item.purchase_invoice_id = ?
                  ORDER BY p_item.id ASC";
    $stmt_items = $conn->prepare($sql_items);
    $stmt_items->bind_param("i", $invoice_id);
    $stmt_items->execute();
    $result_items = $stmt_items->get_result();
    while ($row_item = $result_items->fetch_assoc()) {
        $purchase_invoice_items[] = $row_item;
        $invoice_total_calculated += floatval($row_item['total_cost']);
    }
    $stmt_items->close();

    // جلب قائمة المنتجات كاملة (سنستخدمها في JS كـ JSON للبحث السريع)
    $sql_products = "SELECT id, product_code, name, unit_of_measure, current_stock, cost_price, selling_price FROM products ORDER BY name ASC";
    $result_products_query = $conn->query($sql_products);
    if ($result_products_query) {
        while ($row_prod = $result_products_query->fetch_assoc()) {
            $products_list[] = $row_prod;
        }
    } else {
        $message .= "<div class='alert alert-warning'>خطأ في جلب قائمة المنتجات: " . htmlspecialchars($conn->error) . "</div>";
    }
}

// روابط
$edit_purchase_invoice_header_link = BASE_URL . "admin/edit_purchase_invoice.php?id=" . $invoice_id;
$manage_suppliers_link = BASE_URL . "admin/manage_suppliers.php";
$delete_purchase_item_link = BASE_URL . "admin/delete_purchase_item.php";

?>

<div class="container mt-5 pt-3">
    <?php echo $message; ?>

    <?php if ($purchase_invoice_data): ?>
        <div class="card shadow-lg mb-4">
            <div class="card-header d-flex flex-column flex-md-row justify-content-between align-items-center" style="background:var(--grad-1); color:white;">
                <h3 class="mb-2 mb-md-0"><i class="fas fa-receipt"></i> تفاصيل فاتورة المشتريات رقم: #<?php echo $purchase_invoice_data['id']; ?></h3>
                <div class="d-flex gap-2">
                    <!-- زر تعديل رأس الفاتورة -->
                    <a href="<?php echo $edit_purchase_invoice_header_link; ?>" class="btn btn-warning btn-sm"><i class="fas fa-edit"></i> تعديل</a>

                    <!-- أزرار الحالة الصغيرة (pending / fully_received / cancelled) -->
                    <form method="post" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="new_status" value="pending">
                        <button type="submit" name="set_status" class="btn btn-outline-light btn-sm" title="قيد الانتظار" onclick="return confirm('هل تود وضع الفاتورة كقيد انتظار؟');">
                            <i class="fas fa-hourglass-start"></i>
                        </button>
                    </form>
                    <form method="post" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="new_status" value="fully_received">
                        <button type="submit" name="set_status" class="btn btn-light btn-sm" title="تم الاستلام بالكامل" onclick="return confirm('عند تحويل الفاتورة إلى (تم الاستلام بالكامل) سيتم إضافة الأرصدة للمخزون إذا لم تُطبّق من قبل. استمر؟');">
                            <i class="fas fa-check-double"></i>
                        </button>
                    </form>
                    <form method="post" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="new_status" value="cancelled">
                        <button type="submit" name="set_status" class="btn btn-outline-danger btn-sm" title="ملغاة" onclick="return confirm('هل تريد إلغاء هذه الفاتورة؟');">
                            <i class="fas fa-times"></i>
                        </button>
                    </form>

                    <a href="#" onclick="window.print(); return false;" class="btn btn-secondary btn-sm"><i class="fas fa-print"></i> طباعة</a>
                </div>
            </div>

            <div class="card-body p-4">
                <div class="row">
                    <div class="col-lg-6 mb-3">
                        <h5>بيانات الفاتورة:</h5>
                        <ul class="list-unstyled">
                            <li><strong>رقم فاتورة المورد:</strong> <?php echo htmlspecialchars($purchase_invoice_data['supplier_invoice_number'] ?: '-'); ?></li>
                            <li><strong>تاريخ الشراء:</strong> <?php echo date('Y-m-d', strtotime($purchase_invoice_data['purchase_date'])); ?></li>
                            <li><strong>الحالة:</strong>
                                <span class="badge <?php
                                    switch($purchase_invoice_data['status']){
                                        case 'pending': echo 'bg-warning text-dark'; break;
                                        case 'fully_received': echo 'bg-success'; break;
                                        case 'cancelled': echo 'bg-danger'; break;
                                        default: echo 'bg-secondary';
                                    }
                                ?>"><?php echo $status_labels[$purchase_invoice_data['status']]; ?></span>
                            </li>
                            <li><strong>ملاحظات:</strong> <?php echo nl2br(htmlspecialchars($purchase_invoice_data['notes'] ?: '-')); ?></li>
                        </ul>
                    </div>
                    <div class="col-lg-6 mb-3">
                        <h5>بيانات المورد:</h5>
                        <ul class="list-unstyled">
                            <li><strong>الاسم:</strong> <?php echo htmlspecialchars($purchase_invoice_data['supplier_name']); ?></li>
                            <li><strong>أنشئت بواسطة:</strong> <?php echo htmlspecialchars($purchase_invoice_data['creator_name'] ?? 'غير معروف'); ?></li>
                            <li><strong>تاريخ الإنشاء:</strong> <?php echo date('Y-m-d H:i A', strtotime($purchase_invoice_data['created_at'])); ?></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        <!-- جدول البنود -->
        <div class="card shadow-lg mb-4">
            <div class="card-header bg-light"><h4><i class="fas fa-boxes"></i> بنود فاتورة المشتريات</h4></div>
            <div class="card-body p-0">
                <?php if (!empty($purchase_invoice_items)): ?>
                    <div class="table-responsive">
                        <table class="table table-striped table-hover mb-0">
                            <thead class="table-dark">
                                <tr>
                                    <th>#</th>
                                    <th>كود المنتج</th>
                                    <th>اسم المنتج</th>
                                    <th class="text-center">الكمية المستلمة</th>
                                    <th class="text-end">سعر التكلفة للوحدة</th>
                                    <th class="text-end">إجمالي التكلفة</th>
                                    <?php if ($purchase_invoice_data['status'] != 'cancelled'): ?>
                                    <th class="text-center">إجراء</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $item_counter = 1; ?>
                                <?php foreach ($purchase_invoice_items as $item): ?>
                                    <tr>
                                        <td><?php echo $item_counter++; ?></td>
                                        <td><?php echo htmlspecialchars($item['product_code']); ?></td>
                                        <td><?php echo htmlspecialchars($item['product_name']); ?> (<?php echo htmlspecialchars($item['unit_of_measure']); ?>)</td>
                                        <td class="text-center"><?php echo number_format(floatval($item['quantity']), 2); ?></td>
                                        <td class="text-end"><?php echo number_format(floatval($item['cost_price_per_unit']), 2); ?> ج.م</td>
                                        <td class="text-end fw-bold"><?php echo number_format(floatval($item['total_cost']), 2); ?> ج.م</td>
                                        <?php if ($purchase_invoice_data['status'] != 'cancelled'): ?>
                                        <td class="text-center">
                                            <form action="<?php echo $delete_purchase_item_link; ?>" method="post" class="d-inline" onsubmit="return confirm('هل أنت متأكد من حذف هذا البند؟');">
                                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                                <input type="hidden" name="item_id_to_delete" value="<?php echo $item['item_id_pk']; ?>">
                                                <input type="hidden" name="purchase_invoice_id" value="<?php echo $invoice_id; ?>">
                                                <input type="hidden" name="product_id_to_adjust" value="<?php echo $item['product_id']; ?>">
                                                <input type="hidden" name="quantity_to_adjust" value="<?php echo floatval($item['quantity']); ?>">
                                                <button type="submit" name="delete_purchase_item" class="btn btn-danger btn-sm" title="حذف البند">
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
                                    <td colspan="6" class="text-end fw-bold fs-5">الإجمالي الكلي للفاتورة:</td>
                                    <td class="text-end fw-bold fs-5"><?php echo number_format(floatval($invoice_total_calculated), 2); ?> ج.م</td>
                                    <?php if ($purchase_invoice_data['status'] != 'cancelled'): ?>
                                    <td></td>
                                    <?php endif; ?>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="text-center p-3">لا توجد بنود في فاتورة المشتريات هذه حتى الآن.</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- إضافة بند جديد (واجهة محسّنة - بحث مشابه ل Invoice Out) -->
        <?php if ($purchase_invoice_data['status'] != 'cancelled'): ?>
        <div class="card shadow-lg mt-4">
            <div class="card-header" style="background:var(--grad-2); color:white;">
                <h4 class="mb-0"><i class="fas fa-cart-plus"></i> إضافة بند جديد لفاتورة المشتريات</h4>
            </div>
            <div class="card-body p-4">
                <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>?id=<?php echo $invoice_id; ?>" method="post" id="addItemForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <div class="row gy-2">
                        <div class="col-md-6">
                            <label for="product_search_input" class="form-label">ابحث عن المنتج (باسم أو كود)</label>
                            <input type="text" id="product_search_input" class="form-control" placeholder="ابدأ بالكتابة لاختيار منتج..." autocomplete="off">
                            <div id="product_suggestions" class="list-group position-relative" style="z-index:2000;"></div>
                            <input type="hidden" name="product_id" id="product_id">
                        </div>

                        <div class="col-md-2">
                            <label for="current_stock_display" class="form-label">الرصيد الآن</label>
                            <input type="text" id="current_stock_display" class="form-control" readonly>
                        </div>

                        <div class="col-md-2">
                            <label for="quantity" class="form-label">الكمية المستلمة</label>
                            <input type="number" name="quantity" id="quantity" class="form-control" step="0.01" min="0.01" value="1.00" required>
                        </div>

                        <div class="col-md-3">
                            <label for="cost_price_per_unit" class="form-label">سعر التكلفة للوحدة</label>
                            <input type="number" name="cost_price_per_unit" id="cost_price_per_unit" class="form-control" step="0.01" min="0" value="0.00" required>
                        </div>

                        <div class="col-md-3">
                            <label for="selling_price" class="form-label">سعر البيع الحالي / جديد (اختياري)</label>
                            <input type="number" name="selling_price" id="selling_price" class="form-control" step="0.01" min="0" placeholder="سعر البيع">
                        </div>

                        <div class="col-md-3 d-flex align-items-center">
                            <div class="form-check mt-3">
                                <input class="form-check-input" type="checkbox" value="1" id="update_selling_price" name="update_selling_price">
                                <label class="form-check-label" for="update_selling_price">تحديث سعر البيع في المنتج</label>
                            </div>
                        </div>

                        <div class="col-md-3 d-flex align-items-end">
                            <button type="submit" name="add_purchase_item" class="btn btn-success w-100"><i class="fas fa-plus me-2"></i> إضافة</button>
                        </div>
                    </div>

                    <div class="mt-2 small text-muted">يمكنك اختيار منتج عبر الحقول أعلاه، أو إدخال الكود/الاسم والاختيار من الاقتراحات. إذا حددت (تحديث سعر البيع) فسيتم تعديل سعر البيع في سجل المنتج.</div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <div class="card-footer text-muted text-center mt-4">
            <a href="<?php echo $manage_suppliers_link; ?>" class="btn btn-outline-secondary"><i class="fas fa-arrow-left"></i> العودة لإدارة الموردين</a>
        </div>

    <?php else: ?>
        <div class="alert alert-warning text-center">لم يتم العثور على فاتورة المشتريات المطلوبة.
            <a href="<?php echo $manage_suppliers_link; ?>">العودة لإدارة الموردين</a>.
        </div>
    <?php endif; ?>
</div>

<!-- ========================= JS للبحث السريع والاقتراحات ========================= -->
<script>
    // products_list_json: بيانات المنتجات قادمة من السيرفر
    const products = <?php echo json_encode($products_list, JSON_UNESCAPED_UNICODE); ?> || [];

    const input = document.getElementById('product_search_input');
    const suggestions = document.getElementById('product_suggestions');
    const productIdField = document.getElementById('product_id');
    const currentStockDisplay = document.getElementById('current_stock_display');
    const costPriceField = document.getElementById('cost_price_per_unit');
    const sellingPriceField = document.getElementById('selling_price');

    function clearSuggestions() {
        suggestions.innerHTML = '';
    }

    function renderSuggestion(prod) {
        const a = document.createElement('a');
        a.href = '#';
        a.className = 'list-group-item list-group-item-action';
        a.innerHTML = `<div><strong>${escapeHtml(prod.name)}</strong> &nbsp;<small class="text-muted">(${escapeHtml(prod.product_code)})</small></div>
                       <div class="small text-muted">الرصيد: ${prod.current_stock} — تكلفة: ${prod.cost_price} — بيع: ${prod.selling_price}</div>`;
        a.addEventListener('click', function(e){
            e.preventDefault();
            selectProduct(prod);
            clearSuggestions();
        });
        return a;
    }

    function selectProduct(prod) {
        input.value = prod.name + ' (' + prod.product_code + ')';
        productIdField.value = prod.id;
        currentStockDisplay.value = prod.current_stock;
        // ملء الحقول بقيم المنتج الحالية (يمكن التعديل)
        costPriceField.value = prod.cost_price !== null ? prod.cost_price : '0.00';
        sellingPriceField.value = prod.selling_price !== null ? prod.selling_price : '';
    }

    function escapeHtml(text) {
        if (!text) return '';
        return text.replace(/[&<>"'`=\/]/g, function (s) {
            return ({
                '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;', '/': '&#x2F;', '`': '&#x60;', '=': '&#x3D;'
            })[s];
        });
    }

    input.addEventListener('input', function(){
        const q = this.value.trim().toLowerCase();
        clearSuggestions();
        if (q.length === 0) return;
        let matched = [];
        // ابحث في الاسم و الكود
        for (let p of products) {
            if ((p.name && p.name.toLowerCase().includes(q)) || (p.product_code && p.product_code.toLowerCase().includes(q))) {
                matched.push(p);
                if (matched.length >= 8) break;
            }
        }
        if (matched.length === 0) {
            const no = document.createElement('div');
            no.className = 'list-group-item';
            no.textContent = 'لا توجد نتائج';
            suggestions.appendChild(no);
        } else {
            for (let m of matched) {
                suggestions.appendChild(renderSuggestion(m));
            }
        }
    });

    // عند الخروج من الحقل نُخفي الاقتراحات بعد فترة قصيرة لتفادي منع الضغط على رابط الاقتراح
    input.addEventListener('blur', function(){
        setTimeout(clearSuggestions, 150);
    });

    // لو دخل المستخدم رقم فاتورة أو كود مباشرة دون اختيار - نتركه، وإرسال النموذج سيتحقق من product_id
    document.getElementById('addItemForm').addEventListener('submit', function(e){
        if (!productIdField.value || productIdField.value === '') {
            // حاول البحث عن المنتج من الحقل النصي يدوياً (مطابقة دقيقة على الكود)
            const q = input.value.trim().toLowerCase();
            if (q.length > 0) {
                // محاولة إستخراج كود من داخل القوسين أو من النص
                let found = null;
                for (let p of products) {
                    if (p.product_code && p.product_code.toString().toLowerCase() === q) {
                        found = p;
                        break;
                    }
                    if (p.name && p.name.toLowerCase() === q) {
                        found = p;
                        break;
                    }
                }
                if (found) {
                    selectProduct(found);
                }
            }
        }
        // بعد كل هذا إذا لم يتم تحديد product_id, نمنع الإرسال
        if (!productIdField.value || productIdField.value === '') {
            e.preventDefault();
            alert('الرجاء اختيار منتج من الاقتراحات أو كتابة الكود/الاسم بشكل مطابق.');
            input.focus();
            return false;
        }
    });
</script>

<?php
// تحرير الموارد
if (isset($result) && is_object($result)) $result->free();
$conn->close();
require_once BASE_DIR . 'partials/footer.php';
?>
