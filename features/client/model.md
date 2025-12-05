بص بقي، فهمتك تماماً. هشرحلك النظام الجديد بشكل عملي ومفصل مع التعديلات اللي هتحتاجها في الواجهات.

## أولاً: نظام ربط الشغلانة بالفاتورة

### التعديلات في واجهة إنشاء الفاتورة (`create_invoice.php`):

```html
<!-- بعد قسم العميل مباشرة -->
<div class="work-order-section" id="work-order-section" style="display: none;">
    <div class="panel-title">
        <i class="fas fa-tools"></i>
        ربط بشغلانة
    </div>
    <select id="work-order-select" class="form-select">
        <option value="">اختر الشغلانة المرتبطة (اختياري)</option>
    </select>
    <div id="work-order-details" style="margin-top: 10px; padding: 10px; background: #f8f9fa; border-radius: 5px; display: none;">
        <div><strong>تفاصيل الشغلانة:</strong></div>
        <div id="wo-description"></div>
        <div id="wo-worker"></div>
        <div id="wo-status"></div>
    </div>
</div>
```

### الكود JavaScript الجديد:

```javascript
// في AppState أضف:
workOrder: null,

// في UI.update أضف:
updateWorkOrderSection() {
    const section = document.getElementById('work-order-section');
    const select = document.getElementById('work-order-select');
    const details = document.getElementById('work-order-details');
    
    if (AppState.currentCustomer && AppState.currentCustomer.id) {
        section.style.display = 'block';
        this.loadWorkOrders(AppState.currentCustomer.id);
    } else {
        section.style.display = 'none';
        select.innerHTML = '<option value="">اختر الشغلانة المرتبطة (اختياري)</option>';
        details.style.display = 'none';
    }
},

async loadWorkOrders(customerId) {
    try {
        const response = await fetch(`?action=get_work_orders&customer_id=${customerId}`);
        const result = await response.json();
        
        const select = document.getElementById('work-order-select');
        select.innerHTML = '<option value="">اختر الشغلانة المرتبطة (اختياري)</option>';
        
        if (result.ok && result.work_orders.length > 0) {
            result.work_orders.forEach(wo => {
                const option = document.createElement('option');
                option.value = wo.id;
                option.textContent = `#${wo.order_number} - ${wo.description} (${wo.status}) - ${wo.worker_name}`;
                option.dataset.workOrder = JSON.stringify(wo);
                select.appendChild(option);
            });
        } else {
            const option = document.createElement('option');
            option.value = "";
            option.textContent = "لا توجد شغلانات نشطة";
            select.appendChild(option);
        }
    } catch (error) {
        console.error('Error loading work orders:', error);
    }
},

// في EventManager أضف:
setupWorkOrderEvents() {
    const select = document.getElementById('work-order-select');
    if (select) {
        select.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            if (selectedOption.value) {
                const workOrder = JSON.parse(selectedOption.dataset.workOrder);
                AppState.workOrder = workOrder;
                
                // عرض تفاصيل الشغلانة
                const details = document.getElementById('work-order-details');
                document.getElementById('wo-description').textContent = workOrder.description;
                document.getElementById('wo-worker').textContent = `الصنايعي: ${workOrder.worker_name}`;
                document.getElementById('wo-status').textContent = `الحالة: ${workOrder.status}`;
                details.style.display = 'block';
            } else {
                AppState.workOrder = null;
                document.getElementById('work-order-details').style.display = 'none';
            }
        });
    }
}
```

## ثانياً: نظام المرتجعات - فهم شامل

### أنواع المرتجعات:
1. **مرتجع كامل** - إرجاع الفاتورة بالكامل
2. **مرتجع جزئي** - إرجاع بعض المنتجات
3. **مرتجع نقدي** - استرجاع المال للعميل
4. **مرتجع رصيد** - إضافة المبلغ إلى رصيد العميل

### واجهة عرض الفاتورة مع المرتجعات:

```html
<!-- في صفحة عرض الفاتورة نضيف -->
<div class="invoice-actions">
    <button class="btn btn-warning" id="return-invoice-btn">
        <i class="fas fa-undo"></i> إنشاء مرتجع
    </button>
    <button class="btn btn-info" id="view-returns-btn">
        <i class="fas fa-history"></i> عرض المرتجعات السابقة
    </button>
</div>

<!-- نموذج إنشاء مرتجع -->
<div class="modal-backdrop" id="return-modal">
    <div class="mymodal" style="max-width: 800px;">
        <div class="title">إنشاء مرتجع للفاتورة #<span id="return-invoice-id"></span></div>
        
        <div class="return-type-selection">
            <div class="form-check">
                <input class="form-check-input" type="radio" name="return-type" id="return-full" value="full">
                <label class="form-check-label" for="return-full">
                    مرتجع كامل
                </label>
            </div>
            <div class="form-check">
                <input class="form-check-input" type="radio" name="return-type" id="return-partial" value="partial" checked>
                <label class="form-check-label" for="return-partial">
                    مرتجع جزئي
                </label>
            </div>
        </div>

        <div class="refund-method-selection">
            <label>طريقة الاسترجاع:</label>
            <div class="form-check">
                <input class="form-check-input" type="radio" name="refund-method" id refund-cash" value="cash">
                <label class="form-check-label" for="refund-cash">
                    استرجاع نقدي
                </label>
            </div>
            <div class="form-check">
                <input class="form-check-input" type="radio" name="refund-method" id="refund-credit" value="credit" checked>
                <label class="form-check-label" for="refund-credit">
                    إضافة إلى رصيد العميل
                </label>
            </div>
        </div>

        <div class="return-items-section">
            <table class="table">
                <thead>
                    <tr>
                        <th><input type="checkbox" id="select-all-items"></th>
                        <th>المنتج</th>
                        <th>الكمية المباعة</th>
                        <th>الكمية المرتجعة</th>
                        <th>سبب الإرجاع</th>
                    </tr>
                </thead>
                <tbody id="return-items-list">
                    <!-- سيتم تعبئتها بالمنتجات -->
                </tbody>
            </table>
        </div>

        <div class="return-summary">
            <div class="summary-row">
                <span>إجمالي المرتجع:</span>
                <span id="return-total-amount">٠٫٠٠ ج.م</span>
            </div>
            <div class="summary-row">
                <span>طريقة الاسترجاع:</span>
                <span id="return-method-text">رصيد</span>
            </div>
        </div>

        <div class="modal-footer">
            <button class="btn btn-secondary" id="cancel-return">إلغاء</button>
            <button class="btn btn-primary" id="confirm-return">تأكيد المرتجع</button>
        </div>
    </div>
