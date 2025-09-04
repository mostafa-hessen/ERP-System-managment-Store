<?php
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

// --- مصفوفة ترجمة حالة الفاتورة ---
$status_labels = [
    'pending' => 'قيد الانتظار',
    'partial_received' => 'تم الاستلام جزئياً',
    'fully_received' => 'تم الاستلام بالكامل',
    'cancelled' => 'ملغاة'
];

if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    unset($_SESSION['message']);
}

// إنشاء CSRF token إذا لم يكن موجود
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// التحقق من وجود ID صحيح
if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $invoice_id = intval($_GET['id']);
} else {
    $_SESSION['message'] = "<div class='alert alert-danger'>رقم فاتورة المشتريات غير محدد أو غير صالح.</div>";
    header("Location: " . BASE_URL . "admin/manage_suppliers.php");
    exit;
}

// --- معالجة إضافة بند جديد ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_purchase_item'])) {
    $sql_check_invoice_status_for_add = "SELECT status FROM purchase_invoices WHERE id = ?";
    $stmt_check_status_add = $conn->prepare($sql_check_invoice_status_for_add);
    $stmt_check_status_add->bind_param("i", $invoice_id);
    $stmt_check_status_add->execute();
    $result_status_add = $stmt_check_status_add->get_result();
    $invoice_status_data_add = $result_status_add->fetch_assoc();
    $stmt_check_status_add->close();

    if (!$invoice_status_data_add || $invoice_status_data_add['status'] == 'cancelled') {
        $_SESSION['message'] = "<div class='alert alert-warning'>لا يمكن إضافة بنود لهذه الفاتورة لأنها ملغاة أو غير موجودة.</div>";
    } elseif (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['message'] = "<div class='alert alert-danger'>خطأ: طلب غير صالح (CSRF).</div>";
    } else {
        $product_id_to_add = intval($_POST['product_id']);
        $quantity_received = floatval($_POST['quantity']);
        $cost_price_to_add = floatval($_POST['cost_price_per_unit']);

        if ($product_id_to_add <= 0) {
            $_SESSION['message'] = "<div class='alert alert-danger'>الرجاء اختيار منتج صحيح.</div>";
        } elseif ($quantity_received <= 0) {
            $_SESSION['message'] = "<div class='alert alert-danger'>الرجاء إدخال كمية صحيحة أكبر من صفر.</div>";
        } elseif ($cost_price_to_add <= 0) {
            $_SESSION['message'] = "<div class='alert alert-danger'>الرجاء إدخال سعر تكلفة أكبر من صفر.</div>";
        } else {
            $conn->begin_transaction();
            try {
                $total_cost_for_item = $quantity_received * $cost_price_to_add;
                $sql_insert_item = "INSERT INTO purchase_invoice_items
                                    (purchase_invoice_id, product_id, quantity, cost_price_per_unit, total_cost)
                                    VALUES (?, ?, ?, ?, ?)";
                $stmt_insert_item = $conn->prepare($sql_insert_item);
                $stmt_insert_item->bind_param("iiddd", $invoice_id, $product_id_to_add, $quantity_received, $cost_price_to_add, $total_cost_for_item);
                $stmt_insert_item->execute();
                $stmt_insert_item->close();

                // تحديث المخزون فقط إذا fully_received
                if ($invoice_status_data_add['status'] === 'fully_received') {
                    $sql_update_stock = "UPDATE products SET current_stock = current_stock + ? WHERE id = ?";
                    $stmt_update_stock = $conn->prepare($sql_update_stock);
                    $stmt_update_stock->bind_param("di", $quantity_received, $product_id_to_add);
                    $stmt_update_stock->execute();
                    $stmt_update_stock->close();

                    // ✅ تعديل جديد: تحديث cost_price للمنتج من آخر فاتورة fully_received
                    $sql_update_cost = "UPDATE products SET cost_price = ? WHERE id = ?";
                    $stmt_update_cost = $conn->prepare($sql_update_cost);
                    $stmt_update_cost->bind_param("di", $cost_price_to_add, $product_id_to_add);
                    $stmt_update_cost->execute();
                    $stmt_update_cost->close();
                }

                // تحديث إجمالي الفاتورة
                $sql_sum_total = "SELECT SUM(total_cost) AS grand_total FROM purchase_invoice_items WHERE purchase_invoice_id = ?";
                $stmt_sum = $conn->prepare($sql_sum_total);
                $stmt_sum->bind_param("i", $invoice_id);
                $stmt_sum->execute();
                $result_sum = $stmt_sum->get_result();
                $row_sum = $result_sum->fetch_assoc();
                $invoice_total_calculated = floatval($row_sum['grand_total']);
                $stmt_sum->close();

                $sql_update_invoice_total = "UPDATE purchase_invoices SET total_amount = ? WHERE id = ?";
                $stmt_update_invoice_total = $conn->prepare($sql_update_invoice_total);
                $stmt_update_invoice_total->bind_param("di", $invoice_total_calculated, $invoice_id);
                $stmt_update_invoice_total->execute();
                $stmt_update_invoice_total->close();

                $conn->commit();
                $_SESSION['message'] = "<div class='alert alert-success'>تم إضافة البند بنجاح.</div>";
            } catch (mysqli_sql_exception $exception) {
                $conn->rollback();
                $_SESSION['message'] = "<div class='alert alert-danger'>فشلت العملية: " . $exception->getMessage() . "</div>";
            }
        }
    }
    header("Location: " . BASE_URL . "admin/view_purchase_invoice.php?id=" . $invoice_id);
    exit;
}

