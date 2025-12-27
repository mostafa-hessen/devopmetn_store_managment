# تقرير كامل: سلوك كل جدول في جميع حالات الإرجاع

كخبير في أنظمة إدارة المخازن والمبيعات، سأقوم بتحليل شامل لسلوك كل جدول في جميع السيناريوهات الممكنة للإرجاع.

## 📊 **التحليل الشامل لكل جدول**

### **1. جدول `batches` (الدُفعات)**

#### **أ- عند الشراء (إنشاء الفاتورة):**
```sql
-- يتم إنشاء أو تحديث دفعات عند الشراء:
-- حالة: شراء 10 وحدات من المنتج X من دفعتين (5 من كل دفعة)
UPDATE batches SET remaining = remaining - 5 WHERE id = 1;
UPDATE batches SET remaining = remaining - 5 WHERE id = 2;

-- إذا وصلت remaining إلى 0:
UPDATE batches SET status = 'consumed' WHERE remaining = 0;
```

#### **ب- عند الإرجاع:**

| الحالة | التغيير في `batches` | الكود المقترح |
|--------|---------------------|---------------|
| **إرجاع بند عادي** | زيادة `remaining` في الدفعات الأصلية | `UPDATE batches SET remaining = remaining + @qty WHERE id = @batch_id` |
| **إرجاع بند عليه خصم** | نفس الإرجاع العادي (لا فرق في المخزون) | `UPDATE batches SET remaining = remaining + @qty WHERE id = @batch_id` |
| **إرجاع فاتورة كاملة** | إعادة كل الكميات لجميع الدفعات | `UPDATE batches SET remaining = remaining + sia.qty FROM sale_item_allocations WHERE...` |
| **دفعة كانت `consumed`** | تغيير `status` إلى `active` | `UPDATE batches SET status='active', remaining=@qty WHERE id=@batch_id` |

**التفاصيل:**
```sql
-- إعادة تفعيل الدفعة المستهلكة
UPDATE batches 
SET 
    remaining = remaining + @return_qty,
    status = CASE 
        WHEN (remaining + @return_qty) > 0 THEN 'active'
        ELSE status
    END,
    updated_at = NOW(),
    revert_reason = CONCAT('إرجاع من فاتورة #', @invoice_id)
WHERE id = @batch_id;
```

### **2. جدول `sale_item_allocations` (تخصيصات البيع)**

#### **أ- عند الشراء:**
```sql
-- يتم إنشاء سجل لكل تخصيص
INSERT INTO sale_item_allocations 
(sale_item_id, batch_id, qty, unit_cost, line_cost, created_by)
VALUES 
(@item_id, 1, 5, 10.00, 50.00, @user_id),
(@item_id, 2, 5, 12.00, 60.00, @user_id);
```

#### **ب- عند الإرجاع:**

| الحالة | التغيير في `sale_item_allocations` | المنطق |
|--------|-----------------------------------|--------|
| **إرجاع جزئي** | إنشاء تخصيصات عكسية (سالبة) | كمية سالبة لنفس `batch_id` |
| **إرجاع كامل** | إنشاء تخصيصات عكسية لجميع التخصيصات الأصلية | نؤرشف ولا نحذف |
| **حفظ التاريخ** | لا نحذف التخصيصات الأصلية | للحفاظ على السجل التاريخي |

**الكود المقترح:**
```sql
-- إضافة عمود للإرجاع (مقترح)
ALTER TABLE sale_item_allocations 
ADD COLUMN return_id INT NULL,
ADD COLUMN is_return BOOLEAN DEFAULT FALSE;

-- عند الإرجاع: إنشاء تخصيصات عكسية
INSERT INTO sale_item_allocations 
(sale_item_id, batch_id, qty, unit_cost, line_cost, created_by, return_id, is_return)
VALUES 
(@sale_item_id, @batch_id, -@return_qty, @unit_cost, -(@unit_cost * @return_qty), @user_id, @return_id, TRUE);
```

### **3. جدول `invoices_out` (الفواتير الصادرة)**

#### **أ- عند الإرجاع:**

| الحالة | التغيير في `invoices_out` | التأثير |
|--------|--------------------------|---------|
| **إرجاع بند واحد** | تحديث `total_cost`, `profit_amount` | إعادة حساب الربح |
| **إرجاع فاتورة كاملة** | تغيير `delivered` إلى `reverted` | تعطيل الفاتورة |
| **تأثير مالي** | `total_cost` يقل، `profit_amount` يتغير | حسب تكلفة المرتجع |

