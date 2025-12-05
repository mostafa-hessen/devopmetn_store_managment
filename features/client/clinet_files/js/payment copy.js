import  AppData  from './app_data.js';
import WalletManager from './wallet.js';
const PaymentMethods = [
        { id: 1, name: "نقدي", icon: "fas fa-money-bill-wave" },
        { id: 2, name: "فيزا", icon: "fas fa-credit-card" },
        { id: 3, name: "شيك", icon: "fas fa-file-invoice" },
        { id: 4, name: "محفظة", icon: "fas fa-wallet" },
      ];
const PaymentManager = {
    init() {
        this.setupPaymentEventListeners();
    },
    setupPaymentEventListeners() {
        // تغيير نوع السداد
        document.querySelectorAll('input[name="paymentType"]').forEach(radio => {
            radio.addEventListener('change', function () {
                const paymentType = this.value;

                // إظهار/إخفاء الأقسام
                document.getElementById('invoicesPaymentSection').style.display =
                    paymentType === 'invoices' ? 'block' : 'none';
                document.getElementById('workOrderPaymentSection').style.display =
                    paymentType === 'workOrder' ? 'block' : 'none';

                // إعادة تعيين الحقول
                PaymentManager.resetPaymentForm();

                // تحميل البيانات حسب النوع
                if (paymentType === 'invoices') {
                    PaymentManager.loadInvoicesForPayment();
                } else {
                    PaymentManager.resetWorkOrderSearch();
                }
            });
        });

        // بحث في الفواتير
        document.getElementById('invoiceSearch').addEventListener('input', function (e) {
            PaymentManager.filterInvoicesForPayment(e.target.value);
        });

        // بحث في الشغلانات
        document.getElementById('workOrderSearch').addEventListener('input', function (e) {
            PaymentManager.searchWorkOrders(e.target.value);
        });

        // تحديد/إلغاء تحديد جميع الفواتير
        document.getElementById('selectAllInvoicesForPayment').addEventListener('change', function () {
            PaymentManager.toggleSelectAllInvoices(this.checked);
        });

        // إضافة طريقة دفع
        document.getElementById('addPaymentMethodBtn').addEventListener('click', function () {
            PaymentManager.addPaymentMethod();
        });

        // معالجة السداد
        document.getElementById('processPaymentBtn').addEventListener('click', function () {
            PaymentManager.processPayment();
        });

        // تحديث المبالغ عند الإدخال
        document.addEventListener('input', function (e) {
            if (e.target.classList.contains('invoice-payment-amount') ||
                e.target.classList.contains('workorder-invoice-payment-amount') ||
                e.target.classList.contains('payment-method-amount')) {
                PaymentManager.updatePaymentSummary();
            }
        });

        // تغيير طريقة الدفع
        document.addEventListener('change', function (e) {
            if (e.target.classList.contains('payment-method-select')) {
                PaymentManager.updatePaymentSummary();
            }
        });
    },
    updateManualPaymentTable() {
        const tbody = document.getElementById("manualPaymentTableBody");
        tbody.innerHTML = "";

        const unpaidInvoices = AppData.invoices.filter(
            (i) => i.remaining > 0
        );

        unpaidInvoices.forEach((invoice) => {
            const row = document.createElement("tr");
            row.className = "invoice-item-hover";

            // إنشاء tooltip للبنود
            let itemsTooltip = "";
            if (invoice.items && invoice.items.length > 0) {
                const itemsList = invoice.items
                    .map((item) => {
                        const itemTotal = (item.quantity || 0) * (item.price || 0);
                        return `
                                <div class="tooltip-item">
                                    <div>
                                        <div class="tooltip-item-name">${item.productName || "منتج"
                            }</div>
                                        <div class="tooltip-item-details">الكمية: ${item.quantity || 0
                            } | السعر: ${(item.price || 0).toFixed(
                                2
                            )} ج.م</div>
                                    </div>
                                    <div class="fw-bold">${itemTotal.toFixed(
                                2
                            )} ج.م</div>
                                </div>
                            `;
                    })
                    .join("");

                itemsTooltip = `
                            <div class="invoice-items-tooltip">
                                <div class="tooltip-header">بنود الفاتورة ${invoice.number
                    }</div>
                                ${itemsList}
                                <div class="tooltip-total">
                                    <span>الإجمالي:</span>
                                    <span>${invoice.total.toFixed(2)} ج.م</span>
                                </div>
                            </div>
                        `;
            }

            row.innerHTML = `
                        <td class="position-relative">
                            ${invoice.number}
                            ${itemsTooltip}
                        </td>
                        <td>${invoice.date}</td>
                        <td>${invoice.total.toFixed(2)} ج.م</td>
                        <td>${invoice.remaining.toFixed(2)} ج.م</td>
                        <td>
                            <input type="number" class="form-control form-control-sm manual-payment-amount" 
                                   data-invoice-id="${invoice.id}" 
                                   min="0" max="${invoice.remaining}" 
                                   value="0" step="0.01">
                        </td>
                    `;
            tbody.appendChild(row);
        });

        this.resetPaymentMethods(
            "manualPaymentMethods",
            "manualPaymentTotal"
        );
        this.updateManualPaymentTotal();
    },

    updateWorkOrderPaymentSelect() {
        const select = document.getElementById("workOrderPaymentSelect");
        select.innerHTML = '<option value="">اختر الشغلانة</option>';

        AppData.workOrders.forEach((workOrder) => {
            const option = document.createElement("option");
            option.value = workOrder.id;
            option.textContent = workOrder.name;
            select.appendChild(option);
        });

        document.getElementById("workOrderInvoicesSection").style.display =
            "none";
    },

    updateWorkOrderInvoices(workOrderId) {
        const invoices = WorkOrderManager.getWorkOrderInvoices(workOrderId);
        const tbody = document.getElementById("workOrderInvoicesTableBody");
        tbody.innerHTML = "";

        let hasUnpaidInvoices = false;

        invoices.forEach((invoice) => {
            if (invoice.remaining > 0) {
                hasUnpaidInvoices = true;
                const row = document.createElement("tr");
                row.className = "invoice-item-hover";

                // إنشاء tooltip للبنود
                let itemsTooltip = "";
                if (invoice.items && invoice.items.length > 0) {
                    const itemsList = invoice.items
                        .map((item) => {
                            const itemTotal = (item.quantity || 0) * (item.price || 0);
                            return `
                                    <div class="tooltip-item">
                                        <div>
                                            <div class="tooltip-item-name">${item.productName || "منتج"
                                }</div>
                                            <div class="tooltip-item-details">الكمية: ${item.quantity || 0
                                } | السعر: ${(
                                    item.price || 0
                                ).toFixed(2)} ج.م</div>
                                        </div>
                                        <div class="fw-bold">${itemTotal.toFixed(
                                    2
                                )} ج.م</div>
                                    </div>
                                `;
                        })
                        .join("");

                    itemsTooltip = `
                                <div class="invoice-items-tooltip">
                                    <div class="tooltip-header">بنود الفاتورة ${invoice.number
                        }</div>
                                    ${itemsList}
                                    <div class="tooltip-total">
                                        <span>الإجمالي:</span>
                                        <span>${invoice.total.toFixed(
                            2
                        )} ج.م</span>
                                    </div>
                                </div>
                            `;
                }

                row.innerHTML = `
                            <td class="position-relative">
                                ${invoice.number}
                                ${itemsTooltip}
                            </td>
                            <td>${invoice.date}</td>
                            <td>${invoice.total.toFixed(2)} ج.م</td>
                            <td>${invoice.remaining.toFixed(2)} ج.م</td>
                            <td>
                                <input type="number" class="form-control form-control-sm workorder-payment-amount" 
                                       data-invoice-id="${invoice.id}" 
                                       min="0" max="${invoice.remaining}" 
                                       value="${invoice.remaining}" step="0.01">
                            </td>
                        `;
                tbody.appendChild(row);
            }
        });

        if (hasUnpaidInvoices) {
            document.getElementById("workOrderInvoicesSection").style.display =
                "block";
            this.resetPaymentMethods(
                "workOrderPaymentMethods",
                "workOrderPaymentTotal"
            );
            this.updateWorkOrderPaymentTotal();
        } else {
            document.getElementById("workOrderInvoicesSection").style.display =
                "none";
            Swal.fire(
                "تنبيه",
                "لا توجد فواتير غير مسددة في هذه الشغلانة.",
                "info"
            );
        }
    },

   

    // إضافة دالة resetPaymentForm لإعادة تعيين المودال

    resetPaymentMethods(containerId, totalElementId) {
        document.getElementById(containerId).innerHTML = "";
        document.getElementById(totalElementId).textContent = "0.00 ج.م";
        this.addPaymentMethod(containerId, totalElementId);
    },

    updateManualPaymentTotal() {
        let total = 0;

        // جمع المبالغ من المدخلات
        document
            .querySelectorAll(".manual-payment-amount")
            .forEach((input) => {
                total += parseFloat(input.value) || 0;
            });

        document.getElementById("manualPaymentTotal").textContent =
            total.toFixed(2) + " ج.م";

        // تحديث الرصيد المتاح والمتبقي
        const availableBalance = WalletManager.getAvailableBalance();
        const remainingBalance = availableBalance - total;

        document.getElementById("manualPaymentAvailableBalance").textContent =
            availableBalance.toFixed(2) + " ج.م";
        document.getElementById("manualPaymentRemainingBalance").textContent =
            remainingBalance.toFixed(2) + " ج.م";

        // تغيير لون المتبقي إذا كان سالباً
        const remainingElement = document.getElementById(
            "manualPaymentRemainingBalance"
        );
        if (remainingBalance < 0) {
            remainingElement.classList.add("text-danger");
            remainingElement.classList.remove("text-success");
        } else {
            remainingElement.classList.add("text-success");
            remainingElement.classList.remove("text-danger");
        }
    },

    updateWorkOrderPaymentTotal() {
        let total = 0;

        // جمع المبالغ من المدخلات
        document
            .querySelectorAll(".workorder-payment-amount")
            .forEach((input) => {
                total += parseFloat(input.value) || 0;
            });

        document.getElementById("workOrderPaymentTotal").textContent =
            total.toFixed(2) + " ج.م";

        // تحديث الرصيد المتاح والمتبقي
        const availableBalance = WalletManager.getAvailableBalance();
        const remainingBalance = availableBalance - total;

        document.getElementById(
            "workOrderPaymentAvailableBalance"
        ).textContent = availableBalance.toFixed(2) + " ج.م";
        document.getElementById(
            "workOrderPaymentRemainingBalance"
        ).textContent = remainingBalance.toFixed(2) + " ج.م";

        // تغيير لون المتبقي إذا كان سالباً
        const remainingElement = document.getElementById(
            "workOrderPaymentRemainingBalance"
        );
        if (remainingBalance < 0) {
            remainingElement.classList.add("text-danger");
            remainingElement.classList.remove("text-success");
        } else {
            remainingElement.classList.add("text-success");
            remainingElement.classList.remove("text-danger");
        }
    },

    collectPaymentMethods(containerId) {
        const methods = [];
        const container = document.getElementById(containerId);

        container.querySelectorAll(".payment-method-item").forEach((item) => {
            const methodSelect = item.querySelector(".payment-method-select");
            const amountInput = item.querySelector(".payment-method-amount");

            const methodId = parseInt(methodSelect.value);
            const amount = parseFloat(amountInput.value) || 0;

            if (methodId && amount > 0) {
                const method = PaymentMethods.find((pm) => pm.id === methodId);
                if (method) {
                    methods.push({
                        method: method.name,
                        amount: amount,
                    });
                }
            }
        });

        return methods;
    },

    processPayment() {
        if (document.getElementById("manualPayment").checked) {
            this.processManualPayment();
        } else if (document.getElementById("workOrderPayment").checked) {
            this.processWorkOrderPayment();
        }
    },

    processManualPayment() {
        const paymentMethods = this.collectPaymentMethods(
            "manualPaymentMethods"
        );
        const totalPayment = paymentMethods.reduce(
            (sum, pm) => sum + pm.amount,
            0
        );

        if (totalPayment <= 0) {
            Swal.fire("تحذير", "يرجى إدخال مبالغ صحيحة للدفع.", "warning");
            return;
        }

        let totalPaid = 0;
        const paymentInputs = document.querySelectorAll(
            ".manual-payment-amount"
        );

        paymentInputs.forEach((input) => {
            const amount = parseFloat(input.value) || 0;
            const invoiceId = parseInt(input.getAttribute("data-invoice-id"));
            const invoice = AppData.invoices.find((i) => i.id === invoiceId);

            if (invoice && amount > 0 && amount <= invoice.remaining) {
                this.addPaymentToInvoice(invoiceId, amount, paymentMethods);
                totalPaid += amount;
            }
        });

        if (totalPaid > 0) {
            Swal.fire(
                "نجاح",
                `تم سداد ${totalPaid.toFixed(2)} ج.م بنجاح.`,
                "success"
            );
            const paymentModal = bootstrap.Modal.getInstance(
                document.getElementById("paymentModal")
            );
            paymentModal.hide();
        } else {
            Swal.fire(
                "تحذير",
                "لم يتم تحديد أي مبالغ للدفع أو القيم غير صالحة.",
                "warning"
            );
        }
    },

    processWorkOrderPayment() {
        const workOrderId = parseInt(
            document.getElementById("workOrderPaymentSelect").value
        );
        if (!workOrderId) {
            Swal.fire("تحذير", "يرجى اختيار شغلانة.", "warning");
            return;
        }

        const paymentMethods = this.collectPaymentMethods(
            "workOrderPaymentMethods"
        );
        const totalPayment = paymentMethods.reduce(
            (sum, pm) => sum + pm.amount,
            0
        );

        if (totalPayment <= 0) {
            Swal.fire("تحذير", "يرجى إدخال مبالغ صحيحة للدفع.", "warning");
            return;
        }

        let totalPaid = 0;
        const paymentInputs = document.querySelectorAll(
            ".workorder-payment-amount"
        );

        paymentInputs.forEach((input) => {
            const amount = parseFloat(input.value) || 0;
            const invoiceId = parseInt(input.getAttribute("data-invoice-id"));
            const invoice = AppData.invoices.find((i) => i.id === invoiceId);

            if (invoice && amount > 0 && amount <= invoice.remaining) {
                this.addPaymentToInvoice(invoiceId, amount, paymentMethods);
                totalPaid += amount;
            }
        });

        if (totalPaid > 0) {
            Swal.fire(
                "نجاح",
                `تم سداد ${totalPaid.toFixed(2)} ج.م للشغلانة بنجاح.`,
                "success"
            );
            const paymentModal = bootstrap.Modal.getInstance(
                document.getElementById("paymentModal")
            );
            paymentModal.hide();
        } else {
            Swal.fire(
                "تحذير",
                "لم يتم تحديد أي مبالغ للدفع أو القيم غير صالحة.",
                "warning"
            );
        }
    },

    openSingleInvoicePayment(invoiceId) {
        // تعيين نوع السداد إلى فواتير
        document.getElementById('payInvoicesRadio').checked = true;
        document.getElementById('invoicesPaymentSection').style.display = 'block';
        document.getElementById('workOrderPaymentSection').style.display = 'none';

        // تحميل الفواتير
        PaymentManager.loadInvoicesForPayment();

        // تحديد الفاتورة المطلوبة فقط
        setTimeout(() => {
            const checkbox = document.querySelector(`.invoice-payment-checkbox[data-invoice-id="${invoiceId}"]`);
            if (checkbox) {
                checkbox.checked = true;
                checkbox.dispatchEvent(new Event('change'));

                const amountInput = document.querySelector(`.invoice-payment-amount[data-invoice-id="${invoiceId}"]`);
                if (amountInput) {
                    amountInput.value = amountInput.getAttribute('max');
                    amountInput.dispatchEvent(new Event('input'));
                }

                // التمرير إلى الصف المحدد
                const row = checkbox.closest('tr');
                if (row) {
                    row.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    row.style.backgroundColor = 'rgba(13, 110, 253, 0.1)';
                    setTimeout(() => {
                        row.style.backgroundColor = '';
                    }, 3000);
                }
            }
        }, 100);

        // فتح المودال
        const paymentModal = new bootstrap.Modal(document.getElementById('paymentModal'));
        paymentModal.show();
    }
    , openWorkOrderPayment(workOrderId) {
        document.getElementById("workOrderPayment").checked = true;
        document.getElementById("manualPaymentSection").style.display =
            "none";
        document.getElementById("workOrderPaymentSection").style.display =
            "block";

        // تحديد الشغلانة
        document.getElementById("workOrderPaymentSelect").value = workOrderId;
        this.updateWorkOrderInvoices(workOrderId);

        // فتح مودال السداد
        const paymentModal = new bootstrap.Modal(
            document.getElementById("paymentModal")
        );
        paymentModal.show();
    },
    // في دالة loadInvoicesForPayment داخل PaymentManager
    loadInvoicesForPayment() {
        const tbody = document.getElementById('invoicesPaymentTableBody');
        tbody.innerHTML = '';

        const unpaidInvoices = AppData.invoices.filter(i => i.remaining > 0);

        unpaidInvoices.forEach(invoice => {
            const row = document.createElement('tr');
            row.className = 'invoice-item-hover';

            // الحصول على اسم الشغلانة إذا كانت مرتبطة
            let workOrderName = '';
            if (invoice.workOrderId) {
                const workOrder = AppData.workOrders.find(wo => wo.id === invoice.workOrderId);
                if (workOrder) {
                    workOrderName = workOrder.name;
                }
            }

            // إنشاء tooltip للبنود
            let itemsTooltip = '';
            if (invoice.items && invoice.items.length > 0) {
                const itemsList = invoice.items.map(item => {
                    const itemTotal = (item.quantity || 0) * (item.price || 0);
                    return `
                    <div class="tooltip-item">
                        <div>
                            <div class="tooltip-item-name">${item.productName || 'منتج'}</div>
                            <div class="tooltip-item-details">الكمية: ${item.quantity || 0} | السعر: ${(item.price || 0).toFixed(2)} ج.م</div>
                        </div>
                        <div class="fw-bold">${itemTotal.toFixed(2)} ج.م</div>
                    </div>
                `;
                }).join('');

                itemsTooltip = `
                <div class="invoice-items-tooltip">
                    <div class="tooltip-header">بنود الفاتورة ${invoice.number}</div>
                    ${itemsList}
                    <div class="tooltip-total">
                        <span>الإجمالي:</span>
                        <span>${invoice.total.toFixed(2)} ج.م</span>
                    </div>
                </div>
            `;
            }

            row.innerHTML = `
            <td>
                <input type="checkbox" class="form-check-input invoice-payment-checkbox" 
                       data-invoice-id="${invoice.id}"
                       data-has-workorder="${invoice.workOrderId ? 'true' : 'false'}">
            </td>
            <td class="position-relative">
                <strong>${invoice.number}</strong>
                ${workOrderName ? `<br><small class="text-muted"><i class="fas fa-tools me-1"></i>${workOrderName}</small>` : ''}
                ${itemsTooltip}
            </td>
            <td>${invoice.date}</td>
            <td>${invoice.total.toFixed(2)} ج.م</td>
            <td>${invoice.remaining.toFixed(2)} ج.م</td>
            <td>
                <input type="number" class="form-control form-control-sm invoice-payment-amount" 
                       data-invoice-id="${invoice.id}" 
                       data-workorder-id="${invoice.workOrderId || ''}"
                       min="0" max="${invoice.remaining}" 
                       value="0" step="0.01" disabled>
            </td>
        `;
            tbody.appendChild(row);

            // تفعيل/تعطيل حقل الإدخال بناءً على التحديد
            const checkbox = row.querySelector('.invoice-payment-checkbox');
            const amountInput = row.querySelector('.invoice-payment-amount');

            checkbox.addEventListener('change', function () {
                amountInput.disabled = !this.checked;
                if (!this.checked) {
                    amountInput.value = 0;
                }
                PaymentManager.updatePaymentSummary();
            });

            amountInput.addEventListener('input', function () {
                const maxAmount = parseFloat(this.getAttribute('max'));
                const currentValue = parseFloat(this.value) || 0;

                if (currentValue > maxAmount) {
                    this.value = maxAmount;
                    Swal.fire('تحذير', `لا يمكن سداد أكثر من ${maxAmount.toFixed(2)} ج.م لهذه الفاتورة`, 'warning');
                }

                // التحقق من القيمة
                if (currentValue < 0) {
                    this.value = 0;
                    Swal.fire('تحذير', 'لا يمكن إدخال قيمة سالبة', 'warning');
                }

                PaymentManager.updatePaymentSummary();
            });
        });

        PaymentManager.resetPaymentMethods();
        PaymentManager.updatePaymentSummary();
    },
    filterInvoicesForPayment(searchTerm) {
        const rows = document.querySelectorAll('#invoicesPaymentTableBody tr');

        rows.forEach(row => {
            const invoiceNumber = row.querySelector('td:nth-child(2)').textContent;
            const isVisible = invoiceNumber.toLowerCase().includes(searchTerm.toLowerCase());
            row.style.display = isVisible ? '' : 'none';
        });
    },

    searchWorkOrders(searchTerm) {
        const container = document.getElementById('workOrderSearchResults');

        if (!searchTerm || searchTerm.length < 2) {
            container.style.display = 'none';
            return;
        }

        const results = AppData.workOrders.filter(workOrder =>
            workOrder.name.toLowerCase().includes(searchTerm.toLowerCase()) ||
            workOrder.description.toLowerCase().includes(searchTerm.toLowerCase())
        );

        container.innerHTML = '';

        if (results.length === 0) {
            container.innerHTML = '<div class="p-3 text-muted text-center">لا توجد نتائج</div>';
            container.style.display = 'block';
            return;
        }

        results.forEach(workOrder => {
            const div = document.createElement('div');
            div.className = 'search-result-item';
            div.innerHTML = `
            <div class="fw-bold">${workOrder.name}</div>
            <div class="small text-muted">${workOrder.description}</div>
            <div class="small">تاريخ البدء: ${workOrder.startDate} | الحالة: ${workOrder.status === 'pending' ? 'قيد التنفيذ' : 'مكتمل'}</div>
        `;

            div.addEventListener('click', function () {
                PaymentManager.selectWorkOrderForPayment(workOrder.id);
                container.style.display = 'none';
                document.getElementById('workOrderSearch').value = workOrder.name;
            });

            container.appendChild(div);
        });

        container.style.display = 'block';
    },

    selectWorkOrderForPayment(workOrderId) {
        const workOrder = AppData.workOrders.find(wo => wo.id === workOrderId);
        if (!workOrder) return;

        // تحديث تفاصيل الشغلانة
        document.getElementById('selectedWorkOrderName').textContent = workOrder.name;
        document.getElementById('selectedWorkOrderDescription').textContent = workOrder.description;
        document.getElementById('selectedWorkOrderStartDate').textContent = workOrder.startDate;
        document.getElementById('selectedWorkOrderStatus').textContent =
            workOrder.status === 'pending' ? 'قيد التنفيذ' : 'مكتمل';

        // الحصول على الفواتير المرتبطة
        const relatedInvoices = AppData.invoices.filter(inv =>
            workOrder.invoices.includes(inv.id) && inv.remaining > 0
        );

        document.getElementById('selectedWorkOrderInvoicesCount').textContent =
            `${relatedInvoices.length} فاتورة`;

        // تحديث جدول فواتير الشغلانة
        const tbody = document.getElementById('workOrderInvoicesTableBody');
        tbody.innerHTML = '';

        relatedInvoices.forEach(invoice => {
            const row = document.createElement('tr');
            row.className = 'invoice-item-hover';

            // إنشاء tooltip للبنود
            let itemsTooltip = '';
            if (invoice.items && invoice.items.length > 0) {
                const itemsList = invoice.items.map(item => {
                    const itemTotal = (item.quantity || 0) * (item.price || 0);
                    return `
                    <div class="tooltip-item">
                        <div>
                            <div class="tooltip-item-name">${item.productName || 'منتج'}</div>
                            <div class="tooltip-item-details">الكمية: ${item.quantity || 0} | السعر: ${(item.price || 0).toFixed(2)} ج.م</div>
                        </div>
                        <div class="fw-bold">${itemTotal.toFixed(2)} ج.م</div>
                    </div>
                `;
                }).join('');

                itemsTooltip = `
                <div class="invoice-items-tooltip">
                    <div class="tooltip-header">بنود الفاتورة ${invoice.number}</div>
                    ${itemsList}
                    <div class="tooltip-total">
                        <span>الإجمالي:</span>
                        <span>${invoice.total.toFixed(2)} ج.م</span>
                    </div>
                </div>
            `;
            }

            row.innerHTML = `
            <td class="position-relative">
                ${invoice.number}
                ${itemsTooltip}
            </td>
            <td>${invoice.date}</td>
            <td>${invoice.total.toFixed(2)} ج.م</td>
            <td>${invoice.remaining.toFixed(2)} ج.م</td>
            <td>
                <input type="number" class="form-control form-control-sm workorder-invoice-payment-amount" 
                       data-invoice-id="${invoice.id}" 
                       min="0" max="${invoice.remaining}" 
                       value="0" step="0.01">
            </td>
        `;
            tbody.appendChild(row);

            // إضافة مستمع للأحداث للمبلغ المدخل
            const amountInput = row.querySelector('.workorder-invoice-payment-amount');
            amountInput.addEventListener('input', function () {
                const maxAmount = parseFloat(this.getAttribute('max'));
                const currentValue = parseFloat(this.value) || 0;

                if (currentValue > maxAmount) {
                    this.value = maxAmount;
                    Swal.fire('تحذير', `لا يمكن سداد أكثر من ${maxAmount.toFixed(2)} ج.م لهذه الفاتورة`, 'warning');
                }

                PaymentManager.updatePaymentSummary();
            });
        });

        document.getElementById('selectedWorkOrderDetails').style.display = 'block';
        PaymentManager.resetPaymentMethods();
        PaymentManager.updatePaymentSummary();
    },

    resetWorkOrderSearch() {
        document.getElementById('workOrderSearch').value = '';
        document.getElementById('workOrderSearchResults').style.display = 'none';
        document.getElementById('selectedWorkOrderDetails').style.display = 'none';
        document.getElementById('workOrderInvoicesTableBody').innerHTML = '';
        PaymentManager.resetPaymentMethods();
        PaymentManager.updatePaymentSummary();
    },

    toggleSelectAllInvoices(checked) {
        const checkboxes = document.querySelectorAll('.invoice-payment-checkbox');
        const amountInputs = document.querySelectorAll('.invoice-payment-amount');

        checkboxes.forEach((checkbox, index) => {
            if (checkbox.closest('tr').style.display !== 'none') {
                checkbox.checked = checked;
                amountInputs[index].disabled = !checked;

                if (!checked) {
                    amountInputs[index].value = 0;
                }
            }
        });

        PaymentManager.updatePaymentSummary();
    },

    // في دالة addPaymentMethod في PaymentManager، استبدل الـ HTML الحالي بهذا:
    addPaymentMethod() {
        const container = document.getElementById('paymentMethodsContainer');
        const methodCount = container.children.length;

        const methodElement = document.createElement('div');
        methodElement.className = 'payment-method-item mb-3 border p-3 rounded';
        methodElement.innerHTML = `
        <div class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label small">طريقة الدفع</label>
                <select class="form-select payment-method-select" data-method-index="${methodCount}" required>
                    <option value="">اختر طريقة...</option>
                    ${PaymentMethods.map(pm =>
            `<option value="${pm.id}">${pm.name}</option>`
        ).join('')}
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small">المبلغ</label>
                <input type="number" class="form-control payment-method-amount" 
                       data-method-index="${methodCount}" step="0.01" value="0" required>
            </div>
            <div class="col-md-4">
                <label class="form-label small">ملاحظات (اختياري)</label>
                <input type="text" class="form-control payment-method-notes" 
                       data-method-index="${methodCount}" placeholder="ملاحظات حول هذه الدفعة...">
            </div>
            <div class="col-md-1">
                <button type="button" class="btn btn-danger btn-sm remove-payment-method" 
                        data-method-index="${methodCount}" ${methodCount === 0 ? 'disabled' : ''}>
                    <i class="fas fa-times"></i>
                </button>
            </div>
        </div>
    `;
        container.appendChild(methodElement);

        // إضافة مستمع حدث لحذف طريقة الدفع
        const removeBtn = methodElement.querySelector('.remove-payment-method');
        removeBtn.addEventListener('click', function () {
            if (container.children.length > 1) {
                methodElement.remove();
                PaymentManager.updatePaymentSummary();
            }
        });

        PaymentManager.updatePaymentSummary();
    },

    resetPaymentMethods() {
        document.getElementById('paymentMethodsContainer').innerHTML = '';
        PaymentManager.addPaymentMethod();
    },

    updatePaymentSummary() {
        let totalRequired = 0;
        let totalEntered = 0;
        let walletPayment = 0;
        const paymentType = document.querySelector('input[name="paymentType"]:checked').value;

        // حساب المبلغ المطلوب بناءً على نوع السداد
        if (paymentType === 'invoices') {
            // جمع المبالغ المطلوبة من الفواتير المحددة
            document.querySelectorAll('.invoice-payment-checkbox:checked').forEach(checkbox => {
                const invoiceId = parseInt(checkbox.getAttribute('data-invoice-id'));
                const invoice = AppData.invoices.find(i => i.id === invoiceId);
                if (invoice) {
                    totalRequired += invoice.remaining;
                }
            });

            // جمع المبالغ المدخلة
            document.querySelectorAll('.invoice-payment-amount:not(:disabled)').forEach(input => {
                totalEntered += parseFloat(input.value) || 0;
            });
        } else {
            // جمع المبالغ من فواتير الشغلانة
            document.querySelectorAll('.workorder-invoice-payment-amount').forEach(input => {
                const amount = parseFloat(input.value) || 0;
                totalEntered += amount;

                const invoiceId = parseInt(input.getAttribute('data-invoice-id'));
                const invoice = AppData.invoices.find(i => i.id === invoiceId);
                if (invoice) {
                    totalRequired += invoice.remaining;
                }
            });
        }

        // حساب مبلغ المحفظة من طرق الدفع
        walletPayment = 0;
        document.querySelectorAll('.payment-method-item').forEach(item => {
            const methodSelect = item.querySelector('.payment-method-select');
            const amountInput = item.querySelector('.payment-method-amount');

            if (methodSelect.value === '4') { // 4 هو id طريقة دفع المحفظة
                walletPayment += parseFloat(amountInput.value) || 0;
            }
        });

        // تحديث العناصر
        document.getElementById('invoicesTotalAmount').value = totalRequired.toFixed(2) ;
        document.getElementById('totalRequiredAmount').textContent = totalRequired.toFixed(2) + ' ج.م';
        document.getElementById('totalEnteredAmount').textContent = totalEntered.toFixed(2) + ' ج.م';

        // عرض/إخفاء تفاصيل المحفظة
        const walletDetails = document.getElementById('walletPaymentDetails');
        if (walletPayment > 0) {
            const availableBalance = WalletManager.getAvailableBalance();
            const remainingBalance = availableBalance - walletPayment;

            document.getElementById('availableWalletBalance').textContent = availableBalance.toFixed(2) + ' ج.م';
            document.getElementById('walletPaymentAmount').textContent = walletPayment.toFixed(2) + ' ج.م';
            document.getElementById('remainingWalletBalance').textContent = remainingBalance.toFixed(2) + ' ج.م';

            walletDetails.style.display = 'block';

            // التحقق من أن مبلغ المحفظة لا يتجاوز الرصيد المتاح
            if (walletPayment > availableBalance) {
                document.getElementById('paymentError').textContent =
                    'مبلغ المحفظة المطلوب يتجاوز الرصيد المتاح!';
                document.getElementById('paymentError').style.display = 'block';
                document.getElementById('processPaymentBtn').disabled = true;
                return;
            }
        } else {
            walletDetails.style.display = 'none';
        }

        // التحقق من أن المبلغ المدخل لا يتجاوز المبلغ المطلوب
        if (totalEntered > totalRequired) {
            document.getElementById('paymentError').textContent =
                'المبلغ المدخل يتجاوز المبلغ المطلوب!';
            document.getElementById('paymentError').style.display = 'block';
            document.getElementById('processPaymentBtn').disabled = true;
            return;
        }

        // التحقق من أن المبلغ المدخل يساوي المبلغ المطلوب (إذا كان المستخدم يريد السداد الكامل)
        const remainingAfterPayment = totalRequired - totalEntered;
        document.getElementById('totalRemainingAfterPayment').textContent =
            remainingAfterPayment.toFixed(2) + ' ج.م';

        // إخفاء رسالة الخطأ إذا لم توجد أخطاء
        document.getElementById('paymentError').style.display = 'none';

        // تفعيل/تعطيل زر المعالجة
        document.getElementById('processPaymentBtn').disabled = totalEntered <= 0;
    },

    // resetPaymentForm() {
    //     PaymentManager.resetPaymentMethods();
    //     document.getElementById('totalRequiredAmount').textContent = '0.00 ج.م';
    //     document.getElementById('totalEnteredAmount').textContent = '0.00 ج.م';
    //     document.getElementById('walletPaymentDetails').style.display = 'none';
    //     document.getElementById('paymentError').style.display = 'none';
    //     document.getElementById('processPaymentBtn').disabled = true;
    //     document.getElementById('invoiceSearch').value = '';

    // },

    resetPaymentForm() {
        PaymentManager.resetPaymentMethods();
        document.getElementById('paymentMethodsContainer').innerHTML = '';
        this.addPaymentMethod();
        document.getElementById('totalRequiredAmount').textContent = '0.00 ج.م';
        document.getElementById('totalEnteredAmount').textContent = '0.00 ج.م';
        document.getElementById('totalRemainingAfterPayment').textContent = '0.00 ج.م';
        document.getElementById('paymentError').style.display = 'none';
        document.getElementById('processPaymentBtn').disabled = true;
    },

    processPayment() {
        const paymentType = document.querySelector('input[name="paymentType"]:checked').value;

        // جمع طرق الدفع
        const paymentMethods = [];
        document.querySelectorAll('.payment-method-item').forEach(item => {
            const methodSelect = item.querySelector('.payment-method-select');
            const amountInput = item.querySelector('.payment-method-amount');

            const methodId = parseInt(methodSelect.value);
            const amount = parseFloat(amountInput.value) || 0;

            if (methodId && amount > 0) {
                const method = PaymentMethods.find(pm => pm.id === methodId);
                if (method) {
                    paymentMethods.push({
                        method: method.name,
                        methodId: methodId,
                        amount: amount
                    });
                }
            }
        });

        if (paymentMethods.length === 0) {
            Swal.fire('تحذير', 'يرجى إدخال طرق دفع صحيحة.', 'warning');
            return;
        }

        let totalPaid = 0;

        if (paymentType === 'invoices') {
            // معالجة سداد الفواتير
            document.querySelectorAll('.invoice-payment-checkbox:checked').forEach(checkbox => {
                const invoiceId = parseInt(checkbox.getAttribute('data-invoice-id'));
                const amountInput = document.querySelector(`.invoice-payment-amount[data-invoice-id="${invoiceId}"]`);
                const amount = parseFloat(amountInput.value) || 0;

                if (amount > 0) {
                    PaymentManager.addPaymentToInvoice(invoiceId, amount, paymentMethods);
                    totalPaid += amount;
                }
            });
        } else {
            // معالجة سداد فواتير الشغلانة
            document.querySelectorAll('.workorder-invoice-payment-amount').forEach(input => {
                const amount = parseFloat(input.value) || 0;
                const invoiceId = parseInt(input.getAttribute('data-invoice-id'));

                if (amount > 0) {
                    PaymentManager.addPaymentToInvoice(invoiceId, amount, paymentMethods);
                    totalPaid += amount;
                }
            });
        }

        if (totalPaid > 0) {
            Swal.fire('نجاح', `تم سداد ${totalPaid.toFixed(2)} ج.م بنجاح.`, 'success');
            const paymentModal = bootstrap.Modal.getInstance(document.getElementById('paymentModal'));
            paymentModal.hide();

            // تحديث الواجهة
            InvoiceManager.updateInvoicesTable();
            WorkOrderManager.updateWorkOrdersTable();
            CustomerManager.updateCustomerBalance();
            updateInvoiceStats();
        } else {
            Swal.fire('تحذير', 'لم يتم تحديد أي مبالغ للدفع.', 'warning');
        }
    },

    addPaymentToInvoice(invoiceId, amount, paymentMethods) {
        const invoice = AppData.invoices.find(i => i.id === invoiceId);
        if (invoice) {
            // تحديث الفاتورة
            invoice.paid += amount;
            invoice.remaining = invoice.total - invoice.paid;

            // تحديث حالة الفاتورة
            if (invoice.remaining === 0) {
                invoice.status = 'paid';
            } else if (invoice.paid > 0) {
                invoice.status = 'partial';
            }

            // إضافة حركة للمحفظة إذا كانت هناك طريقة دفع بالمحفظة
            const walletPayment = paymentMethods.find(pm => pm.methodId === 4); // 4 هو id المحفظة
            if (walletPayment) {
                WalletManager.addTransaction({
                    type: 'payment',
                    amount: -walletPayment.amount,
                    description: `سداد للفاتورة ${invoice.number}`,
                    date: new Date().toISOString().split('T')[0],
                    paymentMethods: [walletPayment]
                });
            }
        }
    }

};
export default PaymentManager;