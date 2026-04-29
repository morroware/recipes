<?php
// public_html/src/models/Memory.php
// AI-learned facts about the user (preferences, allergies, equipment, etc.).
// Prepared statements only.

declare(strict_types=1);

class Memory {

    public const CATEGORIES = [
        'diet', 'allergy', 'dislike', 'like', 'cuisine',
        'household', 'equipment', 'skill', 'schedule', 'goal', 'other',
    ];

    public const SOURCES = ['user', 'assistant', 'system'];

    /** @return array<int, array<string,mixed>> */
    public static function listForUser(int $user_id, ?int $limit = null): array {
        $sql = 'SELECT id, category, fact, source, weight, pinned, created_at, updated_at
                  FROM ai_memories
                 WHERE user_id = ?
                 ORDER BY pinned DESC, weight DESC, updated_at DESC';
        if ($limit !== null) {
            $sql .= ' LIMIT ' . max(1, $limit);
        }
        $stmt = db()->prepare($sql);
        $stmt->execute([$user_id]);
        return $stmt->fetchAll();
    }

    public static function findById(int $user_id, int $id): ?array {
        $stmt = db()->prepare(
            'SELECT id, category, fact, source, weight, pinned, created_at, updated_at
               FROM ai_memories
              WHERE id = ? AND user_id = ? LIMIT 1'
        );
        $stmt->execute([$id, $user_id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Insert a memory if no near-duplicate exists for this user. */
    public static function add(
        int $user_id,
        string $fact,
        string $category = 'other',
        string $source   = 'assistant',
        int    $weight   = 5,
        bool   $pinned   = false
    ): ?array {
        $fact = trim($fact);
        if ($fact === '' || mb_strlen($fact) > 512) return null;
        if (!in_array($category, self::CATEGORIES, true)) $category = 'other';
        if (!in_array($source,   self::SOURCES,    true)) $source   = 'assistant';

        // De-dupe: identical fact (case-insensitive) for same user.
        $dup = db()->prepare(
            'SELECT id FROM ai_memories
              WHERE user_id = ? AND LOWER(fact) = LOWER(?) LIMIT 1'
        );
        $dup->execute([$user_id, $fact]);
        if ($existing = $dup->fetch()) {
            // Bump weight and refresh updated_at so it stays fresh in context.
            $bump = db()->prepare(
                'UPDATE ai_memories
                    SET weight = LEAST(10, weight + 1),
                        updated_at = NOW()
                  WHERE id = ? AND user_id = ?'
            );
            $bump->execute([(int)$existing['id'], $user_id]);
            return self::findById($user_id, (int)$existing['id']);
        }

        $stmt = db()->prepare(
            'INSERT INTO ai_memories (user_id, category, fact, source, weight, pinned)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$user_id, $category, $fact, $source, max(1, min(10, $weight)), $pinned ? 1 : 0]);
        return self::findById($user_id, (int)db()->lastInsertId());
    }

    public static function update(int $user_id, int $id, array $patch): ?array {
        $existing = self::findById($user_id, $id);
        if (!$existing) return null;

        $fields = [];
        $params = [];
        if (array_key_exists('fact', $patch)) {
            $fact = trim((string)$patch['fact']);
            if ($fact === '') return $existing;
            $fields[] = 'fact = ?';
            $params[] = mb_substr($fact, 0, 512);
        }
        if (array_key_exists('category', $patch) && in_array($patch['category'], self::CATEGORIES, true)) {
            $fields[] = 'category = ?';
            $params[] = $patch['category'];
        }
        if (array_key_exists('weight', $patch)) {
            $fields[] = 'weight = ?';
            $params[] = max(1, min(10, (int)$patch['weight']));
        }
        if (array_key_exists('pinned', $patch)) {
            $fields[] = 'pinned = ?';
            $params[] = !empty($patch['pinned']) ? 1 : 0;
        }
        if (!$fields) return $existing;

        $params[] = $id;
        $params[] = $user_id;
        $stmt = db()->prepare(
            'UPDATE ai_memories SET ' . implode(', ', $fields)
            . ' WHERE id = ? AND user_id = ?'
        );
        $stmt->execute($params);
        return self::findById($user_id, $id);
    }

    public static function delete(int $user_id, int $id): bool {
        $stmt = db()->prepare('DELETE FROM ai_memories WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $user_id]);
        return $stmt->rowCount() > 0;
    }

    public static function deleteAll(int $user_id): int {
        $stmt = db()->prepare('DELETE FROM ai_memories WHERE user_id = ?');
        $stmt->execute([$user_id]);
        return $stmt->rowCount();
    }

    /** Group memories by category for compact LLM context. */
    public static function groupedForContext(int $user_id): array {
        $rows = self::listForUser($user_id, 80);
        $by = [];
        foreach ($rows as $r) {
            $by[$r['category']][] = $r['fact'];
        }
        return $by;
    }
}
