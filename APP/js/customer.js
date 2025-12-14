import AppData from './app_data.js';
import apis from './constant/api_links.js';

export const CustomerManager = {
    currentCustomer: null,
    
    async init(customerId = null) {
        // 1. تحديد معرف العميل
        const id = customerId || this.getCustomerIdFromURL();
       
        
        if (!id) {
            console.error("❌ Customer ID not found");
            this.showError("معرف العميل غير موجود");
            return;
        }
        
        console.log(`🔍 Fetching customer info for ID: ${id}`);
        
        // 2. جلب البيانات من API
        const result = await this.fetchCustomerInfo(id);
        
        if (result.success) {
            this.handleSuccess(result.data);
        } else {
            this.handleError(result.message);
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
    
    async fetchCustomerInfo(customerId) {
        try {
            console.log(`📡 Calling API for customer ${customerId}...`);
            
            const response = await fetch(
                `${apis.getCustomerInfo}${encodeURIComponent(customerId)}`,
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
            console.log('✅ API Response:', data);
            return data;
            
        } catch (error) {
            console.error('❌ API Error:', error);
            return {
                success: false,
                message: 'فشل في الاتصال بالخادم'
            };
        }
    },
    
    handleSuccess(apiData) {
        // 1. حفظ البيانات في AppData
        this.currentCustomer = apiData.customer;
        AppData.currentCustomer = apiData.customer;
        
        // 2. تحديث واجهة المستخدم
        this.updateCustomerInfo(apiData.customer);
        this.updateInvoiceStats(apiData.stats);
        
        // 3. تحديث بقية المانجرز
        this.updateOtherManagers(apiData);
        
        console.log('✅ Customer data loaded successfully');
    },
    
    updateCustomerInfo(customer) {
        // معلومات العميل
        document.getElementById("customerName").textContent = customer.name || 'غير محدد';
        document.getElementById("customerPhone").textContent = customer.mobile || 'غير محدد';
        
        // العنوان
        let address = customer.city || '';
        if (customer.address) {
            address += address ? ` - ${customer.address}` : customer.address;
        }
        document.getElementById("customerAddress").textContent = address || 'غير محدد';
        
        // تاريخ الانضمام
        const joinDate = customer.join_date || customer.created_at || 'غير محدد';
        document.getElementById("customerJoinDate").textContent = joinDate;
        
        // الأرصدة
        const balance = parseFloat(customer.balance) || 0;
        const wallet = parseFloat(customer.wallet) || 0;
        
        document.getElementById("currentBalance").textContent = 
            Math.abs(balance).toFixed(2);
        document.getElementById("walletBalance").textContent = 
            wallet.toFixed(2);
        
        // تحديث حالة الرصيد (مدين/دائن)
        const balanceCard = document.querySelector('.stat-card.negative');
        if (balanceCard) {
            const label = balance > 0 ? 'مدين' : 'دائن';
            const colorClass = balance > 0 ? 'text-danger' : 'text-success';
            
            balanceCard.querySelector('small').textContent = label;
            balanceCard.querySelector('small').className = colorClass;
        }
        
        // الصورة الرمزية (الأحرف الأولى)
        const avatar = document.getElementById("customerAvatar");
        if (avatar && customer.name) {
            const initials = customer.name
                .split(' ')
                .map(word => word.charAt(0))
                .join('')
                .substring(0, 2)
                .toUpperCase();
            avatar.textContent = initials;
        }
        
        // حفظ في AppData للاستخدام لاحقاً
        AppData.customerBalance = balance;
        AppData.customerWallet = wallet;
    },
    
    updateInvoiceStats(stats) {
        // تحديث الإحصائيات
        if (stats.total) {
            document.getElementById("totalInvoicesCount").textContent = 
                stats.total.count || 0;
        }
        
        if (stats.pending) {
            document.getElementById("pendingInvoicesCount").textContent = 
                stats.pending.count || 0;
        }
        
        if (stats.partial) {
            document.getElementById("partialInvoicesCount").textContent = 
                stats.partial.count || 0;
        }
        
        if (stats.paid) {
            document.getElementById("paidInvoicesCount").textContent = 
                stats.paid.count || 0;
        }
        
        if (stats.returned) {
            document.getElementById("returnedInvoicesCount").textContent = 
                stats.returned?.count || 0;
        }
        
        // تحديث المبالغ (لو عندك العناصر دي)
        this.updateStatsAmounts(stats);
    },
    
    updateStatsAmounts(stats) {
        // يمكن إضافة عناصر لعرض المبالغ
        const statCards = document.querySelectorAll('.invoice-stat-card');
        
        statCards.forEach(card => {
            const filter = card.getAttribute('data-filter');
            const amountElement = card.querySelector('.stat-amount');
            
            if (amountElement && stats[filter]) {
                const amount = stats[filter].amount || 0;
                amountElement.textContent = `${amount.toFixed(2)} ج.م`;
            }
        });
    },
    
   
    
    mapInvoiceStatus(status) {
        // تحويل status من قاعدة البيانات إلى status في JavaScript
        const statusMap = {
            'no': 'pending',
            'partial': 'partial',
            'yes': 'paid',
            'reverted': 'returned',
            'canceled': 'canceled'
        };
        
        return statusMap[status] || 'pending';
    },
    
    handleError(message) {
        console.error('❌ Customer Error:', message);
        this.showError(message || 'حدث خطأ في تحميل بيانات العميل');
        
        // استخدام بيانات وهمية للاختبار
        this.useMockData();
    },
    
    showError(message) {
        // عرض رسالة خطأ للمستخدم
        const alertDiv = document.createElement('div');
        alertDiv.className = 'alert alert-danger alert-dismissible fade show mt-3';
        alertDiv.innerHTML = `
            <i class="fas fa-exclamation-triangle me-2"></i>
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;
        
        const container = document.querySelector('.container-fluid');
        if (container) {
            container.insertBefore(alertDiv, container.firstChild);
        }
        
        // إخفاء الرسالة بعد 5 ثواني
        setTimeout(() => {
            if (alertDiv.parentNode) {
                alertDiv.remove();
            }
        }, 5000);
    },
    
    useMockData() {
        // بيانات وهمية للاختبار إذا فشل API
        console.warn('⚠️ Using mock data for testing');
        
        const mockCustomer = {
            id: 1,
            name: "محمد أحمد",
            mobile: "01234567890",
            city: "القاهرة",
            address: "المعادي",
            balance: 1200.00,
            wallet: 500.00,
            join_date: "2024-01-20"
        };
        
        const mockStats = {
            total: { count: 16, amount: 15000 },
            pending: { count: 3, amount: 2500 },
            partial: { count: 2, amount: 1200 },
            paid: { count: 10, amount: 11300 },
            returned: { count: 1, amount: 800 }
        };
        
        this.updateCustomerInfo(mockCustomer);
        this.updateInvoiceStats(mockStats);
    },
    
    // دالة مساعدة: تحديث الرصيد عند حدوث عملية
    updateBalance(amount, type = 'payment') {
        if (!this.currentCustomer) return;
        
        if (type === 'payment') {
            this.currentCustomer.balance = (parseFloat(this.currentCustomer.balance) - amount).toFixed(2);
        } else if (type === 'invoice') {
            this.currentCustomer.balance = (parseFloat(this.currentCustomer.balance) + amount).toFixed(2);
        } else if (type === 'wallet_deposit') {
            this.currentCustomer.wallet = (parseFloat(this.currentCustomer.wallet) + amount).toFixed(2);
        } else if (type === 'wallet_withdraw') {
            this.currentCustomer.wallet = (parseFloat(this.currentCustomer.wallet) - amount).toFixed(2);
        }
        
        // تحديث الواجهة
        this.updateCustomerInfo(this.currentCustomer);
    },
    
    // جلب بيانات العميل الحالي
    getCustomer() {
        return this.currentCustomer;
    }
};

// جعل CustomerManager متاحاً بشكل global
window.CustomerManager = CustomerManager;
export default CustomerManager;