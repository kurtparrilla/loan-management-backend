<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}
require_once '../../config/database.php';
require_once '../../helpers/response.php';
require_once '../../helpers/auth.php';

if($_SERVER['REQUEST_METHOD'] != 'GET'){
    sendResponse('error', 'Method not Allowed', null, 405);
    exit;
}

$db = new Database();
$conn = $db->connect();
$userId = requireAuthUserId($conn);

$status = $_GET['status'] ?? 'all';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit  = isset($_GET['limit']) ? (int)$_GET['limit'] : 5;
$search = trim($_GET['search'] ?? '');

$allowedStatus = ['all', 'active', 'overdue', 'paid'];
if (!in_array($status, $allowedStatus, true)) {
    sendResponse('error', 'Invalid status', null, 400);
}

if ($page < 1) $page = 1;
if ($limit < 1) $limit = 5;
if ($limit > 20) $limit = 20; 
$offset = ($page - 1) * $limit;

$where = [];
$params =[];

$where[] = "l.status IN ('active', 'paid')";

if($search !== ''){
    $where[] = "(CONCAT(b.first_name, ' ', b.last_name) LIKE :search OR b.phone LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

if ($status === 'paid') {
    $where[] = "l.status = 'paid'";
} elseif ($status === 'overdue') {
    $where[] = "l.status = 'active' AND EXISTS (
        SELECT 1
        FROM loan_schedules s
        WHERE s.loan_id = l.loan_id
          AND s.due_date < CURDATE()
          AND s.status <> 'paid'
    )";
} elseif ($status === 'active') {
    $where[] = "l.status = 'active' AND NOT EXISTS (
        SELECT 1
        FROM loan_schedules s
        WHERE s.loan_id = l.loan_id
          AND s.due_date < CURDATE()
          AND s.status <> 'paid'
    )";
}
 
$whereSql = 'WHERE ' . implode(' AND ', $where);

try {
    $countSql = "SELECT COUNT(*) AS total
                FROM loans l
                JOIN borrowers b ON b.borrower_id = l.borrower_id
                {$whereSql}";

    $stmtCount = $conn->prepare($countSql);
    foreach ($params as $k => $v) $stmtCount->bindValue($k, $v);
    $stmtCount->execute();
    $total = (int)$stmtCount->fetch(PDO::FETCH_ASSOC)['total'];

    $listSql = "
    SELECT
        l.loan_id,
        CONCAT(b.first_name, ' ', b.last_name) AS borrower_name,
        b.phone,
        l.principal_amount,
        (
          SELECT MIN(s2.due_date)
          FROM loan_schedules s2
          WHERE s2.loan_id = l.loan_id AND s2.status <> 'paid'
        ) AS next_due_date,
        ROUND(
          COALESCE((
            SELECT SUM(pa.amount_applied)
            FROM payment_allocations pa
            JOIN loan_schedules sx ON sx.schedule_id = pa.schedule_id
            WHERE sx.loan_id = l.loan_id
          ), 0) /
          NULLIF((
            SELECT SUM(s3.expected_total)
            FROM loan_schedules s3
            WHERE s3.loan_id = l.loan_id
          ), 0) * 100, 1
        ) AS progress_percent,
        CASE
          WHEN l.status = 'paid' THEN 'paid'
          WHEN EXISTS (
            SELECT 1 FROM loan_schedules s
            WHERE s.loan_id = l.loan_id
              AND s.due_date < CURDATE()
              AND s.status <> 'paid'
          ) THEN 'overdue'
          ELSE 'active'
        END AS status
    FROM loans l
    JOIN borrowers b ON b.borrower_id = l.borrower_id
    {$whereSql}
    ORDER BY l.loan_id DESC
    LIMIT :limit OFFSET :offset
    ";
    $stmtList = $conn->prepare($listSql);
    foreach ($params as $k => $v) $stmtList->bindValue($k, $v);
    $stmtList->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmtList->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmtList->execute();
    $items = $stmtList->fetchAll(PDO::FETCH_ASSOC);
    $totalPages = (int) ceil($total / $limit);

    sendResponse('success', 'Loan list fetched', [
        'items' => $items,
        'meta' => [
            'status' => $status,
            'search' => $search,
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'total_pages' => $totalPages,
        ]
    ]);
} catch (PDOException $e) {
    error_log($e->getMessage());
    sendResponse('error', 'An error occurred. Please try again later.', null, 500);
}
