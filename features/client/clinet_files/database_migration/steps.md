# التعديلات الكاملة على قاعدة البيانات (بالترتيب)

## 1. إضافة الحقول الجديدة لجدول `invoices_out`

```sql
-- الخطوة 1: إضافة الحقول الجديدة
ALTER TABLE invoices_out 
ADD COLUMN paid_amount DECIMAL(12,2) DEFAULT 0.00,
ADD COLUMN remaining_amount DECIMAL(12,2) DEFAULT 0.00;
```

## 2. إنشاء جدول المدفوعات الجديد

```sql
-- الخطوة 2: إنشاء جدول المدفوعات
CREATE TABLE `invoice_payments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `invoice_id` int(11) NOT NULL,
  `payment_amount` decimal(12,2) NOT NULL,
  `payment_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `payment_method` enum('cash','bank_transfer','check','card','mixed' , ' wallet') DEFAULT 'cash',

  `notes` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_payment_invoice` (`invoice_id`),
  KEY `fk_payment_user` (`created_by`),
  CONSTRAINT `fk_payment_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `invoices_out` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_payment_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
);
```

## 3. تحديث البيانات القديمة (الفواتير المدفوعة)

```sql
-- الخطوة 3: تحديث الفواتير المدفوعة قديماً
UPDATE invoices_out 
SET paid_amount = COALESCE(total_after_discount, total_before_discount),
    remaining_amount = 0
WHERE delivered = 'yes' 
AND (paid_amount = 0 OR paid_amount IS NULL);
```

## 4. إنشاء مدفوعات افتراضية للبيانات القديمة

```sql
-- الخطوة 4: إنشاء سجلات مدفوعات للفواتير المدفوعة قديماً
INSERT INTO invoice_payments (invoice_id, payment_amount, payment_method, notes, created_by, payment_date)
SELECT 
    id,
    COALESCE(total_after_discount,0),
    'cash',
    'دفعة تلقائية - ترحيل من النظام القديم',
    COALESCE(created_by),
    created_at
FROM invoices_out 
WHERE delivered = 'yes'
AND NOT EXISTS (
    SELECT 1 FROM invoice_payments WHERE invoice_id = invoices_out.id
);
```

## 5. تحديث الفواتير المؤجلة

```sql
-- الخطوة 5: تحديث الفواتير المؤجلة
UPDATE invoices_out 
SET paid_amount = 0,
    remaining_amount = COALESCE(total_after_discount, total_before_discount, 0)
WHERE delivered = 'no' 
AND (paid_amount = 0 OR paid_amount IS NULL);
```

ALTER TABLE invoice_out
MODIFY delivered ENUM('yes','no','canceled','reverted','partial')
NOT NULL DEFAULT 'no';





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


5- ALTER TABLE invoices_out 
ADD COLUMN work_order_id INT NULL COMMENT 'ربط بالشغلانة';
ALTER TABLE invoices_out MODIFY work_order_id INT NULL;



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


6- CREATE TABLE `wallet_transactions` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `customer_id` INT(11) NOT NULL,
    
    -- نوع الحركة
    `type` ENUM('deposit', 'withdraw', 'refund', 'invoice_payment') NOT NULL,
    
    `amount` DECIMAL(12,2) NOT NULL,
    `description` VARCHAR(255) NOT NULL,
    
    -- رصيد المحفظة قبل وبعد العملية
    `wallet_before` DECIMAL(12,2) DEFAULT 0.00,
    `wallet_after` DECIMAL(12,2) DEFAULT 0.00,
    
    -- تاريخ الحركة
    `transaction_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    `created_by` INT(11) NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    PRIMARY KEY (`id`),
    
    -- العلاقات
    FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    
    -- فهرسة لتسريع البحث
    INDEX idx_customer_date (`customer_id`, `transaction_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


7--- 2.1: إنشاء جدول customer_transactions إذا لم يكن موجود

-- 2.2: التحقق من إنشاء الجدو

START TRANSACTION;

