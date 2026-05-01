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

    /** Lightweight existence/lookup — bare row, no ingredients/steps. */
    public static function findById(int $user_id, int $recipe_id): ?array {
        $stmt = db()->prepare(
            'SELECT id, slug, title, cuisine, summary, time_minutes, servings,
                    difficulty, glyph, color, photo_url, is_favorite
               FROM recipes
              WHERE id = ? AND user_id = ? LIMIT 1'
        );
        $stmt->execute([$recipe_id, $user_id]);
        $r = $stmt->fetch();
        return $r ?: null;
    }

    /**
     * Recipe-RAG search across the user's library. Looks at title, cuisine,
     * summary, notes, ingredient names, step text, and tags — so the assistant
     * can find a saved recipe by partial title, cuisine, or remembered
     * ingredient.
     *
     * The query is tokenised on whitespace so multi-word searches still match
     * titles whose punctuation differs (e.g. "One-Pan Chicken Black Beans
     * Rice" finds the recipe titled "One-Pan Chicken, Black Beans & Rice").
     * Each token must appear somewhere in the recipe's text — the model can
     * therefore feed the full title back without worrying about commas,
     * ampersands, or accents the user typed slightly differently.
     *
     * Pure LIKE (no FULLTEXT) so it works on every MySQL/MariaDB version a
     * shared cPanel host might run. Personal cookbooks rarely exceed a few
     * hundred rows; this is plenty fast.
     *
     * Each row in the result is a full recipe (ingredients + steps + tags).
     *
     * @param array{cuisine?:string, tag?:string, time?:string, favorites_only?:bool} $filters
     * @return array<int, array<string,mixed>>
     */
    public static function search(int $user_id, string $query, array $filters = [], int $limit = 8): array {
        $query = trim($query);
        $limit = max(1, min(20, $limit));

        $tokens = self::searchTokens($query);

        $select = 'SELECT DISTINCT r.id, r.title, r.cuisine, r.summary, r.time_minutes,
                          r.servings, r.difficulty, r.glyph, r.color, r.is_favorite';
        $from   = 'FROM recipes r';
        $joins  = [];
        $where  = ['r.user_id = :uid'];
        $params = [':uid' => $user_id];

        if ($tokens) {
            $joins[] = 'LEFT JOIN ingredients i ON i.recipe_id = r.id';
            $joins[] = 'LEFT JOIN steps s ON s.recipe_id = r.id';
            $joins[] = 'LEFT JOIN recipe_tags rtq ON rtq.recipe_id = r.id';
            $tokenClauses = [];
            foreach ($tokens as $idx => $t) {
                $key = ':q' . $idx;
                $tokenClauses[] = '('
                    . "r.title LIKE $key OR r.cuisine LIKE $key OR r.summary LIKE $key"
                    . " OR r.notes LIKE $key OR i.name LIKE $key OR s.text LIKE $key"
                    . " OR rtq.tag LIKE $key"
                    . ')';
                $params[$key] = '%' . $t . '%';
            }
            // ALL tokens must match somewhere in the recipe (AND).
            $where[] = '(' . implode(' AND ', $tokenClauses) . ')';
        }

        if (!empty($filters['cuisine']) && $filters['cuisine'] !== 'All') {
            $where[] = 'r.cuisine = :cuisine';
            $params[':cuisine'] = (string)$filters['cuisine'];
        }
        if (!empty($filters['tag'])) {
            $joins[] = 'INNER JOIN recipe_tags rt ON rt.recipe_id = r.id AND rt.tag = :tag';
            $params[':tag'] = (string)$filters['tag'];
        }
        if (!empty($filters['time']) && $filters['time'] !== 'All') {
            switch ($filters['time']) {
                case '< 30 min':   $where[] = 'r.time_minutes < 30'; break;
                case '30–60 min':  $where[] = 'r.time_minutes BETWEEN 30 AND 60'; break;
                case '> 60 min':   $where[] = 'r.time_minutes > 60'; break;
            }
        }
        if (!empty($filters['favorites_only'])) {
            $where[] = 'r.is_favorite = 1';
        }

        $sql = $select . ' ' . $from
             . ($joins ? ' ' . implode(' ', $joins) : '')
             . ' WHERE ' . implode(' AND ', $where);

        // Prefer matches in title/cuisine over deep matches in steps/notes.
        // Rank against the full original query first (a literal phrase match
        // wins), then by favorite, then by title alphabetically.
        if ($tokens) {
            $sql .= ' ORDER BY (CASE'
                  . ' WHEN r.title LIKE :rank THEN 1'
                  . ' WHEN r.cuisine LIKE :rank THEN 2'
                  . ' WHEN r.summary LIKE :rank THEN 3'
                  . ' ELSE 4 END), r.is_favorite DESC, r.title ASC';
            // Use the first (typically most distinctive) token for ranking.
            $params[':rank'] = '%' . $tokens[0] . '%';
        } else {
            $sql .= ' ORDER BY r.is_favorite DESC, r.title ASC';
        }
        $sql .= ' LIMIT ' . $limit;

        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        if (!$rows) return [];

        // Hydrate full body so the model gets ingredients + steps without
        // a second tool call.
        $out = [];
        foreach ($rows as $row) {
            $full = self::findFull($user_id, (int)$row['id']);
            if ($full) $out[] = $full;
        }
        return $out;
    }

    /**
     * Tokenise a free-text search query for {@see search()}.
     *
     * - Lowercase + Unicode trim.
     * - Strip leading/trailing punctuation and connector characters
     *   (commas, ampersands, slashes, quotes, dashes…) but keep internal
     *   ones so "one-pan" still matches a hyphenated title.
     * - Drop tokens that are too short or are common stopwords ("and", "or",
     *   "the", "with", "&"). They'd otherwise force-match every recipe and
     *   silently empty the result set when AND-joined.
     * - If every token gets filtered (e.g. the user typed "&" or "the"),
     *   fall back to the trimmed original so the call still returns
     *   something rather than a confusing zero-hit AND-of-nothing.
     *
     * @return string[]
     */
    private static function searchTokens(string $query): array {
        $query = trim($query);
        if ($query === '') return [];

        $stopwords = [
            'a','an','and','or','the','of','to','for','with','in','on',
            '&','+','-','/','\\',
        ];

        $raw = preg_split('/\s+/u', $query) ?: [];
        $tokens = [];
        foreach ($raw as $t) {
            // Trim outer punctuation; keep internal hyphens / apostrophes.
            $t = preg_replace('/^[\p{P}\p{S}]+|[\p{P}\p{S}]+$/u', '', $t) ?? '';
            if ($t === '') continue;
            $lower = mb_strtolower($t, 'UTF-8');
            if (mb_strlen($lower) < 2) continue;
            if (in_array($lower, $stopwords, true)) continue;
            $tokens[] = $t;
        }
        if (!$tokens) return [$query];
        return $tokens;
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

    /**
     * Create a recipe with ingredients/steps/tags. Returns the new recipe id.
     * @throws InvalidArgumentException on bad input.
     */
    public static function create(int $user_id, array $data): int {
        $clean = self::sanitize($data);
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $slug = self::uniqueSlug($user_id, $clean['title']);
            $stmt = $pdo->prepare(
                'INSERT INTO recipes
                    (user_id, slug, title, cuisine, summary, time_minutes, servings,
                     difficulty, glyph, color, photo_url, notes, is_favorite)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)'
            );
            $stmt->execute([
                $user_id, $slug, $clean['title'], $clean['cuisine'], $clean['summary'],
                $clean['time_minutes'], $clean['servings'], $clean['difficulty'],
                $clean['glyph'], $clean['color'], $clean['photo_url'], $clean['notes'],
            ]);
            $rid = (int)$pdo->lastInsertId();

            self::writeIngredients($rid, $clean['ingredients']);
            self::writeSteps($rid, $clean['steps']);
            self::writeTags($rid, $clean['tags']);

            $pdo->commit();
            return $rid;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function updateFull(int $user_id, int $recipe_id, array $data): bool {
        $existing = self::findFull($user_id, $recipe_id);
        if (!$existing) return false;
        $clean = self::sanitize($data);

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $slug = $existing['slug'];
            if ($clean['title'] !== $existing['title']) {
                $slug = self::uniqueSlug($user_id, $clean['title'], $recipe_id);
            }
            $stmt = $pdo->prepare(
                'UPDATE recipes
                    SET slug = ?, title = ?, cuisine = ?, summary = ?,
                        time_minutes = ?, servings = ?, difficulty = ?,
                        glyph = ?, color = ?, photo_url = ?, notes = ?
                  WHERE id = ? AND user_id = ?'
            );
            $stmt->execute([
                $slug, $clean['title'], $clean['cuisine'], $clean['summary'],
                $clean['time_minutes'], $clean['servings'], $clean['difficulty'],
                $clean['glyph'], $clean['color'], $clean['photo_url'], $clean['notes'],
                $recipe_id, $user_id,
            ]);

            $pdo->prepare('DELETE FROM ingredients WHERE recipe_id = ?')->execute([$recipe_id]);
            $pdo->prepare('DELETE FROM steps WHERE recipe_id = ?')->execute([$recipe_id]);
            $pdo->prepare('DELETE FROM recipe_tags WHERE recipe_id = ?')->execute([$recipe_id]);

            self::writeIngredients($recipe_id, $clean['ingredients']);
            self::writeSteps($recipe_id, $clean['steps']);
            self::writeTags($recipe_id, $clean['tags']);

            $pdo->commit();
            return true;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function delete(int $user_id, int $recipe_id): bool {
        $stmt = db()->prepare('DELETE FROM recipes WHERE id = ? AND user_id = ?');
        $stmt->execute([$recipe_id, $user_id]);
        return $stmt->rowCount() > 0;
    }

    // ---- internals ---------------------------------------------------------

    private static function sanitize(array $data): array {
        $colors = ['mint','butter','peach','lilac','sky','blush','lime','coral'];
        $diffs  = ['Easy','Medium','Hard'];

        $title = trim((string)($data['title'] ?? ''));
        if ($title === '') throw new InvalidArgumentException('title_required');
        if (mb_strlen($title) > 160) throw new InvalidArgumentException('title_too_long');

        $cuisine = trim((string)($data['cuisine'] ?? ''));
        $summary = (string)($data['summary'] ?? '');
        $notes   = (string)($data['notes'] ?? '');

        $time = (int)($data['time_minutes'] ?? $data['time'] ?? 30);
        if ($time < 0 || $time > 24 * 60) throw new InvalidArgumentException('time_invalid');

        $servings = (int)($data['servings'] ?? 2);
        if ($servings < 1 || $servings > 100) throw new InvalidArgumentException('servings_invalid');

        $difficulty = (string)($data['difficulty'] ?? 'Easy');
        if (!in_array($difficulty, $diffs, true)) $difficulty = 'Easy';

        $color = (string)($data['color'] ?? 'mint');
        if (!in_array($color, $colors, true)) $color = 'mint';

        $glyph = trim((string)($data['glyph'] ?? '🍽️'));
        if ($glyph === '') $glyph = '🍽️';
        if (mb_strlen($glyph) > 8) $glyph = mb_substr($glyph, 0, 8);

        $photo = trim((string)($data['photo_url'] ?? ''));
        if ($photo !== '' && !preg_match('#^(https?://|/)#i', $photo)) {
            throw new InvalidArgumentException('photo_url_invalid');
        }

        // Ingredients
        $ingredients = [];
        $rawIng = $data['ingredients'] ?? [];
        if (is_array($rawIng)) {
            foreach ($rawIng as $i) {
                $name = trim((string)($i['name'] ?? ''));
                if ($name === '') continue;
                $qty = $i['qty'] ?? null;
                if ($qty !== null && $qty !== '') $qty = (string)$qty;
                $aisle = (string)($i['aisle'] ?? 'Other');
                if (!in_array($aisle, AISLES, true)) $aisle = 'Other';
                $ingredients[] = [
                    'name'  => mb_substr($name, 0, 128),
                    'qty'   => ($qty === '' || $qty === null) ? null : $qty,
                    'unit'  => mb_substr((string)($i['unit'] ?? ''), 0, 16),
                    'aisle' => $aisle,
                ];
            }
        }

        // Steps
        $steps = [];
        $rawSteps = $data['steps'] ?? [];
        if (is_array($rawSteps)) {
            foreach ($rawSteps as $s) {
                $text = is_array($s) ? (string)($s['text'] ?? '') : (string)$s;
                $text = trim($text);
                if ($text === '') continue;
                $steps[] = $text;
            }
        }

        // Tags
        $tags = [];
        $rawTags = $data['tags'] ?? [];
        if (is_string($rawTags)) {
            $rawTags = array_map('trim', explode(',', $rawTags));
        }
        if (is_array($rawTags)) {
            foreach ($rawTags as $t) {
                $t = trim((string)$t);
                if ($t === '') continue;
                if (!in_array($t, $tags, true)) $tags[] = mb_substr($t, 0, 64);
            }
        }

        return [
            'title'        => mb_substr($title, 0, 160),
            'cuisine'      => mb_substr($cuisine, 0, 64),
            'summary'      => $summary,
            'time_minutes' => $time,
            'servings'     => $servings,
            'difficulty'   => $difficulty,
            'glyph'        => $glyph,
            'color'        => $color,
            'photo_url'    => $photo === '' ? null : $photo,
            'notes'        => $notes,
            'ingredients'  => $ingredients,
            'steps'        => $steps,
            'tags'         => $tags,
        ];
    }

    private static function writeIngredients(int $recipe_id, array $ingredients): void {
        if (!$ingredients) return;
        $stmt = db()->prepare(
            'INSERT INTO ingredients (recipe_id, position, qty, unit, name, aisle)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        foreach ($ingredients as $i => $ing) {
            $stmt->execute([$recipe_id, $i, $ing['qty'], $ing['unit'], $ing['name'], $ing['aisle']]);
        }
    }

    private static function writeSteps(int $recipe_id, array $steps): void {
        if (!$steps) return;
        $stmt = db()->prepare(
            'INSERT INTO steps (recipe_id, position, text) VALUES (?, ?, ?)'
        );
        foreach ($steps as $i => $text) {
            $stmt->execute([$recipe_id, $i, $text]);
        }
    }

    private static function writeTags(int $recipe_id, array $tags): void {
        if (!$tags) return;
        $stmt = db()->prepare(
            'INSERT IGNORE INTO recipe_tags (recipe_id, tag) VALUES (?, ?)'
        );
        foreach ($tags as $t) {
            $stmt->execute([$recipe_id, $t]);
        }
    }

    private static function uniqueSlug(int $user_id, string $title, ?int $exclude_id = null): string {
        $base = preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($title)) ?: 'recipe';
        $base = trim($base, '-');
        if ($base === '') $base = 'recipe';
        $base = mb_substr($base, 0, 50);

        $slug = $base;
        $n = 1;
        while (self::slugExists($user_id, $slug, $exclude_id)) {
            $n++;
            $slug = $base . '-' . $n;
            if ($n > 200) { $slug = $base . '-' . substr(bin2hex(random_bytes(3)), 0, 6); break; }
        }
        return $slug;
    }

    private static function slugExists(int $user_id, string $slug, ?int $exclude_id): bool {
        if ($exclude_id) {
            $stmt = db()->prepare('SELECT 1 FROM recipes WHERE user_id = ? AND slug = ? AND id <> ? LIMIT 1');
            $stmt->execute([$user_id, $slug, $exclude_id]);
        } else {
            $stmt = db()->prepare('SELECT 1 FROM recipes WHERE user_id = ? AND slug = ? LIMIT 1');
            $stmt->execute([$user_id, $slug]);
        }
        return (bool)$stmt->fetch();
    }
}
