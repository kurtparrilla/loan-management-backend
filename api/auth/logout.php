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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse('error', 'Method not allowed', null, 405);
    exit;
}

$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (!preg_match('/Bearer\s+(.+)/i', $authHeader, $matches)) {
    sendResponse('error', 'No token provided', null, 401);
    exit;
}
$rawToken = trim($matches[1]);

if (strpos($rawToken, ':') === false) {
    sendResponse('error', 'Invalid token format', null, 400);
    exit;
}
[$selector] = explode(':', $rawToken, 2);

$db   = new Database();
$conn = $db->connect();
$stmt = $conn->prepare("DELETE FROM auth_tokens WHERE token LIKE :selector_prefix");
$stmt->execute([':selector_prefix' => $selector . ':%']);

sendResponse('success', 'Logged out successfully');
