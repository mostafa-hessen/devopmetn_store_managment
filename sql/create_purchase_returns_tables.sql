-- جدول المرتجعات للمشتريات
CREATE TABLE IF NOT EXISTS `purchase_returns` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `return_number` varchar(50) NOT NULL COMMENT 'رقم المرتجع التلقائي',
  `supplier_id` int(11) NOT NULL COMMENT 'معرف المورد',
  `purchase_invoice_id` int(11) NOT NULL COMMENT 'معرف فاتورة الشراء الأصلية',
  `return_date` date NOT NULL COMMENT 'تاريخ الإرجاع',
  `return_type` enum('supplier_return','damaged','expired','other') NOT NULL COMMENT 'نوع المرتجع',
  `return_reason` text DEFAULT NULL COMMENT 'سبب الإرجاع',
  `total_amount` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'إجمالي قيمة المرتجع',
  `status` enum('pending','approved','completed','cancelled') NOT NULL DEFAULT 'pending' COMMENT 'حالة المرتجع',
  `created_by` int(11) DEFAULT NULL COMMENT 'معرف المستخدم الذي أنشأ المرتجع',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `approved_by` int(11) DEFAULT NULL COMMENT 'معرف المستخدم الذي وافق على المرتجع',
  `approved_at` datetime DEFAULT NULL,
  `completed_by` int(11) DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `return_number` (`return_number`),
  KEY `supplier_id` (`supplier_id`),
  KEY `purchase_invoice_id` (`purchase_invoice_id`),
  KEY `status` (`status`),
  KEY `return_date` (`return_date`),
  CONSTRAINT `fk_pr_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_pr_invoice` FOREIGN KEY (`purchase_invoice_id`) REFERENCES `purchase_invoices` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول مرتجعات المشتريات';

-- جدول بنود المرتجعات
CREATE TABLE IF NOT EXISTS `purchase_return_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `purchase_return_id` int(11) NOT NULL COMMENT 'معرف المرتجع',
  `purchase_invoice_item_id` int(11) DEFAULT NULL COMMENT 'معرف بند الفاتورة الأصلي',
  `batch_id` bigint(20) UNSIGNED NOT NULL COMMENT 'معرف الدفعة المرتجعة',
  `product_id` int(11) NOT NULL COMMENT 'معرف المنتج',
  `quantity` decimal(13,4) NOT NULL DEFAULT 0.0000 COMMENT 'الكمية المرتجعة',
  `unit_cost` decimal(13,4) NOT NULL DEFAULT 0.0000 COMMENT 'تكلفة الوحدة',
  `total_cost` decimal(13,4) NOT NULL DEFAULT 0.0000 COMMENT 'إجمالي التكلفة',
  `reason` text DEFAULT NULL COMMENT 'سبب إرجاع هذا البند',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `purchase_return_id` (`purchase_return_id`),
  KEY `product_id` (`product_id`),
  KEY `batch_id` (`batch_id`),
  CONSTRAINT `fk_pri_return` FOREIGN KEY (`purchase_return_id`) REFERENCES `purchase_returns` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pri_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_pri_batch` FOREIGN KEY (`batch_id`) REFERENCES `batches` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول بنود مرتجعات المشتريات';

-- إضافة حقول جديدة لجدول batches إذا لم تكن موجودة
ALTER TABLE `batches` 
ADD COLUMN IF NOT EXISTS `returned_by` int(11) DEFAULT NULL COMMENT 'من قام بالإرجاع',
ADD COLUMN IF NOT EXISTS `returned_at` datetime DEFAULT NULL COMMENT 'تاريخ الإرجاع',
ADD COLUMN IF NOT EXISTS `return_reason` varchar(255) DEFAULT NULL COMMENT 'سبب الإرجاع';

-- تحديث enum status في batches لإضافة 'returned'
ALTER TABLE `batches` 
MODIFY COLUMN `status` enum('active','consumed','cancelled','reverted','returned') NOT NULL DEFAULT 'active';
