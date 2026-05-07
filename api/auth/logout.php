<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');
require_once '../../config/database.php';
require_once '../../helpers/response.php';
session_start();
session_destroy();
if(!empty($_COOKIE['remember_me'])){
    $cookieToken = $_COOKIE['remember_me'];
    $db   = new Database();
    $conn = $db->connect();
    if(strpos($cookieToken, ':') !== false){
        [$selector] = explode(':', $cookieToken, 2);
        $stmt = $conn->prepare("DELETE FROM auth_tokens WHERE token LIKE :selector_prefix");
        $stmt->execute([':selector_prefix' => $selector . ':%']);
    }
    setcookie('remember_me' , '', time() - 3600, '/');
}
sendResponse('success', 'Logged out successfully');
