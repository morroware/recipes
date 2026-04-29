<?php
// public_html/src/lib/view.php
// Plain-PHP template helpers. No Twig, no Blade.

declare(strict_types=1);

require_once __DIR__ . '/db.php';

/** htmlspecialchars shorthand for use inside templates: <?= h($value) ?> */
function h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/**
 * Render a layout template that includes a body partial.
 *
 * Layout expects: $title (string), $body_view (path), $tweaks (array), and any
 * extra vars caller provides via $vars.
 */
function render(string $body_view, array $vars = []): void {
    $body_view = SRC_PATH . '/views/' . ltrim($body_view, '/');
    extract($vars, EXTR_SKIP);

    if (!isset($tweaks)) {
        $tweaks = current_user_id() ? load_user_tweaks(current_user_id()) : default_tweaks();
    }

    require SRC_PATH . '/views/layout.php';
}

function default_tweaks(): array {
    return [
        'density'       => 'cozy',
        'theme'         => 'rainbow',
        'mode'          => 'light',
        'fontPair'      => 'default',
        'radius'        => 'default',
        'stickerRotate' => 'on',
        'dotGrid'       => 'on',
    ];
}

function load_user_tweaks(int $user_id): array {
    try {
        $stmt = db()->prepare('SELECT density, theme, mode, font_pair, radius, sticker_rotate, dot_grid FROM user_settings WHERE user_id = ?');
        $stmt->execute([$user_id]);
        $row = $stmt->fetch();
    } catch (Throwable $e) {
        return default_tweaks();
    }
    if (!$row) return default_tweaks();
    return [
        'density'       => $row['density'],
        'theme'         => $row['theme'],
        'mode'          => $row['mode'],
        'fontPair'      => $row['font_pair'],
        'radius'        => $row['radius'],
        'stickerRotate' => $row['sticker_rotate'] ? 'on' : 'off',
        'dotGrid'       => $row['dot_grid'] ? 'on' : 'off',
    ];
}