// --- جلب بيانات الفاتورة ---
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

// --- جلب البنود ---
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

    // --- جلب قائمة المنتجات ---
    $sql_products = "SELECT id, product_code, name, unit_of_measure FROM products ORDER BY name ASC";
    $result_products_query = $conn->query($sql_products);
    if ($result_products_query) {
        while ($row_prod = $result_products_query->fetch_assoc()) {
            $products_list[] = $row_prod;
        }
    } else { $message .= "<div class='alert alert-warning'>خطأ في جلب قائمة المنتجات: " . $conn->error . "</div>"; }
}

$edit_purchase_invoice_header_link = BASE_URL . "admin/edit_purchase_invoice.php?id=" . $invoice_id;
$manage_suppliers_link = BASE_URL . "admin/manage_suppliers.php";
$delete_purchase_item_link = BASE_URL . "admin/delete_purchase_item.php";
?>





<div class="container mt-5 pt-3">
    <?php echo $message; ?>

    <?php if ($purchase_invoice_data): ?>
        <div class="card shadow-lg mb-4">
            <div class="card-header bg-dark text-white d-flex flex-column flex-md-row justify-content-between align-items-center">
                <h3 class="mb-2 mb-md-0"><i class="fas fa-receipt"></i> تفاصيل فاتورة المشتريات رقم: #<?php echo $purchase_invoice_data['id']; ?></h3>
                <div>
                    <a href="<?php echo $edit_purchase_invoice_header_link; ?>" class="btn btn-warning btn-sm me-md-2 mb-2 mb-md-0"><i class="fas fa-edit"></i> تعديل بيانات الفاتورة</a>
                    <a href="#" onclick="window.print(); return false;" class="btn btn-secondary btn-sm mb-2 mb-md-0"><i class="fas fa-print"></i> طباعة</a>
                </div>
            </div>
            <div class="card-body p-4">
                <div class="row">
                    <div class="col-lg-6 mb-3">
                        <h5>بيانات الفاتورة:</h5>
                        <ul class="list-unstyled">
                            <li><strong>رقم فاتورة المورد:</strong> <?php echo htmlspecialchars($purchase_invoice_data['supplier_invoice_number'] ?: '-'); ?></li>
                            <li><strong>تاريخ الشراء:</strong> <?php echo date('Y-m-d', strtotime($purchase_invoice_data['purchase_date'])); ?></li>
                            <li><strong>الحالة:</strong> <span class="badge bg-<?php
                                switch($purchase_invoice_data['status']){
                                    case 'pending': echo 'warning text-dark'; break;
                                    case 'fully_received': echo 'success'; break;
                                    case 'cancelled': echo 'danger'; break;
                                    default: echo 'secondary';
                                }
                            ?>"><?php echo $status_labels[$purchase_invoice_data['status']]; ?></span></li>
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
                                    <td colspan="<?php echo ($purchase_invoice_data['status'] != 'cancelled') ? '6' : '6'; ?>" class="text-end fw-bold fs-5">الإجمالي الكلي للفاتورة:</td>
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

        <!-- إضافة بند جديد -->
        <?php if ($purchase_invoice_data['status'] != 'cancelled'): ?>
        <div class="card shadow-lg mt-4">
            <div class="card-header bg-success text-white">
                <h4><i class="fas fa-cart-plus"></i> إضافة بند جديد لفاتورة المشتريات</h4>
            </div>
            <div class="card-body p-4">
                <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>?id=<?php echo $invoice_id; ?>" method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label for="product_id" class="form-label">اختر المنتج:</label>
                            <select name="product_id" id="product_id" class="form-select" required>
                                <option value="">-- اختر منتجاً --</option>
                                <?php foreach ($products_list as $product): ?>
                                    <option value="<?php echo $product['id']; ?>">
                                        <?php echo htmlspecialchars($product['product_code']); ?> - <?php echo htmlspecialchars($product['name']); ?> (<?php echo htmlspecialchars($product['unit_of_measure']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="quantity" class="form-label">الكمية المستلمة:</label>
                            <input type="number" name="quantity" id="quantity" class="form-control" step="0.01" min="0.01" value="1.00" required>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="cost_price_per_unit" class="form-label">سعر التكلفة للوحدة:</label>
                            <input type="number" name="cost_price_per_unit" id="cost_price_per_unit" class="form-control" step="0.01" min="0" value="0.00" required>
                        </div>
                        <div class="col-md-2 mb-3 d-flex align-items-end">
                            <button type="submit" name="add_purchase_item" class="btn btn-success w-100"><i class="fas fa-plus"></i> إضافة</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <div class="card-footer text-muted text-center mt-4">
            <a href="<?php echo $manage_suppliers_link; ?>" class="btn btn-outline-secondary"><i class="fas fa-arrow-left"></i> العودة لإدارة الموردين</a>
        </div>

    <?php elseif(empty($message)): ?>
        <div class="alert alert-warning text-center">لم يتم العثور على فاتورة المشتريات المطلوبة.
            <a href="<?php echo $manage_suppliers_link; ?>">العودة لإدارة الموردين</a>.
        </div>
    <?php endif; ?>
</div>

<?php
$conn->close();
require_once BASE_DIR . 'partials/footer.php';
?>
