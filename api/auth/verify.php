<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once '../../config/database.php';
require_once '../../helpers/response.php';
require_once '../../helpers/auth.php';

$db     = new Database();
$conn   = $db->connect();
$userId = requireAuthUserId($conn);

sendResponse('success', 'Token is valid', ['user_id' => $userId]);
