<?php
// public_html/config.example.php
// install.php writes the real config.php with values supplied through the wizard.
// This file is checked into git as a reference of the expected shape.

return [
    'db' => [
        'host'    => 'localhost',
        'name'    => 'cpaneluser_recipes',
        'user'    => 'cpaneluser_recipes',
        'pass'    => 'replace-me',
        'charset' => 'utf8mb4',
    ],
    'app_key'      => '<32-byte hex string written by install.php>',
    'session_name' => 'rb_sess',
];
