<?php
// session أو تعريف المستخدم لو عاوز
$user = "admin";

// تحديد الصفحة الحالية لتفعيل Active
$current_page = basename($_SERVER['PHP_SELF']);
?>


<!-- الهيدر -->
<header class="main-header">

    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center">
         

            <div class="d-flex align-items-center">
                <button class="btn btn-sm btn-light me-3" id="sidebarToggle">
                    <i class="fas fa-bars"></i>
                </button>
                <h1 class="h4 mb-0"><i class="fas fa-warehouse me-2"></i> نظام إدارة المخازن</h1>
            </div>
            <div class="d-flex align-items-center  ">
                  <button id="themeToggle" class="btn  me-2 btn-outline-light rounded-circle d-flex align-items-center justify-content-center" 
        style="width:40px;height:40px;">
  <i class="fas fa-sun"></i>
</button>
                <div class="dropdown me-2">
                    <button class="btn btn-light dropdown-toggle" type="button" id="userDropdown" data-bs-toggle="dropdown">
                        <i class="fas fa-user-circle me-1"></i> <?php echo $user; ?>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="#"><i class="fas fa-user me-2"></i> الملف الشخصي</a></li>
                        <li><a class="dropdown-item" href="#"><i class="fas fa-cog me-2"></i> الإعدادات</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>auth/logout.php"><i class="fas fa-sign-out-alt me-2"></i> تسجيل الخروج</a></li>
                    </ul>

                </div>
                
            </div>
        </div>
    </div>
</header>

<!-- السايدبار -->
<div class="sidebar" id="sidebar">
    <div class="sidebar-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0 text-white">القائمة</h5>
        <!-- <button class="btn btn-sm btn-outline-light" id="sidebarClose">
            <i class="fas fa-times"></i>
        </button> -->
    </div>
    <ul class="nav flex-column mt-4">
        <li class="nav-item"><a class="nav-link <?php echo ($current_page == 'welcome.php') ? 'active' : ''; ?>" href="<?php echo BASE_URL; ?>user/welcome.php"><i class="fas fa-tachometer-alt"></i> لوحة التحكم</a></li>
        <li class="nav-item"><a class="nav-link <?php echo ($current_page == 'manage_products.php') ? 'active' : ''; ?>" href="<?php echo BASE_URL; ?>admin/manage_products.php"><i class="fas fa-boxes"></i> المنتجات</a></li>
        <li class="nav-item"><a class="nav-link <?php echo ($current_page == 'manage_customer.php') ? 'active' : ''; ?>" href="<?php echo BASE_URL; ?>admin/manage_customer.php"><i class="fas fa-users"></i> العملاء</a></li>
        <li class="nav-item"><a class="nav-link <?php echo ($current_page == 'manage_suppliers.php') ? 'active' : ''; ?>" href="<?php echo BASE_URL; ?>admin/manage_suppliers.php"><i class="fas fa-people-carry"></i> الموردين</a></li>
        <li class="nav-item"><a class="nav-link <?php echo ($current_page == 'manage_purchase_invoices.php') ? 'active' : ''; ?>" href="<?php echo BASE_URL; ?>admin/manage_purchase_invoices.php"><i class="fas fa-shopping-cart"></i> المشتريات</a></li>
        <li class="nav-item"><a class="nav-link <?php echo ($current_page == 'manage_expenses.php') ? 'active' : ''; ?>" href="<?php echo BASE_URL; ?>admin/manage_expenses.php"><i class="fas fa-file-invoice-dollar"></i> المصروفات</a></li>
        <li class="nav-item"><a class="nav-link <?php echo ($current_page == 'gross_profit_report.php') ? 'active' : ''; ?>" href="<?php echo BASE_URL; ?>admin/gross_profit_report.php"><i class="fas fa-chart-line"></i> التقارير</a></li>
        <li class="nav-item"><a class="nav-link <?php echo ($current_page == 'manage_users.php') ? 'active' : ''; ?>" href="<?php echo BASE_URL; ?>admin/manage_users.php"><i class="fas fa-cog"></i> الإعدادات</a></li>
        <!-- <li class="nav-item"><a class="nav-link <?php echo ($current_page == 'manage_users.php') ? 'active' : ''; ?>" href="<?php echo BASE_URL; ?>customer/show.php"><i class="fas fa-cog"></i> جرب</a></li> -->
    </ul>
</div>


<script>
document.addEventListener("DOMContentLoaded", function() {
    const sidebar = document.getElementById("sidebar");
    const sidebarToggle = document.getElementById("sidebarToggle");
    const sidebarClose = document.getElementById("sidebarClose");
    const mainContent = document.querySelector(".main-content");

    sidebarToggle.addEventListener("click", () => {
        sidebar.classList.toggle("collapsed");
        mainContent.classList.toggle("expanded");
    });

    sidebarClose.addEventListener("click", () => {
        sidebar.classList.add("collapsed");
        mainContent.classList.add("expanded");
    });
});
</script>
