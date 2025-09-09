-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Sep 08, 2025 at 05:41 PM
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
(8, 'عميل نقدي ', '12345678901', 'Fayoum', 'Fayoum, Egypt', 5, '2025-09-01 13:38:46'),
(10, 'مصطفي حسين رمضان عطيه', '01096590768', 'الفيوم', '', NULL, '2025-09-03 19:39:40'),
(11, 'Mostafa Hussien Ramadan', '01032486387', 'Fayoum', 'Sheikh Yusuf ST, Fayoum, Egypt', 5, '2025-09-04 13:44:23'),
(12, 'Mostafa Hussien Ramadan', '01157787112', 'Fayoum', 'Sheikh Yusuf ST, Fayoum, Egypt', 5, '2025-09-04 13:44:53'),
(13, 'Mostafa Hussien Ramadan', '11111111111', 'Fayoum', 'Sheikh Yusuf ST, Fayoum, Egypt', 5, '2025-09-04 13:45:32'),
(14, 'Mostafa Hussien Ramadan', '01032486382', 'Fayoum', 'Sheikh Yusuf ST, Fayoum, Egypt', 5, '2025-09-04 13:46:36'),
(15, 'Mostafa Hussien Ramadan', '01157787111', 'Fayoum', 'Sheikh Yusuf ST, Fayoum, Egypt', 5, '2025-09-04 16:20:02'),
(16, 'b', '', '', '0', 5, '2025-09-06 11:31:20'),
(17, 'said hamdy abdelrazek', '01099337896', 'Faiyum', 'abo shnak', 5, '2025-09-07 07:07:25'),
(19, 'd', '01034863811', '', '0', 5, '2025-09-07 07:40:50'),
(62, 'tata', '01115473773', '', '0', 5, '2025-09-07 17:10:45'),
(63, 'tata', '01115273773', '1', '11111', 5, '2025-09-07 17:16:18'),
(64, 'tata', '01115273772', '1', '11111', 5, '2025-09-07 17:20:28'),
(65, 'ريان', '01115273778', 'مدابغ', '0', 5, '2025-09-07 21:11:57'),
(66, 'ريان2', '01115273178', 'مدابغ', '0', 5, '2025-09-08 09:09:57'),
(67, 'ريا22', '01115213178', 'مدابغ', '0', 5, '2025-09-08 09:20:59'),
(68, 'ريا232', '01115213678', 'مدابغ', 'بيت ريان', 5, '2025-09-08 09:30:29');

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
(3, '2025-06-04', 'كهرباء مخزن', 200.00, 3, '0', 5, '2025-06-04 16:31:43', NULL),
(4, '2025-09-03', 'اكل للعيال', 200.00, 3, '0', 5, '2025-09-03 09:04:05', NULL),
(5, '2025-09-04', 'زو\\\\د', 6.00, NULL, 'ةة', 5, '2025-09-03 22:26:47', NULL);

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
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp() COMMENT 'تاريخ ووقت آخر تعديل',
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول فواتير العملاء الصادرة';

--
-- Dumping data for table `invoices_out`
--