**الحسابات المالية:**
```sql
-- حساب تكلفة المرتجع
SET @return_cost = (
    SELECT SUM(sia.line_cost * (ri.quantity / sia.qty))
    FROM return_items ri
    JOIN sale_item_allocations sia ON JSON_CONTAINS(ri.batch_allocations, CAST(sia.batch_id AS JSON))
    WHERE ri.return_id = @return_id
);

-- تحديث الفاتورة
UPDATE invoices_out 
SET 
    total_cost = total_cost - @return_cost,
    profit_amount = total_after_discount - (total_cost - @return_cost),
    delivered = CASE 
        WHEN @is_full_return THEN 'reverted'
        WHEN delivered = 'yes' AND @has_partial_return THEN 'partial'
        ELSE delivered
    END,
    updated_at = NOW(),
    updated_by = @user_id
WHERE id = @invoice_id;
```

### **4. جدول `invoice_out_items` (بنود الفاتورة)**

#### **أ- الأعمدة المهمة للإرجاع:**
- `returned_quantity`: الكمية المرتجعة
- `return_flag`: تلقائي (1 إذا `returned_quantity = quantity`)
- `available_for_return`: تلقائي (`quantity - returned_quantity`)

#### **ب- عند الإرجاع:**

| الحالة | التغيير في `invoice_out_items` | الكود |
|--------|-------------------------------|-------|
| **إرجاع جزئي** | زيادة `returned_quantity` | `UPDATE ... SET returned_quantity = returned_quantity + @qty` |
| **إرجاع كامل للبند** | `returned_quantity = quantity` | `UPDATE ... SET returned_quantity = quantity` |
| **بند عليه خصم** | نفس المعاملة (الخصم لا يؤثر على الكمية) | نفس الكود مع مراعاة السعر |

**الكود الكامل:**
```sql
-- تحديث بند الفاتورة عند الإرجاع
UPDATE invoice_out_items 
SET 
    returned_quantity = returned_quantity + @return_qty,
    updated_at = NOW()
WHERE id = @invoice_item_id;

-- ملاحظة: return_flag و available_for_return يتم تحديثهما تلقائياً
```

### **5. جدول `returns` و `return_items` (سجلات الإرجاع)**

#### **أ- إنشاء سجل الإرجاع:**
```sql
-- في returns
INSERT INTO returns 
(invoice_id, customer_id, total_amount, return_type, 
 status, reason, created_by, notes)
VALUES 
(@invoice_id, @customer_id, @total_return_amount, 
 @return_type, 'completed', @reason, @user_id, @notes);

-- في return_items لكل بند
INSERT INTO return_items 
(return_id, invoice_item_id, product_id, quantity, 
 return_price, total_amount, batch_allocations, status)
VALUES 
(@return_id, @item_id, @product_id, @qty,
 @calculated_price, @total_item_amount, @batch_json, 'restocked');
```

#### **ب- `batch_allocations` في JSON:**
```json
{
  "allocations": [
    {
      "batch_id": 1,
      "qty": 3,
      "unit_cost": 10.00,
      "original_allocation_id": 101
    },
    {
      "batch_id": 2,
      "qty": 2,
      "unit_cost": 12.00,
      "original_allocation_id": 102
    }
  ]
}
```

### **6. جداول المعاملات المالية**

#### **أ- جدول `customers`:**

| نوع الفاتورة | التغيير في `customers` | المنطق |
|--------------|------------------------|--------|
| **فاتورة مؤجلة** | تقليل `balance` (المدين) | العميل كان مديناً، الإرجاع يقلل دينه |
| **فاتورة مدفوعة** | زيادة `wallet` (إذا اختار محفظة) | أو لا تغيير (إذا أخذ نقدي) |
| **فاتورة جزئية** | تقليل `balance` وجزء `wallet` | حسب المبلغ |

