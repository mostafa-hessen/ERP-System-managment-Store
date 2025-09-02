-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Sep 02, 2025 at 03:10 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `saied_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `customers`
--

CREATE TABLE `customers` (
  `id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL COMMENT 'اسم العميل',
  `mobile` varchar(11) NOT NULL COMMENT 'رقم الموبايل (11 رقم)',
  `city` varchar(100) NOT NULL COMMENT 'المدينة',
  `address` varchar(255) DEFAULT NULL COMMENT 'العنوان التفصيلي',
  `created_by` int(11) DEFAULT NULL COMMENT 'معرف المستخدم الذي أضاف العميل',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() COMMENT 'تاريخ الإضافة'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `customers`
--

INSERT INTO `customers` (`id`, `name`, `mobile`, `city`, `address`, `created_by`, `created_at`) VALUES
(7, 'Mostafa Hussien Ramadan', '01157787113', 'Fayoum', 'Sheikh Yusuf ST, Fayoum, Egypt', 5, '2025-09-01 10:26:16'),
(8, 'ضيف', '12345678901', 'Fayoum', 'Sheikh Yusuf ST, Fayoum, Egypt', 5, '2025-09-01 13:38:46');

-- --------------------------------------------------------

--
-- Table structure for table `expenses`
--

CREATE TABLE `expenses` (
  `id` int(11) NOT NULL,
  `expense_date` date NOT NULL COMMENT 'تاريخ حدوث المصروف',
  `description` varchar(255) NOT NULL COMMENT 'وصف أو بيان المصروف',
  `amount` decimal(10,2) NOT NULL COMMENT 'قيمة المصروف',
  `category_id` int(11) DEFAULT NULL COMMENT 'معرف فئة المصروف (FK to expense_categories.id)',
  `notes` text DEFAULT NULL COMMENT 'ملاحظات إضافية على المصروف (اختياري)',
  `created_by` int(11) DEFAULT NULL COMMENT 'معرف المستخدم الذي سجل المصروف (FK to users.id)',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='سجل المصاريف التشغيلية';

--
-- Dumping data for table `expenses`
--

INSERT INTO `expenses` (`id`, `expense_date`, `description`, `amount`, `category_id`, `notes`, `created_by`, `created_at`, `updated_at`) VALUES
(2, '2025-06-01', 'نقل البضاعة من السوق الى المخزن', 300.00, NULL, '', NULL, '2025-06-03 07:19:13', NULL),
(3, '2025-06-04', 'كهرباء مخزن', 200.00, 3, '0', 5, '2025-06-04 16:31:43', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `expense_categories`
--

CREATE TABLE `expense_categories` (
  `id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL COMMENT 'اسم فئة المصروف (مثال: نقل، كهرباء، إيجار)',
  `description` text DEFAULT NULL COMMENT 'وصف إضافي للفئة (اختياري)',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='فئات المصاريف المختلفة';

--
-- Dumping data for table `expense_categories`
--

INSERT INTO `expense_categories` (`id`, `name`, `description`, `created_at`) VALUES
(1, 'ايجارات', 'مثل ايجار المحل او المخزن و خلافة', '2025-06-03 07:30:12'),
(2, 'مرتبات', 'مثل مرتبات الموظفين', '2025-06-03 07:30:32'),
(3, 'مصاريف ثابتة', 'مثل الكهرباء و المياه و غيرها', '2025-06-03 07:30:57');

-- --------------------------------------------------------

--
-- Table structure for table `invoices_out`
--

CREATE TABLE `invoices_out` (
  `id` int(11) NOT NULL COMMENT 'المعرف التلقائي للفاتورة',
  `customer_id` int(11) NOT NULL COMMENT 'معرف العميل المرتبط بالفاتورة',
  `delivered` enum('yes','no') NOT NULL DEFAULT 'no' COMMENT 'هل تم التسليم؟ (نعم/لا)',
  `invoice_group` enum('group1','group2','group3','group4','group5','group6','group7','group8','group9','group10','group11') NOT NULL COMMENT 'مجموعة الفاتورة (من 1 إلى 11)',
  `created_by` int(11) DEFAULT NULL COMMENT 'معرف المستخدم الذي أنشأ الفاتورة',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() COMMENT 'تاريخ ووقت الإنشاء',
  `updated_by` int(11) DEFAULT NULL COMMENT 'معرف المستخدم الذي آخر من عدل الفاتورة',
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp() COMMENT 'تاريخ ووقت آخر تعديل'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول فواتير العملاء الصادرة';

--
-- Dumping data for table `invoices_out`
--

INSERT INTO `invoices_out` (`id`, `customer_id`, `delivered`, `invoice_group`, `created_by`, `created_at`, `updated_by`, `updated_at`) VALUES
(1, 2, 'yes', 'group1', NULL, '2025-05-29 11:03:06', NULL, '2025-06-04 11:55:12'),
(3, 1, 'yes', 'group2', NULL, '2025-05-29 11:45:05', NULL, '2025-05-29 11:49:16'),
(5, 5, 'yes', 'group1', NULL, '2025-06-01 07:38:36', NULL, '2025-06-03 12:04:06'),
(7, 2, 'yes', 'group1', NULL, '2025-06-01 07:41:00', NULL, '2025-06-04 08:22:19'),
(8, 1, 'no', 'group1', NULL, '2025-06-04 05:50:43', NULL, NULL),
(9, 3, 'no', 'group1', 3, '2025-06-04 16:24:52', NULL, NULL),
(10, 7, 'no', 'group1', 5, '2025-09-01 10:26:23', NULL, NULL),
(11, 7, 'no', 'group1', 5, '2025-09-01 13:32:00', NULL, NULL),
(12, 7, 'no', 'group1', 5, '2025-09-01 13:36:52', NULL, NULL),
(13, 7, 'no', 'group1', 5, '2025-09-01 13:37:22', NULL, NULL),
(14, 8, 'no', 'group1', 5, '2025-09-01 13:40:18', NULL, NULL),
(15, 7, 'no', 'group1', 5, '2025-09-01 13:43:03', NULL, NULL),
(16, 8, 'no', 'group1', 5, '2025-09-01 14:11:39', NULL, NULL),
(17, 8, 'no', 'group1', 5, '2025-09-01 15:01:22', NULL, NULL),
(18, 7, 'no', 'group1', 5, '2025-09-02 11:27:37', NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `invoice_out_items`
--

CREATE TABLE `invoice_out_items` (
  `id` int(11) NOT NULL COMMENT 'المعرف التلقائي لبند الفاتورة',
  `invoice_out_id` int(11) NOT NULL COMMENT 'معرف الفاتورة الصادرة (مفتاح أجنبي لجدول invoices_out)',
  `product_id` int(11) NOT NULL COMMENT 'معرف المنتج (مفتاح أجنبي لجدول products)',
  `quantity` decimal(10,2) NOT NULL COMMENT 'الكمية المباعة من المنتج',
  `total_price` decimal(10,2) NOT NULL COMMENT 'السعر الإجمالي للبند (الكمية * سعر الوحدة)',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() COMMENT 'تاريخ إضافة البند',
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp() COMMENT 'تاريخ آخر تعديل للبند',
  `selling_price` decimal(10,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `invoice_out_items`
--

INSERT INTO `invoice_out_items` (`id`, `invoice_out_id`, `product_id`, `quantity`, `total_price`, `created_at`, `updated_at`, `selling_price`) VALUES
(1, 7, 1, 2.00, 740.00, '2025-06-01 07:59:38', NULL, 0.00),
(3, 7, 1, 0.30, 111.00, '2025-06-01 08:09:50', NULL, 0.00),
(4, 7, 2, 3.50, 647.50, '2025-06-01 08:52:24', NULL, 0.00),
(6, 5, 2, 1.00, 185.00, '2025-06-01 09:06:02', NULL, 0.00),
(8, 5, 1, 1.00, 385.00, '2025-06-03 11:30:58', NULL, 0.00),
(9, 5, 1, 1.00, 385.00, '2025-06-03 11:31:46', NULL, 0.00),
(10, 5, 1, 1.00, 385.00, '2025-06-03 11:32:58', NULL, 0.00),
(11, 1, 2, 1.00, 185.00, '2025-06-03 11:33:47', NULL, 0.00),
(12, 1, 1, 1.00, 350.00, '2025-06-03 11:47:20', NULL, 0.00),
(13, 1, 1, 1.00, 350.00, '2025-06-03 11:47:54', NULL, 0.00),
(16, 1, 1, 1.00, 350.00, '2025-06-03 11:49:54', NULL, 0.00),
(17, 1, 1, 1.00, 320.00, '2025-06-03 11:50:44', NULL, 0.00),
(18, 1, 2, 1.00, 185.00, '2025-06-03 11:53:37', NULL, 0.00),
(19, 5, 2, 1.00, 185.00, '2025-06-03 11:55:12', NULL, 0.00),
(20, 5, 2, 1.00, 185.00, '2025-06-03 11:56:06', NULL, 0.00),
(21, 5, 2, 1.00, 185.00, '2025-06-03 12:01:26', NULL, 0.00),
(22, 5, 2, 1.00, 185.00, '2025-06-03 12:02:30', NULL, 0.00),
(23, 8, 2, 1.00, 185.00, '2025-06-04 05:50:53', NULL, 0.00),
(24, 9, 2, 2.50, 450.00, '2025-06-04 16:25:21', NULL, 0.00),
(25, 9, 1, 1.00, 320.00, '2025-06-04 16:25:39', NULL, 0.00);

-- --------------------------------------------------------

--
-- Table structure for table `products`
--

CREATE TABLE `products` (
  `id` int(11) NOT NULL COMMENT 'المعرف التلقائي للمنتج',
  `product_code` varchar(50) NOT NULL COMMENT 'كود المنتج الفريد',
  `name` varchar(255) NOT NULL COMMENT 'اسم المنتج',
  `description` text DEFAULT NULL COMMENT 'وصف المنتج (اختياري)',
  `unit_of_measure` varchar(50) NOT NULL COMMENT 'وحدة القياس (مثال: قطعة، كجم، لتر)',
  `current_stock` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'الرصيد الحالي في المخزن',
  `reorder_level` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'حد إعادة الطلب (التنبيه عند وصول الرصيد إليه أو أقل)',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() COMMENT 'تاريخ الإنشاء',
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp() COMMENT 'تاريخ آخر تعديل',
  `cost_price` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'سعر شراء القطعه',
  `selling_price` decimal(10,2) NOT NULL COMMENT 'سعر بيع القطعه'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول المنتجات المخزنة';

--
-- Dumping data for table `products`
--

INSERT INTO `products` (`id`, `product_code`, `name`, `description`, `unit_of_measure`, `current_stock`, `reorder_level`, `created_at`, `updated_at`, `cost_price`, `selling_price`) VALUES
(4, '212', 'لبس', '', 'قطعه', 200.00, 50.00, '2025-09-01 14:08:20', '2025-09-02 11:23:34', 0.00, 0.00),
(5, '11', 'mm', '', 'كجم', 122.00, 0.00, '2025-09-01 14:21:38', '2025-09-01 15:28:25', 0.00, 0.00),
(6, '1223', 'ggg', '', 'قطعه', 150.00, 50.00, '2025-09-01 15:30:09', '2025-09-01 16:05:30', 0.00, 0.00),
(778, '231', 'مفصله', '', 'قطعه', 21.00, 0.00, '2025-09-02 07:44:19', '2025-09-02 10:13:21', 100.00, 150.00),
(779, '101000', 'خنجري', '', 'قطعه', 10.00, 1.00, '2025-09-02 07:45:17', '2025-09-02 07:54:00', 0.00, 0.00),
(780, '2122', 'سكينه', '', 'قطعه', 17.00, 0.00, '2025-09-02 07:55:41', '2025-09-02 11:40:37', 100.00, 210.00);

-- --------------------------------------------------------

--
-- Table structure for table `purchase_invoices`
--

CREATE TABLE `purchase_invoices` (
  `id` int(11) NOT NULL COMMENT 'المعرف التلقائي لفاتورة الشراء',
  `supplier_id` int(11) NOT NULL COMMENT 'معرف المورد (FK to suppliers.id)',
  `supplier_invoice_number` varchar(100) DEFAULT NULL COMMENT 'رقم فاتورة المورد (قد يكون فريداً لكل مورد)',
  `purchase_date` date NOT NULL COMMENT 'تاريخ الشراء/الفاتورة',
  `notes` text DEFAULT NULL COMMENT 'ملاحظات إضافية على الفاتورة',
  `total_amount` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'الإجمالي الكلي للفاتورة (يُحسب من البنود)',
  `status` enum('pending','partial_received','fully_received','cancelled') NOT NULL DEFAULT 'pending' COMMENT 'حالة فاتورة الشراء',
  `created_by` int(11) DEFAULT NULL COMMENT 'معرف المستخدم الذي أنشأ فاتورة الشراء (FK to users.id)',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() COMMENT 'تاريخ ووقت الإنشاء',
  `updated_by` int(11) DEFAULT NULL COMMENT 'معرف المستخدم الذي آخر من عدل بيانات رأس الفاتورة',
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp() COMMENT 'تاريخ ووقت آخر تعديل'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول فواتير المشتريات (الوارد)';

--
-- Dumping data for table `purchase_invoices`
--

INSERT INTO `purchase_invoices` (`id`, `supplier_id`, `supplier_invoice_number`, `purchase_date`, `notes`, `total_amount`, `status`, `created_by`, `created_at`, `updated_by`, `updated_at`) VALUES
(4, 2, '', '2025-09-01', '', 0.00, 'fully_received', 5, '2025-09-01 13:48:45', NULL, NULL),
(5, 2, '', '2025-09-01', '', 0.00, 'partial_received', 5, '2025-09-01 14:22:24', 5, '2025-09-01 14:23:21'),
(6, 2, '', '2025-09-01', '', 0.00, 'fully_received', 5, '2025-09-01 15:28:09', NULL, NULL),
(7, 2, '', '2025-09-01', '', 0.00, 'fully_received', 5, '2025-09-01 15:30:55', NULL, NULL),
(8, 2, '', '2025-09-01', '', 0.00, 'fully_received', 5, '2025-09-01 16:05:08', NULL, NULL),
(9, 2, '', '2025-09-02', '', 0.00, 'fully_received', 5, '2025-09-02 09:56:44', 5, '2025-09-02 10:12:59'),
(10, 2, '', '2025-09-02', '', 0.00, 'fully_received', 5, '2025-09-02 10:27:45', NULL, NULL),
(11, 2, '', '2025-09-02', '', 0.00, 'pending', 5, '2025-09-02 10:40:44', NULL, NULL),
(12, 2, '', '2025-09-02', '', 50.00, 'fully_received', 5, '2025-09-02 10:42:36', 5, '2025-09-02 11:40:37'),
(13, 2, '', '2025-09-02', '', 20120.00, 'fully_received', 5, '2025-09-02 10:45:54', 5, '2025-09-02 11:31:22'),
(14, 2, '', '2025-09-02', '', 10000.00, 'fully_received', 5, '2025-09-02 11:33:40', 5, '2025-09-02 11:35:21');

-- --------------------------------------------------------

--
-- Table structure for table `purchase_invoice_items`
--

CREATE TABLE `purchase_invoice_items` (
  `id` int(11) NOT NULL COMMENT 'المعرف التلقائي لبند فاتورة الشراء',
  `purchase_invoice_id` int(11) NOT NULL COMMENT 'معرف فاتورة الشراء (FK to purchase_invoices.id)',
  `product_id` int(11) NOT NULL COMMENT 'معرف المنتج (FK to products.id)',
  `quantity` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'الكمية التي تم استلامها فعلياً',
  `cost_price_per_unit` decimal(10,2) NOT NULL COMMENT 'سعر التكلفة للوحدة من المورد',
  `total_cost` decimal(12,2) NOT NULL COMMENT 'التكلفة الإجمالية للبند (الكمية المطلوبة * سعر التكلفة)',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `purchase_invoice_items`
--

INSERT INTO `purchase_invoice_items` (`id`, `purchase_invoice_id`, `product_id`, `quantity`, `cost_price_per_unit`, `total_cost`, `created_at`, `updated_at`) VALUES
(5, 4, 4, 100.00, 10.00, 1000.00, '2025-09-01 14:09:01', NULL),
(6, 5, 5, 22.00, 10.00, 220.00, '2025-09-01 14:24:54', NULL),
(7, 6, 5, 100.00, 100.00, 10000.00, '2025-09-01 15:28:25', NULL),
(8, 8, 6, 100.00, 200.00, 20000.00, '2025-09-01 16:05:30', NULL),
(11, 9, 778, 21.00, 10.00, 210.00, '2025-09-02 10:13:21', NULL),
(12, 10, 780, 1.00, 100.00, 100.00, '2025-09-02 10:27:53', NULL),
(13, 10, 780, 1.00, 100.00, 100.00, '2025-09-02 10:40:03', NULL),
(15, 13, 4, 1.00, 20.00, 20.00, '2025-09-02 11:23:19', NULL),
(16, 13, 780, 100.00, 200.00, 20000.00, '2025-09-02 11:24:35', NULL),
(17, 13, 780, 10.00, 10.00, 100.00, '2025-09-02 11:31:22', NULL),
(18, 14, 779, 1000.00, 10.00, 10000.00, '2025-09-02 11:33:57', NULL),
(19, 12, 780, 5.00, 10.00, 50.00, '2025-09-02 11:40:37', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `settings`
--

CREATE TABLE `settings` (
  `setting_name` varchar(100) NOT NULL COMMENT 'اسم الإعداد',
  `setting_value` text DEFAULT NULL COMMENT 'قيمة الإعداد',
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp() COMMENT 'آخر تحديث'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول إعدادات النظام العامة';

--
-- Dumping data for table `settings`
--

INSERT INTO `settings` (`setting_name`, `setting_value`, `updated_at`) VALUES
('user_registration_status', 'closed', '2025-09-01 13:30:41');

-- --------------------------------------------------------

--
-- Table structure for table `suppliers`
--

CREATE TABLE `suppliers` (
  `id` int(11) NOT NULL COMMENT 'المعرف التلقائي للمورد',
  `name` varchar(200) NOT NULL COMMENT 'اسم المورد',
  `mobile` varchar(11) NOT NULL COMMENT 'رقم موبايل المورد (11 رقم)',
  `city` varchar(100) NOT NULL COMMENT 'مدينة المورد',
  `address` text DEFAULT NULL COMMENT 'عنوان المورد التفصيلي (اختياري)',
  `commercial_register` varchar(100) DEFAULT NULL COMMENT 'رقم السجل التجاري (اختياري ولكنه فريد إذا أدخل)',
  `created_by` int(11) DEFAULT NULL COMMENT 'معرف المستخدم الذي أضاف المورد (FK to users.id)',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() COMMENT 'تاريخ إضافة المورد',
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp() COMMENT 'تاريخ آخر تعديل لبيانات المورد'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول بيانات الموردين';

--
-- Dumping data for table `suppliers`
--

INSERT INTO `suppliers` (`id`, `name`, `mobile`, `city`, `address`, `commercial_register`, `created_by`, `created_at`, `updated_at`) VALUES
(2, 'محمد جمال', '01157787113', 'Fayoum', 'Sheikh Yusuf ST, Fayoum, Egypt', NULL, 5, '2025-09-01 13:48:30', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('user','admin') NOT NULL DEFAULT 'user',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `email`, `password`, `role`, `created_at`) VALUES
(3, 'ali', 'ali@gmail.com', '$2y$10$.T2JrZaSsBcyr6gWWkWG3.2lVfnFj4110W102gM7k3reWbnpfo0Wq', 'user', '2025-05-29 06:58:44'),
(4, 'omar', 'omar@gmail.com', '$2y$10$DpCQk7lfVfFE30qkYfCRgOCpTny3Md7v7gvQ4mZ3U.tFa3S10FHz6', 'user', '2025-05-29 06:58:58'),
(5, 'admin', 'admin@gmail.com', '$2y$10$9DkMvN8bVe3xV3Mf5qd91O/0YyliyVLUVWVcy8NQkjmNo4.hDuvIq', 'admin', '2025-06-04 13:52:41');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `customers`
--
ALTER TABLE `customers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `mobile` (`mobile`),
  ADD KEY `fk_customer_user` (`created_by`);

--
-- Indexes for table `expenses`
--
ALTER TABLE `expenses`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_expense_category` (`category_id`),
  ADD KEY `fk_expense_user_creator` (`created_by`);

--
-- Indexes for table `expense_categories`
--
ALTER TABLE `expense_categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indexes for table `invoices_out`
--
ALTER TABLE `invoices_out`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_invoice_customer` (`customer_id`),
  ADD KEY `fk_invoice_creator` (`created_by`),
  ADD KEY `fk_invoice_updater` (`updated_by`);

--
-- Indexes for table `invoice_out_items`
--
ALTER TABLE `invoice_out_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_invoice_item_to_invoice` (`invoice_out_id`),
  ADD KEY `fk_invoice_item_to_product` (`product_id`);

--
-- Indexes for table `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `product_code` (`product_code`);

--
-- Indexes for table `purchase_invoices`
--
ALTER TABLE `purchase_invoices`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_purchase_invoice_supplier` (`supplier_id`),
  ADD KEY `fk_purchase_invoice_creator` (`created_by`),
  ADD KEY `fk_purchase_invoice_updater` (`updated_by`);

--
-- Indexes for table `purchase_invoice_items`
--
ALTER TABLE `purchase_invoice_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_purchase_item_to_purchase_invoice` (`purchase_invoice_id`),
  ADD KEY `fk_purchase_item_to_product` (`product_id`);

--
-- Indexes for table `settings`
--
ALTER TABLE `settings`
  ADD PRIMARY KEY (`setting_name`);

--
-- Indexes for table `suppliers`
--
ALTER TABLE `suppliers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `mobile` (`mobile`),
  ADD UNIQUE KEY `commercial_register` (`commercial_register`),
  ADD KEY `fk_supplier_user_creator` (`created_by`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `customers`
--
ALTER TABLE `customers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `expenses`
--
ALTER TABLE `expenses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `expense_categories`
--
ALTER TABLE `expense_categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `invoices_out`
--
ALTER TABLE `invoices_out`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT COMMENT 'المعرف التلقائي للفاتورة', AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT for table `invoice_out_items`
--
ALTER TABLE `invoice_out_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT COMMENT 'المعرف التلقائي لبند الفاتورة', AUTO_INCREMENT=26;

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT COMMENT 'المعرف التلقائي للمنتج', AUTO_INCREMENT=781;

--
-- AUTO_INCREMENT for table `purchase_invoices`
--
ALTER TABLE `purchase_invoices`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT COMMENT 'المعرف التلقائي لفاتورة الشراء', AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT for table `purchase_invoice_items`
--
ALTER TABLE `purchase_invoice_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT COMMENT 'المعرف التلقائي لبند فاتورة الشراء', AUTO_INCREMENT=20;

--
-- AUTO_INCREMENT for table `suppliers`
--
ALTER TABLE `suppliers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT COMMENT 'المعرف التلقائي للمورد', AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `customers`
--
ALTER TABLE `customers`
  ADD CONSTRAINT `fk_customer_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `expenses`
--
ALTER TABLE `expenses`
  ADD CONSTRAINT `fk_expense_category` FOREIGN KEY (`category_id`) REFERENCES `expense_categories` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_expense_user_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `purchase_invoices`
--
ALTER TABLE `purchase_invoices`
  ADD CONSTRAINT `fk_purchase_invoice_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_purchase_invoice_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_purchase_invoice_updater` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `purchase_invoice_items`
--
ALTER TABLE `purchase_invoice_items`
  ADD CONSTRAINT `fk_purchase_item_to_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_purchase_item_to_purchase_invoice` FOREIGN KEY (`purchase_invoice_id`) REFERENCES `purchase_invoices` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
