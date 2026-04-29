<?php
// public_html/src/controllers/PrintController.php

declare(strict_types=1);

class PrintController {

    public function page(): void {
        $uid = require_login();

        $mode = $_GET['mode'] ?? 'shopping';
        if (!in_array($mode, ['shopping','card','booklet','week'], true)) $mode = 'shopping';

        $recipes = Recipe::listForUser($uid);
        $shoppingRaw = Shopping::listForUser($uid);

        // Group shopping by aisle (using ingredients table to look up aisle).
        $byAisle = [];
        foreach (AISLES as $a) $byAisle[$a] = [];
        $byAisle['Other'] = [];

        // Build a map ingredient name => aisle (case-insensitive).
        $aisleMap = self::aisleMap($uid);
        foreach ($shoppingRaw as $item) {
            $aisle = $item['aisle'] ?? 'Other';
            if ($aisle === 'Other' || $aisle === '') {
                $key = strtolower($item['name']);
                if (isset($aisleMap[$key])) $aisle = $aisleMap[$key];
            }
            if (!isset($byAisle[$aisle])) $aisle = 'Other';
            $byAisle[$aisle][] = $item;
        }
        $byAisle = array_filter($byAisle, static fn($v) => count($v) > 0);

        // Ids requested for card/booklet/week
        $cardId   = isset($_GET['id']) ? (int)$_GET['id'] : null;
        $idsParam = (string)($_GET['ids'] ?? '');
        $bookletIds = array_values(array_filter(array_map('intval', array_filter(explode(',', $idsParam)))));

        $cardRecipe = null;
        if ($cardId) $cardRecipe = Recipe::findFull($uid, $cardId);

        $bookletRecipes = [];
        foreach ($bookletIds as $rid) {
            $r = Recipe::findFull($uid, (int)$rid);
            if ($r) $bookletRecipes[] = $r;
        }

        // Week: pull plan, fetch recipe full
        $week = Plan::forUser($uid);
        $weekRecipes = [];
        foreach ($week as $day => $entry) {
            if (!$entry) continue;
            $r = Recipe::findFull($uid, (int)$entry['id']);
            if ($r) $weekRecipes[] = ['day' => $day, 'recipe' => $r];
        }

        render('print/index.php', [
            'title'           => 'print · my little cookbook',
            'active'          => 'print',
            'mode'            => $mode,
            'recipes'         => $recipes,
            'shoppingByAisle' => $byAisle,
            'shoppingTotal'   => count($shoppingRaw),
            'cardId'          => $cardId,
            'cardRecipe'      => $cardRecipe,
            'bookletIds'      => $bookletIds,
            'bookletRecipes'  => $bookletRecipes,
            'weekRecipes'     => $weekRecipes,
        ]);
    }

    /** Build name (lowercase) => aisle map across the user's ingredients table. */
    private static function aisleMap(int $user_id): array {
        $stmt = db()->prepare(
            'SELECT LOWER(i.name) AS name, i.aisle
               FROM ingredients i
               INNER JOIN recipes r ON r.id = i.recipe_id
              WHERE r.user_id = ?'
        );
        $stmt->execute([$user_id]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            // First wins — keeps it deterministic on duplicate ingredient names.
            if (!isset($out[$row['name']])) $out[$row['name']] = $row['aisle'];
        }
        return $out;
    }
}
