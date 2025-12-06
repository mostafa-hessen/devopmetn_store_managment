رائع — طلبك واضح: عايز إضافة كاملة ومنسقة لـ **رصيد العميل (balance)** + **ودائع** + **مرتجعات مرتبطة بفواتير** + **شغلانات مرتبطة بالعميل**، وكمان تتبع **حركات الحساب** (ledger) بحيث نقدر نعرض يوميات مثل المثال الي بعته مع حساب `balanceBefore` و `balanceAfter`. هقدملك هنا تصميم متكامل (تغييرات قاعدة البيانات مع SQL جاهز للـ migration + واجهات API مقترحة + منطق التحديث (triggers أو application-level) + أمثلة JSON و استعلامات لعرض الكشف) — كلّها آمنة للتطبيق في بيئة اختبار أولاً.

أقسّم الرد إلى: (A) نظرة عامة على المفاهيم والمصطلحات، (B) تغييرات قاعدة البيانات (جداول + أعمدة + قيود + سكربتات SQL)، (C) منطق التحديث (Triggers vs App logic) مع أمثلة triggers آمنة، (D) API endpoints + نماذج JSON، (E) استعلامات لعرض الـ ledger واحتساب balanceBefore/After، (F) واجهة المستخدم وUX مقترح، (G) خطوات نشر/اختبار/rollback وملاحظات مهمة.

---

# A — نظرة عامة على المفاهيم

* **Customer Balance**: رقم واحد في جدول `customers` يمثل الرصيد الحالي: موجب = العميل مدين (عليه مبلغ للشركة)، سالب أو موجب حسب الاتفاق. (سأعتمد كون **موجب = العميل مدين** كما في أمثلتك: فاتورة تُنقص الرصيد (amount negative) — سنتفق أن عرض `amount` للفواتير يظهر بالسالب).
* **Customer Transaction (Ledger)**: جدول سجلات ماليّة لكل حدث يؤثر على رصيد العميل — أنواع: `invoice`, `payment`, `deposit`, `return` (credit_note), `adjustment`, `work_order_payment` الخ.
* **Deposit (وديعة)**: نقدية يدفعها العميل مقدماً، تُسجَّل كـ transaction موجبة (تزيد رصيد الشركة لصالح العميل أو تقلل مديونيته بحسب التعريف). سنسميها `deposit` وتظهر في ledger.
* **Return (مرتجع)**: مرتجع مرتبط بفاتورة، يُحدث سند ائتمان (credit note) أو تعديل على الفاتورة ويؤثر على الرصيد.
* **Work Order (شغلانة)**: مشروع / مهمة مرتبطة بعميل له فاتورات/دفعات خاصة؛ مرتبط بحالة مالية منفصلة لكن يؤثر على الحساب الكلي للعميل.

---

# B — تغييرات قاعدة البيانات (SQL Migrations)

> ملاحظة: شغّل هذه السكربتات في بيئة اختبار أولاً. كل ALTER محاط بتعليقات ونصائح. استعمل TRANSACTION عند الإمكان.

## 1) إضافة أعمدة في customers

```sql
ALTER TABLE customers
  ADD COLUMN current_balance DECIMAL(13,2) NOT NULL DEFAULT 0 COMMENT 'رصيد العميل: موجب = العميل مدين',
  ADD COLUMN opening_balance DECIMAL(13,2) NOT NULL DEFAULT 0 COMMENT 'الرصيد الابتدائي عند الإنشاء (للتوثيق)',
  ADD COLUMN balance_currency VARCHAR(10) NOT NULL DEFAULT 'EGP' COMMENT 'العملة إن احتجت';
```

## 2) إنشاء جدول ledger للحركات (customer_transactions)

