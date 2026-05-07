<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');

require_once '../../config/database.php';
require_once '../../helpers/response.php';
require_once '../../helpers/auth.php';

$db     = new Database();
$conn   = $db->connect();
$userId = requireAuthUserId($conn);

sendResponse('success', 'Token is valid', ['user_id' => $userId]);