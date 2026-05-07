<?php
function requireAuth(){
    if(session_status() !== PHP_SESSION_ACTIVE){
        session_start();
    }

    if(!empty($_SESSION['user_id'])){
        return;
    }

    $cookieToken = $_COOKIE['remember_me'] ?? $_COOKIE['remember_token'] ?? null;
    if(!empty($cookieToken) && strpos($cookieToken, ':') !== false){
        require_once __DIR__ . '/../config/database.php';
        $db = new Database();
        $conn = $db->connect();

        [$selector, $validator] = explode(':', $cookieToken, 2);

        $stmt = $conn->prepare("
            SELECT t.token, u.user_id, u.username
            FROM auth_tokens t
            JOIN users u ON t.user_id = u.user_id
            WHERE t.token LIKE :selector_prefix
              AND t.expires_at > NOW()
            LIMIT 1
        ");
        $stmt->execute([':selector_prefix' => $selector . ':%']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if($row && strpos($row['token'], ':') !== false){
            [, $storedValidatorHash] = explode(':', $row['token'], 2);
            if(password_verify($validator, $storedValidatorHash)){
                $_SESSION['user_id'] = $row['user_id'];
                $_SESSION['username'] = $row['username'];
                return;
            }
        }
    }

    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized. Please log in.']);
    exit();
}
