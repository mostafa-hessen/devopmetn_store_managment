    const AppData = {
        currentUser: "مدير النظام", // المستخدم الحالي
        customers: [],
        currentCustomer: null,
        invoices: [],
        workOrders: [],
        walletTransactions: [],
        returns: [],
        products: [
          { id: 1, name: "شباك ألوميتال 2×1.5", price: 800, stock: 15 },
          { id: 2, name: "باب خشب", price: 1200, stock: 8 },
          { id: 3, name: "مفصلات ستانلس", price: 150, stock: 50 },
          { id: 4, name: "أقفال أمنية", price: 300, stock: 20 },
          { id: 5, name: "زجاج عاكس", price: 400, stock: 25 },
        ],
        nextInvoiceId: 124,
        nextWorkOrderId: 3,
        nextReturnId: 2,
        nextWalletTransactionId: 6,
        activeFilters: {
          dateFrom: null,
          dateTo: null,
          productSearch: null,
          invoiceType: null,
        },
      };
export default AppData;