</div>
```

## ثالثاً: نظام السداد الذكي

### واجهة السداد في صفحة العميل:

```html
<!-- في صفحة إدارة العملاء نضيف زر السداد -->
<button class="btn btn-success btn-sm" onclick="openPaymentModal(<?php echo $customer['id']; ?>)">
    <i class="fas fa-money-bill-wave"></i> سداد
</button>

<!-- نموذج السداد -->
<div class="modal-backdrop" id="payment-modal">
    <div class="mymodal" style="max-width: 900px;">
        <div class="title">سداد للعميل: <span id="payment-customer-name"></span></div>
        
        <div class="payment-methods">
            <div class="payment-method-card active" data-method="auto">
                <i class="fas fa-robot"></i>
                <div>تسديد تلقائي</div>
                <small>توزيع المبلغ على الفواتير الأقدم</small>
            </div>
            <div class="payment-method-card" data-method="manual">
                <i class="fas fa-hand-pointer"></i>
                <div>تسديد يدوي</div>
                <small>اختر الفواتير المراد سدادها</small>
            </div>
            <div class="payment-method-card" data-method="specific">
                <i class="fas fa-target"></i>
                <div>سداد محدد</div>
                <small>تسديد فاتورة معينة</small>
            </div>
        </div>

        <div class="payment-amount-section">
            <label>المبلغ المطلوب سداده:</label>
            <input type="number" id="payment-amount" class="form-control" placeholder="أدخل المبلغ">
            <button class="btn btn-outline btn-sm" id="suggest-amount">اقتراح المبلغ المتاح</button>
        </div>

        <!-- قسم الفواتير المستحقة -->
        <div class="pending-invoices-section">
            <h5>الفواتير المستحقة:</h5>
            <div id="invoices-list">
                <!-- سيتم تعبئتها بالفواتير -->
            </div>
        </div>

        <div class="payment-summary">
            <div class="summary-row">
                <span>المبلغ المدخل:</span>
                <span id="entered-amount">٠٫٠٠ ج.م</span>
            </div>
            <div class="summary-row">
                <span>المسدد تلقائياً:</span>
                <span id="auto-allocated">٠٫٠٠ ج.م</span>
            </div>
            <div class="summary-row">
                <span>المتبقي بعد السداد:</span>
                <span id="remaining-after">٠٫٠٠ ج.م</span>
            </div>
        </div>

        <div class="modal-footer">
            <button class="btn btn-secondary" id="cancel-payment">إلغاء</button>
            <button class="btn btn-primary" id="confirm-payment">تنفيذ السداد</button>
        </div>
    </div>
</div>
```

## رابعاً: صفحة العميل الشاملة

### التعديلات في `manage_customers.php`:

```php
// في loop العملاء نضيف:
<td>
    <?php
    $balance_class = $row['balance'] > 0 ? 'text-danger' : ($row['balance'] < 0 ? 'text-success' : '');
    ?>
    <span class="<?php echo $balance_class; ?>">
        <?php echo number_format($row['balance'], 2); ?> ج.م
    </span>
    <?php if ($row['balance'] > 0): ?>
        <br><small class="text-muted">مدين</small>
    <?php elseif ($row['balance'] < 0): ?>
        <br><small class="text-muted">دائن</small>
    <?php endif; ?>
</td>

<td class="text-center">
    <!-- الأزرار الحالية -->
    
    <!-- زر الفواتير المنسدل -->
    <div class="dropdown d-inline-block">
        <button class="btn btn-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown">
            <i class="fas fa-file-invoice"></i> الفواتير
        </button>
        <ul class="dropdown-menu">
            <li><a class="dropdown-item" href="pending_invoices.php?customer_id=<?php echo $row['id']; ?>">
                <i class="fas fa-clock text-warning"></i> مؤجل (<?php echo $pending_count; ?>)
            </a></li>
            <li><a class="dropdown-item" href="partial_invoices.php?customer_id=<?php echo $row['id']; ?>">
                <i class="fas fa-money-bill-wave text-info"></i> جزئي (<?php echo $partial_count; ?>)
            </a></li>
            <li><a class="dropdown-item" href="delivered_invoices.php?customer_id=<?php echo $row['id']; ?>">
                <i class="fas fa-check-circle text-success"></i> مسلم (<?php echo $delivered_count; ?>)
            </a></li>
            <li><a class="dropdown-item" href="returned_invoices.php?customer_id=<?php echo $row['id']; ?>">
                <i class="fas fa-undo text-danger"></i> مرتجع (<?php echo $returned_count; ?>)
            </a></li>
        </ul>
    </div>

    <!-- زر السداد -->
    <button class="btn btn-success btn-sm payment-btn" data-customer-id="<?php echo $row['id']; ?>" 
            data-customer-name="<?php echo htmlspecialchars($row['name']); ?>"
            data-customer-balance="<?php echo $row['balance']; ?>">
        <i class="fas fa-money-bill-wave"></i> سداد
    </button>

    <!-- زر الشغلانات -->
    <a href="work_orders.php?customer_id=<?php echo $row['id']; ?>" class="btn btn-info btn-sm">
        <i class="fas fa-tools"></i> الشغلانات
    </a>

    <!-- زر حركات الرصيد -->
    <a href="customer_balance.php?customer_id=<?php echo $row['id']; ?>" class="btn btn-warning btn-sm">
        <i class="fas fa-wallet"></i> الرصيد
    </a>
