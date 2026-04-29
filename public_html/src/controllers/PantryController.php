<?php
// public_html/src/controllers/PantryController.php
// Pantry page + JSON API.

declare(strict_types=1);

class PantryController {

    public function page(): void {
        $uid = require_login();

        $mode = ($_GET['mode'] ?? '') === 'tag' ? 'tag' : 'pantry';
        $tags = [];
        $tagResults = [];
        if ($mode === 'tag') {
            $rawTags = (string)($_GET['tags'] ?? '');
            $tags = array_values(array_filter(array_map('trim', explode(',', $rawTags))));
            if ($tags) $tagResults = Pantry::recipesByIngredients($uid, $tags);
        }

        $items   = Pantry::listForUser($uid);
        $inStock = array_values(array_filter($items, static fn($i) => (int)$i['in_stock'] === 1));
        $oos     = array_values(array_filter($items, static fn($i) => (int)$i['in_stock'] !== 1));

        // Group in-stock by category, in PANTRY_CATEGORIES order
        $grouped = [];
        foreach (PANTRY_CATEGORIES as $cat) $grouped[$cat] = [];
        foreach ($inStock as $row) {
            $cat = $row['category'];
            if (!isset($grouped[$cat])) $cat = 'Other';
            $grouped[$cat][] = $row;
        }
        foreach ($grouped as &$bucket) {
            usort($bucket, static fn($a, $b) => strcasecmp($a['name'], $b['name']));
        }
        unset($bucket);

        // Most used: top 6 by purchase_count >= 1
        $mostUsed = array_values(array_filter($items, static fn($i) => (int)$i['purchase_count'] >= 1));
        usort($mostUsed, static fn($a, $b) => (int)$b['purchase_count'] <=> (int)$a['purchase_count']);
        $mostUsed = array_slice($mostUsed, 0, 6);

        $suggestions = $mode === 'pantry' ? Pantry::suggestions($uid) : [];

        render('pantry/index.php', [
            'title'       => 'pantry · my little cookbook',
            'active'      => 'pantry',
            'items'       => $items,
            'inStock'     => $inStock,
            'oos'         => $oos,
            'grouped'     => $grouped,
            'mostUsed'    => $mostUsed,
            'suggestions' => array_slice($suggestions, 0, 8),
            'mode'        => $mode,
            'tags'        => $tags,
            'tagResults'  => $tagResults,
        ]);
    }

    // ---- API ----------------------------------------------------------------

    public function apiList(): void {
        $uid = require_login();
        json_ok(['items' => Pantry::listForUser($uid)]);
    }

    public function apiCreate(): void {
        $uid = require_login();
        csrf_require();
        $body = self::readJson();
        $name = trim((string)($body['name'] ?? ''));
        if ($name === '') json_err('name_required', 422);
        if (mb_strlen($name) > 128) json_err('name_too_long', 422);
        try {
            $row = Pantry::addOrUpdate($uid, $name, [
                'in_stock' => array_key_exists('in_stock', $body) ? (bool)$body['in_stock'] : true,
                'category' => $body['category'] ?? null,
                'qty'      => $body['qty'] ?? null,
                'unit'     => $body['unit'] ?? null,
            ]);
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
        if (array_key_exists('in_stock', $body)) $patch['in_stock'] = (bool)$body['in_stock'];
        if (array_key_exists('category', $body)) $patch['category'] = $body['category'];
        if (array_key_exists('qty', $body))      $patch['qty']      = $body['qty'];
        if (array_key_exists('unit', $body))     $patch['unit']     = $body['unit'];
        $row = Pantry::update($uid, (int)$id, $patch);
        if ($row === null) json_err('not_found', 404);
        json_ok(['item' => $row]);
    }

    public function apiDelete(string $id): void {
        $uid = require_login();
        csrf_require();
        $ok = Pantry::delete($uid, (int)$id);
        if (!$ok) json_err('not_found', 404);
        json_ok(['deleted' => true]);
    }

    public function apiRestock(string $id): void {
        $uid = require_login();
        csrf_require();
        $row = Pantry::restockById($uid, (int)$id);
        if ($row === null) json_err('not_found', 404);
        json_ok(['item' => $row]);
    }

    public function apiSuggestions(): void {
        $uid = require_login();
        json_ok(['suggestions' => Pantry::suggestions($uid)]);
    }

    public function apiByIngredients(): void {
        $uid = require_login();
        $names = $_GET['names'] ?? [];
        if (is_string($names)) $names = [$names];
        if (!is_array($names)) $names = [];
        json_ok(['recipes' => Pantry::recipesByIngredients($uid, $names)]);
    }

    public function apiCategorize(): void {
        require_login();
        $name = (string)($_GET['name'] ?? '');
        json_ok(['category' => pantry_categorize($name)]);
    }

    private static function readJson(): array {
        $raw = file_get_contents('php://input');
        if (!$raw) return [];
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }
}
