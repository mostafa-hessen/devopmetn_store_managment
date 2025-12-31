<?php
// admin/manage_customers.php
$page_title = "إدارة العملاء - لوحة التحكم المالية";
$class_dashboard = "active";
require_once dirname(__DIR__) . '/config.php';
require_once BASE_DIR . 'partials/session_admin.php';
require_once BASE_DIR . 'partials/header.php';

$message = "";
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    unset($_SESSION['message']);
}

function e($s) {
    return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// --- إعدادات ---
$protected_customers = [8];

// --- معالجة حذف عميل ---
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['delete_customer'])) {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['message'] = "<div class='alert alert-danger'>خطأ: طلب غير صالح (CSRF).</div>";
        header("Location: " . htmlspecialchars($_SERVER['PHP_SELF']));
        exit;
    }

    $customer_id_to_delete = intval($_POST['customer_id_to_delete'] ?? 0);
    if ($customer_id_to_delete <= 0) {
        $_SESSION['message'] = "<div class='alert alert-danger'>معرّف العميل غير صالح.</div>";
        header("Location: " . htmlspecialchars($_SERVER['PHP_SELF']));
        exit;
    }

    if (in_array($customer_id_to_delete, $protected_customers, true)) {
        $_SESSION['message'] = "<div class='alert alert-warning'><strong>غير مسموح.</strong> هذا العميل محمي من النظام ولا يمكن حذفه.</div>";
        header("Location: " . htmlspecialchars($_SERVER['PHP_SELF']));
        exit;
    }

    // حساب إجمالي الرصيد قبل الحذف للتأكد
    $sql_check = "SELECT COUNT(*) AS cnt, SUM(balance) as total_balance, SUM(wallet) as total_wallet 
                  FROM customers WHERE id = ?";
    $linked_count = 0;
    if ($chk = $conn->prepare($sql_check)) {
        $chk->bind_param("i", $customer_id_to_delete);
        $chk->execute();
        $res = $chk->get_result();
        if ($res && $row = $res->fetch_assoc()) {
            $linked_count = intval($row['cnt']);
        }
        $chk->close();
    }

    if ($linked_count == 0) {
        $_SESSION['message'] = "<div class='alert alert-warning'>لم يتم العثور على العميل.</div>";
        header("Location: " . htmlspecialchars($_SERVER['PHP_SELF']));
        exit;
    }

    // الحذف
    $sql_delete = "DELETE FROM customers WHERE id = ? LIMIT 1";
    if ($stmt_delete = $conn->prepare($sql_delete)) {
        $stmt_delete->bind_param("i", $customer_id_to_delete);
        if ($stmt_delete->execute()) {
            $_SESSION['message'] = ($stmt_delete->affected_rows > 0)
                ? "<div class='alert alert-success'>تم حذف العميل بنجاح.</div>"
                : "<div class='alert alert-warning'>لم يتم العثور على العميل.</div>";
        } else {
            error_log("Delete customer error: " . $stmt_delete->error);
            $_SESSION['message'] = "<div class='alert alert-danger'>حدث خطأ أثناء الحذف.</div>";
        }
        $stmt_delete->close();
    } else {
        error_log("Prepare delete customer failed: " . $conn->error);
        $_SESSION['message'] = "<div class='alert alert-danger'>حدث خطأ داخلي. الرجاء المحاولة لاحقاً.</div>";
    }

    header("Location: " . htmlspecialchars($_SERVER['PHP_SELF']));
    exit;
}

// --- جلب الإحصائيات الإجمالية ---
$stats_sql = "SELECT 
    COUNT(DISTINCT c.id) AS total_customers,

    SUM(
        CASE 
            WHEN i.remaining_amount > 0 
             AND i.delivered != 'canceled'
            THEN i.remaining_amount 
            ELSE 0 
        END
    ) AS total_debts,

    SUM(c.wallet) AS total_wallet,

    SUM(CASE WHEN c.wallet > 0 THEN c.wallet ELSE 0 END) AS total_positive_wallet,
    SUM(CASE WHEN c.wallet < 0 THEN ABS(c.wallet) ELSE 0 END) AS total_negative_wallet

FROM customers c
LEFT JOIN invoices_out i 
    ON i.customer_id = c.id";

$stats = [];
if ($res_stats = $conn->query($stats_sql)) {
    $stats = $res_stats->fetch_assoc();
    $res_stats->free();
}

// --- تحديد الفلتر ---
$debt_only = isset($_GET['debt_only']) && $_GET['debt_only'] == 1;
$wallet_negative = isset($_GET['wallet_negative']) && $_GET['wallet_negative'] == 1;

// --- البحث الديناميكي ---
$q = '';
$search_terms = [];
$search_mode = false;

if (isset($_GET['q'])) {
    $q = trim((string) $_GET['q']);
    if (mb_strlen($q) > 255) $q = mb_substr($q, 0, 255);
    
    // تقسيم الكلمات البحثية
    $search_terms = preg_split('/\s+/', $q);
    $search_terms = array_filter($search_terms, function($term) {
        return mb_strlen(trim($term)) > 0;
    });
    
    $search_mode = true;
}

$customers = [];

