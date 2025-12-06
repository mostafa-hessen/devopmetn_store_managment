

    // app_data.js
const AppData = {
    currentUser: "مدير النظام",
    customers: [],
    currentCustomer: { name: "عميل افتراضي", walletBalance: 0 },
    invoices: [],
    returns: [],
    workOrders: [],
     products: [
          { id: 1, name: "شباك ألوميتال 2×1.5", price: 800, stock: 15 },
          { id: 2, name: "باب خشب", price: 1200, stock: 8 },
          { id: 3, name: "مفصلات ستانلس", price: 150, stock: 50 },
          { id: 4, name: "أقفال أمنية", price: 300, stock: 20 },
          { id: 5, name: "زجاج عاكس", price: 400, stock: 25 },
        ],
    walletTransactions: [],
    nextReturnId: 2,
    nextInvoiceId: 126,
    nextWorkOrderId: 3,
    nextWalletTransactionId: 6,
    activeFilters: {},
    paymentMethods: [
        { id: 1, name: "نقدي", icon: "fas fa-money-bill-wave" },
        { id: 2, name: "فيزا", icon: "fas fa-credit-card" },
        { id: 3, name: "شيك", icon: "fas fa-file-invoice" },
        { id: 4, name: "محفظة", icon: "fas fa-wallet" },
        { id: 5, name: "آجل", icon: "fas fa-calendar" } // أضفنا الآجل
    ]
};
export default AppData;