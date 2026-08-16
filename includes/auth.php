<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$configuredBaseUrl = getenv('LABSPHERE_BASE_URL');
define('BASE_URL', rtrim($configuredBaseUrl !== false && $configuredBaseUrl !== '' ? $configuredBaseUrl : '/LabSphere', '/'));

function e($v) {
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

function user() {
    return $_SESSION['user'] ?? null;
}

function require_login() {
    if (!user()) {
        header('Location: ' . BASE_URL . '/index.php');
        exit;
    }
}

function require_role(array $roles) {
    require_login();
    if (!in_array(user()['role'], $roles, true)) {
        http_response_code(403);
        exit('Access denied');
    }
}

function csrf_token() {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function verify_csrf() {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(419);
        exit('Invalid request token');
    }
}

function flash($type, $message) {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function take_flash() {
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $flash;
}

function audit(PDO $pdo, string $action, string $module, string $details = '') {
    if (!user()) {
        return;
    }

    $statement = $pdo->prepare(
        'INSERT INTO audit_logs(user_id, action, module, details) VALUES(?, ?, ?, ?)'
    );
    $statement->execute([user()['id'], $action, $module, $details]);
}