**الكود:**
```sql
-- فاتورة مؤجلة: تقليل الدين
UPDATE customers 
SET balance = balance - @return_amount
WHERE id = @customer_id;

-- فاتورة مدفوعة (محفظة): زيادة المحفظة
UPDATE customers 
SET wallet = wallet + @return_amount
WHERE id = @customer_id;

-- فاتورة جزئية: كلا التعديلين
UPDATE customers 
SET 
    balance = balance - @balance_adjustment,
    wallet = wallet + @wallet_adjustment
WHERE id = @customer_id;
```

#### **ب- جدول `customer_transactions`:**

```sql
-- لكل نوع من الإرجاع
INSERT INTO customer_transactions 
(customer_id, transaction_type, amount, description,
 invoice_id, return_id, balance_before, balance_after,
 wallet_before, wallet_after, created_by)
VALUES 
(@customer_id, 'return', -@amount, 
 CONCAT('إرجاع فاتورة #', @invoice_id),
 @invoice_id, @return_id, @old_balance, @new_balance,
 @old_wallet, @new_wallet, @user_id);
```

#### **ج- جدول `invoice_payments` (للاسترداد النقدي):**

```sql
-- إذا كان استرداد نقدي من فاتورة مدفوعة
INSERT INTO invoice_payments 
(invoice_id, payment_amount, payment_method, 
 payment_date, notes, created_by)
VALUES 
(@invoice_id, -@return_amount, 'cash', 
 NOW(), 'استرداد نقدي للإرجاع', @user_id);
```

#### **د- جدول `wallet_transactions`:**

```sql
-- إذا كان استرداد عبر المحفظة
INSERT INTO wallet_transactions 
(customer_id, type, amount, description,
 wallet_before, wallet_after, created_by)
VALUES 
(@customer_id, 'refund', @return_amount,
 CONCAT('إرجاع فاتورة #', @invoice_id),
 @old_wallet, @new_wallet, @user_id);
```

## 🎯 **السيناريوهات الثلاثة الرئيسية بالتفصيل**

### **السيناريو 1: إرجاع بند عادي (بدون خصم)**

**مثال:** بند: 10 وحدات، سعر الوحدة 20، التكلفة 15

**التأثير على الجداول:**

| الجدول | التغيير | القيمة |
|--------|---------|--------|
| `batches` | `remaining` يزيد | +10 وحدات |
| `invoice_out_items` | `returned_quantity` يزيد | +10 |
| `invoices_out` | `total_cost` يقل | -150 (10×15) |
| `invoices_out` | `profit_amount` يتغير | +150 (يقل الربح) |
| `returns` | سجل جديد | مبلغ 200 |
| `return_items` | سجل البند | كمية 10 |
| `customers` | حسب نوع الدفع | توضيح أدناه |

**الكود المتسلسل:**
```sql
-- 1. التحقق من البيانات
SELECT * FROM invoice_out_items WHERE id = @item_id;
SELECT * FROM sale_item_allocations WHERE sale_item_id = @item_id;

-- 2. إنشاء سجل الإرجاع
INSERT INTO returns (...) VALUES (...);
SET @return_id = LAST_INSERT_ID();

-- 3. تحديث المخزون (batches)
UPDATE batches b
JOIN sale_item_allocations sia ON b.id = sia.batch_id
SET 
    b.remaining = b.remaining + (@return_qty * (sia.qty / @total_item_qty)),
    b.status = CASE 
        WHEN b.remaining > 0 THEN 'active'
        ELSE b.status
    END
WHERE sia.sale_item_id = @item_id;

-- 4. تحديث بند الفاتورة
UPDATE invoice_out_items 
SET returned_quantity = returned_quantity + @return_qty 
WHERE id = @item_id;

-- 5. تحديث الفاتورة
UPDATE invoices_out 
SET 
    total_cost = total_cost - (@return_qty * @cost_price),
    profit_amount = total_after_discount - (total_cost - (@return_qty * @cost_price))
WHERE id = @invoice_id;

-- 6. المعالجة المالية (حسب نوع الدفع)
-- ... (سيتم توضيحها لاحقاً)
```

### **السيناريو 2: إرجاع بند عليه خصم**

**مثال:** بند: 10 وحدات، سعر الوحدة 20، خصم 10%، صافي 180

**التحدي:** كم نرجع للعميل؟ 200 أم 180؟

**الحل:** نرجع **180** (السعر بعد الخصم)

**التأثير المختلف:**

