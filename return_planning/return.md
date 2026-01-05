عند انشاء فاتوره بيع بيحصل الاتي
1- بيسحب من المخزن بناء علي FIFO 
كل منتج بيبقي له دفعاتدخلت المخزن
الجدول
CREATE TABLE `batches` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `product_id` int(11) NOT NULL,
  `qty` decimal(13,4) NOT NULL DEFAULT 0.0000,
  `remaining` decimal(13,4) NOT NULL DEFAULT 0.0000,
  `original_qty` decimal(13,4) NOT NULL DEFAULT 0.0000,
  `unit_cost` decimal(13,4) NOT NULL DEFAULT 0.0000,
  `sale_price` decimal(13,4) DEFAULT NULL,
  `received_at` date DEFAULT NULL,
  `expiry` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `source_invoice_id` int(11) DEFAULT NULL,
  `source_item_id` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `adjusted_by` int(11) DEFAULT NULL,
  `adjusted_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `revert_reason` varchar(255) DEFAULT NULL,
  `cancel_reason` varchar(255) DEFAULT NULL,
  `status` enum('active','consumed','cancelled','reverted') NOT NULL DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
وكمان بيعمل sale_item_allocation 
عشان يوضح اشتريت من اي دفعه
عشان لو في منتج تم شرزها من دفعتين بيتم حساب متوسط ال sale_item
بيساعد لتوضيح سحب من اي بالظبط

CREATE TABLE `sale_item_allocations` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `sale_item_id` int(11) NOT NULL,
  `batch_id` bigint(20) UNSIGNED NOT NULL,
  `qty` decimal(13,4) NOT NULL DEFAULT 0.0000,
  `unit_cost` decimal(13,4) NOT NULL DEFAULT 0.0000,
  `line_cost` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--


بعد ذلك بيقوم بانشاء فاتوره وبنودها

CREATE TABLE `invoices_out` (
  `id` int(11) NOT NULL COMMENT 'المعرف التلقائي للفاتورة',
  `customer_id` int(11) NOT NULL COMMENT 'معرف العميل المرتبط بالفاتورة',
  `delivered` enum('yes','no','canceled','reverted','partial') NOT NULL DEFAULT 'no',
  `invoice_group` enum('group1','group2','group3','group4','group5','group6','group7','group8','group9','group10','group11') NOT NULL COMMENT 'مجموعة الفاتورة (من 1 إلى 11)',
  `created_by` int(11) DEFAULT NULL COMMENT 'معرف المستخدم الذي أنشأ الفاتورة',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() COMMENT 'تاريخ ووقت الإنشاء',
  `updated_by` int(11) DEFAULT NULL COMMENT 'معرف المستخدم الذي آخر من عدل الفاتورة',
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp() COMMENT 'تاريخ ووقت آخر تعديل',
  `notes` text DEFAULT NULL,
  `cancel_reason` varchar(255) DEFAULT NULL,
  `revert_reason` varchar(255) DEFAULT NULL,
  `total_before_discount` decimal(12,2) DEFAULT 0.00 COMMENT 'مجموع البيع قبل أي خصم',
  `discount_type` enum('percent','amount') DEFAULT 'percent' COMMENT 'نوع الخصم',
  `discount_value` decimal(10,2) DEFAULT 0.00 COMMENT 'قيمة الخصم: إذا percent -> تخزن النسبة (مثال: 10) وإلا قيمة المبلغ',
  `discount_amount` decimal(12,2) DEFAULT 0.00 COMMENT 'مبلغ الخصم المحسوب بالعملة',
  `total_after_discount` decimal(12,2) DEFAULT 0.00 COMMENT 'المجموع النهائي بعد الخصم',
  `total_cost` decimal(12,2) DEFAULT 0.00 COMMENT 'اجمالي التكلفة (مخزن للتقارير)',
  `profit_amount` decimal(12,2) DEFAULT 0.00 COMMENT 'اجمالي الربح = total_before_discount - total_cost',
  `paid_amount` decimal(12,2) DEFAULT 0.00,
  `remaining_amount` decimal(12,2) DEFAULT 0.00,
  `work_order_id` int(11) DEFAULT NULL,
  `discount_scope` enum('invoice','items','mixed') DEFAULT 'invoice' COMMENT 'مكان تطبيق الخصم'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول فواتير العملاء الصادرة';

CREATE TABLE `invoice_out_items` (
  `id` int(11) NOT NULL COMMENT 'المعرف التلقائي لبند الفاتورة',
  `invoice_out_id` int(11) NOT NULL COMMENT 'معرف الفاتورة الصادرة (مفتاح أجنبي لجدول invoices_out)',
  `product_id` int(11) NOT NULL COMMENT 'معرف المنتج (مفتاح أجنبي لجدول products)',
  `quantity` decimal(10,2) NOT NULL COMMENT 'الكمية المباعة من المنتج',
  `total_before_discount` decimal(10,2) NOT NULL COMMENT 'السعر الإجمالي للبند قبل الخصم (الكمية * سعر الوحدة)',
  `cost_price_per_unit` decimal(10,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() COMMENT 'تاريخ إضافة البند',
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp() COMMENT 'تاريخ آخر تعديل للبند',
  `selling_price` decimal(10,2) NOT NULL,
  `price_type` enum('retail','wholesale') NOT NULL DEFAULT 'wholesale',
  `returned_quantity` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'الكمية المرتجعة',
  `return_flag` tinyint(1) GENERATED ALWAYS AS (case when `returned_quantity` = `quantity` then 1 else 0 end) STORED COMMENT '1 إذا تم إرجاع البند بالكامل (تمام)، 0 جزئي',
  `available_for_return` decimal(10,2) GENERATED ALWAYS AS (`quantity` - `returned_quantity`) STORED COMMENT 'الكمية المتاحة للمرتجع',
  `discount_type` enum('percent','amount') DEFAULT NULL,
  `discount_value` decimal(10,2) DEFAULT 0.00,
  `discount_amount` decimal(12,2) DEFAULT 0.00,
  `total_after_discount` decimal(12,2) DEFAULT 0.00
  `unit_price_after_discount` decimal(10,2) DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--

بيتم تسجيل حركه العميل

CREATE TABLE `customer_transactions` (
  `id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `transaction_type` enum('invoice','payment','return','deposit','withdraw','adjustment') NOT NULL,
  `amount` decimal(12,2) NOT NULL COMMENT 'موجب للزيادة، سالب للنقصان',
  `description` varchar(255) NOT NULL,
  `invoice_id` int(11) DEFAULT NULL,
  `payment_id` int(11) DEFAULT NULL,
  `return_id` int(11) DEFAULT NULL,
  `wallet_transaction_id` int(11) DEFAULT NULL,
  `work_order_id` int(11) DEFAULT NULL,
  `balance_before` decimal(12,2) DEFAULT 0.00,
  `balance_after` decimal(12,2) DEFAULT 0.00,
  `wallet_before` decimal(12,2) DEFAULT 0.00,
  `wallet_after` decimal(12,2) DEFAULT 0.00,
  `transaction_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


الدفع مؤجل ا جزئي او مدفوغ

لو تم اي دفع 
بيتم انشاء
CREATE TABLE `invoice_payments` (
  `id` int(11) NOT NULL,
  `invoice_id` int(11) NOT NULL,
  `payment_amount` decimal(12,2) NOT NULL,
  `payment_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `payment_method` enum('cash','bank_transfer','check','card','wallet','mixed') DEFAULT 'cash' COMMENT 'طريقة الدفع: cash, bank_transfer, check, card, wallet, mixed',
  `notes` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `wallet_before` decimal(12,2) DEFAULT 0.00 COMMENT 'رصيد المحفظة قبل الدفع',
  `wallet_after` decimal(12,2) DEFAULT 0.00 COMMENT 'رصيد المحفظة بعد الدفع',
  `work_order_id` int(11) DEFAULT NULL COMMENT 'ربط بالشغلانة'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
