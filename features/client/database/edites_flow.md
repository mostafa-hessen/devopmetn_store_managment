## الإجابات التفصيلية:

### 1- مشكلة إيداع الشغلانة وتأثيرها على الـ Balance:

**الحل: فصل الأرصدة بشكل واضح:**

```sql
-- إضافة حقول جديدة للشغلانات
ALTER TABLE work_orders 
ADD COLUMN deposit_balance DECIMAL(12,2) DEFAULT 0.00,
ADD COLUMN total_paid DECIMAL(12,2) DEFAULT 0.00,
ADD COLUMN work_order_balance DECIMAL(12,2) DEFAULT 0.00;

-- إضافة حقول جديدة للعملاء
ALTER TABLE customers 
ADD COLUMN customer_deposit_balance DECIMAL(12,2) DEFAULT 0.00,  -- الودائع العامة
ADD COLUMN available_balance DECIMAL(12,2) DEFAULT 0.00;         -- الرصيد المتاح للاستخدام
```

**آلية العمل:**
- عند إيداع مبلغ لشغلانة: 
  - `balance_before` = الرصيد الحالي للشغلانة
  - `balance_after` = الرصيد الحالي + المبلغ المضاف
  - يتم تحديث `deposit_balance` في جدول `work_orders` فقط

### 2- مشكلة المرتجعات والدفع من الودائع:

**إنشاء جدول لتفصيل استرجاع المبالغ:**

```sql
CREATE TABLE return_allocations (
    id INT PRIMARY KEY AUTO_INCREMENT,
    return_id INT NOT NULL,
    source_type ENUM('deposit', 'workorder_deposit', 'cash_payment') NOT NULL,
    source_id INT NULL, -- work_order_id أو invoice_payment_id
    amount DECIMAL(12,2) NOT NULL,
    allocated_to ENUM('customer_deposit', 'workorder_deposit', 'cash_refund') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (return_id) REFERENCES returns(id)
);
```

**سيناريو مرتجز مع دفع من ودائع الشغلانة:**
```sql
-- مثال: مرتجز بقيمة 300 جنيه، تم الدفع الأصلي 200 من ودائع الشغلانة + 100 نقدي
INSERT INTO return_allocations VALUES
(NULL, 1, 'workorder_deposit', 1, 200, 'workorder_deposit', NOW()), -- إعادة 200 لودائع الشغلانة
(NULL, 1, 'cash_payment', 101, 100, 'cash_refund', NOW());          -- إعادة 100 نقدي للعميل
```

### 3- الفلو الكامل للعميل من اليوم الأول:

#### اليوم ١: إنشاء العميل
```sql
-- إضافة العميل الجديد
INSERT INTO customers (name, mobile, city, initial_balance, current_balance, total_purchases, deposit_balance, join_date) 
VALUES ('محمد أحمد', '01234567890', 'القاهرة', 0, 0, 0, 0, '2024-01-20');
```

#### الحالة ١: إيداع ودائع عامة
```sql
-- تسجيل الحركة
INSERT INTO deposits (customer_id, amount, type, balance_before, balance_after, description)
VALUES (1, 200, 'customer', 0, 200, 'وديعة العميل');

-- تحديث رصيد العميل
UPDATE customers SET 
deposit_balance = deposit_balance + 200,
available_balance = available_balance + 200 
WHERE id = 1;
```

#### الحالة ٢: إنشاء شغلانة وإيداع ودائع لها
```sql
-- إنشاء الشغلانة
INSERT INTO work_orders (customer_id, name, budget, deposit_balance, work_order_balance)
VALUES (1, 'تركيب شباك المعادي', 1500, 0, 1500);

-- إيداع ودائع للشغلانة
INSERT INTO deposits (customer_id, work_order_id, amount, type, balance_before, balance_after, description)
VALUES (1, 1, 300, 'workorder', 0, 300, 'وديعة لشغلانة تركيب شباك المعادي');

-- تحديث أرصدة الشغلانة
UPDATE work_orders SET 
deposit_balance = deposit_balance + 300,
work_order_balance = work_order_balance - 300  -- تقليل الدين
WHERE id = 1;

-- تقليل الرصيد المتاح للعميل
UPDATE customers SET 
available_balance = available_balance - 300 
WHERE id = 1;
```

#### الحالة ٣: إنشاء فاتورة مرتبطة بالشغلانة
```sql
-- إنشاء الفاتورة
INSERT INTO invoices_out (customer_id, work_order_id, total_after_discount, remaining_amount, description)
VALUES (1, 1, 800, 800, 'فاتورة مرتبطة بشغلانة #WO-001');

-- إضافة أصناف الفاتورة
INSERT INTO invoice_out_items (invoice_out_id, product_id, quantity, total_price)
VALUES (1, 1, 1, 800);

-- تسجيل الحركة المالية
INSERT INTO customer_transactions (customer_id, type, amount, balance_before, balance_after, description)
VALUES (1, 'invoice', -800, 0, -800, 'فاتورة جديدة #123');

-- تحديث إجمالي المشتريات
UPDATE customers SET 
total_purchases = total_purchases + 800,
current_balance = current_balance - 800 
WHERE id = 1;
```