</td>
```

## خامساً: التخيل الكامل لصفحة العميل

### قسم رأس العميل:
```
[اسم العميل] [رقم الموبايل] [المدينة]
╔═══════════════════════════════════════╗
║ 🏷️  الرصيد: 1,200.00 ج.م (مدين)       ║
║ 📊 إجمالي المشتريات: 15,000.00 ج.م   ║
║ ⭐ متوسط السداد: 85%                 ║
╚═══════════════════════════════════════╝
```

### قسم الإجراءات السريعة:
```
[🔄 سداد] [📋 فاتورة جديدة] [🔧 شغلانة جديدة] [📊 كشف حساب]
```

### قسم الفواتير المصغرة:
```
الفواتير:
┌────────────┬──────────┬──────────┬──────────┐
│   مؤجل     │  جزئي    │   مسلم   │  مرتجع   │
├────────────┼──────────┼──────────┼──────────┤
│   3 فواتير │ 2 فاتورة │ 10 فاتورة│ 1 فاتورة │
│    2,500  │  1,200  │  11,300 │  800    │
└────────────┴──────────┴──────────┴──────────┘
```

### قسم الشغلانات النشطة:
```
الشغلانات النشطة:
• تركيب شباك (أحمد) - ⏳ قيد التنفيذ
• تصليح باب (محمد) - ✅ مكتمل
```

### قسم آخر الحركات:
```
آخر الحركات:
2024-01-15  سداد        500.00   رصيد: 1,200.00
2024-01-10  فاتورة      800.00   رصيد: 1,700.00  
2024-01-05  مرتجع       300.00   رصيد: 2,500.00
```

## سادساً: نماذج CSS إضافية

```css
/* أنماط الرصيد */
.balance-positive { color: #dc3545; font-weight: bold; }
.balance-negative { color: #28a745; font-weight: bold; }

/* كروت الفواتير المصغرة */
.mini-invoice-cards {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 10px;
    margin: 15px 0;
}

.mini-card {
    padding: 15px;
    border-radius: 8px;
    text-align: center;
    background: white;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.mini-card.pending { border-left: 4px solid #ffc107; }
.mini-card.partial { border-left: 4px solid #17a2b8; }
.mini-card.delivered { border-left: 4px solid #28a745; }
.mini-card.returned { border-left: 4px solid #dc3545; }

/* كروت طرق السداد */
.payment-method-cards {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 10px;
    margin: 15px 0;
}

.payment-method-card {
    padding: 15px;
    border: 2px solid #dee2e6;
    border-radius: 8px;
    text-align: center;
    cursor: pointer;
    transition: all 0.3s;
}

.payment-method-card.active {
    border-color: #007bff;
    background-color: #f8f9fa;
}

.payment-method-card:hover {
    border-color: #007bff;
}
```

## سابعاً: آلية العمل المقترحة

### تدفق الشغلانة:
1. الصنايعي يطلب إنشاء شغلانة للعميل
2. عند إنشاء فاتورة للعميل، يظهر اختيار ربطها بالشغلانة
3. النظام يسجل العلاقة بين الفاتورة والشغلانة
4. يمكن تتبع كل الشغلانات والفاتورات المرتبطة بها

### تدفق المرتجع:
1. اختيار الفاتورة المراد عمل مرتجع لها
2. تحديد المنتجات والكميات المرتجعة
3. اختيار طريقة الاسترجاع (نقدي/رصيد)
4. النظام يحدث المخزون والرصيد تلقائياً

### تدفق السداد:
1. إدخال المبلغ المطلوب سداده
2. اختيار طريقة التوزيع (تلقائي/يدوي/محدد)
3. النظام يقترح التوزيع الأمثل
4. تأكيد وتنفيذ السداد

هذا التصور يغطي كل النقاط اللي ذكرتها. عاوز تبدأ بتنفيذ جزء معين أولاً ولا محتاج أي تعديلات في التصور؟4




أهلاً وسهلاً! اسأل يا باشا، وانا تحت أمرك.

## أولاً: نظام الشغلانة والفاتورة

### السؤال: ازاي بنربط الشغلانة بالفاتورة؟

**الإجابة:** هتكون علاقة Many-to-Many:

```sql
-- جدول جديد لربط الفواتير بالشغلانات
CREATE TABLE `invoice_work_orders` (
  `id` int(11) NOT NULL,
  `invoice_id` int(11) NOT NULL,
  `work_order_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
);
```

### التدفق العملي:
1. **الصنايعي بيطلب شغلانة:** 
   - بيقولك "عاوز أعمل شغلانة لعميل محمد"
   - بتسجّل الشغلانة في النظام

2. **لما تجي تعمل فاتورة:**
   - بتختار العميل (محمد)
   - النظام بيظهرلك كل الشغلانات النشطة بتاعته
   - بتختار الشغلانة اللي هتربطها بالفاتورة

3. **لما تكمل الفاتورة:**
   - النظام بيتذكر إن الفاتورة دي متعلقة بالشغلانة دي
   - الصنايعي يقدر يشوف كل الفواتير المتعلقة بشغلانه

## ثانياً: نظام المرتجعات - تفصيل أكتر

### السؤال: إيه اللي بيحصل exactly في المرتجع؟

**الإجابة:** فيه 4 حاجات بتحصل:

### 1. **ترجيع المنتج للمخزن:**
```php
// بيحصل update لل batches
UPDATE batches SET remaining = remaining + returned_qty 
WHERE product_id = ? AND id = ?;
```

### 2. **تعديل رصيد العميل:**
```php
if ($refund_method == 'cash') {
    // لو استرجاع نقدي - بنزود رصيد العميل
    UPDATE customers SET balance = balance - refund_amount WHERE id = ?;
} else if ($refund_method == 'credit') {
    // لو إضافة لرصيد - بنزود رصيده
    UPDATE customers SET balance = balance + refund_amount WHERE id = ?;
}
```

### 3. **تسجيل الحركة المالية:**
```php
INSERT INTO customer_balance_transactions 
(customer_id, invoice_id, transaction_type, amount, previous_balance, new_balance) 
VALUES (?, ?, 'refund', ?, ?, ?);
```

### 4. **تحديث حالة الفاتورة:**
- لو مرتجع كامل: `delivered = 'reverted'`
- لو مرتجع جزئي: `delivered = 'partial'` + بنعدل المبالغ

## ثالثاً: نظام السداد الذكي

### السؤال: إيه اللي بيحصل في السداد التلقائي؟

**الإجابة:** بيكون في 3 طرق:

### 1. **التلقائي (الأقدم أولاً):**
```php
function autoAllocatePayment($customer_id, $payment_amount) {
    // بنجيب الفواتير الأقدم أولاً
    $invoices = getPendingInvoices($customer_id, 'ASC');
    
    foreach ($invoices as $invoice) {
        if ($payment_amount <= 0) break;
        
        $remaining = $invoice['total_after_discount'] - $invoice['paid_amount'];
        $amount_to_pay = min($remaining, $payment_amount);
        
        if ($amount_to_pay > 0) {
            // بنسدد للفاتورة
            payInvoice($invoice['id'], $amount_to_pay);
            $payment_amount -= $amount_to_pay;
        }
    }
}
```

### 2. **اليدوي:**
- بتختار انت الفواتير اللي عاوز تسددها
- بتدخل المبلغ لكل فاتورة

### 3. **المحدد:**
- بتسدد فاتورة معينة بالكامل
- أو جزء من فاتورة معينة

## رابعاً: واجهة عرض العميل - تفصيل أكتر

### السؤال: عاوز أشوف صفحة العميل إيه بالظبط؟

**تصور الصفحة:**

```
┌─────────────────────────────────────────────────────────────┐
│   👤 محمد أحمد - 01234567890 - القاهرة                     │
├─────────────────────────────────────────────────────────────┤
│  🏷️  الرصيد: 1,200.00 ج.م (مدين)   📊 إجمالي المشتريات: 15,000 │
│  ⭐ تقييم: 4.5/5            🕒 آخر حركة: 2024-01-15         │
└─────────────────────────────────────────────────────────────┘

[🔄 سداد] [📋 فاتورة جديدة] [🔧 شغلانة جديدة] [📊 كشف حساب]

┌────────────┬──────────┬──────────┬──────────┬──────────┐
│   مؤجل     │  جزئي    │   مسلم   │  مرتجع   │  الجميع  │
├────────────┼──────────┼──────────┼──────────┼──────────┤
│   3 فواتير │ 2 فاتورة │ 10 فاتورة│ 1 فاتورة │ 16 فاتورة│
│    2,500  │  1,200  │  11,300 │  800    │  15,800 │
└────────────┴──────────┴──────────┴──────────┴──────────┘

📋 آخر الفواتير:
┌──────┬────────────┬──────────┬──────────┬──────────┐
│ #123 │ 2024-01-15 │  800.00 │  500.00 │  300.00 │
│ #122 │ 2024-01-10 │  1,200  │  1,200  │  0.00   │
│ #121 │ 2024-01-05 │  500.00 │  200.00 │  300.00 │
└──────┴────────────┴──────────┴──────────┴──────────┘

🔧 الشغلانات النشطة:
• تركيب شباك - أحمد - ⏳ قيد التنفيذ - منذ 3 أيام
• تصليح باب - محمد - ✅ مكتمل - منذ يومين

📈 حركات الرصيد:
2024-01-15  سداد        500.00   رصيد: 1,200.00
2024-01-10  فاتورة      800.00   رصيد: 1,700.00  
2024-01-05  مرتجع       300.00   رصيد: 2,500.00
```

## خامساً: أسئلة تقنية مهمة

### السؤال: إزاي هتتعامل مع المرتجع لو الفاتورة فيها خصم؟

**الإجابة:** بنحسب المرتجع بنسبة الخصم:

```php
// مثال: فاتورة قيمتها 1000 جنيه وخصم 10%
$original_total = 1000;
$discount_percent = 10;
$final_total = 900;

// لو عاوز ترجع منتج سعره 200 في الفاتورة الأصلية
$return_amount = 200 * ($final_total / $original_total); // = 180 جنيه
```

### السؤال: إزاي هتتعامل مع الفاتورة الجزئية في السداد التلقائي؟

**الإجابة:** بنفضل نوزع المبلغ على الفواتير لحد ما المبلغ يخلص:

```php
// كود توزيع المبلغ
$remaining_payment = $payment_amount;

foreach ($invoices as $invoice) {
    if ($remaining_payment <= 0) break;
    
    $invoice_remaining = $invoice['total_after_discount'] - $invoice['paid_amount'];
    $can_pay = min($invoice_remaining, $remaining_payment);
    
    if ($can_pay > 0) {
        processPayment($invoice['id'], $can_pay);
        $remaining_payment -= $can_pay;
    }
}

// لو فضل مبلغ بعد ما سددنا كل الفواتير
if ($remaining_payment > 0) {
    // بنزوده رصيد للعميل
    addToCustomerBalance($customer_id, $remaining_payment);
}
```

## سادساً: أمثلة عملية من واقع الشغل

### مثال 1: الصنايعي بيطلب شغلانة
```
الصنايعي أحمد: "عاوز أعمل شغلانة تركيب شباك لعميل محمد"
الرد: "تمام، هدخلك على نظام الشغلانات وتسجلها"

- بيضيف شغلانة جديدة
- بيختار العميل (محمد)
- بيدخل تفاصيل الشغلانة (تركيب شباك)
- بيحدد الصنايعي (أحمد)
- بيحط توقيت التسليم المتوقع
```

### مثال 2: عملية بيع مرتبطة بشغلانة
```
لما تجي تعمل فاتورة لـ محمد:
- بتدور على الشغلانات النشطة بتاعته
- بتلاقي شغلانة "تركيب شباك"
- بتضيف المنتجات (شباك، مسامير، ...)
- النظام بيتذكر إن الفاتورة دي متعلقة بالشغلانة دي
```

### مثال 3: مرتجع لسبب معين
```
العميل محمد: "عاوز أرجع المنتج علشان فيه عيب"
الرد: "تمام، هعمللك مرتجع"

- بتدخل على الفاتورة اللي فيها المنتج
- بتختار المنتج اللي عاوز ترجعه
- بتحدد الكمية والسبب
- بتختار طريقة الاسترجاع (نقدي ولا رصيد)
- النظام بيتعامل مع المخزون والرصيد
```

### مثال 4: سداد ذكي
```
العميل محمد: "عندي 1000 جنيه عاوز أسدد بيها"
الرد: "هوزعهم لك على الفواتير الأقدم"

- النظام بيشوف الفواتير المؤجلة
- بيبدأ بالأقدم
- بيقولك: "هسدد 500 للفاتورة #120 و500 للفاتورة #121"
- وبعد السداد بيعدل الرصيد
```

## سابعاً: استفسارات إضافية

### السؤال: إزاي هتتعامل مع الفواتير الملغاة؟

**الإجابة:** 
- `delivered = 'canceled'` - فاتورة ملغاة
- `cancel_reason` - سبب الإلغاء
- بنرجع المخزون تلقائياً
- بنعدل رصيد العميل لو كان دافع

### السؤال: إزاي هتشغل التقارير؟

**الإجابة:** تقارير متقدمة:
1. **تقرير الصنايعية:** كل صنايعي وشنطة الشغلانات بتاعته
2. **تقرير المرتجعات:** تحليل أسباب المرتجعات
3. **تقرير السداد:** معدلات السداد للعملاء
4. **تقرير الأرباح:** أرباح كل صنايعي على حدة

---

بص يا صاحبي، ده تصور كامل للنظام. 

عاوز تركز على حاجة معينة من دول ولا عندك أسئلة تانية؟ 

وإزاي حابب نبدأ التنفيذ؟ جزء جزء ولا جملة واحدة؟


# الخطوة الأولى: قاعدة البيانات - التعديلات المطلوبة

## أولاً: الجداول الجديدة المطلوبة

```sql
-- 1. جدول الصنايعية
CREATE TABLE `workers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `mobile` varchar(11) DEFAULT NULL,
  `specialization` varchar(100) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
);

-- 2. جدول الشغلانات
CREATE TABLE `work_orders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `customer_id` int(11) NOT NULL,
  `worker_id` int(11) NOT NULL,
  `order_number` varchar(50) NOT NULL,
  `description` text NOT NULL,
  `status` enum('pending','in_progress','completed','delivered','cancelled') DEFAULT 'pending',
  `total_amount` decimal(12,2) DEFAULT 0.00,
  `paid_amount` decimal(12,2) DEFAULT 0.00,
  `delivery_date` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`),
  FOREIGN KEY (`worker_id`) REFERENCES `workers`(`id`)
);

-- 3. جدول ربط الفواتير بالشغلانات
CREATE TABLE `invoice_work_orders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `invoice_id` int(11) NOT NULL,
  `work_order_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  FOREIGN KEY (`invoice_id`) REFERENCES `invoices_out`(`id`),
  FOREIGN KEY (`work_order_id`) REFERENCES `work_orders`(`id`)
);

-- 4. جدول حركات الرصيد
CREATE TABLE `customer_balance_transactions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `customer_id` int(11) NOT NULL,
  `invoice_id` int(11) DEFAULT NULL,
  `transaction_type` enum('deposit','withdraw','payment','refund','adjustment') NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `previous_balance` decimal(12,2) NOT NULL,
  `new_balance` decimal(12,2) NOT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`)
);

-- 5. جدول فواتير المرتجعات
CREATE TABLE `invoice_returns` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `original_invoice_id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `return_type` enum('full','partial') NOT NULL,
  `return_reason` varchar(255) NOT NULL,
  `refund_status` enum('pending','refunded','credit','not_refunded') DEFAULT 'pending',
  `refund_amount` decimal(12,2) DEFAULT 0.00,
  `refund_method` enum('cash','bank_transfer','credit') DEFAULT NULL,
  `total_amount` decimal(12,2) NOT NULL,
  `status` enum('pending','approved','rejected','completed') DEFAULT 'pending',
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  FOREIGN KEY (`original_invoice_id`) REFERENCES `invoices_out`(`id`)
);
```

## ثانياً: التعديلات على الجداول الحالية

```sql
-- إضافة حقل الرصيد للعملاء
ALTER TABLE `customers` 
ADD `balance` DECIMAL(12,2) DEFAULT 0.00 COMMENT 'رصيد العميل (موجب = مدين, سالب = دائن)';

-- إضافة حقول للفاتورة للتعامل مع الشغلانات
ALTER TABLE `invoices_out` 
ADD `work_order_id` INT(11) NULL AFTER `customer_id`,
ADD `is_linked_to_work` TINYINT(1) DEFAULT 0 AFTER `work_order_id`;

-- تعديل حالة الفاتورة لتشمل المرتجعات
ALTER TABLE `invoices_out` 
MODIFY `delivered` ENUM('yes','no','canceled','reverted','partial','returned') NOT NULL DEFAULT 'no';
```

# الخطوة الثانية: سيناريو كامل من البداية

## السيناريو 1: عميل عادي بدون شغلانة

### الخطوة 1: دخول العميل للمحل
- **البيانات المطلوبة:**
  - الاسم (مطلوب)
  - الموبايل (مطلوب) 
  - المدينة (اختياري)
  - العنوان (اختياري)
  - ملاحظات (اختياري)

### الخطوة 2: إنشاء العميل في النظام
```php
// في ملف insert_customer.php
$customer_data = [
    'name' => 'محمد أحمد',
    'mobile' => '01234567890',
    'city' => 'القاهرة',
    'address' => 'العنوان هنا',
    'notes' => 'عميل جديد'
];
```

### الخطوة 3: صفحة العميل الجديدة
سيتم توجيهك لصفحة العميل التي ستحتوي على:

**الجزء العلوي:**
```
┌─────────────────────────────────────────────────────────────┐
│   👤 محمد أحمد - 01234567890 - القاهرة                     │
├─────────────────────────────────────────────────────────────┤
│  🏷️  الرصيد: ٠٫٠٠ ج.م        📊 إجمالي المشتريات: ٠٫٠٠      │
│  ⭐ تقييم: جديد             🕒 العضو منذ: 2024-01-20       │
└─────────────────────────────────────────────────────────────┘
```

**أزرار الإجراءات:**
```
[📋 فاتورة جديدة] [🔧 شغلانة جديدة] [💳 سداد] [📊 كشف حساب]
```

**إحصائيات سريعة:**
```
┌────────────┬──────────┬──────────┬──────────┐
│   مؤجل     │  جزئي    │   مسلم   │  مرتجع   │
├────────────┼──────────┼──────────┼──────────┤
│     ٠      │    ٠     │    ٠     │    ٠     │
└────────────┴──────────┴──────────┴──────────┘
```

## السيناريو 2: عميل مع شغلانة

### الخطوة 1: إنشاء الشغلانة أولاً
```php
// في ملف create_work_order.php
$work_order_data = [
    'customer_id' => 1,
    'worker_id' => 1, // الصنايعي أحمد
    'description' => 'تركيب شباك ألوميتال',
    'delivery_date' => '2024-01-25',
    'notes' => 'الشغلانة مستعجلة'
];
```

### الخطوة 2: صفحة إنشاء الفاتورة المعدلة

**التعديلات على create_invoice.php:**

1. **إضافة قسم الشغلانة:**
```html
<!-- بعد قسم العميل -->
<div class="work-order-section" id="work-order-section">
    <div class="panel-title">
        <i class="fas fa-tools"></i>
        ربط بالشغلانة (اختياري)
    </div>
    <select id="work-order-select" class="form-select">
        <option value="">-- اختر الشغلانة --</option>
        <option value="1">#WO-001 - تركيب شباك ألوميتال (قيد التنفيذ)</option>
        <option value="2">#WO-002 - تصليح باب (مكتمل)</option>
    </select>
    
    <div id="work-order-details" style="display: none;">
        <div class="work-order-info">
            <strong>تفاصيل الشغلانة:</strong>
            <div id="wo-description"></div>
            <div id="wo-worker"></div>
            <div id="wo-status"></div>
        </div>
    </div>
</div>
```

2. **الكود JavaScript:**
```javascript
// عندما يتم اختيار عميل
function onCustomerSelected(customer) {
    // تحميل الشغلانات الخاصة بهذا العميل
    loadWorkOrders(customer.id);
}

// تحميل الشغلانات
async function loadWorkOrders(customerId) {
    const response = await fetch(`work_orders.php?customer_id=${customerId}`);
    const workOrders = await response.json();
    
    const select = document.getElementById('work-order-select');
    select.innerHTML = '<option value="">-- اختر الشغلانة --</option>';
    
    workOrders.forEach(wo => {
        const option = document.createElement('option');
        option.value = wo.id;
        option.textContent = `#${wo.order_number} - ${wo.description} (${wo.status})`;
        select.appendChild(option);
    });
}

