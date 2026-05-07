<?php 
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');

require_once '../../config/database.php';
require_once '../../helpers/response.php';

if($_SERVER['REQUEST_METHOD'] !== 'POST'){
    sendResponse('error', 'Method not Allowed', null, 405);
    exit;
} 
$body = json_decode(file_get_contents('php://input'), true);

if(empty($body['username']) || empty($body['password'])){
    sendResponse('error', 'Username and password are required', null, 400);
}
$db = new Database();
$conn = $db->connect();
try{
    $stmt = $conn->prepare("SELECT * FROM users WHERE username = :username");
    $stmt->execute([':username' => $body['username']]);
    $user = $stmt->fetch();
    if(!$user || !password_verify($body['password'], $user['password_hash'])){
        sendResponse('error', 'Invalid email or password', null, 401);
    }
    $responseData = [
        'user_id'  => $user['user_id'],
        'username' => $user['username'],
    ];
    if(!empty($body['remember_me']) && $body['remember_me'] === true){
        $selector = bin2hex(random_bytes(16));
        $validator = bin2hex(random_bytes(32));
        $validatorHash = password_hash($validator, PASSWORD_BCRYPT);
        $storedToken = $selector . ':' . $validatorHash;
        $expires_at = date('Y-m-d H:i:s', strtotime('+30 days'));
        $tokenStmt = $conn->prepare("INSERT INTO auth_tokens (user_id, token, expires_at) VALUES (:user_id, :token, :expires_at)");
        $tokenStmt->execute([
            ':user_id' => $user['user_id'],
            ':token' => $storedToken,
            ':expires_at' => $expires_at
        ]);
        $rawToken = $selector . ':' . $validator;
        $responseData['token']      = $rawToken;
        $responseData['expires_at'] = $expires_at;
    }
    sendResponse('success', 'Login successful', $responseData);
}catch(PDOException $e){
    sendResponse('error', 'Login Failed: ' . $e->getMessage(), null, 500);
}
