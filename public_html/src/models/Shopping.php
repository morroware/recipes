<?php
// public_html/src/models/Shopping.php
// Shopping list data access. Prepared statements only.

declare(strict_types=1);

class Shopping {

    /** @return array<int, array<string,mixed>> */
    public static function listForUser(int $user_id): array {
        $stmt = db()->prepare(
            'SELECT id, name, qty, unit, source_recipe_id, source_label,
                    aisle, checked, position, created_at
               FROM shopping_items
              WHERE user_id = ?
              ORDER BY checked ASC, position ASC, id ASC'
        );
        $stmt->execute([$user_id]);
        return $stmt->fetchAll();
    }

    public static function findById(int $user_id, int $id): ?array {
        $stmt = db()->prepare(
            'SELECT id, name, qty, unit, source_recipe_id, source_label,
                    aisle, checked, position, created_at
               FROM shopping_items
              WHERE id = ? AND user_id = ?
              LIMIT 1'
        );
        $stmt->execute([$id, $user_id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Add a manual or pantry-sourced item. Dedupes by normalized name + same
     * source label so that "from Carbonara" and "manual" don't collide.
     */
    public static function add(int $user_id, array $patch): array {
        $name = trim((string)($patch['name'] ?? ''));
        if ($name === '') throw new InvalidArgumentException('name_required');
        if (mb_strlen($name) > 128) throw new InvalidArgumentException('name_too_long');

        $sourceLabel = trim((string)($patch['source_label'] ?? $patch['source'] ?? 'manual'));
        $aisle = self::normalizeAisle($patch['aisle'] ?? null);
        $qty   = isset($patch['qty']) && $patch['qty'] !== '' && $patch['qty'] !== null
            ? (string)$patch['qty'] : null;
        $unit  = (string)($patch['unit'] ?? '');
        $sourceRecipeId = isset($patch['source_recipe_id']) && $patch['source_recipe_id'] !== ''
            ? (int)$patch['source_recipe_id'] : null;

        // Dedupe by normalized name + identical source label
        $key = pantry_normalize($name);
        $existing = self::findByKeyAndSource($user_id, $key, $sourceLabel);
        if ($existing) return $existing;

        $position = self::nextPosition($user_id);

        $stmt = db()->prepare(
            'INSERT INTO shopping_items
                (user_id, name, qty, unit, source_recipe_id, source_label,
                 aisle, checked, position, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, NOW())'
        );
        $stmt->execute([$user_id, $name, $qty, $unit, $sourceRecipeId, $sourceLabel, $aisle, $position]);
        return self::findById($user_id, (int)db()->lastInsertId());
    }

    public static function update(int $user_id, int $id, array $patch): ?array {
        $existing = self::findById($user_id, $id);
        if (!$existing) return null;

        $fields = [];
        $params = [];
        if (array_key_exists('checked', $patch)) {
            $fields[] = 'checked = ?';
            $params[] = $patch['checked'] ? 1 : 0;
        }
        if (array_key_exists('qty', $patch)) {
            $fields[] = 'qty = ?';
            $params[] = $patch['qty'] === '' || $patch['qty'] === null ? null : (string)$patch['qty'];
        }
        if (array_key_exists('unit', $patch)) {
            $fields[] = 'unit = ?';
            $params[] = (string)$patch['unit'];
        }
        if (array_key_exists('name', $patch) && trim((string)$patch['name']) !== '') {
            $fields[] = 'name = ?';
            $params[] = trim((string)$patch['name']);
        }
        if (!$fields) return $existing;

        $params[] = $id;
        $params[] = $user_id;
        $sql = 'UPDATE shopping_items SET ' . implode(', ', $fields) . ' WHERE id = ? AND user_id = ?';
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return self::findById($user_id, $id);
    }

    public static function delete(int $user_id, int $id): bool {
        $stmt = db()->prepare('DELETE FROM shopping_items WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $user_id]);
        return $stmt->rowCount() > 0;
    }

    public static function clearAll(int $user_id): int {
        $stmt = db()->prepare('DELETE FROM shopping_items WHERE user_id = ?');
        $stmt->execute([$user_id]);
        return $stmt->rowCount();
    }

    /**
     * Add every ingredient of $recipe_id to the user's shopping list, scaled.
     * Dedupes per (normalized name, source label = recipe title).
     * @return array{added:int, skipped:int}
     */
    public static function addFromRecipe(int $user_id, int $recipe_id, float $scale = 1.0): array {
        $recipe = Recipe::findFull($user_id, $recipe_id);
        if (!$recipe) throw new InvalidArgumentException('recipe_not_found');

        $sourceLabel = (string)$recipe['title'];
        $added = 0;
        $skipped = 0;
        foreach ($recipe['ingredients'] as $ing) {
            $name = (string)$ing['name'];
            if ($name === '') continue;
            $key = pantry_normalize($name);
            if (self::findByKeyAndSource($user_id, $key, $sourceLabel)) {
                $skipped++;
                continue;
            }
            $rawQty = $ing['qty'];
            $qty = null;
            if ($rawQty !== null && $rawQty !== '') {
                $scaled = (float)$rawQty * $scale;
                $qty = number_format($scaled, 3, '.', '');
                $qty = rtrim(rtrim($qty, '0'), '.');
                if ($qty === '' || $qty === '-') $qty = null;
            }
            $position = self::nextPosition($user_id);
            $stmt = db()->prepare(
                'INSERT INTO shopping_items
                    (user_id, name, qty, unit, source_recipe_id, source_label,
                     aisle, checked, position, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, NOW())'
            );
            $stmt->execute([
                $user_id, $name, $qty, (string)($ing['unit'] ?? ''),
                $recipe_id, $sourceLabel, self::normalizeAisle($ing['aisle'] ?? null),
                $position,
            ]);
            $added++;
        }
        return ['added' => $added, 'skipped' => $skipped];
    }

    /**
     * Move all checked items into pantry (in_stock=1, last_bought=NOW(),
     * purchase_count++). Dedupes by normalized name. Removes the moved rows
     * from the shopping list afterwards.
     *
     * Returns {added: new pantry rows created, moved: shopping rows consumed}.
     *
     * @return array{added:int, moved:int}
     */
    public static function moveCheckedToPantry(int $user_id): array {
        $pdo = db();
        $stmt = $pdo->prepare(
            'SELECT id, name FROM shopping_items
              WHERE user_id = ? AND checked = 1
              ORDER BY position ASC, id ASC'
        );
        $stmt->execute([$user_id]);
        $rows = $stmt->fetchAll();
        if (!$rows) return ['added' => 0, 'moved' => 0];

        $movedIds = [];
        $added = 0;
        $seen = [];

        $pdo->beginTransaction();
        try {
            foreach ($rows as $row) {
                $name = (string)$row['name'];
                $key = pantry_normalize($name);
                $existing = isset($seen[$key]) ? true : (Pantry::findByKey($user_id, $key) !== null);
                if (!$existing) $added++;
                Pantry::restock($user_id, $name);
                $seen[$key] = true;
                $movedIds[] = (int)$row['id'];
            }
            // Bulk delete the moved rows
            if ($movedIds) {
                $place = implode(',', array_fill(0, count($movedIds), '?'));
                $del = $pdo->prepare("DELETE FROM shopping_items WHERE user_id = ? AND id IN ($place)");
                $del->execute(array_merge([$user_id], $movedIds));
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return ['added' => $added, 'moved' => count($movedIds)];
    }

    // ---- internals ---------------------------------------------------------

    private static function findByKeyAndSource(int $user_id, string $key, string $sourceLabel): ?array {
        if ($key === '') return null;
        // Normalize in PHP so the rule matches pantry_normalize() exactly.
        $stmt = db()->prepare(
            'SELECT id, name, qty, unit, source_recipe_id, source_label,
                    aisle, checked, position, created_at
               FROM shopping_items
              WHERE user_id = ? AND source_label = ?'
        );
        $stmt->execute([$user_id, $sourceLabel]);
        foreach ($stmt->fetchAll() as $row) {
            if (pantry_normalize((string)$row['name']) === $key) return $row;
        }
        return null;
    }

    private static function nextPosition(int $user_id): int {
        $stmt = db()->prepare('SELECT COALESCE(MAX(position), 0) + 1 AS p FROM shopping_items WHERE user_id = ?');
        $stmt->execute([$user_id]);
        $row = $stmt->fetch();
        return (int)($row['p'] ?? 1);
    }

    private static function normalizeAisle($val): string {
        $aisle = (string)($val ?? '');
        return $aisle !== '' && in_array($aisle, AISLES, true) ? $aisle : 'Other';
    }
}
