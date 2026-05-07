<?php
require_once __DIR__ . '/response.php';
function requireAuthUserId(PDO $conn): int
{
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/Bearer\s+(.+)/i', $authHeader, $m)) {
        sendResponse('error', 'Missing bearer token', null, 401);
    }
    $rawToken = trim($m[1]); 
    [$selector, $validator] = array_pad(explode(':', $rawToken, 2), 2, null);
    if (empty($selector) || empty($validator)) {
        sendResponse('error', 'Invalid token format', null, 401);
    }
    $stmt = $conn->prepare("
        SELECT token_id, user_id, token, expires_at
        FROM auth_tokens
        WHERE token LIKE :selector_pattern
        ORDER BY token_id DESC
        LIMIT 1
    ");
    $stmt->execute([':selector_pattern' => $selector . ':%']);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        sendResponse('error', 'Token not found', null, 401);
    }
    if (strtotime($row['expires_at']) < time()) {
        sendResponse('error', 'Token expired', null, 401);
    }
    [, $storedValidatorHash] = array_pad(explode(':', $row['token'], 2), 2, null);
    if (empty($storedValidatorHash) || !password_verify($validator, $storedValidatorHash)) {
        sendResponse('error', 'Invalid token', null, 401);
    }
    return (int)$row['user_id'];
}