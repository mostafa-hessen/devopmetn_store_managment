<?php
$page_title = "تعديل الفاتورة - خصم إضافي";
require_once dirname(__DIR__) . '/config.php';
require_once BASE_DIR . 'partials/session_admin.php';
require_once BASE_DIR . 'partials/header.php';
require_once BASE_DIR . 'partials/sidebar.php';

function e($s) {
    return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// التحقق من معرف الفاتورة
$invoice_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($invoice_id <= 0) {
    echo "<div class='container mt-5'><div class='alert alert-danger'>معرف الفاتورة غير صالح</div></div>";
    require_once BASE_DIR . 'partials/footer.php';
    exit;
}

// CSRF Token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// معرف العميل من الرجوع
$customer_id = isset($_GET['customer_id']) ? intval($_GET['customer_id']) : 0;
$back_url = $customer_id > 0 
    ? BASE_URL . "client/customer_details.php?customer_id={$customer_id}" 
    : (BASE_URL . 'admin/pending_invoices.php');
?>

<style>
.loader-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.7);
    display: none;
    justify-content: center;
    align-items: center;
    z-index: 9999;
}

.loader-spinner {
    width: 60px;
    height: 60px;
    border: 5px solid #f3f3f3;
    border-top: 5px solid #3498db;
    border-radius: 50%;
    animation: spin 1s linear infinite;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

.profit-hidden {
    display: none;
}

.item-row.changed {
    background-color: #fff3cd;
    border-left: 3px solid #ffc107;
}

.item-row.row-disabled {
    background-color: #f8f9fa;
    opacity: 0.8;
    cursor: not-allowed;
}

.item-row.row-disabled td {
    color: #6c757d;
}

.badge-returned-lock {
    background-color: #dc3545;
    color: white;
    font-size: 0.7rem;
    padding: 2px 5px;
    border-radius: 3px;
    display: block;
    margin-top: 5px;
    width: fit-content;
}

.item-warning-alert {
    font-size: 0.75rem;
    padding: 4px 8px;
    margin: 0;
}

.alert-sm {
    padding: 0.25rem 0.5rem;
    font-size: 0.875rem;
}

.summary-card {
    background: linear-gradient(135deg, #63346eff 0%, #2e2e5eff 100%);
    color: white;
    border-radius: 10px;
    padding: 20px;
    margin-bottom: 20px;
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
}

.summary-item {
    background: rgba(255, 255, 255, 0.15);
    border-radius: 8px;
    padding: 15px;
    transition: all 0.3s ease;
    backdrop-filter: blur(10px);
    border: 1px solid rgba(255, 255, 255, 0.2);
}

.summary-item:hover {
    background: rgba(255, 255, 255, 0.25);
    transform: translateY(-2px);
}

.summary-label {
    font-size: 0.9rem;
    opacity: 0.9;
    margin-bottom: 8px;
    font-weight: 500;
}

.summary-value {
    font-size: 1.5rem;
    font-weight: bold;
    margin: 8px 0;
    text-shadow: 1px 1px 2px rgba(0,0,0,0.2);
}

.summary-currency {
    font-size: 0.85rem;
    opacity: 0.8;
}

.profit-status-indicator {
    display: inline-block;
    width: 10px;
    height: 10px;
    border-radius: 50%;
    margin-right: 5px;
    vertical-align: middle;
    cursor: help;
}

.profit-status-indicator-invoice{
    width: 50px;
    height: 50px;
    border-radius: 50%;
    margin-left: 5px;
    position: fixed;
    top: 95%;
    left: 0%;
    transform: translate(50%, -40%);


}

.profit-status-indicator.profit-positive {
    background: #28a745;
    box-shadow: 0 0 5px rgba(40, 167, 69, 0.5);
}

.profit-status-indicator.profit-negative {
    background: #dc3545;
    box-shadow: 0 0 5px rgba(220, 53, 69, 0.5);
}

.profit-status-indicator.profit-zero {
    background: #6c757d;
    box-shadow: 0 0 5px rgba(108, 117, 125, 0.5);
}

.profit-status-indicator.warning-discount {
    background: #ffc107;
    box-shadow: 0 0 5px rgba(255, 193, 7, 0.5);
}

.item-profit-indicator {
    display: inline-block;
    width: 12px;
    height: 12px;
    border-radius: 50%;
    margin-left: 5px;
    cursor: help;
    vertical-align: middle;
}

.item-profit-indicator.profit-positive {
    background: #28a745;
    box-shadow: 0 0 5px rgba(40, 167, 69, 0.5);
}

.item-profit-indicator.profit-negative {
    background: #dc3545;
    box-shadow: 0 0 5px rgba(220, 53, 69, 0.5);
}

.item-profit-indicator.profit-zero {
    background: #6c757d;
    box-shadow: 0 0 5px rgba(108, 117, 125, 0.5);
}

.item-profit-indicator.warning-discount {
    background: #ffc107;
    box-shadow: 0 0 5px rgba(255, 193, 7, 0.5);
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.6; }
}

.refund-section {
    background: #e7f3ff;
    border: 2px solid #2196F3;
    border-radius: 8px;
    padding: 15px;
    margin-top: 15px;
}
</style>

<div class="loader-overlay" id="loaderOverlay">
    <div class="loader-spinner"></div>
</div>

<div class="container mt-4" dir="rtl">
    <div class="card mb-4">
        <div class="card-header">
            <div class="header-title">
                <i class="fas fa-calculator"></i>
                <span>تعديل الفاتورة #<span id="invoiceNumber"><?php echo $invoice_id; ?></span></span>
            </div>
     <div class="row">
        
                <div class="col-6">
                           <a href="<?php echo $back_url; ?>" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-arrow-right"></i> العودة
            </a>
                </div>

             <div class="summary-label col-6 text-end ">
                                <!-- <i class="fas fa-chart-line"></i> الربح -->
                                <span class="profit-status-indicator profit-status-indicator-invoice" id="profitStatusIndicator" title="حالة الربح"></span>
                            </div>
     </div>
        </div>
        <div class="card-body">
            <!-- معلومات الفاتورة -->
            <div class="row mb-4">
                <div class="col-md-6 ">
                    <div class="text-muted "> <strong>العميل:</strong> <span class="fw-bold" id="customerName">-</span></div>
                    <div  class="text-muted"><strong>التاريخ:</strong> <span id="invoiceDate">-</span></div>
                    <div  class="text-muted"><strong>الحالة:</strong> <span id="invoiceStatus" class="badge">-</span></div>
                </div>
               
            </div>
            
            <!-- ملخص الإجماليات -->
            <div class="summary-card">
                <div class="row text-center mb-3">
                    <div class="col-md-3 col-6 mb-3">
                        <div class="summary-item">
                            <div class="summary-label">الإجمالي قبل الخصم</div>
                            <div class="summary-value" id="totalBefore">0.00</div>
                            <div class="summary-currency">ج.م</div>
                        </div>
                    </div>
                    <div class="col-md-3 col-6 mb-3">
                        <div class="summary-item">
                            <div class="summary-label">إجمالي المرتجعات  من البنود الفعاله</div>
                            <div class="summary-value text-warning" id="totalReturns">0.00</div>
                            <div class="summary-currency">ج.م</div>
                        </div>
                    </div>
                    <div class="col-md-3 col-6 mb-3">
                        <div class="summary-item">
                            <div class="summary-label">إجمالي الخصومات</div>
                            <div class="summary-value text-danger" id="totalDiscounts">0.00</div>
                            <div class="summary-currency">ج.م</div>
                        </div>
                    </div>
                    <div class="col-md-3 col-6 mb-3">
                        <div class="summary-item">
                            <div class="summary-label">الصافي</div>
                            <div class="summary-value text-primary" id="totalAfter">0.00</div>
                            <div class="summary-currency">ج.م</div>
                        </div>
                    </div>
                </div>
                <div class="row text-center mb-3">
                    <div class="col-md-4 col-6 mb-3">
                        <div class="summary-item">
                            <div class="summary-label">الخصم الإضافي</div>
                            <div class="summary-value text-info" id="totalAdditionalDiscount">0.00</div>
                            <div class="summary-currency">ج.م</div>
                        </div>
                    </div>
                    <div class="col-md-4 col-6 mb-3">
                        <div class="summary-item">
                            <div class="summary-label">المطلوب النهائي</div>
                            <div class="summary-value text-success" id="finalTotal">0.00</div>
                            <div class="summary-currency">ج.م</div>
                        </div>
                    </div>

                     <div class="col-md-4">
                    <div class="summary-item"><strong class="summary-label">المدفوع:

                    </strong> <div id="paidAmount" class="summary-value text-success" class="text-success">0.00 ج.م</div>
                            <div class="summary-currency">ج.م</div>
                </div>
                </div>
                <div class="summary-item"><strong>المتبقي:</strong> <span id="remainingAmount" class="text-warning">0.00 ج.م</span></div>
                    <div class="col-md-4 col-12 mb-3 d-none">
                        <div class="summary-item" id="profitIndicator">
                            <div class="summary-label">
                                <!-- <i class="fas fa-chart-line"></i> الربح -->
                                <span class="profit-status-indicator" id="profitStatusIndicator" title="حالة الربح"></span>
                            </div>
                            <!-- <div class="summary-currency">ج.م</div> -->
                        </div>
                        <div class="summary-value d-none" id="totalProfit">0.00</div>
                    </div>

                </div>
                <div class="row text-center">
                    <div class="col-12">
                        <div class="alert alert-info mb-0">
                            <i class="fas fa-info-circle"></i> الفرق: 
                            <strong id="totalDifference">0.00</strong> ج.م
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- جدول البنود -->
    <div class="card">
        <div class="card-header">
            <h5 class="mb-0"><i class="fas fa-list"></i> بنود الفاتورة</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered table-hover">
                    <thead class="table-light">
                        <tr>
                            <th>المنتج</th>
                            <th class="text-center">الكمية<br><small>(بعد المرتجع)</small></th>
                            <th class="text-center">سعر الوحدة</th>
                            <th class="text-center">الإجمالي قبل الخصم</th>
                            <th class="text-center">الخصم السابق</th>
                            <th class="text-center">سعر الوحدة بعد الخصم</th>
                            <th class="text-center">نوع الخصم الإضافي</th>
                            <th class="text-center">قيمة الخصم الإضافي</th>
                            <th class="text-center">الإجمالي بعد الخصم الإضافي</th>
                            <th class="profit-hidden text-center">الربح</th>
                        </tr>
                    </thead>
                    <tbody id="itemsTableBody">
                        <tr>
                            <td colspan="10" class="text-center">
                                <div class="spinner-border text-primary" role="status">
                                    <span class="visually-hidden">جاري التحميل...</span>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- معالجة الفرق المالي -->
    <div class="card mt-4" id="refundSection" style="display: none;">
        <div class="card-header bg-info text-white">
            <h5 class="mb-0"><i class="fas fa-money-bill-wave"></i> معالجة الفرق المالي</h5>
        </div>
        <div class="card-body refund-section">
            <div id="refundMessage"></div>
            <div class="mt-3" id="refundMethodSelection" style="display: none;">
                <label class="form-label"><strong>طريقة الإرجاع:</strong></label>
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="refundMethod" id="refundCash" value="cash" checked>
                    <label class="form-check-label" for="refundCash">
                        <i class="fas fa-money-bill"></i> رد نقدي
                    </label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="refundMethod" id="refundWallet" value="wallet">
                    <label class="form-check-label" for="refundWallet">
                        <i class="fas fa-wallet"></i> إضافة للمحفظة
                    </label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="refundMethod" id="refundBalance" value="balance_reduction">
                    <label class="form-check-label" for="refundBalance">
                        <i class="fas fa-minus-circle"></i> تخفيض من الرصيد المتبقي
                    </label>
                </div>
            </div>
        </div>
    </div>

    <!-- سبب التعديل -->
    <div class="card mt-4">
        <div class="card-header">
            <h5 class="mb-0"><i class="fas fa-comment-alt"></i> سبب التعديل <span class="text-danger">*</span></h5>
        </div>
        <div class="card-body">
            <textarea 
                class="form-control" 
                id="adjustmentReason" 
                rows="3" 
                placeholder="اكتب سبب التعديل (مثال: خصم إضافي للعميل لشراء كمية كبيرة)"
                required
            ></textarea>
            <small class="text-muted">يجب كتابة سبب واضح للتعديل</small>
        </div>
    </div>

    <!-- أزرار الحفظ -->
    <div class="card mt-4">
        <div class="card-body text-center">
            <button 
                class="btn btn-primary btn-lg px-5" 
                id="saveAdjustmentBtn"
                disabled
            >
                <i class="fas fa-save"></i> حفظ التعديل
            </button>
            <a href="<?php echo $back_url; ?>" class="btn btn-secondary btn-lg px-5">
                <i class="fas fa-times"></i> إلغاء
            </a>
        </div>
    </div>
</div>

<script>
const invoiceId = <?php echo $invoice_id; ?>;
const csrfToken = '<?php echo $csrf_token; ?>';
let invoiceData = null;
let adjustedItems = [];

// جلب بيانات الفاتورة
async function loadInvoiceData() {
    try {
        const response = await fetch(`<?php echo BASE_URL; ?>api/get_invoice_for_adjustment.php?invoice_id=${invoiceId}`);
        const result = await response.json();
        
        if (!result.success) {
            Swal.fire('خطأ', result.message || 'فشل في جلب بيانات الفاتورة', 'error');
            return;
        }
        
        invoiceData = result.invoice;
        adjustedItems = invoiceData.items.map(item => ({
            ...item,
            additional_discount_type: null,
            additional_discount_value: 0
        }));
        
        // عرض البيانات
        displayInvoiceInfo();
        displayItems();
        calculateTotals();
        
    } catch (error) {
        console.error('Error:', error);
        Swal.fire('خطأ', 'حدث خطأ أثناء جلب البيانات', 'error');
    }
}

// عرض معلومات الفاتورة
function displayInvoiceInfo() {
    document.getElementById('invoiceNumber').textContent = invoiceData.id;
    document.getElementById('customerName').textContent = invoiceData.customer_name || '-';
    document.getElementById('invoiceDate').textContent = new Date(invoiceData.created_at).toLocaleDateString('ar-EG');
    
    const statusBadge = document.getElementById('invoiceStatus');
    const statusText = {
        'pending': 'مؤجل',
        'partial': 'جزئي',
        'paid': 'مدفوع',
        'returned': 'مرتجع'
    };
    statusBadge.textContent = statusText[invoiceData.status] || invoiceData.status;
    statusBadge.className = `badge bg-${invoiceData.status === 'paid' ? 'success' : invoiceData.status === 'partial' ? 'warning' : 'info'}`;
    
    document.getElementById('paidAmount').textContent = formatCurrency(invoiceData.paid_amount);
    document.getElementById('remainingAmount').textContent = formatCurrency(invoiceData.remaining_amount);
}

// عرض البنود
function displayItems() {
    const tbody = document.getElementById('itemsTableBody');
    tbody.innerHTML = '';
    
    adjustedItems.forEach((item, index) => {
        const row = document.createElement('tr');
        row.className = 'item-row';
        row.id = `item-row-${item.id}`;
        
        const currentQuantity = item.available_for_return;
        const currentDiscount =item.returned_quantity>0 ?item.discount_amount / item.quantity : item.discount_amount || 0;
        // حساب الإجمالي بناءً على الكمية المتبقية وسعر الوحدة بعد الخصم
        const unitPriceAfterDiscount = item.unit_price_after_discount || 0;
        const currentTotalAfter = currentQuantity * unitPriceAfterDiscount;
        const returnedQty = item.returned_quantity || 0;
        const isLocked = returnedQty > 0;
        
        if (isLocked) {
            row.classList.add('row-disabled');
        }
        
        row.innerHTML = `
            <td>
                <strong>${item.product_name}</strong>
                <span class="item-profit-indicator" data-item-id="${item.id}"></span>
                ${isLocked ? `
                    <span class="badge-returned-lock" title="لا يمكن تعديل بنود بها مرتجعات">
                        <i class="fas fa-lock"></i> غير قابل للتعديل (يوجد مرتجع)
                    </span>
                ` : ''}
            </td>
            <td class="text-center">
                ${currentQuantity.toFixed(2)}
                ${returnedQty > 0 ? `<br><small class="text-muted">مرتجع: ${returnedQty.toFixed(2)}</small>` : ''}
            </td>
            <td class="text-center">${formatCurrency(item.selling_price)}</td>
            <td class="text-center">${formatCurrency(item.returned_quantity > 0 ?item.total_before_discount/item.quantity * item.available_for_return :item.total_before_discount)}</td>
            <td class="text-center">${formatCurrency(currentDiscount)}</td>
            <td class="text-center">${formatCurrency(item.unit_price_after_discount || 0)}</td>
            <td class="text-center">
                <select 
                    class="form-select form-select-sm discount-type-select" 
                    data-item-id="${item.id}"
                    data-index="${index}"
                    ${isLocked ? 'disabled' : ''}
                    ${isLocked ? 'title="لا يمكن تعديل بند عليه مرتجعات"' : ''}
                >
                    <option value="">لا يوجد</option>
                    <option value="percent">نسبة %</option>
                    <option value="amount">مبلغ</option>
                </select>
            </td>
            <td class="text-center">
                <input 
                    type="number" 
                    class="form-control form-control-sm discount-value-input" 
                    data-item-id="${item.id}"
                    data-index="${index}"
                    step="0.01" 
                    min="0"
                    max="${currentTotalAfter}"
                    placeholder="0.00"
                    disabled
                    ${isLocked ? 'title="لا يمكن تعديل بند عليه مرتجعات!"' : `title="الحد الأقصى: ${formatCurrency(currentTotalAfter)}"`}
                >
            </td>
            <td class="text-center item-total-after">
                <strong>${formatCurrency(currentTotalAfter)}</strong>
            </td>
        `;
        
        tbody.appendChild(row);
        
        // تحديث لمبة الربح للبند
        updateItemProfitIndicator(row, item);
    });
    
    // إضافة Event Listeners
    attachEventListeners();
}

// إضافة Event Listeners
function attachEventListeners() {
    document.querySelectorAll('.discount-type-select').forEach(select => {
        select.addEventListener('change', function() {
            const itemId = parseInt(this.dataset.itemId);
            const index = parseInt(this.dataset.index);
            const input = document.querySelector(`.discount-value-input[data-item-id="${itemId}"]`);
            
            if (this.value) {
                input.disabled = false;
                input.focus();
            } else {
                input.disabled = true;
                input.value = '';
            }
            
            updateItemDiscount(itemId, index);
        });
    });
    
    document.querySelectorAll('.discount-value-input').forEach(input => {
        input.addEventListener('input', function() {
            const itemId = parseInt(this.dataset.itemId);
            const index = parseInt(this.dataset.index);
            updateItemDiscount(itemId, index);
        });
    });
}

// تحديث خصم البند
function updateItemDiscount(itemId, index) {
    const item = adjustedItems[index];
    if (!item || item.id !== itemId) return;
    
    const select = document.querySelector(`.discount-type-select[data-item-id="${itemId}"]`);
    const input = document.querySelector(`.discount-value-input[data-item-id="${itemId}"]`);
    
    if (!select || !input) return;
    
    item.additional_discount_type = select.value || null;
    let discountValue = parseFloat(input.value) || 0;
    
    // حساب الحد الأقصى للخصم بناءً على الكمية المتبقية
    const availableQty = item.available_for_return || 0;
    const unitPriceAfterDiscount = item.unit_price_after_discount || 0;
    const maxDiscount = availableQty * unitPriceAfterDiscount;
    const availableQtySellingPrice = availableQty * (item.selling_price || 0);
    
    // حساب نسبة الخصم الإجمالية (القديم + الجديد)
    const currentDiscount = item.discount_amount || 0;
    let currentDiscountPercent = 0;
    if (availableQtySellingPrice > 0) {
        currentDiscountPercent = (currentDiscount / availableQtySellingPrice) * 100;
    }
    
    // الحد الأقصى للخصم هو 90% من الإجمالي قبل الخصم
    const maxDiscountPercent = 90;
    const maxDiscountAmount = availableQtySellingPrice * (maxDiscountPercent / 100);
    
    if (item.additional_discount_type === 'amount') {
        // التحقق من عدم تجاوز الحد الأقصى للخصم (90%)
        const totalDiscountAfter = currentDiscount + discountValue;
        if (totalDiscountAfter > maxDiscountAmount) {
            discountValue = Math.max(0, maxDiscountAmount - currentDiscount);
            input.value = discountValue.toFixed(2);
            showItemWarning(itemId, `الحد الأقصى للخصم: ${maxDiscountPercent}% (${formatCurrency(maxDiscountAmount)})`);
        } else if (discountValue > maxDiscount) {
            discountValue = maxDiscount;
            input.value = discountValue.toFixed(2);
            showItemWarning(itemId, `الحد الأقصى للخصم: ${formatCurrency(maxDiscount)}`);
        }
    } else if (item.additional_discount_type === 'percent') {
        // التحقق من عدم تجاوز الحد الأقصى للخصم (90%)
        const totalDiscountPercent = currentDiscountPercent + discountValue;
        if (totalDiscountPercent > maxDiscountPercent) {
            discountValue = Math.max(0, maxDiscountPercent - currentDiscountPercent);
            input.value = discountValue.toFixed(2);
            showItemWarning(itemId, `الحد الأقصى للخصم: ${maxDiscountPercent}%`);
        } else {
            const maxPercent = availableQtySellingPrice > 0 ? (maxDiscount / availableQtySellingPrice) * 100 : 0;
            if (discountValue > maxPercent) {
                discountValue = maxPercent;
                input.value = discountValue.toFixed(2);
                showItemWarning(itemId, `الحد الأقصى للنسبة: ${maxPercent.toFixed(2)}%`);
            }
        }
    }
    
    item.additional_discount_value = discountValue;
    
    // حساب الإجمالي الجديد
    calculateItemTotal(item, index);
    calculateTotals();
    
    // تحديث زر الحفظ
    const reason = document.getElementById('adjustmentReason').value.trim();
    const hasDiscount = adjustedItems.some(i => i.additional_discount_value > 0);
    document.getElementById('saveAdjustmentBtn').disabled = !reason || !hasDiscount;
}

// حساب إجمالي البند
function calculateItemTotal(item, index) {
    const availableQty = item.available_for_return || 0;
    const sellingPrice = item.selling_price || 0;
    const unitPriceAfterDiscount = item.unit_price_after_discount || 0;
    
    // حساب الإجمالي قبل الخصم للكمية المتبقية فقط
    const itemTotalBefore = availableQty * sellingPrice;
    // حساب الإجمالي الحالي بعد الخصم للكمية المتبقية فقط
    const currentTotalAfter = availableQty * unitPriceAfterDiscount;
    
    let additionalDiscount = 0;
    
    if (item.additional_discount_type === 'percent' && item.additional_discount_value > 0) {
        additionalDiscount = itemTotalBefore * (item.additional_discount_value / 100);
    } else if (item.additional_discount_type === 'amount' && item.additional_discount_value > 0) {
        additionalDiscount = item.additional_discount_value;
    }
    
    // حساب نسبة الخصم الإجمالية (القديم + الجديد)
    const currentDiscount = item.discount_amount || 0;
    const totalDiscount = currentDiscount + additionalDiscount;
    const maxDiscountPercent = 90; // الحد الأقصى للخصم 90%
    const maxDiscountAmount = itemTotalBefore * (maxDiscountPercent / 100);
    
    // التأكد من عدم تجاوز 90% من الإجمالي قبل الخصم
    if (totalDiscount > maxDiscountAmount) {
        additionalDiscount = Math.max(0, maxDiscountAmount - currentDiscount);
        // تحديث القيمة المدخلة
        if (item.additional_discount_type === 'percent') {
            item.additional_discount_value = itemTotalBefore > 0 ? (additionalDiscount / itemTotalBefore) * 100 : 0;
        } else {
            item.additional_discount_value = additionalDiscount;
        }
        // تحديث input
        const input = document.querySelector(`.discount-value-input[data-item-id="${item.id}"]`);
        if (input) {
            input.value = item.additional_discount_value.toFixed(2);
            showItemWarning(item.id, `الحد الأقصى للخصم: ${maxDiscountPercent}%`);
        }
    }
    
    // التأكد من عدم تجاوز total_after_discount (الحد الأقصى للخصم)
    const maxAvailableDiscount = currentTotalAfter; // الحد الأقصى هو total_after_discount الحالي
    
    if (additionalDiscount > maxAvailableDiscount) {
        additionalDiscount = maxAvailableDiscount;
        // تحديث القيمة المدخلة
        if (item.additional_discount_type === 'percent') {
            item.additional_discount_value = itemTotalBefore > 0 ? (additionalDiscount / itemTotalBefore) * 100 : 0;
        } else {
            item.additional_discount_value = additionalDiscount;
        }
        
        // تحديث input
        const input = document.querySelector(`.discount-value-input[data-item-id="${item.id}"]`);
        if (input) {
            input.value = item.additional_discount_value.toFixed(2);
            showItemWarning(item.id, `الحد الأقصى للخصم: ${formatCurrency(maxAvailableDiscount)}`);
        }
    }
    
    // حساب الخصم الحالي للكمية المتبقية
    // const currentDiscount = itemTotalBefore - currentTotalAfter;
    
    // حساب الإجمالي الجديد بعد الخصم الإضافي
    const newTotalAfter = currentTotalAfter - additionalDiscount;
    item.new_total_after_discount = Math.max(0, newTotalAfter);
    
    // تحديث العرض
    const row = document.getElementById(`item-row-${item.id}`);
    if (row) {
        const totalCell = row.querySelector('.item-total-after');
        if (totalCell) {
            totalCell.innerHTML = `<strong>${formatCurrency(item.new_total_after_discount)}</strong>`;
        }
        
        // تحديث لمبة الربح للبند
        updateItemProfitIndicator(row, item);
        
        // تمييز الصف المعدل
        if (additionalDiscount > 0) {
            row.classList.add('changed');
        } else {
            row.classList.remove('changed');
        }
    }
}

// حساب الإجماليات
function calculateTotals() {
    // حساب الإجمالي قبل الخصم بناءً على الكمية المتبقية فقط (بدون المرتجعات)
    let totalBeforeDiscount = 0;
    let totalReturns = 0; // إجمالي المرتجعات (للعرض فقط)
    let totalOldDiscounts = 0; // الخصومات القديمة للكمية المتبقية فقط
    let totalAdditionalDiscount = 0; // الخصم الإضافي الجديد
    let totalAfterDiscount = 0; // الصافي الحالي (قبل التعديل) للكمية المتبقية فقط
    let finalTotal = 0; // المطلوب النهائي (بعد التعديل) للكمية المتبقية فقط
    let totalCost = 0; // التكلفة الإجمالية للكمية المتبقية فقط
    console.log(invoiceData);
    
    // حساب إجمالي المرتجعات (من API) - للعرض فقط
    totalReturns = invoiceData.total_returns || 0;
    
    // حساب الخصومات الإضافية والإجماليات الجديدة بناءً على الكمية المتبقية فقط
    adjustedItems.forEach((item, index) => {
        const availableQty = item.available_for_return || 0;
        const sellingPrice = item.selling_price || 0;
        const unitPriceAfterDiscount = item.unit_price_after_discount || 0;
        const costPrice = item.cost_price_per_unit || 0;
        
        // حساب الإجمالي بناءً على الكمية المتبقية وسعر الوحدة بعد الخصم
        const currentTotalAfter = availableQty * unitPriceAfterDiscount;
        // حساب الإجمالي قبل الخصم للكمية المتبقية فقط
        const itemTotalBefore = availableQty * sellingPrice;
        // حساب التكلفة للكمية المتبقية فقط
        const itemCost = availableQty * costPrice;
        
        // إضافة للإجماليات
        totalBeforeDiscount = invoiceData.total_before_discount || 0;
        totalCost += itemCost;
        
        // حساب الخصم القديم للكمية المتبقية فقط
        const currentItemDiscount = item.discount_amount || 0;
        // نحسب الخصم القديم بناءً على الفرق بين الإجمالي قبل وبعد الخصم للكمية المتبقية
        const oldDiscountForRemaining = itemTotalBefore - currentTotalAfter;
        totalOldDiscounts += oldDiscountForRemaining;
        
        let additionalDiscount = 0;
        
        // حساب الخصم الإضافي
        if (item.additional_discount_type === 'percent' && item.additional_discount_value > 0) {
            additionalDiscount = itemTotalBefore * (item.additional_discount_value / 100);
        } else if (item.additional_discount_type === 'amount' && item.additional_discount_value > 0) {
            additionalDiscount = item.additional_discount_value;
        }
        
        // حساب نسبة الخصم الإجمالية (القديم + الجديد) للكمية المتبقية فقط
        const totalItemDiscount = oldDiscountForRemaining + additionalDiscount;
        const maxDiscountPercent = 90; // الحد الأقصى للخصم 90%
        const maxDiscountAmount = itemTotalBefore * (maxDiscountPercent / 100);
        
        // التأكد من عدم تجاوز 90% من الإجمالي قبل الخصم
        if (totalItemDiscount > maxDiscountAmount) {
            additionalDiscount = Math.max(0, maxDiscountAmount - oldDiscountForRemaining);
            // تحديث القيمة المدخلة لتعكس الحد الأقصى
            if (item.additional_discount_type === 'percent') {
                item.additional_discount_value = itemTotalBefore > 0 ? (additionalDiscount / itemTotalBefore) * 100 : 0;
            } else {
                item.additional_discount_value = additionalDiscount;
            }
            
            // تحديث input field
            const input = document.querySelector(`.discount-value-input[data-item-id="${item.id}"]`);
            if (input) {
                input.value = item.additional_discount_value.toFixed(2);
            }
            
            // إظهار تحذير
            showItemWarning(item.id, `الحد الأقصى للخصم: ${maxDiscountPercent}%`);
        }
        // التأكد من عدم تجاوز total_after_discount للبند (الحد الأقصى)
        else {
            const maxAvailableForDiscount = currentTotalAfter; // الحد الأقصى هو total_after_discount الحالي
            if (additionalDiscount > maxAvailableForDiscount) {
                additionalDiscount = maxAvailableForDiscount;
                // تحديث القيمة المدخلة لتعكس الحد الأقصى
                if (item.additional_discount_type === 'percent') {
                    item.additional_discount_value = itemTotalBefore > 0 ? (maxAvailableForDiscount / itemTotalBefore) * 100 : 0;
                } else {
                    item.additional_discount_value = maxAvailableForDiscount;
                }
                
                // تحديث input field
                const input = document.querySelector(`.discount-value-input[data-item-id="${item.id}"]`);
                if (input) {
                    input.value = item.additional_discount_value.toFixed(2);
                }
                
                // إظهار تحذير
                showItemWarning(item.id, `الحد الأقصى للخصم: ${formatCurrency(maxAvailableForDiscount)}`);
            }
        }
        
    
        const newItemTotalAfter = Math.max(0, currentTotalAfter - additionalDiscount);
        item.new_total_after_discount = newItemTotalAfter;
        
        totalAdditionalDiscount += additionalDiscount;
        totalAfterDiscount += currentTotalAfter; // الصافي القديم للكمية المتبقية فقط
        finalTotal += newItemTotalAfter;
        
        // تحديث عرض البند
        updateItemDisplay(item, index);
    });
    
    // حساب الربح بناءً على الكمية المتبقية فقط
    const newProfit = finalTotal - totalCost;
    // حساب الربح القديم للكمية المتبقية فقط
    const oldProfit = totalAfterDiscount - totalCost;
    const profitChange = newProfit - oldProfit;
    
    // حساب الفرق بناءً على الكمية المتبقية فقط
    const difference = finalTotal - totalAfterDiscount;
    
    // تحديث العرض (جميع القيم بناءً على الكمية المتبقية فقط)
    document.getElementById('totalBefore').textContent = formatNumber(totalBeforeDiscount);
    document.getElementById('totalReturns').textContent = formatNumber(totalReturns);
    document.getElementById('totalDiscounts').textContent = formatNumber(totalOldDiscounts + totalAdditionalDiscount);
    document.getElementById('totalAfter').textContent = formatNumber(totalAfterDiscount);
    document.getElementById('totalAdditionalDiscount').textContent = formatNumber(totalAdditionalDiscount);
    document.getElementById('finalTotal').textContent = formatNumber(finalTotal);
    document.getElementById('totalProfit').textContent = formatNumber(newProfit);
    document.getElementById('totalDifference').textContent = formatNumber(Math.abs(difference));
    
    // تحديث لمبة الربح
    updateProfitIndicator(newProfit, totalCost, profitChange);
    
    // عرض/إخفاء قسم الإرجاع
    showRefundSection(difference, invoiceData.status);
    
    // تفعيل/تعطيل زر الحفظ
    const saveBtn = document.getElementById('saveAdjustmentBtn');
    saveBtn.disabled = (totalAdditionalDiscount === 0 || !document.getElementById('adjustmentReason').value.trim());
}

// تحديث لمبة الربح
function updateProfitIndicator(profit, cost, change) {
    const indicator = document.getElementById('profitStatusIndicator');
    const profitEl = document.getElementById('totalProfit');
    
    if (!indicator) {
        // إنشاء اللمبة إذا لم تكن موجودة
        const profitLabel = document.querySelector('#profitIndicator .summary-label');
        if (profitLabel) {
            const newIndicator = document.createElement('span');
            newIndicator.className = 'profit-status-indicator';
            newIndicator.id = 'profitStatusIndicator';
            profitLabel.insertBefore(newIndicator, profitLabel.firstChild);
        }
        return;
    }
    
    // إزالة الفئات القديمة
    indicator.classList.remove('profit-positive', 'profit-negative', 'profit-zero', 'warning-discount');
    profitEl.classList.remove('text-success', 'text-danger', 'text-warning');
    
    // حساب نسبة الخصم الإجمالية بناءً على الكمية المتبقية فقط
    let totalBeforeDiscount = 0;
    let totalOldDiscounts = 0;
    let totalAdditionalDiscount = 0;
    
    adjustedItems.forEach((item) => {
        const availableQty = item.available_for_return || 0;
        const sellingPrice = item.selling_price || 0;
        const unitPriceAfterDiscount = item.unit_price_after_discount || 0;
        const itemTotalBefore = availableQty * sellingPrice;
        const currentTotalAfter = availableQty * unitPriceAfterDiscount;
        
        totalBeforeDiscount += itemTotalBefore;
        // حساب الخصم القديم للكمية المتبقية فقط
        const oldDiscountForRemaining = itemTotalBefore - currentTotalAfter;
        totalOldDiscounts += oldDiscountForRemaining;
        
        let additionalDiscount = 0;
        if (item.additional_discount_type === 'percent' && item.additional_discount_value > 0) {
            additionalDiscount = itemTotalBefore * (item.additional_discount_value / 100);
        } else if (item.additional_discount_type === 'amount' && item.additional_discount_value > 0) {
            additionalDiscount = item.additional_discount_value;
        }
        totalAdditionalDiscount += additionalDiscount;
    });
    
    const totalDiscount = totalOldDiscounts + totalAdditionalDiscount;
    const discountPercentage = totalBeforeDiscount > 0 ? (totalDiscount / totalBeforeDiscount) * 100 : 0;
    
    // تحديد حالة الربح حسب الأولوية:
    // 1. أحمر إذا كان هناك خسارة أو يساوي التكلفة
    if (profit <= 0) {
        indicator.classList.add('profit-negative');
        profitEl.classList.add('text-danger');
        indicator.title = `التكلفة: ${formatCurrency(cost)}\nالخسارة: ${formatCurrency(Math.abs(profit))}\nالتغيير: ${formatCurrency(change)}\nالخصم: ${discountPercentage.toFixed(1)}%`;
    }
    // 2. أصفر إذا كان الخصم يتخطى 50% وليس هناك خسارة
    else if (discountPercentage > 50) {
        indicator.classList.add('warning-discount');
        profitEl.classList.add('text-warning');
        indicator.title = `التكلفة: ${formatCurrency(cost)}\nالربح: ${formatCurrency(profit)}\nالتغيير: ${formatCurrency(change)}\n⚠️ الخصم: ${discountPercentage.toFixed(1)}%`;
    }
    // 3. أخضر للربح العادي
    else {
        indicator.classList.add('profit-positive');
        profitEl.classList.add('text-success');
        indicator.title = `التكلفة: ${formatCurrency(cost)}\nالربح: ${formatCurrency(profit)}\nالتغيير: ${formatCurrency(change)}\nالخصم: ${discountPercentage.toFixed(1)}%`;
    }
}

// تحديث عرض البند
function updateItemDisplay(item, index) {
    const row = document.getElementById(`item-row-${item.id}`);
    if (!row) return;
    
    const totalCell = row.querySelector('.item-total-after');
    if (totalCell) {
        totalCell.innerHTML = `<strong>${formatCurrency(item.new_total_after_discount || item.total_after_discount)}</strong>`;
    }
    
    // تحديث لمبة الربح للبند
    updateItemProfitIndicator(row, item);
    
    // تمييز الصف المعدل
    if (item.additional_discount_value > 0) {
        row.classList.add('changed');
    } else {
        row.classList.remove('changed');
    }
}

// تحديث لمبة الربح للبند
function updateItemProfitIndicator(row, item) {
    const sellingPrice = item.selling_price || 0;
    const costPrice = item.cost_price_per_unit || 0;
    const availableQty = item.available_for_return || 0;
    const itemTotalAfter = item.new_total_after_discount || item.total_after_discount;
    
    const itemCost = availableQty * costPrice;
    const itemProfit = itemTotalAfter - itemCost;
    const profitMargin = itemTotalAfter > 0 ? (itemProfit / itemTotalAfter) * 100 : 0;
    
    // البحث عن لمبة الربح الحالية أو إنشاؤها
    let indicator = row.querySelector('.item-profit-indicator');
    if (!indicator) {
        indicator = document.createElement('span');
        indicator.className = 'item-profit-indicator';
        const productCell = row.querySelector('td:first-child');
        if (productCell) {
            productCell.appendChild(indicator);
        }
    }
    
    // إزالة الفئات القديمة
    indicator.classList.remove('profit-positive', 'profit-negative', 'profit-zero', 'warning-discount');
    
    // حساب نسبة الخصم
    const itemTotalBefore = availableQty * sellingPrice;
    const currentDiscount = item.returned_quantity>0 ?item.discount_amount / item.quantity : item.discount_amount || 0;
    const additionalDiscount = item.additional_discount_value > 0 ? 
        (item.additional_discount_type === 'percent' ? 
            itemTotalBefore * (item.additional_discount_value / 100) : 
            item.additional_discount_value) : 0;
    const totalDiscount = currentDiscount + additionalDiscount;
    const discountPercentage = itemTotalBefore > 0 ? (totalDiscount / itemTotalBefore) * 100 : 0;
    
    // تحديد حالة الربح حسب الأولوية:
    // 1. أحمر إذا كان هناك خسارة أو يساوي التكلفة
    if (itemProfit <= 0) {
        indicator.classList.add('profit-negative');
        if (itemProfit < 0) {
            indicator.title = `التكلفة: ${formatCurrency(itemCost)}\nالخسارة: ${formatCurrency(Math.abs(itemProfit))}\nالخصم: ${discountPercentage.toFixed(1)}%`;
        } else {
            indicator.title = `التكلفة: ${formatCurrency(itemCost)}\nالربح: صفر (يساوي التكلفة)\nالخصم: ${discountPercentage.toFixed(1)}%`;
        }
    }
    // 2. أصفر إذا كان الخصم يتخطى 50% وليس هناك خسارة
    else if (discountPercentage > 50) {
        indicator.classList.add('warning-discount');
        indicator.title = `التكلفة: ${formatCurrency(itemCost)}\nالربح: ${formatCurrency(itemProfit)} (${profitMargin.toFixed(1)}%)\n⚠️ الخصم: ${discountPercentage.toFixed(1)}%`;
    }
    // 3. أخضر للربح العادي
    else {
        indicator.classList.add('profit-positive');
        indicator.title = `التكلفة: ${formatCurrency(itemCost)}\nالربح: ${formatCurrency(itemProfit)} (${profitMargin.toFixed(1)}%)\nالخصم: ${discountPercentage.toFixed(1)}%`;
    }
}

// إظهار تحذير للبند
function showItemWarning(itemId, message) {
    const row = document.getElementById(`item-row-${itemId}`);
    if (!row) return;
    
    // إزالة التحذير القديم
    const oldAlert = row.querySelector('.item-warning-alert');
    if (oldAlert) oldAlert.remove();
    
    // إضافة تحذير جديد
    const alert = document.createElement('div');
    alert.className = 'item-warning-alert alert alert-warning alert-sm mt-1';
    alert.innerHTML = `<i class="fas fa-exclamation-triangle"></i> ${message}`;
    
    const lastCell = row.querySelector('td:last-child');
    if (lastCell) {
        lastCell.appendChild(alert);
    }
}

// تنسيق الرقم بدون عملة
function formatNumber(amount) {
    return parseFloat(amount || 0).toFixed(2);
}

// عرض قسم الإرجاع
function showRefundSection(difference, invoiceStatus) {
    const refundSection = document.getElementById('refundSection');
    const refundMessage = document.getElementById('refundMessage');
    const refundMethodSelection = document.getElementById('refundMethodSelection');
    
    if (difference >= 0) {
        refundSection.style.display = 'none';
        return;
    }
    
    refundSection.style.display = 'block';
    const refundAmount = Math.abs(difference);
    
    if (invoiceStatus === 'paid') {
        refundMessage.innerHTML = `
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i> 
                يجب إرجاع <strong>${formatCurrency(refundAmount)}</strong> للعميل
                <br><small>سيتم تقليل المبلغ المدفوع تلقائياً</small>
            </div>
        `;
        refundMethodSelection.style.display = 'block';
        // تفعيل خيارات الإرجاع فقط (نقدي/محفظة)
        document.getElementById('refundCash').disabled = false;
        document.getElementById('refundCash').checked = true;
        document.getElementById('refundWallet').disabled = false;
        document.getElementById('refundBalance').disabled = true;
        document.getElementById('refundBalance').checked = false;
        
    } else if (invoiceStatus === 'partial') {
        const oldRemaining = invoiceData.remaining_amount;
        const discountAmount = Math.abs(difference); // مقدار الخصم
        
        // إذا كان الخصم أكبر من المتبقي، يجب إرجاع الفرق
        if (discountAmount > oldRemaining && invoiceData.paid_amount > 0) {
            // المبلغ الزائد الذي يجب إرجاعه = الخصم - المتبقي القديم
            const refundNeeded = discountAmount - oldRemaining;
            refundMessage.innerHTML = `
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle"></i> 
                    الخصم (<strong>${formatCurrency(discountAmount)}</strong>) أكبر من المتبقي (<strong>${formatCurrency(oldRemaining)}</strong>)
                    <br>سيتم تقليل المتبقي إلى صفر
                    <br>يجب إرجاع <strong>${formatCurrency(refundNeeded)}</strong> للعميل
                </div>
            `;
            refundMethodSelection.style.display = 'block';
            document.getElementById('refundCash').disabled = false;
            document.getElementById('refundCash').checked = true;
            document.getElementById('refundWallet').disabled = false;
            document.getElementById('refundBalance').disabled = true;
            document.getElementById('refundBalance').checked = false;
        } else {
            // الخصم أقل من أو يساوي المتبقي، فقط تقليل المتبقي
            refundMessage.innerHTML = `
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> 
                    سيتم تقليل المتبقي فقط (من <strong>${formatCurrency(oldRemaining)}</strong> إلى <strong>${formatCurrency(Math.max(0, oldRemaining - discountAmount))}</strong>)
                </div>
            `;
            refundMethodSelection.style.display = 'none';
        }
        
    } else {
        // pending - فاتورة مؤجلة
        refundMessage.innerHTML = `
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> 
                سيتم تقليل الرصيد المتبقي على العميل
            </div>
        `;
        refundMethodSelection.style.display = 'none';
    }
}

// حفظ التعديل
async function saveAdjustment() {
    const reason = document.getElementById('adjustmentReason').value.trim();
    
    if (!reason) {
        Swal.fire('تنبيه', 'يرجى كتابة سبب التعديل', 'warning');
        return;
    }
    
    // التحقق من وجود تعديلات
    const hasChanges = adjustedItems.some(item => item.additional_discount_value > 0);
    if (!hasChanges) {
        Swal.fire('تنبيه', 'لم تقم بإدخال أي خصم إضافي', 'warning');
        return;
    }
    
    // التحقق من طريقة الإرجاع
    const refundMethod = document.querySelector('input[name="refundMethod"]:checked')?.value || 'balance_reduction';
    
    // إظهار Loader
    document.getElementById('loaderOverlay').style.display = 'flex';
    
    try {
        const formData = new FormData();
        formData.append('invoice_id', invoiceId);
        formData.append('items', JSON.stringify(adjustedItems));
        formData.append('reason', reason);
        formData.append('refund_method', refundMethod);
        formData.append('csrf_token', csrfToken);
        
        const response = await fetch('<?php echo BASE_URL; ?>api/apply_additional_discount.php', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        document.getElementById('loaderOverlay').style.display = 'none';
        
        if (result.success) {
            await Swal.fire({
                icon: 'success',
                title: 'تم بنجاح',
                text: result.message || 'تم تطبيق التعديل بنجاح',
                timer: 2000,
                showConfirmButton: false
            });
            
            // الرجوع لصفحة تفاصيل العميل
            // window.location.href = '<?php echo $back_url; ?>';
            
        } else {
            Swal.fire('خطأ', result.message || 'فشل في تطبيق التعديل', 'error');
        }
        
    } catch (error) {
        document.getElementById('loaderOverlay').style.display = 'none';
        console.error('Error:', error);
        Swal.fire('خطأ', 'حدث خطأ أثناء حفظ التعديل', 'error');
    }
}

// تنسيق العملة
function formatCurrency(amount) {
    return parseFloat(amount || 0).toFixed(2) + ' ج.م';
}

// Event Listeners
document.getElementById('saveAdjustmentBtn').addEventListener('click', saveAdjustment);
document.getElementById('adjustmentReason').addEventListener('input', function() {
    const saveBtn = document.getElementById('saveAdjustmentBtn');
    const hasDiscount = adjustedItems.some(item => item.additional_discount_value > 0);
    saveBtn.disabled = !this.value.trim() || !hasDiscount;
});

// تحميل البيانات عند فتح الصفحة
loadInvoiceData();
</script>

<?php
require_once BASE_DIR . 'partials/footer.php';
?>

