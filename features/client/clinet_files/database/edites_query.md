//==================== work order =======================

CREATE TABLE work_orders (
    id INT PRIMARY KEY AUTO_INCREMENT,
    customer_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    status ENUM('pending', 'in_progress', 'completed', 'cancelled') DEFAULT 'pending',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(id)
);



// ============== customer_transactions =====================
CREATE TABLE customer_transactions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    customer_id INT NOT NULL,
    transaction_date DATE NOT NULL,
    transaction_time TIME NOT NULL,
    type ENUM('invoice', 'payment', 'return', 'deposit', 'adjustment') NOT NULL,
    description VARCHAR(255),
    amount DECIMAL(12,2) NOT NULL,
    balance_before DECIMAL(12,2) NOT NULL,
    balance_after DECIMAL(12,2) NOT NULL,
    wallet_balance_before DECIMAL(12,2) NOT NULL,
    wallet_balance_after DECIMAL(12,2) NOT NULL,

    invoice_id INT NULL,
    work_order_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(id),
    FOREIGN KEY (invoice_id) REFERENCES invoices_out(id),
    FOREIGN KEY (work_order_id) REFERENCES work_orders(id)
);




CREATE TABLE deposits (
    id INT PRIMARY KEY AUTO_INCREMENT,
    customer_id INT NOT NULL,
    deposit_date DATE NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    description VARCHAR(255),
    balance_before DECIMAL(12,2) NOT NULL,
    balance_after DECIMAL(12,2) NOT NULL,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(id),
    FOREIGN KEY (work_order_id) REFERENCES work_orders(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
);



CREATE TABLE returns (
    id INT PRIMARY KEY AUTO_INCREMENT,
    return_number VARCHAR(50) UNIQUE,
    customer_id INT NOT NULL,
    invoice_id INT NOT NULL,
    type ENUM('full', 'partial') NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    method ENUM('credit', 'cash', 'bank_transfer','wallet','check') NOT NULL,
    status ENUM('pending', 'completed', 'cancelled') DEFAULT 'completed',
    return_date DATE NOT NULL,
    reason TEXT,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(id),
    FOREIGN KEY (invoice_id) REFERENCES invoices_out(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
);



CREATE TABLE return_items (
    id INT PRIMARY KEY AUTO_INCREMENT,
    return_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity DECIMAL(10,2) NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    total DECIMAL(10,2) NOT NULL,
    batch_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (return_id) REFERENCES returns(id),
    FOREIGN KEY (product_id) REFERENCES products(id),
    FOREIGN KEY (batch_id) REFERENCES batches(id)
);



ALTER TABLE customers 
ADD COLUMN balance DECIMAL(12,2) DEFAULT 0.00,
ADD COLUMN deposit_balance DECIMAL(12,2) DEFAULT 0.00,
ADD COLUMN join_date DATE;


ALTER TABLE invoices_out 
ADD COLUMN work_order_id INT NULL,

ADD FOREIGN KEY (work_order_id) REFERENCES work_orders(id);










========================= new =                
تمام — ممتاز الوصف اللي اديتَه، خلّيني أرتّب لك الحل العملي والمباشر بناءً على النقاط اللي في بالك، وبشرح بسيط وملفّز: الجداول الجديدة، الحقول اللي هتتضاف، منطق عمل المرتجعات (مع حفظ كم رجع ومتاح يرجع)، وسكربت SQL للعمليات الأساسية (إنشاء الجداول + مثال معالجة مرتجع داخل transaction).
هتقدر تنفّذ ده على MySQL / MariaDB — عدّل أسماء الجداول القديمة لو عندك اختلاف.

---

# نظرة عامة سريعة

المطلوب يتلخّص في:

1. إضافة أرصدة للعميل (`wallet_balance`, `balance`).
2. الاعتماد على `invoice_payments` لعمليات السداد (نستخدمه كما هو).
3. جدول يتابع كل الحركات المالية/العملية للعميل (سجل مركزي).
4. جدول المرتجعات و جدول بنود المرتجع مع تتبُّع الكميات، وتسجيل طريقة الاسترجاع وحالة المرتجع.
5. منطق لحساب "كم اترد من بند" و"كم متاح للإرجاع" ووضع فلاج عندما يُرجع كل البند.

سأعرض التعريفات (CREATE TABLE)، ثم خطّة الترحيل، ثم مثال عملية (transaction) للمرتجع، ثم استعلامات لحساب المتاح.

---

# أ — الجداول/حقول التي ستُضاف (SQL جاهز)

## 1) تعديل جدول `customers` — إضافة أرصدة

```sql
ALTER TABLE customers
  ADD COLUMN wallet_balance DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  ADD COLUMN balance DECIMAL(14,2) NOT NULL DEFAULT 0.00; -- balance = إجمالي المديونية أو حساب مخصص حسب منطقك
```

> ملاحظة: يمكنك تسميتها `current_balance` بدل `balance` لو تحب.

---

## 2) جدول تتبُّع الحركات العامة (transactions / customer_activity)

يسجل كل حدث مهم: سداد، إنشاء فاتورة، إيداع محفظة، استرداد، مرتجع...

```sql
CREATE TABLE IF NOT EXISTS customer_activity (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id BIGINT UNSIGNED NOT NULL,
  activity_type ENUM('invoice_created','payment','wallet_topup','refund','return','adjustment','other') NOT NULL,
  reference_table VARCHAR(100) NULL, -- e.g. 'invoice_out','payments','returns'
  reference_id BIGINT UNSIGNED NULL,
  amount DECIMAL(14,2) NULL,        -- موجبة/سالبة حسب النوع
  meta JSON NULL,                   -- بيانات إضافية (مثلاً: { "payment_method":"cash" })
  created_by VARCHAR(100) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (customer_id),
  CONSTRAINT fk_activity_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

## 3) جدول المرتجعات (returns) + بنود المرتجع (return_items)

**returns** يخزن رأس عملية المرتجع، **return_items** يخزن كل بند مُرجَع ويعطي القدرة على تتبُّع الكميات والمبلغ والطريقة.

```sql
CREATE TABLE IF NOT EXISTS returns (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  return_number VARCHAR(100) NOT NULL UNIQUE,
  invoice_id BIGINT UNSIGNED NOT NULL,    -- الفاتورة الأصلية
  customer_id BIGINT UNSIGNED NOT NULL,
  refund_total DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  refund_to ENUM('wallet','cash','bank') NOT NULL DEFAULT 'wallet',
  status ENUM('pending','processed','cancelled') NOT NULL DEFAULT 'pending',
  note TEXT NULL,
  processed_by VARCHAR(100) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (invoice_id),
  INDEX (customer_id),
  CONSTRAINT fk_returns_invoice FOREIGN KEY (invoice_id) REFERENCES invoice_out(id) ON DELETE CASCADE,
  CONSTRAINT fk_returns_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

```sql
CREATE TABLE IF NOT EXISTS return_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  return_id BIGINT UNSIGNED NOT NULL,
  invoice_item_id BIGINT UNSIGNED NOT NULL, -- ربط للبند الأصلي في الفاتورة
  product_id BIGINT UNSIGNED NULL,
  qty_returned INT NOT NULL DEFAULT 0,
  unit_price DECIMAL(14,2) NOT NULL,       -- سعر الوحدة المرجع على أساسه
  line_total DECIMAL(14,2) NOT NULL,       -- qty_returned * unit_price
  refund_method ENUM('wallet','cash','bank') NOT NULL DEFAULT 'wallet',
  status ENUM('pending','processed','rejected') NOT NULL DEFAULT 'pending',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (return_id),
  INDEX (invoice_item_id),
  CONSTRAINT fk_return_items_return FOREIGN KEY (return_id) REFERENCES returns(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

> افتراض أن جدول بنود الفاتورة اسمه `invoice_out_items` ويحتوي `id, invoice_id, product_id, qty, unit_price, subtotal`. لو اسم مختلف غيّره في الـ FK.

---

## 4) حقل تتبع لكمية مرجعة داخل `invoice_out_items` (مقترح عملي)

نضيف حقل `qty_returned` و `is_fully_returned` لتسريع الاستعلام ومعرفة المتاح للإرجاع بسهولة.

```sql
ALTER TABLE invoice_out_items
  ADD COLUMN qty_returned INT NOT NULL DEFAULT 0,
  ADD COLUMN is_fully_returned TINYINT(1) NOT NULL DEFAULT 0;
```

* `qty_returned` = مجموع الكميات المرجعة لهذا البند.
* `is_fully_returned` = 1 عندما `qty_returned >= qty` (يمكن تحديثه عبر trigger أو أثناء عملية المرتجع).

---

## 5) (اختياري) جدول لربط الـ work orders بالـ invoices

أنت ذكرت تشغيلات مرتبطة بفاتورات (مثال الـ work orders). نعمل جدول ربط بسيط:

```sql
CREATE TABLE IF NOT EXISTS work_orders (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  description TEXT NULL,
  status ENUM('pending','in_progress','completed','cancelled') DEFAULT 'pending',
  start_date DATE NULL,
  notes TEXT NULL,
  created_by VARCHAR(100) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS work_order_invoices (
  work_order_id BIGINT UNSIGNED NOT NULL,
  invoice_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (work_order_id, invoice_id),
  CONSTRAINT fk_wo_inv_wo FOREIGN KEY (work_order_id) REFERENCES work_orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_wo_inv_inv FOREIGN KEY (invoice_id) REFERENCES invoice_out(id) ON DELETE CASCADE
);
```

---

# ب — عملية المرتجع (Business logic) — كيف تتم بالـ SQL + مثال transaction

الهدف: عند معالجة مرتجع لبند:

* نتأكّد أن الكمية المرغوب إرجاعها ≤ (qty - qty_returned).
* نُدرِج سجل في `returns` و `return_items`.
* نحدّث `invoice_out_items.qty_returned` ونضبط `is_fully_returned` إن لزم.
* نُسجّل الحركة في `customer_activity`.
* إذا الرد عائد للمحفظة/نقدي: نُحدّث `customers.wallet_balance` أو نُدرج في `invoice_payments`/`payments`.
* كل ده يعمل داخل transaction واحدة.

### مثال: ترجع بند بكمية `X` وتعيد للـ wallet

```sql
START TRANSACTION;

-- 1) Insert head of return
INSERT INTO returns (return_number, invoice_id, customer_id, refund_total, refund_to, status, note)
VALUES ('RET-20251206-0001', 123, 45, 0.00, 'wallet', 'pending', 'مرتجع جزئي لبند رقم 7');

SET @return_id = LAST_INSERT_ID();

-- 2) افترض بند الفاتورة id = 987 , نرجع qty = 2
SET @invoice_item_id = 987;
SET @qty_to_return = 2;

-- 3) اقرأ بيانات البند
SELECT qty, qty_returned, unit_price INTO @orig_qty, @orig_qty_returned, @unit_price
FROM invoice_out_items WHERE id = @invoice_item_id FOR UPDATE;

-- 4) تحقق من المقدار المتاح
SET @available = @orig_qty - @orig_qty_returned;
IF @available < @qty_to_return THEN
  ROLLBACK;
  -- أعد خطأ للـ application (غير مسموح)
