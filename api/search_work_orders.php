<?php
header('Content-Type: application/json; charset=utf-8');
require_once dirname(__DIR__) . '/config.php';

$query = isset($_GET['query']) ? trim($_GET['query']) : '';

if (empty($query)) {
    echo json_encode(['success' => true, 'suggestions' => []]);
    exit;
}

try {
    $search = "%{$query}%";
    $start_date = isset($_GET['start_date']) ? $_GET['start_date'] . " 00:00:00" : null;
    $end_date = isset($_GET['end_date']) ? $_GET['end_date'] . " 23:59:59" : null;

    $sql = "SELECT DISTINCT wo.id, wo.title, wo.status, c.name as customer_name 
            FROM work_orders wo
            LEFT JOIN customers c ON wo.customer_id = c.id
            INNER JOIN invoices_out io ON wo.id = io.work_order_id
            WHERE (wo.title LIKE ? OR c.name LIKE ? OR wo.id = ?)";
    
    $params = [$search, $search, $query];
    $types = "ssi";

    if ($start_date && $end_date) {
        $sql .= " AND io.created_at BETWEEN ? AND ?";
        $params[] = $start_date;
        $params[] = $end_date;
        $types .= "ss";
    }

    $sql .= " LIMIT 10";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $suggestions = [];
    while ($row = $result->fetch_assoc()) {
        $status_text = '';
        switch($row['status']) {
            case 'pending': $status_text = 'قيد التنفيذ'; break;
            case 'in_progress': $status_text = 'جاري العمل'; break;
            case 'completed': $status_text = 'مكتمل'; break;
            default: $status_text = 'ملغي';
        }
        
        $suggestions[] = [
            'id' => $row['id'],
            'title' => $row['title'],
            'status' => $row['status'],
            'status_text' => $status_text,
            'customer_name' => $row['customer_name'] ?? 'بدون عميل'
        ];
    }
    
    echo json_encode(['success' => true, 'suggestions' => $suggestions], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
