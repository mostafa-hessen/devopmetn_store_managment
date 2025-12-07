1.1 ALTER TABLE customers 
ADD COLUMN balance DECIMAL(12,2) DEFAULT 0.00 COMMENT 'الرصيد الحالي (مدين + / دائن -)',
ADD COLUMN wallet DECIMAL(12,2) DEFAULT 0.00 COMMENT 'رصيد المحفظة',
ADD COLUMN join_date DATE DEFAULT CURRENT_DATE COMMENT 'تاريخ الانضمام';


1.2- -- =============================================
-- 1. أولاً: إنشاء نسخة احتياطية (اختياري ولكن مهم)
-- =============================================
CREATE TABLE IF NOT EXISTS invoices_out_backup_before_update AS 
SELECT * FROM invoices_out 
WHERE id <= 122;


-- =============================================
-- 2. التحقق من البيانات قبل التحديث
-- =============================================
SELECT 
    id,
    total_before_discount,
    discount_type,
    discount_value,
    discount_amount,
    total_after_discount,
    total_cost,
    profit_amount,
    paid_amount,
    remaining_amount
FROM invoices_out 
WHERE id <= 5  -- شاهد أول 5 فواتير للتحقق
ORDER BY id;

-- =============================================
-- 3. بدء Transaction للعمل الآمن
-- =============================================
START TRANSACTION;

-- =============================================
-- 4. التحديث الرئيسي للفواتير من 1 إلى 122
-- =============================================
UPDATE invoices_out inv
LEFT JOIN (
    SELECT 
        invoice_out_id,
        SUM(total_price) as total_before_discount,
        SUM(cost_price_per_unit * quantity) as total_cost
    FROM invoice_out_items
    GROUP BY invoice_out_id
) items ON inv.id = items.invoice_out_id
SET 
    inv.total_before_discount = COALESCE(items.total_before_discount, 0),
    inv.total_cost = COALESCE(items.total_cost, 0),
    inv.discount_amount = 
        CASE 
            WHEN inv.discount_type = 'percent' 
                THEN ROUND(COALESCE(items.total_before_discount, 0) * (COALESCE(inv.discount_value, 0) / 100), 2)
            WHEN inv.discount_type = 'amount' 
                THEN COALESCE(inv.discount_value, 0)
            ELSE 0
        END,
    inv.total_after_discount = 
        COALESCE(items.total_before_discount, 0) - 
        CASE 
            WHEN inv.discount_type = 'percent' 
                THEN ROUND(COALESCE(items.total_before_discount, 0) * (COALESCE(inv.discount_value, 0) / 100), 2)
            WHEN inv.discount_type = 'amount' 
                THEN COALESCE(inv.discount_value, 0)
            ELSE 0
        END,
    inv.profit_amount = 
        (COALESCE(items.total_before_discount, 0) - 
        CASE 
            WHEN inv.discount_type = 'percent' 
                THEN ROUND(COALESCE(items.total_before_discount, 0) * (COALESCE(inv.discount_value, 0) / 100), 2)
            WHEN inv.discount_type = 'amount' 
                THEN COALESCE(inv.discount_value, 0)
            ELSE 0
        END) - COALESCE(items.total_cost, 0),
    inv.remaining_amount = 
        (COALESCE(items.total_before_discount, 0) - 
        CASE 
            WHEN inv.discount_type = 'percent' 
                THEN ROUND(COALESCE(items.total_before_discount, 0) * (COALESCE(inv.discount_value, 0) / 100), 2)
            WHEN inv.discount_type = 'amount' 
                THEN COALESCE(inv.discount_value, 0)
            ELSE 0
        END) - COALESCE(inv.paid_amount, 0)
WHERE inv.id <= 122;

-- =============================================
-- 5. التحقق من عدد الفواتير المحدثة
-- =============================================
SELECT CONCAT('تم تحديث ', ROW_COUNT(), ' فاتورة') as result;

