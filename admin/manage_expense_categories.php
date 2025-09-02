<?php
$page_title = "إدارة فئات المصروفات";
// $class_settings_active = "active"; أو $class_expenses_active
require_once dirname(__DIR__) . '/config.php';
require_once BASE_DIR . 'partials/session_admin.php';

$message = "";
$categories_list = [];

// متغيرات للنموذج (إضافة أو تعديل)
$edit_mode = false;
$category_id_to_edit = null;
$category_name = '';
$category_description = '';
$category_name_err = '';

// جلب توكن CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// --- معالجة إضافة أو تحديث فئة ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['save_category'])) {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $message = "<div class='alert alert-danger'>خطأ: طلب غير صالح (CSRF).</div>";
    } else {
        $category_name = trim($_POST['category_name']);
        $category_description = trim($_POST['category_description']);
        $edit_id = isset($_POST['edit_id']) ? intval($_POST['edit_id']) : null;

        if (empty($category_name)) {
            $category_name_err = "اسم الفئة مطلوب.";
        } else {
            // التحقق من تفرد اسم الفئة
            $sql_check_name = "SELECT id FROM expense_categories WHERE name = ?";
            if ($edit_id) {
                $sql_check_name .= " AND id != ?";
            }
            if ($stmt_check = $conn->prepare($sql_check_name)) {
                if ($edit_id) {
                    $stmt_check->bind_param("si", $category_name, $edit_id);
                } else {
                    $stmt_check->bind_param("s", $category_name);
                }
                $stmt_check->execute();
                $stmt_check->store_result();
                if ($stmt_check->num_rows > 0) {
                    $category_name_err = "اسم الفئة هذا موجود بالفعل.";
                }
                $stmt_check->close();
            } else {
                 $message = "<div class='alert alert-danger'>خطأ في التحقق من اسم الفئة.</div>";
            }
        }

        if (empty($category_name_err) && empty($message)) {
            if ($edit_id) { // وضع التعديل
                $sql_save = "UPDATE expense_categories SET name = ?, description = ? WHERE id = ?";
                if ($stmt_save = $conn->prepare($sql_save)) {
                    $stmt_save->bind_param("ssi", $category_name, $category_description, $edit_id);
                    if ($stmt_save->execute()) {
                        $_SESSION['message'] = "<div class='alert alert-success'>تم تحديث الفئة بنجاح.</div>";
                    } else {
                        $_SESSION['message'] = "<div class='alert alert-danger'>خطأ أثناء تحديث الفئة: " . $stmt_save->error . "</div>";
                    }
                    $stmt_save->close();
                } else {
                    $_SESSION['message'] = "<div class='alert alert-danger'>خطأ في تحضير استعلام التحديث.</div>";
                }
            } else { // وضع الإضافة
                $sql_save = "INSERT INTO expense_categories (name, description) VALUES (?, ?)";
                if ($stmt_save = $conn->prepare($sql_save)) {
                    $stmt_save->bind_param("ss", $category_name, $category_description);
                    if ($stmt_save->execute()) {
                        $_SESSION['message'] = "<div class='alert alert-success'>تمت إضافة الفئة بنجاح.</div>";
                    } else {
                        $_SESSION['message'] = "<div class='alert alert-danger'>خطأ أثناء إضافة الفئة: " . $stmt_save->error . "</div>";
                    }
                    $stmt_save->close();
                } else {
                    $_SESSION['message'] = "<div class='alert alert-danger'>خطأ في تحضير استعلام الإضافة.</div>";
                }
            }
            header("Location: manage_expense_categories.php"); // PRG
            exit;
        } else {
            // إذا فشل التحقق، احتفظ بالبيانات المدخلة لعرضها في النموذج
            // $category_name و $category_description تم تعبئتهما بالفعل من POST
            $edit_mode = !empty($edit_id); // احتفظ بوضع التعديل إذا كان كذلك
            $category_id_to_edit = $edit_id;
            if(empty($message) && !empty($category_name_err)) $message = "<div class='alert alert-danger'>الرجاء إصلاح الخطأ في اسم الفئة.</div>";
        }
    }
}