```sql
CREATE TABLE customer_transactions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  customer_id INT NOT NULL,
  related_table VARCHAR(64) NULL COMMENT 'مثال: invoices_out, invoice_payments, credit_notes, deposits, work_orders',
  related_id BIGINT NULL COMMENT 'المرجع داخل الجدول المرتبط',
  txn_type ENUM('invoice','payment','deposit','return','adjustment','work_order') NOT NULL,
  txn_subtype VARCHAR(64) NULL COMMENT 'تفصيل إذا احتجنا (مثال: partial_payment, full_return)',
  description VARCHAR(512) NULL,
  amount DECIMAL(13,2) NOT NULL COMMENT 'موجب = يزيد رصيد العميل (يعني يقل مديونية؟ اتفقنا: موجب=العميل مدين). **اتفقنا:** سنخزن amount موجباً للحركة التي تزيد مديونية العميل (مثلاً فاتورة -> amount > 0). عند العرض نستخدم علامات حسب النوع.',
  balance_before DECIMAL(13,2) NULL,
  balance_after DECIMAL(13,2) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by INT NULL,
  INDEX (customer_id),
  INDEX (related_table, related_id),
  CONSTRAINT fk_ct_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**توضيح عن اتجاه المبالغ:**

* أفضّل أن نجعل `amount` دائمًا **موجبًا** ويصف تأثير الحركة على مديونية العميل:

  * `txn_type='invoice'` و `amount = invoice_total` → يزيد مديونية العميل. (balance_after = balance_before + amount)
  * `txn_type='payment'` و `amount = payment_amount` → يقلل مديونية العميل. لكن بدل جعل amount سالب، سنعطي أيضًا `txn_type` ونحسب `balance_after = balance_before - amount`.
    هذا يسهل العمليات الحسابية والبحث.

## 3) جدول customer_deposits (الودائع)

```sql
CREATE TABLE customer_deposits (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  customer_id INT NOT NULL,
  amount DECIMAL(13,2) NOT NULL,
  description VARCHAR(255),
  status ENUM('active','applied','refunded','cancelled') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by INT NULL,
  applied_at DATETIME NULL,
  CONSTRAINT fk_deposit_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
  INDEX (customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

## 4) جدول credit_notes / returns

(مرتجع مرتبط بفاتورة)

```sql
CREATE TABLE credit_notes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  number VARCHAR(50) NULL,
  invoice_id BIGINT NULL,
  customer_id INT NOT NULL,
  type ENUM('full','partial') NOT NULL DEFAULT 'partial',
  total_amount DECIMAL(13,2) NOT NULL,
  method ENUM('credit','refund') NOT NULL DEFAULT 'credit' COMMENT 'credit = يضاف كرّصيد للعميل، refund = يعيد نقداً',
  status ENUM('draft','completed','cancelled') NOT NULL DEFAULT 'draft',
  reason VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by INT NULL,
  CONSTRAINT fk_cn_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
  CONSTRAINT fk_cn_invoice FOREIGN KEY (invoice_id) REFERENCES invoices_out(id) ON DELETE SET NULL,
  INDEX (invoice_id),
  INDEX (customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

وجداول بنود سند الائتمان:

```sql
CREATE TABLE credit_note_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  credit_note_id BIGINT UNSIGNED NOT NULL,
  product_id INT NOT NULL,
  quantity DECIMAL(13,4) NOT NULL,
  unit_price DECIMAL(13,2) NOT NULL,
  total DECIMAL(13,2) NOT NULL,
  batch_id BIGINT NULL,
  CONSTRAINT fk_cni_cn FOREIGN KEY (credit_note_id) REFERENCES credit_notes(id) ON DELETE CASCADE,
  CONSTRAINT fk_cni_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

## 5) جدول work_orders (الشغلانات المرتبطة بالعميل)

```sql
CREATE TABLE work_orders (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  customer_id INT NOT NULL,
  name VARCHAR(255) NOT NULL,
  description TEXT,
  status ENUM('pending','in_progress','completed','cancelled') NOT NULL DEFAULT 'pending',
  delivery_date DATE NULL,
  budget DECIMAL(13,2) NOT NULL DEFAULT 0,
  paid_amount DECIMAL(13,2) NOT NULL DEFAULT 0,
  remaining_amount DECIMAL(13,2) GENERATED ALWAYS AS (budget - paid_amount) VIRTUAL,
  notes TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by INT NULL,
  INDEX (customer_id),
  CONSTRAINT fk_wo_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

> ملاحظة: جدول `work_orders` يحتوي على `paid_amount` و `remaining_amount` لحساب حالة الدفع. الدفعات المرتبطة بشغلانات يمكن تسجيلها كـ `invoice_out` مرتبطة بالـ work_order أو كـ `work_order_payments` مستقل — اخترت الخيار الأول (ربط الفواتير بالشغلانة) لأنه يسهّل تتبع محاسبي.

## 6) ربط invoices_out بالـ work_order (اختياري)

```sql
ALTER TABLE invoices_out
  ADD COLUMN work_order_id BIGINT NULL,
  ADD INDEX (work_order_id),
  ADD CONSTRAINT fk_invoice_workorder FOREIGN KEY (work_order_id) REFERENCES work_orders(id) ON DELETE SET NULL;
```

---

# C — منطق التحديث (Triggers vs Application-level)

أنا أوصيك **بتنفيذ المنطق في طبقة التطبيق** (server-side) لأن العمليات قد تحتاج تحققات تلقائية (مثلاً تعديل بواقي الباتشات، إنشاء sale_item_allocations، محاسبات ضريبية) وهذا أسهل للاختبار والتحكم وال rollback. لكن أقدّم triggers بسيطة وآمنة لو حبيت جزئياً:

## C1) منطق عام (مفضل — Application-level)

عند كل حدث يجب تنفيذ الخطوات التالية (pseudo-sequence):

### عند إنشاء فاتورة جديدة (`invoices_out`)

1. احسب إجمالي الفاتورة `invoice_total`.
2. داخل transaction DB:

   * Insert into `invoices_out`.
   * Insert items `invoice_out_items`.
   * (إن كنت تستخدم تخصيص باتشات) ایجاد `sale_item_allocations` وتحديث `batches.remaining`.
   * **Create a customer_transactions row**:

     * `txn_type = 'invoice'`, `amount = invoice_total`, `balance_before = customers.current_balance`, `balance_after = balance_before + amount`.
   * **Update customers.current_balance = balance_after**.
3. Commit.

### عند تسجيل دفعة (`invoice_payments`)

1. داخل transaction:

   * Insert into `invoice_payments`.
   * Create a `customer_transactions` row with `txn_type='payment'`, `amount = payment_amount`, `balance_before = current_balance`, `balance_after = balance_before - amount`.
   * Update customers.current_balance = balance_after.
2. Commit.

### عند إنشاء ودیعة (`customer_deposits`)

* Insert deposit, create `customer_transactions` with `txn_type='deposit'`, increase / decrease حسب تعريفك (عادة deposit يقلل مديونية العميل, أي نفس تأثير payment). اتفقنا على منطق: **deposit يُعامل كدفعة مقدمة** → يقلل مديونية العميل (balance_after = before - amount). في `customer_deposits` ستبقى سجل الوديعة ويمكن تحويلها لاحقًا.

> ملاحظة: لأن في جدول `customer_transactions` قررنا `amount` بالنسبة للـ invoice يضيف إلى المديونية بينما بالنسبة للدفعة يقلل، عند عرض الـ ledger نستخدم `txn_type` لتحديد اتجاه الرصيد.

### عند إنشاء `credit_note` (مرتجع)

* إذا `method='credit'` → نُنشئ `customer_transactions` من نوع `return` ونجري `customers.current_balance = before - amount` (لأن سند الائتمان يقلل مديونية العميل). إن كان `method='refund'` → سيتم دفع نقدي/تحويل إلى العميل، مع إنشاء `invoice_payments` أو `payment_out` حسب هيكل النظام.

## C2) Triggers (إذا أردت تنفيذ جزئي على مستوى DB)

**تنبيه:** استخدم triggers فقط إذا كانت العمليات بسيطة. لا تخلط بين triggers وتحديثات الباتشات المعقدة.

### مثال trigger على INSERT في `invoice_payments`:

```sql
DELIMITER $$
CREATE TRIGGER trg_after_insert_invoice_payment
AFTER INSERT ON invoice_payments
FOR EACH ROW
BEGIN
  DECLARE cust_id INT DEFAULT NULL;
  SELECT customer_id INTO cust_id FROM invoices_out WHERE id = NEW.invoice_id;
  IF cust_id IS NOT NULL THEN
    DECLARE prev DECIMAL(13,2);
    SELECT current_balance INTO prev FROM customers WHERE id = cust_id FOR UPDATE;
    -- تسجيل الحركة
    INSERT INTO customer_transactions (customer_id, related_table, related_id, txn_type, amount, description, balance_before, balance_after, created_by)
    VALUES (cust_id, 'invoice_payments', NEW.id, 'payment', NEW.amount, CONCAT('سداد فاتورة #', NEW.invoice_id), prev, prev - NEW.amount, NEW.created_by);
    -- تحديث الرصيد
    UPDATE customers SET current_balance = prev - NEW.amount WHERE id = cust_id;
  END IF;
END$$
DELIMITER ;
```

**ملاحظة أمنية:** trigger أعلاه يفترض أن `invoices_out` و `customers` موجودين وأن `invoice_payments` يحوي `created_by`. تأكد من أسماء الأعمدة الحقيقية قبل الاستخدام. اختبر على DB صغير.

---

# D — API Endpoints مقترحة (REST)

1. إدارة ledger / استعلامات

* `GET /api/customers/{id}/ledger?from=YYYY-MM-DD&to=YYYY-MM-DD&page=1&pageSize=50`

  * يعيد قائمة `customer_transactions` مفروشة مع الحقول: date/time, txn_type, description, amount, balanceBefore, balanceAfter, related (table/id).
* `GET /api/customers/{id}/balance`

  * يعيد `current_balance`, `opening_balance`, `available_credit` إن كان موجود.

2. إيداع (وديعة)

* `POST /api/customers/{id}/deposits`

  * body: `{ amount, description, created_by }`
  * عملية: Insert into `customer_deposits`, Create `customer_transactions` txn_type='deposit', Update customers.current_balance.

3. إنشاء مرتجع / credit note

* `POST /api/credit-notes`

  * body: `{ invoice_id, customer_id, type, total_amount, method, reason, items: [...] }`
  * عملية: Insert credit_note, Insert credit_note_items, Adjust batches.remaining إن لزم، Create customer_transactions txn_type='return' (or other), Update customers.current_balance.

4. شغلانات

* `POST /api/work-orders`  — إنشاء شغلانة
* `GET /api/work-orders?customerId=...`
* `POST /api/work-orders/{id}/record-payment` — لتسجيل دفعة للشغلانة (ينشئ فاتورة أو payment مرتبط بالـ work_order)

5. استعلام فاتورة ومرتجع مرتبط

* `GET /api/invoices/{id}/returns` — يعرض الـ credit_notes المرتبطة بالفاتورة.

---

# E — استعلامات لعرض الـ ledger مع حساب balanceBefore/After (SQL)

## 1) إحضار ledger بالترتيب الزمني وحساب رصيد متحرك

تفترض أننا نحدث `customer_transactions` مع الحقول balance_before/after (وهو مُفضّل لأننا نخزّن تلك القيم عند الكتابة). لكن إن لم تكن مخزنة ونريد احتسابه on-the-fly:

### طريقة A (إذا خزنت balance_before & balance_after)

```sql
SELECT id, created_at, txn_type, description, amount, balance_before, balance_after
FROM customer_transactions
WHERE customer_id = ? AND created_at BETWEEN '2024-01-01' AND '2024-12-31'
ORDER BY created_at DESC, id DESC;
```

### طريقة B (حساب تشغيلى باستخدام SUM — إذا لم تخزن الأرصدة)

(حساب رصيد تراكمي حتى كل صف — MySQL 8+ يدعم window functions)

```sql
SELECT
  t.id,
  t.created_at,
  t.txn_type,
  t.description,
  t.amount,
  SUM(CASE WHEN t.txn_type = 'invoice' THEN t.amount WHEN t.txn_type IN ('payment','deposit','return') THEN -t.amount ELSE (CASE WHEN t.txn_type='adjustment' THEN t.amount ELSE 0 END) END)
    OVER (ORDER BY t.created_at, t.id ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) AS running_effect,
  -- يمكنك إضافة opening balance ثم جمعه
  (c.opening_balance + SUM(
    CASE WHEN t.txn_type = 'invoice' THEN t.amount WHEN t.txn_type IN ('payment','deposit','return') THEN -t.amount ELSE 0 END
    ) OVER (ORDER BY t.created_at, t.id ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW)
  ) AS balance_after
FROM customer_transactions t
JOIN customers c ON c.id = t.customer_id
WHERE t.customer_id = ?
ORDER BY t.created_at DESC;
```

> أو نحتفظ بالقيم balance_before/after عند إنشاء الحركة (أنسب وأبسط للعرض).

## 2) توليد الـ JSON بنفس صيغة مثالك

خلال الAPI، عند جلب السجلات رتب الحقول بهذا الشكل (مثال Node.js pseudocode):

```js
{
  date: formatDate(txn.created_at), // '2024-01-15'
  time: formatTime(txn.created_at), // '10:30 ص'
  type: txn.txn_type, // payment | invoice | return | deposit
  description: txn.description,
  amount: txn.txn_type === 'invoice' ? -txn.amount : txn.amount, // للعرض فقط حسب أمثلتك
  balanceBefore: txn.balance_before,
  balanceAfter: txn.balance_after,
  paymentMethods: (...) // عند الدفع، اجلب من invoice_payments_methods أو حقل JSON
}
```

> ملاحظة: أنا اقترحت تخزين `amount` دائماً موجباً واحتساب اتجاه العرض حسب `txn_type` ليتطابق مع المثال الذي أرسلتَه (invoice يظهر بمقدار سالب عند العرض).

---

# F — واجهة المستخدم وUX مُقترح (صفحة العميل — تبويب الحساب)

1. شاشة العميل — تبويبات: Info | Invoices | Payments | Deposits | Returns | Work Orders | Ledger
2. تبويب Ledger:

   * الأعلى: بطاقة موجزة: `Current Balance` (رقم كبير)، `Opening Balance`, `Available Credit` (إن وُجد). زر: "Add Deposit" و "Add Adjustment".
   * جدول يوميات: أعمدة: Date, Time, Type (badge), Description, Amount (Debit/Credit), Balance After. كل صف يفتح modal يعرض التفاصيل (مرجع الفاتورة، طرق الدفع، البنود).
   * فلتر: فترة، نوع الحركة، بحث نصي. زر: Export CSV / Print Statement.
3. صفحة Deposit: نموذج بسيط (amount, description, payment method) → يؤدي لإنشاء deposit و customer_transaction.
4. صفحة Return: Modal/flow يتيح اختيار فاتورة مرجعة، اختيار أصناف وكميات، حقل reason، اختيار method (credit/refund) → عند Issue يقوم بإنتاج credit_note وتحديث الرصيد والمخزون.

---

# G — خطوات النشر والاختبار + rollback

1. **تحضير نسخة اختبار**: restore DB dump إلى بيئة staging.
2. **شغّل المigrations**: أضف الأعمدة والجداول أعلاه. راجع أي أخطاء FK.
3. **تهيئة البيانات**: قم بتعبئة `customer_transactions` تاريخياً بناءً على الموجود:

   * Script لترحيل التاريخ: احسب SUM(invoices) و SUM(payments) لكل عميل ورتّبهم زمنياً لإدراج ledger وحساب current_balance القديم ثم قارن بالـ customers.current_balance. (إمكانية توليد script أقدمه لو حبيت).
4. **اختبار end-to-end**: إنشاء فاتورة، تسجيل دفعة، إنشاء وديعة، إصدار مرتجع، تأكد أن customers.current_balance و customer_transactions يتطابقان.
5. **مراقبة**: بعد الترحيل شغّل تقارير لعدة أيام وتقارن الرصيد الحالي مع التوقعات.
6. **Rollback**: تحضير SQL لعكس الـ ALTERs (DROP columns/tables) — احتفظ بـ dump قبل التغيير.

---

# H — أمثلة JSON مطابقة لطلبك (جاهز للعرض في الواجهة)

## مثال Ledger entry (مطابق لمثال المستخدم)

```json
{
  "date": "2024-01-15",
  "time": "10:30 ص",
  "type": "payment",
  "description": "سداد فاتورة #123",
  "amount": 500,
  "balanceBefore": 1700,
  "balanceAfter": 1200,
  "paymentMethods": [
    { "method": "نقدي", "amount": 300 },
    { "method": "فيزا", "amount": 200 }
  ]
}
```

## مثال Return / Credit Note

```json
{
  "id": 1,
  "number": "#RET-001",
  "invoiceId": 120,
  "invoiceNumber": "#120",
  "type": "full",
  "amount": 300,
  "method": "credit",
  "status": "completed",
  "date": "2024-01-05",
  "reason": "شباك معيب",
  "items": [
    { "productId": 1, "productName": "شباك ألوميتال 2×1.5", "quantity": 1, "price": 300, "total": 300 }
  ]
}
```

## مثال Deposit

```json
{
  "id": 1,
  "date": "2024-01-18",
  "amount": 200,
  "description": "وديعة العميل",
  "balanceBefore": 0,
  "balanceAfter": 200
}
```

## مثال Work Orders

```json
{
  "id": 1,
  "name": "تركيب شباك المعادي",
  "description": "تركيب شباك ألوميتال مقاس 2×1.5 في فيلا المعادي",
  "status": "pending",
  "deliveryDate": "2024-01-25",
  "budget": 1500,
  "notes": "يجب الانتهاء قبل نهاية الشهر",
  "paid": 500,
  "remaining": 1000,
  "invoices": [123]
}
```

---

# I — نصائح واعتبارات عملية أخيرة

* **التاريخية (migration)**: لو لديك بيانات قديمة كثيرة، جهّز سكربت لترحيل ledger تلقائياً (أعطيك سكربت جاهز لو تحب).
* **اتساق الأرقام**: استخدم DECIMAL(13,2) للجزء المالي إن كانت العملة نقدية عادية. لو تتعامل بكميات (kgs) استخدم 4 منازل عشرية للـ quantities.
* **تجنّب triggers الثقيلة**: إذا العمليات تتضمن تحديثات باتشات/حساب تكاليف COGS فطبقها في الكود (service layer) وليس في DB triggers.
* **سجّل كل تغيير**: audit_logs لكل تغيير على الحسابات/فواتير/سندات الائتمان.
* **التعامل مع العملات**: إن النظام متعدد عملات أضف حقول currency و exchange_rate في الحركات.

---

إذا تحب، أقدر أعمل فورًا واحد من التالي (نفّذه لك الآن في السكربت):

1. أقدملك سكربت Migration كامل (كل `CREATE TABLE` + `ALTER TABLE`) في ملف SQL واحد جاهز للتشغيل في staging.
2. أقدملك سكربت ترحيل (migration) لملء `customer_transactions` من السجلات الحالية في `invoices_out` و `invoice_payments` و `invoice_cancellations` — هذا يعطينا ledger تاريخي ويعدل `customers.current_balance` ليتطابق.
3. أقدملك الكود المثال للـ API (Express.js) لثلاثة endpoints: create payment (يحدث customer_transaction)، create deposit، create credit_note.

اختر رقم (1) أو (2) أو (3) أو قلّي "عايز كله" — وأنا أبدأ وأنت تستلم السكربت/الكود فورًا.