if (!empty($search_terms)) {
    $search_mode = true;
    
    // بناء استعلام بحث متقدم مع تحديد الأوزان
    $sql_select = "SELECT 
        c.id, 
        c.name, 
        c.mobile, 
        c.city, 
        c.address, 
        c.notes, 
        c.created_at, 
        c.balance, 
        c.wallet, 
        c.join_date,
        u.username as creator_name,
        
        -- حساب وزن المطابقة
        (
            -- مطابقة كاملة للاسم (أعلى وزن)
            CASE WHEN c.name = ? THEN 1000
                 WHEN c.name LIKE ? THEN 900
                 ELSE 0 END +
            
            -- مطابقة جزئية للاسم (متوسط وزن)
            CASE WHEN c.name LIKE ? THEN 800
                 ELSE 0 END +
            
            -- مطابقة كاملة للموبايل
            CASE WHEN c.mobile = ? THEN 700
                 WHEN c.mobile LIKE ? THEN 600
                 ELSE 0 END +
            
            -- مطابقة كاملة للمدينة
            CASE WHEN c.city = ? THEN 500
                 WHEN c.city LIKE ? THEN 400
                 ELSE 0 END +
            
            -- مطابقة جزئية للعنوان
            CASE WHEN c.address LIKE ? THEN 300
                 ELSE 0 END +
            
            -- مطابقة جزئية للملاحظات
            CASE WHEN c.notes LIKE ? THEN 200
                 ELSE 0 END +
            
            -- مطابقة جزئية لأي كلمة في الاسم
            CASE WHEN c.name LIKE ? THEN 100
                 ELSE 0 END
        ) AS search_score
        
        FROM customers c
        LEFT JOIN users u ON c.created_by = u.id
        WHERE 1=1 ";
    
    // إضافة شروط البحث لكل مصطلح
    $where_conditions = [];
    $bind_types = '';
    $bind_values = [];
    
    // معلمات البحث الأساسية (لكل مصطلح)
    foreach ($search_terms as $term) {
        $where_conditions[] = "(c.name LIKE ? OR c.mobile LIKE ? OR c.city LIKE ? OR c.address LIKE ? OR c.notes LIKE ?)";
        $bind_types .= 'sssss';
        $bind_values[] = '%' . $term . '%';
        $bind_values[] = '%' . $term . '%';
        $bind_values[] = '%' . $term . '%';
        $bind_values[] = '%' . $term . '%';
        $bind_values[] = '%' . $term . '%';
    }
    
    if (!empty($where_conditions)) {
        $sql_select .= " AND (" . implode(' AND ', $where_conditions) . ")";
    }
    
    // إضافة شرط الفلتر إذا كان مفعلاً
    if ($debt_only) {
        $sql_select .= " AND c.balance > 0";
    } elseif ($wallet_negative) {
        $sql_select .= " AND c.wallet < 0";
    }
    
    // ترتيب حسب وزن المطابقة أولاً، ثم حسب تاريخ الإنشاء
    $sql_select .= " ORDER BY search_score DESC, c.id DESC";
    
    // إعداد معلمات الربط
    $bind_params = array_merge([$q, $q . '%', '%' . $q . '%', $q, '%' . $q . '%', $q, '%' . $q . '%', '%' . $q . '%', '%' . $q . '%', '%' . $q . '%'], $bind_values);
    $bind_types = 'ssssssssss' . $bind_types;
    
    if ($stmt = $conn->prepare($sql_select)) {
        $stmt->bind_param($bind_types, ...$bind_params);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            // تخزين درجة المطابقة لفلاتر JavaScript
            $r['search_score_display'] = $r['search_score'];
            $customers[] = $r;
        }
        $stmt->close();
    }
} else {
    // جلب جميع العملاء مع الرصيد
    $sql_select = "SELECT c.id, c.name, c.mobile, c.city, c.address, c.notes, 
                          c.created_at, c.balance, c.wallet, c.join_date,
                          u.username as creator_name
                   FROM customers c
                   LEFT JOIN users u ON c.created_by = u.id";
    
    // إضافة شرط الفلتر إذا كان مفعلاً
    if ($debt_only) {
        $sql_select .= " WHERE c.balance > 0";
    } elseif ($wallet_negative) {
        $sql_select .= " WHERE c.wallet < 0";
    }
    
    $sql_select .= " ORDER BY c.id DESC";
    
    if ($res = $conn->query($sql_select)) {
        while ($r = $res->fetch_assoc()) $customers[] = $r;
        $res->free();
    }
}


$invoice_counts = [];

if (!empty($customers)) {

    // $ids = array_map(fn($c) => (int)$c['id'], $customers);

    $ids = array_map(function ($c) {
    return (int) $c['id'];
}, $customers);
    $ids_csv = implode(',', $ids);

    $sql = "
        SELECT 
            i.customer_id,
            CASE 
                WHEN i.delivered = 'reverted' THEN 'returned'
                WHEN i.remaining_amount = 0 THEN 'paid'
                WHEN i.paid_amount > 0 AND i.remaining_amount > 0 THEN 'partial'
                ELSE 'pending'
            END AS status,
            COUNT(*) AS cnt
        FROM invoices_out i
        WHERE i.customer_id IN ($ids_csv)
        GROUP BY i.customer_id, status
    ";

    if ($res = $conn->query($sql)) {
        while ($row = $res->fetch_assoc()) {

            $cid    = (int)$row['customer_id'];
            $status = $row['status'];
            $cnt    = (int)$row['cnt'];

            if (!isset($invoice_counts[$cid])) {
                $invoice_counts[$cid] = [
                    'paid'     => 0,
                    'partial'  => 0,
                    'pending'  => 0,
                    'returned' => 0,
                    'total'    => 0
                ];
            }

            $invoice_counts[$cid][$status] += $cnt;
            $invoice_counts[$cid]['total'] += $cnt;
        }
        $res->free();
    }
}


require_once BASE_DIR . 'partials/sidebar.php';
?>

<!-- CSS مخصص -->
<style>
/* تصميم البطاقات الإحصائية */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 1rem;
    margin-bottom: 2rem;
}

.stat-card {
    background: var(--surface);
    border-radius: var(--radius);
    padding: 1.25rem;
    border: 1px solid var(--border);
    box-shadow: var(--shadow-1);
    transition: transform var(--fast), box-shadow var(--fast);
    position: relative;
    overflow: hidden;
}

.stat-card:hover {
    transform: translateY(-3px);
    box-shadow: var(--shadow-2);
}

.stat-card.primary {
    background: linear-gradient(135deg, var(--primary), var(--primary-700));
    color: white;
}

