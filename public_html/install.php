<?php
// public_html/install.php — one-shot web installer for shared cPanel hosts.
// Phase 0: collects DB credentials + admin login, writes config.php, optionally
// runs db/schema.sql and db/seeds.sql (Phase 1 artifacts) when they are present.
// After success it touches db/install.lock and refuses to re-run.

declare(strict_types=1);

define('APP_ROOT', __DIR__);
$configPath = APP_ROOT . '/config.php';
$lockPath   = APP_ROOT . '/db/install.lock';
$schemaPath = APP_ROOT . '/db/schema.sql';
$seedsPath  = APP_ROOT . '/db/seeds.sql';

if (is_file($configPath) && is_file($lockPath)) {
    http_response_code(403);
    echo install_layout('Installer locked',
        '<p>This installer has already run. Delete <code>public_html/db/install.lock</code> and <code>public_html/config.php</code> if you really want to reinstall.</p>'
        . '<p><a href="' . htmlspecialchars(install_url_for('/')) . '">Go to the app →</a></p>');
    exit;
}

$step    = $_POST['step']   ?? 'form';
$errors  = [];
$values  = [
    'db_host'    => $_POST['db_host']    ?? 'localhost',
    'db_name'    => $_POST['db_name']    ?? '',
    'db_port'    => $_POST['db_port']    ?? '3306',
    'db_user'    => $_POST['db_user']    ?? '',
    'db_pass'    => $_POST['db_pass']    ?? '',
    'admin_email'=> $_POST['admin_email']?? '',
    'admin_name' => $_POST['admin_name'] ?? 'me',
    'admin_pass' => $_POST['admin_pass'] ?? '',
    'anthropic_api_key' => $_POST['anthropic_api_key'] ?? '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 'install') {
    foreach (['db_host','db_name','db_user','db_port','admin_email','admin_pass'] as $req) {
        if ($values[$req] === '') {
            $errors[] = "Missing field: {$req}";
        }
    }
    if (!ctype_digit((string)$values['db_port']) || (int)$values['db_port'] < 1 || (int)$values['db_port'] > 65535) {
        $errors[] = 'Database port must be a number between 1 and 65535.';
    }
    if (!filter_var($values['admin_email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Admin email is not a valid email address.';
    }
    if (strlen($values['admin_pass']) < 8) {
        $errors[] = 'Admin password must be at least 8 characters.';
    }

    $pdo = null;
    if (!$errors) {
        try {
            $dsn = "mysql:host={$values['db_host']};port={$values['db_port']};dbname={$values['db_name']};charset=utf8mb4";
            $pdo = new PDO($dsn, $values['db_user'], $values['db_pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (Throwable $e) {
            $errors[] = 'DB connection failed: ' . $e->getMessage();
        }
    }

    if (!$errors && $pdo) {
        try {
            // Order matters: schema → admin user (id=1) → seeds (which reference user_id=1).
            $userExists = false;
            $ranSchema  = false;
            $ranSeeds   = false;

            if (is_file($schemaPath)) {
                run_sql_file($pdo, $schemaPath);
                $ranSchema = true;
            }

            $hash = password_hash($values['admin_pass'], PASSWORD_BCRYPT);
            try {
                $check = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
                $check->execute([$values['admin_email']]);
                $existing = $check->fetch();
                if ($existing) {
                    $upd = $pdo->prepare('UPDATE users SET password_hash = ?, display_name = ? WHERE id = ?');
                    $upd->execute([$hash, $values['admin_name'], $existing['id']]);
                    $userExists = true;
                } else {
                    $ins = $pdo->prepare('INSERT INTO users (id, email, password_hash, display_name, created_at) VALUES (1, ?, ?, ?, NOW())');
                    $ins->execute([$values['admin_email'], $hash, $values['admin_name']]);
                }
            } catch (Throwable $e) {
                // users table may not exist yet (Phase 0 with no schema.sql).
                // Capture and surface, but do not abort — config still gets written.
                $errors[] = 'Could not create admin user yet: ' . $e->getMessage();
            }

            if (is_file($seedsPath)) {
                run_sql_file($pdo, $seedsPath);
                $ranSeeds = true;
            }

            // Generate an app key + write config.php
            $appKey = bin2hex(random_bytes(32));
            $config = [
                'db' => [
                    'host'    => $values['db_host'],
                    'name'    => $values['db_name'],
                    'port'    => (int)$values['db_port'],
                    'user'    => $values['db_user'],
                    'pass'    => $values['db_pass'],
                    'charset' => 'utf8mb4',
                ],
                'app_key'           => $appKey,
                'session_name'      => 'rb_sess',
                'anthropic_api_key' => trim((string)$values['anthropic_api_key']),
            ];
            file_put_contents($configPath, "<?php\nreturn " . var_export($config, true) . ";\n");
            @chmod($configPath, 0640);

            // Lock the installer.
            @mkdir(dirname($lockPath), 0755, true);
            file_put_contents($lockPath, gmdate('c') . "\n");

            echo install_layout('Installed',
                '<p>✅ Recipe Book is installed.</p>'
                . '<ul>'
                . '<li>config.php written</li>'
                . ($ranSchema ? '<li>schema.sql applied</li>' : '<li><em>schema.sql not present — upload it and re-run</em></li>')
                . ($ranSeeds  ? '<li>seeds.sql applied (12 recipes + pantry staples)</li>' : '<li><em>seeds.sql not present yet</em></li>')
                . '<li>admin ' . htmlspecialchars($values['admin_email']) . ' ' . ($userExists ? 'updated' : 'created') . '</li>'
                . '</ul>'
                . '<p><strong>Next step:</strong> delete <code>install.php</code> from your server, then visit <a href="' . htmlspecialchars(install_url_for('/')) . '">the app</a>.</p>');
            exit;
        } catch (Throwable $e) {
            $errors[] = 'Install failed: ' . $e->getMessage();
        }
    }
}

// --- Render the form ---------------------------------------------------------

$body  = '<h1>Install Recipe Book</h1>';
$body .= '<p class="muted">Enter your cPanel MySQL details and the credentials you want to log in with. This page only runs once.</p>';

if ($errors) {
    $body .= '<div class="errors"><strong>Could not install:</strong><ul>';
    foreach ($errors as $err) $body .= '<li>' . htmlspecialchars($err) . '</li>';
    $body .= '</ul></div>';
}

$body .= '<form method="post" autocomplete="off">';
$body .= '<input type="hidden" name="step" value="install">';
$body .= '<fieldset><legend>Database (cPanel → MySQL Databases)</legend>';
$body .= field('Host',     'db_host', $values['db_host']);
$body .= field('DB name',  'db_name', $values['db_name']);
$body .= field('DB port',  'db_port', $values['db_port']);
$body .= field('DB user',  'db_user', $values['db_user']);
$body .= field('DB pass',  'db_pass', $values['db_pass'], 'password');
$body .= '</fieldset>';
$body .= '<fieldset><legend>Admin login</legend>';
$body .= field('Email',         'admin_email', $values['admin_email'], 'email');
$body .= field('Display name',  'admin_name',  $values['admin_name']);
$body .= field('Password (8+)', 'admin_pass',  '', 'password');
$body .= '</fieldset>';
$body .= '<fieldset><legend>AI features (optional)</legend>';
$body .= '<p class="muted" style="font-size:13px;">Add your Anthropic API key to enable Claude-powered recipe suggestions, bulk pantry import, and the in-app assistant. Leave blank to skip — you can add it later in <code>config.php</code>.</p>';
$body .= '<label>Anthropic API key<input type="text" name="anthropic_api_key" value="' . htmlspecialchars($values['anthropic_api_key']) . '" placeholder="sk-ant-…"></label>';
$body .= '</fieldset>';
$body .= '<button type="submit">Install</button>';
$body .= '</form>';

echo install_layout('Install Recipe Book', $body);

// --- helpers -----------------------------------------------------------------

function field(string $label, string $name, string $value, string $type = 'text'): string {
    $v = htmlspecialchars($value);
    return '<label>' . htmlspecialchars($label)
         . '<input type="' . $type . '" name="' . $name . '" value="' . $v . '" required></label>';
}

function run_sql_file(PDO $pdo, string $path): void {
    $sql = file_get_contents($path);
    if ($sql === false) throw new RuntimeException("Cannot read {$path}");
    // Execute as a single multi-statement batch.
    $pdo->exec($sql);
}

function install_layout(string $title, string $body): string {
    $t = htmlspecialchars($title);
    return <<<HTML
<!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{$t}</title>
<style>
  body { font: 15px/1.5 system-ui,-apple-system,Segoe UI,Roboto,sans-serif; max-width: 540px; margin: 40px auto; padding: 0 20px; color: #222; }
  h1 { font-size: 22px; margin-bottom: 4px; }
  .muted { color: #666; }
  fieldset { border: 1px solid #ddd; padding: 14px 16px; margin: 16px 0; border-radius: 8px; }
  legend { padding: 0 6px; font-weight: 600; }
  label { display: block; margin: 8px 0; font-size: 13px; }
  input { display: block; width: 100%; padding: 8px 10px; font: inherit; border: 1px solid #ccc; border-radius: 6px; margin-top: 4px; box-sizing: border-box; }
  button { background: #1a1a1a; color: #fff; border: 0; padding: 10px 16px; border-radius: 6px; cursor: pointer; font: inherit; font-weight: 600; }
  .errors { background: #fff1f1; border: 1px solid #f1c0c0; padding: 12px 16px; border-radius: 8px; color: #7a1e1e; margin: 16px 0; }
  code { background: #f4f4f4; padding: 1px 5px; border-radius: 3px; }
</style>
</head><body>
{$body}
</body></html>
HTML;
}


function install_url_for(string $path = '/'): string {
    $path = '/' . ltrim($path, '/');
    $script = $_SERVER['SCRIPT_NAME'] ?? '/install.php';
    $dir = str_replace('\\', '/', dirname($script));
    $base = ($dir === '/' || $dir === '.') ? '' : rtrim($dir, '/');
    return $base . $path;
}