INSERT INTO `invoices_out` (`id`, `customer_id`, `delivered`, `invoice_group`, `created_by`, `created_at`, `updated_by`, `updated_at`, `notes`) VALUES
(61, 8, 'no', 'group1', 5, '2025-09-05 21:06:17', NULL, NULL, NULL),
(62, 8, 'no', 'group1', 5, '2025-09-05 21:11:58', NULL, NULL, NULL),
(63, 8, 'no', 'group1', 5, '2025-09-06 08:19:06', NULL, NULL, NULL),
(64, 0, 'no', 'group1', 5, '2025-09-06 08:30:43', 5, '2025-09-06 09:03:16', NULL),
(65, 8, 'yes', 'group1', 5, '2025-09-06 09:04:08', 5, '2025-09-06 09:04:32', NULL),
(66, 8, 'no', 'group1', 5, '2025-09-06 09:06:19', NULL, NULL, NULL),
(67, 8, 'no', 'group1', 5, '2025-09-06 09:07:24', 5, '2025-09-06 09:22:53', NULL),
(68, 8, 'no', 'group1', 5, '2025-09-06 09:14:32', NULL, NULL, NULL),
(69, 8, 'no', 'group1', 5, '2025-09-06 09:24:59', 5, '2025-09-06 09:27:00', NULL),
(72, 8, 'no', 'group1', 5, '2025-09-06 09:31:31', NULL, NULL, NULL),
(73, 8, 'no', 'group1', 5, '2025-09-06 09:41:49', NULL, NULL, NULL),
(74, 8, 'no', 'group1', 5, '2025-09-06 09:42:29', NULL, NULL, NULL),
(75, 8, 'no', 'group1', 5, '2025-09-06 11:18:31', NULL, NULL, NULL),
(76, 7, 'no', 'group1', 5, '2025-09-06 19:02:50', NULL, NULL, ''),
(77, 16, 'no', 'group1', 5, '2025-09-06 19:03:20', NULL, NULL, ''),
(78, 8, 'no', 'group1', 5, '2025-09-06 19:03:40', NULL, NULL, NULL),
(79, 16, 'no', 'group1', 5, '2025-09-06 19:07:08', 5, '2025-09-08 15:03:30', ''),
(80, 15, 'no', 'group1', 5, '2025-09-06 19:09:00', 5, '2025-09-08 15:03:40', 'فاتوره اختبارر'),
(81, 15, 'yes', 'group1', 5, '2025-09-06 19:09:04', NULL, NULL, 'فاتوره اختبارر'),
(82, 10, 'yes', 'group1', 5, '2025-09-06 19:10:21', NULL, NULL, ''),
(83, 8, 'no', 'group1', 5, '2025-09-06 19:11:36', NULL, NULL, ''),
(84, 16, 'no', 'group1', 5, '2025-09-06 19:12:14', NULL, NULL, ''),
(85, 11, 'yes', 'group1', 5, '2025-09-06 19:12:27', 5, '2025-09-06 19:13:00', ''),
(86, 12, 'yes', 'group1', 5, '2025-09-06 19:15:14', NULL, NULL, ''),
(87, 16, 'no', 'group1', 5, '2025-09-07 06:35:16', NULL, NULL, ''),
(88, 15, 'no', 'group1', 5, '2025-09-07 06:37:55', NULL, NULL, 'kmwekefkfmkmfmf;lemf;lemfl;fml;f'),
(89, 8, 'no', 'group1', 5, '2025-09-07 06:43:16', NULL, NULL, NULL),
(90, 11, 'no', 'group1', 5, '2025-09-07 06:43:40', NULL, NULL, ''),
(91, 8, 'no', 'group1', 5, '2025-09-07 07:07:56', NULL, NULL, NULL),
(93, 17, 'yes', 'group1', 5, '2025-09-07 07:34:43', NULL, NULL, ''),
(94, 16, 'yes', 'group1', 5, '2025-09-07 07:38:23', NULL, NULL, ''),
(95, 0, 'yes', 'group1', 5, '2025-09-07 07:44:05', NULL, NULL, '\n(عميل نقدي: m)'),
(96, 17, 'yes', 'group1', 5, '2025-09-07 07:46:54', NULL, NULL, '(عميل نقدي: m)'),
(97, 11, 'yes', 'group1', 5, '2025-09-07 07:48:48', NULL, NULL, ''),
(98, 11, 'yes', 'group1', 5, '2025-09-07 08:45:56', NULL, NULL, ''),
(99, 17, 'yes', 'group1', 5, '2025-09-07 08:49:34', NULL, NULL, ''),
(100, 17, 'no', 'group1', 5, '2025-09-07 08:50:27', NULL, NULL, ''),
(101, 17, 'no', 'group1', 5, '2025-09-07 08:51:02', 5, '2025-09-07 08:51:34', 'الاال'),
(102, 8, 'no', 'group1', 0, '2025-09-07 10:55:23', NULL, NULL, '(عميل نقدي)'),
(103, 8, 'yes', 'group1', 0, '2025-09-07 11:12:00', 5, '2025-09-07 11:12:56', '(عميل نقدي)'),
(104, 8, 'yes', 'group1', 5, '2025-09-07 11:13:27', 5, '2025-09-07 11:29:31', '(عميل نقدي)'),
(105, 8, 'yes', 'group1', 5, '2025-09-07 11:13:57', NULL, NULL, '(عميل نقدي)'),
(106, 8, 'yes', 'group1', 5, '2025-09-07 11:14:33', NULL, NULL, '(عميل نقدي)'),
(107, 8, 'yes', 'group1', 5, '2025-09-07 11:25:49', 5, '2025-09-07 11:26:07', '(عميل نقدي)'),
(108, 17, 'yes', 'group1', 5, '2025-09-07 11:26:43', NULL, NULL, ''),
(109, 8, 'no', 'group1', 5, '2025-09-07 11:43:48', NULL, NULL, '(عميل نقدي)\n(عميل نقدي)'),
(110, 8, 'no', 'group1', 5, '2025-09-07 11:44:12', NULL, NULL, '(عميل نقدي)'),
(111, 8, 'yes', 'group1', 5, '2025-09-07 11:46:08', NULL, NULL, '(عميل نقدي)'),
(112, 19, 'no', 'group1', 5, '2025-09-07 12:12:03', NULL, NULL, ''),
(113, 8, 'no', 'group1', 5, '2025-09-07 14:48:47', NULL, NULL, 'mqw cfkjlqwnedjknq\n(عميل نقدي)'),
(114, 19, 'no', 'group1', 5, '2025-09-07 15:20:55', NULL, NULL, ''),
(115, 8, 'no', 'group1', 5, '2025-09-07 15:31:09', NULL, NULL, '(عميل نقدي)'),
(116, 8, 'no', 'group1', 5, '2025-09-07 16:27:25', NULL, NULL, '(عميل نقدي)'),
(117, 8, 'no', 'group1', 5, '2025-09-07 16:28:34', NULL, NULL, '(عميل نقدي)'),
(118, 8, 'no', 'group1', 5, '2025-09-07 16:30:27', NULL, NULL, '(عميل نقدي)'),
(119, 8, 'no', 'group1', 5, '2025-09-07 16:50:29', NULL, NULL, '(عميل نقدي)'),
(120, 8, 'no', 'group1', 5, '2025-09-07 17:10:04', NULL, NULL, '(عميل نقدي)'),
(121, 12, 'yes', 'group1', 5, '2025-09-07 19:08:55', 5, '2025-09-07 19:12:31', ''),
(122, 8, 'no', 'group1', 5, '2025-09-07 19:13:09', NULL, NULL, '(عميل نقدي)'),
(123, 8, 'no', 'group1', 5, '2025-09-07 19:14:04', NULL, NULL, '(عميل نقدي)'),
(124, 8, 'no', 'group1', 5, '2025-09-07 19:16:46', NULL, NULL, '(عميل نقدي)'),
(125, 8, 'no', 'group1', 5, '2025-09-07 19:19:04', NULL, NULL, '(عميل نقدي)'),
(126, 8, 'yes', 'group1', 5, '2025-09-07 19:19:40', 5, '2025-09-07 19:22:55', '(عميل نقدي)'),
(127, 8, 'yes', 'group1', 5, '2025-09-07 19:32:15', NULL, NULL, '(عميل نقدي)'),
(128, 10, 'yes', 'group1', 5, '2025-09-07 19:57:18', 5, '2025-09-07 19:57:18', ''),
(129, 8, 'yes', 'group1', 5, '2025-09-07 20:15:24', 5, '2025-09-08 14:35:09', '(عميل نقدي)'),
(130, 8, 'yes', 'group1', 5, '2025-09-07 20:16:52', 5, '2025-09-07 20:17:11', '(عميل نقدي)'),
(131, 8, 'yes', 'group1', 5, '2025-09-07 20:19:02', 5, '2025-09-07 20:19:32', '(عميل نقدي)'),
(132, 65, 'yes', 'group1', 5, '2025-09-07 21:12:52', 5, '2025-09-07 21:13:13', ''),
(133, 8, 'no', 'group1', 5, '2025-09-07 21:16:39', 5, '2025-09-08 14:01:01', 'عميل نقدي تبع الحمد\n(عميل نقدي)'),
(134, 68, 'no', 'group1', 5, '2025-09-08 09:41:41', NULL, NULL, 'mmmm'),
(135, 8, 'no', 'group1', 5, '2025-09-08 09:42:04', NULL, NULL, '(عميل نقدي)'),
(136, 8, 'no', 'group1', 5, '2025-09-08 09:42:30', NULL, NULL, '(عميل نقدي)'),
(137, 8, 'no', 'group1', 5, '2025-09-08 09:42:59', 5, '2025-09-08 15:00:19', '(عميل نقدي)'),
(138, 8, 'no', 'group1', 5, '2025-09-08 09:43:17', 5, '2025-09-08 15:01:12', '(عميل نقدي)'),
(139, 8, 'no', 'group1', 5, '2025-09-08 09:57:10', 5, '2025-09-08 15:01:06', '(عميل نقدي)'),
(141, 65, 'yes', 'group1', 5, '2025-09-08 11:10:17', 5, '2025-09-08 14:03:07', NULL);

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
  `cost_price_per_unit` decimal(10,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() COMMENT 'تاريخ إضافة البند',
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp() COMMENT 'تاريخ آخر تعديل للبند',
  `selling_price` decimal(10,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `invoice_out_items`
--

INSERT INTO `invoice_out_items` (`id`, `invoice_out_id`, `product_id`, `quantity`, `total_price`, `cost_price_per_unit`, `created_at`, `updated_at`, `selling_price`) VALUES
(62, 64, 782, 3.00, 450.00, 120.00, '2025-09-06 09:03:16', NULL, 150.00),
(63, 64, 785, 20.00, 300.00, 15.00, '2025-09-06 09:03:16', NULL, 15.00),
(65, 65, 6, 1.00, 100.00, 100.00, '2025-09-06 09:04:32', NULL, 100.00),
(74, 67, 782, 1.00, 150.00, 120.00, '2025-09-06 09:22:53', NULL, 150.00),
(90, 69, 6, 1.00, 100.00, 100.00, '2025-09-06 09:27:00', NULL, 100.00),
(91, 69, 5, 1.00, 0.00, 0.00, '2025-09-06 09:27:00', NULL, 0.00),
(92, 69, 784, 1.00, 0.00, 0.00, '2025-09-06 09:27:00', NULL, 0.00),
(93, 69, 779, 10.00, 90.00, 100.00, '2025-09-06 09:27:00', NULL, 9.00),
(94, 69, 780, 14.00, 2940.00, 100.00, '2025-09-06 09:27:00', NULL, 210.00),
(105, 76, 5, 1.00, 0.00, 0.00, '2025-09-06 19:02:50', NULL, 0.00),
(106, 77, 5, 1.00, 0.00, 0.00, '2025-09-06 19:03:20', NULL, 0.00),
(107, 79, 782, 1.00, 150.00, 120.00, '2025-09-06 19:07:08', NULL, 150.00),
(108, 80, 782, 1.00, 150.00, 120.00, '2025-09-06 19:09:00', NULL, 150.00),
(109, 80, 785, 1.00, 15.00, 15.00, '2025-09-06 19:09:00', NULL, 15.00),
(110, 81, 782, 1.00, 150.00, 120.00, '2025-09-06 19:09:04', NULL, 150.00),
(111, 81, 785, 1.00, 15.00, 15.00, '2025-09-06 19:09:04', NULL, 15.00),
(112, 82, 782, 1.00, 150.00, 0.00, '2025-09-06 19:10:21', NULL, 150.00),
(113, 83, 5, 1.00, 0.00, 0.00, '2025-09-06 19:11:36', NULL, 0.00),
(114, 84, 6, 1.00, 100.00, 100.00, '2025-09-06 19:12:14', NULL, 100.00),
(115, 84, 5, 1.00, 0.00, 0.00, '2025-09-06 19:12:14', NULL, 0.00),
(116, 85, 6, 1.00, 100.00, 100.00, '2025-09-06 19:12:27', NULL, 100.00),
(117, 85, 5, 1.00, 0.00, 0.00, '2025-09-06 19:12:27', NULL, 0.00),
(118, 86, 6, 1.40, 140.00, 100.00, '2025-09-06 19:15:14', NULL, 100.00),
(119, 86, 5, 1.00, 0.00, 0.00, '2025-09-06 19:15:14', NULL, 0.00),
(120, 87, 782, 1.00, 150.00, 120.00, '2025-09-07 06:35:16', NULL, 150.00),
(121, 88, 782, 1.00, 150.00, 120.00, '2025-09-07 06:37:55', NULL, 150.00),
(122, 88, 785, 1.00, 15.00, 15.00, '2025-09-07 06:37:55', NULL, 15.00),
(123, 90, 5, 1.00, 200.00, 100.00, '2025-09-07 06:43:40', NULL, 200.00),
(124, 92, 782, 1.00, 150.00, 120.00, '2025-09-07 07:11:51', NULL, 150.00),
(125, 92, 785, 1.00, 15.00, 15.00, '2025-09-07 07:11:51', NULL, 15.00),
(126, 93, 782, 2.00, 300.00, 120.00, '2025-09-07 07:34:43', NULL, 150.00),
(127, 93, 785, 2.00, 30.00, 15.00, '2025-09-07 07:34:43', NULL, 15.00),
(128, 93, 6, 1.00, 0.00, 0.00, '2025-09-07 07:34:43', NULL, 0.00),
(129, 93, 5, 1.00, 0.00, 0.00, '2025-09-07 07:34:43', NULL, 0.00),
(130, 94, 782, 2.00, 300.00, 120.00, '2025-09-07 07:38:23', NULL, 150.00),
(131, 94, 785, 2.00, 30.00, 15.00, '2025-09-07 07:38:23', NULL, 15.00),
(132, 94, 6, 1.00, 0.00, 0.00, '2025-09-07 07:38:23', NULL, 0.00),
(133, 94, 5, 1.00, 0.00, 0.00, '2025-09-07 07:38:23', NULL, 0.00),
(134, 95, 782, 1.00, 150.00, 120.00, '2025-09-07 07:44:05', NULL, 150.00),
(135, 95, 785, 1.00, 15.00, 15.00, '2025-09-07 07:44:05', NULL, 15.00),
(136, 96, 782, 1.00, 150.00, 120.00, '2025-09-07 07:46:54', NULL, 150.00),
(137, 96, 785, 1.00, 15.00, 15.00, '2025-09-07 07:46:54', NULL, 15.00),
(138, 97, 6, 1.00, 100.00, 100.00, '2025-09-07 07:48:48', NULL, 100.00),
(139, 97, 5, 1.00, 0.00, 0.00, '2025-09-07 07:48:48', NULL, 0.00),
(140, 98, 6, 1.00, 100.00, 100.00, '2025-09-07 08:45:56', NULL, 100.00),
(141, 98, 5, 1.00, 0.00, 0.00, '2025-09-07 08:45:56', NULL, 0.00),
(142, 99, 6, 1.00, 100.00, 100.00, '2025-09-07 08:49:34', NULL, 100.00),
(143, 99, 5, 1.00, 0.00, 0.00, '2025-09-07 08:49:34', NULL, 0.00),
(144, 99, 780, 1.00, 210.00, 100.00, '2025-09-07 08:49:34', NULL, 210.00),
(145, 100, 782, 1.00, 150.00, 120.00, '2025-09-07 08:50:27', NULL, 150.00),
(146, 101, 782, 1.00, 150.00, 120.00, '2025-09-07 08:51:02', NULL, 150.00),
(147, 102, 5, 8.00, 800.00, 100.00, '2025-09-07 10:55:23', NULL, 100.00),
(148, 103, 5, 1.00, 0.00, 0.00, '2025-09-07 11:12:00', NULL, 0.00),
(149, 103, 785, 1.00, 15.00, 15.00, '2025-09-07 11:12:00', NULL, 15.00),
(150, 104, 779, 1.00, 200.00, 100.00, '2025-09-07 11:13:27', NULL, 200.00),
(151, 105, 782, 1.00, 150.00, 120.00, '2025-09-07 11:13:57', NULL, 150.00),
(152, 106, 782, 1.00, 150.00, 120.00, '2025-09-07 11:14:33', NULL, 150.00),
(153, 107, 785, 1.00, 15.00, 15.00, '2025-09-07 11:25:49', NULL, 15.00),
(154, 108, 780, 1.00, 210.00, 100.00, '2025-09-07 11:26:44', NULL, 210.00),
(155, 109, 779, 1.00, 200.00, 100.00, '2025-09-07 11:43:48', NULL, 200.00),
(156, 110, 782, 1.00, 150.00, 120.00, '2025-09-07 11:44:12', NULL, 150.00),
(157, 111, 6, 1.00, 0.00, 0.00, '2025-09-07 11:46:08', NULL, 0.00),
(158, 111, 5, 1.00, 0.00, 0.00, '2025-09-07 11:46:08', NULL, 0.00),
(159, 112, 6, 1.00, 0.00, 0.00, '2025-09-07 12:12:03', NULL, 0.00),
(160, 113, 785, 1.00, 15.00, 15.00, '2025-09-07 14:48:47', NULL, 15.00),
(161, 114, 778, 2.00, 300.00, 200.00, '2025-09-07 15:20:55', NULL, 150.00),
(162, 115, 5, 1.00, 0.00, 0.00, '2025-09-07 15:31:09', NULL, 0.00),
(163, 115, 6, 1.00, 0.00, 0.00, '2025-09-07 15:31:09', NULL, 0.00),
(164, 116, 5, 1.00, 0.00, 0.00, '2025-09-07 16:27:25', NULL, 0.00),
(165, 116, 782, 1.00, 150.00, 120.00, '2025-09-07 16:27:25', NULL, 150.00),
(166, 117, 6, 1.00, 0.00, 0.00, '2025-09-07 16:28:34', NULL, 0.00),
(167, 118, 782, 1.00, 150.00, 120.00, '2025-09-07 16:30:27', NULL, 150.00),
(168, 119, 785, 1.00, 15.00, 15.00, '2025-09-07 16:50:29', NULL, 15.00),
(169, 120, 782, 1.00, 150.00, 120.00, '2025-09-07 17:10:04', NULL, 150.00),
(170, 121, 785, 1.00, 15.00, 15.00, '2025-09-07 19:08:55', NULL, 15.00),
(171, 122, 785, 5.00, 75.00, 15.00, '2025-09-07 19:13:09', NULL, 15.00),
(172, 123, 785, 1.00, 15.00, 15.00, '2025-09-07 19:14:04', NULL, 15.00),
(173, 124, 6, 1.00, 0.00, 0.00, '2025-09-07 19:16:46', NULL, 0.00),
(174, 125, 6, 1.00, 0.00, 0.00, '2025-09-07 19:19:04', NULL, 0.00),
(175, 125, 782, 1.00, 150.00, 120.00, '2025-09-07 19:19:04', NULL, 150.00),
(176, 126, 782, 1.00, 150.00, 120.00, '2025-09-07 19:19:40', NULL, 150.00),
(177, 127, 782, 95.00, 14250.00, 120.00, '2025-09-07 19:32:15', NULL, 150.00),
(178, 128, 5, 1.00, 100.00, 0.00, '2025-09-07 19:57:18', NULL, 100.00),
(179, 129, 788, 1.00, 200.00, 100.00, '2025-09-07 20:15:24', NULL, 200.00),
(180, 130, 780, 1.00, 210.00, 100.00, '2025-09-07 20:16:52', NULL, 210.00),
(181, 131, 779, 1.00, 200.00, 100.00, '2025-09-07 20:19:02', NULL, 200.00),
(182, 132, 783, 1.00, 1050.00, 1030.00, '2025-09-07 21:12:52', NULL, 1050.00),
(183, 133, 779, 1.00, 100.00, 100.00, '2025-09-07 21:16:39', NULL, 100.00),
(184, 134, 5, 14.00, 1400.00, 50.00, '2025-09-08 09:41:41', NULL, 100.00),
(185, 134, 6, 9.00, 900.00, 50.00, '2025-09-08 09:41:41', NULL, 100.00),
(186, 135, 6, 121.00, 1210.00, 5.00, '2025-09-08 09:42:04', NULL, 10.00),
(187, 136, 6, 0.58, 0.00, 0.00, '2025-09-08 09:42:30', NULL, 0.00),
(188, 137, 5, 83.00, 8300.00, 5.00, '2025-09-08 09:42:59', NULL, 100.00),
(189, 138, 6, 0.02, 0.00, 0.00, '2025-09-08 09:43:17', NULL, 0.00),
(190, 139, 779, 1.00, 140.00, 100.00, '2025-09-08 09:57:10', NULL, 140.00),
(191, 140, 779, 1.00, 150.00, 100.00, '2025-09-08 09:57:45', NULL, 150.00);

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
  `cost_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `selling_price` decimal(10,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول المنتجات المخزنة';

--
-- Dumping data for table `products`
--

INSERT INTO `products` (`id`, `product_code`, `name`, `description`, `unit_of_measure`, `current_stock`, `reorder_level`, `created_at`, `updated_at`, `cost_price`, `selling_price`) VALUES
(4, '212', 'لبس', '', 'قطعه', 399.00, 50.00, '2025-09-01 14:08:20', '2025-09-03 12:23:03', 200.00, 999.99),
(5, '11', 'mm', '', 'كجم', 0.00, 0.00, '2025-09-01 14:21:38', '2025-09-08 09:42:59', 0.00, 0.00),
(6, '1223', 'ggg', '', 'قطعه', 0.00, 50.00, '2025-09-01 15:30:09', '2025-09-08 09:43:17', 0.00, 0.00),
(778, '231', 'مفصله', '', 'قطعه', 1018.00, 0.00, '2025-09-02 07:44:19', '2025-09-07 15:20:55', 200.00, 150.00),
(779, '101000', 'خنجري', '', 'قطعه', 2125.00, 1.00, '2025-09-02 07:45:17', '2025-09-08 10:49:42', 100.00, 0.00),
(780, '2122', 'سكينه', '', 'قطعه', 13.00, 0.00, '2025-09-02 07:55:41', '2025-09-07 20:16:52', 100.00, 210.00),
(781, '21223', 'عربيه', '', 'قطعه', 0.00, 10.00, '2025-09-03 09:33:39', '2025-09-03 22:43:37', 20.00, 60.00),
(782, '212232', 'بتنجان', '', 'قطعه', 0.00, 10.00, '2025-09-03 10:38:53', '2025-09-07 19:32:15', 120.00, 150.00),
(783, '21223s', 'موبايل', 'جايبه من كوكو', 'قطعه', 338.00, 30.00, '2025-09-03 21:17:46', '2025-09-07 21:12:52', 1030.00, 1100.00),
(784, '1101000', 'بلح', '', 'ك', 0.00, 0.00, '2025-09-03 21:42:34', NULL, 0.00, 0.00),
(785, 'd50', 'خمسه ام', '', 'قطعه', 0.00, 5.00, '2025-09-03 21:47:31', '2025-09-07 19:14:04', 15.00, 15.00),
(787, 'd50وس', 'خمسه ام', '', 'قطعه', 0.00, 0.00, '2025-09-04 17:32:02', NULL, 0.00, 0.00),
(788, '2122b', 'لعبه', '', 'قطعه', 99.00, 10.00, '2025-09-07 20:15:06', '2025-09-07 20:15:24', 100.00, 200.00);

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
(14, 2, '', '2025-09-02', '', 10000.00, 'fully_received', 5, '2025-09-02 11:33:40', 5, '2025-09-02 11:35:21'),
(15, 2, '', '2025-09-02', '', 1000.00, 'fully_received', 5, '2025-09-02 14:55:41', 5, '2025-09-02 14:59:18'),
(16, 2, '', '2025-09-02', '', 100000.00, 'pending', 5, '2025-09-02 15:02:47', 5, '2025-09-02 16:17:40'),
(17, 2, '', '2025-09-02', '', 20000.00, 'fully_received', 5, '2025-09-02 15:41:11', 5, '2025-09-02 15:41:52'),
(18, 2, '', '2025-09-02', '', 10000.00, 'fully_received', 5, '2025-09-02 15:57:21', 5, '2025-09-02 16:01:09'),
(19, 2, '', '2025-09-02', '', 0.00, 'fully_received', 5, '2025-09-02 16:20:15', 5, '2025-09-02 16:28:50'),
(20, 2, '', '2025-09-02', '', 0.00, 'pending', 5, '2025-09-02 19:09:40', NULL, NULL),
(21, 2, '', '2025-09-02', '', 40000.00, 'fully_received', 5, '2025-09-02 20:13:53', 5, '2025-09-02 20:39:14'),
(22, 2, '', '2025-09-03', '', 40.00, 'fully_received', 5, '2025-09-03 09:47:40', 5, '2025-09-03 09:48:28'),
(23, 2, '', '2025-09-03', '', 1000.00, 'fully_received', 5, '2025-09-03 10:47:11', 5, '2025-09-03 10:48:05'),
(24, 2, '', '2025-09-03', '', 12000.00, 'fully_received', 5, '2025-09-03 11:51:08', 5, '2025-09-03 11:51:56'),
(25, 2, '', '2025-09-03', '', 20000.00, 'fully_received', 5, '2025-09-03 21:23:27', 5, '2025-09-03 21:25:49'),
(26, 2, '', '2025-09-03', '', 0.00, 'pending', 5, '2025-09-03 21:27:14', NULL, NULL),
(27, 2, '', '2025-09-03', '', 0.00, 'pending', 5, '2025-09-03 21:27:44', NULL, NULL),
(28, 2, '', '2025-09-03', '', 20600.00, 'fully_received', 5, '2025-09-03 21:29:08', 5, '2025-09-03 21:33:27'),
(29, 2, '', '2025-09-03', '', 300.00, 'fully_received', 5, '2025-09-03 21:51:39', 5, '2025-09-03 21:53:31'),
(30, 2, '', '2025-09-04', '', 0.00, 'fully_received', 5, '2025-09-03 22:08:11', 5, '2025-09-03 22:09:56'),
(31, 2, '', '2025-10-03', '', 0.00, 'fully_received', 5, '2025-09-04 15:08:12', 5, '2025-09-08 15:19:54'),
(32, 2, '', '2025-09-04', '', 0.00, 'pending', 5, '2025-09-04 15:33:15', NULL, NULL),
(33, 2, '', '2025-09-04', '', 10.00, 'pending', 5, '2025-09-04 17:31:38', NULL, '2025-09-04 17:31:46'),
(34, 2, '', '2025-09-04', '', 0.00, 'pending', 5, '2025-09-04 17:36:39', NULL, NULL),
(35, 2, '', '2025-09-07', '', 0.00, 'pending', 5, '2025-09-07 15:19:00', NULL, NULL),
(36, 2, '', '2025-09-08', '', 0.00, 'pending', 5, '2025-09-08 11:58:24', NULL, NULL),
(37, 2, '', '2025-09-08', '', 0.00, 'pending', 5, '2025-09-08 14:47:43', NULL, NULL),
(38, 2, '', '2025-09-08', '', 0.00, 'pending', 5, '2025-09-08 15:13:20', NULL, NULL),
(39, 2, '', '2025-09-08', '', 0.00, 'pending', 5, '2025-09-08 15:31:54', NULL, NULL),
(40, 2, '', '2025-09-08', '', 0.00, 'pending', 5, '2025-09-08 15:35:24', NULL, NULL);

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
(19, 12, 780, 5.00, 10.00, 50.00, '2025-09-02 11:40:37', NULL),
(20, 15, 779, 100.00, 10.00, 1000.00, '2025-09-02 14:58:11', NULL),
(21, 17, 779, 20.00, 1000.00, 20000.00, '2025-09-02 15:41:36', NULL),
(22, 18, 779, 1000.00, 10.00, 10000.00, '2025-09-02 15:58:03', NULL),
(24, 16, 779, 1000.00, 100.00, 100000.00, '2025-09-02 16:16:50', NULL),
(31, 21, 4, 200.00, 200.00, 40000.00, '2025-09-02 20:39:14', NULL),
(32, 22, 781, 1.00, 40.00, 40.00, '2025-09-03 09:48:20', NULL),
(33, 23, 782, 10.00, 100.00, 1000.00, '2025-09-03 10:47:52', NULL),
(34, 24, 782, 100.00, 120.00, 12000.00, '2025-09-03 11:51:36', NULL),
(35, 25, 783, 20.00, 1000.00, 20000.00, '2025-09-03 21:24:42', NULL),
(36, 28, 783, 20.00, 1030.00, 20600.00, '2025-09-03 21:31:56', NULL),
(37, 29, 785, 20.00, 15.00, 300.00, '2025-09-03 21:52:59', NULL),
(38, 33, 783, 1.00, 10.00, 10.00, '2025-09-04 17:31:46', NULL);

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
(5, 'admin', 'admin@gmail.com', '$2y$10$9DkMvN8bVe3xV3Mf5qd91O/0YyliyVLUVWVcy8NQkjmNo4.hDuvIq', 'admin', '2025-06-04 13:52:41'),
(6, 'صاصا', 'mustafahussienatya@gmail.com', '$2y$10$3ZBMeMobT7nHFChhNfxp/eH2U983IEHrQQre/qce2cjLCFgtGol1a', 'admin', '2025-09-08 14:38:25'),
(7, 'صاصاي', 'mustafahussienawtya@gmail.com', '$2y$10$D4PUF9Ca5qeprMIGXiRQR.soxFD.FrbWxJKCBF.EUZveqQ0Btaqv6', 'user', '2025-09-08 14:39:24');

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
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=69;

--
-- AUTO_INCREMENT for table `expenses`
--
ALTER TABLE `expenses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `expense_categories`
--
ALTER TABLE `expense_categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `invoices_out`
--
ALTER TABLE `invoices_out`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT COMMENT 'المعرف التلقائي للفاتورة', AUTO_INCREMENT=142;

--
-- AUTO_INCREMENT for table `invoice_out_items`
--
ALTER TABLE `invoice_out_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT COMMENT 'المعرف التلقائي لبند الفاتورة', AUTO_INCREMENT=192;

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT COMMENT 'المعرف التلقائي للمنتج', AUTO_INCREMENT=789;

--
-- AUTO_INCREMENT for table `purchase_invoices`
--
ALTER TABLE `purchase_invoices`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT COMMENT 'المعرف التلقائي لفاتورة الشراء', AUTO_INCREMENT=41;

--
-- AUTO_INCREMENT for table `purchase_invoice_items`
--
ALTER TABLE `purchase_invoice_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT COMMENT 'المعرف التلقائي لبند فاتورة الشراء', AUTO_INCREMENT=39;

--
-- AUTO_INCREMENT for table `suppliers`
--
ALTER TABLE `suppliers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT COMMENT 'المعرف التلقائي للمورد', AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

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
