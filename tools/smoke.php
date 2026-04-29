<?php
// tools/smoke.php — end-to-end HTTP smoke test for the Recipe Book API.
// Logs in as the admin user, walks every JSON endpoint via cURL, and reports
// pass/fail. Designed to be run from SSH on a cPanel host after install.
//
//     php tools/smoke.php https://yourdomain.com admin@example.com 'p4ssword'
//
// Exits 0 on full success, 1 on any failed assertion.
//
// What it covers (no destructive ops on seeded data):
//   - login + CSRF round-trip
//   - GET /api/settings + PUT /api/settings (no-op round-trip)
//   - GET /api/pantry, POST a smoke item, PATCH it, restock it, DELETE it
//   - GET /api/recipes/suggestions
//   - GET /api/recipes/by-ingredients?names[]=tomato
//   - GET /api/shopping, POST + DELETE a smoke item
//   - GET /api/plan, PUT /api/plan/Mon (with rollback)
//   - toggle favorite on recipe 1 (and toggle back)

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "This script is CLI-only.\n";
    exit(1);
}
if (!extension_loaded('curl')) {
    fwrite(STDERR, "PHP curl extension is required.\n");
    exit(1);
}

[$_, $base, $email, $password] = array_pad($argv, 4, null);
if (!$base || !$email || !$password) {
    fwrite(STDERR, "usage: php tools/smoke.php <base-url> <email> <password>\n");
    exit(1);
}
$base = rtrim($base, '/');

$cookieJar = tempnam(sys_get_temp_dir(), 'rb_smoke_');
register_shutdown_function(static function () use ($cookieJar) {
    if (is_file($cookieJar)) @unlink($cookieJar);
});

$failures = [];
$checks   = 0;

function assert_that(string $label, bool $ok, string $detail = ''): void {
    global $failures, $checks;
    $checks++;
    $mark = $ok ? '  OK' : 'FAIL';
    $line = "[{$mark}] {$label}";
    if ($detail !== '') $line .= " — {$detail}";
    echo $line . "\n";
    if (!$ok) $failures[] = $label . ($detail ? " ({$detail})" : '');
}

/**
 * @return array{status:int, body:string, headers:string}
 */
function req(string $method, string $url, array $opts = []): array {
    global $cookieJar;
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADER         => true,
        CURLOPT_COOKIEJAR      => $cookieJar,
        CURLOPT_COOKIEFILE     => $cookieJar,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $headers = $opts['headers'] ?? [];
    if (!empty($opts['json'])) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($opts['json']));
    } elseif (!empty($opts['form'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($opts['form']));
    }
    if ($headers) curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return ['status' => 0, 'body' => '', 'headers' => '', 'error' => $err];
    }
    $status   = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $hsize    = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headStr  = substr($raw, 0, $hsize);
    $body     = substr($raw, $hsize);
    curl_close($ch);
    return ['status' => $status, 'body' => $body, 'headers' => $headStr];
}

function decode_json(string $body): ?array {
    $j = json_decode($body, true);
    return is_array($j) ? $j : null;
}

function scrape_csrf(string $html): ?string {
    if (preg_match('/<meta\s+name="csrf-token"\s+content="([^"]+)"/', $html, $m)) {
        return $m[1];
    }
    if (preg_match('/name="_csrf"\s+value="([^"]+)"/', $html, $m)) {
        return $m[1];
    }
    return null;
}

echo "Recipe Book — HTTP smoke test\n";
echo "Base: {$base}\nUser: {$email}\n\n";

// 1. Pull /login to seat session + grab CSRF.
$r = req('GET', "{$base}/login");
assert_that('GET /login returns 200', $r['status'] === 200, "status={$r['status']}");
$csrf = scrape_csrf($r['body']);
assert_that('login page has CSRF token', $csrf !== null);
if ($csrf === null) { echo "\nCannot continue without CSRF token.\n"; exit(1); }

