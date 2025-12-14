import AppData from "./app_data.js";
import CustomerManager from "./customer.js";
import PrintManager from "./print.js";
import PaymentManager from "./payment.js";
import apis from "./constant/api_links.js";


// work-order-manager.js
const WorkOrderManager = {
    currentCustomerId: null,
    async init() {
        let customerId =         this.getCustomerIdFromURL();

;
        if (!customerId) {
            console.error('Customer ID is required');
            return;
        }
        
        this.currentCustomerId = customerId;
        await this.fetchWorkOrders();
    },

    // جلب الشغلانات من الـ API وتخزينها في AppData
    async fetchWorkOrders() {
        try {
            // عرض حالة التحميل
            this.showLoading();
            
            const response = await fetch(
                `${apis.getCustomerWorkOrders}${encodeURIComponent(this.currentCustomerId)}`,
                {
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    cache: 'no-cache'
                }
            );
            
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            
            const data = await response.json();
            
            if (data.success) {
                // تخزين البيانات في AppData
                AppData.workOrders = data.work_orders.map(wo => ({
                    id: wo.id,
                    name: wo.title,
                    title: wo.title,
                    description: wo.description || '',
                    status: wo.status,
                    startDate: wo.start_date,
                    // نستخدم البيانات المالية من الـ API
                    total_invoice_amount: parseFloat(wo.total_invoice_amount) || 0,
                    total_paid: parseFloat(wo.total_paid) || 0,
                    total_remaining: parseFloat(wo.total_remaining) || 0,
                    progress_percent: wo.progress_percent || 0,
                    invoices_count: wo.invoices_count || 0,
                    customer_id: wo.customer_id,
                    customer_name: wo.customer_name,
                    created_at: wo.created_at
                }));
                
                // تحديث الجدول
                this.updateWorkOrdersTable();
                
                console.log('✅ تم تحميل الشغلانات بنجاح:', AppData.workOrders.length);
            } else {
                throw new Error(data.message || 'فشل في تحميل البيانات');
            }
        } catch (error) {
            console.error('❌ خطأ في جلب الشغلانات:', error);
            this.showError('خطأ', 'فشل في تحميل الشغلانات');
        } finally {
            this.hideLoading();
        }
    },

    
  getCustomerIdFromURL() {
        // طريقة 1: من query string
        const urlParams = new URLSearchParams(window.location.search);
        const id = urlParams.get('customer_id') || urlParams.get('id');
        
        // طريقة 2: من data attribute
        if (!id) {
            const dataId = document.body.getAttribute('data-customer-id');
            if (dataId) return dataId;
        }
        
        // طريقة 3: من متغير global
        if (!id && window.customerId) {
            return window.customerId;
        }
        
        return id;
    },
    // إنشاء شغلانة جديدة
    async createWorkOrder(workOrderData) {
        try {
            const response = await fetch(apis.createWorkOrder, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify(workOrderData)
            });
            
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            
            const data = await response.json();
            
            if (data.success) {
                // إضافة الشغلانة الجديدة إلى AppData
                const newWorkOrder = {
                    id: data.work_order.id,
                    name: data.work_order.title,
                    title: data.work_order.title,
                    description: data.work_order.description || '',
                    status: data.work_order.status,
                    startDate: data.work_order.start_date,
                    total_invoice_amount: parseFloat(data.work_order.total_invoice_amount) || 0,
                    total_paid: parseFloat(data.work_order.total_paid) || 0,
                    total_remaining: parseFloat(data.work_order.total_remaining) || 0,
                    progress_percent: data.work_order.total_invoice_amount > 0 ? 
                        Math.round((data.work_order.total_paid / data.work_order.total_invoice_amount) * 100, 2) : 0,
                    invoices_count: 0,
                    customer_id: data.work_order.customer_id,
                    customer_name: data.work_order.customer_name,
                    created_at: data.work_order.created_at
                };
                
                AppData.workOrders.unshift(newWorkOrder);
                
                // تحديث الجدول
                this.updateWorkOrdersTable();
                
                return {
                    success: true,
                    message: data.message,
                    workOrder: newWorkOrder
                };
            } else {
                throw new Error(data.message || 'فشل في إنشاء الشغلانة');
            }
        } catch (error) {
            console.error('❌ خطأ في إنشاء الشغلانة:', error);
            return {
                success: false,
                message: error.message
            };
        }
    },

    // جلب تفاصيل شغلانة محددة
    async fetchWorkOrderDetails(workOrderId) {
        try {
            const response = await fetch(
                `${apis.getWorkOrderDetails}${encodeURIComponent(workOrderId)}`,
                {
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    cache: 'no-cache'
                }
            );
            
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            
            const data = await response.json();
            
            if (data.success) {
                return {
                    success: true,
                    workOrder: data.work_order,
                    invoices: data.work_order.invoices || []
                };
            } else {
                throw new Error(data.message || 'فشل في جلب التفاصيل');
            }
        } catch (error) {
            console.error('❌ خطأ في جلب تفاصيل الشغلانة:', error);
            return {
                success: false,
                message: error.message
            };
        }
    },

    // تحديث جدول الشغلانات
    updateWorkOrdersTable() {
        const container = document.getElementById("workOrdersContainer");
        if (!container) {
            console.error('❌ عنصر workOrdersContainer غير موجود');
            return;
        }
        
        container.innerHTML = "";

        if (AppData.workOrders.length === 0) {
            container.innerHTML = `
                <div class="col-12">
                    <div class="alert alert-info text-center">
                        لا توجد شغلانات لعرضها
                    </div>
                </div>
            `;
            return;
        }

        AppData.workOrders.forEach((workOrder) => {
            const workOrderCard = document.createElement("div");
            workOrderCard.className = "col-md-6 mb-3";

            // حساب القيم من البيانات المخزنة
            const totalInvoices = workOrder.total_invoice_amount || 0;
            const totalPaid = workOrder.total_paid || 0;
            const totalRemaining = workOrder.total_remaining || 0;
            const progressPercent = workOrder.progress_percent || 0;

            // تحديد حالة الشغلانة
            let statusBadge = "";
            let statusText = "";
            
            if (workOrder.status === "pending") {
                statusBadge = "badge-pending";
                statusText = "قيد التنفيذ";
            } else if (workOrder.status === "in_progress") {
                statusBadge = "badge-partial";
                statusText = "جاري العمل";
            } else if (workOrder.status === "completed") {
                statusBadge = "badge-paid";
                statusText = "مكتمل";
            } else if (workOrder.status === "cancelled") {
                statusBadge = "badge-danger";
                statusText = "ملغي";
            }

         workOrderCard.innerHTML = `
<div class="work-order-card card h-100">
    <div class="card-body">

        <!-- عنوان الحالة -->
        <div class="d-flex justify-content-between align-items-start mb-2">
            <h5 class="card-title mb-0">${workOrder.title}</h5>
            <span class="status-badge ${statusBadge}">${statusText}</span>
        </div>

        <!-- الوصف -->
        <p class="card-text text-muted mb-3">${workOrder.description || 'لا يوجد وصف'}</p>

        <!-- معلومات أساسية -->
        <div class="row mb-3">
            <div class="col-6">
                <small>تاريخ البدء:</small>
                <div class="text-muted">${workOrder.startDate}</div>
            </div>
            <div class="col-6">
                <small>الفواتير:</small>
                <div class="text-muted">${workOrder.invoices_count || 0} فاتورة</div>
            </div>
        </div>

        <!-- شريط التقدم -->
        <div class="work-order-progress bg-light mb-3 rounded" style="height: 10px;">
            <div class="progress-bar bg-success rounded" style="width: ${progressPercent}%"></div>
        </div>

        <!-- المبالغ -->
        <div class="row text-center mb-3">
            <div class="col-4">
                <small>المطلوب</small>
                <div class="fw-bold">${totalInvoices.toFixed(2)} ج.م</div>
            </div>
            <div class="col-4">
                <small>المدفوع</small>
                <div class="fw-bold text-success">${totalPaid.toFixed(2)} ج.م</div>
            </div>
            <div class="col-4">
                <small>المتبقي</small>
                <div class="fw-bold text-danger">${totalRemaining.toFixed(2)} ج.م</div>
            </div>
        </div>

        <!-- أزرار الإجراء -->
        <div class="action-buttons d-flex gap-2 mt-3">
            <button class="btn btn-sm btn-outline-info view-work-order" data-work-order-id="${workOrder.id}">
                <i class="fas fa-eye"></i> عرض
            </button>
            ${totalRemaining > 0 ? `
            <button class="btn btn-sm btn-outline-success pay-work-order" data-work-order-id="${workOrder.id}">
                <i class="fas fa-money-bill-wave"></i> سداد
            </button>
            ` : ''}
            <button class="btn btn-sm btn-outline-primary print-work-order" data-work-order-id="${workOrder.id}">
                <i class="fas fa-print"></i> طباعة
            </button>
        </div>

    </div>
</div>
`;

            
            container.appendChild(workOrderCard);
        });

        // إضافة مستمعي الأحداث
        this.attachWorkOrderEventListeners();
    },

    // إضافة مستمعي الأحداث (نفس الكود مع تعديلات طفيفة)
    attachWorkOrderEventListeners() {
        // زر عرض الشغلانة
        document.querySelectorAll(".view-work-order").forEach((btn) => {
            btn.addEventListener("click", async function () {
                const workOrderId = parseInt(this.getAttribute("data-work-order-id"));
                await WorkOrderManager.showWorkOrderDetails(workOrderId);
            });
        });

        // زر سداد الشغلانة
        document.querySelectorAll(".pay-work-order").forEach((btn) => {
            btn.addEventListener("click", function () {
                const workOrderId = parseInt(this.getAttribute("data-work-order-id"));

                // تعيين نوع السداد إلى شغلانة
                document.getElementById("payWorkOrderRadio").checked = true;
                document.getElementById("invoicesPaymentSection").style.display = "none";
                document.getElementById("workOrderPaymentSection").style.display = "block";

                // تحديد الشغلانة
                PaymentManager.selectWorkOrderForPayment(workOrderId);
                document.getElementById("workOrderSearch").value = "";

                // فتح المودال
                const paymentModal = new bootstrap.Modal(
                    document.getElementById("paymentModal")
                );
                paymentModal.show();
            });
        });

        // زر طباعة الشغلانة
        document.querySelectorAll(".print-work-order").forEach((btn) => {
            btn.addEventListener("click", async function (e) {
                e.preventDefault();
                e.stopPropagation();
                const workOrderId = parseInt(this.getAttribute("data-work-order-id"));
                // نستخدم الـ API للحصول على البيانات قبل الطباعة
                const result = await WorkOrderManager.fetchWorkOrderDetails(workOrderId);
                if (result.success) {
                    PrintManager.printWorkOrderInvoices(workOrderId, result.invoices);
                }
            });
        });
    },

    // عرض تفاصيل الشغلانة (محدث لاستخدام الـ API)
    async showWorkOrderDetails(workOrderId) {
        try {
            //  this.showLoading('جاري تحميل التفاصيل...');
            
            const result = await this.fetchWorkOrderDetails(workOrderId);
            
            if (result.success) {
                const workOrder = result.workOrder;
                const invoices = result.invoices;
                
                // تحديث البيانات في المودال
                document.getElementById("workOrderInvoicesName").textContent = workOrder.title;
                document.getElementById("workOrderTotalInvoices").textContent = 
                    AppData.formatCurrency(workOrder.total_invoice_amount);
                document.getElementById("workOrderTotalPaid").textContent = 
                    AppData.formatCurrency(workOrder.total_paid);
                document.getElementById("workOrderTotalRemaining").textContent = 
                    AppData.formatCurrency(workOrder.total_remaining);

                // ملء جدول الفواتير
                const tbody = document.getElementById("workOrderInvoicesList");
                tbody.innerHTML = "";

                invoices.forEach((invoice) => {
                    const row = document.createElement("tr");
                    const statusInfo = AppData.getInvoiceStatusText(invoice.status);
                    
                    // إنشاء tooltip للبنود
                    let itemsTooltip = "";
                    if (invoice.items && invoice.items.length > 0) {
                        const itemsList = invoice.items.map((item) => {
                            const itemTotal = (item.quantity || 0) * (item.price || 0);
                            return `
                                <div class="tooltip-item">
                                    <div>
                                        <div class="tooltip-item-name">${item.product_name || 'منتج'}</div>
                                        <div class="tooltip-item-details">الكمية: ${item.quantity || 0} | السعر: ${(item.price || 0).toFixed(2)} ج.م</div>
                                    </div>
                                    <div class="fw-bold">${itemTotal.toFixed(2)} ج.م</div>
                                </div>
                            `;
                        }).join("");

                        itemsTooltip = `
                            <div class="invoice-items-tooltip tooltip-item" >
                                <div class="tooltip-header" style="font-weight: bold; border-bottom: 1px solid #dee2e6; padding-bottom: 5px; margin-bottom: 10px;">بنود الفاتورة ${invoice.invoice_number}</div>
                                ${itemsList}
                                <div class="tooltip-total" style="display: flex; justify-content: space-between; font-weight: bold; border-top: 1px solid #dee2e6; padding-top: 10px; margin-top: 10px;">
                                    <span>الإجمالي:</span>
                                    <span>${invoice.total.toFixed(2)} ج.م</span>
                                </div>
                            </div>
                        `;
                    }

                    // تحديد لون المبلغ المتبقي
                    let remainingColor = "text-danger";
                    if (invoice.remaining === 0) {
                        remainingColor = "text-success";
                    } else if (invoice.status === "partial") {
                        remainingColor = "text-warning";
                    }

                    row.innerHTML = `
                        <td class="position-relative" style="position: relative;">
                            <div class="invoice-item-hover" style="position: relative; display: inline-block; cursor: pointer;">
                                ${invoice.invoice_number}
                                <br><small class="text-muted">(مرر للعرض)</small>
                                ${itemsTooltip}
                            </div>
                        </td>
                        <td>${invoice.date}</td>
                        <td>${invoice.total.toFixed(2)} ج.م</td>
                        <td>${invoice.paid.toFixed(2)} ج.م</td>
                        <td><span class="${remainingColor} fw-bold">${invoice.remaining.toFixed(2)} ج.م</span></td>
                        <td><span class="status-badge ${statusInfo.class}">${statusInfo.text}</span></td>
                        <td>
                            <div class="action-buttons">
                                <button class="btn btn-sm btn-outline-info view-work-order-invoice" data-invoice-id="${invoice.id}">
                                    <i class="fas fa-eye"></i>
                                </button>
                                ${invoice.status !== "paid" ? `
                                <button class="btn btn-sm btn-outline-success pay-work-order-invoice" data-invoice-id="${invoice.id}">
                                    <i class="fas fa-money-bill-wave"></i>
                                </button>
                                ` : ''}
                                <button class="btn btn-sm btn-outline-secondary print-work-order-invoice" data-invoice-id="${invoice.id}">
                                    <i class="fas fa-print"></i>
                                </button>
                            </div>
                        </td>
                    `;
                    
                    tbody.appendChild(row);
                });

                // إضافة مستمعي الأحداث للأزرار داخل المودال
                this.attachWorkOrderModalEventListeners();

                // فتح المودال
                const modal = new bootstrap.Modal(
                    document.getElementById("workOrderInvoicesModal")
                );
                modal.show();
            } else {
                throw new Error(result.message);
            }
        } catch (error) {
            this.showError(`${error.message}`, 'فشل في تحميل التفاصيل ,');
        } finally {
            this.hideLoading();
        }
    },
