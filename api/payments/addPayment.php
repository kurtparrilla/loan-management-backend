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
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once '../../config/database.php';
require_once '../../helpers/response.php';
require_once '../../helpers/auth.php';

if ($_SERVER['REQUEST_METHOD'] != 'POST') {
    sendResponse('error', 'Method not allowed', null, 405);
}

$db     = new Database();
$conn   = $db->connect();
$userId = requireAuthUserId($conn);

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    sendResponse('error', 'Invalid JSON body', null, 400);
}

if (empty($body['loan_id']) || empty($body['amount_paid']) || empty($body['payment_date'])) {
    sendResponse('error', 'loan_id, amount_paid and payment_date are required', null, 400);
}

$loanId          = (int)$body['loan_id'];
$amountPaid      = (float)$body['amount_paid'];
$paymentDate     = $body['payment_date'];
$paymentMethod   = $body['payment_method']   ?? null;
$referenceNumber = $body['reference_number'] ?? null;

if ($amountPaid <= 0) {
    sendResponse('error', 'Amount paid must be greater than 0', null, 400);
}

try {
    $conn->beginTransaction();

    $stmtLoan = $conn->prepare("
        SELECT loan_id, principal_amount, interest_rate, number_of_installments, status 
        FROM loans 
        WHERE loan_id = :loan_id
    ");
    $stmtLoan->execute([':loan_id' => $loanId]);
    $loan = $stmtLoan->fetch();

    if (!$loan) {
        $conn->rollBack();
        sendResponse('error', 'Loan not found', null, 404);
    }

    if ($loan['status'] !== 'active') {
        $conn->rollBack();
        sendResponse('error', 'Loan is not active. Status: ' . $loan['status'], null, 400);
    }

    $stmtLastPayment = $conn->prepare("
        SELECT remaining_balance FROM payments 
        WHERE loan_id = :loan_id 
        ORDER BY payment_id DESC 
        LIMIT 1
    ");
    $stmtLastPayment->execute([':loan_id' => $loanId]);
    $lastPayment = $stmtLastPayment->fetch();

    if ($lastPayment) {
        $remainingBalance = (float)$lastPayment['remaining_balance'];
    } else {
        $principalPerInstallment = round($loan['principal_amount'] / $loan['number_of_installments'], 2);
        $interestPerInstallment  = round($loan['principal_amount'] * ($loan['interest_rate'] / 100), 2);
        $totalPerInstallment     = $principalPerInstallment + $interestPerInstallment;
        $remainingBalance        = round($totalPerInstallment * $loan['number_of_installments'], 2);
    }

    $stmtSchedules = $conn->prepare("
        SELECT schedule_id, installment_number, expected_total, expected_principal, expected_interest, status 
        FROM loan_schedules 
        WHERE loan_id = :loan_id 
          AND status != 'paid' 
        ORDER BY installment_number ASC
    ");
    $stmtSchedules->execute([':loan_id' => $loanId]);
    $unpaidSchedules = $stmtSchedules->fetchAll();

    if (empty($unpaidSchedules)) {
        $conn->rollBack();
        sendResponse('error', 'No unpaid installments found', null, 400);
    }

    $amountRemaining      = $amountPaid;
    $installmentsAffected = [];
    $pendingAllocations   = [];

    foreach ($unpaidSchedules as $schedule) {
        if ($amountRemaining <= 0) break;

        $scheduleId    = $schedule['schedule_id'];
        $expectedTotal = (float)$schedule['expected_total'];

        $stmtAlreadyPaid = $conn->prepare("
            SELECT COALESCE(SUM(amount_applied), 0) as paid_so_far
            FROM payment_allocations 
            WHERE schedule_id = :schedule_id
        "); 
        $stmtAlreadyPaid->execute([':schedule_id' => $scheduleId]);
        $alreadyPaid = (float)$stmtAlreadyPaid->fetch()['paid_so_far'];
        $stillOwed   = round($expectedTotal - $alreadyPaid, 2);

        if ($amountRemaining >= $stillOwed) {
            $amountApplied   = $stillOwed;
            $amountRemaining = round($amountRemaining - $stillOwed, 2);
            $newStatus       = 'paid';
        } else {
            $amountApplied   = $amountRemaining;
            $amountRemaining = 0;
            $newStatus       = 'partially_paid';
        }

        $stmtUpdateSchedule = $conn->prepare("
            UPDATE loan_schedules SET status = :status WHERE schedule_id = :schedule_id
        ");
        $stmtUpdateSchedule->execute([
            ':status'      => $newStatus,
            ':schedule_id' => $scheduleId
        ]);

        $installmentsAffected[] = [
            'installment_number' => $schedule['installment_number'],
            'amount_applied'     => $amountApplied,
            'status'             => $newStatus
        ];

        $pendingAllocations[] = [
            'schedule_id'    => $scheduleId,
            'amount_applied' => $amountApplied
        ];
        
    }

    $newRemainingBalance = round($remainingBalance - $amountPaid, 2);
    if ($newRemainingBalance < 0) $newRemainingBalance = 0;

    $stmtPayment = $conn->prepare("
        INSERT INTO payments (loan_id, amount_paid, payment_date, payment_method, reference_number, remaining_balance)
        VALUES (:loan_id, :amount_paid, :payment_date, :payment_method, :reference_number, :remaining_balance)
    ");
    $stmtPayment->execute([
        ':loan_id'           => $loanId,
        ':amount_paid'       => $amountPaid,
        ':payment_date'      => $paymentDate,
        ':payment_method'    => $paymentMethod,
        ':reference_number'  => $referenceNumber,
        ':remaining_balance' => $newRemainingBalance
    ]);
    $paymentId = (int)$conn->lastInsertId();

    $stmtAllocation = $conn->prepare("
        INSERT INTO payment_allocations (payment_id, schedule_id, amount_applied)
        VALUES (:payment_id, :schedule_id, :amount_applied)
    ");
    foreach ($pendingAllocations as $allocation) {
        $stmtAllocation->execute([
            ':payment_id'     => $paymentId,
            ':schedule_id'    => $allocation['schedule_id'],
            ':amount_applied' => $allocation['amount_applied']
        ]);
    }

    $stmtCheckPaid = $conn->prepare("
        SELECT COUNT(*) as unpaid_count FROM loan_schedules 
        WHERE loan_id = :loan_id AND status != 'paid'
    ");
    $stmtCheckPaid->execute([':loan_id' => $loanId]);
    $unpaidCount = (int)$stmtCheckPaid->fetch()['unpaid_count'];

    $loanStatus = 'active';
    if ($unpaidCount === 0) {
        $loanStatus = 'paid';
        $stmtUpdateLoan = $conn->prepare("UPDATE loans SET status = 'paid' WHERE loan_id = :loan_id");
        $stmtUpdateLoan->execute([':loan_id' => $loanId]);
    }

    $conn->commit();

    sendResponse('success', 'Payment recorded successfully', [
        'payment_id'            => $paymentId,
        'loan_id'               => $loanId,
        'amount_paid'           => $amountPaid,
        'remaining_balance'     => $newRemainingBalance,
        'installments_affected' => $installmentsAffected,
        'loan_status'           => $loanStatus
    ], 201);

} catch (PDOException $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    sendResponse('error', 'Payment failed: ' . $e->getMessage(), null, 500);
}
