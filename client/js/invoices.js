import AppData from "./app_data.js";
import { CustomReturnManager } from "./return.js";
import { updateInvoiceStats } from "./helper.js";
import CustomerManager from "./customer.js";
import PrintManager from "./print.js";
import PaymentManager from "./payment.js";
import apis from "./constant/api_links.js";

const InvoiceManager = {
    isLoading: false,
    currentCustomerId: null,
    
    async init() {
        await this.loadCustomerInvoices();
        this.setupGlobalListeners();
    },

    // ========== دوال API ==========
    
    async loadCustomerInvoices() {
        try {
            const customer = CustomerManager.getCustomer();
            if (!customer?.id) {
                this.showError("العميل غير محدد");
                return;
            }
            
            this.currentCustomerId = customer.id;
            this.isLoading = true;
            this.showLoading();
            
            const response = await fetch(`${apis.getCustomerInvoices}${customer.id}`);
            const data = await response.json();
            
            if (data.success) {
                // حفظ البيانات
                AppData.invoices = data.invoices;
                AppData.invoiceSummary = data.summary || {};
                
                // تحديث الواجهة
                this.updateInvoicesTable();
                this.updateStatsCards(data.summary);
            } else {
                this.showError(data.message || "فشل في تحميل الفواتير");
            }
        } catch (error) {
            console.error("❌ Error loading invoices:", error);
            this.showError("خطأ في الاتصال بالخادم");
        } finally {
            this.isLoading = false;
        }
    },
    
    async loadInvoiceDetails(invoiceId) {
        try {
            const response = await fetch(`${apis.getInvoiceDetails}${invoiceId}`);
            const data = await response.json();
            
            if (data.success) {
                return data.invoice;
            } else {
                throw new Error(data.message);
            }
        } catch (error) {
            console.error("❌ Error loading invoice details:", error);
            throw error;
        }
    },
    
    // ========== الواجهة الرئيسية (كما كانت) ==========
    
    updateInvoicesTable() {
        const tbody = document.getElementById("invoicesTableBody");
        tbody.innerHTML = "";

        // إذا لم توجد فواتير
        if (!AppData.invoices || AppData.invoices.length === 0) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="9" class="text-center py-5">
                        <div class="text-muted">
                            <i class="fas fa-file-invoice fa-2x mb-3"></i>
                            <p>لا توجد فواتير</p>
                        </div>
                    </td>
                </tr>
            `;
            return;
        }

        // تطبيق الفلاتر
        let filteredInvoices = this.filterInvoices(AppData.invoices);

        // إذا لم توجد نتائج بعد الفلترة
        if (filteredInvoices.length === 0) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="9" class="text-center py-5">
                        <div class="text-warning">
                            <i class="fas fa-search fa-2x mb-3"></i>
                            <p>لا توجد فواتير تطابق معايير البحث</p>
                        </div>
                    </td>
                </tr>
            `;
            return;
        }

        // بناء الجدول بنفس التصميم الأصلي
        filteredInvoices.forEach((invoice) => {
            const row = this.createInvoiceRow(invoice);
            tbody.appendChild(row);
        });

        // إضافة Event Listeners بعد بناء الجدول
        this.attachInvoiceEventListeners();
        
        // تحديث عدد المحدد
        this.updateSelectedCount();
    },
    
    createInvoiceRow(invoice) {
        const row = document.createElement("tr");
        row.className = `invoice-row ${invoice.status}`;

        // 1. تحديد حالة الفاتورة (نفس الكود الأصلي)
        let statusBadge = "";
        if (invoice.status === "pending") {
            statusBadge = '<span class="status-badge badge-pending">مؤجل</span>';
        } else if (invoice.status === "partial") {
            statusBadge = '<span class="status-badge badge-partial">جزئي</span>';
        } else if (invoice.status === "paid") {
            statusBadge = '<span class="status-badge badge-paid">مسلم</span>';
        } else if (invoice.status === "returned") {
            statusBadge = '<span class="status-badge badge-returned">مرتجع</span>';
        }

        // 2. تحديد لون المبلغ المتبقي (نفس الكود الأصلي)
        let remainingColor = "text-danger";
        if (parseFloat(invoice.remaining) === 0) {
            remainingColor = "text-success";
        } else if (invoice.status === "partial") {
            remainingColor = "text-warning";
        }

        // 3. تحضير الـ Tooltip (سنضيفه بعد تحميل التفاصيل)
        const tooltipContainer = this.createTooltipContainer(invoice);

        // 4. بناء HTML الصف (نفس التصميم الأصلي)
        row.setAttribute("data-invoice-id", invoice.id);
        row.innerHTML = `
            <td>
                <input type="checkbox" class="form-check-input invoice-checkbox" 
                       data-invoice-id="${invoice.id}">
            </td>
            <td>
                <strong>${invoice.invoice_number || invoice.id}</strong>
            </td>
            <td>${invoice.date}<br><small>${invoice.time}</small></td>
            <td class="invoice-item-hover position-relative">
                <div class="items-count" data-invoice-id="${invoice.id}">
                    ${invoice.items_count || 0} بند
                    ${invoice.has_returns ? 
                        '<br><small class="text-warning">(يوجد مرتجعات)</small>' : 
                        '<br><small class="text-muted">(مرر للعرض)</small>'}
                </div>
                ${tooltipContainer}
            </td>
            <td>${parseFloat(invoice.total).toFixed(2)} ج.م</td>
            <td>${parseFloat(invoice.paid).toFixed(2)||0} ج.م</td>
            <td>
                <span class="${remainingColor} fw-bold">
                    ${parseFloat(invoice.remaining).toFixed(2)|| 0} ج.م
                </span>
            </td>
            <td>${statusBadge}</td>
            <td>
                <div class="action-buttons">
                    <button class="btn btn-sm btn-outline-info view-invoice" 
                            data-invoice-id="${invoice.id}">
                        <i class="fas fa-eye"></i>
                    </button>
                    ${invoice.status !== "paid" && invoice.status !== "returned" ? `
                    <button class="btn btn-sm btn-outline-success pay-invoice" 
                            data-invoice-id="${invoice.id}">
                        <i class="fas fa-money-bill-wave"></i>
                    </button>
                    ` : ""}
                    ${invoice.status !== "returned" ? `
                    <button class="btn btn-sm btn-outline-warning custom-return-invoice" 
                            data-invoice-id="${invoice.id}">
                        <i class="fas fa-undo"></i>
                    </button>
                    ` : ""}
                    <button class="btn btn-sm btn-outline-secondary print-invoice" 
                            data-invoice-id="${invoice.id}">
                        <i class="fas fa-print"></i>
                    </button>
                </div>
            </td>
        `;

        // 5. إضافة event لتحميل البنود عند hover
        this.setupTooltipHover(row, invoice.id);
        
        return row;
    },
    
    createTooltipContainer(invoice) {
        return `
            <div class="invoice-items-tooltip" id="tooltip-${invoice.id}" style="display: none;">
                <div class="tooltip-loading">
                    <i class="fas fa-spinner fa-spin me-2"></i> جاري تحميل البنود...
                </div>
            </div>
        `;
    },
    
    setupTooltipHover(row, invoiceId) {
        const itemsCell = row.querySelector('.invoice-item-hover');
        const tooltip = row.querySelector(`#tooltip-${invoiceId}`);
        
        let isLoaded = false;
        let isLoading = false;
        
        itemsCell.addEventListener('mouseenter', async () => {
            if (isLoaded || isLoading) return;
            
            isLoading = true;
            tooltip.style.display = 'block';
            
            try {
                // تحميل تفاصيل الفاتورة من API
                const invoiceDetails = await this.loadInvoiceDetails(invoiceId);
                
                if (invoiceDetails?.items) {
                    // بناء الـ Tooltip كما في التصميم الأصلي
                    const tooltipHTML = this.buildItemsTooltip(invoiceDetails);
                    tooltip.innerHTML = tooltipHTML;
                    isLoaded = true;
                }
            } catch (error) {
                tooltip.innerHTML = `
                    <div class="tooltip-error text-danger">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        فشل في تحميل البنود
                    </div>
                `;
            } finally {
                isLoading = false;
            }
        });
        
        itemsCell.addEventListener('mouseleave', () => {
            tooltip.style.display = 'none';
        });
    },
    
    buildItemsTooltip(invoice) {
        const items = invoice.items || [];
        
        if (items.length === 0) {
            return `
                <div class="invoice-items-tooltip">
                    <div class="tooltip-header">بنود الفاتورة ${invoice.invoice_number || invoice.id}</div>
                    <div class="text-center py-3 text-muted">
                        لا توجد بنود
                    </div>
                </div>
            `;
        }
        
        const itemsList = items
            .map((item) => {
                const currentQuantity = item.current_quantity || 
                                      (item.quantity - (item.returned_quantity || 0));
                const currentTotal = item.current_total || 
                                   (currentQuantity * (item.selling_price || item.price || 0));
                const returnedText = item.returned_quantity > 0 ? 
                    ` (مرتجع: ${item.returned_quantity})` : "";
                
                return `
                    <div class="tooltip-item">
                        <div>
                            <div class="tooltip-item-name">${item.product_name || "منتج"}</div>
                            <div class="tooltip-item-details">
                                الكمية: ${currentQuantity} من ${item.quantity}${returnedText}<br>
                                السعر: ${(item.selling_price || item.price || 0).toFixed(2)} ج.م
                            </div>
                        </div>
                        <div class="fw-bold">${currentTotal.toFixed(2)} ج.م</div>
                    </div>
                `;
            })
            .join("");

        return `
            <div class="invoice-items-tooltip">
                <div class="tooltip-header">بنود الفاتورة ${invoice.invoice_number || invoice.id}</div>
                ${itemsList}
                <div class="tooltip-total">
                    <span>الإجمالي الحالي:</span>
                    <span>${parseFloat(invoice.total_after_discount || invoice.total).toFixed(2)} ج.م</span>
                </div>
            </div>
        `;
    },
    
    // ========== Event Listeners (نفس الكود الأصلي) ==========
    
    attachInvoiceEventListeners() {
        // 1. زر عرض الفاتورة
        document.querySelectorAll(".view-invoice").forEach((btn) => {
            btn.addEventListener("click", async function () {
                const invoiceId = parseInt(this.getAttribute("data-invoice-id"));
                await InvoiceManager.showInvoiceDetails(invoiceId);
            });
        });

        // 2. زر سداد الفاتورة
        document.querySelectorAll(".pay-invoice").forEach((btn) => {
            btn.addEventListener("click", function () {
                const invoiceId = parseInt(this.getAttribute("data-invoice-id"));
                PaymentManager.openSingleInvoicePayment(invoiceId);
            });
        });

        // 3. زر إرجاع الفاتورة المخصص
        document.querySelectorAll(".custom-return-invoice").forEach((btn) => {
            btn.addEventListener("click", function () {
                const invoiceId = parseInt(this.getAttribute("data-invoice-id"));
                CustomReturnManager.openReturnModal(invoiceId);
            });
        });

        // 4. زر طباعة الفاتورة
        document.querySelectorAll(".print-invoice").forEach((btn) => {
            btn.addEventListener("click", function () {
                const invoiceId = parseInt(this.getAttribute("data-invoice-id"));
                PrintManager.printSingleInvoice(invoiceId);
            });
        });
        
        // 5. تحديد/إلغاء تحديد الفواتير
        document.querySelectorAll(".invoice-checkbox").forEach((checkbox) => {
            checkbox.addEventListener("change", () => {
                InvoiceManager.updateSelectedCount();
            });
        });
        
        // 6. تحديد الكل
        document.getElementById("selectAllInvoices")?.addEventListener("change", function() {
            const checkboxes = document.querySelectorAll(".invoice-checkbox");
            checkboxes.forEach(cb => cb.checked = this.checked);
            InvoiceManager.updateSelectedCount();
        });
    },
    
    // ========== دالة عرض تفاصيل الفاتورة (تستخدم API) ==========
    
    async showInvoiceDetails(invoiceId) {
        try {
            // إظهار loading في المودال
            this.showModalLoading();
            
            // تحميل التفاصيل من API
            const invoice = await this.loadInvoiceDetails(invoiceId);
            
            // تعبئة المودال
            this.populateInvoiceModal(invoice);
            
            // إظهار المودال
            const modal = new bootstrap.Modal(document.getElementById("invoiceItemsModal"));
            modal.show();
            
        } catch (error) {
            console.error("Error showing invoice details:", error);
            this.showModalError(error.message);
        } finally {
            this.hideModalLoading();
        }
    },
    
    populateInvoiceModal(invoice) {
        // نفس الكود الأصلي مع تعديلات طفيفة
        document.getElementById("invoiceItemsNumber").textContent = 
            invoice.invoice_number || invoice.id;
        document.getElementById("invoiceItemsDate").textContent = 
            `${invoice.date} - ${invoice.time}`;
        document.getElementById("invoiceItemsStatus").textContent = 
            this.getInvoiceStatusText(invoice.status);
        document.getElementById("invoiceItemsTotal").textContent = 
            parseFloat(invoice.total_after_discount || invoice.total).toFixed(2) + " ج.م";
        document.getElementById("invoiceItemsPaid").textContent = 
            parseFloat(invoice.paid_amount || invoice.paid||0).toFixed(2) + " ج.م";
        document.getElementById("invoiceItemsRemaining").textContent = 
            parseFloat(invoice.remaining_amount || invoice.remaining||0).toFixed(2) + " ج.م";
        document.getElementById("invoiceItemsNotes").textContent = 
            invoice.notes || invoice.description || "لا يوجد";

        // عرض اسم الشغلانة
        document.getElementById("invoiceItemsWorkOrder").textContent = 
            invoice.work_order_name || "لا يوجد";

        // التحقق من وجود مرتجعات
        if (invoice.returns?.length > 0) {
            document.getElementById("invoiceReturnsSection").style.display = "block";
            document.getElementById("viewInvoiceReturns").onclick = (e) => {
                e.preventDefault();
                CustomReturnManager.showInvoiceReturns(invoice.id);
            };
        } else {
            document.getElementById("invoiceReturnsSection").style.display = "none";
        }

        // تعبئة جدول البنود (نفس الكود الأصلي مع تعديلات طفيفة)
        const tbody = document.getElementById("invoiceItemsDetails");
        tbody.innerHTML = "";
        
        if (invoice.items?.length > 0) {
            invoice.items.forEach((item) => {
                const row = this.createModalItemRow(item);
                tbody.appendChild(row);
            });
        }
    },
    
    createModalItemRow(item) {
        const row = document.createElement("tr");
        
        const currentQuantity = item.current_quantity || 
                               (item.quantity - (item.returned_quantity || 0));
        const currentTotal = item.current_total || 
                            (currentQuantity * (item.selling_price || item.price || 0));
        const originalTotal = item.quantity * (item.selling_price || item.price || 0);
        
        let itemStatus = "سليم";
        let rowClass = "";
        
        if (item.fully_returned || item.returned_quantity >= item.quantity) {
            itemStatus = '<span class="badge bg-danger">مرتجع كلي</span>';
            rowClass = "fully-returned";
        } else if (item.returned_quantity > 0) {
            itemStatus = '<span class="badge bg-warning">مرتجع جزئي</span>';
            rowClass = "partially-returned";
        }

        row.className = rowClass;
        row.innerHTML = `
            <td>
                <strong>${item.product_name || 'منتج'}</strong>
                ${item.returned_quantity > 0 ? 
                    `<div class="mt-1">
                        <span class="badge bg-warning return-history-badge">
                            مرتجع: ${item.returned_quantity}
                        </span>
                    </div>` : 
                    ''}
            </td>
            <td>
                <div class="d-flex flex-column">
                    <span class="text-muted small">أصلي: ${item.quantity}</span>
                    <span class="fw-bold mt-1">حالي: ${currentQuantity}</span>
                </div>
            </td>
            <td>${(item.selling_price || item.price || 0).toFixed(2)} ج.م</td>
            <td>
                <div class="d-flex flex-column">
                    <span class="text-muted small" style="text-decoration: line-through;">
                        ${originalTotal.toFixed(2)} ج.م
                    </span>
                    <span class="fw-bold mt-1">${currentTotal.toFixed(2)} ج.م</span>
                </div>
            </td>
            <td>${item.returned_quantity || 0}</td>
            <td>${itemStatus}</td>
        `;
        
        return row;
    },
    
    // ========== دوال مساعدة ==========
    
    getInvoiceStatusText(status) {
        const statusMap = {
            pending: "مؤجل",
            partial: "جزئي",
            paid: "مسلم",
            returned: "مرتجع",
        };
        return statusMap[status] || status;
    },
    
    filterInvoices(invoices) {
        // نفس الكود الأصلي
        let filtered = [...invoices];

        if (AppData.activeFilters.dateFrom) {
            filtered = filtered.filter(inv => inv.date >= AppData.activeFilters.dateFrom);
        }
        if (AppData.activeFilters.dateTo) {
            filtered = filtered.filter(inv => inv.date <= AppData.activeFilters.dateTo);
        }
        if (AppData.activeFilters.invoiceType) {
            filtered = filtered.filter(inv => inv.status === AppData.activeFilters.invoiceType);
        }
        if (AppData.activeFilters.invoiceId) {
            filtered = filtered.filter(inv => inv.id === AppData.activeFilters.invoiceId);
        }
        if (AppData.activeFilters.productSearch) {
            const searchTerm = AppData.activeFilters.productSearch.toLowerCase();
            // هنا سنحتاج لتحسين الـ API لدعم البحث في البنود
            filtered = filtered.filter(inv => inv.description?.toLowerCase().includes(searchTerm) || inv.items?.some(item => item.product_name.toLowerCase().includes(searchTerm))|| inv.invoice_number?.toString().includes(searchTerm));
            console.log(filtered);
            
        }

        return filtered;
    },
    
  updateStatsCards(summary) {
    if (!summary) return;
    
    // 1. تحديث الأعداد (كما كان)
    document.getElementById('totalInvoicesCount').textContent = summary.total_invoices || 0;
    document.getElementById('pendingInvoicesCount').textContent = summary.pending_count || 0;
    document.getElementById('partialInvoicesCount').textContent = summary.partial_count || 0;
    document.getElementById('paidInvoicesCount').textContent = summary.paid_count || 0;
    document.getElementById('returnedInvoicesCount').textContent = summary.returned_count || 0;
    
    // 2. تحديث المبالغ - هذا هو المطلوب
    this.updateAmounts(summary);
},

