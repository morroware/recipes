<?php
// public_html/migrate.php — web-based DB migration runner for shared cPanel
// hosts where SSH/`mysql` CLI isn't available.
//
// Walks `db/migrations/*.sql` in alphabetical order, applies any file that
// hasn't already been recorded in `schema_migrations`, and reports pass/fail
// per file. Designed to be safe to re-run: skipped files are no-ops, and the
// included migrations all use `CREATE TABLE IF NOT EXISTS` so legacy installs
// that ran them manually before the tracker existed get adopted on first run
// without applying anything twice.
//
// Auth: a logged-in session OR `?key=<app_key>` from config.php. The key
// fallback exists so a future migration that requires re-login (or breaks
// auth temporarily) can still be applied without SSH.
//
//   Examples:
//     https://yourdomain.com/migrate.php                — must be signed in
//     https://yourdomain.com/migrate.php?key=AB12…CD34  — bearer-token mode

declare(strict_types=1);

define('APP_ROOT', __DIR__);
define('SRC_PATH', APP_ROOT . '/src');

$configPath = APP_ROOT . '/config.php';
if (!is_file($configPath)) {
    http_response_code(503);
    echo migrate_layout('Config missing',
        '<p>No <code>config.php</code> yet. Run <a href="' . htmlspecialchars(migrate_url_for('/install.php')) . '">install.php</a> first.</p>');
    exit;
}
$CONFIG = require $configPath;

// Mirror index.php's session hardening so the logged-in check picks up an
// existing rb_sess cookie if the user is already signed in.
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

require_once SRC_PATH . '/lib/db.php';

// ---- Auth gate -------------------------------------------------------------

$loggedIn = isset($_SESSION['user_id']);
$key      = (string)($_GET['key'] ?? $_POST['key'] ?? '');
$appKey   = (string)($CONFIG['app_key'] ?? '');
$keyOk    = $appKey !== '' && $key !== '' && hash_equals($appKey, $key);

if (!$loggedIn && !$keyOk) {
    http_response_code(403);
    $msg  = '<p>You must be signed in, or pass <code>?key=&lt;app_key&gt;</code> from <code>config.php</code>, to run migrations.</p>';
    $msg .= '<p><a href="' . htmlspecialchars(migrate_url_for('/login')) . '">Sign in →</a></p>';
    echo migrate_layout('Sign in required', $msg);
    exit;
}

// ---- Discover migrations + bootstrap tracker -------------------------------

$migrationsDir = APP_ROOT . '/db/migrations';
if (!is_dir($migrationsDir)) {
    echo migrate_layout('No migrations',
        '<p>No <code>db/migrations</code> directory found. Nothing to apply.</p>');
    exit;
}

try {
    $pdo = db();
} catch (Throwable $e) {
    http_response_code(500);
    echo migrate_layout('Database error',
        '<p class="bad">Could not connect to the database: ' . htmlspecialchars($e->getMessage()) . '</p>');
    exit;
}

// `schema_migrations` is created by migrate.php itself so there's no
// chicken-and-egg problem with installing it via a migration file.
$pdo->exec(
    'CREATE TABLE IF NOT EXISTS schema_migrations (
       name        VARCHAR(190) NOT NULL,
       applied_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
       PRIMARY KEY (name)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);

$applied = [];
foreach ($pdo->query('SELECT name FROM schema_migrations')->fetchAll() as $row) {
    $applied[(string)$row['name']] = true;
}

$files = glob($migrationsDir . '/*.sql') ?: [];
sort($files); // lexicographic order matches the NNN_name.sql convention

// ---- Run on POST -----------------------------------------------------------

$report = [];
$didRun = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'run')) {
    $didRun = true;
    foreach ($files as $path) {
        $name = basename($path);
        if (isset($applied[$name])) {
            $report[] = ['name' => $name, 'status' => 'skipped', 'note' => 'already applied'];
            continue;
        }
        $sql = (string)@file_get_contents($path);
        if ($sql === '') {
            $report[] = ['name' => $name, 'status' => 'failed', 'note' => 'empty or unreadable'];
            break; // halt so the user can fix the file before later ones run
        }
        try {
            // Multi-statement batch — same pattern install.php uses.
            $pdo->exec($sql);
            $ins = $pdo->prepare('INSERT INTO schema_migrations (name) VALUES (?)');
            $ins->execute([$name]);
            $applied[$name] = true;
            $report[] = ['name' => $name, 'status' => 'applied', 'note' => ''];
        } catch (Throwable $e) {
            $report[] = ['name' => $name, 'status' => 'failed', 'note' => $e->getMessage()];
            break; // stop on first failure to avoid layering on a half-applied schema
        }
    }
}

// ---- Render ----------------------------------------------------------------

$pendingCount = 0;
foreach ($files as $path) {
    if (!isset($applied[basename($path)])) $pendingCount++;
}

ob_start();
?>
<h1>Recipe Book — Database migrations</h1>
<p class="muted">Applies any new files in <code>db/migrations/</code> that haven't run on this database yet. Safe to re-run: completed migrations are skipped.</p>