.stat-card.debt {
    background: linear-gradient(135deg, var(--rose), #dc2626);
    color: white;
}

.stat-card.credit {
    background: linear-gradient(135deg, var(--teal), #059669);
    color: white;
}

.stat-card.wallet {
    background: linear-gradient(135deg, var(--amber), #d97706);
    color: white;
}

.stat-icon {
    /* position: absolute; */
    right: 1.25rem;
    top: 1.25rem;
    font-size: 1.5rem;
    opacity: 0.8;
}

.stat-value {
    font-size: 1.8rem;
    font-weight: 700;
    margin: 0.5rem 0;
}

.stat-label {
    font-size: 0.875rem;
    opacity: 0.9;
    margin-bottom: 0.25rem;
}

/* شريط البحث المحسن */
.search-container {
    background: var(--surface);
    border-radius: var(--radius);
    padding: 1.5rem;
    margin-bottom: 2rem;
    border: 1px solid var(--border);
    box-shadow: var(--shadow-1);
}

.search-box {
    position: relative;
    width: 100%;
}

.search-input {
    width: 100%;
    padding: 1rem 1rem 1rem 3rem;
    border: 2px solid var(--border);
    border-radius: var(--radius);
    background: var(--bg);
    color: var(--text);
    font-size: 1rem;
    transition: all var(--normal);
}

.search-input:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: var(--ring);
}

.search-icon {
    position: absolute;
    left: 1rem;
    top: 50%;
    transform: translateY(-50%);
    color: var(--muted);
}

.search-results-info {
    margin-top: 1rem;
    padding: 0.75rem;
    background: var(--surface-2);
    border-radius: var(--radius-sm);
    color: var(--text-soft);
    font-size: 0.875rem;
}

/* فلتر الديون */
.debt-filter-container {
    background: var(--surface);
    border-radius: var(--radius);
    padding: 1rem;
    margin-bottom: 1.5rem;
    border: 1px solid var(--border);
    box-shadow: var(--shadow-1);
}

.debt-filter-container .btn-group .btn {
    border-radius: var(--radius-sm) !important;
}

.debt-filter-container .btn-group .btn.active {
    background: var(--primary);
    color: white;
    border-color: var(--primary);
}

.debt-filter-container .btn-group .btn:hover:not(.active) {
    background: var(--surface-2);
}

/* بطاقة العميل */
.customer-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
    gap: 1.25rem;
    margin-top: 2rem;
}

.customer-card {
    background: var(--surface);
    border-radius: var(--radius);
    border: 1px solid var(--border);
    overflow: hidden;
    transition: all var(--normal);
    box-shadow: var(--shadow-1);
    position: relative;
}

.customer-card:hover {
    transform: translateY(-4px);
    box-shadow: var(--shadow-2);
    border-color: var(--primary);
}

.customer-card.highlight-match {
    border: 2px solid var(--primary);
    box-shadow: 0 0 0 3px rgba(var(--primary-rgb), 0.1);
}

.customer-header {
    padding: 1.25rem 1.25rem 0.75rem;
    border-bottom: 1px solid var(--border);
    background: var(--surface-2);
}

.customer-name {
    font-size: 1.25rem;
    font-weight: 600;
    color: var(--text);
    margin-bottom: 0.25rem;
}

.customer-id {
    color: var(--muted);
    font-size: 0.875rem;
}

.customer-body {
    padding: 1.25rem;
}

.customer-info-row {
    display: flex;
    justify-content: space-between;
    margin-bottom: 0.75rem;
    padding-bottom: 0.75rem;
    border-bottom: 1px solid var(--border);
}

.customer-info-label {
    color: var(--text-soft);
    font-size: 0.875rem;
}

.customer-info-value {
    color: var(--text);
    font-weight: 500;
}

.balance-section {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
    margin: 1rem 0;
    padding: 1rem;
    background: var(--surface-2);
    border-radius: var(--radius-sm);
}

.balance-item {
    text-align: center;
    padding: 0.75rem;
    border-radius: var(--radius-sm);
}

.balance-item.debt {
    background: linear-gradient(135deg, rgba(239, 68, 68, 0.1), rgba(220, 38, 38, 0.1));
    border: 1px solid rgba(239, 68, 68, 0.2);
}

.balance-item.wallet {
    background: linear-gradient(135deg, rgba(245, 158, 11, 0.1), rgba(217, 119, 6, 0.1));
    border: 1px solid rgba(245, 158, 11, 0.2);
}

.balance-label {
    font-size: 0.75rem;
    color: var(--muted);
    margin-bottom: 0.25rem;
}

.balance-value {
    font-size: 1.25rem;
    font-weight: 700;
}

.balance-value.negative {
    color: var(--rose);
}

.balance-value.positive {
    color: var(--teal);
}

.balance-value.neutral {
    color: var(--muted);
}

.customer-footer {
    padding: 1rem 1.25rem;
    background: var(--surface-2);
    border-top: 1px solid var(--border);
    display: flex;
    justify-content: space-between;
    gap: 0.5rem;
}

.btn-icon {
    width: 36px;
    height: 36px;
    padding: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: var(--radius-sm);
}

/* تحميل البحث */
.loading-spinner {
    display: none;
    position: absolute;
    right: 1rem;
    top: 50%;
    transform: translateY(-50%);
}

.loading-spinner.active {
    display: block;
}

.spinner {
    width: 20px;
    height: 20px;
    border: 2px solid var(--border);
    border-top-color: var(--primary);
    border-radius: 50%;
    animation: spin 0.6s linear infinite;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

/* تحسين الجدول */
.table-responsive {
    border-radius: var(--radius);
    /* overflow: hidden; */
    border: 1px solid var(--border);
}

.custom-table {
    width: 100%;
    border-collapse: collapse;
    background: var(--surface);
}

.custom-table thead {
    background: linear-gradient(135deg, var(--primary), var(--primary-600));
    color: white;
}

.custom-table th {
    padding: 1rem;
    text-align: right;
    font-weight: 600;
    font-size: 0.875rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.custom-table tbody tr {
    border-bottom: 1px solid var(--border);
    transition: background-color var(--fast);
}

.custom-table tbody tr:hover {
    background-color: var(--surface-2);
}

.custom-table tbody tr.highlight-match {
    background-color: rgba(var(--primary-rgb), 0.05);
    border-left: 3px solid var(--primary);
}

.custom-table td {
    padding: 1rem;
    color: var(--text);
    vertical-align: middle;
}

/* مؤشرات الرصيد في الجدول */
.balance-badge {
    padding: 0.25rem 0.75rem;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
}

.balance-badge.debt {
    background: rgba(239, 68, 68, 0.1);
    color: var(--rose);
    border: 1px solid rgba(239, 68, 68, 0.2);
}

.balance-badge.wallet {
    background: rgba(245, 158, 11, 0.1);
    color: var(--amber);
    border: 1px solid rgba(245, 158, 11, 0.2);
}

/* زر العرض والطريقة القديمة */
.view-toggle {
    display: flex;
    gap: 0.5rem;
    margin-bottom: 1.5rem;
    padding: 0.75rem;
    background: var(--surface);
    border-radius: var(--radius);
    border: 1px solid var(--border);
}

.toggle-btn {
    padding: 0.5rem 1rem;
    border: 1px solid var(--border);
    background: var(--bg);
    color: var(--text);
    border-radius: var(--radius-sm);
    cursor: pointer;
    transition: all var(--fast);
}

.toggle-btn.active {
    background: var(--primary);
    color: white;
    border-color: var(--primary);
}

/* مؤشرات دقة البحث */
.search-match-indicator {
    font-size: 0.75rem;
    padding: 0.25rem 0.5rem;
    border-radius: 12px;
    background: rgba(var(--primary-rgb), 0.1);
    color: var(--primary);
    border: 1px solid rgba(var(--primary-rgb), 0.2);
    margin-left: 0.5rem;
}

/* شريط أدوات البحث */
.search-tools {
    display: flex;
    gap: 0.5rem;
    margin-top: 1rem;
    flex-wrap: wrap;
}

.search-tool-btn {
    padding: 0.375rem 0.75rem;
    font-size: 0.875rem;
    background: var(--surface-2);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    color: var(--text-soft);
    cursor: pointer;
    transition: all var(--fast);
}

.search-tool-btn:hover {
    background: var(--surface);
    color: var(--text);
}

.search-tool-btn.active {
    background: var(--primary);
    color: white;
    border-color: var(--primary);
}

/* اقتراحات البحث */
.search-suggestions {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    box-shadow: var(--shadow-2);
    z-index: 1000;
    max-height: 300px;
    overflow-y: auto;
    display: none;
}

.search-suggestion-item {
    padding: 0.75rem 1rem;
    border-bottom: 1px solid var(--border);
    cursor: pointer;
    transition: background var(--fast);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.search-suggestion-item:hover {
    background: var(--surface-2);
}

.search-suggestion-name {
    font-weight: 500;
    color: var(--text);
}

.search-suggestion-details {
    font-size: 0.875rem;
    color: var(--text-soft);
}

/* Dark mode adjustments */
[data-app][data-theme="dark"] .customer-card {
    background: var(--surface);
}

[data-app][data-theme="dark"] .search-input {
    background: var(--surface);
}

[data-app][data-theme="dark"] .balance-item.debt {
    background: rgba(239, 68, 68, 0.15);
}

[data-app][data-theme="dark"] .balance-item.wallet {
    background: rgba(245, 158, 11, 0.15);
}

/* Responsive */
@media (max-width: 768px) {
    .stats-grid {
        grid-template-columns: 1fr;
    }
    
    .customer-grid {
        grid-template-columns: 1fr;
    }
    
    .balance-section {
        grid-template-columns: 1fr;
    }
    
    .view-toggle {
        flex-direction: column;
    }
    
    .search-tools {
        flex-direction: column;
    }
}

.display-4{
    color: var(--text);
}
</style>

<div class="container mt-1 pt-3">
    <!-- العنوان والأزرار -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="mb-1"><i class="fas fa-address-book me-2"></i>إدارة العملاء</h1>
            <p class="text-muted">إدارة ومتابعة حسابات العملاء والرصيد المالي</p>
        </div>
        <div class="d-flex gap-2">
            <a href="<?php echo BASE_URL; ?>customer/insert.php" class="btn btn-success">
                <i class="fas fa-plus-circle me-2"></i>إضافة عميل جديد
            </a>
            <a href="<?php echo BASE_URL; ?>user/welcome.php" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-2"></i>عودة
            </a>
        </div>
    </div>

    <?php echo $message; ?>

    <!-- البطاقات الإحصائية -->
    <div class="stats-grid">
        <div class="stat-card primary">
            <div class="stat-icon"><i class="fas fa-users"></i></div>
            <div class="stat-label">إجمالي العملاء</div>
            <div class="stat-value"><?php echo number_format($stats['total_customers'] ?? 0); ?></div>
        </div>
        
        <div class="stat-card debt">
            <div class="stat-icon"><i class="fas fa-hand-holding-usd"></i></div>
            <div class="stat-label">إجمالي الديون (Balance > 0)</div>
            <div class="stat-value"><?php echo number_format($stats['total_debts'] ?? 0, 2); ?> ج.م</div>
        </div>
        
        <!-- بطاقة عدد العملاء المدينين -->
        <div class="stat-card" style="background: linear-gradient(135deg, #ef4444, #b91c1c); color: white;">
            <div class="stat-icon"><i class="fas fa-exclamation-circle"></i></div>
            <div class="stat-label">عدد العملاء المدينين (Balance > 0)</div>
            <?php
            $debt_customers_sql = "SELECT COUNT(*) as debt_count FROM customers WHERE balance > 0";
            $debt_count = 0;
            if ($res_debt = $conn->query($debt_customers_sql)) {
                $row = $res_debt->fetch_assoc();
                $debt_count = $row['debt_count'];
                $res_debt->free();
            }
            ?>
            <div class="stat-value"><?php echo number_format($debt_count); ?></div>
            <div class="stat-label">
                <?php echo $stats['total_customers'] > 0 
                    ? round(($debt_count / $stats['total_customers']) * 100, 1) 
                    : 0; ?>% من إجمالي العملاء
            </div>
        </div>
        
        <div class="stat-card wallet">
            <div class="stat-icon"><i class="fas fa-wallet"></i></div>
            <div class="stat-label">إجمالي المحفظة</div>
            <div class="stat-value"><?php echo number_format($stats['total_wallet'] ?? 0, 2); ?> ج.م</div>
            <div class="stat-label">
                إيجابي: <?php echo number_format($stats['total_positive_wallet'] ?? 0, 2); ?> ج.م |
                سلبي: <?php echo number_format($stats['total_negative_wallet'] ?? 0, 2); ?> ج.م
            </div>
        </div>
    </div>

    <!-- شريط البحث المتطور -->
    <div class="search-container">
        <div class="search-box">
            <i class="fas fa-search search-icon"></i>
            <input type="search" 
                   id="liveSearch" 
                   class="search-input" 
                   placeholder="ابحث عن عميل بالاسم، الموبايل، المدينة، العنوان..."
                   value="<?php echo e($q); ?>"
                   autocomplete="off"
                   autofocus>
            <div class="loading-spinner">
                <div class="spinner"></div>
            </div>
            <div id="searchSuggestions" class="search-suggestions"></div>
        </div>
        
        <!-- أدوات البحث -->
        <div class="search-tools">
            <button type="button" class="search-tool-btn" data-filter="exact-name">
                <i class="fas fa-user-check me-1"></i>مطابقة الاسم بالكامل
            </button>
            <button type="button" class="search-tool-btn" data-filter="mobile">
                <i class="fas fa-mobile-alt me-1"></i>بحث بالرقم فقط
            </button>
            <button type="button" class="search-tool-btn" data-filter="city">
                <i class="fas fa-city me-1"></i>بحث بالمدينة
            </button>
            <button type="button" class="search-tool-btn" id="clearSearch">
                <i class="fas fa-times me-1"></i>مسح البحث
            </button>
        </div>
        
        <div id="searchResultsInfo" class="search-results-info">
            <?php if ($search_mode): ?>
                <?php if (!empty($search_terms)): ?>
                    تم العثور على <?php echo count($customers); ?> عميل 
                    <?php if (count($search_terms) > 1): ?>
                        لمصطلحات البحث: "<?php echo e(implode(' ', $search_terms)); ?>"
                    <?php else: ?>
                        لمصطلح البحث: "<?php echo e($q); ?>"
                    <?php endif; ?>
                <?php else: ?>
                    إجمالي العملاء: <?php echo count($customers); ?>
                <?php endif; ?>
            <?php else: ?>
                إجمالي العملاء: <?php echo count($customers); ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- فلتر الديون -->
    <div class="debt-filter-container ">
        <div class="btn-group d-flex gap-2" role="group">
            <a href="<?php echo $_SERVER['PHP_SELF']; ?>" 
               class="btn btn-outline-secondary <?php echo !isset($_GET['debt_only']) && !isset($_GET['wallet_negative']) ? 'active' : ''; ?>">
                <i class="fas fa-users me-1"></i> جميع العملاء
            </a>
            <a href="<?php echo $_SERVER['PHP_SELF']; ?>?debt_only=1<?php echo $q ? '&q=' . urlencode($q) : ''; ?>" 
               class="btn btn-outline-danger <?php echo isset($_GET['debt_only']) ? 'active' : ''; ?>">
                <i class="fas fa-exclamation-triangle me-1"></i> العملاء المدينين فقط
            </a>
        </div>
    </div>

    <!-- زر تبديل طريقة العرض -->
    <div class="view-toggle">
        <button class="toggle-btn active" id="toggleGrid">
            <i class="fas fa-th-large me-2"></i>عرض الشبكة
        </button>
        <button class="toggle-btn" id="toggleTable">
            <i class="fas fa-table me-2"></i>عرض الجدول
        </button>
    </div>

    <!-- عرض الشبكة (افتراضي) -->
    <div id="gridView" class="customer-grid">
        <?php if (!empty($customers)): ?>
            <?php foreach ($customers as $row): 
                $cid = intval($row['id']);
                $balance = floatval($row['balance'] ?? 0);
                $wallet = floatval($row['wallet'] ?? 0);
                $is_protected = in_array($cid, $protected_customers, true);
                $counts = $invoice_counts[$cid] ?? ['paid'=>0,'partial'=>0,'pending'=>0,'returned'=>0,'total'=>0];
                
                // تحديد درجة المطابقة للعرض
                $search_score = isset($row['search_score_display']) ? $row['search_score_display'] : 0;
                $is_exact_match = $search_mode && ($search_score >= 1000 || $row['name'] === $q);
                ?>
                <div class="customer-card <?php echo $is_exact_match ? 'highlight-match' : ''; ?>" data-customer-id="<?php echo $cid; ?>">
                    <div class="customer-header">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <div class="customer-name"><?php echo e($row["name"]); ?></div>
                                <div class="customer-id">#<?php echo e($row["id"]); ?></div>
                            </div>
                            <?php if ($search_mode && $search_score > 0): ?>
                                <span class="search-match-indicator" title="درجة المطابقة">
                                    <i class="fas fa-chart-line me-1"></i><?php echo $search_score; ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="customer-body">
                        <div class="customer-info-row">
                            <span class="customer-info-label">الموبايل:</span>
                            <span class="customer-info-value"><?php echo e($row["mobile"]); ?></span>
                        </div>
                        
                        <div class="customer-info-row">
                            <span class="customer-info-label">المدينة:</span>
                            <span class="customer-info-value"><?php echo e($row["city"]); ?></span>
                        </div>
                        
                        <div class="customer-info-row">
                            <span class="customer-info-label">تاريخ الانضمام:</span>
                            <span class="customer-info-value"><?php echo e($row["join_date"] ?? 'غير محدد'); ?></span>
                        </div>
                        
                        <!-- قسم الرصيد -->
                        <div class="balance-section">
                            <div class="balance-item debt">
                                <div class="balance-label">الدين (Balance)</div>
                                <div class="balance-value <?php echo $balance > 0 ? 'negative' : ($balance < 0 ? 'positive' : 'neutral'); ?>">
                                    <?php echo number_format($balance, 2); ?> ج.م
                                </div>
                                <small><?php echo $balance > 0 ? 'عليه للشركة' : ($balance < 0 ? 'للشركة عليه' : 'مُتساوي'); ?></small>
                            </div>
                            
                            <div class="balance-item wallet">
                                <div class="balance-label">المحفظة (Wallet)</div>
                                <div class="balance-value <?php echo $wallet > 0 ? 'positive' : ($wallet < 0 ? 'negative' : 'neutral'); ?>">
                                    <?php echo number_format($wallet, 2); ?> ج.م
                                </div>
                                <small><?php echo $wallet > 0 ? 'رصيد إيجابي' : ($wallet < 0 ? 'رصيد سلبي' : 'لا يوجد رصيد'); ?></small>
                            </div>
                        </div>
                        
                        <?php if (!empty($row["notes"])): ?>
                            <div class="customer-info-row">
                                <span class="customer-info-label">ملاحظات:</span>
                                <span class="customer-info-value" style="font-size: 0.875rem;">
                                    <?php echo mb_substr($row["notes"], 0, 50) . (mb_strlen($row["notes"]) > 50 ? '...' : ''); ?>
                                </span>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="customer-footer d-flex justify-content-between align-items-center">
                        <button type="button" class="btn btn-info btn-icon btn-view" 
                                title="عرض التفاصيل" data-customer='<?php echo e(json_encode($row)); ?>'>
                            <i class="fas fa-eye"></i>
                        </button>

                        <a href="../client/customer_details.php?customer_id=<?php echo $row['id']; ?>"
                           class="btn btn-outline-info btn-sm d-inline-flex align-items-center gap-1"
                           title="عرض تفاصيل العميل">
                            <i class="fas fa-user-circle"></i>
                            <span class="d-none d-md-inline">تفاصيل</span>
                        </a>
                        
                        <form action="<?php echo BASE_URL; ?>admin/edit_customer.php" method="post" class="d-inline">
                            <input type="hidden" name="customer_id_to_edit" value="<?php echo e($row["id"]); ?>">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            <button type="submit" class="btn btn-warning btn-icon" title="تعديل">
                                <i class="fas fa-edit"></i>
                            </button>
                        </form>
                        
                        <?php if ($counts['total'] > 0): ?>
                            <div class="d-flex gap-1">
                                <!-- مسلم -->
                                <?php if ($counts['paid'] > 0): ?>
                                    <span class="btn btn-outline-success btn-sm" title="فواتير مسلمة">
                                        <i class="fas fa-check-circle"></i>
                                        <?php echo $counts['paid']; ?>
                                    </span>
                                <?php endif; ?>

                                <!-- جزئي -->
                                <?php if ($counts['partial'] > 0): ?>
                                    <span class="btn btn-outline-warning btn-sm" title="فواتير مدفوعة جزئيًا">
                                        <i class="fas fa-adjust"></i>
                                        <?php echo $counts['partial']; ?>
                                    </span>
                                <?php endif; ?>

                                <!-- مؤجل -->
                                <?php if ($counts['pending'] > 0): ?>
                                    <span class="btn btn-outline-primary btn-sm" title="فواتير مؤجلة">
                                        <i class="fas fa-hourglass-half"></i>
                                        <?php echo $counts['pending']; ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <span class="text-muted" style="font-size: 0.75rem;">
                                لا توجد فواتير
                            </span>
                        <?php endif; ?>
                        
                        <!-- زر الحذف -->
                        <?php if (!$is_protected && $counts['total'] == 0): ?>
                            <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post" class="d-inline">
                                <input type="hidden" name="customer_id_to_delete" value="<?php echo e($row["id"]); ?>">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                <button type="submit" name="delete_customer" 
                                        class="btn btn-danger btn-icon"
                                        onclick="return confirm('هل أنت متأكد من حذف هذا العميل؟');" 
                                        title="حذف">
                                    <i class="fas fa-trash-alt"></i>
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="col-12 text-center py-5">
                <i class="fas fa-users fa-3x text-muted mb-3"></i>
                <h4 class="text-muted">لا يوجد عملاء</h4>
                <p class="text-muted"><?php 
                    if (isset($_GET['debt_only'])) {
                        echo 'لا يوجد عملاء لديهم ديون في الوقت الحالي';
                    } elseif (isset($_GET['wallet_negative'])) {
                        echo 'لا يوجد عملاء لديهم رصيد سلبي في المحفظة';
                    } else {
                        echo 'قم بإضافة عميل جديد لبدء المتابعة';
                    }
                ?></p>
            </div>
        <?php endif; ?>
    </div>

    <!-- عرض الجدول (مخفي افتراضيًا) -->
    <div id="tableView" class="d-none">
        <div class="table-responsive custom-table-wrapper">
            <table class="table custom-table">
                <thead class="center">
                    <tr>
                        <th>#</th>
                        <th>الاسم</th>
                        <th>الموبايل</th>
                        <th>المدينة</th>
                        <th>الديون</th>
                        <th>المحفظة</th>
                        <th>درجة المطابقة</th>
                        <th>ملاحظات</th>
                        <th class="text-center">إجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($customers)): ?>
                        <?php foreach ($customers as $row): 
                            $cid = intval($row['id']);
                            $balance = floatval($row['balance'] ?? 0);
                            $wallet = floatval($row['wallet'] ?? 0);
                            $is_protected = in_array($cid, $protected_customers, true);
                            $counts = $invoice_counts[$cid] ?? ['paid'=>0,'partial'=>0,'pending'=>0,'returned'=>0,'total'=>0];
                            $preview_notes = !empty($row["notes"]) ? mb_substr($row["notes"], 0, 30) . (mb_strlen($row["notes"])>30?'...':'') : '-';
                            $search_score = isset($row['search_score_display']) ? $row['search_score_display'] : 0;
                            $is_exact_match = $search_mode && ($search_score >= 1000 || $row['name'] === $q);
                            ?>
                            <tr class="<?php echo $is_exact_match ? 'highlight-match' : ''; ?>" data-customer='<?php echo e(json_encode($row)); ?>'>
                                <td><?php echo e($row["id"]); ?></td>
                                <td><strong><?php echo e($row["name"]); ?></strong></td>
                                <td><?php echo e($row["mobile"]); ?></td>
                                <td><?php echo e($row["city"]); ?></td>
                                <td>
                                    <span class="balance-badge debt">
                                        <i class="fas fa-<?php echo $balance > 0 ? 'hand-holding-usd' : 'credit-card'; ?> me-1"></i>
                                        <?php echo number_format($balance, 2); ?> ج.م
                                    </span>
                                </td>
                                <td>
                                    <span class="balance-badge wallet">
                                        <i class="fas fa-wallet me-1"></i>
                                        <?php echo number_format($wallet, 2); ?> ج.م
                                    </span>
                                </td>
                                <td>
                                    <?php if ($search_mode && $search_score > 0): ?>
                                        <span class="badge bg-info"><?php echo $search_score; ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo e($preview_notes); ?></td>
                                <td class="text-center gap-1 d-flex align-items-center justify-content-center">
                                    <button type="button" class="btn btn-info btn-sm btn-view" title="عرض التفاصيل">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    
                                    <a href="../client/customer_details.php?customer_id=<?php echo $row['id']; ?>"
                                       class="btn btn-outline-info btn-sm d-inline-flex align-items-center gap-1"
                                       title="عرض تفاصيل العميل">
                                        <i class="fas fa-user-circle"></i>
                                    </a>
                                    
                                    <form action="<?php echo BASE_URL; ?>admin/edit_customer.php" method="post" class="d-inline">
                                        <input type="hidden" name="customer_id_to_edit" value="<?php echo e($row["id"]); ?>">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                        <button type="submit" class="btn btn-warning btn-sm" title="تعديل">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                    </form>
                                    
                                    <?php if (!$is_protected && $counts['total'] == 0): ?>
                                        <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post" class="d-inline">
                                            <input type="hidden" name="customer_id_to_delete" value="<?php echo e($row["id"]); ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                            <button type="submit" name="delete_customer" 
                                                    class="btn btn-danger btn-sm"
                                                    onclick="return confirm('هل أنت متأكد من حذف هذا العميل؟');" 
                                                    title="حذف">
                                                <i class="fas fa-trash-alt"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="9" class="text-center py-4">
                                <i class="fas fa-users fa-2x text-muted mb-2"></i>
                                <p class="text-muted mb-0"><?php 
                                    if (isset($_GET['debt_only'])) {
                                        echo 'لا يوجد عملاء لديهم ديون في الوقت الحالي';
                                    } elseif (isset($_GET['wallet_negative'])) {
                                        echo 'لا يوجد عملاء لديهم رصيد سلبي في المحفظة';
                                    } else {
                                        echo 'لا يوجد عملاء لعرضهم';
                                    }
                                ?></p>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal عرض التفاصيل -->
<div id="customerModal" class="modal-backdrop" aria-hidden="true">
  <div class="mymodal">
    <h3><i class="fas fa-user-circle me-2"></i>تفاصيل العميل</h3>
    <div id="modalCustomerBody">
      <p class="muted-small">اختر عميلاً لعرض التفاصيل.</p>
    </div>
    <div class="modal-footer">
      <div>
        <button id="modalClose" type="button" class="btn btn-secondary">إغلاق</button>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function(){
    const modal = document.getElementById('customerModal');
    const modalBody = document.getElementById('modalCustomerBody');
    const btnClose = document.getElementById('modalClose');
    const liveSearch = document.getElementById('liveSearch');
    const loadingSpinner = document.querySelector('.loading-spinner');
    const searchResultsInfo = document.getElementById('searchResultsInfo');
    const searchSuggestions = document.getElementById('searchSuggestions');
    const toggleGrid = document.getElementById('toggleGrid');
    const toggleTable = document.getElementById('toggleTable');
    const gridView = document.getElementById('gridView');
    const tableView = document.getElementById('tableView');
    const clearSearchBtn = document.getElementById('clearSearch');
    const searchToolBtns = document.querySelectorAll('.search-tool-btn[data-filter]');
    
    let searchTimeout;
    let currentSearchFilter = null;
    
    // جعل حقل البحث في حالة التركيز عند تحميل الصفحة
    if (liveSearch) {
        liveSearch.focus();
        // وضع المؤشر في نهاية النص إذا كان هناك نص موجود
        if (liveSearch.value) {
            liveSearch.setSelectionRange(liveSearch.value.length, liveSearch.value.length);
        }
    }
    
    // تبديل طريقة العرض
    toggleGrid.addEventListener('click', function() {
        toggleGrid.classList.add('active');
        toggleTable.classList.remove('active');
        gridView.style.display = 'grid';
        tableView.classList.add('d-none');
    });

    toggleTable.addEventListener('click', function() {
        toggleTable.classList.add('active');
        toggleGrid.classList.remove('active');
        gridView.style.display = 'none';
        tableView.classList.remove('d-none');
    });

    // البحث المباشر مع تحسينات
    liveSearch.addEventListener('input', function() {
        clearTimeout(searchTimeout);
        const query = this.value.trim();
        
        if (query.length === 0) {
            // إذا كان البحث فارغًا، إعادة تحميل الصفحة لإظهار الكل
            reloadPageWithoutSearch();
            return;
        }
        
        loadingSpinner.classList.add('active');
        searchSuggestions.style.display = 'none';
        
        searchTimeout = setTimeout(() => {
            performSearch(query);
        }, 300); // تأخير 300 مللي ثانية
    });
    
    // اقتراحات البحث أثناء الكتابة
    liveSearch.addEventListener('keyup', function() {
        const query = this.value.trim();
        
        if (query.length < 2) {
            searchSuggestions.style.display = 'none';
            return;
        }
        
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => {
            fetchSearchSuggestions(query);
        }, 200);
    });
    
    // إغلاق اقتراحات البحث عند النقر خارجها
    document.addEventListener('click', function(e) {
        if (!searchSuggestions.contains(e.target) && e.target !== liveSearch) {
            searchSuggestions.style.display = 'none';
        }
    });
    
    // زر مسح البحث
    clearSearchBtn.addEventListener('click', function() {
        liveSearch.value = '';
        liveSearch.focus();
        reloadPageWithoutSearch();
    });
    
    // أزرار فلاتر البحث
    searchToolBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            const filter = this.getAttribute('data-filter');
            
            // تبديل حالة الزر النشط
            searchToolBtns.forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            
            currentSearchFilter = filter;
            
            const query = liveSearch.value.trim();
            if (query.length > 0) {
                performSearch(query);
            }
        });
    });
    
    // إعادة تحميل الصفحة بدون معلمة البحث
    function reloadPageWithoutSearch() {
        const url = new URL(window.location.href);
        url.searchParams.delete('q');
        window.location.href = url.toString();
    }
    
    // إجراء البحث
    function performSearch(query) {
        // احتفظ بمعلمات الفلتر الحالية
        const urlParams = new URLSearchParams(window.location.search);
        const debtOnly = urlParams.get('debt_only');
        const walletNegative = urlParams.get('wallet_negative');
        
        let fetchUrl = `<?php echo $_SERVER['PHP_SELF']; ?>?q=${encodeURIComponent(query)}`;
        
        // إضافة فلتر البحث المخصص إذا تم تحديده
        if (currentSearchFilter) {
            fetchUrl += `&search_filter=${currentSearchFilter}`;
        }
        
        if (debtOnly) fetchUrl += `&debt_only=${debtOnly}`;
        if (walletNegative) fetchUrl += `&wallet_negative=${walletNegative}`;
        
        fetch(fetchUrl)
            .then(response => response.text())
            .then(html => {
                // تحليل الـ HTML واستخراج جزء العملاء فقط
                const parser = new DOMParser();
                const doc = parser.parseFromString(html, 'text/html');
                
                // استخراج البيانات المطلوبة
                const customersGrid = doc.getElementById('gridView');
                const customersTable = doc.getElementById('tableView');
                const statsInfo = doc.querySelector('.search-results-info');
                
                if (customersGrid) {
                    gridView.innerHTML = customersGrid.innerHTML;
                }
                
                if (customersTable) {
                    tableView.innerHTML = customersTable.innerHTML;
                }
                
                if (statsInfo) {
                    searchResultsInfo.innerHTML = statsInfo.innerHTML;
                }
                
                // إعادة ربط أحداث الأزرار للبيانات الجديدة
                rebindViewButtons();
                rebindDeleteButtons();
                
                loadingSpinner.classList.remove('active');
            })
            .catch(error => {
                console.error('Error:', error);
                loadingSpinner.classList.remove('active');
                searchResultsInfo.innerHTML = '<span class="text-danger">حدث خطأ أثناء البحث. يرجى المحاولة مرة أخرى.</span>';
            });
    }
    
    // جلب اقتراحات البحث
    function fetchSearchSuggestions(query) {
        fetch(`<?php echo $_SERVER['PHP_SELF']; ?>?ajax_suggestions=1&q=${encodeURIComponent(query)}`)
            .then(response => response.json())
            .then(data => {
                if (data.suggestions && data.suggestions.length > 0) {
                    renderSearchSuggestions(data.suggestions);
                } else {
                    searchSuggestions.style.display = 'none';
                }
            })
            .catch(error => {
                console.error('Error fetching suggestions:', error);
                searchSuggestions.style.display = 'none';
            });
    }
    
    // عرض اقتراحات البحث
    function renderSearchSuggestions(suggestions) {
        searchSuggestions.innerHTML = '';
        
        suggestions.forEach(customer => {
            const item = document.createElement('div');
            item.className = 'search-suggestion-item';
            item.innerHTML = `
                <div>
                    <div class="search-suggestion-name">${escapeHtml(customer.name)}</div>
                    <div class="search-suggestion-details">
                        ${customer.mobile ? escapeHtml(customer.mobile) + ' • ' : ''}
                        ${customer.city ? escapeHtml(customer.city) : ''}
                    </div>
                </div>
                <div>
                    <span class="badge bg-secondary">#${customer.id}</span>
                </div>
            `;
            
            item.addEventListener('click', function() {
                liveSearch.value = customer.name;
                performSearch(customer.name);
                searchSuggestions.style.display = 'none';
            });
            
            searchSuggestions.appendChild(item);
        });
        
        searchSuggestions.style.display = 'block';
    }
    
    // إعادة ربط أحداث عرض التفاصيل
    function rebindViewButtons() {
        document.querySelectorAll('.btn-view').forEach(btn => {
            btn.addEventListener('click', function(e) {
                let data;
                if (this.hasAttribute('data-customer')) {
                    data = JSON.parse(this.getAttribute('data-customer'));
                } else {
                    const tr = this.closest('tr');
                    if (tr) {
                        const raw = tr.getAttribute('data-customer');
                        if (raw) data = JSON.parse(raw);
                    }
                }
                
                if (data) {
                    renderCustomerModal(data);
                }
            });
        });
    }
    
    // إعادة ربط أحداث الحذف
    function rebindDeleteButtons() {
        document.querySelectorAll('form[action*="delete"]').forEach(form => {
            const btn = form.querySelector('button[type="submit"]');
            if (btn) {
                btn.addEventListener('click', function(e) {
                    if (!confirm('هل أنت متأكد من حذف هذا العميل؟')) {
                        e.preventDefault();
                    }
                });
            }
        });
    }
    
    // عرض تفاصيل العميل في المودال
    function renderCustomerModal(data) {
        const balance = parseFloat(data.balance || 0);
        const wallet = parseFloat(data.wallet || 0);
        const created = data.created_at ? (new Date(data.created_at)).toLocaleString('ar-EG') : '-';
        const joinDate = data.join_date ? (new Date(data.join_date)).toLocaleDateString('ar-EG') : '-';
        const creator = data.creator_name || '-';
        const address = data.address || '-';
        const notes = data.notes || '-';
        
        let balanceStatus = 'مُتساوي';
        let balanceClass = 'neutral';
        if (balance > 0) {
            balanceStatus = 'عليه للشركة';
            balanceClass = 'negative';
        } else if (balance < 0) {
            balanceStatus = 'للشركة عليه';
            balanceClass = 'positive';
        }
        
        let walletStatus = 'لا يوجد رصيد';
        let walletClass = 'neutral';
        if (wallet > 0) {
            walletStatus = 'رصيد إيجابي';
            walletClass = 'positive';
        } else if (wallet < 0) {
            walletStatus = 'رصيد سلبي';
            walletClass = 'negative';
        }
        
        modalBody.innerHTML = `
            <div class="customer-details">
                <div class="row mb-3">
                    <div class="col-md-6">
                        <div class="detail-item">
                            <strong>الاسم الكامل:</strong>
                            <div class="detail-value">${escapeHtml(data.name)}</div>
                        </div>
                        <div class="detail-item">
                            <strong>رقم الموبايل:</strong>
                            <div class="detail-value">${escapeHtml(data.mobile || '-')}</div>
                        </div>
                        <div class="detail-item">
                            <strong>المدينة:</strong>
                            <div class="detail-value">${escapeHtml(data.city || '-')}</div>
                        </div>
                        <div class="detail-item">
                            <strong>العنوان التفصيلي:</strong>
                            <div class="detail-value">${escapeHtml(address)}</div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="detail-item">
                            <strong>تاريخ الانضمام:</strong>
                            <div class="detail-value">${joinDate}</div>
                        </div>
                        <div class="detail-item">
                            <strong>تاريخ الإضافة:</strong>
                            <div class="detail-value">${created}</div>
                        </div>
                        <div class="detail-item">
                            <strong>أضيف بواسطة:</strong>
                            <div class="detail-value">${escapeHtml(creator)}</div>
                        </div>
                        <div class="detail-item">
                            <strong>معرف العميل:</strong>
                            <div class="detail-value">#${data.id}</div>
                        </div>
                    </div>
                </div>
                
                <div class="row mb-3">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header bg-gradient-debt">
                                <i class="fas fa-hand-holding-usd me-2"></i>الرصيد (Balance)
                            </div>
                            <div class="card-body">
                                <div class="text-center ">
                                    <div class="display-4 mb-2 ${balanceClass}">${balance.toFixed(2)} ج.م</div>
                                    <p class="text-muted">${balanceStatus}</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header bg-gradient-wallet">
                                <i class="fas fa-wallet me-2"></i>المحفظة (Wallet)
                            </div>
                            <div class="card-body">
                                <div class="text-center">
                                    <div class="display-4 mb-2 ${walletClass}">${wallet.toFixed(2)} ج.م</div>
                                    <p class="text-muted">${walletStatus}</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <i class="fas fa-sticky-note me-2"></i>الملاحظات
                            </div>
                            <div class="card-body">
                                <div class="notes-content" style="max-height: 200px; overflow-y: auto; padding: 10px; background: var(--bg);color: var(--text);border-radius: 8px;">
                                    ${notes ? escapeHtml(notes).replace(/\n/g, '<br>') : '<p class="text-muted text-center">لا توجد ملاحظات</p>'}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        `;
        modal.classList.add('open');
    }
    
    // دالة الهروب من HTML
    function escapeHtml(str) {
        if (str === undefined || str === null) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }
    
    // ربط الأحداث الأولية
    rebindViewButtons();
    rebindDeleteButtons();
    
    btnClose.addEventListener('click', () => modal.classList.remove('open'));
    
    // إغلاق المودال عند النقر خارجها
    modal.addEventListener('click', function(e) {
        if (e.target === modal) {
            modal.classList.remove('open');
        }
    });
    
    // إضافة وظيفة الـ Enter للبحث
    liveSearch.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            const query = this.value.trim();
            if (query.length > 0) {
                performSearch(query);
                searchSuggestions.style.display = 'none';
            }
        }
    });
});

