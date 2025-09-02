CREATE TABLE `users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(50) NOT NULL UNIQUE,
  `email` VARCHAR(100) NOT NULL UNIQUE,
  `password` VARCHAR(255) NOT NULL,
  `role` ENUM('user', 'admin') NOT NULL DEFAULT 'user',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE `customers` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(150) NOT NULL COMMENT 'اسم العميل',
  `mobile` VARCHAR(11) NOT NULL UNIQUE COMMENT 'رقم الموبايل (11 رقم)',
  `city` VARCHAR(100) NOT NULL COMMENT 'المدينة',
  `address` VARCHAR(255) NULL COMMENT 'العنوان التفصيلي',
  `created_by` INT NULL COMMENT 'معرف المستخدم الذي أضاف العميل', -- <<< العمود الجديد
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ الإضافة',

  -- <<< قيد المفتاح الأجنبي
  CONSTRAINT `fk_customer_user` -- اسم القيد (اختر أي اسم)
  FOREIGN KEY (`created_by`)   -- العمود في هذا الجدول
  REFERENCES `users`(`id`)     -- يرجع إلى جدول users (عمود id)
  ON DELETE SET NULL          -- <<< ماذا يحدث عند حذف المستخدم؟
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE `invoices_out` (
  `id` INT AUTO_INCREMENT PRIMARY KEY COMMENT 'المعرف التلقائي للفاتورة',
  `customer_id` INT NOT NULL COMMENT 'معرف العميل المرتبط بالفاتورة',
  `delivered` ENUM('yes', 'no') NOT NULL DEFAULT 'no' COMMENT 'هل تم التسليم؟ (نعم/لا)',
  `invoice_group` ENUM(
      'group1', 'group2', 'group3', 'group4', 'group5',
      'group6', 'group7', 'group8', 'group9', 'group10', 'group11'
  ) NOT NULL COMMENT 'مجموعة الفاتورة (من 1 إلى 11)',
  `created_by` INT NULL COMMENT 'معرف المستخدم الذي أنشأ الفاتورة',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ ووقت الإنشاء',
  `updated_by` INT NULL COMMENT 'معرف المستخدم الذي آخر من عدل الفاتورة',
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP COMMENT 'تاريخ ووقت آخر تعديل',

  -- --- المفاتيح الأجنبية (Foreign Keys) ---

  -- ربط الفاتورة بالعميل
  CONSTRAINT `fk_invoice_customer`
    FOREIGN KEY (`customer_id`)
    REFERENCES `customers`(`id`)
    ON DELETE RESTRICT -- منع حذف العميل إذا كان لديه فواتير
    ON UPDATE CASCADE, -- تحديث تلقائي عند تعديل id العميل (نادر)

  -- ربط الفاتورة بمنشئها
  CONSTRAINT `fk_invoice_creator`
    FOREIGN KEY (`created_by`)
    REFERENCES `users`(`id`)
    ON DELETE SET NULL -- جعل الحقل فارغاً عند حذف المستخدم
    ON UPDATE CASCADE,

  -- ربط الفاتورة بآخر مُعدل
  CONSTRAINT `fk_invoice_updater`
    FOREIGN KEY (`updated_by`)
    REFERENCES `users`(`id`)
    ON DELETE SET NULL -- جعل الحقل فارغاً عند حذف المستخدم
    ON UPDATE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول فواتير العملاء الصادرة';