| الجدول | الفرق عن البند العادي |
|--------|----------------------|
| `return_items.return_price` | 18 بدلاً من 20 |
| `return_items.total_amount` | 180 بدلاً من 200 |
| `returns.total_amount` | 180 بدلاً من 200 |
| **الباقي متطابق** في المخزون والتكاليف | |

**الكود الخاص بالخصم:**
```sql
-- حساب السعر بعد الخصم
SET @item_discount_ratio = 1 - (@discount_amount / @total_before_discount);
SET @unit_price_after_discount = @selling_price * @item_discount_ratio;

-- أو باستخدام القيم الجاهزة
SET @unit_price_after_discount = @total_after_discount / @quantity;

-- تسجيل في return_items
INSERT INTO return_items 
(return_id, invoice_item_id, product_id, quantity, 
 return_price, total_amount, batch_allocations)
VALUES 
(@return_id, @item_id, @product_id, @return_qty,
 @unit_price_after_discount, 
 @unit_price_after_discount * @return_qty,
 @batch_json);
```

### **السيناريو 3: إرجاع فاتورة كاملة**

**التأثير الشامل:**

| الجدول | التغيير | المنطق |
|--------|---------|--------|
| `invoices_out.delivered` | `'reverted'` | تعطيل الفاتورة |
| كل `invoice_out_items` | `returned_quantity = quantity` | إرجاع كامل لكل بند |
| كل `batches` مرتبطة | إعادة كل الكميات | حسب تخصيصات البيع |
| `sale_item_allocations` | تخصيصات عكسية كاملة | لكل تخصيص أصلي |
| `returns.return_type` | `'full'` | إرجاع كامل |

**الكود المتسلسل:**
```sql
-- 1. الحصول على كل بنود الفاتورة
SELECT id, product_id, quantity 
FROM invoice_out_items 
WHERE invoice_out_id = @invoice_id
AND available_for_return > 0;

-- 2. إنشاء إرجاع كامل
INSERT INTO returns 
(invoice_id, customer_id, total_amount, return_type, status)
VALUES 
(@invoice_id, @customer_id, @invoice_total, 'full', 'completed');

-- 3. لكل بند في الفاتورة
FOR EACH item IN items:
    -- إرجاع الكمية الكاملة
    SET @return_qty = item.available_for_return;
    
    -- تحديث بند الفاتورة
    UPDATE invoice_out_items 
    SET returned_quantity = quantity 
    WHERE id = item.id;
    
    -- إعادة المخزون
    CALL RestockItem(item.id, @return_qty, @return_id);
END FOR;

-- 4. تحديث حالة الفاتورة
UPDATE invoices_out 
SET 
    delivered = 'reverted',
    total_cost = 0,
    profit_amount = 0,
    updated_at = NOW()
WHERE id = @invoice_id;

-- 5. المعالجة المالية الكاملة
CALL ProcessFinancialReturn(@invoice_id, @return_id, 'full');
```

## 🔄 **معالجة الدفعات المستهلكة (consumed)**

### **مشكلة:** دفعة `remaining = 0` و `status = 'consumed'`

### **الحل:** 
```sql
-- عند الإرجاع، نفحص حالة الدفعة
SELECT remaining, status FROM batches WHERE id = @batch_id;

IF @current_status = 'consumed' AND @return_qty > 0 THEN
    -- إعادة تفعيل الدفعة
    UPDATE batches 
    SET 
        remaining = @return_qty,
        status = 'active',
        revert_reason = CONCAT('إعادة تفعيل من إرجاع فاتورة #', @invoice_id),
        updated_at = NOW()
    WHERE id = @batch_id;
ELSE
    -- زيادة الكمية المتبقية
    UPDATE batches 
    SET remaining = remaining + @return_qty 
    WHERE id = @batch_id;
END IF;
```

## 💰 **الأنواع الثلاثة للدفع وتأثيرها**

### **النوع 1: فاتورة مؤجلة (لم تدفع)**
```sql
-- فقط تعديل الرصيد
UPDATE customers 
SET balance = balance - @return_amount 
WHERE id = @customer_id;

-- حركة العميل
INSERT INTO customer_transactions 
(customer_id, transaction_type, amount, description,
 balance_before, balance_after, invoice_id, return_id)
VALUES 
(@customer_id, 'return', -@return_amount,
 'إرجاع فاتورة مؤجلة',
 @old_balance, @old_balance - @return_amount,
 @invoice_id, @return_id);
```