// إضافة بعض الأنماط الإضافية للمودال
const modalStyle = document.createElement('style');
modalStyle.textContent = `
.modal-backdrop{ 
    position:fixed; 
    left:0; 
    top:0; 
    right:0; 
    bottom:0; 
    display:none; 
    align-items:center; 
    justify-content:center; 
    background:rgba(2,6,23,0.7); 
    z-index:9999;
    backdrop-filter: blur(4px);
}
.modal-backdrop.open{ 
    display:flex;
    animation: fadeIn 0.3s ease;
}
.mymodal{ 
    background:var(--surface);
    color: var(--text); 
    padding:24px; 
    border-radius:var(--radius-lg); 
    max-width:900px; 
    width:95%;
    max-height:90vh;
    overflow-y:auto;
    box-shadow:var(--shadow-2);
    border:1px solid var(--border);
}
.mymodal h3 {
    color:var(--primary);
    margin-bottom:1.5rem;
    padding-bottom:1rem;
    border-bottom:2px solid var(--border);
}
.detail-item {
    margin-bottom:1rem;
    padding-bottom:0.75rem;
    border-bottom:1px solid var(--border);
}
.detail-item strong {
    color:var(--text-soft);
    display:block;
    margin-bottom:0.25rem;
    font-size:0.875rem;
}
.detail-value {
    color:var(--text);
    font-size:1rem;
    font-weight:500;
}
.bg-gradient-debt {
    background:linear-gradient(135deg, var(--rose), #dc2626) !important;
    color:white !important;
}
.bg-gradient-wallet {
    background:linear-gradient(135deg, var(--amber), #d97706) !important;
    color:white !important;
}
@keyframes fadeIn {
    from { opacity:0; }
    to { opacity:1; }
}
`;
document.head.appendChild(modalStyle);