-- =============================================
-- 6. عرض عينة من النتائج للتحقق
-- =============================================
SELECT 
    id,
    customer_id,
    total_before_discount,
    discount_type,
    discount_value,
    discount_amount,
    total_after_discount,
    total_cost,
    profit_amount,
    paid_amount,
    remaining_amount,
    ROUND(profit_amount / NULLIF(total_after_discount, 0) * 100, 2) as profit_percentage
FROM invoices_out 
WHERE id <= 10  -- عرض أول 10 فواتير
ORDER BY id;

-- =============================================
-- 7. تحقق من التطابق بين الحقول المحسوبة
-- =============================================
SELECT 
    id,
    total_before_discount,
    discount_amount,
    total_after_discount,
    (total_before_discount - discount_amount) as calculated_total_after_discount,
    total_after_discount - (total_before_discount - discount_amount) as difference,
    CASE 
        WHEN total_after_discount = (total_before_discount - discount_amount) 
        THEN 'صحيح' 
        ELSE 'خطأ' 
    END as status
FROM invoices_out 
WHERE id <= 122 
HAVING difference != 0
LIMIT 10;

-- =============================================
-- 8. اختيار: حفظ التغييرات أو التراجع
-- =============================================
-- إذا كانت كل النتائج صحيحة، نفذ:
-- COMMIT;

-- إذا وجدت أخطاء وتريد التراجع، نفذ:
-- ROLLBACK;

-- =============================================
-- 9. بعد COMMIT: تحقق نهائي
-- =============================================
-- بعد تنفيذ COMMIT، يمكنك التحقق النهائي:
/*
SELECT 
    COUNT(*) as total_invoices,
    SUM(total_before_discount) as total_sales,
    SUM(discount_amount) as total_discounts,
    SUM(total_after_discount) as net_sales,
    SUM(total_cost) as total_costs,
    SUM(profit_amount) as total_profit
FROM invoices_out 
WHERE id <= 122;
*/



2- UPDATE customers c
LEFT JOIN (
    SELECT customer_id, SUM(remaining_amount) AS total_remaining
    FROM invoices_out
    
   WHERE delivered IN ('no', 'partial') 
    GROUP BY customer_id
) x ON c.id = x.customer_id
SET c.balance = COALESCE(x.total_remaining, 0);





3.2- ALTER TABLE invoice_out_items
-- 1. الكمية المرتجعة
ADD COLUMN returned_quantity DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT 'الكمية المرتجعة',

-- 2. علامة البند إذا تم إرجاعه بالكامل (TRUE/FALSE)
ADD COLUMN return_flag TINYINT(1) AS (CASE WHEN returned_quantity = quantity THEN 1 ELSE 0 END) PERSISTENT COMMENT '1 إذا تم إرجاع البند بالكامل، 0 جزئي',

-- 3. الكمية المتاحة للمرتجع (محسوبة تلقائيًا)
ADD COLUMN available_for_return DECIMAL(10,2) AS (quantity - returned_quantity) PERSISTENT COMMENT 'الكمية المتاحة للمرتجع',

-- 4. السعر الإجمالي للبند بعد المرتجع (محسوب تلقائيًا)
MODIFY COLUMN total_price DECIMAL(10,2) AS ((quantity - returned_quantity) * selling_price) PERSISTENT COMMENT 'السعر الإجمالي للبند بعد المرتجع';





4- ALTER TABLE invoice_payments 
ADD COLUMN wallet_before DECIMAL(12,2) DEFAULT 0.00 COMMENT 'رصيد المحفظة قبل الدفع',
ADD COLUMN wallet_after DECIMAL(12,2) DEFAULT 0.00 COMMENT 'رصيد المحفظة بعد الدفع',
ADD COLUMN work_order_id INT NULL COMMENT 'ربط بالشغلانة';


