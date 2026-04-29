<?php
// public_html/src/models/CookingLog.php
// Tracks recipes the user has actually cooked + how it went.

declare(strict_types=1);

class CookingLog {

    public static function add(
        int $user_id,
        ?int $recipe_id,
        string $recipe_title,
        ?int $rating = null,
        ?string $notes = null,
        ?string $cooked_at = null
    ): array {
        $title = trim($recipe_title);
        if ($title === '' && $recipe_id) {
            $r = Recipe::findById($user_id, $recipe_id);
            if ($r) $title = (string)$r['title'];
        }
        if ($rating !== null) {
            $rating = max(1, min(5, $rating));
        }
        $sql = 'INSERT INTO cooking_log (user_id, recipe_id, recipe_title, rating, notes';
        $vals = '?, ?, ?, ?, ?';
        $params = [$user_id, $recipe_id, mb_substr($title, 0, 160), $rating, $notes];
        if ($cooked_at) {
            $sql .= ', cooked_at';
            $vals .= ', ?';
            $params[] = $cooked_at;
        }
        $sql .= ") VALUES ($vals)";
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return self::findById($user_id, (int)db()->lastInsertId());
    }

    public static function findById(int $user_id, int $id): ?array {
        $stmt = db()->prepare(
            'SELECT id, recipe_id, recipe_title, cooked_at, rating, notes
               FROM cooking_log
              WHERE id = ? AND user_id = ? LIMIT 1'
        );
        $stmt->execute([$id, $user_id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function recent(int $user_id, int $limit = 20): array {
        $stmt = db()->prepare(
            'SELECT id, recipe_id, recipe_title, cooked_at, rating, notes
               FROM cooking_log
              WHERE user_id = ?
              ORDER BY cooked_at DESC, id DESC
              LIMIT ' . max(1, $limit)
        );
        $stmt->execute([$user_id]);
        return $stmt->fetchAll();
    }

    public static function delete(int $user_id, int $id): bool {
        $stmt = db()->prepare('DELETE FROM cooking_log WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $user_id]);
        return $stmt->rowCount() > 0;
    }

    /** Top N favorite + worst-rated recipes for context. */
    public static function highlights(int $user_id, int $limit = 8): array {
        $stmt = db()->prepare(
            'SELECT recipe_title, recipe_id,
                    AVG(rating) AS avg_rating,
                    COUNT(*)   AS times,
                    MAX(cooked_at) AS last_cooked
               FROM cooking_log
              WHERE user_id = ? AND rating IS NOT NULL
              GROUP BY recipe_title, recipe_id
              ORDER BY avg_rating DESC, times DESC
              LIMIT ' . max(1, $limit)
        );
        $stmt->execute([$user_id]);
        $loved = $stmt->fetchAll();

        $stmt = db()->prepare(
            'SELECT recipe_title, recipe_id, cooked_at, rating, notes
               FROM cooking_log
              WHERE user_id = ?
              ORDER BY cooked_at DESC, id DESC
              LIMIT ' . max(1, $limit)
        );
        $stmt->execute([$user_id]);
        $recent = $stmt->fetchAll();

        return ['loved' => $loved, 'recent' => $recent];
    }
}