// إضافة تحسينات إضافية للبحث
document.addEventListener('DOMContentLoaded', function() {
    // إضافة خاصية autofocus لحقل البحث
    const searchInput = document.getElementById('liveSearch');
    if (searchInput && !searchInput.value) {
        searchInput.focus();
    }
    
    // حفظ حالة البحث في localStorage
    if (searchInput && searchInput.value) {
        localStorage.setItem('customer_search_query', searchInput.value);
    }
    
    // استعادة حالة البحث عند العودة للصفحة
    window.addEventListener('pageshow', function() {
        const savedQuery = localStorage.getItem('customer_search_query');
        if (savedQuery && searchInput && !searchInput.value) {
            searchInput.value = savedQuery;
        }
    });
});
</script>

<?php
// معالجة طلبات AJLAX للاقتراحات
if (isset($_GET['ajax_suggestions']) && isset($_GET['q'])) {
    $suggestions = [];
    $query = trim($_GET['q']);
    
    if (strlen($query) >= 2) {
        $sql = "SELECT id, name, mobile, city FROM customers 
                WHERE (name LIKE ? OR mobile LIKE ?) 
                ORDER BY 
                    CASE 
                        WHEN name = ? THEN 1
                        WHEN name LIKE ? THEN 2
                        WHEN mobile = ? THEN 3
                        ELSE 4
                    END,
                    name ASC 
                LIMIT 10";
        
        if ($stmt = $conn->prepare($sql)) {
            $like1 = '%' . $query . '%';
            $like2 = $query . '%';
            $stmt->bind_param('sssss', $like1, $like1, $query, $like2, $query);
            $stmt->execute();
            $result = $stmt->get_result();
            
            while ($row = $result->fetch_assoc()) {
                $suggestions[] = $row;
            }
            
            $stmt->close();
        }
    }
    
    header('Content-Type: application/json');
    echo json_encode(['suggestions' => $suggestions]);
    exit;
}

$conn->close();
require_once BASE_DIR . 'partials/footer.php';
?>