// --- معالجة الحذف ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_category'])) {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['message'] = "<div class='alert alert-danger'>خطأ: طلب غير صالح (CSRF).</div>";
    } else {
        $category_id_to_delete = intval($_POST['category_id_to_delete']);
        // عند حذف الفئة، المصاريف المرتبطة بها سيصبح category_id لها NULL بسبب ON DELETE SET NULL
        $sql_delete = "DELETE FROM expense_categories WHERE id = ?";
        if ($stmt_delete = $conn->prepare($sql_delete)) {
            $stmt_delete->bind_param("i", $category_id_to_delete);
            if ($stmt_delete->execute()) {
                $_SESSION['message'] = ($stmt_delete->affected_rows > 0) ? "<div class='alert alert-success'>تم حذف الفئة بنجاح. المصاريف المرتبطة أصبحت بدون فئة.</div>" : "<div class='alert alert-warning'>لم يتم العثور على الفئة أو لم يتم حذفها.</div>";
            } else {
                $_SESSION['message'] = "<div class='alert alert-danger'>حدث خطأ أثناء حذف الفئة: " . $stmt_delete->error . "</div>";
            }
            $stmt_delete->close();
        } else {
            $_SESSION['message'] = "<div class='alert alert-danger'>خطأ في تحضير استعلام الحذف: " . $conn->error . "</div>";
        }
    }
    header("Location: manage_expense_categories.php"); // PRG
    exit;
}


// --- جلب بيانات فئة للتعديل (إذا تم طلب ذلك عبر GET) ---
if (isset($_GET['edit_id']) && is_numeric($_GET['edit_id'])) {
    $edit_mode = true;
    $category_id_to_edit = intval($_GET['edit_id']);
    $sql_get_category = "SELECT name, description FROM expense_categories WHERE id = ?";
    if ($stmt_get = $conn->prepare($sql_get_category)) {
        $stmt_get->bind_param("i", $category_id_to_edit);
        $stmt_get->execute();
        $result_get = $stmt_get->get_result();
        if ($row_get = $result_get->fetch_assoc()) {
            $category_name = $row_get['name'];
            $category_description = $row_get['description'];
        } else {
            $message = "<div class='alert alert-warning'>الفئة المطلوبة للتعديل غير موجودة.</div>";
            $edit_mode = false;
            $category_id_to_edit = null;
        }
        $stmt_get->close();
    } else {
         $message = "<div class='alert alert-danger'>خطأ في جلب بيانات الفئة للتعديل.</div>";
         $edit_mode = false;
         $category_id_to_edit = null;
    }
}

// --- جلب الرسائل من الجلسة (بعد إعادة التوجيه) ---
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    unset($_SESSION['message']);
}

// --- جلب كل فئات المصاريف للعرض ---
$sql_select_categories = "SELECT id, name, description, created_at FROM expense_categories ORDER BY name ASC";
$result_select_categories = $conn->query($sql_select_categories);
if ($result_select_categories) {
    while ($row = $result_select_categories->fetch_assoc()) {
        $categories_list[] = $row;
    }
} else {
    $message .= "<div class='alert alert-danger'>خطأ في جلب قائمة الفئات: " . $conn->error . "</div>";
}


require_once BASE_DIR . 'partials/header.php';
require_once BASE_DIR . 'partials/navbar.php';
$current_page_url_for_forms = htmlspecialchars($_SERVER["PHP_SELF"]);
?>