// 2. POST /login.
$r = req('POST', "{$base}/login", [
    'form' => ['_csrf' => $csrf, 'email' => $email, 'password' => $password, 'next' => '/'],
]);
$loc = '';
if (preg_match('/^Location:\s*(.+)$/im', $r['headers'], $m)) $loc = trim($m[1]);
$loggedIn = $r['status'] === 302 && (rtrim($loc, " \t/") === '' || $loc === '/');
assert_that('POST /login redirects to /', $loggedIn, "status={$r['status']} location={$loc}");
if (!$loggedIn) { echo "\nLogin failed; aborting.\n"; exit(1); }

// 3. Re-pull a page to refresh CSRF token (sessions regenerate on login).
$r = req('GET', "{$base}/");
assert_that('GET / returns 200 when logged in', $r['status'] === 200, "status={$r['status']}");
$csrf = scrape_csrf($r['body']) ?: $csrf;

$auth = ["X-CSRF-Token: {$csrf}"];

// 4. Settings round-trip.
$r = req('GET', "{$base}/api/settings");
$j = decode_json($r['body']);
assert_that('GET /api/settings ok', $r['status'] === 200 && ($j['ok'] ?? false));
$settings = $j['data']['settings'] ?? [];
assert_that('settings has theme key', isset($settings['theme']));

if ($settings) {
    $r = req('PUT', "{$base}/api/settings", ['headers' => $auth, 'json' => $settings]);
    $j = decode_json($r['body']);
    assert_that('PUT /api/settings round-trip ok',
        $r['status'] === 200 && ($j['ok'] ?? false), "status={$r['status']}");
}

// 5. Pantry list.
$r = req('GET', "{$base}/api/pantry");
$j = decode_json($r['body']);
$pantryItems = $j['data']['items'] ?? [];
assert_that('GET /api/pantry returns items',
    $r['status'] === 200 && is_array($pantryItems) && count($pantryItems) > 0,
    'count=' . count($pantryItems));

// 6. Pantry create → patch → restock → delete (all on a smoke-only key).
$smokeName = 'smoke_' . bin2hex(random_bytes(3));
$r = req('POST', "{$base}/api/pantry", [
    'headers' => $auth,
    'json'    => ['name' => $smokeName, 'in_stock' => true, 'category' => 'Other'],
]);
$j = decode_json($r['body']);
$smokeId = (int)($j['data']['item']['id'] ?? 0);
assert_that('POST /api/pantry creates row',
    $r['status'] === 200 && $smokeId > 0, "status={$r['status']}");

if ($smokeId > 0) {
    $r = req('PATCH', "{$base}/api/pantry/{$smokeId}", [
        'headers' => $auth, 'json' => ['in_stock' => false],
    ]);
    $j = decode_json($r['body']);
    assert_that('PATCH /api/pantry/{id} ok',
        $r['status'] === 200 && ($j['ok'] ?? false));

    $r = req('POST', "{$base}/api/pantry/{$smokeId}/restock", ['headers' => $auth]);
    $j = decode_json($r['body']);
    $restockedItem = $j['data']['item'] ?? null;
    assert_that('POST /api/pantry/{id}/restock sets in_stock=1 and bumps count',
        $r['status'] === 200
        && ($restockedItem['in_stock'] ?? 0) == 1
        && ((int)($restockedItem['purchase_count'] ?? 0)) >= 1);

    $r = req('DELETE', "{$base}/api/pantry/{$smokeId}", ['headers' => $auth]);
    $j = decode_json($r['body']);
    assert_that('DELETE /api/pantry/{id} ok',
        $r['status'] === 200 && ($j['ok'] ?? false));
}

// 7. Pantry-derived endpoints.
$r = req('GET', "{$base}/api/pantry/categorize?name=onion");
$j = decode_json($r['body']);
assert_that('GET /api/pantry/categorize returns Produce for onion',
    $r['status'] === 200 && (($j['data']['category'] ?? '') === 'Produce'),
    'got=' . ($j['data']['category'] ?? '?'));

