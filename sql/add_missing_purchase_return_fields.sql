-- إضافة حقل qty_returned لجدول purchase_invoice_items
ALTER TABLE `purchase_invoice_items` 
ADD COLUMN `qty_returned` DECIMAL(13,4) DEFAULT 0.0000 COMMENT 'الكمية المرتجعة من هذا البند' 
AFTER `qty_adjusted`;

-- إضافة حقل batch_remaining_before لجدول purchase_return_items
ALTER TABLE `purchase_return_items` 
ADD COLUMN `batch_remaining_before` DECIMAL(13,4) DEFAULT NULL COMMENT 'الكمية المتبقية في الدفعة قبل الإرجاع' 
AFTER `reason`;