updateAmounts(summary) {
    // دالة تنسيق المبلغ
    const formatCurrency = (amount) => {
        const num = parseFloat(amount || 0);
        return num.toFixed(2) + ' ج.م';
    };
    
    // تحديث كل كارت حسب data-filter
    const cards = document.querySelectorAll('.invoice-stat-card');
    
    cards.forEach(card => {
        const filter = card.getAttribute('data-filter');
        const amountElement = card.querySelector('.stat-amount');
        
        if (!amountElement) return;
        
        let amount = 0;
        
        // تحديد المبلغ حسب نوع الكارت
        switch(filter) {
            case 'all':
                amount = summary.total_amount || summary.total_invoices || 0;
                break;
            case 'pending':
                amount = summary.pending_amount || summary.pending_count || 0;
                break;
            case 'partial':
                amount = summary.partial_amount || summary.partial_count || 0;
                break;
            case 'paid':
                amount = summary.paid_amount || summary.paid_count || 0;
                break;
            case 'returned':
                amount = summary.returned_amount || summary.returned_count || 0;
                break;
        }
        
        // تحديث النص
        amountElement.textContent = formatCurrency(amount);
        
        // الحفاظ على اللون الأصلي من HTML
        // HTML فيه: text-primary, text-warning, text-info, etc
        // لنغيرها، فقط تأكد من وجود اللون
    });
},
    
    updateSelectedCount() {
        const selectedCount = document.querySelectorAll(".invoice-checkbox:checked").length;
        const printBtn = document.getElementById("printSelectedInvoices");
        if (printBtn) {
            printBtn.disabled = selectedCount === 0;
            printBtn.innerHTML = `<i class="fas fa-print me-2"></i>طباعة (${selectedCount})`;
        }
    },
    
    showLoading() {
        const tbody = document.getElementById("invoicesTableBody");
        tbody.innerHTML = `
            <tr>
                <td colspan="9" class="text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">جاري التحميل...</span>
                    </div>
                    <p class="mt-2 text-muted">جاري تحميل الفواتير...</p>
                </td>
            </tr>
        `;
    },
    
    showError(message) {
        const tbody = document.getElementById("invoicesTableBody");
        tbody.innerHTML = `
            <tr>
                <td colspan="9" class="text-center py-5">
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        ${message}
                    </div>
                    <button class="btn btn-sm btn-outline-primary mt-2" 
                            onclick="InvoiceManager.loadCustomerInvoices()">
                        <i class="fas fa-redo me-1"></i> إعادة المحاولة
                    </button>
                </td>
            </tr>
        `;
    },
    
    showModalLoading() {
        const modalBody = document.querySelector('#invoiceItemsModal .modal-body');
        const loadingDiv = document.createElement('div');
        loadingDiv.className = 'modal-loading';
        loadingDiv.innerHTML = `
            <div class="text-center py-4">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">جاري التحميل...</span>
                </div>
                <p class="mt-2">جاري تحميل تفاصيل الفاتورة...</p>
            </div>
        `;
        modalBody.appendChild(loadingDiv);
    },
    
    hideModalLoading() {
        const loadingDiv = document.querySelector('.modal-loading');
        if (loadingDiv) loadingDiv.remove();
    },
    
    showModalError(message) {
        const modalBody = document.querySelector('#invoiceItemsModal .modal-body');
        const errorDiv = document.createElement('div');
        errorDiv.className = 'alert alert-danger text-center';
        errorDiv.innerHTML = `
            <i class="fas fa-exclamation-triangle me-2"></i>
            ${message}
        `;
        modalBody.appendChild(errorDiv);
    },
    
    setupGlobalListeners() {
        // تحديث عند تغيير الفلاتر
        document.getElementById('invoiceTypeFilter')?.addEventListener('change', (e) => {
            AppData.activeFilters.invoiceType = e.target.value || null;
            this.loadCustomerInvoices();
        });
        
        // تحديث عند تغيير التواريخ
        ['dateFrom', 'dateTo'].forEach(id => {
            document.getElementById(id)?.addEventListener('change', (e) => {
                AppData.activeFilters[id] = e.target.value;
                this.loadCustomerInvoices();
            });
        });
    },
    
    // دوال أخرى كما هي
    getInvoiceById(invoiceId) {
        return AppData.invoices.find(inv => inv.id === invoiceId);
    },
    
    selectAllInvoices() {
        document.querySelectorAll(".invoice-checkbox").forEach((checkbox) => {
            checkbox.checked = true;
        });
        this.updateSelectedCount();
    },
    
    selectNonWorkOrderInvoices() {
        document.querySelectorAll(".invoice-checkbox").forEach((checkbox) => {
            const invoiceId = parseInt(checkbox.getAttribute("data-invoice-id"));
            const invoice = this.getInvoiceById(invoiceId);
            checkbox.checked = !invoice?.workOrderId;
        });
        this.updateSelectedCount();
    }
};

export default InvoiceManager;