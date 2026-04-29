<?php
// public_html/index.php — front controller.
// Boots config, starts session, dispatches to controllers.

declare(strict_types=1);

define('APP_ROOT', __DIR__);
define('SRC_PATH', APP_ROOT . '/src');
define('DB_PATH',  APP_ROOT . '/db');

// 1) Bounce to the installer until config.php exists.
$configPath = APP_ROOT . '/config.php';
if (!is_file($configPath)) {
    header('Location: /install.php');
    exit;
}
$CONFIG = require $configPath;

// 2) Harden session cookies before starting the session.
session_name($CONFIG['session_name'] ?? 'rb_sess');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'domain'   => '',
    'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

// 3) Shared libs.
require_once SRC_PATH . '/lib/db.php';
require_once SRC_PATH . '/lib/auth.php';
require_once SRC_PATH . '/lib/csrf.php';
require_once SRC_PATH . '/lib/response.php';
require_once SRC_PATH . '/lib/view.php';
require_once SRC_PATH . '/lib/constants.php';

// 4) Tiny autoloader for src/controllers/*.php and src/models/*.php.
spl_autoload_register(static function (string $class): void {
    foreach (['controllers', 'models'] as $dir) {
        $f = SRC_PATH . '/' . $dir . '/' . $class . '.php';
        if (is_file($f)) { require_once $f; return; }
    }
});

// 5) Route table. Each row: [METHOD, pattern, handler, requiresLogin].
$routes = [
    // Public
    ['GET',  '#^/login$#',                    [AuthController::class, 'showLogin'], false],
    ['POST', '#^/login$#',                    [AuthController::class, 'postLogin'], false],
    ['POST', '#^/logout$#',                   [AuthController::class, 'postLogout'], true],
    ['GET',  '#^/healthz$#',                  'healthz', false],

    // Authenticated home (placeholder until Phase 3 lands the Browse page).
    ['GET',  '#^/$#',                         'home_placeholder', true],
];

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path   = '/' . trim($path, '/');
if ($path === '/index.php') $path = '/';

foreach ($routes as [$m, $pattern, $handler, $needsLogin]) {
    if ($m !== $method) continue;
    if (!preg_match($pattern, $path, $params)) continue;

    if ($needsLogin) require_login();

    array_shift($params);
    if (is_array($handler)) {
        [$class, $action] = $handler;
        (new $class())->$action(...$params);
    } else {
        $handler(...$params);
    }
    exit;
}

// Fallback 404
http_response_code(404);
if (str_starts_with($path, '/api/')) {
    json_err('not_found', 404);
}
$title = 'Not found';
$body_view = SRC_PATH . '/views/_404_body.php';
require SRC_PATH . '/views/layout.php';
exit;

// ---- inline handlers -------------------------------------------------------

function home_placeholder(): void {
    render('_home_placeholder.php', ['title' => 'my little cookbook']);
}

function healthz(): void {
    json_ok(['phase' => 2, 'logged_in' => is_logged_in()]);
}
