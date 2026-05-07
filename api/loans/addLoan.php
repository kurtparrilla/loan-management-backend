<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');

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
    $stmtLoan = $conn->prepare("INSERT INTO loans (borrower_id, principal_amount, interest_rate, payment_frequency, number_of_installments, start_date, end_date) VALUES (:borrower_id, :principal_amount, :interest_rate, :payment_frequency, :number_of_installments, :start_date, :end_date)");
    $stmtLoan->execute([
        ':borrower_id' => $borrowerId,
        ':principal_amount' => $l['principal_amount'] ?? null,
        ':interest_rate' => $l['interest_rate'] ?? 0,
        ':payment_frequency' => $l['payment_frequency'] ?? null,
        ':number_of_installments' => $l['number_of_installments'] ?? null,
        ':start_date' => $l['start_date'] ?? null,
        ':end_date' => $l['end_date'] ?? null,
    ]);
    $loanId = (int)$conn->lastInsertId();
    $conn->commit();
    sendResponse('success', 'Loan created successfully',[
        'mode' => $mode,
        'borrower_id' => $borrowerId,
        'loan_id' => $loanId
    ], 201);
}catch(PDOException $e){
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    sendResponse('error', 'Create failed: ' . $e->getMessage(), null, 500);
}