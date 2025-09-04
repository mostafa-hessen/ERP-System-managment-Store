<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title><?php echo isset($page_title) ? $page_title : 'لوحة التحكم'; ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/print_invoice.css" media="print">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/index.css" >

       <?php if (!empty($page_css)): ?>
        <!-- CSS خاص بالصفحة -->
        <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/<?php echo $page_css; ?>">
    <?php endif; ?>
    
    <!-- <style>
        /* body { padding-top: 70px; background-color: #f8f9fa; }
        .navbar-brand { font-weight: bold; }
        .card { margin-bottom: 20px; box-shadow: 0 4px 8px rgba(0,0,0,0.1); transition: transform 0.2s; }
        .card:hover { transform: translateY(-5px); }
        .card-header { font-weight: bold; }
        .card-icon { font-size: 3rem; color: #0d6efd; لون الأيقونة margin-bottom: 15px; }
        /* .welcome-jumbotron { background-color: #ffffff; padding: 2rem 1rem; margin-bottom: 2rem; border-radius: 0.3rem; } */ */
    </style> -->
</head>
<body data-app data-theme="dark">

<!-- المحتوى يبدأ هنا -->
<div class="main-content">