END IF;

-- 5) Insert return_items
SET @line_total = @qty_to_return * @unit_price;
INSERT INTO return_items (return_id, invoice_item_id, product_id, qty_returned, unit_price, line_total, refund_method, status)
VALUES (@return_id, @invoice_item_id, NULL, @qty_to_return, @unit_price, @line_total, 'wallet', 'processed');

-- 6) Update qty_returned في invoice_out_items
UPDATE invoice_out_items
SET qty_returned = qty_returned + @qty_to_return,
    is_fully_returned = CASE WHEN (qty_returned + @qty_to_return) >= qty THEN 1 ELSE 0 END
WHERE id = @invoice_item_id;

-- 7) Update رأس المرتجع بالمجموع
UPDATE returns
SET refund_total = (SELECT SUM(line_total) FROM return_items WHERE return_id = @return_id),
    status = 'processed'
WHERE id = @return_id;

-- 8) Update wallet (مثال: إضافة إلى wallet_balance)
UPDATE customers
SET wallet_balance = wallet_balance + @line_total
WHERE id = 45;

-- 9) سجل الحركة في customer_activity
INSERT INTO customer_activity (customer_id, activity_type, reference_table, reference_id, amount, meta)
VALUES (45, 'return', 'returns', @return_id, @line_total, JSON_OBJECT('invoice_id', 123, 'invoice_item_id', @invoice_item_id));

