-- جدول لتسجيل تعديلات الفواتير (اختياري - للتوثيق المحاسبي)
-- يمكن تشغيل هذا الملف في قاعدة البيانات إذا أردت تتبع التعديلات بشكل منفصل

CREATE TABLE IF NOT EXISTS `invoice_adjustments` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `invoice_id` INT(11) NOT NULL COMMENT 'معرف الفاتورة',
    `adjustment_type` ENUM('discount_add', 'price_change', 'item_discount_add', 'item_price_change') DEFAULT 'discount_add' COMMENT 'نوع التعديل',
    `discount_type` ENUM('percent', 'amount') DEFAULT NULL COMMENT 'نوع الخصم (نسبة/مبلغ)',
    `discount_value` DECIMAL(10,2) DEFAULT 0.00 COMMENT 'قيمة الخصم',
    `discount_amount` DECIMAL(12,2) DEFAULT 0.00 COMMENT 'مبلغ الخصم الفعلي بالعملة',
    `old_total_after_discount` DECIMAL(12,2) DEFAULT 0.00 COMMENT 'الإجمالي قبل التعديل',
    `new_total_after_discount` DECIMAL(12,2) DEFAULT 0.00 COMMENT 'الإجمالي بعد التعديل',
    `old_remaining_amount` DECIMAL(12,2) DEFAULT 0.00 COMMENT 'المتبقي قبل التعديل',
    `new_remaining_amount` DECIMAL(12,2) DEFAULT 0.00 COMMENT 'المتبقي بعد التعديل',
    `old_profit_amount` DECIMAL(12,2) DEFAULT 0.00 COMMENT 'الربح قبل التعديل',
    `new_profit_amount` DECIMAL(12,2) DEFAULT 0.00 COMMENT 'الربح بعد التعديل',
    `refund_method` ENUM('cash', 'wallet', 'balance_reduction', 'none') DEFAULT 'none' COMMENT 'طريقة الإرجاع',
    `refund_amount` DECIMAL(12,2) DEFAULT 0.00 COMMENT 'مبلغ الإرجاع',
    `reason` TEXT NOT NULL COMMENT 'سبب التعديل',
    `items_data` TEXT DEFAULT NULL COMMENT 'بيانات البنود المعدلة (JSON)',
    `customer_balance_before` DECIMAL(12,2) DEFAULT 0.00 COMMENT 'رصيد العميل قبل التعديل',
    `customer_balance_after` DECIMAL(12,2) DEFAULT 0.00 COMMENT 'رصيد العميل بعد التعديل',
    `customer_wallet_before` DECIMAL(12,2) DEFAULT 0.00 COMMENT 'محفظة العميل قبل التعديل',
    `customer_wallet_after` DECIMAL(12,2) DEFAULT 0.00 COMMENT 'محفظة العميل بعد التعديل',
    `work_order_id` INT(11) DEFAULT NULL COMMENT 'معرف الشغلانة (إن وجدت)',
    `created_by` INT(11) NOT NULL COMMENT 'المستخدم الذي قام بالتعديل',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ ووقت التعديل',
    PRIMARY KEY (`id`),
    INDEX `idx_invoice_id` (`invoice_id`),
    INDEX `idx_created_at` (`created_at`),
    INDEX `idx_created_by` (`created_by`),
    INDEX `idx_work_order_id` (`work_order_id`),
    FOREIGN KEY (`invoice_id`) REFERENCES `invoices_out`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT,
    FOREIGN KEY (`work_order_id`) REFERENCES `work_orders`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='سجل تعديلات الفواتير';

