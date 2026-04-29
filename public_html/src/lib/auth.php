<?php
// public_html/src/lib/auth.php
// Session-based auth for the single-user app. Schema carries user_id FKs so
// the same helpers will keep working when multi-user is enabled later.

declare(strict_types=1);

require_once __DIR__ . '/db.php';

function auth_login(string $email, string $password): bool {
    $stmt = db()->prepare('SELECT id, password_hash, display_name FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $row = $stmt->fetch();
    if (!$row || !password_verify($password, $row['password_hash'])) {
        return false;
    }
    session_regenerate_id(true);
    $_SESSION['user_id']      = (int)$row['id'];
    $_SESSION['display_name'] = $row['display_name'];
    return true;
}

function auth_logout(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function current_user_id(): ?int {
    return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
}

function is_logged_in(): bool {
    return current_user_id() !== null;
}

function require_login(): int {
    $uid = current_user_id();
    if ($uid === null) {
        $next = $_SERVER['REQUEST_URI'] ?? '/';
        header('Location: ' . url_for('/login') . '?next=' . urlencode($next));
        exit;
    }
    return $uid;
}
