<?php
// public_html/src/lib/db.php
// PDO singleton. ERRMODE_EXCEPTION, FETCH_ASSOC, EMULATE_PREPARES=false.
// Every query that reaches this PDO must use prepared statements.

declare(strict_types=1);

function db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    /** @var array $CONFIG populated by index.php */
    global $CONFIG;
    $c = $CONFIG['db'] ?? [];

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $c['host']    ?? 'localhost',
        (int)($c['port'] ?? 3306),
        $c['name']    ?? '',
        $c['charset'] ?? 'utf8mb4'
    );
    $pdo = new PDO($dsn, $c['user'] ?? '', $c['pass'] ?? '', [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    return $pdo;
}
