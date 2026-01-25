<?php
require_once dirname(__DIR__) . '/config.php'; // ضبط المسار حسب هيكل مشروعك
require_once BASE_DIR . 'partials/header.php';
require_once BASE_DIR . 'partials/sidebar.php';
?>

<div class="content-wrapper">
    <div class="container-fluid py-4">
        
        <!-- Animated Quotes Banner -->
        <div class="row mb-4 fade-in-down">
            <div class="col-12">
                <div class="card quote-card border-0 shadow-lg overflow-hidden position-relative">
                    <div class="card-body p-4 text-center position-relative z-1">
                        <i class="fas fa-quote-right fa-2x opacity-50 mb-3 text-warning"></i>
                        <h4 id="quoteDisplay" class="fw-bold mb-0 quote-text">
                            "مانقص مال من صدقة"
                        </h4>
                        <div class="mt-2 text-muted">
                            <small id="quoteSource" class="fw-light">- حديث شريف</small>
                        </div>
                    </div>
                    <!-- Decorative Circles -->
                    <div class="position-absolute top-0 start-0 translate-middle rounded-circle bg-surface-secondary opacity-50" style="width: 200px; height: 200px; filter: blur(40px);"></div>
                    <div class="position-absolute bottom-0 end-0 translate-middle rounded-circle bg-surface-secondary opacity-50" style="width: 150px; height: 150px; filter: blur(30px);"></div>
                </div>
            </div>
        </div>

        <!-- Header -->
        <div class="row mb-4 slide-in-left">
            <div class="col-12">
                <div class="page-title-box d-flex align-items-center justify-content-between">
                    <h2 class="h3 mb-0 fw-bold system-page-title">
                        <i class="fas fa-hand-holding-heart text-success me-2 heartbeat-icon"></i>
                        حاسبة الصدقة
                    </h2>
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb mb-0 glass-breadcrumb p-2 px-3 rounded-pill shadow-sm">
                            <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>" class="text-decoration-none">الرئيسية</a></li>
                            <li class="breadcrumb-item active">الأدوات</li>
                            <li class="breadcrumb-item active">حاسبة الصدقة</li>
                        </ol>
                    </nav>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Calculator Section -->
            <div class="col-md-5 mb-4 slide-in-up" style="animation-delay: 0.1s;">
                <div class="card shadow-lg border-0 rounded-4 overflow-hidden h-100 hover-lift">
                    <div class="card-header bg-gradient-success text-white py-3">
                        <h5 class="mb-0 fw-bold"><i class="fas fa-calculator me-2"></i> حساب الصدقة</h5>
                    </div>
                    <div class="card-body p-4">
                        <form id="charityForm" onsubmit="return false;">
                            
                            <div class="mb-4 form-group-animated">
                                <label class="form-label fw-bold text-muted">صافي الربح / المبلغ</label>
                                <div class="input-group input-group-lg shadow-sm rounded-pill overflow-hidden">
                                    <input type="number" id="netProfit" class="form-control border-0 bg-surface-secondary" placeholder="0.00" step="0.01" required>
                                    <span class="input-group-text border-0 bg-success text-white fw-bold">ج.م</span>
                                </div>
                                <small class="text-muted ms-2 mt-1 d-block"><i class="fas fa-info-circle me-1"></i> أدخل المبلغ المراد تزكيته</small>
                            </div>

                            <div class="mb-4">
                                <label class="form-label fw-bold text-muted mb-3">نوع النسبة</label>
                                <div class="ratio-selector d-flex gap-2">
                                    <input type="radio" class="btn-check" name="ratioType" id="ratio2.5" value="2.5" checked>
                                    <label class="btn btn-outline-success flex-fill rounded-pill py-2 shadow-sm ratio-btn" for="ratio2.5">
                                        <div class="fw-bold">2.5%</div>
                                        <div class="small">زكاة المال</div>
                                    </label>

                                    <input type="radio" class="btn-check" name="ratioType" id="ratio10" value="10">
                                    <label class="btn btn-outline-success flex-fill rounded-pill py-2 shadow-sm ratio-btn" for="ratio10">
                                        <div class="fw-bold">10%</div>
                                        <div class="small">عشر</div>
                                    </label>

                                    <input type="radio" class="btn-check" name="ratioType" id="ratioCustom" value="custom">
                                    <label class="btn btn-outline-success flex-fill rounded-pill py-2 shadow-sm ratio-btn" for="ratioCustom">
                                        <div class="fw-bold"><i class="fas fa-edit"></i></div>
                                        <div class="small">مخصص</div>
                                    </label>
                                </div>
                            </div>

                            <div class="mb-3 slide-down" id="customRatioContainer" style="display: none;">
                                <label class="form-label fw-bold">نسبة مخصصة (%)</label>
                                <div class="input-group shadow-sm rounded-3">
                                    <input type="number" id="customRatio" class="form-control border-0 bg-surface-secondary" placeholder="أدخل النسبة" step="0.1">
                                    <span class="input-group-text border-0 bg-surface-secondary"><i class="fas fa-percent text-muted"></i></span>
                                </div>
                            </div>

                            <div class="alert alert-custom-success d-flex align-items-center shadow-sm rounded-3 mb-4 pulse-animation" role="alert">
                                <div class="icon-circle bg-surface text-success me-3 shadow-sm">
                                    <i class="fas fa-hand-holding-usd fa-lg"></i>
                                </div>
                                <div>
                                    <small class="d-block text-uppercase fw-bold opacity-75">قيمة الصدقة المستحقة</small>
                                    <h3 class="mb-0 fw-bold mt-1" id="charityAmount">0.00 ج.م</h3>
                                </div>
                            </div>

                            <div class="mb-4">
                                <label class="form-label fw-bold text-muted">ملاحظات <span class="badge bg-surface-secondary text-muted rounded-pill fw-normal">اختياري</span></label>
                                <textarea id="notes" class="form-control border-0 bg-surface-secondary shadow-sm rounded-3" rows="2" placeholder="اكتب ملاحظة للحفظ..."></textarea>
                            </div>

                            <button type="button" class="btn btn-success w-100 btn-lg rounded-pill shadow-lg hover-scale fw-bold py-3 gradient-btn" onclick="saveCharity()">
                                <i class="fas fa-save me-2"></i> حفظ العملية
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- History Section -->
            <div class="col-md-7 slide-in-up" style="animation-delay: 0.2s;">
                <div class="card shadow-lg border-0 rounded-4 overflow-hidden h-100">
                    <div class="card-header bg-surface border-bottom py-3 d-flex justify-content-between align-items-center">
                        <h5 class="mb-0 fw-bold text-primary-dark"><i class="fas fa-history me-2 text-warning"></i> سجل الخير</h5>
                        <button class="btn btn-outline-danger btn-sm rounded-pill hover-shake" onclick="clearAllHistory()">
                            <i class="fas fa-trash-alt me-1"></i> مسح الكل
                        </button>
                    </div>
                    <div class="card-body p-0 custom-scrollbar" style="max-height: 500px; overflow-y: auto;">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0 custom-table">
                                <thead class="bg-surface-secondary sticky-top">
                                    <tr>
                                        <th class="border-0 py-3 text-muted ps-4">التاريخ</th>
                                        <th class="border-0 py-3 text-muted">المبلغ الأصل</th>
                                        <th class="border-0 py-3 text-muted">الصدقة</th>
                                        <th class="border-0 py-3 text-muted text-center">النسبة</th>
                                        <th class="border-0 py-3 text-muted">بواسطة</th>
                                        <th class="border-0 py-3 text-muted">ملاحظات</th>
                                        <th class="border-0 py-3 text-center text-muted pe-4">إجراء</th>
                                    </tr>
                                </thead>
                                <tbody id="historyTableBody">
                                    <!-- Data will be populated here -->
                                </tbody>
                            </table>
                        </div>
                        <div id="noDataMessage" class="text-center py-5 text-muted fade-in" style="display: none;">
                            <div class="mb-3">
                                <div class="icon-circle bg-surface-secondary d-inline-flex text-muted" style="width: 80px; height: 80px;">
                                    <i class="fas fa-folder-open fa-2x"></i>
                                </div>
                            </div>
                            <h6 class="fw-bold">لا توجد سجلات محفوظة</h6>
                            <p class="small opacity-75">ابدأ بحساب صدقتك الآن لتملأ هذا السجل بالخير</p>
                        </div>
                    </div>
                    <div class="card-footer bg-surface-secondary border-top p-3">
                        <div class="d-flex justify-content-between align-items-center px-2">
                            <span class="text-muted fw-bold">إجمالي الصدقات المحفوظة:</span>
                            <span class="badge bg-success rounded-pill px-3 py-2 fs-6 shadow-sm" id="totalSavedCharity">0.00 ج.م</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    // Quotes Rotator
    const quotes = [
        { text: "ما نقص مال من صدقة", source: "حديث شريف" },
        { text: "داووا مرضاكم بالصدقة", source: "حديث شريف" },
        { text: "الصدقة تطفئ غضب الرب", source: "حديث شريف" },
        { text: "صنائع المعروف تقي مصارع السوء", source: "حديث شريف" },
        { text: "الصدقة برهان", source: "حديث شريف" },
        { text: "اتقوا النار ولو بشق تمرة", source: "حديث شريف" },
        { text: "إن الصدقة لتطفئ عن أهلها حر القبور", source: "حديث شريف" }
    ];

    let currentQuoteIndex = 0;
    const quoteDisplay = document.getElementById('quoteDisplay');
    const quoteSource = document.getElementById('quoteSource');

    function rotateQuote() {
        currentQuoteIndex = (currentQuoteIndex + 1) % quotes.length;
        
        // Scale out
        quoteDisplay.style.transform = 'scale(0.95)';
        quoteDisplay.style.opacity = '0';
        
        setTimeout(() => {
            quoteDisplay.textContent = `"${quotes[currentQuoteIndex].text}"`;
            quoteSource.textContent = `- ${quotes[currentQuoteIndex].source}`;
            
            // Scale in
            quoteDisplay.style.transform = 'scale(1)';
            quoteDisplay.style.opacity = '1';
        }, 500); // Wait for fade out transition
    }

    // Initialize Quote Interval
    setInterval(rotateQuote, 5000);

    // ----------------------
    // Main Functional Logic
    // ----------------------
    const netProfitInput = document.getElementById('netProfit');
    const customRatioContainer = document.getElementById('customRatioContainer');
    const customRatioInput = document.getElementById('customRatio');
    const charityAmountDisplay = document.getElementById('charityAmount');
    const ratioRadios = document.getElementsByName('ratioType');
    const historyTableBody = document.getElementById('historyTableBody');
    const noDataMessage = document.getElementById('noDataMessage');
    const totalSavedCharityDisplay = document.getElementById('totalSavedCharity');

    // Events
    netProfitInput.addEventListener('input', calculate);
    customRatioInput.addEventListener('input', calculate);
    
    ratioRadios.forEach(radio => {
        radio.addEventListener('change', function() {
            if (this.value === 'custom') {
                customRatioContainer.style.display = 'block';
                // Add simple animation class
                setTimeout(() => customRatioContainer.classList.add('active'), 10);
                customRatioInput.focus();
            } else {
                customRatioContainer.classList.remove('active');
                setTimeout(() => customRatioContainer.style.display = 'none', 300);
            }
            calculate();
        });
    });

    function calculate() {
        const amount = parseFloat(netProfitInput.value) || 0;
        let percentage = 0;
        
        let selectedRatio = document.querySelector('input[name="ratioType"]:checked').value;
        
        if (selectedRatio === 'custom') {
            percentage = parseFloat(customRatioInput.value) || 0;
        } else {
            percentage = parseFloat(selectedRatio);
        }

        const charityValue = amount * (percentage / 100);
        
        // Animate Price Change if value is significant
        // (Just updating text here for simplicity)
        charityAmountDisplay.innerHTML = formatCurrency(charityValue);
        
        return { amount, percentage, charityValue };
    }

    function formatCurrency(num) {
        return new Intl.NumberFormat('ar-EG', { style: 'currency', currency: 'EGP' }).format(num);
    }

    async function saveCharity() {
        const { amount, percentage, charityValue } = calculate();
        
        if (amount <= 0) {
            Swal.fire({
                icon: 'warning',
                title: 'تنبيه',
                text: 'يرجى إدخال مبلغ صحيح',
                confirmButtonColor: '#ffc107',
                confirmButtonText: 'حسناً'
            });
            return;
        }

        const note = document.getElementById('notes').value.trim();
        
        Swal.showLoading();

        try {
            const response = await fetch('<?php echo BASE_URL; ?>api/charity/save.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    amount: amount,
                    percentage: percentage,
                    charity_value: charityValue,
                    notes: note
                })
            });

            const result = await response.json();

            if (result.status === 'success') {
                netProfitInput.value = '';
                document.getElementById('notes').value = '';
                calculate();
                fetchHistory();

                Swal.fire({
                    icon: 'success',
                    title: 'جزاكم الله خيراً',
                    text: 'تم حفظ العملية بنجاح',
                    timer: 2000,
                    showConfirmButton: false,
                    backdrop: `rgba(0,0,123,0.1)`
                });
            } else {
                Swal.fire('خطأ', result.message, 'error');
            }
        } catch (error) {
            Swal.fire('خطأ', 'حدث خطأ في الاتصال بالخادم', 'error');
        }
    }

    async function fetchHistory() {
        try {
            const response = await fetch('<?php echo BASE_URL; ?>api/charity/get_history.php');
            const result = await response.json();

            if (result.status === 'success') {
                renderHistory(result.data);
            }
        } catch (error) {
            console.error('Error fetching history:', error);
        }
    }

    function renderHistory(history) {
        historyTableBody.innerHTML = '';
        let totalCharity = 0;

        if (!history || history.length === 0) {
            noDataMessage.style.display = 'block';
        } else {
            noDataMessage.style.display = 'none';
        }

        history.forEach((item, index) => {
            totalCharity += parseFloat(item.charityValue);
            
            const tr = document.createElement('tr');
            // Add staggering animation
            tr.style.animation = `fadeInUp 0.3s ease forwards ${index * 0.05}s`;
            tr.style.opacity = '0'; // start invisible for animation
            
            
            tr.innerHTML = `
                <td class="ps-4 text-nowrap"><small class="fw-bold text-muted">${item.date}</small></td>
                <td class="fw-bold">${formatCurrency(item.amount)}</td>
                <td class="text-success fw-bold fs-6">${formatCurrency(item.charityValue)}</td>
                <td class="text-center"><span class="badge bg-surface-secondary text-muted border shadow-sm rounded-pill">${item.percentage}%</span></td>
                <td><small class="text-muted fw-bold">${item.created_by_name}</small></td>
                <td><small class="text-muted text-break" style="max-width: 250px; display: inline-block;">${item.note || '-'}</small></td>
                <td class="text-center pe-4">
                    <button class="btn btn-sm btn-light text-danger rounded-circle shadow-sm hover-scale" onclick="deleteRecord(${item.id})" title="حذف">
                        <i class="fas fa-trash-alt"></i>
                    </button>
                </td>
            `;
            historyTableBody.appendChild(tr);
        });

        totalSavedCharityDisplay.innerHTML = formatCurrency(totalCharity);
    }

    function deleteRecord(id) {
        Swal.fire({
            title: 'حذف السجل؟',
            text: "لن تتمكن من استرجاع هذا السجل!",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'نعم، احذف',
            cancelButtonText: 'إلغاء'
        }).then(async (result) => {
            if (result.isConfirmed) {
                try {
                    const response = await fetch('<?php echo BASE_URL; ?>api/charity/delete.php', {
                        method: 'POST',
                        body: JSON.stringify({ id: id })
                    });
                    const res = await response.json();

                    if (res.status === 'success') {
                        fetchHistory();
                        const Toast = Swal.mixin({
                            toast: true,
                            position: 'top-end',
                            showConfirmButton: false,
                            timer: 3000
                        });
                        Toast.fire({
                            icon: 'success',
                            title: 'تم الحذف بنجاح'
                        });
                    } else {
                        Swal.fire('خطأ', res.message, 'error');
                    }
                } catch (error) {
                    Swal.fire('خطأ', 'فشل الحذف', 'error');
                }
            }
        });
    }

    function clearAllHistory() {
        Swal.fire({
            title: 'تصفير السجل بالكامل؟',
            text: "هل أنت متأكد من مسح جميع العمليات المحفوظة؟",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'نعم، مسح الكل',
            cancelButtonText: 'تراجع'
        }).then(async (result) => {
            if (result.isConfirmed) {
                try {
                    const response = await fetch('<?php echo BASE_URL; ?>api/charity/clear_history.php', { method: 'POST' });
                    const res = await response.json();

                    if (res.status === 'success') {
                        fetchHistory();
                        Swal.fire({
                            icon: 'success',
                            title: 'تم المسح',
                            text: 'السجل الآن فارغ',
                            timer: 1500,
                            showConfirmButton: false
                        });
                    } else {
                        Swal.fire('خطأ', res.message, 'error');
                    }
                } catch (error) {
                    Swal.fire('خطأ', 'فشل المسح', 'error');
                }
            }
        });
    }

    window.addEventListener('load', fetchHistory);
