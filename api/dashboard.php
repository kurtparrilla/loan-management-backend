<?php
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedOriginsRaw = getenv('CORS_ALLOWED_ORIGINS') ?: ($_ENV['CORS_ALLOWED_ORIGINS'] ?? '');
if ($allowedOriginsRaw === '') {
    $envPath = __DIR__ . '/../../.env';
    if (!file_exists($envPath)) {
        $envPath = __DIR__ . '/../.env';
    }
    if (file_exists($envPath)) {
        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0 || strpos($line, '=') === false) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            if (trim($key) === 'CORS_ALLOWED_ORIGINS') {
                $allowedOriginsRaw = trim($value);
                break;
            }
        }
    }
}
$allowedOrigins = array_filter(array_map('trim', explode(',', $allowedOriginsRaw)));
if ($origin !== '' && in_array($origin, $allowedOrigins, true)) {
    header("Access-Control-Allow-Origin: $origin");
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

require_once '../config/database.php';
require_once '../helpers/response.php';
require_once '../helpers/auth.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] != 'GET') {
    sendResponse('error', 'Method not allowed', null, 405);
}

$db = new Database();
$conn = $db->connect();
$userId = requireAuthUserId($conn);

try{
    // CARDS QUERY
    $stmt_cards = $conn->prepare("SELECT
                COALESCE((SELECT SUM(principal_amount) FROM loans WHERE status IN ('active','paid')), 0) AS total_loans_issued,
                COALESCE((SELECT COUNT(*) FROM loans WHERE status = 'active'), 0) AS active_loans_count,
                COALESCE((SELECT SUM(amount_paid) FROM payments), 0) AS total_collected,
                ROUND(
                  COALESCE((SELECT SUM(amount_paid) FROM payments), 0) /
                  NULLIF((SELECT SUM(principal_amount) FROM loans WHERE status IN ('active','paid')), 0) * 100, 1
                ) AS collected_pct,
                COALESCE((
                  SELECT SUM(GREATEST(s.expected_total - COALESCE(a.paid_so_far,0), 0))
                  FROM loan_schedules s
                  JOIN loans l ON l.loan_id = s.loan_id
                  LEFT JOIN (
                    SELECT schedule_id, SUM(amount_applied) AS paid_so_far
                    FROM payment_allocations
                    GROUP BY schedule_id
                  ) a ON a.schedule_id = s.schedule_id
                  WHERE l.status = 'active' AND s.status <> 'paid'
                ), 0) AS outstanding_balance,
                COALESCE((
                  SELECT COUNT(DISTINCT l.borrower_id)
                  FROM loans l
                  JOIN loan_schedules s ON s.loan_id = l.loan_id
                  WHERE l.status = 'active' AND s.due_date < CURDATE() AND s.status <> 'paid'
                ), 0) AS overdue_borrowers,
                COALESCE((
                  SELECT SUM(GREATEST(s.expected_total - COALESCE(a.paid_so_far,0), 0))
                  FROM loan_schedules s
                  JOIN loans l ON l.loan_id = s.loan_id
                  LEFT JOIN (
                    SELECT schedule_id, SUM(amount_applied) AS paid_so_far
                    FROM payment_allocations
                    GROUP BY schedule_id
                  ) a ON a.schedule_id = s.schedule_id
                  WHERE l.status = 'active' AND s.due_date < CURDATE() AND s.status <> 'paid'
                ), 0) AS overdue_amount;
                ");
    $stmt_cards->execute();
    $cards = $stmt_cards->fetch(PDO::FETCH_ASSOC);
    //LOAN COUNTS FOR TAB
    $stmt_loan_counts = $conn->prepare("
                                        SELECT
                                          COUNT(*) AS `all`,
                                          SUM(CASE WHEN l.status = 'paid' THEN 1 ELSE 0 END) AS paid,
                                          SUM(CASE WHEN l.status = 'active'
                                                    AND EXISTS (
                                                      SELECT 1 FROM loan_schedules s
                                                      WHERE s.loan_id = l.loan_id
                                                        AND s.due_date < CURDATE()
                                                        AND s.status <> 'paid'
                                                    ) THEN 1 ELSE 0 END) AS overdue,
                                            SUM(CASE WHEN l.status = 'active'
                                                    AND NOT EXISTS (
                                                      SELECT 1 FROM loan_schedules s
                                                      WHERE s.loan_id = l.loan_id
                                                        AND s.due_date < CURDATE()
                                                        AND s.status <> 'paid'
                                                    ) THEN 1 ELSE 0 END) AS active
                                        FROM loans l
                                        WHERE l.status IN ('active','paid');
                                        ");
    $stmt_loan_counts->execute();
    $loan_counts = $stmt_loan_counts->fetch(PDO::FETCH_ASSOC);
    //Loan summary
    $stmt_loan_summary = $conn->prepare("
                                        SELECT
                                            (SELECT COUNT(*) FROM borrowers) AS total_borrowers,
                                            ROUND((SELECT AVG(principal_amount) FROM loans WHERE status IN ('active','paid')), 2) AS avg_loan_size,
                                            ROUND((SELECT AVG(interest_rate) FROM loans WHERE status IN ('active','paid')), 3) AS avg_interest_rate,
                                            COALESCE((
                                              SELECT SUM(pa.amount_applied * (ls.expected_interest / NULLIF(ls.expected_total, 0)))
                                              FROM payment_allocations pa
                                              JOIN loan_schedules ls ON ls.schedule_id = pa.schedule_id
                                            ), 0) AS total_interest_earned,
                                            COALESCE((
                                              SELECT SUM(GREATEST(t.total_expected - t.total_paid, 0))
                                              FROM (
                                                SELECT l.loan_id,
                                                       COALESCE(SUM(ls.expected_total),0) AS total_expected,
                                                       COALESCE(SUM(pa.amount_applied),0) AS total_paid
                                                FROM loans l
                                                LEFT JOIN loan_schedules ls ON ls.loan_id = l.loan_id
                                                LEFT JOIN payment_allocations pa ON pa.schedule_id = ls.schedule_id
                                                WHERE l.status = 'active'
                                                GROUP BY l.loan_id
                                              ) t
                                            ), 0) AS net_outstanding;
                                            ");
    $stmt_loan_summary->execute();
    $loan_summary = $stmt_loan_summary->fetch(PDO::FETCH_ASSOC);
    //Upcoming payment schedule (top 5)
    $stmt_upcoming_schedule = $conn->prepare("
                                            SELECT
                                                  ls.schedule_id,
                                                  CONCAT(b.first_name, ' ', b.last_name) AS borrower_name,
                                                  ls.due_date,
                                                  ls.installment_number,
                                                  l.number_of_installments,
                                                  GREATEST(ls.expected_total - COALESCE(p.paid_so_far,0), 0) AS balance_to_fulfill,
                                                  CASE
                                                    WHEN ls.due_date < CURDATE() THEN 'overdue'
                                                    WHEN ls.due_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN 'due_soon'
                                                    ELSE 'active'
                                                  END AS schedule_status
                                                FROM loan_schedules ls
                                                JOIN loans l ON l.loan_id = ls.loan_id
                                                JOIN borrowers b ON b.borrower_id = l.borrower_id
                                                LEFT JOIN (
                                                  SELECT schedule_id, SUM(amount_applied) AS paid_so_far
                                                  FROM payment_allocations
                                                  GROUP BY schedule_id
                                                ) p ON p.schedule_id = ls.schedule_id
                                                WHERE l.status = 'active'
                                                  AND ls.status <> 'paid'
                                                  AND ls.due_date >= CURDATE()
                                                ORDER BY ls.due_date ASC
                                                LIMIT 5;
                                            ");
    $stmt_upcoming_schedule->execute();
    $upcoming_schedule = $stmt_upcoming_schedule->fetchAll(PDO::FETCH_ASSOC);
    // Recent payments (top 5)
    $stmt_recent_payments = $conn->prepare("
                                            SELECT
                                                    p.payment_id,
                                                    CONCAT(b.first_name, ' ', b.last_name) AS borrower_name,
                                                    p.amount_paid,
                                                    p.payment_date,
                                                    p.remaining_balance
                                                FROM payments p
                                                JOIN loans l ON l.loan_id = p.loan_id
                                                JOIN borrowers b ON b.borrower_id = l.borrower_id
                                                ORDER BY p.payment_date DESC, p.payment_id DESC
                                            LIMIT 5;
                                            ");
    $stmt_recent_payments->execute();
    $recent_payments = $stmt_recent_payments->fetchAll(PDO::FETCH_ASSOC);

    $stmt_reminders = $conn->prepare("
                                    SELECT
                                       CONCAT(b.first_name, ' ', b.last_name) AS borrower_name,
                                       GREATEST(ls.expected_total - COALESCE(p.paid_so_far, 0), 0) AS amount_due,
                                       DATEDIFF(CURDATE(), ls.due_date) AS days_overdue
                                    FROM loan_schedules ls
                                    JOIN loans l ON l.loan_id = ls.loan_id
                                    JOIN borrowers b ON b.borrower_id = l.borrower_id
                                    LEFT JOIN (
                                        SELECT schedule_id, SUM(amount_applied) AS paid_so_far
                                        FROM payment_allocations
                                        GROUP BY schedule_id
                                    ) p ON p.schedule_id = ls.schedule_id
                                    WHERE l.status = 'active'
                                      AND ls.status <> 'paid'
                                      AND ls.due_date < CURDATE()
                                      AND b.created_by = :user_id
                                    ORDER BY days_overdue DESC, ls.due_date ASC
                                    LIMIT 5;
                                    ");
    $stmt_reminders->execute([':user_id' => $userId]);
    $reminders = $stmt_reminders->fetchAll(PDO::FETCH_ASSOC);
    sendResponse('success', 'Dashboard fetched',[
        'cards' => $cards,
        'loan_counts' => $loan_counts,
        'loan_summary' => $loan_summary,
        'upcoming_schedule' => $upcoming_schedule,
        'recent_payments' => $recent_payments,
        'reminders' => $reminders
    ]);
}catch(PDOException $e){
    error_log($e->getMessage());
    sendResponse('error', 'An error occurred. Please try again later.', null, 500);
}
