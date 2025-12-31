
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
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp() COMMENT 'تاريخ آخر تعديل',
  `cancel_reason` varchar(255) DEFAULT NULL,
  `revert_reason` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول فواتير المشتريات (الوارد)';




CREATE TABLE `purchase_invoice_items` (
  `id` int(11) NOT NULL COMMENT 'المعرف التلقائي لبند فاتورة الشراء',
  `batch_id` bigint(20) UNSIGNED DEFAULT NULL,
  `purchase_invoice_id` int(11) NOT NULL COMMENT 'معرف فاتورة الشراء (FK to purchase_invoices.id)',
  `product_id` int(11) NOT NULL COMMENT 'معرف المنتج (FK to products.id)',
  `quantity` decimal(13,4) NOT NULL DEFAULT 0.0000,
  `cost_price_per_unit` decimal(10,2) NOT NULL COMMENT 'سعر التكلفة للوحدة من المورد',
  `total_cost` decimal(12,2) NOT NULL COMMENT 'التكلفة الإجمالية للبند (الكمية المطلوبة * سعر التكلفة)',
  `sale_price` decimal(13,4) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  `qty_received` decimal(13,4) NOT NULL DEFAULT 0.0000,
  `qty_adjusted` decimal(13,4) DEFAULT NULL,
  `adjustment_reason` varchar(255) DEFAULT NULL,
  `adjusted_by` int(11) DEFAULT NULL,
  `adjusted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
  `selling_price` decimal(10,2) NOT NULL,
  `retail_price` decimal(10,2) DEFAULT NULL COMMENT 'سعر البيع القطاعي (للعميل الفرد)'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول المنتجات المخزنة';



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




CREATE TABLE `purchase_returns` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `return_number` varchar(50) NOT NULL COMMENT 'رقم المرتجع (مثل: PR-2024-001)',
  `supplier_id` int(11) NOT NULL COMMENT 'المورد المرتجع منه',
  `purchase_invoice_id` int(11) DEFAULT NULL COMMENT 'فاتورة الشراء الأصلية (إن وجدت)',
  `return_date` date NOT NULL COMMENT 'تاريخ المرتجع',
  `return_type` enum('supplier_return','damaged','expired','other') NOT NULL COMMENT 'نوع المرتجع',
  `return_reason` varchar(500) DEFAULT NULL COMMENT 'سبب المرتجع',
  `total_amount` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'إجمالي قيمة المرتجع',
  `status` enum('pending','approved','completed','cancelled') NOT NULL DEFAULT 'pending',
  `created_by` int(11) DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `return_number` (`return_number`),
  KEY `supplier_id` (`supplier_id`),
  KEY `purchase_invoice_id` (`purchase_invoice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `purchase_return_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `purchase_return_id` int(11) NOT NULL,
  `batch_id` bigint(20) UNSIGNED NOT NULL COMMENT 'الدفعة المرتجعة',
  `product_id` int(11) NOT NULL,
  `quantity` decimal(13,4) NOT NULL COMMENT 'الكمية المرتجعة',
  `unit_cost` decimal(13,4) NOT NULL COMMENT 'سعر التكلفة وقت الشراء',
  `total_cost` decimal(12,2) NOT NULL COMMENT 'إجمالي القيمة',
  `reason` varchar(255) DEFAULT NULL COMMENT 'سبب مرتجع البند',
  `batch_remaining_before` decimal(13,4) NOT NULL COMMENT 'الرصيد قبل المرتجع',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `purchase_return_id` (`purchase_return_id`),
  KEY `batch_id` (`batch_id`),
  KEY `product_id` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- إضافة حقول لتعقب المرتجعات في جدول batches
ALTER TABLE `batches` 
ADD COLUMN `return_reason` varchar(255) DEFAULT NULL AFTER `cancel_reason`,
ADD COLUMN `returned_by` int(11) DEFAULT NULL AFTER `adjusted_by`,
ADD COLUMN `returned_at` datetime DEFAULT NULL AFTER `adjusted_at`,
ADD COLUMN `purchase_return_id` int(11) DEFAULT NULL AFTER `source_invoice_id`;

-- تحديث الحقول الموجودة
ALTER TABLE `batches` 
MODIFY COLUMN `status` enum('active','consumed','cancelled','returned','expired','damaged') NOT NULL DEFAULT 'active';



ALTER TABLE `purchase_invoice_items`
ADD COLUMN `qty_returned` decimal(13,4) NOT NULL DEFAULT 0.0000 AFTER `qty_adjusted`;