COMMIT;
```

> هذا سيناريو نموذجي — نفّذ في stored procedure أو في كود الخادم (Node/PHP) للتعامل بالخطأ والـ rollback وإرجاع رسائل مفهومة للـ UI.

---

# ت — استعلامات مهمة: كم اترد وكم متاح يرجع

1. **كم مرجع من بند معين (sum):**

```sql
SELECT qty_returned FROM invoice_out_items WHERE id = 987;
-- او لو لم تضف qty_returned:
SELECT SUM(ri.qty_returned) AS total_returned
FROM return_items ri
JOIN invoice_out_items ioi ON ioi.id = ri.invoice_item_id
WHERE ioi.id = 987;
```

2. **كم متاح يرجع لبند معين:**

```sql
SELECT ioi.id, ioi.qty, ioi.qty_returned, (ioi.qty - ioi.qty_returned) AS qty_available_to_return
FROM invoice_out_items ioi
WHERE ioi.id = 987;
```

3. **إظهار كل البنود في فاتورة مع المتاح للرجاع:**

```sql
SELECT ioi.id, ioi.product_id, ioi.qty, ioi.unit_price, ioi.qty_returned,
       (ioi.qty - ioi.qty_returned) AS available_to_return,
       ioi.is_fully_returned
FROM invoice_out_items ioi
WHERE ioi.invoice_id = 123;
```

---

# ث — وضع فلاج عند إرجاع كامل البند

نستخدم `is_fully_returned` كما في ALTER أعلاه. نحدّثه أثناء عملية ترحيل المرتجع (كما في المثال). بهذا الشكل، الواجهة تقدر تعرض بسرعة البنود المسموح إرجاعها أو المعلمة كمكتملة.

---

# ج — خطة ترحيل/إضافة عمليّة (خطوات مختصرة وآمنة)

1. **نسخة احتياطية كاملة** (ضروري).
2. تنفيذ ALTER/CREATE للجداول أعلاه على **staging** أولًا.
3. شغّل data migration scripts:

   * إن لديك سجلات قديمة للمرتجعات (`invoice_cancellations` أو ما شابه)، اكتب استعلامات لتحويلها إلى `returns`/`return_items` واملأ `invoice_out_items.qty_returned`.
   * احفظ سجلّات الترحيل في `migration_batches`.
4. بعد الترحيل: نفّذ reconciliation للتأكد:

   * مجموع المبالغ القديمة للمرتجعات == مجموع `returns.refund_total`.
   * `invoice_out_items.qty_returned` لا تتجاوز `qty`.
5. حدّث كود التطبيق ليستخدم الـ APIs أو الاستعلامات الجديدة:

   * واجهة عرض "كم متاح يرجع" تستخدم الاستعلام أعلاه.
   * عند عملية مرتجع، ارسِل طلب للـ backend لينفّذ transaction المشروحة.
6. اختبار شامل (unit/integration) للسيناريوهات: مرتجع جزئي، مرتجع كامل، مرتجع ومحفظة، دفع جزئي.
7. نشر خطّة (maintenance window) إن لزم أو dual-write إن تريد zero-downtime.

---

# د — ملاحظات وخيارات تصميم (نصايح خبرة)

* **تخزين `balance`**: الأفضل أن لا تُخزّن `balance` بدون reconciliation دوري؛ استخدم `wallet_transactions` واحتسب الرصيد عند الحاجة أو حدّث `wallet_balance` فقط عبر العمليات المصرّح بها (atomic).
* **المنطق في DB vs App**: العمليات الحسّاسة (amount updates, qty checks) من الأفضل تنفيذها داخل transaction في الـ backend (app) أو في Stored Procedure في DB لضمان atomicity.
* **الأرشفة**: احتفظ بسجلّات المرتجعات القديمة في `returns` لكن يمكنك أرشفتها لاحقًا إن كانت كثيفة.
* **سجلات التدقيق**: كل تعديل في `qty_returned`, `wallet_balance` يجب أن يُدوَّن في `customer_activity` أو `audit_logs`.

---

# هـ — هل أجهز لك سكربت ترحيل جاهز؟

لو تحب أقدّم لك الآن:

* سكربت SQL كامل للـ DDL (باستخدام أسماء جداولك كما في dump)،
* سكربت Node.js/SQL لترحيل بيانات `invoice_cancellations` القديمة إلى `returns/return_items` مع ملء `qty_returned`,
* أو Stored Procedure جاهز لمعالجة العودة (return process).

قلّي أي واحد تفضّل وأنا أبعته لك فورًا (جاهز للتشغيل على **staging**).
