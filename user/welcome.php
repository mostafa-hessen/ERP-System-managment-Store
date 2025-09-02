

<?php
$page_title = "أهلاً بك";
$class1 = "active";
require_once dirname(__DIR__) . '/config.php';
require_once BASE_DIR . 'partials/session_user.php';
require_once BASE_DIR . 'partials/header.php';
require_once BASE_DIR . 'partials/navbar.php';
?>

<div class="container mt-5 pt-3">

    <div class="p-4 mb-4 bg-light rounded-3 text-center">
        <h1 class="display-5 fw-bold">أهلاً بك، <?php echo htmlspecialchars($_SESSION["username"]); ?>!</h1>
        <p class="fs-4">هذه هي لوحة التحكم الخاصة بك. يمكنك البدء من هنا.</p>
    </div>

    <div class="row text-center justify-content-center">

        <div class="col-md-6 col-lg-4 mb-4">
            <div class="card h-100 shadow-sm">
                <div class="card-body d-flex flex-column">
                    <i class="fas fa-user-plus card-icon" style="color: #198754;"></i>
                    <h5 class="card-title mt-3">إضافة عميل جديد</h5>
                    <p class="card-text flex-grow-1">قم بإدخال بيانات عميل جديد في النظام بسهولة وسرعة.</p>
                    <a href="<?php echo BASE_URL; ?>customer/insert.php" class="btn btn-success mt-auto">إضافة عميل الآن</a>
                </div>
            </div>
        </div>

        <div class="col-md-6 col-lg-4 mb-4">
            <div class="card h-100 shadow-sm">
                <div class="card-body d-flex flex-column">
                    <i class="fas fa-list-alt card-icon" style="color: #0d6efd;"></i>
                    <h5 class="card-title mt-3">استعراض العملاء</h5>
                    <p class="card-text flex-grow-1">عرض قائمة العملاء مع إمكانية البحث والتصفية.</p>
                    <a href="<?php echo BASE_URL; ?>customer/show.php" class="btn btn-primary mt-auto">استعراض العملاء الآن</a>
                </div>
            </div>
        </div>

        <div class="col-md-6 col-lg-4 mb-4">
            <div class="card h-100 shadow-sm">
                <div class="card-body d-flex flex-column">
                    <i class="fas fa-truck-loading card-icon" style="color: #fd7e14;"></i>
                    <h5 class="card-title mt-3">الفواتير غير المستلمة</h5>
                    <p class="card-text flex-grow-1">عرض وتتبع الفواتير التي لم يتم تسليمها بعد، مع إمكانية التصفية.</p>
                    <a href="<?php echo BASE_URL; ?>invoices_out/pending.php" class="btn btn-warning mt-auto">عرض الفواتير غير المستلمة</a>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-lg-4 mb-4">
            <div class="card h-100 shadow-sm">
                <div class="card-body d-flex flex-column">
                    <i class="fas fa-id-card card-icon" style="color: #6c757d;"></i>
                    <h5 class="card-title mt-3">ملفي الشخصي</h5>
                    <p class="card-text flex-grow-1">عرض وتعديل بيانات حسابك الشخصي وكلمة المرور.</p>
                    <a href="#" class="btn btn-secondary mt-auto disabled">تعديل الملف الشخصي (قريباً)</a>
                </div>
            </div>
        </div>

    </div> </div> <?php require_once BASE_DIR . 'partials/footer.php'; ?>