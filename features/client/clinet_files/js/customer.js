import AppData from './app_data.js';

      const CustomerManager = {
        init() {
          // بيانات العميل الحالي
          AppData.currentCustomer = {
            id: 1,
            name: "محمد أحمد",
            phone: "01234567890",
            address: "القاهرة - المعادي",
            joinDate: "2024-01-20",
            walletBalance: 500,
          };

          AppData.customers.push(AppData.currentCustomer);
          this.updateCustomerInfo();
        },

        updateCustomerInfo() {
          const customer = AppData.currentCustomer;
          document.getElementById("customerName").textContent = customer.name;
          document.getElementById("customerPhone").textContent = customer.phone;
          document.getElementById("customerAddress").textContent =
            customer.address;
          document.getElementById("customerJoinDate").textContent =
            customer.joinDate;
          document.getElementById("walletBalance").textContent =
            customer.walletBalance.toFixed(2);

          // تحديث الصورة الرمزية بناءً على الاسم
          const avatar = document.getElementById("customerAvatar");
          avatar.textContent = customer.name
            .split(" ")
            .map((n) => n[0])
            .join("")
            .substring(0, 2);

          this.updateCustomerBalance();
        },

        updateCustomerBalance() {
          // حساب الرصيد الحالي (إجمالي الفواتير - المسدد - المرتجعات)
          const totalInvoices = AppData.invoices.reduce((sum, i) => {
            const invoiceTotal = i.total_before_discount || i.total || 0;
            return sum + invoiceTotal;
          }, 0);

          const totalPaid = AppData.invoices.reduce(
            (sum, i) => sum + (i.paid || 0),
            0
          );
          const totalReturns = AppData.returns.reduce(
            (sum, r) => sum + (r.amount || 0),
            0
          );

          const currentBalance = totalInvoices - totalPaid;

          document.getElementById("currentBalance").textContent =
            currentBalance.toFixed(2);
        },
      };
        export default CustomerManager;