$r = req('GET', "{$base}/api/recipes/suggestions");
$j = decode_json($r['body']);
assert_that('GET /api/recipes/suggestions ok',
    $r['status'] === 200 && is_array($j['data']['suggestions'] ?? null));

$r = req('GET', "{$base}/api/recipes/by-ingredients?names%5B%5D=tomato");
$j = decode_json($r['body']);
assert_that('GET /api/recipes/by-ingredients ok',
    $r['status'] === 200 && is_array($j['data']['recipes'] ?? null));

// 8. Shopping list create + delete.
$r = req('GET', "{$base}/api/shopping");
$j = decode_json($r['body']);
assert_that('GET /api/shopping ok',
    $r['status'] === 200 && is_array($j['data']['items'] ?? null));

$r = req('POST', "{$base}/api/shopping", [
    'headers' => $auth,
    'json'    => ['name' => 'smoke_item_' . bin2hex(random_bytes(2)), 'qty' => 1, 'unit' => '', 'source' => 'smoke'],
]);
$j = decode_json($r['body']);
$shopId = (int)($j['data']['item']['id'] ?? 0);
assert_that('POST /api/shopping creates row',
    $r['status'] === 200 && $shopId > 0);

if ($shopId > 0) {
    $r = req('DELETE', "{$base}/api/shopping/{$shopId}", ['headers' => $auth]);
    $j = decode_json($r['body']);
    assert_that('DELETE /api/shopping/{id} ok',
        $r['status'] === 200 && ($j['ok'] ?? false));
}

// 9. Plan: read, set Mon, then restore previous.
$r = req('GET', "{$base}/api/plan");
$j = decode_json($r['body']);
$plan = $j['data']['plan'] ?? null;
assert_that('GET /api/plan ok',
    $r['status'] === 200 && is_array($plan));

$prevMon = is_array($plan) ? ($plan['Mon'] ?? null) : null;
$r = req('PUT', "{$base}/api/plan/Mon", [
    'headers' => $auth, 'json' => ['recipe_id' => 1],
]);
$j = decode_json($r['body']);
assert_that('PUT /api/plan/Mon assigns recipe',
    $r['status'] === 200 && ($j['data']['recipe_id'] ?? null) == 1);

$restoreId = is_array($prevMon) ? ($prevMon['recipe_id'] ?? null) : null;
$r = req('PUT', "{$base}/api/plan/Mon", [
    'headers' => $auth, 'json' => ['recipe_id' => $restoreId],
]);
assert_that('PUT /api/plan/Mon restores prior value',
    $r['status'] === 200);

// 10. Favorite toggle (twice, to leave state untouched).
$r = req('POST', "{$base}/api/recipes/1/favorite", ['headers' => $auth]);
$j = decode_json($r['body']);
$favA = $j['data']['is_favorite'] ?? null;
assert_that('POST /api/recipes/1/favorite toggles', $r['status'] === 200 && $favA !== null);

$r = req('POST', "{$base}/api/recipes/1/favorite", ['headers' => $auth]);
$j = decode_json($r['body']);
$favB = $j['data']['is_favorite'] ?? null;
assert_that('toggling again returns the opposite of previous',
    $favA !== null && $favB !== null && $favA !== $favB,
    "first={$favA} second={$favB}");

// 11. CSRF rejection on a mutating call without the header.
$r = req('POST', "{$base}/api/shopping", [
    'json' => ['name' => 'no_csrf_should_fail'],
]);
assert_that('POST without CSRF token is rejected',
    $r['status'] === 419 || $r['status'] === 403,
    "status={$r['status']}");

echo "\n";
if ($failures) {
    echo count($failures) . " of {$checks} checks failed:\n";
    foreach ($failures as $f) echo "  - {$f}\n";
    exit(1);
}
echo "All {$checks} checks passed.\n";
exit(0);
