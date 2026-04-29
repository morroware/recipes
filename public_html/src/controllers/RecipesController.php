<?php
// public_html/src/controllers/RecipesController.php

declare(strict_types=1);

class RecipesController {

    public function browse(): void {
        $uid = require_login();
        $opts = [
            'search'  => trim((string)($_GET['search']  ?? '')),
            'cuisine' => trim((string)($_GET['cuisine'] ?? 'All')),
            'tag'     => trim((string)($_GET['tag']     ?? '')),
            'time'    => trim((string)($_GET['time']    ?? 'All')),
            'sort'    => trim((string)($_GET['sort']    ?? 'title')),
        ];
        $recipes  = Recipe::listForUser($uid, $opts);
        $cuisines = array_merge(['All'], Recipe::distinctCuisines($uid));
        $tags     = array_merge(['All'], Recipe::distinctTags($uid));
        $times    = ['All', '< 30 min', '30–60 min', '> 60 min'];

        render('browse/index.php', [
            'title'    => 'my little cookbook',
            'recipes'  => $recipes,
            'cuisines' => $cuisines,
            'tags'     => $tags,
            'times'    => $times,
            'opts'     => $opts,
        ]);
    }

    public function favorites(): void {
        $uid = require_login();
        $recipes = Recipe::listForUser($uid, ['favorites_only' => true]);
        render('browse/favorites.php', [
            'title'   => 'favorites · my little cookbook',
            'recipes' => $recipes,
        ]);
    }

    public function show(string $id): void {
        $uid = require_login();
        $recipe = Recipe::findFull($uid, (int)$id);
        if (!$recipe) {
            http_response_code(404);
            $title = 'Recipe not found';
            $body_view = SRC_PATH . '/views/_404_body.php';
            require SRC_PATH . '/views/layout.php';
            return;
        }
        render('detail/show.php', [
            'title'  => $recipe['title'] . ' · my little cookbook',
            'recipe' => $recipe,
        ]);
    }

    public function toggleFavorite(string $id): void {
        $uid = require_login();
        csrf_require();
        $next = Recipe::toggleFavorite($uid, (int)$id);
        if ($next === null) json_err('not_found', 404);
        json_ok(['is_favorite' => $next]);
    }

    public function updateNotes(string $id): void {
        $uid = require_login();
        csrf_require();
        $body  = json_decode(file_get_contents('php://input') ?: '[]', true) ?: [];
        $notes = (string)($body['notes'] ?? $_POST['notes'] ?? '');
        if (mb_strlen($notes) > 5000) json_err('notes_too_long', 422);
        Recipe::setNotes($uid, (int)$id, $notes);
        json_ok(['saved' => true]);
    }

    // ---- Add / Edit pages --------------------------------------------------

    public function newPage(): void {
        require_login();
        render('add/edit.php', [
            'title'  => 'add a recipe · my little cookbook',
            'active' => 'add',
            'recipe' => null,
            'mode'   => 'create',
        ]);
    }

    public function editPage(string $id): void {
        $uid = require_login();
        $recipe = Recipe::findFull($uid, (int)$id);
        if (!$recipe) {
            http_response_code(404);
            $title = 'Recipe not found';
            $body_view = SRC_PATH . '/views/_404_body.php';
            require SRC_PATH . '/views/layout.php';
            return;
        }
        render('add/edit.php', [
            'title'  => 'edit · ' . $recipe['title'],
            'active' => '',
            'recipe' => $recipe,
            'mode'   => 'edit',
        ]);
    }

    // ---- API: create / update / delete -------------------------------------

    public function apiCreate(): void {
        $uid = require_login();
        csrf_require();
        $body = self::readJson();
        try {
            $rid = Recipe::create($uid, $body);
        } catch (InvalidArgumentException $e) {
            json_err($e->getMessage(), 422);
        }
        json_ok(['id' => $rid]);
    }

    public function apiUpdate(string $id): void {
        $uid = require_login();
        csrf_require();
        $body = self::readJson();
        try {
            $ok = Recipe::updateFull($uid, (int)$id, $body);
        } catch (InvalidArgumentException $e) {
            json_err($e->getMessage(), 422);
        }
        if (!$ok) json_err('not_found', 404);
        json_ok(['id' => (int)$id]);
    }

    public function apiDelete(string $id): void {
        $uid = require_login();
        csrf_require();
        if (!Recipe::delete($uid, (int)$id)) json_err('not_found', 404);
        json_ok(['deleted' => true]);
    }

    private static function readJson(): array {
        $raw = file_get_contents('php://input');
        if (!$raw) return [];
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }
}
