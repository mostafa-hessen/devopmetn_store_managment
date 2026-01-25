import AppData from "./app_data.js";

const PaymentMethods = [
    { id: 1, name: "نقدي", icon: "fas fa-money-bill-wave" },
    { id: 2, name: "فيزا", icon: "fas fa-credit-card" },
    { id: 3, name: "شيك", icon: "fas fa-file-invoice" },
    { id: 4, name: "محفظة", icon: "fas fa-wallet" },
];
function setupNumberInputPrevention() {
    // اختيار جميع حقول الإدخال العددية
    const numberInputs = document.querySelectorAll('input[type="number"]');


    numberInputs.forEach(input => {
        // منع تغيير القيمة بواسطة عجلة التمرير (scroll)
        input.addEventListener('wheel', function (e) {
            e.preventDefault();

        }, { passive: false });

        // منع تغيير القيمة بواسطة السهمين لأعلى ولأسفل
        input.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowUp' || e.key === 'ArrowDown') {
                e.preventDefault();

            }

        });


    });
}
function escapeHtml(text) {
    const map = {
        "&": "&amp;",
        "<": "&lt;",
        ">": "&gt;",
        '"': "&quot;",
        "'": "&#039;",
    };
    return text?.replace(/[&<>"']/g, (m) => map[m]);
}

function updateInvoiceStats() {
    const invoices = AppData.invoices;

    const pending = invoices.filter((i) => i.status === "pending");
    const partial = invoices.filter((i) => i.status === "partial");
    const paid = invoices.filter((i) => i.status === "paid");
    const returned = invoices.filter((i) => i.status === "returned");

    // Update counts with null checks
    const totalInvoicesCount = document.getElementById("totalInvoicesCount");
    if (totalInvoicesCount) totalInvoicesCount.textContent = invoices.length;

    const pendingInvoicesCount = document.getElementById("pendingInvoicesCount");
    if (pendingInvoicesCount) pendingInvoicesCount.textContent = pending.length;

    const partialInvoicesCount = document.getElementById("partialInvoicesCount");
    if (partialInvoicesCount) partialInvoicesCount.textContent = partial.length;

    const paidInvoicesCount = document.getElementById("paidInvoicesCount");
    if (paidInvoicesCount) paidInvoicesCount.textContent = paid.length;

    const returnedInvoicesCount = document.getElementById("returnedInvoicesCount");
    if (returnedInvoicesCount) returnedInvoicesCount.textContent = returned.length;

    // تحديث المبالغ
    const allStatAmount = document.querySelector('[data-filter="all"] .stat-amount');
    if (allStatAmount) {
        allStatAmount.textContent = `${invoices
            .reduce((sum, i) => sum + i.total, 0)
            .toFixed(2)} ج.م`;
    }

    const pendingStatAmount = document.querySelector('[data-filter="pending"] .stat-amount');
    if (pendingStatAmount) {
        pendingStatAmount.textContent = `${pending
            .reduce((sum, i) => sum + i.total, 0)
            .toFixed(2)} ج.م`;
    }

    // document.querySelector(
    //     '[data-filter="partial"] .stat-amount'
    // ).textContent = `${partial
    //     .reduce((sum, i) => sum + i.total, 0)
    //     .toFixed(2)} ج.م`;

    const paidStatAmount = document.querySelector('[data-filter="paid"] .stat-amount');
    if (paidStatAmount) {
        paidStatAmount.textContent = `${paid
            .reduce((sum, i) => sum + i.total, 0)
            .toFixed(2)} ج.م`;
    }

    const returnedStatAmount = document.querySelector('[data-filter="returned"] .stat-amount');
    if (returnedStatAmount) {
        returnedStatAmount.textContent = `${returned
            .reduce((sum, i) => sum + i.total, 0)
            .toFixed(2)} ج.م`;
    }
}

function getCustomerIdFromURL() {
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
}


function

    splitDateTime(datetime) {
    if (!datetime || !datetime.includes(" ")) {
        return { date: '', time: '' };
    }
    const [date, time] = datetime.split(" ");
    return { date, time };
}
function toggleSection(sectionId, buttonElement) {

    const section = document.getElementById(sectionId);
    if (!section) return;

    const isHidden = section.classList.contains('collapse-section');

    section.classList.toggle('collapse-section');
    buttonElement.innerHTML = `
    <i class="fas ${!isHidden ? 'fa-chevron-down' : 'fa-chevron-up'} me-1"></i>
    ${!isHidden ? 'إظهار' : 'إخفاء'} 
    ${sectionId === 'invoice-payment' ? 'الفواتير' : 'فواتير الشغلانه'}
  `;
}



export { setupNumberInputPrevention, escapeHtml, updateInvoiceStats, getCustomerIdFromURL, splitDateTime, PaymentMethods, toggleSection };


