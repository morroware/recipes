<?php
// public_html/src/controllers/SettingsController.php

declare(strict_types=1);

class SettingsController {

    public function apiGet(): void {
        $uid = require_login();
        json_ok(['settings' => Settings::forUser($uid)]);
    }

    public function apiUpdate(): void {
        $uid = require_login();
        csrf_require();
        $body = self::readJson();
        $row = Settings::update($uid, $body);
        json_ok(['settings' => $row]);
    }

    private static function readJson(): array {
        $raw = file_get_contents('php://input');
        if (!$raw) return [];
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }
}
