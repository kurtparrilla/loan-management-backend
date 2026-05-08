<?php
header('Access-Control-Allow-Origin: *');
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

if($_SERVER['REQUEST_METHOD'] !== 'POST'){
    sendResponse('error', 'Method not Allowed', null, 405);
    exit;
}

$db = new Database();
$conn = $db->connect();
$userId = requireAuthUserId($conn);

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
     sendResponse('error', 'Invalid JSON body', null, 400);
 }
$mode = $body['mode'] ?? null;
if($mode !== 'existing' && $mode !== 'new'){
    sendResponse('error', 'Invalid mode. Use existing or new', null, 400);
}
if ($mode === 'existing') {
    if (empty($body['borrower_id']) || empty($body['loan']) || !is_array($body['loan'])) {
        sendResponse('error', 'borrower_id and loan are required for existing mode', null, 400);
    }
 }
if ($mode === 'new') {
    if (empty($body['borrower']) || !is_array($body['borrower']) || empty($body['loan']) || !is_array($body['loan'])) {
        sendResponse('error', 'borrower and loan are required for new mode', null, 400);
    }
}

try{
    $conn->beginTransaction();
    $borrowerId = null;
    if($mode == 'existing'){
        $borrowerId = (int)$body['borrower_id'];
        $check = $conn->prepare("SELECT borrower_id FROM borrowers WHERE borrower_id = :id");
        $check->execute([':id' => $borrowerId]);
        if(!$check->fetch()){
            $conn->rollBack();
            sendResponse('error', 'Borrower not found', null, 404);
        }
    }
    if($mode == 'new'){
        $b = $body['borrower'];
        $stmtBorrower = $conn->prepare("INSERT INTO borrowers (first_name, last_name, email, phone, address, created_by) VALUES (:first_name, :last_name, :email, :phone, :address, :created_by)");
        $stmtBorrower->execute([
            ':first_name' => $b['first_name'] ?? null,
            ':last_name' => $b['last_name'] ?? null,
            ':email' => $b['email'] ?? null,
            ':phone' => $b['phone'] ?? null,
            ':address' => $b['address'] ?? null,
            ':created_by' => $userId,
        ]);
        $borrowerId = (int)$conn->lastInsertId();
    }
    $l = $body['loan'];
    $stmtLoan = $conn->prepare("INSERT INTO loans (borrower_id, principal_amount, interest_rate, payment_frequency, number_of_installments, start_date) VALUES (:borrower_id, :principal_amount, :interest_rate, :payment_frequency, :number_of_installments, :start_date)");
    $stmtLoan->execute([
        ':borrower_id' => $borrowerId,
        ':principal_amount' => $l['principal_amount'] ?? null,
        ':interest_rate' => $l['interest_rate'] ?? 0,
        ':payment_frequency' => $l['payment_frequency'] ?? null,
        ':number_of_installments' => $l['number_of_installments'] ?? null,
        ':start_date' => $l['start_date'] ?? null
    ]);
    $loanId = (int)$conn->lastInsertId();


    $principal = (float)$l['principal_amount'];
    $interestRate = (float)($l['interest_rate'] ?? 0);
    $installments = (int)($l['number_of_installments'] ?? 0);
    $frequency    = $l['payment_frequency'] ?? null;
    $startDate = new DateTime($l['start_date']);

    if (empty($installments) || $installments <= 0) {
        sendResponse('error', 'Installments must be greater than 0', null, 400);
        exit;
    }   

    $principalPerInstallment = round($principal / $installments, 2);
    $interestPerInstallment = round($principal * ($interestRate / 100), 2);

    $stmtSchedule = $conn->prepare("
                                    INSERT INTO loan_schedules (loan_id, installment_number, due_date, expected_principal, expected_interest, status)
                                    VALUES (:loan_id, :installment_number, :due_date, :expected_principal, :expected_interest, 'pending')");
    $lastDueDate = null;

    for ($i = 1; $i <= $installments; $i++) {
        $dueDate = clone $startDate;

        match($frequency) {
            'weekly'    => $dueDate->modify("+{$i} week"),
            'biweekly'  => $dueDate->modify("+" . ($i * 2) . " week"),
            'monthly'   => $dueDate->modify("+{$i} month"),
            'quarterly' => $dueDate->modify("+" . ($i * 3) . " month"),
            'yearly'    => $dueDate->modify("+{$i} year"),
            'lump_sum'  => $dueDate->modify("+{$i} month"),
            default     => $dueDate->modify("+{$i} month"),
        };

        $stmtSchedule->execute([
            ':loan_id'            => $loanId,
            ':installment_number' => $i,
            ':due_date'           => $dueDate->format('Y-m-d'),
            ':expected_principal' => $principalPerInstallment,
            ':expected_interest'  => $interestPerInstallment
        ]);

        $lastDueDate = $dueDate->format('Y-m-d');
    }
    $stmtUpdateLoan = $conn->prepare("UPDATE loans SET end_date = :end_date WHERE loan_id = :loan_id");
    $stmtUpdateLoan->execute([
        ':end_date' => $lastDueDate,
        ':loan_id'  => $loanId
    ]);
    $conn->commit();
    sendResponse('success', 'Loan created successfully', [
        'mode'                   => $mode,
        'borrower_id'            => $borrowerId,
        'loan_id'                => $loanId,
        'end_date'               => $lastDueDate,
        'installments_generated' => $installments
    ], 201);
}catch(PDOException $e){
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    sendResponse('error', 'Create failed: ' . $e->getMessage(), null, 500);
}

