<?php
require_once '../config.php';
require_once '../partials/session_admin.php';

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// إذا كان طلب AJAX، توجيه للمعالج
if (isset($_GET['ajax']) || isset($_POST['ajax'])) {
    require_once __DIR__ . '/includes/api_handler.php';
    exit;
}

// عرض الصفحة العادية
$page_title = "إدارة فواتير المشتريات";
require_once '../partials/header.php';
require_once '../partials/sidebar.php';

// فلترة بسيطة
$supplier_id = $_GET['supplier'] ?? '';
$status = $_GET['status'] ?? '';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl" data-app data-theme="light">
<head>
    <title>نظام إدارة المشتريات والمرتجعات</title>
    
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        .search-highlight {
            background-color: #fff3cd;
            font-weight: bold;
        }
        .filter-tags {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 16px;
        }
        .filter-tag {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 8px;
            background: #e5e7eb;
            border-radius: 16px;
            font-size: 14px;
        }
        .filter-tag .remove {
            cursor: pointer;
            color: #6b7280;
        }
        .filter-tag .remove:hover {
            color: #374151;
        }
        .loading-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255, 255, 255, 0.8);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }
        .search-input-with-icon {
            position: relative;
        }
        .search-input-with-icon input {
            padding-right: 40px;
        }
        .search-input-with-icon i {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #6b7280;
        }
        .invoice-status-indicator {
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .invoice-status-indicator .dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
        }
        .invoice-status-indicator .dot.pending { background: #f59e0b; }
        .invoice-status-indicator .dot.received { background: #10b981; }
        .invoice-status-indicator .dot.cancelled { background: #ef4444; }
        .invoice-status-indicator .dot.partial { background: #8b5cf6; }
        
        /* Modal fixes */
        .modal {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            display: none;
            align-items: flex-start; /* Changed from center to flex-start */
            justify-content: center;
            z-index: 1100;
            overflow-y: auto; /* Enable vertical scrolling */
            padding: 20px 0; /* Add padding top and bottom */
        }
        
        .modal.active {
            display: flex;
        }
        
        .modal-content {
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
            max-width: 90%;
            width: 900px;
            max-height: 85vh; /* Limit height */
            display: flex;
            flex-direction: column;
            margin-top: 20px; /* Add margin from top */
            margin-bottom: 20px; /* Add margin at bottom */
        }
        
        .modal-header {
            padding: 20px;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-shrink: 0;
        }
        
        .modal-body {
            padding: 20px;
            overflow-y: auto; /* Enable scrolling in body */
            flex-grow: 1;
        }
        
        .modal-footer {
            padding: 20px;
            border-top: 1px solid #e5e7eb;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            flex-shrink: 0;
        }
        
        .modal-close {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: #6b7280;
        }
        
        .modal-close:hover {
            color: #374151;
        }
        
        /* Ensure sidebar doesn't interfere */
        .sidebar {
            z-index: 1000;
        }
        
        .app-container {
            position: relative;
            z-index: 1;
        }
        
        /* Print styles */
        @media print {
            body * {
                visibility: hidden;
            }
            .modal,
            .modal * {
                visibility: visible;
            }
            .modal {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
                height: 100%;
                background: white;
                display: block !important;
                overflow: visible;
            }
            .modal-content {
                box-shadow: none;
                border: none;
                max-width: 100%;
                width: 100%;
                margin: 0;
            }
            .modal-footer,
            .modal-close {
                display: none !important;
            }
        }
    </style>
    <link rel="stylesheet" href="./css/main.css">
</head>
<body>
    <div class="app-container m-2">
        <!-- ==================== HEADER ==================== -->
        <header class="app-header">
            <div class="header-brand">
                <h1>
                    <i class="fas fa-shopping-cart"></i>
                    نظام إدارة المشتريات والمرتجعات
                </h1>
            </div>

            <!-- Header Actions -->
            <div class="header-actions">
                <button class="btn btn-primary" onclick="PurchaseManager.openNewInvoiceModal()">
                    <i class="fas fa-plus"></i>
                    فاتورة شراء جديدة
                </button>
                <button class="btn btn-warning" onclick="UIManager.switchTab('returns')">
                    <i class="fas fa-exchange-alt"></i>
                    إدارة المرتجعات
                </button>
            </div>
        </header>

        <!-- ==================== MAIN CONTENT ==================== -->
        <main class="main-content-container">
            <!-- Stats Cards -->
            <div class="stats-grid" id="statsCards">
                <!-- سيتم تعبئته بواسطة JavaScript -->
            </div>

            <!-- Tabs -->
            <div class="tabs-container">
                <div class="tabs">
                    <button class="tab active" onclick="UIManager.switchTab('invoices')">
                        <i class="fas fa-file-invoice-dollar"></i>
                        فواتير المشتريات
                        <span class="stats-badge info" id="invoicesCountBadge">0</span>
                    </button>
                    <button class="tab" onclick="UIManager.switchTab('returns')">
                        <i class="fas fa-exchange-alt"></i>
                        المرتجعات
                        <span class="stats-badge warning" id="returnsCountBadge">0</span>
                    </button>
                </div>
            </div>

            <!-- ==================== INVOICES TAB ==================== -->
            <div id="invoicesTab" class="tab-content">
                <!-- Filter Tags -->
                <div class="filter-tags" id="invoicesFilterTags"></div>

                <!-- Invoices Filters -->
                <div class="filters-card">
                    <div class="filter-grid">
                        <div class="filter-group">
                            <label>اسم المورد</label>
                            <div class="search-input-with-icon">
                                <input type="text" class="form-input" id="supplierNameFilter" 
                                       placeholder="ابحث باسم المورد..."
                                       onkeyup="PurchaseManager.searchSupplier(event)">
                                <i class="fas fa-search"></i>
                            </div>
                        </div>
                        <div class="filter-group">
                            <label>رقم الفاتورة</label>
                            <div class="search-input-with-icon">
                                <input type="number" class="form-input" id="invoiceIdFilter" placeholder="رقم الفاتورة">
                                <i class="fas fa-search"></i>
                            </div>
                        </div>
                        <div class="filter-group">
                            <label>من تاريخ</label>
                            <input type="date" class="form-input" id="dateFromFilter" 
                                   onchange="PurchaseManager.updateDateFilter()">
                        </div>
                        <div class="filter-group">
                            <label>إلى تاريخ</label>
                            <input type="date" class="form-input" id="dateToFilter"
                                   onchange="PurchaseManager.updateDateFilter()">
                        </div>
                        <div class="filter-group">
                            <label>الحالة</label>
                            <select class="form-select" id="statusFilter">
                                <option value="">كل الحالات</option>
                                <option value="pending">قيد الانتظار</option>
                                <option value="partial_received">مستلمة جزئياً</option>
                                <option value="fully_received">تم الاستلام</option>
                                <option value="cancelled">ملغاة</option>
                            </select>
                        </div>
                        <div class="filter-actions">
                            <button class="btn btn-primary" onclick="PurchaseManager.filterInvoices()">
                                <i class="fas fa-search"></i>
                                بحث
                            </button>
                            <button class="btn btn-light" onclick="PurchaseManager.resetFilters()">
                                <i class="fas fa-redo"></i>
                                تصفير
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- Summary Stats -->
                <div class="stats-grid" id="invoicesSummaryStats">
                    <!-- سيتم تعبئته بواسطة JavaScript -->
                </div>
                
                <!-- Invoices Table -->
                <div class="table-container">
                    <div class="table-header">
                        <h3><i class="fas fa-file-invoice-dollar"></i> فواتير المشتريات</h3>
                        <div class="table-actions">
                            <button class="btn btn-light btn-sm" onclick="ExportManager.exportInvoices()">
                                <i class="fas fa-download"></i>
                                تصدير البيانات
                            </button>
                            <button class="btn btn-light btn-sm" onclick="PurchaseManager.refreshInvoices()">
                                <i class="fas fa-sync-alt"></i>
                                تحديث
                            </button>
                        </div>
                    </div>
                    <div class="table-wrapper" style="position: relative; min-height: 200px;">
                        <div id="invoicesTableLoading" class="loading-overlay" style="display: none;">
                            <div class="spinner-border text-primary" role="status">
                                <span class="visually-hidden">جاري التحميل...</span>
                            </div>
                        </div>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>المورد</th>
                                    <th>تاريخ الشراء</th>
                                    <th>رقم فاتورة المورد</th>
                                    <th>الحالة</th>
                                    <th>الكميات</th>
                                    <th>الإجمالي</th>
                                    <th>المرتجعات</th>
                                    <th>الإجراءات</th>
                                </tr>
                            </thead>
                            <tbody id="invoicesTable">
                                <!-- سيتم تعبئته بواسطة JavaScript -->
                            </tbody>
                        </table>
                    </div>
                    <div class="table-footer">
                        <div class="pagination" id="invoicesPagination">
                            <!-- سيتم تعبئته بواسطة JavaScript -->
                        </div>
                    </div>
                </div>
            </div>

            <!-- ==================== RETURNS TAB ==================== -->
            <div id="returnsTab" class="tab-content hidden">
                <!-- Filter Tags -->
                <div class="filter-tags" id="returnsFilterTags"></div>

                <!-- Returns Filters -->
                <div class="filters-card">
                    <div class="filter-grid">
                        <div class="filter-group">
                            <label>اسم المورد</label>
                            <div class="search-input-with-icon">
                                <input type="text" class="form-input" id="returnSupplierNameFilter" 
                                       placeholder="ابحث باسم المورد..."
                                       onkeyup="ReturnManager.searchSupplier(event)">
                                <i class="fas fa-search"></i>
                            </div>
                        </div>
                        <div class="filter-group">
                            <label>رقم الفاتورة الأصلية</label>
                            <input type="number" class="form-input" id="returnOriginalInvoiceFilter" placeholder="رقم الفاتورة">
                        </div>
                        <div class="filter-group">
                            <label>نوع المرتجع</label>
                            <select class="form-select" id="returnTypeFilter">
                                <option value="">كل الأنواع</option>
                                <option value="supplier_return">إرجاع للمورد</option>
                                <option value="damaged">تلف في المخزن</option>
                                <option value="expired">منتهي الصلاحية</option>
                                <option value="wrong_item">منتج خاطئ</option>
                                <option value="excess">كمية زائدة</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label>حالة المرتجع</label>
                            <select class="form-select" id="returnStatusFilter">
                                <option value="">كل الحالات</option>
                                <option value="pending">قيد المعالجة</option>
                                <option value="completed">مكتمل</option>
                                <option value="cancelled">ملغي</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label>من تاريخ</label>
                            <input type="date" class="form-input" id="returnDateFrom"
                                   onchange="ReturnManager.updateDateFilter()">
                        </div>
                        <div class="filter-group">
                            <label>إلى تاريخ</label>
                            <input type="date" class="form-input" id="returnDateTo"
                                   onchange="ReturnManager.updateDateFilter()">
                        </div>
                        <div class="filter-actions">
                            <button class="btn btn-primary" onclick="ReturnManager.filterReturns()">
                                <i class="fas fa-search"></i>
                                بحث
                            </button>
                            <button class="btn btn-light" onclick="ReturnManager.resetFilters()">
                                <i class="fas fa-redo"></i>
                                تصفير
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Returns Table -->
                <div class="table-container">
                    <div class="table-header">
                        <h3><i class="fas fa-exchange-alt"></i> المرتجعات</h3>
                        <div class="table-actions">
                            <button class="btn btn-primary btn-sm" onclick="ReturnManager.openNewReturnModal()">
                                <i class="fas fa-plus"></i>
                                إضافة مرتجع
                            </button>
                            <button class="btn btn-light btn-sm" onclick="ReturnManager.refreshReturns()">
                                <i class="fas fa-sync-alt"></i>
                                تحديث
                            </button>
                        </div>
                    </div>
                    <div class="table-wrapper" style="position: relative; min-height: 200px;">
                        <div id="returnsTableLoading" class="loading-overlay" style="display: none;">
                            <div class="spinner-border text-primary" role="status">
                                <span class="visually-hidden">جاري التحميل...</span>
                            </div>
                        </div>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>رقم المرتجع</th>
                                    <th>الفاتورة الأصلية</th>
                                    <th>المورد</th>
                                    <th>نوع المرتجع</th>
                                    <th>تاريخ المرتجع</th>
                                    <th>الكمية</th>
                                    <th>القيمة</th>
                                    <th>الحالة</th>
                                    <th>الإجراءات</th>
                                </tr>
                            </thead>
                            <tbody id="returnsTable">
                                <!-- سيتم تعبئته بواسطة JavaScript -->
                            </tbody>
                        </table>
                    </div>
                    <div class="table-footer">
                        <div class="pagination" id="returnsPagination">
                            <!-- سيتم تعبئته بواسطة JavaScript -->
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- ==================== MODALS ==================== -->
    
    <!-- View Invoice Modal -->
    <div class="modal" id="viewInvoiceModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-file-invoice-dollar"></i> تفاصيل الفاتورة</h2>
                <button class="modal-close" onclick="UIManager.closeModal('viewInvoiceModal')">×</button>
            </div>
            <div class="modal-body" id="invoiceDetails">
                <!-- سيتم تعبئته بواسطة JavaScript -->
            </div>
            <div class="modal-footer" id="invoiceModalFooter">
                <button class="btn btn-primary" onclick="PurchaseManager.printInvoice()">
                    <i class="fas fa-print"></i>
                    طباعة A4
                </button>
                <button class="btn btn-secondary" onclick="PurchaseManager.printInvoicePOS()">
                    <i class="fas fa-receipt"></i>
                    طباعة POS
                </button>
                <button class="btn btn-light" onclick="UIManager.closeModal('viewInvoiceModal')">
                    إغلاق
                </button>
            </div>
        </div>
    </div>

    <!-- Edit Invoice Modal -->
    <div class="modal" id="editInvoiceModal">
        <div class="modal-content">
            <div class="modal-header" style="background: var(--grad-2);">
                <h2><i class="fas fa-edit"></i> تعديل بنود الفاتورة <span id="edit_inv_id"></span></h2>
                <button class="modal-close" onclick="UIManager.closeModal('editInvoiceModal')">×</button>
            </div>
            <div class="modal-body" id="editInvoiceBody">
                <!-- سيتم تعبئته بواسطة JavaScript -->
            </div>
            <div class="modal-footer">
                <button id="btn_save_edit" class="btn btn-success">
                    <i class="fas fa-save"></i>
                    حفظ التعديلات
                </button>
                <button class="btn btn-light" onclick="UIManager.closeModal('editInvoiceModal')">
                    إلغاء
                </button>
            </div>
        </div>
    </div>

    <!-- Cancel Invoice Modal -->
    <div class="modal" id="cancelInvoiceModal">
        <div class="modal-content">
            <div class="modal-header" style="background: var(--grad-4);">
                <h2><i class="fas fa-times-circle"></i> إلغاء الفاتورة</h2>
                <button class="modal-close" onclick="UIManager.closeModal('cancelInvoiceModal')">×</button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="detail-label">رقم الفاتورة</label>
                    <div class="detail-value" id="cancelInvoiceId"></div>
                </div>
                <div class="mb-3">
                    <label class="detail-label">السبب</label>
                    <textarea id="cancelReason" rows="4" class="edit-input" 
                              placeholder="أدخل سبب الإلغاء (مطلوب)"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-danger" onclick="PurchaseManager.confirmCancel()">
                    <i class="fas fa-check"></i>
                    تأكيد الإلغاء
                </button>
                <button class="btn btn-light" onclick="UIManager.closeModal('cancelInvoiceModal')">
                    إلغاء
                </button>
            </div>
        </div>
    </div>

    <!-- Receive Invoice Modal -->
    <div class="modal" id="receiveInvoiceModal">
        <div class="modal-content">
            <div class="modal-header" style="background: var(--grad-success);">
                <h2><i class="fas fa-check-circle"></i> استلام الفاتورة</h2>
                <button class="modal-close" onclick="UIManager.closeModal('receiveInvoiceModal')">×</button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="detail-label">رقم الفاتورة</label>
                    <div class="detail-value" id="receiveInvoiceId"></div>
                </div>
                <div class="mb-3">
                    <p>هل تريد تأكيد استلام الفاتورة بالكامل؟</p>
                    <p class="text-muted">سيتم إنشاء دفعات للمنتجات وتحديث المخزون.</p>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-success" onclick="PurchaseManager.confirmReceive()">
                    <i class="fas fa-check"></i>
                    تأكيد الاستلام
                </button>
                <button class="btn btn-light" onclick="UIManager.closeModal('receiveInvoiceModal')">
                    إلغاء
                </button>
            </div>
        </div>
    </div>

    <!-- View Return Details Modal -->
    <div class="modal" id="viewReturnModal">
        <div class="modal-content">
            <div class="modal-header" style="background: var(--grad-4);">
                <h2><i class="fas fa-exchange-alt"></i> تفاصيل المرتجع</h2>
                <button class="modal-close" onclick="UIManager.closeModal('viewReturnModal')">×</button>
            </div>
            <div class="modal-body" id="returnDetails">
                <!-- سيتم تعبئته بواسطة JavaScript -->
            </div>
            <div class="modal-footer">
                <button class="btn btn-light" onclick="UIManager.closeModal('viewReturnModal')">
                    إغلاق
                </button>
            </div>
        </div>
    </div>

    <!-- New Return Modal -->
    <div class="modal" id="newReturnModal">
        <div class="modal-content">
            <div class="modal-header" style="background: var(--grad-3);">
                <h2><i class="fas fa-plus-circle"></i> إنشاء مرتجع جديد</h2>
                <button class="modal-close" onclick="ReturnManager.closeReturnModal()">×</button>
            </div>
            <div class="modal-body" id="newReturnBody">
                <!-- سيتم تعبئته بواسطة JavaScript -->
            </div>
        </div>
    </div>

    <!-- ==================== SCRIPTS ==================== -->
    <script>
        // ==================== CONSTANTS & CONFIG ====================
        const API_BASE_URL =  'store_v1/api/purchase/api_purchase_invoices.php';
        const CSRF_TOKEN = '<?php echo $_SESSION['csrf_token']; ?>';
        
        const STATUS_LABELS = {
            pending: 'قيد الانتظار',
            partial_received: 'مستلمة جزئياً',
            fully_received: 'تم الاستلام',
            cancelled: 'ملغاة'
        };

        const STATUS_COLORS = {
            pending: '#f59e0b',
            partial_received: '#8b5cf6',
            fully_received: '#10b981',
            cancelled: '#ef4444'
        };

        const RETURN_TYPES = {
            supplier_return: 'إرجاع للمورد',
            damaged: 'تلف في المخزن',
            expired: 'منتهي الصلاحية',
            wrong_item: 'منتج خاطئ',
            excess: 'كمية زائدة'
        };

        // ==================== API MANAGER ====================
        const APIManager = {
            async callAPI(action, params = {}, method = 'GET') {
                const url = new URL(API_BASE_URL, window.location.origin);
                url.searchParams.append('action', action);
                
                for (const [key, value] of Object.entries(params)) {
                    if (value !== undefined && value !== null && value !== '') {
                        url.searchParams.append(key, value);
                    }
                }

                const headers = {
                    'X-CSRF-Token': CSRF_TOKEN
                };

                let options = {
                    method: method,
                    headers: headers
                };

                if (method === 'POST') {
                    headers['Content-Type'] = 'application/json';
                    options.body = JSON.stringify(params);
                }

                try {
                    const response = await fetch(url, options);
                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }
                    return await response.json();
                } catch (error) {
                    console.error('API Error:', error);
                    throw error;
                }
            },

            async fetchInvoices(filters = {}) {
                const params = {};
                if (filters.supplierName) params.supplier_name = filters.supplierName;
                if (filters.invoiceId) params.invoice_out_id = filters.invoiceId;
                if (filters.dateFrom) params.date_from = filters.dateFrom;
                if (filters.dateTo) params.date_to = filters.dateTo;
                if (filters.status) params.status_filter_val = filters.status;
                if (filters.page) params.page = filters.page;
                if (filters.limit) params.limit = filters.limit;
                
                return await this.callAPI('list_invoices', params);
            },

            async fetchInvoiceDetails(invoiceId) {
                return await this.callAPI('fetch_invoice', { id: invoiceId });
            },

            async fetchSuppliers() {
                return await this.callAPI('suppliers');
            },

            async fetchStatistics() {
                return await this.callAPI('statistics');
            },

            async receiveInvoice(invoiceId) {
                return await this.callAPI('receive_invoice', { 
                    invoice_id: invoiceId,
                    action: 'receive_invoice'
                }, 'POST');
            },

            async revertInvoice(invoiceId, reason) {
                return await this.callAPI('revert_invoice', {
                    invoice_id: invoiceId,
                    reason: reason,
                    action: 'revert_invoice'
                }, 'POST');
            },

            async cancelInvoice(invoiceId, reason) {
                return await this.callAPI('cancel_invoice', {
                    invoice_id: invoiceId,
                    reason: reason,
                    action: 'cancel_invoice'
                }, 'POST');
            },

            async deleteInvoiceItem(invoiceId, itemId, reason) {
                return await this.callAPI('delete_item', {
                    invoice_id: invoiceId,
                    item_id: itemId,
                    reason: reason,
                    action: 'delete_item'
                }, 'POST');
            },

            async editInvoiceItems(invoiceId, items, adjustReason) {
                return await this.callAPI('edit_invoice', {
                    invoice_id: invoiceId,
                    items: items,
                    adjust_reason: adjustReason,
                    action: 'edit_invoice'
                }, 'POST');
            }
        };

        // ==================== UI MANAGER ====================
        const UIManager = {
            currentLoader: null,
            activeFilters: {
                invoices: {},
                returns: {}
            },

            showLoading(containerId) {
                const container = document.getElementById(containerId);
                if (container) {
                    container.style.display = 'flex';
                }
            },

            hideLoading(containerId) {
                const container = document.getElementById(containerId);
                if (container) {
                    container.style.display = 'none';
                }
            },

            switchTab(tabName) {
                document.querySelectorAll('.tab-content').forEach(tab => {
                    tab.classList.add('hidden');
                });
                document.querySelectorAll('.tab').forEach(tab => {
                    tab.classList.remove('active');
                });
                
                const tabElement = document.getElementById(tabName + 'Tab');
                if (tabElement) tabElement.classList.remove('hidden');
                
                const tabButton = document.querySelector(`.tab[onclick*="${tabName}"]`);
                if (tabButton) tabButton.classList.add('active');
            },

            openModal(modalId) {
                const modal = document.getElementById(modalId);
                if (modal) {
                    modal.classList.add('active');
                    document.body.style.overflow = 'hidden';
                }
            },

            closeModal(modalId) {
                const modal = document.getElementById(modalId);
                if (modal) {
                    modal.classList.remove('active');
                    document.body.style.overflow = 'auto';
                }
            },

            showLoader(title = 'جاري التحميل...', text = 'يرجى الانتظار') {
                this.hideLoader();
                this.currentLoader = Swal.fire({
                    title: title,
                    text: text,
                    allowOutsideClick: false,
                    showConfirmButton: false,
                    willOpen: () => {
                        Swal.showLoading();
                    }
                });
            },

            hideLoader() {
                if (this.currentLoader) {
                    Swal.close();
                    this.currentLoader = null;
                }
            },

            showSuccess(message, title = 'نجاح') {
                return Swal.fire({
                    title: title,
                    text: message,
                    icon: 'success',
                    confirmButtonText: 'موافق',
                    confirmButtonColor: '#10b981',
                    timer: 3000
                });
            },

            showError(message, title = 'خطأ') {
                return Swal.fire({
                    title: title,
                    text: message,
                    icon: 'error',
                    confirmButtonText: 'موافق',
                    confirmButtonColor: '#ef4444'
                });
            },

            showConfirm(message, title = 'تأكيد') {
                return Swal.fire({
                    title: title,
                    text: message,
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: 'نعم',
                    cancelButtonText: 'لا',
                    confirmButtonColor: '#0b84ff',
                    cancelButtonColor: '#6b7280'
                });
            },

            formatCurrency(amount) {
                return new Intl.NumberFormat('ar-EG', {
                    style: 'currency',
                    currency: 'EGP',
                    minimumFractionDigits: 2
                }).format(amount);
            },

            formatDate(dateString) {
                if (!dateString) return '-';
                const date = new Date(dateString);
                return date.toLocaleDateString('ar-EG');
            },

            formatDateTime(dateString) {
                if (!dateString) return '-';
                const date = new Date(dateString);
                return date.toLocaleString('ar-EG');
            },

            updateFilterTags(tab, filters) {
                const tagsContainer = document.getElementById(tab + 'FilterTags');
                if (!tagsContainer) return;

                tagsContainer.innerHTML = '';
                
                Object.entries(filters).forEach(([key, value]) => {
                    if (value && value !== '') {
                        let label = '';
                        let displayValue = value;
                        
                        switch(key) {
                            case 'supplierName':
                                label = 'اسم المورد';
                                break;
                            case 'status':
                                label = 'الحالة';
                                displayValue = STATUS_LABELS[value] || value;
                                break;
                            case 'invoiceId':
                                label = 'رقم الفاتورة';
                                break;
                            case 'dateFrom':
                                label = 'من تاريخ';
                                break;
                            case 'dateTo':
                                label = 'إلى تاريخ';
                                break;
                            case 'returnType':
                                label = 'نوع المرتجع';
                                displayValue = RETURN_TYPES[value] || value;
                                break;
                            case 'returnStatus':
                                label = 'حالة المرتجع';
                                break;
                            default:
                                label = key;
                        }
                        
                        const tag = document.createElement('div');
                        tag.className = 'filter-tag';
                        tag.innerHTML = `
                            <span>${label}: ${displayValue}</span>
                            <span class="remove" onclick="removeFilter('${tab}', '${key}')">×</span>
                        `;
                        tagsContainer.appendChild(tag);
                    }
                });
            },

            updateSummaryStats(stats, containerId) {
                const container = document.getElementById(containerId);
                if (!container) return;

                container.innerHTML = `
                    <div class="stat-card">
                        <h3>عدد الفواتير</h3>
                        <div class="stat-value">${stats.total_invoices || 0}</div>
                    </div>
                    <div class="stat-card">
                        <h3>المجموع المعروض</h3>
                        <div class="stat-value">${this.formatCurrency(stats.displayed_sum || 0)}</div>
                    </div>
                    <div class="stat-card">
                        <h3>المجموع الكلي</h3>
                        <div class="stat-value">${this.formatCurrency(stats.grand_total_all || 0)}</div>
                    </div>
                    <div class="stat-card">
                        <h3>متوسط قيمة الفاتورة</h3>
                        <div class="stat-value">
                            ${stats.total_invoices > 0 ? this.formatCurrency((stats.displayed_sum || 0) / stats.total_invoices) : '0.00 ج.م'}
                        </div>
                    </div>
                `;
            },

            renderPagination(totalItems, currentPage, pageSize, containerId, callback) {
                const container = document.getElementById(containerId);
                if (!container) return;

                const totalPages = Math.ceil(totalItems / pageSize);
                if (totalPages <= 1) {
                    container.innerHTML = '';
                    return;
                }

                let html = `
                    <button class="btn btn-light btn-sm" ${currentPage === 1 ? 'disabled' : ''} 
                            onclick="${callback}(${currentPage - 1})">
                        <i class="fas fa-chevron-right"></i>
                        السابق
                    </button>
                    <div class="pagination-numbers">
                `;

                const maxVisible = 5;
                let startPage = Math.max(1, currentPage - Math.floor(maxVisible / 2));
                let endPage = Math.min(totalPages, startPage + maxVisible - 1);

                if (endPage - startPage + 1 < maxVisible) {
                    startPage = Math.max(1, endPage - maxVisible + 1);
                }

                if (startPage > 1) {
                    html += `<button class="btn btn-light btn-sm" onclick="${callback}(1)">1</button>`;
                    if (startPage > 2) html += '<span>...</span>';
                }

                for (let i = startPage; i <= endPage; i++) {
                    html += `
                        <button class="btn btn-sm ${i === currentPage ? 'btn-primary' : 'btn-light'}" 
                                onclick="${callback}(${i})">
                            ${i}
                        </button>
                    `;
                }

                if (endPage < totalPages) {
                    if (endPage < totalPages - 1) html += '<span>...</span>';
                    html += `<button class="btn btn-light btn-sm" onclick="${callback}(${totalPages})">${totalPages}</button>`;
                }

                html += `
                    </div>
                    <button class="btn btn-light btn-sm" ${currentPage === totalPages ? 'disabled' : ''} 
                            onclick="${callback}(${currentPage + 1})">
                        التالي
                        <i class="fas fa-chevron-left"></i>
                    </button>
                `;

                container.innerHTML = html;
            }
        };

        // ==================== PURCHASE MANAGER ====================
        const PurchaseManager = {
            currentInvoiceId: null,
            currentPage: 1,
            pageSize: 10,
            totalInvoices: 0,
            currentInvoiceData: null,

            async init() {
                await this.loadStatistics();
                await this.loadInvoices();
                this.setupEventListeners();
            },

            async loadStatistics() {
                try {
                    const result = await APIManager.fetchStatistics();
                    if (result.success) {
                        this.renderStatsCards(result.statistics);
                    }
                } catch (error) {
                    console.error('Error loading statistics:', error);
                }
            },

            renderStatsCards(stats) {
                const container = document.getElementById('statsCards');
                if (!container) return;

                let totalAmount = 0;
                let pendingCount = 0;
                let byStatus = {};

                if (stats.by_status) {
                    stats.by_status.forEach(item => {
                        if (item.status !== 'cancelled') {
                            totalAmount += item.amount;
                        }
                        if (item.status === 'pending') {
                            pendingCount = item.count;
                        }
                        byStatus[item.status] = item;
                    });
                }

                container.innerHTML = `
                    <div class="stat-card">
                        <h3>إجمالي المشتريات</h3>
                        <div class="stat-value" id="totalPurchases">${UIManager.formatCurrency(totalAmount)}</div>
                        <div class="stat-change positive">
                            <i class="fas fa-chart-line"></i>
                            إجمالي الفواتير
                        </div>
                    </div>
                    <div class="stat-card">
                        <h3>فواتير قيد الانتظار</h3>
                        <div class="stat-value" id="pendingInvoices">${pendingCount} فواتير</div>
                        <div class="stat-change ${pendingCount > 0 ? 'negative' : 'positive'}">
                            <i class="fas ${pendingCount > 0 ? 'fa-exclamation-triangle' : 'fa-check-circle'}"></i>
                            ${pendingCount > 0 ? 'تحتاج للمتابعة' : 'جميع الفواتير معالجة'}
                        </div>
                    </div>
                    <div class="stat-card">
                        <h3>فواتير ملغاة</h3>
                        <div class="stat-value" id="cancelledInvoices">${byStatus.cancelled ? byStatus.cancelled.count : 0} فواتير</div>
                        <div class="stat-change">
                            <i class="fas fa-ban"></i>
                            إجمالي الفواتير الملغاة
                        </div>
                    </div>
                    <div class="stat-card">
                        <h3>متوسط قيمة الفاتورة</h3>
                        <div class="stat-value" id="averageInvoiceValue">
                            ${UIManager.formatCurrency(totalAmount / Math.max(1, (stats.by_status ? stats.by_status.reduce((sum, item) => sum + item.count, 0) : 0)))}
                        </div>
                        <div class="stat-change positive">
                            <i class="fas fa-calculator"></i>
                            متوسط القيمة
                        </div>
                    </div>
                `;
            },

            setupEventListeners() {
                // Set default dates
                const today = new Date();
                const firstDayOfMonth = new Date(today.getFullYear(), today.getMonth(), 1);
                
                document.getElementById('dateFromFilter').value = this.formatDateForInput(firstDayOfMonth);
                document.getElementById('dateToFilter').value = this.formatDateForInput(today);
                
                // Set default dates for returns too
                document.getElementById('returnDateFrom').value = this.formatDateForInput(firstDayOfMonth);
                document.getElementById('returnDateTo').value = this.formatDateForInput(today);
            },

            formatDateForInput(date) {
                return date.toISOString().split('T')[0];
            },

            async loadInvoices(page = 1) {
                this.currentPage = page;
                UIManager.showLoading('invoicesTableLoading');
                
                try {
                    // Build filters from form
                    const filters = {
                        page: page,
                        limit: this.pageSize,
                        supplierName: document.getElementById('supplierNameFilter').value,
                        invoiceId: document.getElementById('invoiceIdFilter').value,
                        dateFrom: document.getElementById('dateFromFilter').value,
                        dateTo: document.getElementById('dateToFilter').value,
                        status: document.getElementById('statusFilter').value
                    };

                    const result = await APIManager.fetchInvoices(filters);
                    
                    if (result.success) {
                        this.totalInvoices = result.statistics.total_invoices;
                        this.renderInvoices(result.invoices);
                        UIManager.updateSummaryStats(result.statistics, 'invoicesSummaryStats');
                        UIManager.renderPagination(this.totalInvoices, page, this.pageSize, 'invoicesPagination', 'PurchaseManager.loadInvoices');
                        UIManager.updateFilterTags('invoices', filters);
                        
                        // Update badge count
                        const badge = document.getElementById('invoicesCountBadge');
                        if (badge) {
                            badge.textContent = this.totalInvoices;
                        }
                    } else {
                        UIManager.showError(result.message || 'فشل تحميل الفواتير');
                    }
                } catch (error) {
                    console.error('Error loading invoices:', error);
                    UIManager.showError('حدث خطأ أثناء تحميل الفواتير');
                } finally {
                    UIManager.hideLoading('invoicesTableLoading');
                }
            },

            renderInvoices(invoices) {
                const tbody = document.getElementById('invoicesTable');
                if (!tbody) return;

                tbody.innerHTML = '';

                if (invoices.length === 0) {
                    tbody.innerHTML = `
                        <tr>
                            <td colspan="9" class="text-center" style="padding: 40px;">
                                <div style="color: var(--muted); font-size: 16px;">
                                    <i class="fas fa-search" style="margin-bottom: 10px; font-size: 48px;"></i>
                                    <div>لا توجد فواتير</div>
                                </div>
                            </td>
                        </tr>
                    `;
                    return;
                }

                invoices.forEach(invoice => {
                    const row = this.createInvoiceRow(invoice);
                    tbody.appendChild(row);
                });
            },

            createInvoiceRow(invoice) {
                const row = document.createElement('tr');
                
                const statusBadge = this.getStatusBadge(invoice.status);
                const returnsCount = 0; // سيتم إضافته من API لاحقاً
                
                row.innerHTML = `
                    <td class="fw-bold">${invoice.id}</td>
                    <td>
                        <div class="fw-semibold">${invoice.supplier_name || 'غير معروف'}</div>
                        <div class="text-muted" style="font-size: 12px;">${invoice.creator_name || ''}</div>
                    </td>
                    <td>${UIManager.formatDate(invoice.purchase_date)}</td>
                    <td>${invoice.supplier_invoice_number || '-'}</td>
                    <td>
                        <div class="invoice-status-indicator">
                            <span class="dot ${invoice.status}"></span>
                            ${statusBadge}
                        </div>
                    </td>
                    <td>
                        <div style="font-size: 13px;">
                            <div>${invoice.item_count || 0} منتج</div>
                            <div class="text-muted">الكمية: ${invoice.total_quantity || 0}</div>
                        </div>
                    </td>
                    <td class="fw-bold text-right">${UIManager.formatCurrency(invoice.total_amount)}</td>
                    <td>
                        ${returnsCount > 0 ? 
                            `<span class="badge badge-returned">${returnsCount} مرتجع</span>` : 
                            '<span class="text-muted">لا يوجد</span>'
                        }
                    </td>
                    <td>
                        <div class="action-buttons">
                            ${this.getActionButtons(invoice)}
                        </div>
                    </td>
                `;
                
                return row;
            },

            getStatusBadge(status) {
                const color = STATUS_COLORS[status] || '#6b7280';
                const label = STATUS_LABELS[status] || status;
                
                return `<span class="badge" style="background: ${color}">${label}</span>`;
            },

            getActionButtons(invoice) {
                let buttons = `
                    <button class="btn btn-info btn-sm" onclick="PurchaseManager.viewInvoice(${invoice.id})">
                        <i class="fas fa-eye"></i>
                        عرض
                    </button>
                `;
                
                switch(invoice.status) {
                    case 'pending':
                        buttons += `
                            <button class="btn btn-warning btn-sm" onclick="PurchaseManager.editInvoice(${invoice.id})">
                                <i class="fas fa-edit"></i>
                                تعديل
                            </button>
                            <button class="btn btn-success btn-sm" onclick="PurchaseManager.receiveInvoice(${invoice.id})">
                                <i class="fas fa-check-circle"></i>
                                استلام
                            </button>
                            <button class="btn btn-danger btn-sm" onclick="PurchaseManager.cancelInvoice(${invoice.id})">
                                <i class="fas fa-times-circle"></i>
                                إلغاء
                            </button>
                        `;
                        break;
                    
                    case 'fully_received':
                        // إزالة زر التعديل من الفواتير المسلمة
                        buttons += `
                            <button class="btn btn-danger btn-sm" onclick="ReturnManager.openNewReturnModal(${invoice.id})">
                                <i class="fas fa-exchange-alt"></i>
                                مرتجع
                            </button>
                        `;
                        break;
                    
                    case 'partial_received':
                        buttons += `
                            <button class="btn btn-success btn-sm" onclick="PurchaseManager.receiveInvoice(${invoice.id})">
                                <i class="fas fa-check-circle"></i>
                                استكمال
                            </button>
                        `;
                        break;
                }
                
                return buttons;
            },

            async viewInvoice(invoiceId) {
                this.currentInvoiceId = invoiceId;
                UIManager.showLoader('جاري تحميل تفاصيل الفاتورة...');
                
                try {
                    const result = await APIManager.fetchInvoiceDetails(invoiceId);
                    if (result.success) {
                        this.currentInvoiceData = result;
                        this.renderInvoiceDetails(result);
                        UIManager.openModal('viewInvoiceModal');
                    } else {
                        UIManager.showError(result.message);
                    }
                } catch (error) {
                    console.error('Error loading invoice details:', error);
                    UIManager.showError('حدث خطأ أثناء تحميل التفاصيل');
                } finally {
                    UIManager.hideLoader();
                }
            },

            renderInvoiceDetails(data) {
                const modalBody = document.getElementById('invoiceDetails');
                if (!modalBody) return;

                const invoice = data.invoice;
                const items = data.items || [];
                const batches = data.batches || [];
                const canEdit = data.can_edit || false;
                const canRevert = data.can_revert || false;

                const statusText = STATUS_LABELS[invoice.status] || invoice.status;
                const totalItems = items.reduce((sum, item) => sum + (item.quantity || 0), 0);
                const totalReceived = items.reduce((sum, item) => sum + (item.qty_received || 0), 0);
                const totalCost = items.reduce((sum, item) => sum + (item.total_cost || 0), 0);

                let html = `
                    <div class="invoice-details-grid">
                        <div class="detail-card">
                            <div class="detail-label">رقم الفاتورة</div>
                            <div class="detail-value">${invoice.id}</div>
                        </div>
                        <div class="detail-card">
                            <div class="detail-label">تاريخ الشراء</div>
                            <div class="detail-value">${UIManager.formatDate(invoice.purchase_date)}</div>
                        </div>
                        <div class="detail-card">
                            <div class="detail-label">الحالة</div>
                            <div class="detail-value">${statusText}</div>
                        </div>
                        <div class="detail-card">
                            <div class="detail-label">المورد</div>
                            <div class="detail-value">${invoice.supplier_name || 'غير معروف'}</div>
                        </div>
                        <div class="detail-card">
                            <div class="detail-label">رقم فاتورة المورد</div>
                            <div class="detail-value">${invoice.supplier_invoice_number || '-'}</div>
                        </div>
                        <div class="detail-card">
                            <div class="detail-label">تاريخ الإنشاء</div>
                            <div class="detail-value">${UIManager.formatDateTime(invoice.created_at)}</div>
                        </div>
                        <div class="detail-card">
                            <div class="detail-label">المنشئ</div>
                            <div class="detail-value">${invoice.creator_name || '-'}</div>
                        </div>
                        <div class="detail-card">
                            <div class="detail-label">الإجمالي</div>
                            <div class="detail-value">${UIManager.formatCurrency(invoice.total_amount)}</div>
                        </div>
                    </div>

                    ${invoice.notes ? `
                        <div class="invoice-notes">
                            <h4>ملاحظات</h4>
                            <pre>${invoice.notes}</pre>
                        </div>
                    ` : ''}

                    <h4>بنود الفاتورة (${items.length})</h4>
                    <div class="table-wrapper">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>المنتج</th>
                                    <th>الكمية المطلوبة</th>
                                    <th>الكمية المستلمة</th>
                                    <th>سعر الشراء</th>
                                    <th>سعر البيع</th>
                                    <th>الإجمالي</th>
                                </tr>
                            </thead>
                            <tbody>
                `;

                items.forEach((item, index) => {
                    html += `
                        <tr>
                            <td>${index + 1}</td>
                            <td>${item.product_name || `منتج #${item.product_id}`}</td>
                            <td>${(item.quantity || 0).toFixed(2)}</td>
                            <td>${(item.qty_received || 0).toFixed(2)}</td>
                            <td>${UIManager.formatCurrency(item.cost_price_per_unit || 0)}</td>
                            <td>${item.sale_price ? UIManager.formatCurrency(item.sale_price) : '-'}</td>
                            <td>${UIManager.formatCurrency(item.total_cost || 0)}</td>
                        </tr>
                    `;
                });

                html += `
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td colspan="6" class="fw-bold text-right">الإجمالي</td>
                                    <td class="fw-bold">${UIManager.formatCurrency(totalCost)}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                `;

                if (batches.length > 0) {
                    html += `
                        <h4 class="mt-4">الدفعات المرتبطة (${batches.length})</h4>
                        <div class="table-wrapper">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>رقم الدفعة</th>
                                        <th>المنتج</th>
                                        <th>الكمية الأصلية</th>
                                        <th>المتاح</th>
                                        <th>سعر الشراء</th>
                                        <th>سعر البيع</th>
                                        <th>حالة الدفعة</th>
                                    </tr>
                                </thead>
                                <tbody>
                    `;

                    batches.forEach(batch => {
                        const batchStatus = batch.status === 'active' ? 'نشط' : 
                                          batch.status === 'consumed' ? 'مستهلك' : 
                                          batch.status === 'reverted' ? 'معاد' : 'ملغي';
                        
                        html += `
                            <tr>
                                <td>${batch.id}</td>
                                <td>${batch.product_name || `منتج #${batch.product_id}`}</td>
                                <td>${batch.original_qty}</td>
                                <td>${batch.remaining}</td>
                                <td>${UIManager.formatCurrency(batch.unit_cost || 0)}</td>
                                <td>${batch.sale_price ? UIManager.formatCurrency(batch.sale_price) : '-'}</td>
                                <td>${batchStatus}</td>
                            </tr>
                        `;
                    });

                    html += `
                                </tbody>
                            </table>
                        </div>
                    `;
                }

                // Update footer buttons based on permissions
                const footer = document.getElementById('invoiceModalFooter');
                if (footer && canRevert) {
                    footer.innerHTML = `
                        <button class="btn btn-warning" onclick="PurchaseManager.revertInvoicePrompt(${invoice.id})">
                            <i class="fas fa-undo"></i>
                            إرجاع
                        </button>
                        ${footer.innerHTML}
                    `;
                }

                modalBody.innerHTML = html;
            },

            async editInvoice(invoiceId) {
                this.currentInvoiceId = invoiceId;
                UIManager.showLoader('جاري تحميل بيانات التعديل...');
                
                try {
                    const result = await APIManager.fetchInvoiceDetails(invoiceId);
                    if (result.success && result.can_edit) {
                        this.renderEditModal(result);
                        UIManager.openModal('editInvoiceModal');
                    } else {
                        UIManager.showError('لا يمكن تعديل هذه الفاتورة في حالتها الحالية');
                    }
                } catch (error) {
                    console.error('Error loading invoice for edit:', error);
                    UIManager.showError('حدث خطأ أثناء تحميل بيانات التعديل');
                } finally {
                    UIManager.hideLoader();
                }
            },

            renderEditModal(data) {
                const modalBody = document.getElementById('editInvoiceBody');
                const invoiceIdSpan = document.getElementById('edit_inv_id');
                
                if (!modalBody || !invoiceIdSpan) return;
                
                const invoice = data.invoice;
                const items = data.items || [];
                
                invoiceIdSpan.textContent = `#${invoice.id}`;
                
                let html = `
                    <table class="edit-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>المنتج</th>
                                <th>كمية حالية</th>
                                <th>كمية جديدة</th>
                                <th>سعر شراء حالي</th>
                                <th>سعر شراء جديد</th>
                                <th>سعر بيع حالي</th>
                                <th>سعر بيع جديد</th>
                                <th>حذف</th>
                            </tr>
                        </thead>
                        <tbody>
                `;
                
                items.forEach((item, index) => {

                    console.log(item);
                    
                    html += `
                        <tr>
                            <td>${index + 1}</td>
                            <td>${item.product_name || `منتج #${item.product_id}`}</td>
                            <td>${(item.quantity || 0).toFixed(2)}</td>
                            <td>
                                <input class="edit-input edit-item-qty" 
                                       data-item-id="${item.id}" 
                                       type="number" 
                                       step="0.01" 
                                       min="0"
                                       value="${(item.quantity || 0).toFixed(2)}">
                            </td>
                            <td>${UIManager.formatCurrency(item.cost_price_per_unit || 0)}</td>
                            <td>
                                <input class="edit-input edit-item-cost" 
                                       data-item-id="${item.id}" 
                                       type="number" 
                                       step="0.01" 
                                       min="0"
                                       value="${(item.cost_price_per_unit || 0).toFixed(2)}">
                            </td>
                            <td>${item.sale_price ? UIManager.formatCurrency(item.sale_price) : '-'}</td>
                            <td>
                                <input class="edit-input edit-item-sale" 
                                       data-item-id="${item.id}" 
                                       type="number" 
                                       step="0.01" 
                                       min="0"
                                       value="${parseFloat(item.sale_price)?.toFixed(2) || ''}">
                            </td>
                            <td>
                                <button type="button" class="btn btn-danger btn-sm js-delete-item" 
                                        data-item-id="${item.id}">
                                    <i class="fas fa-trash"></i>
                                    حذف
                                </button>
                            </td>
                        </tr>
                    `;
                });
                
                html += `
                        </tbody>
                    </table>
                    
                    <div class="mt-4">
                        <label class="detail-label">سبب التعديل (مطلوب)</label>
                        <textarea id="js_adjust_reason" rows="3" class="edit-input" 
                                  placeholder="أدخل سبب التعديل..."></textarea>
                    </div>
                `;
                
                modalBody.innerHTML = html;
                
                // Add event listeners
                setTimeout(() => {
                    document.getElementById('btn_save_edit').onclick = () => this.saveEdit();
                    document.querySelectorAll('.js-delete-item').forEach(btn => {
                        btn.onclick = (e) => {
                            const itemId = e.currentTarget.dataset.itemId;
                            this.deleteItemPrompt(itemId);
                        };
                    });
                }, 100);
            },

            async saveEdit() {
                const reason = document.getElementById('js_adjust_reason').value.trim();
                if (!reason) {
                    UIManager.showError('يرجى إدخال سبب التعديل');
                    return;
                }

                // Collect item data
                const items = [];
                document.querySelectorAll('.edit-item-qty').forEach(input => {
                    const itemId = input.dataset.itemId;
                    const newQty = parseFloat(input.value) || 0;
                    const newCost = parseFloat(document.querySelector(`.edit-item-cost[data-item-id="${itemId}"]`)?.value) || 0;
                    const newSaleElement = document.querySelector(`.edit-item-sale[data-item-id="${itemId}"]`);
                    const newSale = newSaleElement && newSaleElement.value !== '' ? parseFloat(newSaleElement.value) : null;

                    items.push({
                        item_id: parseInt(itemId),
                        new_quantity: newQty,
                        new_cost_price: newCost,
                        new_sale_price: newSale
                    });
                });

                if (items.length === 0) {
                    UIManager.showError('لا توجد بنود لتعديلها');
                    return;
                }

                UIManager.showLoader('جاري حفظ التعديلات...');
                
                try {
                    const result = await APIManager.editInvoiceItems(this.currentInvoiceId, items, reason);
                    if (result.success) {
                        UIManager.showSuccess('تم حفظ التعديلات بنجاح');
                        UIManager.closeModal('editInvoiceModal');
                        this.loadInvoices(this.currentPage);
                    } else {
                        UIManager.showError(result.message || 'فشل حفظ التعديلات');
                    }
                } catch (error) {
                    console.error('Error saving edits:', error);
                    UIManager.showError('حدث خطأ أثناء حفظ التعديلات');
                } finally {
                    UIManager.hideLoader();
                }
            },

            deleteItemPrompt(itemId) {
                UIManager.showConfirm('هل تريد تأكيد حذف هذا البند؟', 'تأكيد الحذف')
                    .then(async result => {
                        if (result.isConfirmed) {
                            const reason = prompt('يرجى إدخال سبب الحذف:');
                            if (reason === null || reason.trim() === '') {
                                UIManager.showError('يرجى إدخال سبب الحذف');
                                return;
                            }

                            UIManager.showLoader('جاري حذف البند...');
                            
                            try {
                                const result = await APIManager.deleteInvoiceItem(this.currentInvoiceId, itemId, reason);
                                if (result.success) {
                                    UIManager.showSuccess('تم حذف البند بنجاح');
                                    this.editInvoice(this.currentInvoiceId); // Reload edit modal
                                } else {
                                    UIManager.showError(result.message || 'فشل حذف البند');
                                }
                            } catch (error) {
                                console.error('Error deleting item:', error);
                                UIManager.showError('حدث خطأ أثناء حذف البند');
                            } finally {
                                UIManager.hideLoader();
                            }
                        }
                    });
            },

            receiveInvoice(invoiceId) {
                this.currentInvoiceId = invoiceId;
                document.getElementById('receiveInvoiceId').textContent = invoiceId;
                UIManager.openModal('receiveInvoiceModal');
            },

            async confirmReceive() {
                UIManager.showLoader('جاري استلام الفاتورة...');
                
                try {
                    const result = await APIManager.receiveInvoice(this.currentInvoiceId);
                    if (result.success) {
                        UIManager.showSuccess(result.message || 'تم استلام الفاتورة بنجاح');
                        UIManager.closeModal('receiveInvoiceModal');
                        this.loadInvoices(this.currentPage);
                    } else {
                        UIManager.showError(result.message || 'فشل استلام الفاتورة');
                    }
                } catch (error) {
                    console.error('Error receiving invoice:', error);
                    UIManager.showError('حدث خطأ أثناء استلام الفاتورة');
                } finally {
                    UIManager.hideLoader();
                }
            },

            cancelInvoice(invoiceId) {
                this.currentInvoiceId = invoiceId;
                document.getElementById('cancelInvoiceId').textContent = invoiceId;
                UIManager.openModal('cancelInvoiceModal');
            },

            async confirmCancel() {
                const reason = document.getElementById('cancelReason').value.trim();
                if (!reason) {
                    UIManager.showError('يرجى إدخال سبب الإلغاء');
                    return;
                }

                UIManager.showLoader('جاري إلغاء الفاتورة...');
                
                try {
                    const result = await APIManager.cancelInvoice(this.currentInvoiceId, reason);
                    if (result.success) {
                        UIManager.showSuccess(result.message || 'تم إلغاء الفاتورة بنجاح');
                        UIManager.closeModal('cancelInvoiceModal');
                        this.loadInvoices(this.currentPage);
                    } else {
                        UIManager.showError(result.message || 'فشل إلغاء الفاتورة');
                    }
                } catch (error) {
                    console.error('Error cancelling invoice:', error);
                    UIManager.showError('حدث خطأ أثناء إلغاء الفاتورة');
                } finally {
                    UIManager.hideLoader();
                }
            },

            revertInvoicePrompt(invoiceId) {
                UIManager.showConfirm('هل تريد إرجاع الفاتورة إلى قيد الانتظار؟', 'تأكيد الإرجاع')
                    .then(async result => {
                        if (result.isConfirmed) {
                            const reason = prompt('يرجى إدخال سبب الإرجاع:');
                            if (reason === null || reason.trim() === '') {
                                UIManager.showError('يرجى إدخال سبب الإرجاع');
                                return;
                            }

                            UIManager.showLoader('جاري إرجاع الفاتورة...');
                            
                            try {
                                const result = await APIManager.revertInvoice(invoiceId, reason);
                                if (result.success) {
                                    UIManager.showSuccess(result.message || 'تم إرجاع الفاتورة بنجاح');
                                    UIManager.closeModal('viewInvoiceModal');
                                    this.loadInvoices(this.currentPage);
                                } else {
                                    UIManager.showError(result.message || 'فشل إرجاع الفاتورة');
                                }
                            } catch (error) {
                                console.error('Error reverting invoice:', error);
                                UIManager.showError('حدث خطأ أثناء إرجاع الفاتورة');
                            } finally {
                                UIManager.hideLoader();
                            }
                        }
                    });
            },

            searchSupplier(event) {
                clearTimeout(this.searchTimeout);
                this.searchTimeout = setTimeout(() => {
                    this.filterInvoices();
                }, 500);
            },

            updateDateFilter() {
                this.filterInvoices();
            },

            filterInvoices() {
                this.currentPage = 1;
                this.loadInvoices();
            },

            resetFilters() {
                document.getElementById('supplierNameFilter').value = '';
                document.getElementById('invoiceIdFilter').value = '';
                document.getElementById('statusFilter').value = '';
                
                // Reset to current month
                const today = new Date();
                const firstDayOfMonth = new Date(today.getFullYear(), today.getMonth(), 1);
                
                document.getElementById('dateFromFilter').value = this.formatDateForInput(firstDayOfMonth);
                document.getElementById('dateToFilter').value = this.formatDateForInput(today);
                
                this.currentPage = 1;
                this.loadInvoices();
            },

            refreshInvoices() {
                this.loadInvoices(this.currentPage);
            },

            openNewInvoiceModal() {
                UIManager.showSuccess('هذه الميزة قيد التطوير', 'قريباً');
            },

            generateInvoicePrintContent(invoice, type = 'A4') {
                if (type === 'POS') {
                    return this.generatePOSPrintContent(invoice);
                } else {
                    return this.generateA4PrintContent(invoice);
                }
            },

            generateA4PrintContent(invoice) {
                const items = invoice.items || [];
                const supplier = invoice.supplier || {};
                const status = invoice.status || 'pending';
                
                const date = new Date(invoice.purchase_date || new Date());
                const formattedDate = date.toLocaleDateString('ar-EG');
                const timeString = date.toLocaleTimeString('ar-EG', { hour: '2-digit', minute: '2-digit' });

                let itemsHTML = '';
                let subtotal = 0;

                items.forEach((item, index) => {
                    const itemTotal = (item.quantity || 0) * (item.cost_price_per_unit || 0);
                    subtotal += itemTotal;
                    
                    itemsHTML += `
                        <tr>
                            <td style="text-align:center; padding: 8px; border-bottom: 1px solid #ddd;">${index + 1}</td>
                            <td style="text-align:right; padding: 8px; border-bottom: 1px solid #ddd;">${item.product_name || `منتج #${item.product_id}`}</td>
                            <td style="text-align:center; padding: 8px; border-bottom: 1px solid #ddd;">${(item.quantity || 0).toFixed(2)}</td>
                            <td style="text-align:center; padding: 8px; border-bottom: 1px solid #ddd;">${(item.cost_price_per_unit || 0).toFixed(2)}</td>
                            <td style="text-align:center; padding: 8px; border-bottom: 1px solid #ddd;">${itemTotal.toFixed(2)} ج.م</td>
                        </tr>
                    `;
                });

                const statusText = STATUS_LABELS[status] || status;

                return `
                    <!DOCTYPE html>
                    <html lang="ar" dir="rtl">
                    <head>
                        <meta charset="UTF-8">
                        <meta name="viewport" content="width=device-width, initial-scale=1.0">
                        <title>فاتورة مشتريات ${invoice.id}</title>
                        <style>
                            * {
                                margin: 0;
                                padding: 0;
                                box-sizing: border-box;
                                font-family: 'Arial', sans-serif;
                            }
                            
                            body {
                                padding: 20px;
                                background: white;
                                color: #000;
                            }
                            
                            .invoice-container {
                                width: 210mm;
                                margin: 0 auto;
                                padding: 20px;
                                border: 1px solid #ddd;
                            }
                            
                            .header {
                                text-align: center;
                                margin-bottom: 30px;
                                padding-bottom: 20px;
                                border-bottom: 2px solid #000;
                            }
                            
                            .company-name {
                                font-size: 24px;
                                font-weight: bold;
                                margin-bottom: 10px;
                            }
                            
                            .invoice-info {
                                display: flex;
                                justify-content: space-between;
                                margin-bottom: 30px;
                                background: #f8f9fa;
                                padding: 15px;
                                border-radius: 8px;
                            }
                            
                            .supplier-info {
                                margin-bottom: 30px;
                                padding: 15px;
                                background: #f1f8ff;
                                border-radius: 8px;
                            }
                            
                            table {
                                width: 100%;
                                border-collapse: collapse;
                                margin-bottom: 30px;
                            }
                            
                            th {
                                background: #2c3e50;
                                color: white;
                                padding: 12px;
                                text-align: center;
                                border: 1px solid #ddd;
                            }
                            
                            td {
                                padding: 10px;
                                border: 1px solid #ddd;
                            }
                            
                            .totals {
                                margin-top: 30px;
                                padding: 20px;
                                background: #f8f9fa;
                                border-radius: 8px;
                            }
                            
                            .total-row {
                                display: flex;
                                justify-content: space-between;
                                padding: 8px 0;
                                font-size: 16px;
                            }
                            
                            .total-final {
                                border-top: 2px solid #000;
                                margin-top: 10px;
                                padding-top: 15px;
                                font-size: 18px;
                                font-weight: bold;
                            }
                            
                            .footer {
                                text-align: center;
                                margin-top: 40px;
                                padding-top: 20px;
                                border-top: 1px solid #ddd;
                                color: #666;
                            }
                            
                            @media print {
                                body {
                                    padding: 0;
                                }
                                
                                .invoice-container {
                                    border: none;
                                    padding: 0;
                                }
                                
                                .no-print {
                                    display: none !important;
                                }
                            }
                        </style>
                    </head>
                    <body>
                        <div class="invoice-container">
                            <div class="header">
                                <div class="company-name">نظام إدارة المشتريات</div>
                                <div style="font-size: 18px; margin-top: 10px;">فاتورة مشتريات</div>
                            </div>
                            
                            <div class="invoice-info">
                                <div>
                                    <div><strong>رقم الفاتورة:</strong> ${invoice.id}</div>
                                    <div><strong>التاريخ:</strong> ${formattedDate}</div>
                                    <div><strong>الوقت:</strong> ${timeString}</div>
                                </div>
                                <div>
                                    <div><strong>حالة الفاتورة:</strong> ${statusText}</div>
                                    <div><strong>رقم فاتورة المورد:</strong> ${invoice.supplier_invoice_number || '-'}</div>
                                </div>
                            </div>
                            
                            <div class="supplier-info">
                                <h3 style="margin-bottom: 10px;">بيانات المورد</h3>
                                <div><strong>اسم المورد:</strong> ${supplier.name || 'غير معروف'}</div>
                            </div>
                            
                            <table>
                                <thead>
                                    <tr>
                                        <th width="5%">#</th>
                                        <th width="45%">المنتج</th>
                                        <th width="15%">الكمية</th>
                                        <th width="15%">سعر الشراء</th>
                                        <th width="20%">الإجمالي</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${itemsHTML}
                                </tbody>
                            </table>
                            
                            <div class="totals">
                                <div class="total-row">
                                    <span>عدد المنتجات:</span>
                                    <span>${items.length}</span>
                                </div>
                                <div class="total-row">
                                    <span>إجمالي الكمية:</span>
                                    <span>${items.reduce((sum, item) => sum + (item.quantity || 0), 0).toFixed(2)}</span>
                                </div>
                                <div class="total-row total-final">
                                    <span>المبلغ الإجمالي:</span>
                                    <span>${subtotal.toFixed(2)} ج.م</span>
                                </div>
                            </div>
                            
                            <div class="footer">
                                <div>شكراً لتعاملكم معنا</div>
                                <div style="margin-top: 10px;">${new Date().toLocaleDateString('ar-EG')}</div>
                            </div>
                        </div>
                    </body>
                    </html>
                `;
            },

            generatePOSPrintContent(invoice) {
                const items = invoice.items || [];
                const supplier = invoice.supplier || {};
                
                const date = new Date(invoice.purchase_date || new Date());
                const formattedDate = date.toLocaleDateString('ar-EG');
                const timeString = date.toLocaleTimeString('ar-EG', { hour: '2-digit', minute: '2-digit' });

                let itemsHTML = '';
                let subtotal = 0;

                items.forEach((item, index) => {
                    const itemTotal = (item.quantity || 0) * (item.cost_price_per_unit || 0);
                    subtotal += itemTotal;
                    
                    itemsHTML += `
                        <tr>
                            <td style="width:60%; text-align:right; padding: 4px 2px; border-bottom: 1px dashed #ddd; font-size: 12px;">
                                ${item.product_name || `منتج #${item.product_id}`}
                            </td>
                            <td style="width:15%; text-align:center; padding: 4px 2px; border-bottom: 1px dashed #ddd; font-size: 12px;">
                                ${(item.quantity || 0).toFixed(2)}
                            </td>
                            <td style="width:25%; text-align:left; padding: 4px 2px; border-bottom: 1px dashed #ddd; font-size: 12px;">
                                ${itemTotal.toFixed(2)} ج.م
                            </td>
                        </tr>
                    `;
                });

                return `
                    <!DOCTYPE html>
                    <html lang="ar" dir="rtl">
                    <head>
                        <meta charset="UTF-8">
                        <meta name="viewport" content="width=device-width, initial-scale=1.0">
                        <title>فاتورة مشتريات ${invoice.id}</title>
                        <style>
                            * {
                                margin: 0;
                                padding: 0;
                                box-sizing: border-box;
                                font-family: 'Courier New', monospace;
                            }
                            
                            body {
                                padding: 10px;
                                background: white;
                                color: #000;
                                font-size: 14px;
                            }
                            
                            .pos-invoice {
                                width: 80mm;
                                margin: 0 auto;
                                padding: 10px;
                            }
                            
                            .pos-header {
                                text-align: center;
                                padding-bottom: 10px;
                                margin-bottom: 10px;
                                border-bottom: 1px dashed #000;
                            }
                            
                            .store-name {
                                font-weight: bold;
                                font-size: 16px;
                                margin-bottom: 5px;
                            }
                            
                            .invoice-title {
                                font-size: 14px;
                                margin-bottom: 10px;
                            }
                            
                            .invoice-meta {
                                margin-bottom: 15px;
                                font-size: 12px;
                            }
                            
                            .meta-row {
                                display: flex;
                                justify-content: space-between;
                                margin-bottom: 3px;
                            }
                            
                            .supplier-info {
                                margin-bottom: 15px;
                                padding: 8px;
                                background: #f5f5f5;
                                border-radius: 4px;
                                font-size: 12px;
                            }
                            
                            .items-table {
                                width: 100%;
                                margin-bottom: 15px;
                            }
                            
                            .items-header {
                                border-bottom: 2px solid #000;
                                padding-bottom: 5px;
                                margin-bottom: 5px;
                                font-weight: bold;
                                font-size: 12px;
                            }
                            
                            .items-row {
                                display: flex;
                                justify-content: space-between;
                                padding: 3px 0;
                                border-bottom: 1px dashed #ddd;
                            }
                            
                            .total-section {
                                margin-top: 15px;
                                padding-top: 10px;
                                border-top: 2px dashed #000;
                            }
                            
                            .total-row {
                                display: flex;
                                justify-content: space-between;
                                margin-bottom: 5px;
                                font-weight: bold;
                            }
                            
                            .barcode {
                                text-align: center;
                                margin: 15px 0;
                                font-family: monospace;
                                font-size: 18px;
                                letter-spacing: 2px;
                            }
                            
                            .footer {
                                text-align: center;
                                margin-top: 20px;
                                padding-top: 10px;
                                border-top: 1px dashed #000;
                                font-size: 11px;
                                color: #666;
                            }
                            
                            @media print {
                                body {
                                    padding: 0;
                                }
                                
                                .pos-invoice {
                                    padding: 5px;
                                }
                            }
                        </style>
                    </head>
                    <body>
                        <div class="pos-invoice">
                            <div class="pos-header">
                                <div class="store-name">نظام المشتريات</div>
                                <div class="invoice-title">فاتورة مشتريات</div>
                            </div>
                            
                            <div class="invoice-meta">
                                <div class="meta-row">
                                    <span>رقم:</span>
                                    <span>${invoice.id}</span>
                                </div>
                                <div class="meta-row">
                                    <span>التاريخ:</span>
                                    <span>${formattedDate}</span>
                                </div>
                                <div class="meta-row">
                                    <span>الوقت:</span>
                                    <span>${timeString}</span>
                                </div>
                                <div class="meta-row">
                                    <span>رقم مورد:</span>
                                    <span>${invoice.supplier_invoice_number || '-'}</span>
                                </div>
                            </div>
                            
                            <div class="supplier-info">
                                <div><strong>المورد:</strong> ${supplier.name || 'غير معروف'}</div>
                            </div>
                            
                            <div class="items-header">
                                <div style="width:60%; text-align:right;">المنتج</div>
                                <div style="width:15%; text-align:center;">الكمية</div>
                                <div style="width:25%; text-align:left;">الإجمالي</div>
                            </div>
                            
                            ${itemsHTML}
                            
                            <div class="total-section">
                                <div class="total-row">
                                    <span>عدد المنتجات:</span>
                                    <span>${items.length}</span>
                                </div>
                                <div class="total-row">
                                    <span>إجمالي الكمية:</span>
                                    <span>${items.reduce((sum, item) => sum + (item.quantity || 0), 0).toFixed(2)}</span>
                                </div>
                                <div class="total-row" style="font-size: 16px;">
                                    <span>المجموع:</span>
                                    <span>${subtotal.toFixed(2)} ج.م</span>
                                </div>
                            </div>
                            
                            <div class="barcode">*${invoice.id}*</div>
                            
                            <div class="footer">
                                <div>شكراً لتعاملكم</div>
                                <div>${new Date().toLocaleDateString('ar-EG')} ${new Date().toLocaleTimeString('ar-EG', { hour: '2-digit', minute: '2-digit' })}</div>
                            </div>
                        </div>
                    </body>
                    </html>
                `;
            },

            printInvoice() {
                if (!this.currentInvoiceData) return;
                
                const printWindow = window.open('', '_blank');
                const content = this.generateInvoicePrintContent(this.currentInvoiceData.invoice, 'A4');
                
                printWindow.document.write(content);
                printWindow.document.close();
                printWindow.focus();
                
                setTimeout(() => {
                    printWindow.print();
                    printWindow.close();
                }, 500);
            },

            printInvoicePOS() {
                if (!this.currentInvoiceData) return;
                
                const printWindow = window.open('', '_blank');
                const content = this.generateInvoicePrintContent(this.currentInvoiceData.invoice, 'POS');
                
                printWindow.document.write(content);
                printWindow.document.close();
                printWindow.focus();
                
                setTimeout(() => {
                    printWindow.print();
                    printWindow.close();
                }, 500);
            }
        };

        // ==================== RETURN MANAGER ====================
        const ReturnManager = {
            currentStep: 1,
            selectedBatches: [],
            currentInvoiceId: null,
            currentPage: 1,
            pageSize: 10,
            totalReturns: 0,

            async loadReturns(page = 1) {
                this.currentPage = page;
                UIManager.showLoading('returnsTableLoading');
                
                try {
                    // Build filters from form
                    const filters = {
                        page: page,
                        limit: this.pageSize,
                        supplierName: document.getElementById('returnSupplierNameFilter').value,
                        originalInvoiceId: document.getElementById('returnOriginalInvoiceFilter').value,
                        returnType: document.getElementById('returnTypeFilter').value,
                        returnStatus: document.getElementById('returnStatusFilter').value,
                        dateFrom: document.getElementById('returnDateFrom').value,
                        dateTo: document.getElementById('returnDateTo').value
                    };

                    // Note: You'll need to create a similar API endpoint for returns
                    // For now, using mock data
                    await this.loadMockReturns(filters);
                    
                    // Update badge count
                    const badge = document.getElementById('returnsCountBadge');
                    if (badge) {
                        badge.textContent = this.totalReturns;
                    }
                } catch (error) {
                    console.error('Error loading returns:', error);
                    UIManager.showError('حدث خطأ أثناء تحميل المرتجعات');
                } finally {
                    UIManager.hideLoading('returnsTableLoading');
                }
            },

            async loadMockReturns(filters) {
                // Mock data - replace with actual API call
                const mockReturns = [
                    {
                        id: 'RET-2024-001',
                        purchase_invoice_id: 102,
                        supplier: { name: 'مورد التقنية المتطورة' },
                        return_type: 'wrong_item',
                        return_date: '2024-01-20',
                        status: 'completed',
                        reason: 'استلام منتج غير مطابق للمواصفات',
                        total_amount: 200.00,
                        items: [
                            {
                                product_name: 'ماوس لاسلكي',
                                batch_id: 'B002',
                                quantity: 2,
                                unit_cost: 100.00,
                                total_cost: 200.00
                            }
                        ]
                    }
                ];

                // Apply filters
                let filteredReturns = mockReturns;
                
                if (filters.supplierName) {
                    filteredReturns = filteredReturns.filter(r => 
                        r.supplier.name.includes(filters.supplierName)
                    );
                }
                
                if (filters.originalInvoiceId) {
                    filteredReturns = filteredReturns.filter(r => 
                        r.purchase_invoice_id == filters.originalInvoiceId
                    );
                }
                
                if (filters.returnType) {
                    filteredReturns = filteredReturns.filter(r => r.return_type === filters.returnType);
                }
                
                if (filters.returnStatus) {
                    filteredReturns = filteredReturns.filter(r => r.status === filters.returnStatus);
                }

                this.totalReturns = filteredReturns.length;
                this.renderReturns(filteredReturns);
                UIManager.renderPagination(this.totalReturns, page, this.pageSize, 'returnsPagination', 'ReturnManager.loadReturns');
                UIManager.updateFilterTags('returns', filters);
            },

            renderReturns(returns) {
                const tbody = document.getElementById('returnsTable');
                if (!tbody) return;

                tbody.innerHTML = '';

                if (returns.length === 0) {
                    tbody.innerHTML = `
                        <tr>
                            <td colspan="9" class="text-center" style="padding: 40px;">
                                <div style="color: var(--muted); font-size: 16px;">
                                    <i class="fas fa-exchange-alt" style="margin-bottom: 10px; font-size: 48px;"></i>
                                    <div>لا توجد مرتجعات</div>
                                </div>
                            </td>
                        </tr>
                    `;
                    return;
                }

                returns.forEach(ret => {
                    const row = this.createReturnRow(ret);
                    tbody.appendChild(row);
                });
            },

            createReturnRow(ret) {
                const row = document.createElement('tr');
                
                const typeText = RETURN_TYPES[ret.return_type] || ret.return_type;
                const statusBadge = this.getReturnStatusBadge(ret.status);
                const totalItems = ret.items.reduce((sum, item) => sum + item.quantity, 0);
                
                row.innerHTML = `
                    <td class="fw-bold">${ret.id}</td>
                    <td>${ret.purchase_invoice_id}</td>
                    <td>${ret.supplier?.name || 'غير معروف'}</td>
                    <td><span class="badge">${typeText}</span></td>
                    <td>${UIManager.formatDate(ret.return_date)}</td>
                    <td>${totalItems} منتج</td>
                    <td class="fw-bold text-right">${UIManager.formatCurrency(ret.total_amount)}</td>
                    <td>${statusBadge}</td>
                    <td>
                        <div class="action-buttons">
                            <button class="btn btn-info btn-sm" onclick="ReturnManager.viewReturnDetails('${ret.id}')">
                                <i class="fas fa-eye"></i>
                                عرض
                            </button>
                        </div>
                    </td>
                `;
                
                return row;
            },

            getReturnStatusBadge(status) {
                switch(status) {
                    case 'pending': return '<span class="badge badge-pending">قيد المعالجة</span>';
                    case 'completed': return '<span class="badge badge-received">مكتمل</span>';
                    case 'cancelled': return '<span class="badge badge-cancelled">ملغي</span>';
                    default: return '<span class="badge">غير معروف</span>';
                }
            },

            searchSupplier(event) {
                clearTimeout(this.searchTimeout);
                this.searchTimeout = setTimeout(() => {
                    this.filterReturns();
                }, 500);
            },

            updateDateFilter() {
                this.filterReturns();
            },

            filterReturns() {
                this.currentPage = 1;
                this.loadReturns();
            },

            resetFilters() {
                document.getElementById('returnSupplierNameFilter').value = '';
                document.getElementById('returnOriginalInvoiceFilter').value = '';
                document.getElementById('returnTypeFilter').value = '';
                document.getElementById('returnStatusFilter').value = '';
                
                // Reset to current month
                const today = new Date();
                const firstDayOfMonth = new Date(today.getFullYear(), today.getMonth(), 1);
                
                document.getElementById('returnDateFrom').value = PurchaseManager.formatDateForInput(firstDayOfMonth);
                document.getElementById('returnDateTo').value = PurchaseManager.formatDateForInput(today);
                
                this.currentPage = 1;
                this.loadReturns();
            },

            refreshReturns() {
                this.loadReturns(this.currentPage);
            },

            viewReturnDetails(returnId) {
                // Mock data - replace with actual API call
                const returnData = {
                    id: returnId,
                    purchase_invoice_id: 102,
                    supplier: { name: 'مورد التقنية المتطورة' },
                    return_type: 'wrong_item',
                    return_date: '2024-01-20',
                    status: 'completed',
                    reason: 'استلام منتج غير مطابق للمواصفات',
                    total_amount: 200.00,
                    items: [
                        {
                            product_name: 'ماوس لاسلكي',
                            batch_id: 'B002',
                            quantity: 2,
                            unit_cost: 100.00,
                            total_cost: 200.00
                        }
                    ]
                };

                this.renderReturnDetails(returnData);
                UIManager.openModal('viewReturnModal');
            },

            renderReturnDetails(returnData) {
                const modalBody = document.getElementById('returnDetails');
                if (!modalBody) return;

                const typeText = RETURN_TYPES[returnData.return_type] || returnData.return_type;
                const statusText = returnData.status === 'completed' ? 'مكتمل' : 
                                 returnData.status === 'pending' ? 'قيد المعالجة' : 'ملغي';
                
                modalBody.innerHTML = `
                    <div class="invoice-details-grid">
                        <div class="detail-card">
                            <div class="detail-label">رقم المرتجع</div>
                            <div class="detail-value">${returnData.id}</div>
                        </div>
                        <div class="detail-card">
                            <div class="detail-label">الفاتورة الأصلية</div>
                            <div class="detail-value">${returnData.purchase_invoice_id}</div>
                        </div>
                        <div class="detail-card">
                            <div class="detail-label">المورد</div>
                            <div class="detail-value">${returnData.supplier?.name}</div>
                        </div>
                        <div class="detail-card">
                            <div class="detail-label">نوع المرتجع</div>
                            <div class="detail-value">${typeText}</div>
                        </div>
                        <div class="detail-card">
                            <div class="detail-label">تاريخ المرتجع</div>
                            <div class="detail-value">${UIManager.formatDate(returnData.return_date)}</div>
                        </div>
                        <div class="detail-card">
                            <div class="detail-label">الحالة</div>
                            <div class="detail-value">${statusText}</div>
                        </div>
                        <div class="detail-card">
                            <div class="detail-label">القيمة الإجمالية</div>
                            <div class="detail-value">${UIManager.formatCurrency(returnData.total_amount)}</div>
                        </div>
                    </div>
                    
                    <div class="invoice-notes">
                        <h4>سبب المرتجع</h4>
                        <pre>${returnData.reason}</pre>
                    </div>
                    
                    <h4>المنتجات المرتجعة</h4>
                    <div class="table-wrapper">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>المنتج</th>
                                    <th>الدفعة</th>
                                    <th>الكمية</th>
                                    <th>السعر</th>
                                    <th>الإجمالي</th>
                                </tr>
                            </thead>
                            <tbody>
                `;

                returnData.items.forEach(item => {
                    modalBody.innerHTML += `
                        <tr>
                            <td>${item.product_name}</td>
                            <td>${item.batch_id}</td>
                            <td>${item.quantity}</td>
                            <td>${UIManager.formatCurrency(item.unit_cost)}</td>
                            <td>${UIManager.formatCurrency(item.total_cost)}</td>
                        </tr>
                    `;
                });

                modalBody.innerHTML += `
                            </tbody>
                        </table>
                    </div>
                `;
            },

            openNewReturnModal(invoiceId = null) {
                UIManager.showSuccess('هذه الميزة قيد التطوير', 'قريباً');
            },

            closeReturnModal() {
                UIManager.closeModal('newReturnModal');
            }
        };

        // ==================== HELPER FUNCTIONS ====================
        function removeFilter(tab, key) {
            if (UIManager.activeFilters[tab]) {
                delete UIManager.activeFilters[tab][key];
                
                if (tab === 'invoices') {
                    PurchaseManager.filterInvoices();
                } else if (tab === 'returns') {
                    ReturnManager.filterReturns();
                }
            }
        }

        // ==================== INITIALIZATION ====================
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize Purchase Manager
            PurchaseManager.init();
        });

        // Make managers available globally
        window.UIManager = UIManager;
        window.PurchaseManager = PurchaseManager;
        window.ReturnManager = ReturnManager;
    </script>
</body>
</html>

<?php require_once '../partials/footer.php'; ?>