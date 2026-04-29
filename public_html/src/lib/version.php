<?php
// public_html/src/lib/version.php
// Stamped per release. Bump APP_VERSION when cutting a tag. The installed-at
// timestamp comes from db/install.lock so it stays accurate across re-zips.

declare(strict_types=1);

const APP_VERSION = '1.0.0-alpha';

/**
 * @return array{version:string, installed_at:?string, php:string}
 */
function version_info(): array {
    $lock = APP_ROOT . '/db/install.lock';
    $installed = null;
    if (is_file($lock)) {
        $first = trim((string)@file_get_contents($lock));
        if ($first !== '') {
            // first line is an ISO-8601 UTC stamp written by install.php
            $installed = strtok($first, "\n");
        }
    }
    return [
        'version'      => APP_VERSION,
        'installed_at' => $installed,
        'php'          => PHP_VERSION,
    ];
}
