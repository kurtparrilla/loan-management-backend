<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/auth.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] != 'GET') {
    sendResponse('error', 'Method not allowed', null, 405);
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