### **النوع 2: فاتورة مدفوعة كلياً**
```sql
-- خيار 1: نقدي
INSERT INTO invoice_payments 
(invoice_id, payment_amount, payment_method, notes)
VALUES 
(@invoice_id, -@return_amount, 'cash', 'استرداد إرجاع');

-- خيار 2: محفظة
UPDATE customers 
SET wallet = wallet + @return_amount 
WHERE id = @customer_id;

INSERT INTO wallet_transactions (...) VALUES (...);
```

### **النوع 3: فاتورة مدفوعة جزئياً**
```sql
-- حساب الأجزاء
SET @paid = (SELECT paid_amount FROM invoices_out WHERE id = @invoice_id);
SET @return = @return_amount;

IF @return <= @paid THEN
    -- كل الإرجاع من المدفوع
    CALL ProcessRefund(@customer_id, @return, 'من المدفوع');
ELSE
    -- جزء من المدفوع + جزء من الدين
    SET @refund_part = @paid;
    SET @credit_part = @return - @paid;
    
    -- استرداد المدفوع
    CALL ProcessRefund(@customer_id, @refund_part, 'استرداد مدفوع');
    
    -- تقليل الدين
    UPDATE customers 
    SET balance = balance - @credit_part 
    WHERE id = @customer_id;
    
    -- تحديث الفاتورة
    UPDATE invoices_out 
    SET 
        paid_amount = paid_amount - @refund_part,
        remaining_amount = remaining_amount - @credit_part
    WHERE id = @invoice_id;
END IF;
```

## 📋 **نموذج بيانات كامل للإرجاع**

```sql
-- حالة: إرجاع 5 من 10 وحدات (بند عادي، فاتورة مؤجلة)
-- في returns
{
  id: 1001,
  invoice_id: 5001,
  customer_id: 301,
  total_amount: 100.00,  -- 5 وحدات × 20
  return_type: 'partial',
  status: 'completed'
}

-- في return_items
{
  id: 2001,
  return_id: 1001,
  invoice_item_id: 7001,
  product_id: 101,
  quantity: 5.00,
  return_price: 20.00,
  total_amount: 100.00,
  batch_allocations: '[
    {"batch_id": 1, "qty": 3, "unit_cost": 15.00},
    {"batch_id": 2, "qty": 2, "unit_cost": 16.00}
  ]',
  status: 'restocked'
}

-- في batches (تحديث)
-- الدفعة 1: remaining تزيد من 0 إلى 3 (وتصبح active)
-- الدفعة 2: remaining تزيد من 2 إلى 4

-- في invoice_out_items
-- returned_quantity: تزيد من 0 إلى 5
-- return_flag: تبقى 0 (لأن 5 < 10)
-- available_for_return: تصبح 5 تلقائياً

-- في invoices_out
-- total_cost: يقل بـ 77.50 (5 وحدات × متوسط تكلفة 15.5)
-- profit_amount: يقل بـ 100 ويزيد بـ 77.50 = صافي -22.50

-- في customers
-- balance: يقل بـ 100.00

-- في customer_transactions
{
  transaction_type: 'return',
  amount: -100.00,
  description: 'إرجاع جزئي فاتورة #5001',
  balance_before: 500.00,
  balance_after: 400.00
}
```

## 🎯 **الخلاصة والتوصيات**

### **التوصيات:**

1. **لا تحذف أي سجلات** - أرشيف كل شيء
2. **استخدم JSON في `batch_allocations`** لتتبع الدفعات
3. **أنشئ تخصيصات عكسية** في `sale_item_allocations`
4. **حافظ على `return_flag` و `available_for_return`** التلقائيين
5. **عالج الدفعات المستهلكة** بتغيير `status` إلى `active`

### **القواعد الأساسية:**

1. **المخزون:** يعود لنفس الدفعات (FIFO عكسي)
2. **التكلفة:** تخصم من `total_cost` الفاتورة
3. **الربح:** يعاد حسابه دائماً
4. **الخصم:** نرجع السعر بعد الخصم
5. **الدفع:** نتبع نفس طريقة الدفع الأصلية

هل تريدني أن أشرح أي جزء بشكل أكثر تفصيلاً أو أقدم أمثلة كود إضافية؟