<?php
header ('Access-Control-Allow-Origin: *'); // PLEASE CHANGE THIS SHIT LATER BROO HAHHA
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header ('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once '../../config/database.php';
require_once '../../helpers/response.php';

if($_SERVER['REQUEST_METHOD'] !== 'POST'){
    sendResponse('error', 'Method not Allowed', null, 405);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if(empty($body['username']) ||  empty($body['email']) || empty($body['password'])){
    sendResponse('error', 'All fields are required', null, 400);
}
$db = new Database();
$conn = $db->connect();
try{
    $check = $conn->prepare("SELECT user_id FROM users WHERE email = :email");
    $check->execute([':email' => $body['email']]);
    if($check->fetch()){
        sendResponse('error', 'Email already registered', null, 400);
    }
    $checkUsername = $conn->prepare("SELECT user_id FROM users WHERE username = :username");
    $checkUsername->execute([':username' => $body['username']]);
    if($checkUsername->fetch()){
        sendResponse('error', 'Username already registered', null, 400);
    }
    $stmt = $conn->prepare("INSERT INTO users (username,email,password_hash) VALUES (:username, :email, :password_hash)");
    $stmt->execute([
        ':username' => $body['username'],
        ':email' => $body['email'],
        ':password_hash' => password_hash($body['password'], PASSWORD_BCRYPT)
    ]);
    sendResponse('success', 'Account created successfully', null, 201);
}
catch(PDOException $e){
    sendResponse('error', 'Registration failed: ' . $e->getMessage(), null, 500);
}

