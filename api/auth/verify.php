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