-- 1️⃣ إنشاء جدول الحركات لو مش موجود
CREATE TABLE IF NOT EXISTS customer_transactions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    customer_id INT NOT NULL,

    -- نوع الحركة
    transaction_type ENUM('invoice', 'payment', 'return', 'deposit', 'withdraw', 'adjustment') NOT NULL,

    amount DECIMAL(12,2) NOT NULL COMMENT 'موجب للزيادة، سالب للنقصان',
    description VARCHAR(255) NOT NULL,

    -- الربط مع باقي العمليات
    invoice_id INT NULL,
    payment_id INT NULL,
    return_id INT NULL,
    wallet_transaction_id INT NULL,   -- الجديد

    work_order_id INT NULL,

    -- الأرصدة
    balance_before DECIMAL(12,2) DEFAULT 0.00,
    balance_after DECIMAL(12,2) DEFAULT 0.00,

    wallet_before DECIMAL(12,2) DEFAULT 0.00,
    wallet_after DECIMAL(12,2) DEFAULT 0.00,

    -- التاريخ
    transaction_date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    -- العلاقات
    FOREIGN KEY (customer_id) REFERENCES customers(id),
    FOREIGN KEY (invoice_id) REFERENCES invoices_out(id),
    FOREIGN KEY (payment_id) REFERENCES invoice_payments(id),
    FOREIGN KEY (return_id) REFERENCES returns(id),

    FOREIGN KEY (wallet_transaction_id) REFERENCES wallet_transactions(id) ON DELETE SET NULL,

    FOREIGN KEY (work_order_id) REFERENCES work_orders(id),
    FOREIGN KEY (created_by) REFERENCES users(id),

    -- الفهارس
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


9- المرتجعات مرتجعات المشتريات

CREATE TABLE purchase_returns (
    id INT PRIMARY KEY AUTO_INCREMENT,
    return_number VARCHAR(50) UNIQUE,
    purchase_invoice_id INT,
    supplier_id INT,
    return_date DATE,
    total_amount DECIMAL(15,2),
    status ENUM('pending', 'approved', 'completed', 'cancelled'),
    reason TEXT,
    notes TEXT,
    created_by INT,
    created_at DATETIME,
    updated_by INT,
    updated_at DATETIME,
    FOREIGN KEY (purchase_invoice_id) REFERENCES purchase_invoices(id),
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id)
);


ALTER TABLE `batches`
ADD PRIMARY KEY (id);
ALTER TABLE purchase_invoice_items
ADD PRIMARY KEY (id);


CREATE TABLE purchase_return_items (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    purchase_return_id INT NOT NULL,
    purchase_invoice_item_id INT NOT NULL,
    product_id INT NOT NULL,
    batch_id BIGINT UNSIGNED NULL,
    quantity DECIMAL(15,3) NOT NULL,
    unit_cost DECIMAL(15,2) NOT NULL,
    total_cost DECIMAL(15,2) NOT NULL,
    reason TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (purchase_return_id) REFERENCES purchase_returns(id) ON DELETE CASCADE,
    FOREIGN KEY (purchase_invoice_item_id) REFERENCES purchase_invoice_items(id),
    FOREIGN KEY (product_id) REFERENCES products(id),
    FOREIGN KEY (batch_id) REFERENCES batches(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



الخصم علي البنود 
ALTER TABLE invoice_out_items
ADD discount_type ENUM('percent','amount') DEFAULT NULL,
ADD discount_value DECIMAL(10,2) DEFAULT 0.00,
ADD discount_amount DECIMAL(12,2) DEFAULT 0.00,
ADD total_after_discount DECIMAL(12,2) DEFAULT 0.00;  --> لازم تظبطه يبقي يساوي قبل 

ALTER TABLE invoices_out
ADD discount_scope ENUM('invoice','items','mixed')
DEFAULT 'invoice'
COMMENT 'مكان تطبيق الخصم';



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



ALTER TABLE sale_item_allocations 
ADD COLUMN return_id INT NULL,
ADD COLUMN is_return BOOLEAN DEFAULT FALSE;



UPDATE invoice_out_items
SET total_after_discount = total_before_discount
WHERE discount_amount <= 0;


ALTER TABLE invoice_out_items
ADD unit_price_after_discount DECIMAL(10,2)
GENERATED ALWAYS AS (
    CASE 
        WHEN quantity >= 1 THEN total_after_discount / quantity
        ELSE total_after_discount
    END
) STORED;

CREATE TABLE notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255),
    message TEXT,
    is_read TINYINT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);



