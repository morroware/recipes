<?php
// public_html/src/models/Recipe.php
// Thin data class. Prepared statements only.

declare(strict_types=1);

class Recipe {

    /**
     * List recipes for a user with optional filters.
     *
     * @param array{search?:string, cuisine?:string, tag?:string, time?:string, sort?:string, favorites_only?:bool} $opts
     * @return array<int, array<string,mixed>>
     */
    public static function listForUser(int $user_id, array $opts = []): array {
        $sql = 'SELECT r.* FROM recipes r';
        $where  = ['r.user_id = :uid'];
        $params = [':uid' => $user_id];

        if (!empty($opts['tag'])) {
            $sql    .= ' INNER JOIN recipe_tags rt ON rt.recipe_id = r.id AND rt.tag = :tag';
            $params[':tag'] = $opts['tag'];
        }

        if (!empty($opts['search'])) {
            $where[] = '(r.title LIKE :q OR r.cuisine LIKE :q OR r.summary LIKE :q)';
            $params[':q'] = '%' . $opts['search'] . '%';
        }

        if (!empty($opts['cuisine']) && $opts['cuisine'] !== 'All') {
            $where[] = 'r.cuisine = :cuisine';
            $params[':cuisine'] = $opts['cuisine'];
        }

        if (!empty($opts['time']) && $opts['time'] !== 'All') {
            switch ($opts['time']) {
                case '< 30 min':   $where[] = 'r.time_minutes < 30'; break;
                case '30–60 min':  $where[] = 'r.time_minutes BETWEEN 30 AND 60'; break;
                case '> 60 min':   $where[] = 'r.time_minutes > 60'; break;
            }
        }

        if (!empty($opts['favorites_only'])) {
            $where[] = 'r.is_favorite = 1';
        }

        $sql .= ' WHERE ' . implode(' AND ', $where);

        $sort = $opts['sort'] ?? 'title';
        switch ($sort) {
            case 'time':       $sql .= ' ORDER BY r.time_minutes ASC, r.title ASC'; break;
            case 'newest':     $sql .= ' ORDER BY r.created_at DESC, r.title ASC'; break;
            case 'difficulty': $sql .= ' ORDER BY FIELD(r.difficulty, "Easy","Medium","Hard"), r.title ASC'; break;
            default:           $sql .= ' ORDER BY r.title ASC'; break;
        }

        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $recipes = $stmt->fetchAll();

        if (!$recipes) return [];

        $ids   = array_column($recipes, 'id');
        $tags  = self::tagsByRecipe($ids);
        foreach ($recipes as &$r) {
            $r['tags'] = $tags[(int)$r['id']] ?? [];
        }
        return $recipes;
    }

    public static function findFull(int $user_id, int $recipe_id): ?array {
        $stmt = db()->prepare('SELECT * FROM recipes WHERE id = ? AND user_id = ? LIMIT 1');
        $stmt->execute([$recipe_id, $user_id]);
        $r = $stmt->fetch();
        if (!$r) return null;

        $r['tags']        = self::tagsByRecipe([(int)$r['id']])[(int)$r['id']] ?? [];
        $r['ingredients'] = self::ingredients($recipe_id);
        $r['steps']       = self::steps($recipe_id);
        return $r;
    }

    public static function ingredients(int $recipe_id): array {
        $stmt = db()->prepare('SELECT id, position, qty, unit, name, aisle FROM ingredients WHERE recipe_id = ? ORDER BY position ASC, id ASC');
        $stmt->execute([$recipe_id]);
        return $stmt->fetchAll();
    }

    public static function steps(int $recipe_id): array {
        $stmt = db()->prepare('SELECT id, position, text FROM steps WHERE recipe_id = ? ORDER BY position ASC, id ASC');
        $stmt->execute([$recipe_id]);
        return $stmt->fetchAll();
    }

    /** @param int[] $recipe_ids */
    public static function tagsByRecipe(array $recipe_ids): array {
        if (!$recipe_ids) return [];
        $place = implode(',', array_fill(0, count($recipe_ids), '?'));
        $stmt  = db()->prepare("SELECT recipe_id, tag FROM recipe_tags WHERE recipe_id IN ($place) ORDER BY tag ASC");
        $stmt->execute($recipe_ids);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(int)$row['recipe_id']][] = $row['tag'];
        }
        return $out;
    }

    public static function distinctCuisines(int $user_id): array {
        $stmt = db()->prepare('SELECT DISTINCT cuisine FROM recipes WHERE user_id = ? AND cuisine <> "" ORDER BY cuisine ASC');
        $stmt->execute([$user_id]);
        return array_column($stmt->fetchAll(), 'cuisine');
    }

    public static function distinctTags(int $user_id): array {
        $stmt = db()->prepare('SELECT DISTINCT rt.tag FROM recipe_tags rt INNER JOIN recipes r ON r.id = rt.recipe_id WHERE r.user_id = ? ORDER BY rt.tag ASC');
        $stmt->execute([$user_id]);
        return array_column($stmt->fetchAll(), 'tag');
    }

    public static function toggleFavorite(int $user_id, int $recipe_id): ?bool {
        $pdo = db();
        $sel = $pdo->prepare('SELECT is_favorite FROM recipes WHERE id = ? AND user_id = ? LIMIT 1');
        $sel->execute([$recipe_id, $user_id]);
        $row = $sel->fetch();
        if (!$row) return null;
        $next = $row['is_favorite'] ? 0 : 1;
        $upd = $pdo->prepare('UPDATE recipes SET is_favorite = ? WHERE id = ? AND user_id = ?');
        $upd->execute([$next, $recipe_id, $user_id]);
        return (bool)$next;
    }

    public static function setNotes(int $user_id, int $recipe_id, string $notes): bool {
        $upd = db()->prepare('UPDATE recipes SET notes = ? WHERE id = ? AND user_id = ?');
        $upd->execute([$notes, $recipe_id, $user_id]);
        return $upd->rowCount() > 0;
    }
}