<?php if ($report): ?>
  <h2>Run report</h2>
  <ul class="report">
    <?php foreach ($report as $r): ?>
      <li class="r-<?= htmlspecialchars($r['status']) ?>">
        <strong><?= htmlspecialchars($r['name']) ?></strong>
        — <?= htmlspecialchars($r['status']) ?>
        <?php if ($r['note'] !== ''): ?>
          <em>(<?= htmlspecialchars($r['note']) ?>)</em>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>
  <?php
    $failed = array_filter($report, fn($r) => $r['status'] === 'failed');
    if ($failed): ?>
    <p class="bad">A migration failed. Fix the underlying issue (see the message above) and click <strong>Retry pending</strong> below — already-applied files will be skipped.</p>
  <?php endif; ?>
<?php endif; ?>

<h2>Status</h2>
<table>
  <thead><tr><th>Migration</th><th>Status</th></tr></thead>
  <tbody>
    <?php foreach ($files as $path): $name = basename($path); ?>
      <tr>
        <td><code><?= htmlspecialchars($name) ?></code></td>
        <td><?= isset($applied[$name]) ? '<span class="ok-tag">✓ applied</span>' : '<span class="pending-tag">○ pending</span>' ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$files): ?>
      <tr><td colspan="2" class="muted">No <code>*.sql</code> files in <code>db/migrations/</code>.</td></tr>
    <?php endif; ?>
  </tbody>
</table>

<?php if ($pendingCount > 0): ?>
  <form method="post" style="margin-top: 20px;">
    <input type="hidden" name="action" value="run">
    <?php if ($keyOk && !$loggedIn): ?>
      <input type="hidden" name="key" value="<?= htmlspecialchars($key) ?>">
    <?php endif; ?>
    <button type="submit"><?= $didRun ? 'Retry pending' : 'Apply' ?> <?= (int)$pendingCount ?> pending migration<?= $pendingCount === 1 ? '' : 's' ?></button>
  </form>
<?php else: ?>
  <p class="ok"><strong>✓ Database is up to date.</strong> No pending migrations.</p>
<?php endif; ?>

<p style="margin-top: 28px; font-size: 13px; color: #666;">
  <a href="<?= htmlspecialchars(migrate_url_for('/')) ?>">← Back to the app</a>
  <?php if ($keyOk && !$loggedIn): ?>
    &nbsp;·&nbsp; signed in via app key
  <?php elseif ($loggedIn): ?>
    &nbsp;·&nbsp; signed in as user #<?= (int)($_SESSION['user_id'] ?? 0) ?>
  <?php endif; ?>
</p>
<?php
$body = ob_get_clean();
echo migrate_layout('Migrations', $body);

// ---- Helpers ---------------------------------------------------------------

function migrate_url_for(string $path = '/'): string {
    $path = '/' . ltrim($path, '/');
    $script = $_SERVER['SCRIPT_NAME'] ?? '/migrate.php';
    $dir = str_replace('\\', '/', dirname($script));
    $base = ($dir === '/' || $dir === '.') ? '' : rtrim($dir, '/');
    return $base . $path;
}

function migrate_layout(string $title, string $body): string {
    $t = htmlspecialchars($title);
    return <<<HTML
<!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{$t}</title>
<style>
  body { font: 15px/1.5 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;
         max-width: 640px; margin: 40px auto; padding: 0 20px; color: #222; }
  h1 { font-size: 22px; margin-bottom: 4px; }
  h2 { font-size: 16px; margin-top: 24px; }
  .muted { color: #666; }
  table { width: 100%; border-collapse: collapse; margin-top: 12px; }
  th, td { text-align: left; padding: 8px 10px; border-bottom: 1px solid #eee; font-size: 14px; }
  th { font-size: 12px; color: #666; text-transform: uppercase; letter-spacing: 0.04em; }
  ul.report { list-style: none; padding: 0; margin: 12px 0; }
  ul.report li { padding: 8px 12px; margin-bottom: 6px; border-radius: 6px;
                 border: 1px solid #eee; font-size: 14px; }
  ul.report li.r-applied { background: #eafbef; border-color: #c5ebd0; }
  ul.report li.r-skipped { background: #f6f6f6; }
  ul.report li.r-failed  { background: #fff1f1; border-color: #f1c0c0; color: #7a1e1e; }
  .ok      { background: #eafbef; border: 1px solid #c5ebd0; padding: 12px 16px;
             border-radius: 8px; }
  .bad     { background: #fff1f1; border: 1px solid #f1c0c0; padding: 12px 16px;
             border-radius: 8px; color: #7a1e1e; }
  .ok-tag      { color: #18723b; font-weight: 600; }
  .pending-tag { color: #8a6c00; }
  button { background: #1a1a1a; color: #fff; border: 0; padding: 10px 18px;
           border-radius: 6px; cursor: pointer; font: inherit; font-weight: 600; }
  button:hover { background: #000; }
  code { background: #f4f4f4; padding: 1px 5px; border-radius: 3px; font-size: 13px; }
</style>
</head><body>
{$body}
</body></html>
HTML;
}