تمام 👌 خلّيك معايا خطوة خطوة، وهنخليك **تجرب فعليًا** وتشوف السكربت بيشتغل ولا لأ بدون تعقيد.

بما إنك شغال **Windows + XAMPP** (واضح من المسارات)، يبقى هنستخدم

## ✅ **Task Scheduler (بديل Cron على ويندوز)**

---

## 1️⃣ جهّز سكربت PHP للتجربة

خلّي عندك ملف مثلاً:

```
C:\xampp\htdocs\store_v1\cron\backup_cron.php
```

وجوّه حط حاجة بسيطة جدًا للتجربة:

```php
<?php
file_put_contents(
    __DIR__ . '/test_cron.log',
    "Cron worked at " . date('Y-m-d H:i:s') . PHP_EOL,
    FILE_APPEND
);
```

👉 الهدف: نتأكد إن Task Scheduler شغّال قبل أي Backup.

---

## 2️⃣ افتح Task Scheduler

1. Start
2. اكتب **Task Scheduler**
3. افتحه

---

## 3️⃣ Create Task (مش Basic)

📌 اختار **Create Task** (مهم جدًا)

### 🟢 General

* Name:

  ```
  Store Backup Test
  ```
* ✔ Run whether user is logged on or not
* ✔ Run with highest privileges

---

## 4️⃣ Triggers (موعد التشغيل)

* New
* Begin the task: **On a schedule**
* Daily
* Start: خليها بعد دقيقة من دلوقتي (عشان تجرب فورًا)
* ✔ Enabled

---

## 5️⃣ Actions (تشغيل PHP)

* New
* Action: **Start a program**

### Program/script

```
C:\xampp\php\php.exe
``

### Add arguments

```
C:\xampp\htdocs\store_v1\cron\backup_cron.php
```

### Start in (مهم)

```
C:\xampp\htdocs\store_v1\cron
```

---

## 6️⃣ Conditions

* ❌ شيل:

  * Start the task only if the computer is on AC power
* ❌ شيل:

  * Stop if computer switches to battery

---

## 7️⃣ Settings

✔ Allow task to be run on demand
✔ If the task fails, restart every 1 minute (اختياري)

---

## 8️⃣ حفظ وتشغيل يدوي

* اضغط OK
* هتطلب منك باسورد الويندوز
* بعد الحفظ:

  * كليك يمين على المهمة
  * **Run**

---

## 9️⃣ التأكد إنه اشتغل ✅

روح للمجلد:

```
C:\xampp\htdocs\store_v1\cron\
```

لو لقيت ملف:

```
test_cron.log
```

وفيه:

```
Cron worked at 2025-...
```

🎉 كده Task Scheduler شغال 100%

---

## 🔁 بعد النجاح

نبدّل محتوى `backup_cron.php` بـ:

* سكربت النسخ الاحتياطي
* تسجيل إشعار
* إرسال إشعار للوحة التحكم

---

## ⚠️ ملاحظات مهمة

* الجهاز **لازم يكون شغال** (مش Sleep / Hibernate)
* Task Scheduler بيشتغل حتى لو المتصفح مقفول
* لو السيرفر حقيقي → الأفضل Linux + Cron

---

## تحب الخطوة الجاية؟

🔹 أجهز لك:

* `backup_cron.php` كامل
* إشعار WebSocket / Fetch للمستخدمين
* Log ذكي للأخطاء

قولّي 👍 ونبدأ مباشرة.