// عند اختيار شغلانة
document.getElementById('work-order-select').addEventListener('change', function() {
    const selectedId = this.value;
    const detailsDiv = document.getElementById('work-order-details');
    
    if (selectedId) {
        // عرض تفاصيل الشغلانة
        showWorkOrderDetails(selectedId);
        detailsDiv.style.display = 'block';
    } else {
        detailsDiv.style.display = 'none';
    }
});
```

### الخطوة 3: حفظ الفاتورة مع الشغلانة
```php
// في عملية حفظ الفاتورة
if (isset($_POST['work_order_id']) && !empty($_POST['work_order_id'])) {
    $work_order_id = (int)$_POST['work_order_id'];
    
    // ربط الفاتورة بالشغلانة
    $stmt = $conn->prepare("INSERT INTO invoice_work_orders (invoice_id, work_order_id) VALUES (?, ?)");
    $stmt->bind_param('ii', $invoice_id, $work_order_id);
    $stmt->execute();
    
    // تحديث حالة الشغلانة إذا لزم
    updateWorkOrderStatus($work_order_id, 'in_progress');
}
```

# الخطوة الثالثة: صفحة العميل الشاملة

## التصميم النهائي لصفحة العميل

```php
// في ملف customer_profile.php
<?php
$customer_id = $_GET['id'];
$customer = getCustomerById($customer_id);
$invoices = getCustomerInvoices($customer_id);
$work_orders = getCustomerWorkOrders($customer_id);
$balance_transactions = getCustomerBalanceTransactions($customer_id);
?>

