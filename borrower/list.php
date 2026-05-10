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
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/auth.php';

if($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse('error', 'Method not allowed', null, 405);
    exit;
}

$db = new Database();
$conn = $db->connect();
$userId = requireAuthUserId($conn);

$limit  = isset($_GET['limit']) ? (int)$_GET['limit'] : 5;
$search = trim($_GET['search'] ?? '');

if ($limit < 1) $limit = 5;
if ($limit > 20) $limit = 20;

try {
    $sql = "
        SELECT
            borrower_id,
            CONCAT(first_name, ' ', last_name) AS borrower_name,
            phone
        FROM borrowers
        WHERE created_by = :user_id
    ";

    $params = [
        ':user_id' => $userId
    ];

    if ($search !== '') {
        $sql .= " AND (CONCAT(first_name, ' ', last_name) LIKE :search OR phone LIKE :search)";
        $params[':search'] = '%' . $search . '%';
    }

    $sql .= " ORDER BY first_name ASC, last_name ASC LIMIT :limit";

    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':user_id', $params[':user_id'], PDO::PARAM_INT);

    if (isset($params[':search'])) {
        $stmt->bindValue(':search', $params[':search'], PDO::PARAM_STR);
    }

    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    sendResponse('success', 'Borrowers fetched', [
        'items' => $items
    ]);
} catch (PDOException $e) {
    error_log($e->getMessage());
    sendResponse('error', 'An error occurred. Please try again later.', null, 500);
}

