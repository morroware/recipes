<?php
// public_html/src/models/Plan.php
// 7-day meal plan. One row per (user, day) — UNIQUE constraint enforces it.

declare(strict_types=1);

class Plan {

    /**
     * Coerce any reasonable spelling of a weekday into the canonical
     * three-letter code stored in `meal_plan.day` ('Mon'..'Sun'). The
     * AI assistant routinely passes 'Monday', 'monday', 'mon', 'Mondays'
     * and the like — silently rejecting them used to surface as
     * "no_valid_assignments" with no clue why.
     *
     * Returns null if the input doesn't look like any known day.
     */
    public static function normalizeDay(?string $raw): ?string {
        if ($raw === null) return null;
        $s = mb_strtolower(trim($raw), 'UTF-8');
        if ($s === '') return null;
        // Trim trailing punctuation/quote, plural 's', plain "day" suffix.
        $s = preg_replace('/[\p{P}\p{S}]+$/u', '', $s) ?? $s;
        if (str_ends_with($s, 's')) $s = mb_substr($s, 0, -1);

        static $aliases = [
            'mon' => 'Mon', 'monday' => 'Mon',
            'tue' => 'Tue', 'tues' => 'Tue', 'tuesday' => 'Tue',
            'wed' => 'Wed', 'weds' => 'Wed', 'wednesday' => 'Wed',
            'thu' => 'Thu', 'thur' => 'Thu', 'thurs' => 'Thu', 'thursday' => 'Thu',
            'fri' => 'Fri', 'friday' => 'Fri',
            'sat' => 'Sat', 'saturday' => 'Sat',
            'sun' => 'Sun', 'sunday' => 'Sun',
        ];
        return $aliases[$s] ?? null;
    }

    /**
     * Normalise the keys of a plan map (`{Monday: 1, tues: 2}` →
     * `{Mon: 1, Tue: 2}`). Unrecognised keys are dropped.
     *
     * @param array<string, mixed> $plan
     * @return array<string, mixed>
     */
    public static function normalizePlanMap(array $plan): array {
        $out = [];
        foreach ($plan as $k => $v) {
            $day = self::normalizeDay((string)$k);
            if ($day !== null) $out[$day] = $v;
        }
        return $out;
    }

    /**
     * Returns map: ['Mon' => recipe_row|null, 'Tue' => …, …].
     * Recipe row is whatever Recipe::listForUser returns (single recipe shape).
     */
    public static function forUser(int $user_id): array {
        $byDay = array_fill_keys(PLAN_DAYS, null);

        $stmt = db()->prepare(
            'SELECT mp.day, r.id, r.title, r.cuisine, r.glyph, r.color,
                    r.time_minutes, r.servings, r.photo_url
               FROM meal_plan mp
               LEFT JOIN recipes r ON r.id = mp.recipe_id AND r.user_id = mp.user_id
              WHERE mp.user_id = ?'
        );
        $stmt->execute([$user_id]);
        foreach ($stmt->fetchAll() as $row) {
            if (!in_array($row['day'], PLAN_DAYS, true)) continue;
            if ($row['id'] === null) {
                $byDay[$row['day']] = null;
                continue;
            }
            $byDay[$row['day']] = [
                'id'           => (int)$row['id'],
                'title'        => $row['title'],
                'cuisine'      => $row['cuisine'],
                'glyph'        => $row['glyph'],
                'color'        => $row['color'],
                'time_minutes' => (int)$row['time_minutes'],
                'servings'     => (int)$row['servings'],
                'photo_url'    => $row['photo_url'],
            ];
        }
        return $byDay;
    }

    /** Set day=$day to recipe_id (or null to clear). Returns true on success. */
    public static function setDay(int $user_id, string $day, ?int $recipe_id): bool {
        if (!in_array($day, PLAN_DAYS, true)) {
            throw new InvalidArgumentException('day_invalid');
        }
        if ($recipe_id !== null) {
            $stmt = db()->prepare('SELECT 1 FROM recipes WHERE id = ? AND user_id = ? LIMIT 1');
            $stmt->execute([$recipe_id, $user_id]);
            if (!$stmt->fetch()) throw new InvalidArgumentException('recipe_not_found');
        }

        if ($recipe_id === null) {
            $stmt = db()->prepare('DELETE FROM meal_plan WHERE user_id = ? AND day = ?');
            $stmt->execute([$user_id, $day]);
            return true;
        }
        $stmt = db()->prepare(
            'INSERT INTO meal_plan (user_id, day, recipe_id)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE recipe_id = VALUES(recipe_id)'
        );
        $stmt->execute([$user_id, $day, $recipe_id]);
        return true;
    }

    public static function clearAll(int $user_id): int {
        $stmt = db()->prepare('DELETE FROM meal_plan WHERE user_id = ?');
        $stmt->execute([$user_id]);
        return $stmt->rowCount();
    }

    /**
     * Build/append the shopping list from every assigned day. Each day adds
     * its recipe ingredients (scale=1). Dedupe rules from Shopping::add apply.
     *
     * @return array{added:int, recipes:int}
     */
    public static function buildShoppingList(int $user_id): array {
        $byDay = self::forUser($user_id);
        $totalAdded = 0;
        $recipes = 0;
        foreach ($byDay as $entry) {
            if (!$entry) continue;
            $res = Shopping::addFromRecipe($user_id, (int)$entry['id'], 1.0);
            $totalAdded += $res['added'];
            $recipes++;
        }
        return ['added' => $totalAdded, 'recipes' => $recipes];
    }
}