<div class="customer-profile">
    <!-- رأس العميل -->
    <div class="customer-header">
        <div class="customer-avatar">
            <?php echo mb_substr($customer['name'], 0, 1); ?>
        </div>
        <div class="customer-info">
            <h1><?php echo $customer['name']; ?></h1>
            <div class="customer-meta">
                <span><i class="fas fa-phone"></i> <?php echo $customer['mobile']; ?></span>
                <span><i class="fas fa-city"></i> <?php echo $customer['city']; ?></span>
                <span><i class="fas fa-calendar"></i> عضو منذ <?php echo $customer['created_at']; ?></span>
            </div>
        </div>
        <div class="customer-stats">
            <div class="stat-card <?php echo $customer['balance'] > 0 ? 'negative' : 'positive'; ?>">
                <div class="stat-value"><?php echo number_format($customer['balance'], 2); ?> ج.م</div>
                <div class="stat-label">الرصيد</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo number_format($customer['total_purchases'], 2); ?> ج.م</div>
                <div class="stat-label">إجمالي المشتريات</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $customer['invoice_count']; ?></div>
                <div class="stat-label">عدد الفواتير</div>
            </div>
        </div>
    </div>

    <!-- أزرار الإجراءات السريعة -->
    <div class="quick-actions">
        <a href="create_invoice.php?customer_id=<?php echo $customer_id; ?>" class="btn btn-primary">
            <i class="fas fa-receipt"></i> فاتورة جديدة
        </a>
        <a href="create_work_order.php?customer_id=<?php echo $customer_id; ?>" class="btn btn-info">
            <i class="fas fa-tools"></i> شغلانة جديدة
        </a>
        <a href="customer_payment.php?customer_id=<?php echo $customer_id; ?>" class="btn btn-success">
            <i class="fas fa-money-bill-wave"></i> سداد
        </a>
        <a href="customer_statement.php?customer_id=<?php echo $customer_id; ?>" class="btn btn-warning">
            <i class="fas fa-file-invoice"></i> كشف حساب
        </a>
    </div>

    <!-- إحصائيات الفواتير -->
    <div class="invoices-stats">
        <div class="stats-cards">
            <div class="stat-card pending">
                <div class="stat-value"><?php echo $invoices['pending_count']; ?></div>
                <div class="stat-label">مؤجل</div>
                <div class="stat-amount"><?php echo number_format($invoices['pending_amount'], 2); ?> ج.م</div>
            </div>
            <div class="stat-card partial">
                <div class="stat-value"><?php echo $invoices['partial_count']; ?></div>
                <div class="stat-label">جزئي</div>
                <div class="stat-amount"><?php echo number_format($invoices['partial_amount'], 2); ?> ج.م</div>
            </div>
            <div class="stat-card delivered">
                <div class="stat-value"><?php echo $invoices['delivered_count']; ?></div>
                <div class="stat-label">مسلم</div>
                <div class="stat-amount"><?php echo number_format($invoices['delivered_amount'], 2); ?> ج.م</div>
            </div>
            <div class="stat-card returned">
                <div class="stat-value"><?php echo $invoices['returned_count']; ?></div>
                <div class="stat-label">مرتجع</div>
                <div class="stat-amount"><?php echo number_format($invoices['returned_amount'], 2); ?> ج.م</div>
            </div>
        </div>
    </div>

    <!-- تبويبات المحتوى -->
    <div class="customer-tabs">
        <ul class="nav nav-tabs" id="customerTabs" role="tablist">
            <li class="nav-item">
                <a class="nav-link active" id="invoices-tab" data-bs-toggle="tab" href="#invoices">
                    <i class="fas fa-receipt"></i> الفواتير
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" id="work-orders-tab" data-bs-toggle="tab" href="#work-orders">
                    <i class="fas fa-tools"></i> الشغلانات
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" id="balance-tab" data-bs-toggle="tab" href="#balance">
                    <i class="fas fa-wallet"></i> حركات الرصيد
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" id="returns-tab" data-bs-toggle="tab" href="#returns">
                    <i class="fas fa-undo"></i> المرتجعات
                </a>
            </li>
        </ul>

        <div class="tab-content" id="customerTabsContent">
            <!-- تبويب الفواتير -->
            <div class="tab-pane fade show active" id="invoices">
                <div class="invoices-filters">
                    <select id="invoice-status-filter" class="form-select">
                        <option value="all">جميع الفواتير</option>
                        <option value="pending">مؤجل</option>
                        <option value="partial">جزئي</option>
                        <option value="delivered">مسلم</option>
                        <option value="returned">مرتجع</option>
                    </select>
                </div>
                
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>رقم الفاتورة</th>
                            <th>التاريخ</th>
                            <th>الإجمالي</th>
                            <th>المدفوع</th>
                            <th>المتبقي</th>
                            <th>الحالة</th>
                            <th>الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($invoices['list'] as $invoice): ?>
                        <tr>
                            <td>#<?php echo $invoice['id']; ?></td>
                            <td><?php echo $invoice['created_at']; ?></td>
                            <td><?php echo number_format($invoice['total_after_discount'], 2); ?> ج.م</td>
                            <td><?php echo number_format($invoice['paid_amount'], 2); ?> ج.م</td>
                            <td>
                                <span class="<?php echo $invoice['remaining_amount'] > 0 ? 'text-danger' : 'text-success'; ?>">
                                    <?php echo number_format($invoice['remaining_amount'], 2); ?> ج.م
                                </span>
                            </td>
                            <td>
                                <span class="status-badge status-<?php echo $invoice['delivered']; ?>">
                                    <?php 
                                    $status_text = [
                                        'pending' => 'مؤجل',
                                        'partial' => 'جزئي',
                                        'delivered' => 'مسلم',
                                        'returned' => 'مرتجع'
                                    ];
                                    echo $status_text[$invoice['delivered']];
                                    ?>
                                </span>
                            </td>
                            <td>
                                <div class="btn-group">
                                    <a href="view_invoice.php?id=<?php echo $invoice['id']; ?>" class="btn btn-sm btn-info">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <?php if ($invoice['delivered'] == 'pending' || $invoice['delivered'] == 'partial'): ?>
                                    <a href="customer_payment.php?invoice_id=<?php echo $invoice['id']; ?>" class="btn btn-sm btn-success">
                                        <i class="fas fa-money-bill-wave"></i>
                                    </a>
                                    <?php endif; ?>
                                    <?php if ($invoice['delivered'] == 'delivered'): ?>
                                    <a href="create_return.php?invoice_id=<?php echo $invoice['id']; ?>" class="btn btn-sm btn-warning">
                                        <i class="fas fa-undo"></i>
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- تبويب الشغلانات -->
            <div class="tab-pane fade" id="work-orders">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>رقم الشغلانة</th>
                            <th>الوصف</th>
                            <th>الصنايعي</th>
                            <th>الحالة</th>
                            <th>تاريخ التسليم</th>
                            <th>الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($work_orders as $wo): ?>
                        <tr>
                            <td>#<?php echo $wo['order_number']; ?></td>
                            <td><?php echo $wo['description']; ?></td>
                            <td><?php echo $wo['worker_name']; ?></td>
                            <td>
                                <span class="status-badge status-<?php echo $wo['status']; ?>">
                                    <?php echo $wo['status']; ?>
                                </span>
                            </td>
                            <td><?php echo $wo['delivery_date']; ?></td>
                            <td>
                                <a href="view_work_order.php?id=<?php echo $wo['id']; ?>" class="btn btn-sm btn-info">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <?php if ($wo['status'] == 'pending' || $wo['status'] == 'in_progress'): ?>
                                <a href="create_invoice.php?work_order_id=<?php echo $wo['id']; ?>" class="btn btn-sm btn-primary">
                                    <i class="fas fa-receipt"></i>
                                </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- تبويب حركات الرصيد -->
            <div class="tab-pane fade" id="balance">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>التاريخ</th>
                            <th>نوع الحركة</th>
                            <th>المبلغ</th>
                            <th>الرصيد قبل</th>
                            <th>الرصيد بعد</th>
                            <th>ملاحظات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($balance_transactions as $transaction): ?>
                        <tr>
                            <td><?php echo $transaction['created_at']; ?></td>
                            <td>
                                <span class="transaction-type type-<?php echo $transaction['transaction_type']; ?>">
                                    <?php 
                                    $type_text = [
                                        'deposit' => 'ايداع',
                                        'withdraw' => 'سحب',
                                        'payment' => 'سداد',
                                        'refund' => 'مرتجع',
                                        'adjustment' => 'تعديل'
                                    ];
                                    echo $type_text[$transaction['transaction_type']];
                                    ?>
                                </span>
                            </td>
                            <td class="<?php echo $transaction['amount'] > 0 ? 'text-success' : 'text-danger'; ?>">
                                <?php echo number_format($transaction['amount'], 2); ?> ج.م
                            </td>
                            <td><?php echo number_format($transaction['previous_balance'], 2); ?> ج.م</td>
                            <td><?php echo number_format($transaction['new_balance'], 2); ?> ج.م</td>
                            <td><?php echo $transaction['notes']; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- تبويب المرتجعات -->
            <div class="tab-pane fade" id="returns">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>رقم المرتجع</th>
                            <th>الفاتورة الأصلية</th>
                            <th>نوع المرتجع</th>
                            <th>المبلغ</th>
                            <th>طريقة الاسترجاع</th>
                            <th>الحالة</th>
                            <th>التاريخ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($returns as $return): ?>
                        <tr>
                            <td>#RET-<?php echo $return['id']; ?></td>
                            <td>
                                <a href="view_invoice.php?id=<?php echo $return['original_invoice_id']; ?>">
                                    #<?php echo $return['original_invoice_id']; ?>
                                </a>
                            </td>
                            <td>
                                <span class="badge bg-<?php echo $return['return_type'] == 'full' ? 'danger' : 'warning'; ?>">
                                    <?php echo $return['return_type'] == 'full' ? 'كامل' : 'جزئي'; ?>
                                </span>
                            </td>
                            <td><?php echo number_format($return['refund_amount'], 2); ?> ج.م</td>
                            <td>
                                <?php 
                                $method_text = [
                                    'cash' => 'نقدي',
                                    'bank_transfer' => 'تحويل بنكي',
                                    'credit' => 'رصيد'
                                ];
                                echo $method_text[$return['refund_method']];
                                ?>
                            </td>
                            <td>
                                <span class="status-badge status-<?php echo $return['status']; ?>">
                                    <?php echo $return['status']; ?>
                                </span>
                            </td>
                            <td><?php echo $return['created_at']; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
