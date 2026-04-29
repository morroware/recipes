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
    header('Location: ' . url_for('/install.php'));
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
require_once SRC_PATH . '/lib/pantry_helpers.php';
require_once SRC_PATH . '/lib/version.php';
require_once SRC_PATH . '/lib/ai.php';

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
    ['GET',  '#^/login$#',                                   [AuthController::class, 'showLogin'],     false],
    ['POST', '#^/login$#',                                   [AuthController::class, 'postLogin'],     false],
    ['POST', '#^/logout$#',                                  [AuthController::class, 'postLogout'],    true],
    ['GET',  '#^/healthz$#',                                 'healthz',                                false],

    // Authenticated pages
    ['GET',  '#^/$#',                                        [RecipesController::class, 'browse'],     true],
    ['GET',  '#^/favorites$#',                               [RecipesController::class, 'favorites'],  true],
    ['GET',  '#^/recipes/(\d+)$#',                           [RecipesController::class, 'show'],       true],
    ['GET',  '#^/recipes/(\d+)/edit$#',                      [RecipesController::class, 'editPage'],   true],
    ['GET',  '#^/add$#',                                     [RecipesController::class, 'newPage'],    true],
    ['GET',  '#^/pantry$#',                                  [PantryController::class,  'page'],       true],
    ['GET',  '#^/shopping$#',                                [ShoppingController::class, 'page'],      true],
    ['GET',  '#^/plan$#',                                    [PlanController::class,     'page'],      true],
    ['GET',  '#^/print$#',                                   [PrintController::class,    'page'],      true],
    ['GET',  '#^/chat$#',                                    [AiController::class,       'page'],      true],

    // JSON API
    ['POST', '#^/api/recipes$#',                             [RecipesController::class, 'apiCreate'],     true],
    ['PUT',  '#^/api/recipes/(\d+)$#',                       [RecipesController::class, 'apiUpdate'],     true],
    ['DELETE','#^/api/recipes/(\d+)$#',                      [RecipesController::class, 'apiDelete'],     true],
    ['POST', '#^/api/recipes/(\d+)/favorite$#',              [RecipesController::class, 'toggleFavorite'], true],
    ['PUT',  '#^/api/recipes/(\d+)/notes$#',                 [RecipesController::class, 'updateNotes'],    true],
    ['GET',  '#^/api/settings$#',                            [SettingsController::class, 'apiGet'],     true],
    ['PUT',  '#^/api/settings$#',                            [SettingsController::class, 'apiUpdate'],  true],
    ['GET',  '#^/api/recipes/suggestions$#',                 [PantryController::class,  'apiSuggestions'], true],
    ['GET',  '#^/api/recipes/by-ingredients$#',              [PantryController::class,  'apiByIngredients'], true],

    ['GET',    '#^/api/pantry$#',                            [PantryController::class, 'apiList'],         true],
    ['POST',   '#^/api/pantry$#',                            [PantryController::class, 'apiCreate'],       true],
    ['GET',    '#^/api/pantry/categorize$#',                 [PantryController::class, 'apiCategorize'],   true],
    ['POST',   '#^/api/pantry/(\d+)/restock$#',              [PantryController::class, 'apiRestock'],      true],
    ['PATCH',  '#^/api/pantry/(\d+)$#',                      [PantryController::class, 'apiUpdate'],       true],
    ['DELETE', '#^/api/pantry/(\d+)$#',                      [PantryController::class, 'apiDelete'],       true],

    ['GET',    '#^/api/shopping$#',                          [ShoppingController::class, 'apiList'],            true],
    ['POST',   '#^/api/shopping$#',                          [ShoppingController::class, 'apiCreate'],          true],
    ['DELETE', '#^/api/shopping$#',                          [ShoppingController::class, 'apiClearAll'],        true],
    ['POST',   '#^/api/shopping/move-to-pantry$#',           [ShoppingController::class, 'apiMoveToPantry'],    true],
    ['POST',   '#^/api/shopping/from-recipe/(\d+)$#',        [ShoppingController::class, 'apiAddFromRecipe'],   true],
    ['PATCH',  '#^/api/shopping/(\d+)$#',                    [ShoppingController::class, 'apiUpdate'],          true],
    ['DELETE', '#^/api/shopping/(\d+)$#',                    [ShoppingController::class, 'apiDelete'],          true],

    ['GET',    '#^/api/plan$#',                              [PlanController::class, 'apiList'],              true],
    ['DELETE', '#^/api/plan$#',                              [PlanController::class, 'apiClear'],             true],
    ['POST',   '#^/api/plan/build-shopping-list$#',          [PlanController::class, 'apiBuildShopping'],     true],
    ['PUT',    '#^/api/plan/(Mon|Tue|Wed|Thu|Fri|Sat|Sun)$#',[PlanController::class, 'apiSetDay'],            true],

    // AI (Claude) endpoints
    ['GET',  '#^/api/ai/status$#',                           [AiController::class, 'apiStatus'],              true],
    ['POST', '#^/api/ai/chat$#',                             [AiController::class, 'apiChat'],                true],
    ['POST', '#^/api/ai/parse-ingredients$#',                [AiController::class, 'apiParseIngredients'],    true],
    ['POST', '#^/api/ai/parse-recipe$#',                     [AiController::class, 'apiParseRecipe'],         true],
    ['POST', '#^/api/ai/recipe-suggestions$#',               [AiController::class, 'apiRecipeSuggestions'],   true],
    ['POST', '#^/api/ai/recipe-from-idea$#',                 [AiController::class, 'apiRecipeFromIdea'],      true],
    ['POST', '#^/api/ai/categorize$#',                       [AiController::class, 'apiCategorize'],          true],
    ['POST', '#^/api/ai/plan-week$#',                        [AiController::class, 'apiPlanWeek'],            true],
    ['POST', '#^/api/ai/extract-memories$#',                 [AiController::class, 'apiExtractMemories'],     true],

    // AI memory (preferences the assistant remembers)
    ['GET',    '#^/api/ai/memories$#',                       [AiController::class, 'apiMemoriesList'],        true],
    ['POST',   '#^/api/ai/memories$#',                       [AiController::class, 'apiMemoriesCreate'],      true],
    ['DELETE', '#^/api/ai/memories$#',                       [AiController::class, 'apiMemoriesClear'],       true],
    ['PATCH',  '#^/api/ai/memories/(\d+)$#',                 [AiController::class, 'apiMemoriesUpdate'],      true],
    ['DELETE', '#^/api/ai/memories/(\d+)$#',                 [AiController::class, 'apiMemoriesDelete'],      true],

    // AI conversations (persistent chat history)
    ['GET',    '#^/api/ai/conversations$#',                  [AiController::class, 'apiConvList'],            true],
    ['POST',   '#^/api/ai/conversations$#',                  [AiController::class, 'apiConvCreate'],          true],
    ['GET',    '#^/api/ai/conversations/(\d+)$#',            [AiController::class, 'apiConvShow'],            true],
    ['PATCH',  '#^/api/ai/conversations/(\d+)$#',            [AiController::class, 'apiConvRename'],          true],
    ['DELETE', '#^/api/ai/conversations/(\d+)$#',            [AiController::class, 'apiConvDelete'],          true],

    // Cooking history (recipes the user has actually made)
    ['GET',    '#^/api/cooking-log$#',                       [AiController::class, 'apiCookingList'],         true],
    ['POST',   '#^/api/cooking-log$#',                       [AiController::class, 'apiCookingCreate'],       true],
    ['DELETE', '#^/api/cooking-log/(\d+)$#',                 [AiController::class, 'apiCookingDelete'],       true],
];

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$reqPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$base = app_base_path();
if ($base !== '' && str_starts_with($reqPath, $base)) {
    $reqPath = substr($reqPath, strlen($base)) ?: '/';
}
$path = '/' . trim($reqPath, '/');
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
    json_ok([
        'logged_in' => is_logged_in(),
        'app'       => version_info(),
    ]);
}