#### الحالة ٤: دفع الفاتورة من ودائع الشغلانة
```sql
-- تسجيل الدفع
INSERT INTO invoice_payments (invoice_id, payment_amount, payment_method)
VALUES (1, 500, 'cash');

-- توزيع المبالغ (300 من ودائع الشغلانة + 200 نقدي)
INSERT INTO invoice_payment_allocations (invoice_payment_id, source_type, source_id, amount)
VALUES 
(1, 'workorder_deposit', 1, 300),
(1, 'cash', NULL, 200);

-- تحديث أرصدة الشغلانة
UPDATE work_orders SET 
deposit_balance = deposit_balance - 300,
total_paid = total_paid + 300,
work_order_balance = work_order_balance + 300  -- زيادة الدين لأننا استخدمنا الودائع
WHERE id = 1;

-- تحديث رصيد العميل
UPDATE customers SET 
current_balance = current_balance + 500  -- تقليل الدين
WHERE id = 1;

-- تسجيل الحركة المالية
INSERT INTO customer_transactions (customer_id, type, amount, balance_before, balance_after, description)
VALUES (1, 'payment', 500, -800, -300, 'سداد فاتورة #123');
```

#### الحالة ٥: مرتجز معقد
```sql
-- إنشاء المرتجز
INSERT INTO returns (customer_id, invoice_id, amount, type, method, reason)
VALUES (1, 1, 300, 'full', 'credit', 'شباك معيب');

-- توزيع استرجاع المبالغ (200 نقدي + 100 لودائع الشغلانة)
INSERT INTO return_allocations (return_id, source_type, source_id, amount, allocated_to)
VALUES 
(1, 'cash_payment', 1, 200, 'cash_refund'),
(1, 'workorder_deposit', 1, 100, 'workorder_deposit');

-- تحديث ودائع الشغلانة
UPDATE work_orders SET 
deposit_balance = deposit_balance + 100 
WHERE id = 1;

-- تحديث رصيد العميل
UPDATE customers SET 
current_balance = current_balance - 300,  -- زيادة الدين بسبب المرتجز
available_balance = available_balance + 100  -- إضافة 100 للرصيد المتاح
WHERE id = 1;
```

### 4- فلو إنشاء الفاتورة بالتفصيل:

```sql
-- ١: التحقق من بيانات العميل
SELECT id, current_balance, available_balance FROM customers WHERE id = 1;

-- ٢: إنشاء رأس الفاتورة
INSERT INTO invoices_out (
    customer_id, 
    work_order_id, 
    total_before_discount,
    discount_type,
    discount_value, 
    discount_amount,
    total_after_discount,
    total_cost,
    profit_amount,
    remaining_amount,
    description
) VALUES (
    1, 1, 1000, 'percent', 10, 100, 900, 600, 300, 900, 'فاتورة جديدة'
);

-- ٣: إضافة الأصناف مع تتبع الدفعات
INSERT INTO invoice_out_items (invoice_out_id, product_id, quantity, selling_price, total_price, cost_price_per_unit)
VALUES 
(LAST_INSERT_ID(), 1, 2, 500, 1000, 300);

-- ٤: تخصيص الكميات من الدفعات (FIFO)
INSERT INTO sale_item_allocations (sale_item_id, batch_id, qty, unit_cost, line_cost)
SELECT 
    LAST_INSERT_ID(), 
    id, 
    LEAST(remaining, 2),  -- الكمية المطلوبة أو المتبقية أيهما أقل
    unit_cost,
    LEAST(remaining, 2) * unit_cost
FROM batches 
WHERE product_id = 1 AND remaining > 0 
ORDER BY received_at 
LIMIT 1;

-- ٥: تحديث أرصدة الدفعات
UPDATE batches 
SET remaining = remaining - 2 
WHERE id = [الباتش المستخدم];

-- ٦: تحديث مخزن المنتج
UPDATE products 
SET current_stock = current_stock - 2 
WHERE id = 1;

-- ٧: تسجيل الحركة المالية
INSERT INTO customer_transactions (
    customer_id, 
    invoice_id,
    type, 
    amount, 
    balance_before, 
    balance_after, 
    description
)
SELECT 
    1,
    LAST_INSERT_ID(),
    'invoice',
    -900,
    current_balance,
    current_balance - 900,
    'فاتورة جديدة #' || LAST_INSERT_ID()
FROM customers 
WHERE id = 1;

-- ٨: تحديث رصيد العميل
UPDATE customers SET 
current_balance = current_balance - 900,
total_purchases = total_purchases + 900
WHERE id = 1;
```

### 5- التقارير المطلوبة:

```sql
-- تقرير المركز المالي للعميل
SELECT 
    c.name,
    c.current_balance as total_balance,
    c.deposit_balance as general_deposits,
    c.available_balance,
    c.total_purchases,
    SUM(wo.work_order_balance) as work_orders_balance,
    SUM(wo.deposit_balance) as work_orders_deposits
FROM customers c
LEFT JOIN work_orders wo ON c.id = wo.customer_id
WHERE c.id = 1
GROUP BY c.id;

-- تقرير حركات العميل
SELECT 
    date,
    type,
    description,
    amount,
    balance_before,
    balance_after
FROM customer_transactions 
WHERE customer_id = 1 
ORDER BY date DESC;

-- تقرير الشغلانات والمدفوعات
SELECT 
    wo.name,
    wo.budget,
    wo.deposit_balance,
    wo.total_paid,
    wo.work_order_balance,
    GROUP_CONCAT(DISTINCT io.id) as invoice_ids
FROM work_orders wo
LEFT JOIN invoices_out io ON wo.id = io.work_order_id
WHERE wo.customer_id = 1
GROUP BY wo.id;
```

هذا النظام يضمن:
- فصل كامل بين الودائع العامة وودائع الشغلانات
- تتبع دقيق لكل حركة مالية
- منع التضارب في الأرصدة
- تقارير شاملة عن المركز المالي للعميل