```

## CSS الإضافي

```css
/* أنماط الرصيد والإحصائيات */
.customer-stats {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 15px;
    margin: 20px 0;
}

.stat-card {
    background: white;
    padding: 20px;
    border-radius: 10px;
    text-align: center;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    border-left: 4px solid #007bff;
}

.stat-card.negative {
    border-left-color: #dc3545;
}

.stat-card.positive {
    border-left-color: #28a745;
}

.stat-value {
    font-size: 24px;
    font-weight: bold;
    margin-bottom: 5px;
}

.stat-label {
    color: #6c757d;
    font-size: 14px;
}

/* كروت إحصائيات الفواتير */
.stats-cards {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 15px;
    margin: 20px 0;
}

.stats-cards .stat-card {
    border-left-width: 4px;
}

.stat-card.pending { border-left-color: #ffc107; }
.stat-card.partial { border-left-color: #17a2b8; }
.stat-card.delivered { border-left-color: #28a745; }
.stat-card.returned { border-left-color: #dc3545; }

.stat-amount {
    font-size: 16px;
    font-weight: bold;
    margin-top: 5px;
}

/* أزرار الإجراءات السريعة */
.quick-actions {
    display: flex;
    gap: 10px;
    margin: 20px 0;
    flex-wrap: wrap;
}

.quick-actions .btn {
    flex: 1;
    min-width: 150px;
    text-align: center;
}

/* تبويبات المحتوى */
.customer-tabs {
    margin-top: 30px;
}

.customer-tabs .nav-tabs {
    border-bottom: 2px solid #dee2e6;
}

.customer-tabs .nav-link {
    border: none;
    color: #6c757d;
    font-weight: 500;
    padding: 12px 20px;
}

.customer-tabs .nav-link.active {
    color: #007bff;
    border-bottom: 3px solid #007bff;
    background: transparent;
}

/* حالة الفواتير */
.status-badge {
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: bold;
}

.status-pending { background: #fff3cd; color: #856404; }
.status-partial { background: #d1ecf1; color: #0c5460; }
.status-delivered { background: #d4edda; color: #155724; }
.status-returned { background: #f8d7da; color: #721c24; }

/* أنواع الحركات */
.transaction-type {
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: bold;
}

.type-deposit { background: #d4edda; color: #155724; }
.type-withdraw { background: #f8d7da; color: #721c24; }
.type-payment { background: #d1ecf1; color: #0c5460; }
.type-refund { background: #fff3cd; color: #856404; }
.type-adjustment { background: #e2e3e5; color: #383d41; }
```

# الخطوة الرابعة: صفحات مساعدة جديدة

## 1. صفحة إنشاء الشغلانة (`create_work_order.php`)

## 2. صفحة السداد (`customer_payment.php`)

## 3. صفحة كشف الحساب (`customer_statement.php`)

## 4. صفحة المرتجع (`create_return.php`)

هل تريد أن أستمر في شرح أي من هذه الصفحات بالتفصيل؟ أم تريد التركيز على جزء معين أولاً؟