+
تسجيل الحركه 
customer_transaction 
+ 
لو تم سحب من المحفظه بيسجل هنا
CREATE TABLE `wallet_transactions` (
  `id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `type` enum('deposit','withdraw','refund','invoice_payment') NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `description` varchar(255) NOT NULL,
  `wallet_before` decimal(12,2) DEFAULT 0.00,
  `wallet_after` decimal(12,2) DEFAULT 0.00,
  `transaction_date` datetime NOT NULL DEFAULT current_timestamp(),
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


رصيد الديون بيحدث بناء علي المتبقي او لو سحب المحفظه من 

CREATE TABLE `customers` (
  `id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL COMMENT 'اسم العميل',
  `mobile` varchar(11) NOT NULL COMMENT 'رقم الموبايل (11 رقم)',
  `city` varchar(100) NOT NULL COMMENT 'المدينة',
  `address` varchar(255) DEFAULT NULL COMMENT 'العنوان التفصيلي',
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL COMMENT 'معرف المستخدم الذي أضاف العميل',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() COMMENT 'تاريخ الإضافة',
  `balance` decimal(12,2) DEFAULT 0.00 COMMENT 'الرصيد الحالي (مدين + / دائن -)',
  `wallet` decimal(12,2) DEFAULT 0.00 COMMENT 'رصيد المحفظة',
  `join_date` date DEFAULT curdate() COMMENT 'تاريخ الانضمام'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE `work_orders` (
  `id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL COMMENT 'عنوان الشغلانة',
  `description` text DEFAULT NULL COMMENT 'وصف تفصيلي',
  `status` enum('pending','in_progress','completed','cancelled') DEFAULT 'pending',
  `start_date` date NOT NULL COMMENT 'تاريخ البدء',
  `notes` text DEFAULT NULL COMMENT 'ملاحظات إضافية',
  `total_invoice_amount` decimal(12,2) DEFAULT 0.00 COMMENT 'إجمالي فواتير الشغلانة',
  `total_paid` decimal(12,2) DEFAULT 0.00 COMMENT 'إجمالي المدفوع',
  `total_remaining` decimal(12,2) DEFAULT 0.00 COMMENT 'إجمالي المتبقي',
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--



CREATE TABLE `returns` (
  `id` int(11) PRIMARY KEY AUTO_INCREMENT,
  `invoice_id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `return_date` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `total_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `return_type` ENUM('full','partial','exchange') DEFAULT 'partial',
  `status` ENUM('pending','approved','completed','rejected') DEFAULT 'pending',
  `reason` TEXT,
  `approved_by` int(11) NULL,
  `approved_at` DATETIME NULL,
  `created_by` int(11) NOT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `notes` TEXT
);

CREATE TABLE `return_items` (
  `id` int(11) PRIMARY KEY AUTO_INCREMENT,
  `return_id` int(11) NOT NULL,
  `invoice_item_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` DECIMAL(10,2) NOT NULL,
  `return_price` DECIMAL(10,2) NOT NULL, -- السعر وقت الإرجاع
  `total_amount` DECIMAL(10,2) NOT NULL,
  `batch_allocations` JSON, -- لتتبع أي دفعات تم إرجاعها
  `status` ENUM('pending','restocked','discarded') DEFAULT 'pending',
  `restocked_qty` DECIMAL(10,2) DEFAULT 0.00,
  `restocked_at` DATETIME NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
);

### 🟢 حالة 1: فاتورة مؤجلة

* المرتجع:

  * يقلل من **رصيد العميل**
  * ❌ لا نقدي
  * ❌ لا محفظة

---

### 🟢 حالة 2: فاتورة مدفوعة كليًا

* المرتجع:

  * المستخدم يختار:

    * 💵 نقدي
    * 👛 محفظة

---

### 🟢 حالة 3: فاتورة مدفوعة جزئيًا

مثال:

* إجمالي الفاتورة: 200
* المدفوع: 100
* المرتجع: 50

النتيجة:

* المتبقي على العميل = 50
* الجزء المرتجع:

  * يُسأل المستخدم:

    * رد نقدي؟
    * ولا إضافة للمحفظة؟
