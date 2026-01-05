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
            align-items: flex-start;
            justify-content: center;
            z-index: 1100;
            overflow-y: auto;
            padding: 20px 0;
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
            max-height: 85vh;
            display: flex;
            flex-direction: column;
            margin-top: 20px;
            margin-bottom: 20px;
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
            overflow-y: auto;
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
        
        /* تحسينات جديدة */
        .stats-card-enhanced {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        
        .stats-card-warning {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            color: white;
        }
        
        .stats-card-success {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            color: white;
        }
        
        .advanced-filter {
            transition: all 0.3s ease;
        }
        
        .advanced-filter:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 15px rgba(0, 0, 0, 0.1);
        }
        
        .quick-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }
        
        .quick-action-btn {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .quick-action-btn:hover {
            background: #e9ecef;
            border-color: #ced4da;
        }
        
        .quick-action-btn.active {
            background: #0b84ff;
            color: white;
            border-color: #0b84ff;
        }
        
        .return-quantity-badge {
            background: #f59e0b;
            color: white;
            font-size: 11px;
            padding: 2px 6px;
            border-radius: 10px;
            margin-right: 5px;
        }
        
        .invoice-item-returns {
            color: #f59e0b;
            font-size: 12px;
            margin-top: 3px;
        }
        
        .batch-returned-qty {
            background: #fef3c7;
            color: #92400e;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 11px;
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
                <!-- Quick Actions -->
                <div class="quick-actions">
                    <div class="quick-action-btn" onclick="PurchaseManager.setQuickFilter('today')">
                        <i class="fas fa-calendar-day"></i>
                        اليوم
                    </div>
                    <div class="quick-action-btn" onclick="PurchaseManager.setQuickFilter('week')">
                        <i class="fas fa-calendar-week"></i>
                        هذا الأسبوع
                    </div>
                    <div class="quick-action-btn" onclick="PurchaseManager.setQuickFilter('month')">
                        <i class="fas fa-calendar-alt"></i>
                        هذا الشهر
                    </div>
                    <div class="quick-action-btn" onclick="PurchaseManager.setQuickFilter('pending')">
                        <i class="fas fa-clock"></i>
                        قيد الانتظار
                    </div>
                    <div class="quick-action-btn" onclick="PurchaseManager.setQuickFilter('received')">
                        <i class="fas fa-check-circle"></i>
                        تم الاستلام
                    </div>
                </div>

                <!-- Invoices Filters -->
                <div class="filters-card advanced-filter">
                    <div class="filter-grid">
                        <div class="filter-group">
                            <label><i class="fas fa-user"></i> اسم المورد</label>
                            <div class="search-input-with-icon">
                                <input type="text" class="form-input" id="supplierNameFilter" 
                                       placeholder="اكتب للبحث عن مورد..."
                                                                           onkeyup="PurchaseManager.debouncedSearch()">

                                <i class="fas fa-search"></i>
                            </div>
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-hashtag"></i> رقم الفاتورة</label>
                            <div class="search-input-with-icon">
                                <input type="number" class="form-input" id="invoiceIdFilter" 
                                       placeholder="رقم الفاتورة"
                                       onkeyup="PurchaseManager.debouncedSearch()">
                                <i class="fas fa-hashtag"></i>
                            </div>
                        </div>
             <div class="filter-group">
    <label><i class="fas fa-calendar-alt"></i> من تاريخ</label>
    <div class="search-input-with-icon">
        <input type="date" class="form-input" id="dateFromFilter"
               onchange="PurchaseManager.debouncedSearch()">
        <!-- Input فارغ بالكامل -->
    </div>
</div>
<div class="filter-group">
    <label><i class="fas fa-calendar-alt"></i> إلى تاريخ</label>
    <div class="search-input-with-icon">
        <input type="date" class="form-input" id="dateToFilter"
               onchange="PurchaseManager.debouncedSearch()">
        <!-- Input فارغ بالكامل -->
    </div>
</div>


                        <div class="filter-group">
                            <label><i class="fas fa-info-circle"></i> الحالة</label>
                            <select class="form-select" id="statusFilter" onchange="PurchaseManager.debouncedSearch()">
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
                                إعادة تعيين
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
                </div>
            </div>

            <!-- ==================== RETURNS TAB ==================== -->
            <div id="returnsTab" class="tab-content hidden">
                <!-- Quick Actions -->
                <div class="quick-actions">
                    <div class="quick-action-btn" onclick="ReturnManager.setQuickFilter('today')">
                        <i class="fas fa-calendar-day"></i>
                        اليوم
                    </div>
                    <div class="quick-action-btn" onclick="ReturnManager.setQuickFilter('week')">
                        <i class="fas fa-calendar-week"></i>
                        هذا الأسبوع
                    </div>
                    <div class="quick-action-btn" onclick="ReturnManager.setQuickFilter('month')">
                        <i class="fas fa-calendar-alt"></i>
                        هذا الشهر
                    </div>
                    <div class="quick-action-btn" onclick="ReturnManager.setQuickFilter('pending')">
                        <i class="fas fa-clock"></i>
                        قيد الانتظار
                    </div>
                    <div class="quick-action-btn" onclick="ReturnManager.setQuickFilter('completed')">
                        <i class="fas fa-check-circle"></i>
                        مكتمل
                    </div>
                </div>

                <!-- Returns Filters -->
                <div class="filters-card advanced-filter">
                    <div class="filter-grid">
                        <div class="filter-group">
                            <label><i class="fas fa-user"></i> اسم المورد</label>
                            <div class="search-input-with-icon">
                                <input type="text" class="form-input" id="returnSupplierNameFilter" 
                                       placeholder="اكتب للبحث عن مورد..."
                                       onkeyup="ReturnManager.debouncedSearch()"
                                       >
                                <i class="fas fa-search"></i>
                            </div>
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-hashtag"></i> رقم الفاتورة الأصلية</label>
                            <div class="search-input-with-icon">
                                <input type="number" class="form-input" id="returnOriginalInvoiceFilter" 
                                       placeholder="رقم الفاتورة"
                                       onkeyup="ReturnManager.debouncedSearch()">
                                <i class="fas fa-hashtag"></i>
                            </div>
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-exchange-alt"></i> نوع المرتجع</label>
                            <select class="form-select" id="returnTypeFilter" onchange="ReturnManager.debouncedSearch()">
                                <option value="">كل الأنواع</option>
                                <option value="supplier_return">إرجاع للمورد</option>
                                <option value="damaged">تلف في المخزن</option>
                                <option value="expired">منتهي الصلاحية</option>
                                <option value="other">أخرى</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-info-circle"></i> حالة المرتجع</label>
                            <select class="form-select" id="returnStatusFilter" onchange="ReturnManager.debouncedSearch()">
                                <option value="">كل الحالات</option>
                                <option value="pending">قيد المعالجة</option>
                                <option value="approved">معتمد</option>
                                <option value="completed">مكتمل</option>
                                <option value="cancelled">ملغي</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-calendar-alt"></i> من تاريخ</label>
                            <div class="search-input-with-icon">
                                <input type="date" class="form-input" id="returnDateFrom"
                                       onchange="ReturnManager.debouncedSearch()">
                                <i class="fas fa-calendar"></i>
                            </div>
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-calendar-alt"></i> إلى تاريخ</label>
                            <div class="search-input-with-icon">
                                <input type="date" class="form-input" id="returnDateTo"
                                       onchange="ReturnManager.debouncedSearch()">
                                <i class="fas fa-calendar"></i>
                            </div>
                        </div>
                        <div class="filter-actions">
                            <button class="btn btn-primary" onclick="ReturnManager.filterReturns()">
                                <i class="fas fa-search"></i>
                                بحث
                            </button>
                            <button class="btn btn-light" onclick="ReturnManager.resetFilters()">
                                <i class="fas fa-redo"></i>
                                إعادة تعيين
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

    <!-- Select Invoice for Return Modal -->
    <div class="modal" id="selectInvoiceModal">
        <div class="modal-content">
            <div class="modal-header" style="background: var(--grad-2);">
                <h2><i class="fas fa-file-invoice"></i> اختيار فاتورة للارتجاع</h2>
                <button class="modal-close" onclick="UIManager.closeModal('selectInvoiceModal')">×</button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <div class="search-input-with-icon">
                        <input type="text" class="form-input" id="searchInvoiceForReturn" 
                               placeholder="ابحث برقم الفاتورة أو اسم المورد...">
                        <i class="fas fa-search"></i>
                    </div>
                </div>
                <div class="table-wrapper" style="max-height: 400px; overflow-y: auto;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>رقم الفاتورة</th>
                                <th>المورد</th>
                                <th>تاريخ الشراء</th>
                                <th>رقم فاتورة المورد</th>
                                <th>الإجمالي</th>
                                <th>اختيار</th>
                            </tr>
                        </thead>
                        <tbody id="availableInvoicesList">
                            <!-- سيتم تعبئته بواسطة JavaScript -->
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- ==================== SCRIPTS ==================== -->
    <script>
        // ==================== CONSTANTS & CONFIG ====================
        const API_BASE_URL = 'store_v1/api/purchase/api_purchase_invoices.php';
        const RETURNS_API_URL = 'store_v1/api/purchase/api_purchase_returns.php';
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
            excess: 'كمية زائدة',
            other: 'أخرى'
        };

        const RETURN_STATUS_LABELS = {
            pending: 'قيد المعالجة',
            approved: 'معتمد',
            completed: 'مكتمل',
            cancelled: 'ملغي'
        };

        // ==================== API MANAGER ====================
        const APIManager = {
            async callAPI(endpoint, action, params = {}, method = 'GET') {
                const url = new URL(endpoint, window.location.origin);
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
                
                return await this.callAPI(API_BASE_URL, 'list_invoices', params);
            },

            async fetchInvoiceDetails(invoiceId) {
                return await this.callAPI(API_BASE_URL, 'fetch_invoice', { id: invoiceId });
            },

            async fetchStatistics() {
                return await this.callAPI(API_BASE_URL, 'statistics');
            },

            async receiveInvoice(invoiceId) {
                return await this.callAPI(API_BASE_URL, 'receive_invoice', { 
                    invoice_id: invoiceId,
                    action: 'receive_invoice'
                }, 'POST');
            },

            async revertInvoice(invoiceId, reason) {
                return await this.callAPI(API_BASE_URL, 'revert_invoice', {
                    invoice_id: invoiceId,
                    reason: reason,
                    action: 'revert_invoice'
                }, 'POST');
            },

            async cancelInvoice(invoiceId, reason) {
                return await this.callAPI(API_BASE_URL, 'cancel_invoice', {
                    invoice_id: invoiceId,
                    reason: reason,
                    action: 'cancel_invoice'
                }, 'POST');
            },

            async deleteInvoiceItem(invoiceId, itemId, reason) {
                return await this.callAPI(API_BASE_URL, 'delete_item', {
                    invoice_id: invoiceId,
                    item_id: itemId,
                    reason: reason,
                    action: 'delete_item'
                }, 'POST');
            },

            async editInvoiceItems(invoiceId, items, adjustReason) {
                return await this.callAPI(API_BASE_URL, 'edit_invoice', {
                    invoice_id: invoiceId,
                    items: items,
                    adjust_reason: adjustReason,
                    action: 'edit_invoice'
                }, 'POST');
            },

            // Returns API
            async fetchReturns(filters = {}) {
                const params = {};
                if (filters.supplierName) params.supplier_filter_val = filters.supplierName;
                if (filters.originalInvoiceId) params.return_out_id = filters.originalInvoiceId;
                if (filters.returnType) params.type_filter_val = filters.returnType;
                if (filters.returnStatus) params.status_filter_val = filters.returnStatus;
                if (filters.dateFrom) params.start_date = filters.dateFrom;
                if (filters.dateTo) params.end_date = filters.dateTo;
                
                return await this.callAPI(RETURNS_API_URL, 'list_returns', params);
            },

            async fetchReturnDetails(returnId) {
                return await this.callAPI(RETURNS_API_URL, 'fetch_return', { id: returnId });
            },

            async fetchReturnsStatistics() {
                return await this.callAPI(RETURNS_API_URL, 'statistics');
            },

            async fetchAvailableInvoices() {
                return await this.callAPI(RETURNS_API_URL, 'available_invoices');
            },

            async fetchInvoiceBatches(invoiceId) {
                return await this.callAPI(RETURNS_API_URL, 'invoice_batches', { invoice_id: invoiceId });
            },

            async createReturn(data) {
                return await this.callAPI(RETURNS_API_URL, 'create_return', {
                    ...data,
                    action: 'create_return'
                }, 'POST');
            },

            async updateReturnStatus(returnId, newStatus, reason = '') {
                return await this.callAPI(RETURNS_API_URL, 'update_status', {
                    return_id: returnId,
                    new_status: newStatus,
                    reason: reason,
                    action: 'update_status'
                }, 'PUT');
            },

            async cancelReturn(returnId, reason) {
                return await this.callAPI(RETURNS_API_URL, 'cancel_return', {
                    return_id: returnId,
                    reason: reason,
                    action: 'cancel_return'
                }, 'PUT');
            }
        };

        // ==================== UI MANAGER ====================
        const UIManager = {
            currentLoader: null,

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
                
                // إذا انتقلنا لتبويب المرتجعات، تحميل البيانات
                if (tabName === 'returns') {
                    ReturnManager.loadReturns();
                }
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
                this.currentLoader = Swal.fire({
                    title: title,
                    text: text,
                    allowOutsideClick: false,
                    showConfirmButton: false,
                    willOpen: () => {
                        Swal.showLoading();
                    }
                });
                this.hideLoader();
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

            updateSummaryStats(stats, containerId) {
                const container = document.getElementById(containerId);
                if (!container) return;

                container.innerHTML = `
                    <div class="stat-card">
                        <h3>عدد الفواتير</h3>
                        <div class="stat-value">${stats.total_invoices || 0}</div>
                    </div>
                    <div class="stat-card">
                        <h3>اجمالي المشتريات المعروضه</h3>
                        <div class="stat-value">${this.formatCurrency(stats.displayed_sum || 0)}</div>
                    </div>
                    <div class="stat-card">
                        <h3>اجمالي المرتجعات المعروضه
                        <h3>
                        <div class="stat-value">${this.formatCurrency(stats.displayed_returns_sum || 0)}</div>
                    </div>
                    <div class="stat-card">
                        <h3> صافي المشتريات</h3>
                        <div class="stat-value">
                         
                        ${this.formatCurrency(stats.displayed_sum || 0 -  stats.displayed_returns_sum ) }
                        </div>
                    </div>
                `;
            }
        };

        // ==================== PURCHASE MANAGER ====================
        const PurchaseManager = {
            currentInvoiceId: null,
            currentInvoiceData: null,
            searchTimeout: null,

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

                // حساب الإجمالي والمرتجعات
                let totalPurchases = 0;
                let totalReturns = 0;

                if (stats.by_status) {
                    stats.by_status.forEach(item => {
                        if (item.status !== 'cancelled') {
                            totalPurchases += item.amount;
                        }
                    });
                }

                // Note: You might need to fetch returns statistics separately
                // For now, we'll show estimated values
                const netPurchases = totalPurchases - totalReturns;

                container.innerHTML = `
                    <div class="stats-card-enhanced">
                        <div style="display: flex; align-items: center; gap: 15px; margin-bottom: 15px;">
                            <div style="background: rgba(255,255,255,0.2); padding: 10px; border-radius: 10px;">
                                <i class="fas fa-shopping-cart" style="font-size: 24px;"></i>
                            </div>
                            <div>
                                <div style="font-size: 14px; opacity: 0.9;">إجمالي المشتريات</div>
                                <div style="font-size: 28px; font-weight: bold;">${UIManager.formatCurrency(totalPurchases)}</div>
                            </div>
                        </div>
                        <div style="font-size: 12px; opacity: 0.8;">
                            <i class="fas fa-info-circle"></i> القيمة الإجمالية للفواتير النشطة
                        </div>
                    </div>
                    
                    <div class="stats-card-enhanced stats-card-warning">
                        <div style="display: flex; align-items: center; gap: 15px; margin-bottom: 15px;">
                            <div style="background: rgba(255,255,255,0.2); padding: 10px; border-radius: 10px;">
                                <i class="fas fa-exchange-alt" style="font-size: 24px;"></i>
                            </div>
                            <div>
                                <div style="font-size: 14px; opacity: 0.9;">قيمة المرتجعات</div>
                                <div style="font-size: 28px; font-weight: bold;">${UIManager.formatCurrency(totalReturns)}</div>
                            </div>
                        </div>
                        <div style="font-size: 12px; opacity: 0.8;">
                            <i class="fas fa-info-circle"></i> إجمالي قيمة المرتجعات المعتمدة
                        </div>
                    </div>
                    
                    <div class="stats-card-enhanced stats-card-success">
                        <div style="display: flex; align-items: center; gap: 15px; margin-bottom: 15px;">
                            <div style="background: rgba(255,255,255,0.2); padding: 10px; border-radius: 10px;">
                                <i class="fas fa-calculator" style="font-size: 24px;"></i>
                            </div>
                            <div>
                                <div style="font-size: 14px; opacity: 0.9;">صافي المشتريات</div>
                                <div style="font-size: 28px; font-weight: bold;">${UIManager.formatCurrency(netPurchases)}</div>
                            </div>
                        </div>
                        <div style="font-size: 12px; opacity: 0.8;">
                            <i class="fas fa-info-circle"></i> الإجمالي - المرتجعات
                        </div>
                    </div>
                `;
            },

            setupEventListeners() {
                // Set default dates to current month
                const today = new Date();
                const firstDayOfMonth = new Date(today.getFullYear(), today.getMonth(), 1);
                
                document.getElementById('dateFromFilter').value = this.formatDateForInput(firstDayOfMonth);
                document.getElementById('dateToFilter').value = this.formatDateForInput(today);
                
                // Set default dates for returns too
                document.getElementById('returnDateFrom').value = this.formatDateForInput(firstDayOfMonth);
                document.getElementById('returnDateTo').value = this.formatDateForInput(today);
                
                // Add active class to quick actions
                document.querySelectorAll('.quick-action-btn').forEach(btn => {
                    btn.addEventListener('click', function() {
                        document.querySelectorAll('.quick-action-btn').forEach(b => {
                            b.classList.remove('active');
                        });
                        this.classList.add('active');
                    });
                });
            },

            formatDateForInput(date) {
                return date.toISOString().split('T')[0];
            },

            setQuickFilter(type) {
                const today = new Date();
                let startDate = new Date();
                let endDate = new Date();
                
                switch(type) {
                    case 'today':
                        startDate = today;
                        endDate = today;
                        break;
                    case 'week':
                        startDate = new Date(today.setDate(today.getDate() - today.getDay()));
                        endDate = new Date(today.setDate(today.getDate() - today.getDay() + 6));
                        break;
                    case 'month':
                        startDate = new Date(today.getFullYear(), today.getMonth(), 1);
                        endDate = new Date(today.getFullYear(), today.getMonth() + 1, 0);
                        break;
                    case 'pending':
                        document.getElementById('statusFilter').value = 'pending';
                        this.debouncedSearch();
                        return;
                    case 'received':
                        document.getElementById('statusFilter').value = 'fully_received';
                        this.debouncedSearch();
                        return;
                }
                
                document.getElementById('dateFromFilter').value = this.formatDateForInput(startDate);
                document.getElementById('dateToFilter').value = this.formatDateForInput(endDate);
                this.debouncedSearch();
            },

            debouncedSearch() {
                clearTimeout(this.searchTimeout);
                this.searchTimeout = setTimeout(() => {
                    this.filterInvoices();
                }, 300); // 300ms debounce delay
            },

            async loadInvoices() {
                UIManager.showLoading('invoicesTableLoading');


            ;
                
                try {
                    // Build filters from form
                    const filters = {
                        supplierName: document.getElementById('supplierNameFilter').value,
                        invoiceId: document.getElementById('invoiceIdFilter').value,
                        dateFrom: document.getElementById('dateFromFilter').value,
                        dateTo: document.getElementById('dateToFilter').value,
                        status: document.getElementById('statusFilter').value
                    };

                    const result = await APIManager.fetchInvoices(filters);
                    
                    if (result.success) {
                UIManager.showLoading('invoicesTableLoading');

                        this.renderInvoices(result.invoices);
                        UIManager.updateSummaryStats(result.statistics, 'invoicesSummaryStats');
                        
                        // Update badge count
                        const badge = document.getElementById('invoicesCountBadge');
                        if (badge) {
                            badge.textContent = result.statistics.total_invoices || 0;
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
                console.log(invoice);
                
                const row = document.createElement('tr');
                
                const statusBadge = this.getStatusBadge(invoice.status);
                const totalQuantity = invoice.total_quantity || 0;
                const returnsCount = invoice.returned_qty  || 0;
                
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
                            <div>${invoice.total_qty || 0} منتج</div>

                        </div>
                    </td>
                    <td class="fw-bold text-right">${UIManager.formatCurrency(invoice.total_amount)}</td>
                    <td>
                        ${returnsCount > 0 ? 
                            `<span class="badge badge-returned">${returnsCount} منتجات</span>` : 
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
                                    <th>الكمية المرتجعة</th>
                                    <th>سعر الشراء</th>
                                    <th>سعر البيع</th>
                                    <th>الإجمالي</th>
                                </tr>
                            </thead>
                            <tbody>
                `;

                items.forEach((item, index) => {
                    console.log(item);
                    
                    const returnedQty =parseFloat( item.qty_returned) || 0;
                    html += `
                        <tr>
                            <td>${index + 1}</td>
                            <td>${item.product_name || `منتج #${item.product_id}`}</td>
                            <td>${(item.quantity || 0).toFixed(2)}</td>
                            <td>${(item.qty_received || 0).toFixed(2)}</td>
                            <td>
                                ${returnedQty > 0 ? 
                                    `<span class="return-quantity-badge">${returnedQty.toFixed(2)}</span>` : 
                                    '0.00'
                                }
                            </td>
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
                                    <td colspan="7" class="fw-bold text-right">الإجمالي</td>
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
                                        <th>الكمية المرتجعة</th>
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
                                          batch.status === 'returned' ? 'مرجع' :
                                          batch.status === 'reverted' ? 'معاد' : 'ملغي';
                        
                        const returnedQty = (batch.qty || 0) - (batch.remaining || 0);
                        
                        html += `
                            <tr>
                                <td>B${batch.id.toString().padStart(4, '0')}</td>
                                <td>${batch.product_name || `منتج #${batch.product_id}`}</td>
                                <td>${batch.qty || 0}</td>
                                <td>${batch.remaining || 0}</td>
                                <td>
                                    ${returnedQty > 0 ? 
                                        `<span class="batch-returned-qty">${returnedQty}</span>` : 
                                        '0'
                                    }
                                </td>
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
                        await UIManager.showSuccess('تم حفظ التعديلات بنجاح');
                        UIManager.closeModal('editInvoiceModal');
                        await this.loadInvoices();
                        await this.viewInvoice(this.currentInvoiceId);
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
                            const { value: reason } = await Swal.fire({
                                title: 'سبب الحذف',
                                input: 'text',
                                inputLabel: 'يرجى إدخال سبب الحذف',
                                inputPlaceholder: 'أدخل سبب الحذف...',
                                showCancelButton: true,
                                confirmButtonText: 'تأكيد',
                                cancelButtonText: 'إلغاء'
                            });

                            if (reason && reason.trim() !== '') {
                                UIManager.showLoader('جاري حذف البند...');
                                
                                try {
                                    const result = await APIManager.deleteInvoiceItem(this.currentInvoiceId, itemId, reason);
                                    if (result.success) {
                                        await UIManager.showSuccess('تم حذف البند بنجاح');
                                        this.editInvoice(this.currentInvoiceId);
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
                        await UIManager.showSuccess(result.message || 'تم استلام الفاتورة بنجاح');
                        UIManager.closeModal('receiveInvoiceModal');
                        await this.loadInvoices();
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
                        await UIManager.showSuccess(result.message || 'تم إلغاء الفاتورة بنجاح');
                        UIManager.closeModal('cancelInvoiceModal');
                        await this.loadInvoices();
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
                            const { value: reason } = await Swal.fire({
                                title: 'سبب الإرجاع',
                                input: 'text',
                                inputLabel: 'يرجى إدخال سبب الإرجاع',
                                inputPlaceholder: 'أدخل سبب الإرجاع...',
                                showCancelButton: true,
                                confirmButtonText: 'تأكيد',
                                cancelButtonText: 'إلغاء'
                            });

                            if (reason && reason.trim() !== '') {
                                UIManager.showLoader('جاري إرجاع الفاتورة...');
                                
                                try {
                                    const result = await APIManager.revertInvoice(invoiceId, reason);
                                    if (result.success) {
                                        await UIManager.showSuccess(result.message || 'تم إرجاع الفاتورة بنجاح');
                                        UIManager.closeModal('viewInvoiceModal');
                                        await this.loadInvoices();
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
                        }
                    });
            },

            filterInvoices() {
                this.loadInvoices();
            },

            resetFilters() {
                document.getElementById('supplierNameFilter').value = '';
                document.getElementById('invoiceIdFilter').value = '';
                document.getElementById('statusFilter').value = '';
            const today = new Date();
const firstDayLastYear = new Date(today.getFullYear() - 1, 0, 1); // 0 = يناير
document.getElementById('dateFromFilter').value = this.formatDateForInput(firstDayLastYear);
document.getElementById('dateToFilter').value = this.formatDateForInput(today);

                this.loadInvoices();
                
                // Reset quick actions
                document.querySelectorAll('.quick-action-btn').forEach(b => {
                    b.classList.remove('active');
                });
            },

            refreshInvoices() {
                this.loadInvoices();
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

            generateA4PrintContent(data) {
                const invoice = data.invoice;
                const items = data.items || [];
                const batches = data.batches || [];
                
                const date = new Date(invoice.purchase_date || new Date());
                const formattedDate = date.toLocaleDateString('ar-EG');
                const timeString = date.toLocaleTimeString('ar-EG', { hour: '2-digit', minute: '2-digit' });
                const statusText = STATUS_LABELS[invoice.status] || invoice.status;

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
                            <td style="text-align:center; padding: 8px; border-bottom: 1px solid #ddd;">${(item.qty_received || 0).toFixed(2)}</td>
                            <td style="text-align:center; padding: 8px; border-bottom: 1px solid #ddd;">${(item.cost_price_per_unit || 0).toFixed(2)}</td>
                            <td style="text-align:center; padding: 8px; border-bottom: 1px solid #ddd;">${itemTotal.toFixed(2)} ج.م</td>
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
                                <div style="font-size: 18px; margin-top: 10px;">فاتورة مشتريات #${invoice.id}</div>
                            </div>
                            
                            <div class="invoice-info">
                                <div>
                                    <div><strong>رقم الفاتورة:</strong> ${invoice.id}</div>
                                    <div><strong>التاريخ:</strong> ${formattedDate}</div>
                                    <div><strong>الوقت:</strong> ${timeString}</div>
                                    <div><strong>الحالة:</strong> ${statusText}</div>
                                </div>
                                <div>
                                    <div><strong>رقم فاتورة المورد:</strong> ${invoice.supplier_invoice_number || '-'}</div>
                                    <div><strong>المورد:</strong> ${invoice.supplier_name || 'غير معروف'}</div>
                                    <div><strong>المنشئ:</strong> ${invoice.creator_name || '-'}</div>
                                </div>
                            </div>
                            
                            <table>
                                <thead>
                                    <tr>
                                        <th width="5%">#</th>
                                        <th width="35%">المنتج</th>
                                        <th width="12%">الكمية</th>
                                        <th width="12%">المستلمة</th>
                                        <th width="12%">سعر الشراء</th>
                                        <th width="24%">الإجمالي</th>
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

            generatePOSPrintContent(data) {
                const invoice = data.invoice;
                const items = data.items || [];
                
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
                            <td style="width:50%; text-align:right; padding: 4px 2px; border-bottom: 1px dashed #ddd; font-size: 12px;">
                                ${item.product_name || `منتج #${item.product_id}`}
                            </td>
                            <td style="width:15%; text-align:center; padding: 4px 2px; border-bottom: 1px dashed #ddd; font-size: 12px;">
                                ${(item.quantity || 0).toFixed(2)}
                            </td>
                            <td style="width:15%; text-align:center; padding: 4px 2px; border-bottom: 1px dashed #ddd; font-size: 12px;">
                                ${(item.cost_price_per_unit || 0).toFixed(2)}
                            </td>
                            <td style="width:20%; text-align:left; padding: 4px 2px; border-bottom: 1px dashed #ddd; font-size: 12px;">
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
                                <div class="store-name">نظام إدارة المشتريات</div>
                                <div class="invoice-title">فاتورة مشتريات #${invoice.id}</div>
                            </div>
                            
                            <div class="invoice-meta">
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
                                <div><strong>المورد:</strong> ${invoice.supplier_name || 'غير معروف'}</div>
                            </div>
                            
                            <div class="items-header">
                                <div style="width:50%; text-align:right;">المنتج</div>
                                <div style="width:15%; text-align:center;">الكمية</div>
                                <div style="width:15%; text-align:center;">السعر</div>
                                <div style="width:20%; text-align:left;">الإجمالي</div>
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
                const content = this.generateInvoicePrintContent(this.currentInvoiceData, 'A4');
                
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
                const content = this.generateInvoicePrintContent(this.currentInvoiceData, 'POS');
                
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
            currentInvoiceId: null,
            selectedBatches: [],
            searchTimeout: null,

            async loadReturns() {
                UIManager.showLoading('returnsTableLoading');
                
                try {
                    // Build filters from form
                    const filters = {
                        supplierName: document.getElementById('returnSupplierNameFilter').value,
                        originalInvoiceId: document.getElementById('returnOriginalInvoiceFilter').value,
                        returnType: document.getElementById('returnTypeFilter').value,
                        returnStatus: document.getElementById('returnStatusFilter').value,
                        dateFrom: document.getElementById('returnDateFrom').value,
                        dateTo: document.getElementById('returnDateTo').value
                    };

                    const result = await APIManager.fetchReturns(filters);
                    
                    if (result.success) {
                        this.renderReturns(result.returns);
                        
                        // Update badge count
                        const badge = document.getElementById('returnsCountBadge');
                        if (badge) {
                            badge.textContent = result.statistics.total_returns || 0;
                        }
                    } else {
                        UIManager.showError(result.message || 'فشل تحميل المرتجعات');
                    }
                } catch (error) {
                    console.error('Error loading returns:', error);
                    UIManager.showError('حدث خطأ أثناء تحميل المرتجعات');
                } finally {
                    UIManager.hideLoading('returnsTableLoading');
                }
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
                
                row.innerHTML = `
                    <td class="fw-bold">${ret.return_number}</td>
                    <td>${ret.purchase_invoice_id}</td>
                    <td>${ret.supplier_name || 'غير معروف'}</td>
                    <td><span class="badge">${typeText}</span></td>
                    <td>${UIManager.formatDate(ret.return_date)}</td>
                    <td>${ret.total_quantity || 0} منتج</td>
                    <td class="fw-bold text-right">${UIManager.formatCurrency(ret.total_amount)}</td>
                    <td>${statusBadge}</td>
                    <td>
                        <div class="action-buttons">
                            <button class="btn btn-info btn-sm" onclick="ReturnManager.viewReturnDetails(${ret.id})">
                                <i class="fas fa-eye"></i>
                                عرض
                            </button>
                            ${ret.status === 'pending' ? `
                                <button class="btn btn-success btn-sm" onclick="ReturnManager.approveReturn(${ret.id})">
                                    <i class="fas fa-check"></i>
                                    اعتماد
                                </button>
                                <button class="btn btn-danger btn-sm" onclick="ReturnManager.cancelReturnPrompt(${ret.id})">
                                    <i class="fas fa-times"></i>
                                    إلغاء
                                </button>
                            ` : ''}
                            ${ret.status === 'approved' ? `
                                <button class="btn btn-success btn-sm" onclick="ReturnManager.completeReturn(${ret.id})">
                                    <i class="fas fa-check-circle"></i>
                                    إكمال
                                </button>
                            ` : ''}
                            ${ret.status === 'completed' ? `
                                <button class="btn btn-danger btn-sm" onclick="ReturnManager.cancelReturnPrompt(${ret.id})">
                                    <i class="fas fa-undo"></i>
                                    استرجاع
                                </button>
                            ` : ''}
                        </div>
                    </td>
                `;
                
                return row;
            },

            getReturnStatusBadge(status) {
                switch(status) {
                    case 'pending': return '<span class="badge badge-pending">قيد المعالجة</span>';
                    case 'approved': return '<span class="badge badge-warning">معتمد</span>';
                    case 'completed': return '<span class="badge badge-received">مكتمل</span>';
                    case 'cancelled': return '<span class="badge badge-cancelled">ملغي</span>';
                    default: return '<span class="badge">غير معروف</span>';
                }
            },

            setQuickFilter(type) {
                const today = new Date();
                let startDate = new Date();
                let endDate = new Date();
                
                switch(type) {
                    case 'today':
                        startDate = today;
                        endDate = today;
                        break;
                    case 'week':
                        startDate = new Date(today.setDate(today.getDate() - today.getDay()));
                        endDate = new Date(today.setDate(today.getDate() - today.getDay() + 6));
                        break;
                    case 'month':
                        startDate = new Date(today.getFullYear(), today.getMonth(), 1);
                        endDate = new Date(today.getFullYear(), today.getMonth() + 1, 0);
                        break;
                    case 'pending':
                        document.getElementById('returnStatusFilter').value = 'pending';
                        this.debouncedSearch();
                        return;
                    case 'completed':
                        document.getElementById('returnStatusFilter').value = 'completed';
                        this.debouncedSearch();
                        return;
                }
                
                document.getElementById('returnDateFrom').value = PurchaseManager.formatDateForInput(startDate);
                document.getElementById('returnDateTo').value = PurchaseManager.formatDateForInput(endDate);
                this.debouncedSearch();
            },

            debouncedSearch() {
                clearTimeout(this.searchTimeout);
                this.searchTimeout = setTimeout(() => {
                    this.filterReturns();
                }, 300);
            },

            filterReturns() {
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
                
                this.loadReturns();
                
                // Reset quick actions
                document.querySelectorAll('.quick-action-btn').forEach(b => {
                    b.classList.remove('active');
                });
            },

            refreshReturns() {
                this.loadReturns();
            },

            async viewReturnDetails(returnId) {
                UIManager.showLoader('جاري تحميل تفاصيل المرتجع...');
                
                try {
                    const result = await APIManager.fetchReturnDetails(returnId);
                    if (result.success) {
                        this.renderReturnDetails(result);
                        UIManager.openModal('viewReturnModal');
                    } else {
                        UIManager.showError(result.message);
                    }
                } catch (error) {
                    console.error('Error loading return details:', error);
                    UIManager.showError('حدث خطأ أثناء تحميل التفاصيل');
                } finally {
                    UIManager.hideLoader();
                }
            },

            renderReturnDetails(data) {
                const modalBody = document.getElementById('returnDetails');
                if (!modalBody) return;

                const returnData = data.return;
                const items = data.items || [];
                const labels = data.labels || {};
                
                const typeText = labels.return_type && labels.return_type[returnData.return_type] ? 
                                labels.return_type[returnData.return_type] : 
                                RETURN_TYPES[returnData.return_type] || returnData.return_type;
                
                const statusText = labels.status && labels.status[returnData.status] ? 
                                 labels.status[returnData.status] : 
                                 RETURN_STATUS_LABELS[returnData.status] || returnData.status;

                modalBody.innerHTML = `
                    <div class="invoice-details-grid">
                        <div class="detail-card">
                            <div class="detail-label">رقم المرتجع</div>
                            <div class="detail-value">${returnData.return_number}</div>
                        </div>
                        <div class="detail-card">
                            <div class="detail-label">الفاتورة الأصلية</div>
                            <div class="detail-value">${returnData.purchase_invoice_id}</div>
                        </div>
                        <div class="detail-card">
                            <div class="detail-label">رقم فاتورة المورد</div>
                            <div class="detail-value">${returnData.supplier_invoice_number || '-'}</div>
                        </div>
                        <div class="detail-card">
                            <div class="detail-label">المورد</div>
                            <div class="detail-value">${returnData.supplier_name}</div>
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
                        <div class="detail-card">
                            <div class="detail-label">المنشئ</div>
                            <div class="detail-value">${returnData.creator_name || '-'}</div>
                        </div>
                        ${returnData.approver_name ? `
                            <div class="detail-card">
                                <div class="detail-label">المعتمد</div>
                                <div class="detail-value">${returnData.approver_name}</div>
                            </div>
                        ` : ''}
                        ${returnData.approved_at ? `
                            <div class="detail-card">
                                <div class="detail-label">تاريخ الاعتماد</div>
                                <div class="detail-value">${UIManager.formatDateTime(returnData.approved_at)}</div>
                            </div>
                        ` : ''}
                    </div>
                    
                    <div class="invoice-notes">
                        <h4>سبب المرتجع</h4>
                        <pre>${returnData.return_reason || 'لا يوجد'}</pre>
                    </div>
                    
                    <h4>المنتجات المرتجعة (${items.length})</h4>
                    <div class="table-wrapper">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>المنتج</th>
                                    <th>كود المنتج</th>
                                    <th>الدفعة</th>
                                    <th>الكمية</th>
                                    <th>السعر</th>
                                    <th>الإجمالي</th>
                                    <th>السبب</th>
                                </tr>
                            </thead>
                            <tbody>
                `;

                items.forEach((item, index) => {
                    modalBody.innerHTML += `
                        <tr>
                            <td>${index + 1}</td>
                            <td>${item.product_name}</td>
                            <td>${item.product_code || '-'}</td>
                            <td>B${item.batch_id.toString().padStart(4, '0')}</td>
                            <td>${item.quantity}</td>
                            <td>${UIManager.formatCurrency(item.unit_cost)}</td>
                            <td>${UIManager.formatCurrency(item.total_cost)}</td>
                            <td>${item.reason || '-'}</td>
                        </tr>
                    `;
                });

                modalBody.innerHTML += `
                            </tbody>
                        </table>
                    </div>
                `;
            },

            async openNewReturnModal(invoiceId = null) {
                if (invoiceId) {
                    // Directly open return modal for specific invoice
                    await this.loadInvoiceBatches(invoiceId);
                } else {
                    // Show invoice selection modal
                    await this.showInvoiceSelectionModal();
                }
            },

            async showInvoiceSelectionModal() {
                UIManager.showLoader('جاري تحميل الفواتير المتاحة...');
                
                try {
                    const result = await APIManager.fetchAvailableInvoices();
                    if (result.success) {
                        this.renderInvoiceSelection(result.invoices);
                        UIManager.openModal('selectInvoiceModal');
                        
                        // Add search functionality
                        document.getElementById('searchInvoiceForReturn').addEventListener('keyup', (e) => {
                            const searchTerm = e.target.value.toLowerCase();
                            const rows = document.querySelectorAll('#availableInvoicesList tr');
                            
                            rows.forEach(row => {
                                const text = row.textContent.toLowerCase();
                                row.style.display = text.includes(searchTerm) ? '' : 'none';
                            });
                        });
                    } else {
                        UIManager.showError(result.message || 'فشل تحميل الفواتير');
                    }
                } catch (error) {
                    console.error('Error loading available invoices:', error);
                    UIManager.showError('حدث خطأ أثناء تحميل الفواتير');
                } finally {
                    UIManager.hideLoader();
                }
            },

            renderInvoiceSelection(invoices) {
                const tbody = document.getElementById('availableInvoicesList');
                if (!tbody) return;

                tbody.innerHTML = '';

                if (invoices.length === 0) {
                    tbody.innerHTML = `
                        <tr>
                            <td colspan="6" class="text-center" style="padding: 20px;">
                                <div style="color: var(--muted);">
                                    <i class="fas fa-search" style="margin-bottom: 10px; font-size: 24px;"></i>
                                    <div>لا توجد فواتير متاحة للارتجاع</div>
                                </div>
                            </td>
                        </tr>
                    `;
                    return;
                }

                invoices.forEach(invoice => {
                    const row = document.createElement('tr');
                    row.innerHTML = `
                        <td>${invoice.id}</td>
                        <td>${invoice.supplier_name}</td>
                        <td>${UIManager.formatDate(invoice.purchase_date)}</td>
                        <td>${invoice.supplier_invoice_number || '-'}</td>
                        <td>${UIManager.formatCurrency(invoice.total_amount)}</td>
                        <td>
                            <button class="btn btn-primary btn-sm" onclick="ReturnManager.loadInvoiceBatches(${invoice.id})">
                                <i class="fas fa-check"></i>
                                اختيار
                            </button>
                        </td>
                    `;
                    tbody.appendChild(row);
                });
            },

            async loadInvoiceBatches(invoiceId) {
                this.currentInvoiceId = invoiceId;
                UIManager.showLoader('جاري تحميل دفعات الفاتورة...');
                
                try {
                    const result = await APIManager.fetchInvoiceBatches(invoiceId);
                    if (result.success) {
                        this.renderReturnModal(result);
                        UIManager.closeModal('selectInvoiceModal');
                        UIManager.openModal('newReturnModal');
                    } else {
                        UIManager.showError(result.message || 'فشل تحميل دفعات الفاتورة');
                    }
                } catch (error) {
                    console.error('Error loading invoice batches:', error);
                    UIManager.showError('حدث خطأ أثناء تحميل دفعات الفاتورة');
                } finally {
                    UIManager.hideLoader();
                }
            },

            renderReturnModal(data) {
                const modalBody = document.getElementById('newReturnBody');
                if (!modalBody) return;

                console.log(data.batches);
                console.log(data.invoice);
                
                const invoice = data.invoice;
                const batches = data.batches || [];
                this.selectedBatches = [];

                let html = `
                    <div class="invoice-details-grid mb-4">
                        <div class="detail-card">
                            <div class="detail-label">الفاتورة الأصلية</div>
                            <div class="detail-value">#${invoice.id}</div>
                        </div>
                        <div class="detail-card">
                            <div class="detail-label">المورد</div>
                            <div class="detail-value">${invoice.supplier_name}</div>
                        </div>
                        <div class="detail-card">
                            <div class="detail-label">تاريخ الشراء</div>
                            <div class="detail-value">${UIManager.formatDate(invoice.purchase_date)}</div>
                        </div>
                        <div class="detail-card">
                            <div class="detail-label">رقم فاتورة المورد</div>
                            <div class="detail-value">${invoice.supplier_invoice_number || '-'}</div>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="detail-label">تاريخ المرتجع</label>
                        <input type="date" class="form-input" id="returnDate" value="${new Date().toISOString().split('T')[0]}">
                    </div>

                    <div class="mb-4">
                        <label class="detail-label">نوع المرتجع</label>
                        <select class="form-select" id="returnType">
                            <option value="supplier_return">إرجاع للمورد</option>
                            <option value="damaged">تلف في المخزن</option>
                            <option value="expired">منتهي الصلاحية</option>
                            <option value="wrong_item">منتج خاطئ</option>
                            <option value="excess">كمية زائدة</option>
                            <option value="other">أخرى</option>
                        </select>
                    </div>

                    <div class="mb-4">
                        <label class="detail-label">سبب المرتجع</label>
                        <textarea class="form-input" id="returnReason" rows="3" placeholder="أدخل سبب المرتجع..."></textarea>
                    </div>

                    <h4>الدفعات المتاحة للإرجاع</h4>
                `;

                if (batches.length === 0) {
                    html += `
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle"></i>
                            لا توجد دفعات متاحة للإرجاع في هذه الفاتورة
                        </div>
                    `;
                } else {
                    html += `
                        <div class="table-wrapper" style="max-height: 300px; overflow-y: auto;">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th width="5%">#</th>
                                        <th>المنتج</th>
                                        <th>الدفعة</th>
                                        <th>الكمية الأصلية</th>
                                        <th>المتاح</th>
                                        <th>تم إرجاعه</th>
                                        <th>الكمية للإرجاع</th>
                                        <th>سبب</th>
                                    </tr>
                                </thead>
                                <tbody>
                    `;

                    batches.forEach((batch, index) => {
                        html += `
                            <tr>
                                <td>${index + 1}</td>
                                <td>${batch.product_name}</td>
                                <td>${batch.batch_number}</td>
                                <td>${batch.original_qty}</td>
                                <td>${batch.remaining}</td>
                                <td>${batch.already_returned || 0}</td>
                                <td>
                                    <input type="number" 
                                           class="form-input return-quantity" 
                                           data-batch-id="${batch.id}"
                                           data-max="${batch.max_returnable}"
                                           min="0" 
                                           max="${batch.max_returnable}"
                                           step="0.01"
                                           value="0"
                                           style="width: 100px;">
                                </td>
                                <td>
                                    <input type="text" 
                                           class="form-input item-reason" 
                                           data-batch-id="${batch.id}"
                                           placeholder="سبب خاص..."
                                           style="width: 150px;">
                                </td>
                            </tr>
                        `;
                    });

                    html += `
                                </tbody>
                            </table>
                        </div>

                        <div class="mt-4">
                            <button class="btn btn-success" onclick="ReturnManager.submitReturn()">
                                <i class="fas fa-check"></i>
                                إنشاء المرتجع
                            </button>
                            <button class="btn btn-light" onclick="ReturnManager.closeReturnModal()">
                                إلغاء
                            </button>
                        </div>
                    `;
                }

                modalBody.innerHTML = html;
            },

            async submitReturn() {
                const returnDate = document.getElementById('returnDate').value;
                const returnType = document.getElementById('returnType').value;
                const returnReason = document.getElementById('returnReason').value.trim();
                
                if (!returnDate) {
                    UIManager.showError('يرجى تحديد تاريخ المرتجع');
                    return;
                }
                
                if (!returnReason) {
                    UIManager.showError('يرجى إدخال سبب المرتجع');
                    return;
                }

                // Collect selected batches
                const items = [];
                let hasItems = false;
                
                document.querySelectorAll('.return-quantity').forEach(input => {
                    // debugger
                    const batchId = input.dataset.batchId;
                    const quantity = parseFloat(input.value) || 0;

                    console.log(input.dataset.max);
                    console.log(quantity);
                    
                    
                    
                    if (quantity > 0) {
                        const maxQuantity = parseFloat(input.dataset.max);
                        if (quantity > maxQuantity) {
                            UIManager.showError(`الكمية المرتجعة (${quantity}) أكبر من الكمية المتاحة (${maxQuantity})`);
                            throw new Error('Invalid quantity');
                        }
                        
                        const itemReason = document.querySelector(`.item-reason[data-batch-id="${batchId}"]`)?.value || '';
                        
                        items.push({
                            batch_id: parseInt(batchId),
                            quantity: quantity,
                            reason: itemReason
                        });
                        
                        hasItems = true;
                    }
                });
                
                if (!hasItems) {
                    UIManager.showError('يرجى تحديد كميات للإرجاع');
                    return;
                }

                UIManager.showLoader('جاري إنشاء المرتجع...');
                
                try {
                    const result = await APIManager.createReturn({
                        purchase_invoice_id: this.currentInvoiceId,
                        return_date: returnDate,
                        return_type: returnType,
                        return_reason: returnReason,
                        items: items
                    });
                    
                    if (result.success) {
                        await UIManager.showSuccess(`تم إنشاء المرتجع بنجاح برقم ${result.return_number}`);
                        ReturnManager.closeReturnModal();
                        await this.loadReturns();
                        
                        // Reload invoices to update returns value
                        await PurchaseManager.loadInvoices();
                    } else {
                        UIManager.showError(result.message || 'فشل إنشاء المرتجع');
                    }
                } catch (error) {
                    console.error('Error creating return:', error);
                    UIManager.showError('حدث خطأ أثناء إنشاء المرتجع');
                } finally {
                    UIManager.hideLoader();
                }
            },

            closeReturnModal() {
                this.selectedBatches = [];
                UIManager.closeModal('newReturnModal');
                UIManager.closeModal('selectInvoiceModal');
            },

            async approveReturn(returnId) {
                UIManager.showConfirm('هل تريد اعتماد هذا المرتجع؟', 'تأكيد الاعتماد')
                    .then(async result => {
                        if (result.isConfirmed) {
                            UIManager.showLoader('جاري اعتماد المرتجع...');
                            
                            try {
                                const result = await APIManager.updateReturnStatus(returnId, 'approved');
                                if (result.success) {
                                    await UIManager.showSuccess('تم اعتماد المرتجع بنجاح');
                                    await this.loadReturns();
                                } else {
                                    UIManager.showError(result.message || 'فشل اعتماد المرتجع');
                                }
                            } catch (error) {
                                console.error('Error approving return:', error);
                                UIManager.showError('حدث خطأ أثناء اعتماد المرتجع');
                            } finally {
                                UIManager.hideLoader();
                            }
                        }
                    });
            },

            async completeReturn(returnId) {
                UIManager.showConfirm('هل تريد إكمال هذا المرتجع؟', 'تأكيد الإكمال')
                    .then(async result => {
                        if (result.isConfirmed) {
                            UIManager.showLoader('جاري إكمال المرتجع...');
                            
                            try {
                                const result = await APIManager.updateReturnStatus(returnId, 'completed');
                                if (result.success) {
                                    await UIManager.showSuccess('تم إكمال المرتجع بنجاح');
                                    await this.loadReturns();
                                    
                                    // Reload invoices to update returns value
                                    await PurchaseManager.loadInvoices();
                                } else {
                                    UIManager.showError(result.message || 'فشل إكمال المرتجع');
                                }
                            } catch (error) {
                                console.error('Error completing return:', error);
                                UIManager.showError('حدث خطأ أثناء إكمال المرتجع');
                            } finally {
                                UIManager.hideLoader();
                            }
                        }
                    });
            },

            cancelReturnPrompt(returnId) {
                UIManager.showConfirm('هل تريد إلغاء هذا المرتجع؟', 'تأكيد الإلغاء')
                    .then(async result => {
                        if (result.isConfirmed) {
                            const { value: reason } = await Swal.fire({
                                title: 'سبب الإلغاء',
                                input: 'text',
                                inputLabel: 'يرجى إدخال سبب الإلغاء',
                                inputPlaceholder: 'أدخل سبب الإلغاء...',
                                showCancelButton: true,
                                confirmButtonText: 'تأكيد',
                                cancelButtonText: 'إلغاء'
                            });

                            if (reason && reason.trim() !== '') {
                                UIManager.showLoader('جاري إلغاء المرتجع...');
                                
                                try {
                                    const result = await APIManager.cancelReturn(returnId, reason);
                                    if (result.success) {
                                        await UIManager.showSuccess('تم إلغاء المرتجع بنجاح');
                                        await this.loadReturns();
                                        
                                        // Reload invoices to update returns value
                                        await PurchaseManager.loadInvoices();
                                    } else {
                                        UIManager.showError(result.message || 'فشل إلغاء المرتجع');
                                    }
                                } catch (error) {
                                    console.error('Error cancelling return:', error);
                                    UIManager.showError('حدث خطأ أثناء إلغاء المرتجع');
                                } finally {
                                    UIManager.hideLoader();
                                }
                            }
                        }
                    });
            }
        };

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