</script>

<style>
    /* Premium Styles & Animations */
    
    /* Variables for potential theming */
    /* Variables for potential theming */
    :root {
        --text-primary: #2c3e50;
        --card-bg: #ffffff;
        --bg-main: #f4f6f9;
        --surface: #ffffff;
        --surface-secondary: #f8f9fa;
        --border-color: #e9ecef;
        --text-muted: #6c757d;
        --input-bg: #f8f9fa;
    }

    [data-theme="dark"] {
        --text-primary: #e0e0e0;
        --card-bg: #1e1e1e;
        --bg-main: #121212;
        --surface: #1e1e1e;
        --surface-secondary: #2d2d2d;
        --border-color: #404040;
        --text-muted: #adb5bd;
        --input-bg: #2d2d2d;
    }

    body {
        background-color: var(--bg-main) !important;
        color: var(--text-primary) !important;
    }

    .bg-surface { background-color: var(--surface) !important; }
    .bg-surface-secondary { background-color: var(--surface-secondary) !important; }
    
    .card {
        background-color: var(--card-bg);
        color: var(--text-primary);
        border: 1px solid var(--border-color);
    }

    .quote-card {
        border: none !important;
    }
    .quote-card h4, .quote-card .text-muted, .quote-card i {
        color: var(--text-primary) !important;
    }
    
    .form-control {
        background-color: var(--input-bg);
        border-color: var(--border-color);
        color: var(--text-primary);
    }
    
    .form-control:focus {
        background-color: var(--input-bg);
        color: var(--text-primary);
        border-color: #198754;
        box-shadow: 0 0 0 0.25rem rgba(25, 135, 84, 0.25);
    }

    .form-control::placeholder {
        color: var(--text-muted);
        opacity: 0.7;
    }

    .input-group-text {
        border-color: var(--border-color);
    }

    .bg-light {
        background-color: var(--surface-secondary) !important;
    }

    .table { color: var(--text-primary); }
    .text-muted { color: var(--text-muted) !important; }
    
    .ratio-btn {
        background-color: var(--surface);
        border-color: var(--border-color);
        color: var(--text-primary);
    }

    .opacity-5 { opacity: 0.05 !important; }

    .bg-gradient-premium {
        /* Modern Deep Blue/Purple Gradient */
        background: linear-gradient(135deg, #4e54c8 0%, #8f94fb 100%);
    }

    .bg-gradient-success {
        background: linear-gradient(135deg, #059669 0%, #34d399 100%);
    }

    /* Animations */
    @keyframes fadeInDown {
        from { opacity: 0; transform: translateY(-20px); }
        to { opacity: 1; transform: translateY(0); }
    }
    @keyframes fadeInUp {
        from { opacity: 0; transform: translateY(20px); }
        to { opacity: 1; transform: translateY(0); }
    }
    @keyframes slideInLeft {
        from { opacity: 0; transform: translateX(-30px); }
        to { opacity: 1; transform: translateX(0); }
    }
    @keyframes pulse {
        0% { transform: scale(1); }
        50% { transform: scale(1.02); }
        100% { transform: scale(1); }
    }

    .fade-in-down { animation: fadeInDown 0.8s ease-out; }
    .slide-in-left { animation: slideInLeft 0.8s ease-out; }
    .slide-in-up { animation: fadeInUp 0.8s ease-out backwards; }
    .pulse-animation { animation: pulse 2s infinite ease-in-out; }

    /* Component Styles */
    .glass-breadcrumb {
        background: rgba(255, 255, 255, 0.7);
        backdrop-filter: blur(10px);
        border: 1px solid rgba(255, 255, 255, 0.5);
    }

    .quote-text {
        font-family: 'Amiri', serif; /* Or any elegant Arabic font */
        transition: all 0.5s ease;
    }

    .card {
        transition: transform 0.3s ease, box-shadow 0.3s ease;
    }

    .hover-lift:hover {
        transform: translateY(-5px);
        box-shadow: 0 1rem 3rem rgba(0,0,0,.15)!important;
    }

    .hover-scale:hover {
        transform: scale(1.05);
    }
    
    .hover-shake:hover {
        animation: shake 0.5s;
    }
    @keyframes shake {
        0% { transform: translateX(0); }
        25% { transform: translateX(-3px); }
        50% { transform: translateX(3px); }
        75% { transform: translateX(-3px); }
        100% { transform: translateX(0); }
    }

    .ratio-btn {
        transition: all 0.3s ease;
        border: 1px solid #dee2e6;
        background: #fff;
        color: #555;
    }
    .btn-check:checked + .ratio-btn {
        background: #198754;
        color: white;
        border-color: #198754;
        transform: translateY(-2px);
        box-shadow: 0 4px 6px rgba(25, 135, 84, 0.2);
    }

    .gradient-btn {
        background: linear-gradient(45deg, #198754, #20c997);
        border: none;
    }

    .alert-custom-success {
        background-color: #d1e7dd;
        border-left: 5px solid #198754;
        color: #0f5132;
    }

    .icon-circle {
        width: 45px;
        height: 45px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .heartbeat-icon {
        animation: pulse 1.5s infinite;
    }

    /* Custom Table */
    .custom-table thead th {
        background: var(--surface-secondary);
        font-weight: 600;
        letter-spacing: 0.5px;
        color: var(--text-muted);
        text-align: right;
    }
    .custom-table tbody tr {
        transition: background-color 0.2s;
        color: var(--text-primary);
    }
    .custom-table tbody tr:hover {
        background-color: rgba(0,0,0,0.02);
    }

    /* Scrollbar */
    .custom-scrollbar::-webkit-scrollbar {
        width: 6px;
    }
    .custom-scrollbar::-webkit-scrollbar-track {
        background: var(--surface-secondary);
    }
    .custom-scrollbar::-webkit-scrollbar-thumb {
        background: #c1c1c1;
        border-radius: 10px;
    }
    .custom-scrollbar::-webkit-scrollbar-thumb:hover {
        background: #a8a8a8;
    }

    /* Slide Down Effect for Custom Input */
    .slide-down {
        transition: all 0.3s ease-out;
        opacity: 0;
        transform: translateY(-10px);
    }
    .slide-down.active {
        opacity: 1;
        transform: translateY(0);
    }

</style>

<?php require_once BASE_DIR . 'partials/footer.php'; ?>
