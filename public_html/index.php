<?php
// public_html/index.php — front controller.
// Phase 0: boot config, gate on installer, dispatch to a minimal router.

declare(strict_types=1);

define('APP_ROOT', __DIR__);
define('SRC_PATH', APP_ROOT . '/src');
define('DB_PATH',  APP_ROOT . '/db');

// 1) If we have not been installed yet, bounce to the installer.
$configPath = APP_ROOT . '/config.php';
if (!is_file($configPath)) {
    header('Location: /install.php');
    exit;
}

$CONFIG = require $configPath;

// 2) Harden session cookies before starting the session.
$cookieParams = [
    'lifetime' => 0,
    'path'     => '/',
    'domain'   => '',
    'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'httponly' => true,
    'samesite' => 'Lax',
];
session_name($CONFIG['session_name'] ?? 'rb_sess');
session_set_cookie_params($cookieParams);
session_start();

// 3) Parse method + path.
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path   = '/' . trim($path, '/');
if ($path === '/index.php') $path = '/';

// 4) Route table. Phase 2 will move this to a richer controller layer; for now
//    Phase 0 only needs a placeholder home and a 404.
$routes = [
    ['GET', '/',        'home_placeholder'],
    ['GET', '/healthz', 'healthz'],
];

foreach ($routes as [$m, $pattern, $handler]) {
    if ($m !== $method) continue;
    if ($pattern === $path) {
        $handler($CONFIG);
        exit;
    }
}

// Fallback: 404. JSON for /api/*, HTML otherwise.
http_response_code(404);
if (str_starts_with($path, '/api/')) {
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'not_found']);
} else {
    require SRC_PATH . '/views/_404.php';
}
exit;

// ---- handlers (will move into controllers in Phase 2) ----

function home_placeholder(array $CONFIG): void {
    $title = 'my little cookbook';
    $body_view = SRC_PATH . '/views/_home_placeholder.php';
    require SRC_PATH . '/views/layout.php';
}

function healthz(array $CONFIG): void {
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'phase' => 0]);
}
