<?php
// public_html/src/models/Pantry.php
// Pantry data access. Prepared statements only.

declare(strict_types=1);

class Pantry {

    /** @return array<int, array<string,mixed>> */
    public static function listForUser(int $user_id): array {
        $stmt = db()->prepare(
            'SELECT id, name, key_normalized, in_stock, qty, unit, category,
                    last_bought, purchase_count, added_at
               FROM pantry_items
              WHERE user_id = ?
              ORDER BY name ASC'
        );
        $stmt->execute([$user_id]);
        return $stmt->fetchAll();
    }

    public static function findById(int $user_id, int $id): ?array {
        $stmt = db()->prepare(
            'SELECT id, name, key_normalized, in_stock, qty, unit, category,
                    last_bought, purchase_count, added_at
               FROM pantry_items
              WHERE id = ? AND user_id = ?
              LIMIT 1'
        );
        $stmt->execute([$id, $user_id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function findByKey(int $user_id, string $key): ?array {
        $stmt = db()->prepare(
            'SELECT id, name, key_normalized, in_stock, qty, unit, category,
                    last_bought, purchase_count, added_at
               FROM pantry_items
              WHERE user_id = ? AND key_normalized = ?
              LIMIT 1'
        );
        $stmt->execute([$user_id, $key]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Insert a new item or, if a matching key_normalized already exists,
     * update the patchable fields and return that row.
     *
     * Returns the resulting row.
     */
    public static function addOrUpdate(int $user_id, string $name, array $patch = []): array {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('name_required');
        }
        $key = pantry_normalize($name);
        if ($key === '') {
            throw new InvalidArgumentException('name_invalid');
        }

        $existing = self::findByKey($user_id, $key);
        if ($existing) {
            $fields = [];
            $params = [];
            if (array_key_exists('in_stock', $patch)) {
                $fields[] = 'in_stock = ?';
                $params[] = $patch['in_stock'] ? 1 : 0;
            }
            if (array_key_exists('category', $patch) && in_array($patch['category'], PANTRY_CATEGORIES, true)) {
                $fields[] = 'category = ?';
                $params[] = $patch['category'];
            }
            if (array_key_exists('qty', $patch)) {
                $fields[] = 'qty = ?';
                $params[] = $patch['qty'] === '' || $patch['qty'] === null ? null : (string)$patch['qty'];
            }
            if (array_key_exists('unit', $patch)) {
                $fields[] = 'unit = ?';
                $params[] = (string)$patch['unit'];
            }
            if ($fields) {
                $params[] = (int)$existing['id'];
                $params[] = $user_id;
                $sql = 'UPDATE pantry_items SET ' . implode(', ', $fields) . ' WHERE id = ? AND user_id = ?';
                $stmt = db()->prepare($sql);
                $stmt->execute($params);
            }
            return self::findById($user_id, (int)$existing['id']);
        }

        $category = (isset($patch['category']) && in_array($patch['category'], PANTRY_CATEGORIES, true))
            ? $patch['category']
            : pantry_categorize($name);
        $inStock = array_key_exists('in_stock', $patch) ? ($patch['in_stock'] ? 1 : 0) : 1;
        $qty     = array_key_exists('qty', $patch) && $patch['qty'] !== '' && $patch['qty'] !== null
            ? (string)$patch['qty'] : null;
        $unit    = (string)($patch['unit'] ?? '');

        $stmt = db()->prepare(
            'INSERT INTO pantry_items
                (user_id, name, key_normalized, in_stock, qty, unit, category, added_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([$user_id, $name, $key, $inStock, $qty, $unit, $category]);
        $id = (int)db()->lastInsertId();
        return self::findById($user_id, $id);
    }

    public static function update(int $user_id, int $id, array $patch): ?array {
        $existing = self::findById($user_id, $id);
        if (!$existing) return null;

        $fields = [];
        $params = [];
        if (array_key_exists('in_stock', $patch)) {
            $fields[] = 'in_stock = ?';
            $params[] = $patch['in_stock'] ? 1 : 0;
        }
        if (array_key_exists('category', $patch) && in_array($patch['category'], PANTRY_CATEGORIES, true)) {
            $fields[] = 'category = ?';
            $params[] = $patch['category'];
        }
        if (array_key_exists('qty', $patch)) {
            $fields[] = 'qty = ?';
            $params[] = $patch['qty'] === '' || $patch['qty'] === null ? null : (string)$patch['qty'];
        }
        if (array_key_exists('unit', $patch)) {
            $fields[] = 'unit = ?';
            $params[] = (string)$patch['unit'];
        }
        if (!$fields) return $existing;

        $params[] = $id;
        $params[] = $user_id;
        $sql = 'UPDATE pantry_items SET ' . implode(', ', $fields) . ' WHERE id = ? AND user_id = ?';
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return self::findById($user_id, $id);
    }

    public static function delete(int $user_id, int $id): bool {
        $stmt = db()->prepare('DELETE FROM pantry_items WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $user_id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Restock semantics: in_stock=1, last_bought=NOW(), purchase_count++.
     * If the item doesn't exist, create it (count=1, in_stock=1).
     */
    public static function restock(int $user_id, string $name): array {
        $name = trim($name);
        if ($name === '') throw new InvalidArgumentException('name_required');
        $key = pantry_normalize($name);
        if ($key === '') throw new InvalidArgumentException('name_invalid');

        $existing = self::findByKey($user_id, $key);
        if ($existing) {
            $stmt = db()->prepare(
                'UPDATE pantry_items
                    SET in_stock = 1,
                        last_bought = NOW(),
                        purchase_count = purchase_count + 1
                  WHERE id = ? AND user_id = ?'
            );
            $stmt->execute([(int)$existing['id'], $user_id]);
            return self::findById($user_id, (int)$existing['id']);
        }
        $category = pantry_categorize($name);
        $stmt = db()->prepare(
            'INSERT INTO pantry_items
                (user_id, name, key_normalized, in_stock, category,
                 last_bought, purchase_count, added_at)
             VALUES (?, ?, ?, 1, ?, NOW(), 1, NOW())'
        );
        $stmt->execute([$user_id, $name, $key, $category]);
        return self::findById($user_id, (int)db()->lastInsertId());
    }

    public static function restockById(int $user_id, int $id): ?array {
        $existing = self::findById($user_id, $id);
        if (!$existing) return null;
        $stmt = db()->prepare(
            'UPDATE pantry_items
                SET in_stock = 1,
                    last_bought = NOW(),
                    purchase_count = purchase_count + 1
              WHERE id = ? AND user_id = ?'
        );
        $stmt->execute([$id, $user_id]);
        return self::findById($user_id, $id);
    }

    /** Normalized keys for currently in-stock items. */
    public static function inStockKeys(int $user_id): array {
        $stmt = db()->prepare('SELECT key_normalized FROM pantry_items WHERE user_id = ? AND in_stock = 1');
        $stmt->execute([$user_id]);
        return array_column($stmt->fetchAll(), 'key_normalized');
    }

    /**
     * Rank user's recipes by % match against in-stock pantry items.
     * @return array<int, array{recipe: array, have: int, total: int, pct: float, missing: array}>
     */
    public static function suggestions(int $user_id): array {
        $recipes  = Recipe::listForUser($user_id);
        if (!$recipes) return [];

        $ids = array_map(static fn($r) => (int)$r['id'], $recipes);
        $place = implode(',', array_fill(0, count($ids), '?'));
        $stmt = db()->prepare(
            "SELECT recipe_id, name FROM ingredients WHERE recipe_id IN ($place) ORDER BY recipe_id, position"
        );
        $stmt->execute($ids);
        $byRecipe = [];
        foreach ($stmt->fetchAll() as $row) {
            $byRecipe[(int)$row['recipe_id']][] = (string)$row['name'];
        }

        $stockKeys = self::inStockKeys($user_id);

        $out = [];
        foreach ($recipes as $r) {
            $rid = (int)$r['id'];
            $ings = $byRecipe[$rid] ?? [];
            $total = count($ings);
            $missing = [];
            $have = 0;
            foreach ($ings as $name) {
                if (pantry_has_ingredient($stockKeys, $name)) {
                    $have++;
                } else {
                    $missing[] = $name;
                }
            }
            $pct = $total > 0 ? $have / $total : 0.0;
            $out[] = [
                'recipe'  => $r,
                'have'    => $have,
                'total'   => $total,
                'pct'     => $pct,
                'missing' => $missing,
            ];
        }
        usort($out, static fn($a, $b) => $b['pct'] <=> $a['pct']);
        return $out;
    }

    /**
     * Return recipes whose ingredient list contains substrings for every name
     * in $names (case-insensitive).
     * @param string[] $names
     */
    public static function recipesByIngredients(int $user_id, array $names): array {
        $names = array_values(array_filter(array_map(
            static fn($n) => trim((string)$n),
            $names
        )));
        if (!$names) return [];

        $recipes = Recipe::listForUser($user_id);
        if (!$recipes) return [];
        $ids = array_map(static fn($r) => (int)$r['id'], $recipes);
        $place = implode(',', array_fill(0, count($ids), '?'));
        $stmt = db()->prepare(
            "SELECT recipe_id, LOWER(name) AS name FROM ingredients WHERE recipe_id IN ($place)"
        );
        $stmt->execute($ids);
        $byRecipe = [];
        foreach ($stmt->fetchAll() as $row) {
            $byRecipe[(int)$row['recipe_id']][] = (string)$row['name'];
        }

        $needles = array_map('strtolower', $names);
        $matches = [];
        foreach ($recipes as $r) {
            $ings = $byRecipe[(int)$r['id']] ?? [];
            $allFound = true;
            foreach ($needles as $needle) {
                $found = false;
                foreach ($ings as $ing) {
                    if (str_contains($ing, $needle)) { $found = true; break; }
                }
                if (!$found) { $allFound = false; break; }
            }
            if ($allFound) $matches[] = $r;
        }
        return $matches;
    }
}
