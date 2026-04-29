<?php
// public_html/src/lib/response.php
// Tiny HTTP helpers + JSON envelope: { ok: bool, data?: any, error?: string }.

declare(strict_types=1);

function json_ok($data = null, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_err(string $error, int $status = 400, $details = null): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    $payload = ['ok' => false, 'error' => $error];
    if ($details !== null) $payload['details'] = $details;
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function redirect(string $to, int $status = 302): void {
    // Auto-honor the app base path for relative ("/foo") targets so controllers
    // can write redirect('/login') without worrying about subdir installs.
    if ($to !== '' && $to[0] === '/' && function_exists('app_base_path')) {
        $base = app_base_path();
        if ($base !== '' && !str_starts_with($to, $base . '/') && $to !== $base) {
            $to = $base . $to;
        }
    }
    header('Location: ' . $to, true, $status);
    exit;
}
