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
    
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <!-- Select2 for better dropdowns -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <style>
        .select2-container--default .select2-selection--single {
            height: 42px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            padding: 8px;
        }
        .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: 26px;
            text-align: right;
        }
        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 40px;
        }
        .select2-container--default .select2-search--dropdown .select2-search__field {
            border: 1px solid #d1d5db;
            border-radius: 6px;
        }
        .select2-dropdown {
            border: 1px solid #d1d5db;
            border-radius: 8px;
        }
        .search-highlight {
            background-color: #fff3cd;
            font-weight: bold;
        }
        .date-range-picker {
            display: flex;
            gap: 8px;
            align-items: center;
        }
        .date-range-picker input {
            flex: 1;
        }
        .quick-filters {
            display: flex;
            gap: 8px;
            margin-bottom: 16px;
            flex-wrap: wrap;
        }
        .quick-filter-btn {
            padding: 6px 12px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            background: white;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.2s;
        }
        .quick-filter-btn:hover {
            background: #f3f4f6;
        }
        .quick-filter-btn.active {
            background: #0b84ff;
            color: white;
            border-color: #0b84ff;
        }
        .stats-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 12px;
            margin-left: 8px;
        }
        .stats-badge.success {
            background: #d1fae5;
            color: #065f46;
        }
        .stats-badge.warning {
            background: #fef3c7;
            color: #92400e;
        }
        .stats-badge.danger {
            background: #fee2e2;
            color: #991b1b;
        }
        .stats-badge.info {
            background: #dbeafe;
            color: #1e40af;
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
        .advanced-search-toggle {
            color: #0b84ff;
            cursor: pointer;
            font-size: 14px;
            margin-top: 8px;
        }
        .advanced-search-toggle:hover {
            text-decoration: underline;
        }
        .advanced-filters {
            display: none;
            margin-top: 16px;
            padding: 16px;
            background: #f9fafb;
            border-radius: 8px;
            border: 1px solid #e5e7eb;
        }
        .advanced-filters.active {
            display: block;
            animation: slideDown 0.3s ease;
        }
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
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

            <!-- Global Search -->
            <div class="global-search">
                <div class="global-search-wrapper">
                    <input type="text" class="global-search-input" id="globalSearch" 
                           placeholder="ابحث في الموردين، الفواتير، المرتجعات..." 
                           onkeyup="GlobalSearch.handleSearch(event)">
                    <div class="quick-filters" id="globalQuickFilters" style="margin-top: 8px;">
                        <button class="quick-filter-btn" onclick="GlobalSearch.quickFilter('today')">اليوم</button>
                        <button class="quick-filter-btn" onclick="GlobalSearch.quickFilter('week')">هذا الأسبوع</button>
                        <button class="quick-filter-btn" onclick="GlobalSearch.quickFilter('month')">هذا الشهر</button>
                        <button class="quick-filter-btn" onclick="GlobalSearch.quickFilter('pending')">قيد الانتظار</button>
                    </div>
                </div>
            </div>

            <!-- Header Actions -->
            <div class="header-actions">
                <button class="btn btn-primary" onclick="PurchaseManager.openNewInvoiceModal()">
                    <i class="fas fa-plus"></i>
                    فاتورة شراء جديدة
                </button>
                <!-- <button class="btn btn-info" onclick="UIManager.switchTab('analytics')">
                    <i class="fas fa-chart-line"></i>
                    التحليلات
                </button> -->
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
                    <!-- <button class="tab" onclick="UIManager.switchTab('analytics')">
                        <i class="fas fa-chart-pie"></i>
                        التحليلات
                    </button> -->
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
                            <select class="form-select select2-supplier" id="supplierFilter" style="width: 100%;">
                                <option value="">كل الموردين</option>
                                <!-- سيتم تعبئته بواسطة JavaScript -->
                            </select>
                        </div>
                        <div class="filter-group">
                            <label>رقم الفاتورة</label>
                            <div class="search-input-with-icon">
                                <input type="number" class="form-input" id="invoiceIdFilter" placeholder="رقم الفاتورة">
                                <i class="fas fa-search"></i>
                            </div>
                        </div>
                        <div class="filter-group">
                            <label>نطاق التاريخ</label>
                            <div class="date-range-picker">
                                <input type="date" class="form-input" id="dateFromFilter">
                                <span>إلى</span>
                                <input type="date" class="form-input" id="dateToFilter">
                            </div>
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
                        <div class="filter-group">
                            <label>بحث متقدم</label>
                            <div class="advanced-search-toggle" onclick="toggleAdvancedSearch('invoices')">
                                <i class="fas fa-sliders-h"></i> بحث متقدم
                            </div>
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
                    
                    <!-- Advanced Filters -->
                    <div class="advanced-filters" id="invoicesAdvancedFilters">
                        <div class="filter-grid">
                            <div class="filter-group">
                                <label>رقم فاتورة المورد</label>
                                <input type="text" class="form-input" id="supplierInvoiceNumberFilter" placeholder="رقم فاتورة المورد">
                            </div>
                            <div class="filter-group">
                                <label>المبلغ الأدنى</label>
                                <input type="number" class="form-input" id="minAmountFilter" placeholder="المبلغ الأدنى">
                            </div>
                            <div class="filter-group">
                                <label>المبلغ الأقصى</label>
                                <input type="number" class="form-input" id="maxAmountFilter" placeholder="المبلغ الأقصى">
                            </div>
                            <div class="filter-group">
                                <label>المنشئ</label>
                                <select class="form-select" id="createdByFilter">
                                    <option value="">كل المنشئين</option>
                                    <!-- سيتم تعبئته من API -->
                                </select>
                            </div>
                            <div class="filter-group">
                                <label>ترتيب النتائج</label>
                                <select class="form-select" id="sortByFilter">
                                    <option value="newest">الأحدث أولاً</option>
                                    <option value="oldest">الأقدم أولاً</option>
                                    <option value="highest">الأعلى قيمة</option>
                                    <option value="lowest">الأقل قيمة</option>
                                </select>
                            </div>
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
                            <label>نطاق التاريخ</label>
                            <div class="date-range-picker">
                                <input type="date" class="form-input" id="returnDateFrom">
                                <span>إلى</span>
                                <input type="date" class="form-input" id="returnDateTo">
                            </div>
                        </div>
                        <div class="filter-group">
                            <label>المورد</label>
                            <select class="form-select select2-supplier" id="returnSupplierFilter" style="width: 100%;">
                                <option value="">كل الموردين</option>
                                <!-- سيتم تعبئته بواسطة JavaScript -->
                            </select>
                        </div>
                        <div class="filter-group">
                            <label>بحث متقدم</label>
                            <div class="advanced-search-toggle" onclick="toggleAdvancedSearch('returns')">
                                <i class="fas fa-sliders-h"></i> بحث متقدم
                            </div>
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
                    
                    <!-- Advanced Filters -->
                    <div class="advanced-filters" id="returnsAdvancedFilters">
                        <div class="filter-grid">
                            <div class="filter-group">
                                <label>رقم الفاتورة الأصلية</label>
                                <input type="number" class="form-input" id="returnOriginalInvoiceFilter" placeholder="رقم الفاتورة">
                            </div>
                            <div class="filter-group">
                                <label>رقم المرتجع</label>
                                <input type="text" class="form-input" id="returnNumberFilter" placeholder="رقم المرتجع">
                            </div>
                            <div class="filter-group">
                                <label>المبلغ الأدنى</label>
                                <input type="number" class="form-input" id="returnMinAmountFilter" placeholder="المبلغ الأدنى">
                            </div>
                            <div class="filter-group">
                                <label>المبلغ الأقصى</label>
                                <input type="number" class="form-input" id="returnMaxAmountFilter" placeholder="المبلغ الأقصى">
                            </div>
                            <div class="filter-group">
                                <label>ترتيب النتائج</label>
                                <select class="form-select" id="returnSortByFilter">
                                    <option value="newest">الأحدث أولاً</option>
                                    <option value="oldest">الأقدم أولاً</option>
                                    <option value="highest">الأعلى قيمة</option>
                                    <option value="lowest">الأقل قيمة</option>
                                </select>
                            </div>
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

                <!-- Returns Summary -->
                <div class="stats-grid mt-4" id="returnsSummaryStats">
                    <!-- سيتم تعبئته بواسطة JavaScript -->
                </div>
            </div>

            <!-- ==================== ANALYTICS TAB ==================== -->
            <div id="analyticsTab" class="tab-content hidden">
                <!-- Analytics Filters -->
                <div class="filters-card mb-4">
                    <div class="filter-grid">
                        <div class="filter-group">
                            <label>الفترة الزمنية</label>
                            <select class="form-select" id="analyticsTimeFrame" onchange="AnalyticsManager.updateCharts()">
                                <option value="week">أسبوع</option>
                                <option value="month">شهر</option>
                                <option value="quarter">ربع سنة</option>
                                <option value="year">سنة</option>
                                <option value="custom">مخصص</option>
                            </select>
                        </div>
                        <div class="filter-group" id="customDateRange" style="display: none;">
                            <label>نطاق التاريخ</label>
                            <div class="date-range-picker">
                                <input type="date" class="form-input" id="analyticsDateFrom">
                                <span>إلى</span>
                                <input type="date" class="form-input" id="analyticsDateTo">
                            </div>
                        </div>
                        <div class="filter-group">
                            <label>المورد</label>
                            <select class="form-select select2-supplier" id="analyticsSupplierFilter" style="width: 100%;" onchange="AnalyticsManager.updateCharts()">
                                <option value="">كل الموردين</option>
                            </select>
                        </div>
                        <div class="filter-actions">
                            <button class="btn btn-primary" onclick="AnalyticsManager.updateCharts()">
                                <i class="fas fa-chart-line"></i>
                                تطبيق
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Charts Grid -->
                <div class="charts-grid">
                    <!-- Monthly Purchases Chart -->
                    <div class="chart-card">
                        <h3>المشتريات الشهرية</h3>
                        <div class="chart-container">
                            <canvas id="monthlyPurchasesChart"></canvas>
                        </div>
                    </div>

                    <!-- Returns by Type Chart -->
                    <div class="chart-card">
                        <h3>المرتجعات حسب النوع</h3>
                        <div class="chart-container">
                            <canvas id="returnsByTypeChart"></canvas>
                        </div>
                    </div>

                    <!-- Top Suppliers Chart -->
                    <div class="chart-card">
                        <h3>أعلى 5 موردين</h3>
                        <div class="chart-container">
                            <canvas id="topSuppliersChart"></canvas>
                        </div>
                    </div>

                    <!-- Return Rate Chart -->
                    <div class="chart-card">
                        <h3>معدل المرتجعات الشهري</h3>
                        <div class="chart-container">
                            <canvas id="returnRateChart"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Analytics Stats -->
                <div class="stats-grid mt-4" id="analyticsStats">
                    <!-- سيتم تعبئته بواسطة JavaScript -->
                </div>
            </div>
        </main>
    </div>

    <!-- ==================== MODALS ==================== -->
    
    <!-- View Invoice Modal -->
    <div class="modal" id="viewInvoiceModal">
        <div class="modal-content" style="max-width: 900px;">
            <div class="modal-header">
                <h2><i class="fas fa-file-invoice-dollar"></i> تفاصيل الفاتورة</h2>
                <button class="modal-close" onclick="UIManager.closeModal('viewInvoiceModal')">×</button>
            </div>
            <div class="modal-body" id="invoiceDetails">
                <!-- سيتم تعبئته بواسطة JavaScript -->
            </div>
            <div class="modal-footer" id="invoiceModalFooter">
                <button class="btn btn-primary" onclick="window.print()">
                    <i class="fas fa-print"></i>
                    طباعة
                </button>
                <button class="btn btn-light" onclick="UIManager.closeModal('viewInvoiceModal')">
                    إغلاق
                </button>
            </div>
        </div>
    </div>

    <!-- Edit Invoice Modal -->
    <div class="modal" id="editInvoiceModal">
        <div class="modal-content" style="max-width: 1000px;">
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
        <div class="modal-content" style="max-width: 900px;">
            <div class="modal-header" style="background: var(--grad-3);">
                <h2><i class="fas fa-plus-circle"></i> إنشاء مرتجع جديد</h2>
                <button class="modal-close" onclick="ReturnManager.closeReturnModal()">×</button>
            </div>
            <div class="modal-body" id="newReturnBody">
                <!-- سيتم تعبئته بواسطة JavaScript -->
            </div>
        </div>
    </div>

    <!-- View Return Items Modal -->
    <div class="modal" id="viewReturnItemsModal">
        <div class="modal-content">
            <div class="modal-header" style="background: var(--grad-info);">
                <h2><i class="fas fa-boxes"></i> المرتجعات المرتبطة</h2>
                <button class="modal-close" onclick="UIManager.closeModal('viewReturnItemsModal')">×</button>
            </div>
            <div class="modal-body" id="returnItemsList">
                <!-- سيتم تعبئته بواسطة JavaScript -->
            </div>
            <div class="modal-footer">
                <button class="btn btn-light" onclick="UIManager.closeModal('viewReturnItemsModal')">
                    إغلاق
                </button>
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

        const RETURN_COLORS = {
            supplier_return: '#ef4444',
            damaged: '#f59e0b',
            expired: '#64748b',
            wrong_item: '#7c3aed',
            excess: '#0b84ff'
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
                return await this.callAPI('list_invoices', filters);
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
                
                if (tabName === 'analytics') {
                    this.initCharts();
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
                            case 'supplier':
                                label = 'المورد';
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

        // ==================== GLOBAL SEARCH ====================
        const GlobalSearch = {
            searchTimeout: null,

            handleSearch(event) {
                const query = event.target.value.trim();
                
                clearTimeout(this.searchTimeout);
                
                this.searchTimeout = setTimeout(() => {
                    if (query.length >= 2) {
                        this.performSearch(query);
                    } else if (query.length === 0) {
                        // Reset to default when search is cleared
                        if (document.getElementById('invoicesTab').classList.contains('hidden') === false) {
                            PurchaseManager.loadInvoices();
                        } else if (document.getElementById('returnsTab').classList.contains('hidden') === false) {
                            ReturnManager.loadReturns();
                        }
                    }
                }, 500);
            },

            async performSearch(query) {
                // Determine which tab is active
                if (document.getElementById('invoicesTab').classList.contains('hidden') === false) {
                    // Search in invoices
                    UIManager.activeFilters.invoices.search = query;
                    PurchaseManager.loadInvoices();
                } else if (document.getElementById('returnsTab').classList.contains('hidden') === false) {
                    // Search in returns
                    UIManager.activeFilters.returns.search = query;
                    ReturnManager.loadReturns();
                }
            },

            quickFilter(type) {
                const today = new Date();
                
                switch(type) {
                    case 'today':
                        const todayStr = today.toISOString().split('T')[0];
                        UIManager.activeFilters.invoices.dateFrom = todayStr;
                        UIManager.activeFilters.invoices.dateTo = todayStr;
                        break;
                        
                    case 'week':
                        const weekAgo = new Date(today);
                        weekAgo.setDate(today.getDate() - 7);
                        UIManager.activeFilters.invoices.dateFrom = weekAgo.toISOString().split('T')[0];
                        UIManager.activeFilters.invoices.dateTo = today.toISOString().split('T')[0];
                        break;
                        
                    case 'month':
                        const monthAgo = new Date(today);
                        monthAgo.setMonth(today.getMonth() - 1);
                        UIManager.activeFilters.invoices.dateFrom = monthAgo.toISOString().split('T')[0];
                        UIManager.activeFilters.invoices.dateTo = today.toISOString().split('T')[0];
                        break;
                        
                    case 'pending':
                        UIManager.activeFilters.invoices.status = 'pending';
                        break;
                }
                
                PurchaseManager.loadInvoices();
            }
        };

        // ==================== PURCHASE MANAGER ====================
        const PurchaseManager = {
            currentInvoiceId: null,
            currentPage: 1,
            pageSize: 10,
            totalInvoices: 0,
            suppliers: [],

            async init() {
                await this.loadSuppliers();
                await this.loadStatistics();
                await this.loadInvoices();
                this.initSelect2();
            },

            async loadSuppliers() {
                try {
                    const result = await APIManager.fetchSuppliers();
                    if (result.success) {
                        this.suppliers = result.suppliers;
                        this.renderSupplierDropdowns();
                    }
                } catch (error) {
                    console.error('Error loading suppliers:', error);
                }
            },

            renderSupplierDropdowns() {
                // Render for invoices tab
                const supplierSelect = document.getElementById('supplierFilter');
                if (supplierSelect) {
                    supplierSelect.innerHTML = '<option value="">كل الموردين</option>';
                    this.suppliers.forEach(supplier => {
                        const option = document.createElement('option');
                        option.value = supplier.id;
                        option.textContent = supplier.name;
                        supplierSelect.appendChild(option);
                    });
                }

                // Render for returns tab
                const returnSupplierSelect = document.getElementById('returnSupplierFilter');
                if (returnSupplierSelect) {
                    returnSupplierSelect.innerHTML = '<option value="">كل الموردين</option>';
                    this.suppliers.forEach(supplier => {
                        const option = document.createElement('option');
                        option.value = supplier.id;
                        option.textContent = supplier.name;
                        returnSupplierSelect.appendChild(option);
                    });
                }
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

            async loadInvoices(page = 1) {
                this.currentPage = page;
                UIManager.showLoading('invoicesTableLoading');
                
                try {
                    // Build filters from active filters
                    const filters = {
                        page: page,
                        limit: this.pageSize,
                        ...UIManager.activeFilters.invoices
                    };

                    const result = await APIManager.fetchInvoices(filters);
                    
                    if (result.success) {
                        this.totalInvoices = result.statistics.total_invoices;
                        this.renderInvoices(result.invoices);
                        UIManager.updateSummaryStats(result.statistics, 'invoicesSummaryStats');
                        UIManager.renderPagination(this.totalInvoices, page, this.pageSize, 'invoicesPagination', 'PurchaseManager.loadInvoices');
                        
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
                        buttons += `
                            <button class="btn btn-warning btn-sm" onclick="PurchaseManager.editInvoice(${invoice.id})">
                                <i class="fas fa-edit"></i>
                                تعديل
                            </button>
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
                if (footer) {
                    let extraButtons = '';
                    
                    if (canRevert) {
                        extraButtons += `
                            <button class="btn btn-warning" onclick="PurchaseManager.revertInvoicePrompt(${invoice.id})">
                                <i class="fas fa-undo"></i>
                                إرجاع
                            </button>
                        `;
                    }
                    
                    if (extraButtons) {
                        footer.innerHTML = extraButtons + footer.innerHTML;
                    }
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
                                       value="${item.sale_price?.toFixed(2) || ''}">
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

            filterInvoices() {
                // Collect filter values
                const filters = {
                    supplier_filter_val: document.getElementById('supplierFilter').value,
                    status_filter_val: document.getElementById('statusFilter').value,
                    invoice_out_id: document.getElementById('invoiceIdFilter').value,
                    date_from: document.getElementById('dateFromFilter').value,
                    date_to: document.getElementById('dateToFilter').value,
                    supplier_invoice_number: document.getElementById('supplierInvoiceNumberFilter').value,
                    min_amount: document.getElementById('minAmountFilter').value,
                    max_amount: document.getElementById('maxAmountFilter').value,
                    created_by: document.getElementById('createdByFilter').value,
                    sort_by: document.getElementById('sortByFilter').value
                };

                // Update active filters
                UIManager.activeFilters.invoices = filters;
                UIManager.updateFilterTags('invoices', filters);
                
                // Reset to first page and load
                this.currentPage = 1;
                this.loadInvoices();
            },

            resetFilters() {
                // Reset form fields
                document.getElementById('supplierFilter').value = '';
                document.getElementById('invoiceIdFilter').value = '';
                document.getElementById('dateFromFilter').value = '';
                document.getElementById('dateToFilter').value = '';
                document.getElementById('statusFilter').value = '';
                document.getElementById('supplierInvoiceNumberFilter').value = '';
                document.getElementById('minAmountFilter').value = '';
                document.getElementById('maxAmountFilter').value = '';
                document.getElementById('createdByFilter').value = '';
                document.getElementById('sortByFilter').value = 'newest';
                
                // Reset active filters
                UIManager.activeFilters.invoices = {};
                UIManager.updateFilterTags('invoices', {});
                
                // Hide advanced filters
                document.getElementById('invoicesAdvancedFilters').classList.remove('active');
                
                // Reload
                this.currentPage = 1;
                this.loadInvoices();
            },

            refreshInvoices() {
                this.loadInvoices(this.currentPage);
            },

            initSelect2() {
                // Initialize Select2 for supplier dropdowns
                $('.select2-supplier').select2({
                    placeholder: "اختر مورد",
                    allowClear: true,
                    language: {
                        noResults: function() {
                            return "لا توجد نتائج";
                        }
                    }
                });
            },

            openNewInvoiceModal() {
                UIManager.showSuccess('هذه الميزة قيد التطوير', 'قريباً');
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
                    // Build filters from active filters
                    const filters = {
                        page: page,
                        limit: this.pageSize,
                        ...UIManager.activeFilters.returns
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
                        supplier: { name: 'مورد التقنية المتطورة', id: 2 },
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
                    },
                    {
                        id: 'RET-2024-002',
                        purchase_invoice_id: 1003,
                        supplier: { name: 'مستلزمات المكتبة', id: 3 },
                        return_type: 'damaged',
                        return_date: '2024-01-18',
                        status: 'completed',
                        reason: 'تلف أثناء التخزين',
                        total_amount: 2500.00,
                        items: [
                            {
                                product_name: 'طابعة ليزر',
                                batch_id: 'B004',
                                quantity: 1,
                                unit_cost: 2500.00,
                                total_cost: 2500.00
                            }
                        ]
                    }
                ];

                // Apply filters
                let filteredReturns = mockReturns;
                
                if (filters.returnTypeFilter) {
                    filteredReturns = filteredReturns.filter(r => r.return_type === filters.returnTypeFilter);
                }
                
                if (filters.returnStatusFilter) {
                    filteredReturns = filteredReturns.filter(r => r.status === filters.returnStatusFilter);
                }
                
                if (filters.returnSupplierFilter) {
                    filteredReturns = filteredReturns.filter(r => r.supplier.id == filters.returnSupplierFilter);
                }

                this.totalReturns = filteredReturns.length;
                this.renderReturns(filteredReturns);
                UIManager.renderPagination(this.totalReturns, page, this.pageSize, 'returnsPagination', 'ReturnManager.loadReturns');
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
                const typeColor = RETURN_COLORS[ret.return_type] || '#6b7280';
                const statusBadge = this.getReturnStatusBadge(ret.status);
                const totalItems = ret.items.reduce((sum, item) => sum + item.quantity, 0);
                
                row.innerHTML = `
                    <td class="fw-bold">${ret.id}</td>
                    <td>${ret.purchase_invoice_id}</td>
                    <td>${ret.supplier?.name || 'غير معروف'}</td>
                    <td><span class="badge" style="background: ${typeColor}">${typeText}</span></td>
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
                // Same as before, kept for brevity
                const modalBody = document.getElementById('returnDetails');
                if (!modalBody) return;

                // ... existing renderReturnDetails code ...
            },

            openNewReturnModal(invoiceId = null) {
                this.currentInvoiceId = invoiceId;
                this.currentStep = 1;
                this.selectedBatches = [];
                
                // Mock batches data - replace with API call
                const batches = invoiceId ? [
                    {
                        id: 1,
                        product_name: 'لابتوب ديل',
                        batch_id: 'B001',
                        unit_cost: 1500.00,
                        remaining: 10,
                        original_qty: 10,
                        expiry: '2025-12-31'
                    },
                    {
                        id: 2,
                        product_name: 'ماوس لاسلكي',
                        batch_id: 'B002',
                        unit_cost: 100.00,
                        remaining: 48,
                        original_qty: 50,
                        expiry: '2026-06-30'
                    }
                ] : [];

                this.renderNewReturnModal(batches);
                UIManager.openModal('newReturnModal');
            },

            renderNewReturnModal(batches = []) {
                // Same as before, kept for brevity
                const modalBody = document.getElementById('newReturnBody');
                if (!modalBody) return;

                // ... existing renderNewReturnModal code ...
            },

            filterReturns() {
                // Collect filter values
                const filters = {
                    returnTypeFilter: document.getElementById('returnTypeFilter').value,
                    returnStatusFilter: document.getElementById('returnStatusFilter').value,
                    returnDateFrom: document.getElementById('returnDateFrom').value,
                    returnDateTo: document.getElementById('returnDateTo').value,
                    returnSupplierFilter: document.getElementById('returnSupplierFilter').value,
                    returnOriginalInvoiceFilter: document.getElementById('returnOriginalInvoiceFilter').value,
                    returnNumberFilter: document.getElementById('returnNumberFilter').value,
                    returnMinAmountFilter: document.getElementById('returnMinAmountFilter').value,
                    returnMaxAmountFilter: document.getElementById('returnMaxAmountFilter').value,
                    returnSortByFilter: document.getElementById('returnSortByFilter').value
                };

                // Update active filters
                UIManager.activeFilters.returns = filters;
                UIManager.updateFilterTags('returns', filters);
                
                // Reset to first page and load
                this.currentPage = 1;
                this.loadReturns();
            },

            resetFilters() {
                // Reset form fields
                document.getElementById('returnTypeFilter').value = '';
                document.getElementById('returnStatusFilter').value = '';
                document.getElementById('returnDateFrom').value = '';
                document.getElementById('returnDateTo').value = '';
                document.getElementById('returnSupplierFilter').value = '';
                document.getElementById('returnOriginalInvoiceFilter').value = '';
                document.getElementById('returnNumberFilter').value = '';
                document.getElementById('returnMinAmountFilter').value = '';
                document.getElementById('returnMaxAmountFilter').value = '';
                document.getElementById('returnSortByFilter').value = 'newest';
                
                // Reset active filters
                UIManager.activeFilters.returns = {};
                UIManager.updateFilterTags('returns', {});
                
                // Hide advanced filters
                document.getElementById('returnsAdvancedFilters').classList.remove('active');
                
                // Reload
                this.currentPage = 1;
                this.loadReturns();
            },

            refreshReturns() {
                this.loadReturns(this.currentPage);
            },

            // ... other ReturnManager methods kept for brevity ...
        };

        // ==================== HELPER FUNCTIONS ====================
        function removeFilter(tab, key) {
            if (UIManager.activeFilters[tab]) {
                delete UIManager.activeFilters[tab][key];
                UIManager.updateFilterTags(tab, UIManager.activeFilters[tab]);
                
                if (tab === 'invoices') {
                    PurchaseManager.loadInvoices();
                } else if (tab === 'returns') {
                    ReturnManager.loadReturns();
                }
            }
        }

        function toggleAdvancedSearch(type) {
            const advancedFilters = document.getElementById(type + 'AdvancedFilters');
            if (advancedFilters) {
                advancedFilters.classList.toggle('active');
            }
        }

        // ==================== INITIALIZATION ====================
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize Purchase Manager
            PurchaseManager.init();
            
            // Initialize Select2
            $('.select2-supplier').select2({
                placeholder: "اختر مورد",
                allowClear: true,
                language: {
                    noResults: function() {
                        return "لا توجد نتائج";
                    }
                }
            });
            
            // Set default dates
            const today = new Date().toISOString().split('T')[0];
            const lastMonth = new Date();
            lastMonth.setMonth(lastMonth.getMonth() - 1);
            const lastMonthStr = lastMonth.toISOString().split('T')[0];
            
            document.getElementById('dateFromFilter').value = lastMonthStr;
            document.getElementById('dateToFilter').value = today;
            document.getElementById('returnDateFrom').value = lastMonthStr;
            document.getElementById('returnDateTo').value = today;
            
            // Add event listeners for date changes
            document.getElementById('dateFromFilter').addEventListener('change', function() {
                UIManager.activeFilters.invoices.dateFrom = this.value;
            });
            
            document.getElementById('dateToFilter').addEventListener('change', function() {
                UIManager.activeFilters.invoices.dateTo = this.value;
            });
            
            // Analytics timeframe change
            document.getElementById('analyticsTimeFrame').addEventListener('change', function() {
                const customRange = document.getElementById('customDateRange');
                customRange.style.display = this.value === 'custom' ? 'block' : 'none';
            });
        });

        // Make managers available globally
        window.UIManager = UIManager;
        window.PurchaseManager = PurchaseManager;
        window.ReturnManager = ReturnManager;
        window.GlobalSearch = GlobalSearch;
    </script>
</body>
</html>

<?php require_once '../partials/footer.php'; ?>


