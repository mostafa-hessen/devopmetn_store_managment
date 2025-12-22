clinet/api/
├── get_customer_invoices.php
├── get_customer_wallet.php
├── get_customer_work_orders.php
└── get_customer_returns.php
    get_customer_transaction.php

client/js/
-app_data.js
-customer.js
-helper.js
-invoices.js
-payment.js
-print.js
-return.js
wallet.js

client\customer_details.php


هبعتلك ملف 
تقولي اي الدوال اللي هتسخدمها عشان ابعت الداتا للسيرفر
الفكره اني عندي الجداول الاتيه 

CREATE TABLE `invoices_out` (
  `id` int(11) NOT NULL COMMENT 'المعرف التلقائي للفاتورة',
  `customer_id` int(11) NOT NULL COMMENT 'معرف العميل المرتبط بالفاتورة',
  `delivered` enum('yes','no','canceled','reverted','partial') NOT NULL DEFAULT 'no',
  `invoice_group` enum('group1','group2','group3','group4','group5','group6','group7','group8','group9','group10','group11') NOT NULL COMMENT 'مجموعة الفاتورة (من 1 إلى 11)',
  `created_by` int(11) DEFAULT NULL COMMENT 'معرف المستخدم الذي أنشأ الفاتورة',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() COMMENT 'تاريخ ووقت الإنشاء',
  `updated_by` int(11) DEFAULT NULL COMMENT 'معرف المستخدم الذي آخر من عدل الفاتورة',
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp() COMMENT 'تاريخ ووقت آخر تعديل',
  `notes` text DEFAULT NULL,
  `cancel_reason` varchar(255) DEFAULT NULL,
  `revert_reason` varchar(255) DEFAULT NULL,
  `total_before_discount` decimal(12,2) DEFAULT 0.00 COMMENT 'مجموع البيع قبل أي خصم',
  `discount_type` enum('percent','amount') DEFAULT 'percent' COMMENT 'نوع الخصم',
  `discount_value` decimal(10,2) DEFAULT 0.00 COMMENT 'قيمة الخصم: إذا percent -> تخزن النسبة (مثال: 10) وإلا قيمة المبلغ',
  `discount_amount` decimal(12,2) DEFAULT 0.00 COMMENT 'مبلغ الخصم المحسوب بالعملة',
  `total_after_discount` decimal(12,2) DEFAULT 0.00 COMMENT 'المجموع النهائي بعد الخصم',
  `total_cost` decimal(12,2) DEFAULT 0.00 COMMENT 'اجمالي التكلفة (مخزن للتقارير)',
  `profit_amount` decimal(12,2) DEFAULT 0.00 COMMENT 'اجمالي الربح = total_before_discount - total_cost',
  `paid_amount` decimal(12,2) DEFAULT 0.00,
  `remaining_amount` decimal(12,2) DEFAULT 0.00,
  `work_order_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول فواتير العملاء الصادرة';

--

CREATE TABLE `work_orders` (
  `id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL COMMENT 'عنوان الشغلانة',
  `description` text DEFAULT NULL COMMENT 'وصف تفصيلي',
  `status` enum('pending','in_progress','completed','cancelled') DEFAULT 'pending',
  `start_date` date NOT NULL COMMENT 'تاريخ البدء',
  `notes` text DEFAULT NULL COMMENT 'ملاحظات إضافية',
  `total_invoice_amount` decimal(12,2) DEFAULT 0.00 COMMENT 'إجمالي فواتير الشغلانة',
  `total_paid` decimal(12,2) DEFAULT 0.00 COMMENT 'إجمالي المدفوع',
  `total_remaining` decimal(12,2) DEFAULT 0.00 COMMENT 'إجمالي المتبقي',
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `customer_transactions` (
  `id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `transaction_type` enum('invoice','payment','return','deposit','adjustment','withdraw') NOT NULL,
  `amount` decimal(12,2) NOT NULL COMMENT 'موجب للزيادة، سالب للنقصان',
  `description` varchar(255) NOT NULL,
  `invoice_id` int(11) DEFAULT NULL,
  `payment_id` int(11) DEFAULT NULL,
  `return_id` int(11) DEFAULT NULL,
  `wallet_transaction` int(11) DEFAULT NULL,
  `work_order_id` int(11) DEFAULT NULL,
  `balance_before` decimal(12,2) DEFAULT 0.00,
  `balance_after` decimal(12,2) DEFAULT 0.00,
  `wallet_before` decimal(12,2) DEFAULT 0.00,
  `wallet_after` decimal(12,2) DEFAULT 0.00,
  `transaction_date` date NOT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--

الجدول الاتي
لو في سحب من محفظه 
CREATE TABLE `wallet_transactions` (
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


CREATE TABLE `invoice_payments` (
  `id` int(11) NOT NULL,
  `invoice_id` int(11) NOT NULL,
  `payment_amount` decimal(12,2) NOT NULL,
  `payment_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `payment_method` enum('cash','bank_transfer','check','card','wallet','mixed') DEFAULT 'cash',
  `notes` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `wallet_before` decimal(12,2) DEFAULT 0.00 COMMENT 'رصيد المحفظة قبل الدفع',
  `wallet_after` decimal(12,2) DEFAULT 0.00 COMMENT 'رصيد المحفظة بعد الدفع',
  `work_order_id` int(11) DEFAULT NULL COMMENT 'ربط بالشغلانة'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `customers` (
  `id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL COMMENT 'اسم العميل',
  `mobile` varchar(11) NOT NULL COMMENT 'رقم الموبايل (11 رقم)',
  `city` varchar(100) NOT NULL COMMENT 'المدينة',
  `address` varchar(255) DEFAULT NULL COMMENT 'العنوان التفصيلي',
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL COMMENT 'معرف المستخدم الذي أضاف العميل',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() COMMENT 'تاريخ الإضافة',
  `balance` decimal(12,2) DEFAULT 0.00 COMMENT 'الرصيد الحالي (مدين + / دائن -)',
  `wallet` decimal(12,2) DEFAULT 0.00 COMMENT 'رصيد المحفظة',
  `join_date` date DEFAULT curdate() COMMENT 'تاريخ الانضمام'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


استخرج من الملف المرفوع اي الداله المسؤله عن انها تكلم السيرفر تديله المعلومات اللي يستفاد  منها 
ف فهمني ازاي اقدر احدث الصفحه 
والمعولكات 

واعملي سيناريوهات 
لو سددت فاتوره واحده 
عده فواتير
لو سداد بامثر من طريقه 
لو سداد من نقدي ومحفظه 

عاوز اعرف كل ده وتاثيره علي اجدول لالمختلفه




**لا، هذا ليس الطريقة المثلى!** هناك مشاكل في التصميم المذكور. دعني أوضح المشاكل والحلول الأفضل:

## **المشاكل في الطريقة السابقة:**

1. **تقسيم غير منطقي**: تقسيم كل فاتورة لجزأين (محفظة + نقدي) معقد وغير ضروري
2. **مشكلة في التتبع**: صعوبة تتبع مصدر كل جزء من المبلغ
3. **تعقيد في التقارير**: صعوبة في إنشاء تقارير دقيقة عن طرق الدفع
4. **سجلات زائدة**: 4 سجلات دفع لفاتورتين فقط

---

## **الطريقة الأفضل (مبسطة ودقيقة):**

### **السيناريو: سداد فاتورتين (100 + 200) بـ 150 محفظة + 150 نقدي**

## 1. **طريقة التوزيع الذكي:**

نعامل 150 محفظة **كدفعة واحدة** و150 نقدي **كدفعة أخرى**، ونوزعها على الفواتير حسب الأسبقية:

**الخيار الأفضل**: ندفع الفاتورة الأصغر أولاً (100) ثم الأكبر (200)

```
الخطوات:
1. من 150 محفظة: ندفع 100 للفاتورة 1 (تبقى 50 محفظة)
2. من 150 نقدي: ندفع 0 للفاتورة 1 (تم سدادها من المحفظة)
3. من 150 محفظة (المتبقي 50): ندفع 50 للفاتورة 2
4. من 150 نقدي: ندفع 150 للفاتورة 2 (لإكمال 200)
```

## 2. **التنفيذ العملي الأمثل:**

### **جدول `invoice_payments` (سجلين فقط!):**

#### **الدفعة 1: من المحفظة**
```sql
id: 5001
invoice_id: 1001  -- للفاتورة الأولى فقط
payment_amount: 100.00
payment_method: 'wallet'  -- إضافة نوع جديد للمحفظة!
payment_date: [الوقت الحالي]
notes: 'سداد كامل من المحفظة للفاتورة #1001'
created_by: 10
wallet_before: 500.00
wallet_after: 400.00
work_order_id: NULL
```

#### **الدفعة 2: نقدي + محفظة (مختلط)**
```sql
id: 5002
invoice_id: 1002  -- للفاتورة الثانية
payment_amount: 200.00
payment_method: 'mixed'  -- أو نقدي مع ذكر التفاصيل في notes
payment_date: [الوقت الحالي]
notes: 'سداد 50 محفظة + 150 نقدي'
created_by: 10
wallet_before: 400.00  -- قبل سحب الـ 50 محفظة
wallet_after: 350.00   -- بعد سحب الـ 50 محفظة
work_order_id: NULL
```

---

## 3. **الطريقة الأكثر احترافية:**

### **تعديل هيكل `invoice_payments` لدعم مدفوعات متعددة الطرق:**

```sql
-- هيكل محسن
CREATE TABLE `invoice_payments` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `invoice_id` INT(11) NOT NULL,
  `total_payment` DECIMAL(12,2) NOT NULL,  -- المبلغ الإجمالي
  `payment_date` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` INT(11) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
);

-- جدول تفصيلي لطرق الدفع
CREATE TABLE `payment_method_details` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `payment_id` INT(11) NOT NULL,
  `method` ENUM('cash','wallet','bank_transfer','check','card') NOT NULL,
  `amount` DECIMAL(12,2) NOT NULL,
  `wallet_before` DECIMAL(12,2) DEFAULT NULL,
  `wallet_after` DECIMAL(12,2) DEFAULT NULL,
  `notes` VARCHAR(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
);
```

### **مع هذا الهيكل، تسجيل العملية يكون:**

#### **الفاتورة 1001:**
- **payment_method_details:**
  - method: 'wallet', amount: 100.00
  - wallet_before: 500, wallet_after: 400

#### **الفاتورة 1002:**
- **payment_method_details:**
  - method: 'wallet', amount: 50.00
  - wallet_before: 400, wallet_after: 350
  - method: 'cash', amount: 150.00

---

## 4. **التنفيذ المثالي (بدون تعديل الجداول):**

### **باستخدام الـ Notes لتوضيح التفاصيل:**

#### **سجل واحد لكل فاتورة في `invoice_payments`:**

```sql
-- للفاتورة 1001
id: 5001
invoice_id: 1001
payment_amount: 100.00
payment_method: 'wallet'  -- نضيف هذا الخيار
notes: 'سداد كامل من المحفظة'
created_by: 10
wallet_before: 500.00
wallet_after: 400.00

-- للفاتورة 1002
id: 5002
invoice_id: 1002
payment_amount: 200.00
payment_method: 'mixed'  -- أو 'cash' مع التوضيح في notes
notes: '50 جنيه من المحفظة + 150 جنيه نقدي'
created_by: 10
wallet_before: 400.00
wallet_after: 350.00
```

---

## 5. **wallet_transactions: سجلين فقط:**

```sql
-- سحب 100 محفظة للفاتورة 1001
id: 3001
customer_id: 1
type: 'invoice_payment'
amount: -100.00
description: 'سداد فاتورة #1001'
wallet_before: 500.00
wallet_after: 400.00

-- سحب 50 محفظة للفاتورة 1002
id: 3002
customer_id: 1
type: 'invoice_payment'
amount: -50.00
description: 'سداد فاتورة #1002 (جزء من المبلغ)'
wallet_before: 400.00
wallet_after: 350.00
```

---

## 6. **customer_transactions: سجلين فقط:**

```sql
-- معاملة الفاتورة 1001
id: 7001
customer_id: 1
transaction_type: 'payment'
amount: -100.00
description: 'سداد فاتورة #1001 من المحفظة'
invoice_id: 1001
payment_id: 5001
balance_before: -300.00
balance_after: -200.00
wallet_before: 500.00
wallet_after: 400.00

-- معاملة الفاتورة 1002
id: 7002
customer_id: 1
transaction_type: 'payment'
amount: -200.00
description: 'سداد فاتورة #1002 (50 محفظة + 150 نقدي)'
invoice_id: 1002
payment_id: 5002
balance_before: -200.00
balance_after: 0.00
wallet_before: 400.00
wallet_after: 350.00
```

---

## **مقارنة بين الطريقتين:**

| المعيار | الطريقة الأولى (الخاطئة) | الطريقة الثانية (الصحيحة) |
|---------|-------------------------|--------------------------|
| عدد سجلات invoice_payments | 4 سجلات | 2 سجلات فقط |
| الوضوح | غير واضح | واضح وسهل التتبع |
| صحة البيانات | فيها أخطاء في التوزيع | دقيقة ومنطقية |
| سهولة التقارير | صعبة | سهلة |
| تأثير على wallet_transactions | 2 سحب محفظة | 2 سحب محفظة (صحيح) |

## **التوصيات:**

1. **أضف `'wallet'` و`'mixed'` لـ `payment_method` enum**
2. **استخدم سجل دفع واحد لكل فاتورة**
3. **وضح تفاصيل التوزيع في الـ notes**
4. **حافظ على تسلسل منطقي (الأصغر أولاً أو الأقدم أولاً)**

**الخيار الأفضل على المدى الطويل:** إنشاء جدول `payment_method_details` منفصل لتفاصيل طرق الدفع المختلفة في نفس العملية.

هل تريد مساعدة في تنفيذ أي من هذه الحلول أو لديك أسئلة أخرى؟