5- CREATE TABLE work_orders (
    id INT PRIMARY KEY AUTO_INCREMENT,
    customer_id INT NOT NULL,
    title VARCHAR(255) NOT NULL COMMENT 'عنوان الشغلانة',
    description TEXT COMMENT 'وصف تفصيلي',
    status ENUM('pending', 'in_progress', 'completed', 'cancelled') DEFAULT 'pending',
    start_date DATE NOT NULL COMMENT 'تاريخ البدء',
    notes TEXT COMMENT 'ملاحظات إضافية',
    total_invoice_amount DECIMAL(12,2) DEFAULT 0.00 COMMENT 'إجمالي فواتير الشغلانة',
    total_paid DECIMAL(12,2) DEFAULT 0.00 COMMENT 'إجمالي المدفوع',
    total_remaining DECIMAL(12,2) DEFAULT 0.00 COMMENT 'إجمالي المتبقي',
    progress_percent INT DEFAULT 0 COMMENT 'نسبة الإنجاز %',
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(id),
    FOREIGN KEY (created_by) REFERENCES users(id),
    INDEX idx_status (status),
    INDEX idx_customer (customer_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


6- CREATE TABLE deposits (
    id INT PRIMARY KEY AUTO_INCREMENT,
    customer_id INT NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    wallet_before DECIMAL(12,2) DEFAULT 0.00 COMMENT 'رصيد المحفظة قبل الإيداع',
    wallet_after DECIMAL(12,2) DEFAULT 0.00 COMMENT 'رصيد المحفظة بعد الإيداع',
    description VARCHAR(255) NOT NULL,
    deposit_date DATE NOT NULL,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(id),
    FOREIGN KEY (created_by) REFERENCES users(id),
    INDEX idx_customer_date (customer_id, deposit_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



7--- 2.1: إنشاء جدول customer_transactions إذا لم يكن موجوداً
CREATE TABLE IF NOT EXISTS customer_transactions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    customer_id INT NOT NULL,
    transaction_type ENUM('invoice', 'payment', 'return', 'deposit', 'adjustment') NOT NULL,
    amount DECIMAL(12,2) NOT NULL COMMENT 'موجب للزيادة، سالب للنقصان',
    description VARCHAR(255) NOT NULL,
    invoice_id INT NULL,
    payment_id INT NULL,
    return_id INT NULL,
    deposit_id INT NULL,
    work_order_id INT NULL,
    balance_before DECIMAL(12,2) DEFAULT 0.00,
    balance_after DECIMAL(12,2) DEFAULT 0.00,
    wallet_before DECIMAL(12,2) DEFAULT 0.00,
    wallet_after DECIMAL(12,2) DEFAULT 0.00,
    transaction_date DATE NOT NULL,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(id),
    FOREIGN KEY (invoice_id) REFERENCES invoices_out(id),
    FOREIGN KEY (payment_id) REFERENCES invoice_payments(id),
    <!-- FOREIGN KEY (return_id) REFERENCES returns(id), مهمش عشان لسه معملناش الجدول ده  -->
    FOREIGN KEY (deposit_id) REFERENCES deposits(id),
    FOREIGN KEY (work_order_id) REFERENCES work_orders(id),
    FOREIGN KEY (created_by) REFERENCES users(id),
    INDEX idx_customer_type (customer_id, transaction_type),
    INDEX idx_date (transaction_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2.2: التحقق من إنشاء الجدو

START TRANSACTION;

-- 1️⃣ إنشاء جدول الحركات لو مش موجود
CREATE TABLE IF NOT EXISTS customer_transactions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    customer_id INT NOT NULL,
    transaction_type ENUM('invoice', 'payment', 'return', 'deposit', 'adjustment') NOT NULL,
    amount DECIMAL(12,2) NOT NULL COMMENT 'موجب للزيادة، سالب للنقصان',
    description VARCHAR(255) NOT NULL,
    invoice_id INT NULL,
    payment_id INT NULL,
    return_id INT NULL,
    deposit_id INT NULL,
    work_order_id INT NULL,
    balance_before DECIMAL(12,2) DEFAULT 0.00,
    balance_after DECIMAL(12,2) DEFAULT 0.00,
    wallet_before DECIMAL(12,2) DEFAULT 0.00,
    wallet_after DECIMAL(12,2) DEFAULT 0.00,
    transaction_date DATE NOT NULL,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(id),
    FOREIGN KEY (invoice_id) REFERENCES invoices_out(id),
    FOREIGN KEY (payment_id) REFERENCES invoice_payments(id),
    -- FOREIGN KEY (return_id) REFERENCES returns(id) -- جدول الReturns مش موجود بعد
    FOREIGN KEY (deposit_id) REFERENCES deposits(id),
    FOREIGN KEY (work_order_id) REFERENCES work_orders(id),
    FOREIGN KEY (created_by) REFERENCES users(id),
    INDEX idx_customer_type (customer_id, transaction_type),
    INDEX idx_date (transaction_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2️⃣ إدخال الحركات (فواتير + دفعات) مع رصيد تراكمي
INSERT INTO customer_transactions
(customer_id, transaction_type, amount, description, invoice_id, payment_id, transaction_date, created_by, balance_before, balance_after)
WITH ordered_transactions AS (
    SELECT
        t.customer_id,
        t.transaction_type,
        t.amount,
        t.description,
        t.invoice_id,
        t.payment_id,
        t.transaction_date,
        t.created_by,
        CASE WHEN t.transaction_type = 'invoice' THEN t.amount ELSE -t.amount END AS signed_amount
    FROM (
        -- الفواتير فقط (delivered = no, yes, partial)
        SELECT
            invoices_out.customer_id,
            'invoice' AS transaction_type,
            invoices_out.total_after_discount AS amount,
            CONCAT('فاتورة رقم ', invoices_out.id) AS description,
            invoices_out.id AS invoice_id,
            NULL AS payment_id,
            invoices_out.created_at AS transaction_date,
            COALESCE(invoices_out.created_by, 5) AS created_by
        FROM invoices_out
        WHERE invoices_out.delivered IN ('no', 'yes', 'partial')
          AND invoices_out.total_after_discount > 0

        UNION ALL

        -- الدفعات المرتبطة بالفواتير
        SELECT
            invoices_out.customer_id,
            'payment' AS transaction_type,
            invoice_payments.payment_amount AS amount,
            CONCAT('دفعة على فاتورة رقم ', invoices_out.id) AS description,
            invoices_out.id AS invoice_id,
            invoice_payments.id AS payment_id,
            invoice_payments.payment_date AS transaction_date,
            COALESCE(invoice_payments.created_by, 5) AS created_by
        FROM invoices_out
        JOIN invoice_payments ON invoice_payments.invoice_id = invoices_out.id
        WHERE invoices_out.delivered IN ('no', 'yes', 'partial')
    ) AS t
)
SELECT
    customer_id,
    transaction_type,
    amount,
    description,
    invoice_id,
    payment_id,
    transaction_date,
    created_by,
    COALESCE(
        LAG(cumulative_balance) OVER(PARTITION BY customer_id ORDER BY transaction_date, invoice_id, payment_id),
        0
    ) AS balance_before,
    COALESCE(
        LAG(cumulative_balance) OVER(PARTITION BY customer_id ORDER BY transaction_date, invoice_id, payment_id),
        0
    ) + signed_amount AS balance_after
FROM (
    SELECT *,
        SUM(signed_amount) OVER(PARTITION BY customer_id ORDER BY transaction_date, invoice_id, payment_id ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) AS cumulative_balance
    FROM ordered_transactions
) AS cum;

COMMIT;

COMMIT;



** استعلام مهم جدا جدا جدا
SELECT invoices_out.id,
       invoices_out.customer_id,
       invoices_out.total_after_discount,
       invoices_out.paid_amount,
       invoices_out.remaining,
       invoices_out.created_at
FROM invoices_out
LEFT JOIN invoice_payments
       ON invoices_out.id = invoice_payments.invoice_id
       AND invoice_payments.payment_amount > 0
WHERE invoices_out.customer_id = ?
  AND invoices_out.delivered = 'yes'
  AND invoice_payments.invoice_id IS NULL
ORDER BY invoices_out.created_at ASC;

**


9- المرتجعات