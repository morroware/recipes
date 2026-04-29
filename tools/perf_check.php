<?php
// tools/perf_check.php — EXPLAIN the hottest queries against the live DB and
// flag full table scans / missing-index access types. Run from SSH on the
// cPanel host:
//
//     php tools/perf_check.php
//
// Reads config.php from the sibling public_html/ directory. Exits 0 when no
// query reports a flagged access pattern, 1 otherwise. Read-only — uses
// EXPLAIN, never executes mutating SQL.
//
// Note on small datasets: the seeded DB is tiny, so MySQL may legitimately
// pick a full scan (type=ALL) over an index for some queries because the
// table fits in memory. We flag and report — interpret in context.

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "This script is CLI-only.\n";
    exit(1);
}

$configPath = $argv[1] ?? __DIR__ . '/../public_html/config.php';
if (!is_file($configPath)) {
    fwrite(STDERR, "config.php not found at {$configPath}.\n");
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

// Pick a real user_id and recipe_id from the DB to keep EXPLAIN realistic.
$uid = (int)($pdo->query('SELECT MIN(id) AS id FROM users')->fetch()['id'] ?? 1);
$rid = (int)($pdo->query('SELECT MIN(id) AS id FROM recipes')->fetch()['id'] ?? 1);

$queries = [
    'recipes-list-default' => [
        'sql'    => 'SELECT r.* FROM recipes r WHERE r.user_id = ? ORDER BY r.title ASC',
        'params' => [$uid],
    ],
    'recipes-list-fav-only' => [
        'sql'    => 'SELECT r.* FROM recipes r WHERE r.user_id = ? AND r.is_favorite = 1 ORDER BY r.title ASC',
        'params' => [$uid],
    ],
    'recipes-list-by-tag' => [
        'sql'    => 'SELECT r.* FROM recipes r
                     INNER JOIN recipe_tags rt ON rt.recipe_id = r.id AND rt.tag = ?
                     WHERE r.user_id = ? ORDER BY r.title ASC',
        'params' => ['weeknight', $uid],
    ],
    'recipe-detail' => [
        'sql'    => 'SELECT * FROM recipes WHERE id = ? AND user_id = ?',
        'params' => [$rid, $uid],
    ],
    'ingredients-for-recipe' => [
        'sql'    => 'SELECT id, position, qty, unit, name, aisle FROM ingredients
                     WHERE recipe_id = ? ORDER BY position ASC, id ASC',
        'params' => [$rid],
    ],
    'ingredients-bulk-by-recipe' => [
        'sql'    => 'SELECT recipe_id, name FROM ingredients WHERE recipe_id IN (?,?,?)
                     ORDER BY recipe_id, position',
        'params' => [$rid, $rid + 1, $rid + 2],
    ],
    'pantry-list' => [
        'sql'    => 'SELECT * FROM pantry_items WHERE user_id = ? ORDER BY name ASC',
        'params' => [$uid],
    ],
    'pantry-in-stock-keys' => [
        'sql'    => 'SELECT key_normalized FROM pantry_items WHERE user_id = ? AND in_stock = 1',
        'params' => [$uid],
    ],
    'pantry-find-by-key' => [
        'sql'    => 'SELECT id FROM pantry_items WHERE user_id = ? AND key_normalized = ? LIMIT 1',
        'params' => [$uid, 'salt'],
    ],
    'shopping-list' => [
        'sql'    => 'SELECT * FROM shopping_items WHERE user_id = ? ORDER BY checked ASC, position ASC, id ASC',
        'params' => [$uid],
    ],
    'meal-plan-week' => [
        'sql'    => 'SELECT day, recipe_id FROM meal_plan WHERE user_id = ?',
        'params' => [$uid],
    ],
    'recipe-tags-bulk' => [
        'sql'    => 'SELECT recipe_id, tag FROM recipe_tags WHERE recipe_id IN (?,?,?,?,?) ORDER BY tag',
        'params' => [$rid, $rid + 1, $rid + 2, $rid + 3, $rid + 4],
    ],
];

echo "Recipe Book — perf check\n";
echo "DB: {$dsn} (user_id={$uid}, recipe_id={$rid})\n\n";

$flags    = 0;
$ranTotal = 0;

// Access types we always want to see for owner-scoped queries. Anything below
// is flagged. From fastest to slowest: system, const, eq_ref, ref, range,
// index, ALL.
//   ALL    — full table scan (worst).
//   index  — full index scan (better than ALL but still scans every row).
$badTypes = ['ALL', 'index'];

foreach ($queries as $label => $q) {
    $ranTotal++;
    try {
        $stmt = $pdo->prepare('EXPLAIN ' . $q['sql']);
        $stmt->execute($q['params']);
        $rows = $stmt->fetchAll();
    } catch (Throwable $e) {
        printf("[FAIL] %s — EXPLAIN error: %s\n", $label, $e->getMessage());
        $flags++;
        continue;
    }

    $worst = null;
    foreach ($rows as $r) {
        $type = $r['type'] ?? '';
        if ($worst === null) { $worst = $r; continue; }
        // Heuristic: a row with type=ALL is the worst we care about.
        if ($type === 'ALL') $worst = $r;
        elseif ($type === 'index' && ($worst['type'] ?? '') !== 'ALL') $worst = $r;
    }
    $type = $worst['type'] ?? '';
    $key  = $worst['key']  ?? null;
    $rowsEst = $worst['rows'] ?? '?';
    $extra   = $worst['Extra'] ?? '';

    $bad = in_array($type, $badTypes, true);
    $tag = $bad ? 'WARN' : '  OK';
    if ($bad) $flags++;

    printf("[%s] %-30s type=%-7s key=%-30s rows=%s%s\n",
        $tag,
        $label,
        $type,
        $key === null ? '(none)' : $key,
        $rowsEst,
        $extra ? "  Extra=\"{$extra}\"" : ''
    );

    // Print every row when we flagged for triage.
    if ($bad && count($rows) > 1) {
        foreach ($rows as $i => $r) {
            printf("        row#%d table=%s type=%s key=%s rows=%s\n",
                $i, $r['table'] ?? '?', $r['type'] ?? '?', $r['key'] ?? '(none)', $r['rows'] ?? '?');
        }
    }
}

echo "\n";
if ($flags > 0) {
    echo "{$flags} of {$ranTotal} queries flagged. Note: with the seeded ~12-recipe\n";
    echo "dataset MySQL may legitimately prefer a scan; re-run after real data lands.\n";
    exit(1);
}
echo "All {$ranTotal} queries used an indexed lookup.\n";
exit(0);
