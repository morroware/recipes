<?php
// public_html/src/controllers/ShoppingController.php

declare(strict_types=1);

class ShoppingController {

    public function page(): void {
        $uid = require_login();
        $items = Shopping::listForUser($uid);
        $checkedCount = 0;
        foreach ($items as $i) if ((int)$i['checked'] === 1) $checkedCount++;

        render('shopping/index.php', [
            'title'        => 'shopping list · my little cookbook',
            'active'       => 'shopping',
            'items'        => $items,
            'checkedCount' => $checkedCount,
        ]);
    }

    // ---- API ----------------------------------------------------------------

    public function apiList(): void {
        $uid = require_login();
        json_ok(['items' => Shopping::listForUser($uid)]);
    }

    public function apiCreate(): void {
        $uid = require_login();
        csrf_require();
        $body = self::readJson();
        try {
            $row = Shopping::add($uid, $body);
        } catch (InvalidArgumentException $e) {
            json_err($e->getMessage(), 422);
        }
        json_ok(['item' => $row]);
    }

    public function apiUpdate(string $id): void {
        $uid = require_login();
        csrf_require();
        $body = self::readJson();
        $patch = [];
        if (array_key_exists('checked', $body)) $patch['checked'] = (bool)$body['checked'];
        if (array_key_exists('qty', $body))     $patch['qty']     = $body['qty'];
        if (array_key_exists('unit', $body))    $patch['unit']    = $body['unit'];
        if (array_key_exists('name', $body))    $patch['name']    = $body['name'];
        $row = Shopping::update($uid, (int)$id, $patch);
        if ($row === null) json_err('not_found', 404);
        json_ok(['item' => $row]);
    }

    public function apiDelete(string $id): void {
        $uid = require_login();
        csrf_require();
        $ok = Shopping::delete($uid, (int)$id);
        if (!$ok) json_err('not_found', 404);
        json_ok(['deleted' => true]);
    }

    public function apiClearAll(): void {
        $uid = require_login();
        csrf_require();
        $n = Shopping::clearAll($uid);
        json_ok(['deleted' => $n]);
    }

    public function apiAddFromRecipe(string $id): void {
        $uid = require_login();
        csrf_require();
        $scale = isset($_GET['scale']) ? max(0.1, (float)$_GET['scale']) : 1.0;
        try {
            $res = Shopping::addFromRecipe($uid, (int)$id, $scale);
        } catch (InvalidArgumentException $e) {
            json_err($e->getMessage(), 404);
        }
        json_ok($res);
    }

    public function apiMoveToPantry(): void {
        $uid = require_login();
        csrf_require();
        $res = Shopping::moveCheckedToPantry($uid);
        json_ok($res);
    }

    private static function readJson(): array {
        $raw = file_get_contents('php://input');
        if (!$raw) return [];
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }
}
