<?php
// public_html/src/lib/csrf.php
// Per-session CSRF token. Required on every non-GET request.
// HTML forms use csrf_field(); JS fetches read <meta name="csrf-token">
// and send it as the X-CSRF-Token header.

declare(strict_types=1);

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string {
    $t = htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8');
    return '<input type="hidden" name="_csrf" value="' . $t . '">';
}

function csrf_check(): bool {
    $expected = $_SESSION['csrf_token'] ?? null;
    if ($expected === null) return false;
    $supplied = $_POST['_csrf']
        ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    return is_string($supplied) && hash_equals($expected, $supplied);
}

function csrf_require(): void {
    if (!csrf_check()) {
        http_response_code(419);
        if (str_starts_with($_SERVER['REQUEST_URI'] ?? '/', '/api/')) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'csrf_invalid']);
        } else {
            echo 'CSRF token mismatch. Reload the page and try again.';
        }
        exit;
    }
}
