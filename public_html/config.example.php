<?php
// public_html/config.example.php
// install.php writes the real config.php with values supplied through the wizard.
// This file is checked into git as a reference of the expected shape.

return [
    'db' => [
        'host'    => 'localhost',
        'name'    => 'cpaneluser_recipes',
        'port'    => 3306,
        'user'    => 'cpaneluser_recipes',
        'pass'    => 'replace-me',
        'charset' => 'utf8mb4',
    ],
    'app_key'      => '<32-byte hex string written by install.php>',
    'session_name' => 'rb_sess',

    // Optional: enables Claude-powered AI features (bulk pantry add, recipe
    // import/suggestions, in-app chat). Get a key at console.anthropic.com.
    // Falls back to the ANTHROPIC_API_KEY env var if this is not set.
    'anthropic_api_key' => '',
];