<div class="container mt-5 pt-3">
    <div class="row">
        <div class="col-lg-4 mb-4">
            <div class="card shadow-sm">
                <div class="card-header <?php echo $edit_mode ? 'bg-warning text-dark' : 'bg-success text-white'; ?>">
                    <h5><i class="fas <?php echo $edit_mode ? 'fa-edit' : 'fa-plus-circle'; ?>"></i> <?php echo $edit_mode ? 'تعديل الفئة' : 'إضافة فئة مصروفات جديدة'; ?></h5>
                </div>
                <div class="card-body">
                    <form action="<?php echo $current_page_url_for_forms . ($edit_mode ? '?edit_id=' . $category_id_to_edit : ''); ?>" method="post">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <?php if ($edit_mode && $category_id_to_edit): ?>
                            <input type="hidden" name="edit_id" value="<?php echo $category_id_to_edit; ?>">
                        <?php endif; ?>

                        <div class="mb-3">
                            <label for="category_name" class="form-label">اسم الفئة:</label>
                            <input type="text" name="category_name" id="category_name" class="form-control <?php echo (!empty($category_name_err)) ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($category_name); ?>" required>
                            <span class="invalid-feedback"><?php echo $category_name_err; ?></span>
                        </div>
                        <div class="mb-3">
                            <label for="category_description" class="form-label">الوصف (اختياري):</label>
                            <textarea name="category_description" id="category_description" class="form-control" rows="3"><?php echo htmlspecialchars($category_description); ?></textarea>
                        </div>
                        <button type="submit" name="save_category" class="btn <?php echo $edit_mode ? 'btn-warning' : 'btn-success'; ?>">
                            <i class="fas fa-save"></i> <?php echo $edit_mode ? 'تحديث الفئة' : 'إضافة الفئة'; ?>
                        </button>
                        <?php if ($edit_mode): ?>
                            <a href="<?php echo $current_page_url_for_forms; ?>" class="btn btn-secondary ms-2">إلغاء التعديل</a>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <h1><i class="fas fa-tags"></i> إدارة فئات المصروفات</h1>
            <?php echo $message; // عرض رسائل الحالة العامة ?>

            <div class="card shadow-sm mt-4">
                <div class="card-header">
                    قائمة الفئات المسجلة
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped table-hover table-bordered align-middle">
                            <thead class="table-dark">
                                <tr>
                                    <th>#</th>
                                    <th>اسم الفئة</th>
                                    <th>الوصف</th>
                                    <th>تاريخ الإضافة</th>
                                    <th class="text-center">إجراءات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($categories_list)): ?>
                                    <?php $cat_counter = 1; ?>
                                    <?php foreach($categories_list as $category_item): ?>
                                        <tr>
                                            <td><?php echo $cat_counter++; ?></td>
                                            <td><?php echo htmlspecialchars($category_item["name"]); ?></td>
                                            <td><?php echo !empty($category_item["description"]) ? nl2br(htmlspecialchars($category_item["description"])) : '-'; ?></td>
                                            <td><?php echo date('Y-m-d', strtotime($category_item["created_at"])); ?></td>
                                            <td class="text-center">
                                                <a href="<?php echo $current_page_url_for_forms; ?>?edit_id=<?php echo $category_item["id"]; ?>" class="btn btn-warning btn-sm" title="تعديل الفئة">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <form action="<?php echo $current_page_url_for_forms; ?>" method="post" class="d-inline ms-1">
                                                    <input type="hidden" name="category_id_to_delete" value="<?php echo $category_item["id"]; ?>">
                                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                                    <button type="submit" name="delete_category" class="btn btn-danger btn-sm"
                                                            onclick="return confirm('هل أنت متأكد من حذف هذه الفئة؟ المصاريف المرتبطة بها ستصبح بدون فئة.');"
                                                            title="حذف الفئة">
                                                        <i class="fas fa-trash-alt"></i>
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="5" class="text-center">لا توجد فئات مصاريف مسجلة حالياً. قم بإضافة فئة جديدة.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
$conn->close();
require_once BASE_DIR . 'partials/footer.php';
?>