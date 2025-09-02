

<nav class="navbar navbar-expand-lg navbar-dark bg-primary fixed-top">
    <div class="container-fluid">
        <a class="navbar-brand" href="<?php echo BASE_URL; ?>user/welcome.php">اسم البرنامج</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <li class="nav-item">
                    <a class="nav-link <?= $class1 ?>" aria-current="page" href="<?php echo BASE_URL; ?>user/welcome.php">الرئيسية</a>
                </li>

                <?php // التحقق من دور المستخدم لعرض رابط الإدارة ?>
                <?php if (isset($_SESSION["role"]) && $_SESSION["role"] == 'admin'): ?>
                    <li class="nav-item">
                        <a class="nav-link <?= $class2 ?>" href="<?php echo BASE_URL; ?>admin/index.php">لوحة التحكم</a>
                    </li>
                <?php endif; ?>

                <!-- <li class="nav-item">
                    <a class="nav-link <?= $class3 ?>" href="#">ملفي الشخصي</a>
                </li> -->
            </ul>
            <ul class="navbar-nav ms-auto">
                 <li class="nav-item">
                    <span class="navbar-text me-3">
                       مرحباً، <b><?php echo isset($_SESSION["username"]) ? htmlspecialchars($_SESSION["username"]) : 'زائر'; ?></b>
                    </span>
                </li>
                <li class="nav-item">
                    <a href="<?php echo BASE_URL; ?>auth/logout.php" class="btn btn-light">تسجيل الخروج</a> <?php // تم تغيير الزر إلى btn-light ليتناسب مع الخلفية الزرقاء ?>
                </li>
            </ul>
        </div>
    </div>
</nav>