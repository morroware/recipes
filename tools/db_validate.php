<?php
// tools/db_validate.php — schema/seed sanity checks for a freshly installed
// Recipe Book DB. Run from SSH on the cPanel host:
//
//     php tools/db_validate.php
//
// Reads config.php from the sibling public_html/ directory (or accepts an
// override path as the first argument). Exits 0 on success, 1 on any failure.

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "This script is CLI-only.\n";
    exit(1);
}

$configPath = $argv[1] ?? __DIR__ . '/../public_html/config.php';
if (!is_file($configPath)) {
    fwrite(STDERR, "config.php not found at {$configPath}. Run install.php first.\n");
    exit(1);
}

$CONFIG = require $configPath;
$db     = $CONFIG['db'] ?? [];
$dsn    = sprintf('mysql:host=%s;dbname=%s;charset=%s',
    $db['host'] ?? 'localhost', $db['name'] ?? '', $db['charset'] ?? 'utf8mb4');

try {
    $pdo = new PDO($dsn, $db['user'] ?? '', $db['pass'] ?? '', [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (Throwable $e) {
    fwrite(STDERR, "DB connect failed: " . $e->getMessage() . "\n");
    exit(1);
}

$failures = [];
$checks   = 0;

function check(string $label, bool $ok, string $detail = ''): void {
    global $failures, $checks;
    $checks++;
    $mark = $ok ? '  OK' : 'FAIL';
    $line = "[{$mark}] {$label}";
    if ($detail !== '') $line .= " — {$detail}";
    echo $line . "\n";
    if (!$ok) $failures[] = $label;
}

function rowCount(PDO $pdo, string $table, string $where = '1'): int {
    $stmt = $pdo->query("SELECT COUNT(*) AS n FROM {$table} WHERE {$where}");
    return (int)$stmt->fetch()['n'];
}

echo "Recipe Book — DB validation\n";
echo "DSN: {$dsn}\n\n";

// 1. Required tables exist.
$expectedTables = [
    'users', 'recipes', 'recipe_tags', 'ingredients', 'steps',
    'pantry_items', 'shopping_items', 'meal_plan', 'user_settings',
];
$present = [];
foreach ($pdo->query("SHOW TABLES") as $row) {
    $present[] = array_values($row)[0];
}
foreach ($expectedTables as $t) {
    check("table {$t} exists", in_array($t, $present, true));
}

// 2. Charset/collation.
$stmt = $pdo->prepare(
    "SELECT DEFAULT_CHARACTER_SET_NAME AS cs, DEFAULT_COLLATION_NAME AS co
     FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?"
);
$stmt->execute([$db['name'] ?? '']);
$schema = $stmt->fetch();
check('schema is utf8mb4', ($schema['cs'] ?? '') === 'utf8mb4', $schema['cs'] ?? '');

// 3. Seed-row counts.
$adminCount = rowCount($pdo, 'users');
check('users >= 1 (admin seeded)', $adminCount >= 1, "rows={$adminCount}");

$recipeCount = rowCount($pdo, 'recipes');
check('recipes = 12 (prototype set)', $recipeCount === 12, "rows={$recipeCount}");

$pantryCount = rowCount($pdo, 'pantry_items');
check('pantry_items >= 30 (staples seeded)', $pantryCount >= 30, "rows={$pantryCount}");

$ingCount = rowCount($pdo, 'ingredients');
check('ingredients > 0', $ingCount > 0, "rows={$ingCount}");

$stepCount = rowCount($pdo, 'steps');
check('steps > 0', $stepCount > 0, "rows={$stepCount}");

$tagCount = rowCount($pdo, 'recipe_tags');
check('recipe_tags > 0', $tagCount > 0, "rows={$tagCount}");

$settingsCount = rowCount($pdo, 'user_settings');
check('user_settings >= 1', $settingsCount >= 1, "rows={$settingsCount}");

// 4. Per-recipe sanity: every recipe has at least one ingredient and one step.
$orphans = $pdo->query(
    "SELECT r.id, r.title
     FROM recipes r
     LEFT JOIN ingredients i ON i.recipe_id = r.id
     WHERE i.id IS NULL"
)->fetchAll();
check('every recipe has ingredients', empty($orphans),
    $orphans ? count($orphans) . ' recipes missing ingredients' : '');

$stepless = $pdo->query(
    "SELECT r.id, r.title
     FROM recipes r
     LEFT JOIN steps s ON s.recipe_id = r.id
     WHERE s.id IS NULL"
)->fetchAll();
check('every recipe has steps', empty($stepless),
    $stepless ? count($stepless) . ' recipes missing steps' : '');

// 5. FK integrity — orphans shouldn't be possible with FKs in place, but verify.
$badIng  = (int)$pdo->query(
    "SELECT COUNT(*) AS n FROM ingredients i
     LEFT JOIN recipes r ON r.id = i.recipe_id WHERE r.id IS NULL"
)->fetch()['n'];
check('no orphan ingredients', $badIng === 0, "orphans={$badIng}");

$badStep = (int)$pdo->query(
    "SELECT COUNT(*) AS n FROM steps s
     LEFT JOIN recipes r ON r.id = s.recipe_id WHERE r.id IS NULL"
)->fetch()['n'];
check('no orphan steps', $badStep === 0, "orphans={$badStep}");

$badTag  = (int)$pdo->query(
    "SELECT COUNT(*) AS n FROM recipe_tags t
     LEFT JOIN recipes r ON r.id = t.recipe_id WHERE r.id IS NULL"
)->fetch()['n'];
check('no orphan recipe_tags', $badTag === 0, "orphans={$badTag}");

$badPantry = (int)$pdo->query(
    "SELECT COUNT(*) AS n FROM pantry_items p
     LEFT JOIN users u ON u.id = p.user_id WHERE u.id IS NULL"
)->fetch()['n'];
check('no orphan pantry_items', $badPantry === 0, "orphans={$badPantry}");

// 6. Required indexes (exact names from schema.sql).
$expectedIndexes = [
    'recipes'        => ['uniq_recipes_user_slug', 'idx_recipes_user_title'],
    'ingredients'    => ['idx_ingredients_recipe_pos', 'idx_ingredients_recipe_name'],
    'recipe_tags'    => ['idx_recipe_tags_tag'],
    'pantry_items'   => ['uniq_pantry_user_key', 'idx_pantry_user_category'],
    'shopping_items' => ['idx_shopping_user_checked'],
    'meal_plan'      => ['uniq_plan_user_day'],
];
foreach ($expectedIndexes as $table => $names) {
    $stmt = $pdo->query("SHOW INDEX FROM {$table}");
    $have = array_unique(array_map(fn($r) => $r['Key_name'], $stmt->fetchAll()));
    foreach ($names as $idx) {
        check("index {$table}.{$idx}", in_array($idx, $have, true));
    }
}

// 7. UNIQUE-constraint sanity: pantry key_normalized has no duplicates per user.
$dupKeys = (int)$pdo->query(
    "SELECT COUNT(*) AS n FROM (
        SELECT user_id, key_normalized FROM pantry_items
        GROUP BY user_id, key_normalized HAVING COUNT(*) > 1
     ) d"
)->fetch()['n'];
check('pantry key_normalized unique per user', $dupKeys === 0, "dups={$dupKeys}");

echo "\n";
if ($failures) {
    echo count($failures) . " of {$checks} checks failed:\n";
    foreach ($failures as $f) echo "  - {$f}\n";
    exit(1);
}
echo "All {$checks} checks passed.\n";
exit(0);
