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

    /**
     * Memories that should never decay (hard constraints). Even if a user hasn't
     * mentioned an allergy in a year, we keep treating it at full weight.
     */
    private const NEVER_DECAY = ['allergy', 'diet'];

    /** @return array<int, array<string,mixed>> */
    public static function listForUser(int $user_id, ?int $limit = null): array {
        // Fetch all rows for the user, then re-sort by effective (decayed)
        // weight in PHP — that way limit truncation drops the right memories.
        // Personal cookbooks rarely have more than a few hundred memories,
        // so this is fine on cPanel.
        $stmt = db()->prepare(
            'SELECT id, category, fact, source, weight, pinned, created_at, updated_at
               FROM ai_memories
              WHERE user_id = ?'
        );
        $stmt->execute([$user_id]);
        $rows = $stmt->fetchAll();

        foreach ($rows as &$r) {
            $r['effective_weight'] = self::effectiveWeight($r);
        }
        unset($r);
        usort($rows, function ($a, $b) {
            // Pinned wins, then effective weight, then most-recently-updated.
            $pa = (int)($a['pinned'] ?? 0);
            $pb = (int)($b['pinned'] ?? 0);
            if ($pa !== $pb) return $pb - $pa;
            $wa = (int)$a['effective_weight'];
            $wb = (int)$b['effective_weight'];
            if ($wa !== $wb) return $wb - $wa;
            return strcmp((string)($b['updated_at'] ?? ''), (string)($a['updated_at'] ?? ''));
        });

        if ($limit !== null && $limit > 0 && count($rows) > $limit) {
            $rows = array_slice($rows, 0, $limit);
        }
        return $rows;
    }

    /**
     * Soft decay: a memory not reinforced in 180 days loses 1 weight per
     * additional 90 days (capped at 2 total) — UNLESS it's an allergy/diet
     * (those never decay) or pinned. Pure read-time recompute.
     */
    public static function effectiveWeight(array $row): int {
        $weight = (int)($row['weight'] ?? 5);
        if (!empty($row['pinned'])) return $weight;
        $cat = (string)($row['category'] ?? 'other');
        if (in_array($cat, self::NEVER_DECAY, true)) return $weight;

        $updated = strtotime((string)($row['updated_at'] ?? '')) ?: 0;
        if ($updated <= 0) return $weight;
        $ageDays = (int)floor((time() - $updated) / 86400);
        if ($ageDays <= 180) return $weight;

        $decay = (int)floor(($ageDays - 180) / 90) + 1;
        if ($decay > 2) $decay = 2;
        $eff = $weight - $decay;
        return $eff < 1 ? 1 : $eff;
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

    /**
     * Group memories by category for compact LLM context. listForUser()
     * already orders by (pinned DESC, effective_weight DESC) — so taking the
     * first 80 here naturally drops low-weight unpinned items first while
     * keeping pinned/allergy/diet memories on top.
     */
    public static function groupedForContext(int $user_id): array {
        $rows = self::listForUser($user_id, 80);
        $by = [];
        foreach ($rows as $r) {
            $by[$r['category']][] = $r['fact'];
        }
        return $by;
    }
}