// دوال التحكم داخل مودال عرض الشغلانة
attachWorkOrderModalEventListeners() {
    // عرض الفاتورة
    document.querySelectorAll(".view-work-order-invoice").forEach((btn) => {
        btn.addEventListener("click", function () {
            const invoiceId = parseInt(this.getAttribute("data-invoice-id"));
            window.location.href = `invoice_details.php?id=${invoiceId}`;
        });
    });

    // دفع الفاتورة
    document.querySelectorAll(".pay-work-order-invoice").forEach((btn) => {
        btn.addEventListener("click", function () {
            const invoiceId = parseInt(this.getAttribute("data-invoice-id"));

            // ضبط وضع الدفع لفاتورة
            document.getElementById("payInvoiceRadio").checked = true;
            document.getElementById("workOrderPaymentSection").style.display = "none";
            document.getElementById("invoicesPaymentSection").style.display = "block";

            PaymentManager.selectInvoiceForPayment(invoiceId);

            const modal = new bootstrap.Modal(
                document.getElementById("paymentModal")
            );
            modal.show();
        });
    });

    // طباعة الفاتورة
    document.querySelectorAll(".print-work-order-invoice").forEach((btn) => {
        btn.addEventListener("click", async function () {
            const invoiceId = parseInt(this.getAttribute("data-invoice-id"));

            // اجلب تفاصيل الفاتورة مباشرة من نفس API الشغلانة
            const result = await WorkOrderManager.fetchWorkOrderDetails(
                WorkOrderManager.currentWorkOrderId
            );

            if (result.success) {
                const invoice = result.invoices.find((inv) => inv.id === invoiceId);
                if (invoice) {
                    PrintManager.printInvoice(invoice);
                }
            }
        });
    });
}
,
    // الدوال المساعدة للـ UI
    showLoading(message = 'جاري التحميل...') {
        const container = document.getElementById("workOrdersContainer");
        if (container) {
            container.innerHTML = `
                <div class="col-12 text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-2">${message}</p>
                </div>
            `;
        }
    },

   hideLoading() {
    const container = document.getElementById("workOrdersContainer");

    // لو الـ container موجود ومحتواه عبارة عن شاشة تحميل → امسحه
   
}
,

    showError(title, message) {
        if (typeof Swal !== 'undefined') {
            Swal.fire({
                icon: 'error',
                title: title,
                text: message
            });
        } else {
            alert(`${title}: ${message}`);
        }
    },

    // دالة مساعدة لإعادة التهيئة بعد التحديث
    async refresh() {
        await this.fetchWorkOrders();
    },


    async  handleCreateWorkOrder() {
          const name = document.getElementById("workOrderName").value.trim();
          const description = document
            .getElementById("workOrderDescription")
            .value.trim();
          const startDate = document.getElementById("workOrderStartDate").value;
          const notes = document.getElementById("workOrderNotes")?.value;

          if (!name || !description || !startDate) {
            Swal.fire("تحذير", "يرجى ملء جميع الحقول المطلوبة", "warning");
            return;
          }
    const workOrderData = {
        customer_id: this.currentCustomerId,
        title: document.getElementById('workOrderName')?.value,
        description: document.getElementById('workOrderDescription')?.value,
        start_date: document.getElementById('workOrderStartDate')?.value,
        status: 'pending',
        notes: notes || '',
    };
    
    const result = await WorkOrderManager.createWorkOrder(workOrderData);
if (result.success) {

    const modalEl = document.getElementById("newWorkOrderModal");
    const modal = bootstrap.Modal.getInstance(modalEl);

    // 1️⃣ اقفل Bootstrap Modal أولًا
    if (modal) {
        modal.hide();
    }

    // 2️⃣ استنى المودال يقفل فعليًا
    modalEl.addEventListener('hidden.bs.modal', function handler() {
        modalEl.removeEventListener('hidden.bs.modal', handler);

        // 3️⃣ افتح Swal بعد قفل المودال
        Swal.fire('نجاح', result.message, 'success').then(() => {
            // تنظيف أي تغييرات على body لو حصلت (fallback آمن)
            try {
                // إزالة overflow style إن وُضع
                if (document.body.style.overflow === 'hidden') {
                    document.body.style.overflow = '';
                }
                // إزالة أي backdrops أو كلاسات متبقية لو لزم
            } catch (e) {
                console.warn('Cleanup after Swal failed', e);
            }
        });
        // 4️⃣ reset بعد القفل
        document.getElementById("newWorkOrderForm").reset();
    });

} else {
    Swal.fire('خطأ', result.message, 'error');
}


    },
};


export default WorkOrderManager;