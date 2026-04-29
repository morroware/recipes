<?php
// public_html/src/controllers/PlanController.php

declare(strict_types=1);

class PlanController {

    public function page(): void {
        $uid = require_login();
        $byDay   = Plan::forUser($uid);
        $recipes = Recipe::listForUser($uid);

        // Compute Monday's date so the week grid shows real dates.
        $today  = new DateTime('today');
        $offset = ((int)$today->format('N')) - 1; // 0 = Mon … 6 = Sun
        $monday = (clone $today)->modify('-' . $offset . ' day');
        $todayKey = PLAN_DAYS[$offset] ?? 'Mon';

        $dates = [];
        foreach (PLAN_DAYS as $i => $d) {
            $dates[$d] = (clone $monday)->modify('+' . $i . ' day');
        }

        render('plan/index.php', [
            'title'    => 'meal plan · my little cookbook',
            'active'   => 'plan',
            'byDay'    => $byDay,
            'recipes'  => $recipes,
            'dates'    => $dates,
            'todayKey' => $todayKey,
        ]);
    }

    // ---- API ----------------------------------------------------------------

    public function apiList(): void {
        $uid = require_login();
        json_ok(['plan' => Plan::forUser($uid)]);
    }

    public function apiSetDay(string $day): void {
        $uid = require_login();
        csrf_require();
        $body = self::readJson();
        $rid = $body['recipe_id'] ?? null;
        $rid = ($rid === null || $rid === '') ? null : (int)$rid;
        try {
            Plan::setDay($uid, $day, $rid);
        } catch (InvalidArgumentException $e) {
            json_err($e->getMessage(), 422);
        }
        json_ok(['day' => $day, 'recipe_id' => $rid]);
    }

    public function apiClear(): void {
        $uid = require_login();
        csrf_require();
        Plan::clearAll($uid);
        json_ok(['cleared' => true]);
    }

    public function apiBuildShopping(): void {
        $uid = require_login();
        csrf_require();
        $res = Plan::buildShoppingList($uid);
        json_ok($res);
    }

    private static function readJson(): array {
        $raw = file_get_contents('php://input');
        if (!$raw) return [];
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }
}
