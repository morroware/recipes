<?php
// public_html/src/lib/ai.php
// Wrapper around the Anthropic Messages API + memory-aware context builders.
//
// Reads the API key from $CONFIG['anthropic_api_key'] (config.php) or the
// ANTHROPIC_API_KEY env var. Uses Claude Sonnet 4.6 by default with prompt
// caching on the system block so repeat requests within a 5-minute window
// reuse cached context cheaply.

declare(strict_types=1);

const AI_DEFAULT_MODEL = 'claude-sonnet-4-6';
const AI_API_URL       = 'https://api.anthropic.com/v1/messages';
const AI_API_VERSION   = '2023-06-01';

class AiException extends RuntimeException {}

function ai_api_key(): ?string {
    global $CONFIG;
    if (is_array($CONFIG ?? null) && !empty($CONFIG['anthropic_api_key'])) {
        return (string)$CONFIG['anthropic_api_key'];
    }
    $env = getenv('ANTHROPIC_API_KEY');
    return $env ? (string)$env : null;
}

function ai_enabled(): bool {
    return ai_api_key() !== null;
}

/**
 * Call Claude Messages API.
 *
 * @param array $messages  list of {role, content} message objects
 * @param array $opts      system?: string|array, model?: string, max_tokens?: int,
 *                         temperature?: float, tools?: array, tool_choice?: array
 * @return array  decoded response (with `content`, `stop_reason`, `usage`, etc.)
 * @throws AiException on transport / API failure
 */
function ai_call(array $messages, array $opts = []): array {
    $key = ai_api_key();
    if ($key === null) {
        throw new AiException('AI is not configured. Set anthropic_api_key in config.php.');
    }

    $payload = [
        'model'      => $opts['model']      ?? AI_DEFAULT_MODEL,
        'max_tokens' => $opts['max_tokens'] ?? 1500,
        'messages'   => $messages,
    ];
    if (isset($opts['temperature'])) $payload['temperature'] = (float)$opts['temperature'];
    if (isset($opts['tools']))       $payload['tools']       = $opts['tools'];
    if (isset($opts['tool_choice'])) $payload['tool_choice'] = $opts['tool_choice'];

    if (isset($opts['system'])) {
        if (is_string($opts['system'])) {
            // Cache the static system prompt across requests.
            $payload['system'] = [[
                'type' => 'text',
                'text' => $opts['system'],
                'cache_control' => ['type' => 'ephemeral'],
            ]];
        } else {
            $payload['system'] = $opts['system'];
        }
    }

    $ch = curl_init(AI_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . $key,
            'anthropic-version: ' . AI_API_VERSION,
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_CONNECTTIMEOUT => 15,
    ]);

    $body = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        throw new AiException('Anthropic API transport error: ' . $err);
    }
    $decoded = json_decode((string)$body, true);
    if (!is_array($decoded)) {
        throw new AiException('Anthropic API returned non-JSON response.');
    }
    if ($httpCode < 200 || $httpCode >= 300 || isset($decoded['error'])) {
        $msg = $decoded['error']['message'] ?? ('HTTP ' . $httpCode);
        throw new AiException('Anthropic API error: ' . $msg);
    }
    return $decoded;
}

/** Concatenate all text blocks from a Messages API response. */
function ai_text(array $response): string {
    $parts = [];
    foreach (($response['content'] ?? []) as $block) {
        if (($block['type'] ?? '') === 'text' && isset($block['text'])) {
            $parts[] = $block['text'];
        }
    }
    return trim(implode("\n", $parts));
}

/** Return all tool_use blocks from a Messages API response. */
function ai_tool_uses(array $response): array {
    $uses = [];
    foreach (($response['content'] ?? []) as $block) {
        if (($block['type'] ?? '') === 'tool_use') {
            $uses[] = $block;
        }
    }
    return $uses;
}

/**
 * Try to pull a JSON object/array out of a model response that may have
 * surrounding prose or fenced code blocks.
 */
function ai_extract_json(string $text): ?array {
    $text = trim($text);
    // Fenced ```json ... ```
    if (preg_match('/```(?:json)?\s*(.+?)\s*```/s', $text, $m)) {
        $text = $m[1];
    }
    $decoded = json_decode($text, true);
    if (is_array($decoded)) return $decoded;

    // Fallback: find the first balanced { ... } or [ ... ]
    foreach (['{' => '}', '[' => ']'] as $open => $close) {
        $start = strpos($text, $open);
        $end   = strrpos($text, $close);
        if ($start !== false && $end !== false && $end > $start) {
            $candidate = substr($text, $start, $end - $start + 1);
            $decoded = json_decode($candidate, true);
            if (is_array($decoded)) return $decoded;
        }
    }
    return null;
}

/**
 * Build a brief context block describing the user's pantry + recipe library.
 * Kept compact so we stay within the cache window cheaply.
 */
function ai_kitchen_context(int $user_id): string {
    $lines = [];
    try {
        $pantry = Pantry::listForUser($user_id);
    } catch (Throwable $e) {
        $pantry = [];
    }
    $inStock = array_values(array_filter($pantry, fn($p) => (int)$p['in_stock'] === 1));
    $oos     = array_values(array_filter($pantry, fn($p) => (int)$p['in_stock'] !== 1));

    $byCat = [];
    foreach ($inStock as $p) {
        $byCat[$p['category']][] = $p['name'];
    }

    $lines[] = '# Kitchen context';
    $lines[] = 'In-stock pantry items (' . count($inStock) . '):';
    if (!$inStock) {
        $lines[] = '  (none — pantry is empty)';
    } else {
        foreach ($byCat as $cat => $names) {
            sort($names);
            $lines[] = '  ' . $cat . ': ' . implode(', ', $names);
        }
    }
    if ($oos) {
        $names = array_map(fn($p) => $p['name'], array_slice($oos, 0, 30));
        $lines[] = 'Out of stock (' . count($oos) . '): ' . implode(', ', $names);
    }

    try {
        $recipes = Recipe::listForUser($user_id, ['sort' => 'newest']);
    } catch (Throwable $e) {
        $recipes = [];
    }
    $lines[] = '';
    // Titles only — the model has a `recipe_search` tool to fetch any one of
    // these in full on demand. Keeps the cached system prompt cheap.
    $lines[] = 'Recipe library (' . count($recipes) . ' saved — call `recipe_search` for full bodies):';
    $titles = [];
    foreach ($recipes as $r) {
        $titles[] = (string)$r['title'];
    }
    foreach (array_slice($titles, 0, 80) as $t) {
        $lines[] = '  - ' . $t;
    }
    if (count($titles) > 80) {
        $lines[] = '  …and ' . (count($titles) - 80) . ' more (use `recipe_search` to find any of them).';
    }
    return implode("\n", $lines);
}

/** Compact "what we know about this user" block from ai_memories. */
function ai_profile_context(int $user_id): string {
    if (!class_exists('Memory')) return '';
    try {
        $grouped = Memory::groupedForContext($user_id);
    } catch (Throwable $e) {
        return '';
    }
    if (!$grouped) return '';

    $labels = [
        'diet'      => 'Dietary',
        'allergy'   => 'Allergies',
        'dislike'   => 'Dislikes',
        'like'      => 'Likes',
        'cuisine'   => 'Favorite cuisines',
        'household' => 'Household',
        'equipment' => 'Equipment',
        'skill'     => 'Skill / experience',
        'schedule'  => 'Schedule',
        'goal'      => 'Goals',
        'other'     => 'Other notes',
    ];
    $lines = ['# About this user (remembered preferences)'];
    foreach ($labels as $cat => $label) {
        if (empty($grouped[$cat])) continue;
        $facts = array_slice(array_unique($grouped[$cat]), 0, 12);
        $lines[] = '- ' . $label . ': ' . implode('; ', $facts);
    }
    return count($lines) > 1 ? implode("\n", $lines) : '';
}

/** Recent cooking history block. */
function ai_cooking_context(int $user_id): string {
    if (!class_exists('CookingLog')) return '';
    try {
        $h = CookingLog::highlights($user_id, 6);
    } catch (Throwable $e) {
        return '';
    }
    $loved  = $h['loved']  ?? [];
    $recent = $h['recent'] ?? [];
    if (!$loved && !$recent) return '';

    $lines = ['# Cooking history'];
    if ($recent) {
        $lines[] = 'Recently cooked:';
        foreach (array_slice($recent, 0, 6) as $r) {
            $rating = $r['rating'] ? str_repeat('★', (int)$r['rating']) : '';
            $lines[] = sprintf('  - %s (%s)%s',
                $r['recipe_title'] ?: 'untitled',
                substr((string)$r['cooked_at'], 0, 10),
                $rating ? ' ' . $rating : ''
            );
        }
    }
    if ($loved) {
        $lines[] = 'Highest-rated dishes:';
        foreach (array_slice($loved, 0, 5) as $r) {
            $lines[] = sprintf('  - %s (avg %.1f over %d cooks)',
                $r['recipe_title'] ?: 'untitled',
                (float)$r['avg_rating'],
                (int)$r['times']
            );
        }
    }
    return implode("\n", $lines);
}

/**
 * Full context: profile + cooking + kitchen. Used by chat, suggestions,
 * planning, recipe-from-idea — anything that benefits from personalization.
 */
function ai_full_context(int $user_id): string {
    $parts = array_filter([
        ai_profile_context($user_id),
        ai_cooking_context($user_id),
        ai_kitchen_context($user_id),
    ], fn($s) => trim((string)$s) !== '');
    return implode("\n\n", $parts);
}

/**
 * Normalise the per-page `window_context` payload sent by the client.
 * Filters out unsafe shapes; keeps only fields we actively use.
 *
 * @param mixed $raw  whatever arrived in the request body
 * @return array{
 *   page:string, recipe_id:?int, selection_text:string,
 *   filters:array<string,mixed>, visible_ids:int[]
 * }
 */
function ai_normalize_window_context($raw): array {
    $allowedPages = [
        'recipes-index', 'recipes-show', 'recipes-edit', 'recipes-new',
        'recipes-favorites', 'pantry', 'plan', 'shopping', 'chat',
        'favorites', 'add', '',
    ];
    $page = '';
    $recipeId = null;
    $selection = '';
    $filters = [];
    $visible = [];

    if (is_array($raw)) {
        $p = (string)($raw['page'] ?? '');
        if (in_array($p, $allowedPages, true)) $page = $p;

        if (isset($raw['recipe_id']) && $raw['recipe_id'] !== '' && $raw['recipe_id'] !== null) {
            $recipeId = (int)$raw['recipe_id'];
            if ($recipeId <= 0) $recipeId = null;
        }

        $sel = (string)($raw['selection_text'] ?? '');
        if ($sel !== '') {
            $selection = mb_substr(trim($sel), 0, 800);
        }

        if (isset($raw['filters']) && is_array($raw['filters'])) {
            foreach ($raw['filters'] as $k => $v) {
                if (!is_string($k) || $k === '') continue;
                if (mb_strlen($k) > 32) continue;
                if (is_scalar($v)) {
                    $filters[$k] = mb_substr((string)$v, 0, 128);
                } elseif (is_array($v)) {
                    $compact = [];
                    foreach (array_slice($v, 0, 14, true) as $vk => $vv) {
                        if (is_scalar($vv)) {
                            $compact[(string)$vk] = mb_substr((string)$vv, 0, 64);
                        }
                    }
                    if ($compact) $filters[$k] = $compact;
                }
            }
        }

        if (isset($raw['visible_ids']) && is_array($raw['visible_ids'])) {
            foreach (array_slice($raw['visible_ids'], 0, 20) as $id) {
                $n = (int)$id;
                if ($n > 0 && !in_array($n, $visible, true)) $visible[] = $n;
            }
        }
    }

    return [
        'page'           => $page,
        'recipe_id'      => $recipeId,
        'selection_text' => $selection,
        'filters'        => $filters,
        'visible_ids'    => $visible,
    ];
}

/**
 * Re-fetch authoritative data from the DB for whatever the client says it's
 * showing, then build a `# Current view` system block. The model never sees
 * the raw client payload — it sees what the server confirmed exists.
 *
 * IDs in `visible_ids` are filtered to ones owned by $user_id; recipe_id is
 * loaded with full body (ingredients + steps) when present.
 */
function ai_view_context(int $user_id, array $ctx): string {
    if ($ctx['page'] === '' && $ctx['recipe_id'] === null
        && !$ctx['filters'] && !$ctx['visible_ids']
        && $ctx['selection_text'] === '') {
        return '';
    }

    $lines = ['# Current view'];
    if ($ctx['page'] !== '') {
        $lines[] = '- Page: ' . $ctx['page'];
    }

    $filterBits = [];
    foreach ($ctx['filters'] as $k => $v) {
        if (is_array($v)) {
            $inner = [];
            foreach ($v as $vk => $vv) {
                $inner[] = $vk . '=' . $vv;
            }
            if ($inner) $filterBits[] = $k . '={' . implode(', ', $inner) . '}';
        } else {
            $filterBits[] = $k . '=' . $v;
        }
    }
    if ($filterBits) {
        $lines[] = '- Filters / view state: ' . implode('; ', $filterBits);
    }

    if ($ctx['selection_text'] !== '') {
        $sel = $ctx['selection_text'];
        $lines[] = '- User has the following text selected on the page:';
        $lines[] = '  <selection>' . $sel . '</selection>';
    }

    if ($ctx['recipe_id'] !== null) {
        try {
            $recipe = Recipe::findFull($user_id, $ctx['recipe_id']);
        } catch (Throwable $e) {
            $recipe = null;
        }
        if ($recipe) {
            $lines[] = '- Currently viewing recipe #' . (int)$recipe['id'] . ': "' . $recipe['title'] . '"';
            $meta = [];
            if (!empty($recipe['cuisine']))  $meta[] = $recipe['cuisine'];
            if (!empty($recipe['time_minutes'])) $meta[] = (int)$recipe['time_minutes'] . 'm';
            if (!empty($recipe['servings'])) $meta[] = 'serves ' . (int)$recipe['servings'];
            if (!empty($recipe['difficulty'])) $meta[] = $recipe['difficulty'];
            if ($meta) $lines[] = '  meta: ' . implode(' · ', $meta);
            if (!empty($recipe['summary'])) {
                $lines[] = '  summary: ' . mb_substr((string)$recipe['summary'], 0, 240);
            }
            if (!empty($recipe['ingredients'])) {
                $lines[] = '  ingredients:';
                foreach ($recipe['ingredients'] as $ing) {
                    $qty  = isset($ing['qty']) && $ing['qty'] !== null ? rtrim(rtrim((string)$ing['qty'], '0'), '.') : '';
                    $unit = (string)($ing['unit'] ?? '');
                    $name = (string)($ing['name'] ?? '');
                    $bits = array_filter([$qty, $unit, $name], fn($s) => $s !== '');
                    if ($bits) $lines[] = '    - ' . implode(' ', $bits);
                }
            }
            if (!empty($recipe['steps'])) {
                $lines[] = '  steps:';
                foreach ($recipe['steps'] as $i => $s) {
                    $text = is_array($s) ? (string)($s['text'] ?? '') : (string)$s;
                    $lines[] = '    ' . ($i + 1) . '. ' . mb_substr($text, 0, 320);
                }
            }
            if (!empty($recipe['notes'])) {
                $lines[] = '  user notes: ' . mb_substr((string)$recipe['notes'], 0, 240);
            }
        }
    }

    if (($ctx['page'] === 'shopping')) {
        try {
            $items = Shopping::listForUser($user_id);
        } catch (Throwable $e) {
            $items = [];
        }
        if ($items) {
            $lines[] = '- Shopping list (' . count($items) . ' items):';
            foreach (array_slice($items, 0, 40) as $it) {
                $check = ((int)($it['checked'] ?? 0) === 1) ? '[x]' : '[ ]';
                $qty = isset($it['qty']) && $it['qty'] !== null ? rtrim(rtrim((string)$it['qty'], '0'), '.') : '';
                $unit = (string)($it['unit'] ?? '');
                $name = (string)($it['name'] ?? '');
                $src  = (string)($it['source_label'] ?? '');
                $bits = array_filter([$qty, $unit, $name], fn($s) => $s !== '');
                $line = '  ' . $check . ' ' . implode(' ', $bits);
                if ($src !== '' && $src !== 'manual') $line .= ' (from ' . $src . ')';
                $lines[] = $line;
            }
            if (count($items) > 40) {
                $lines[] = '  …and ' . (count($items) - 40) . ' more.';
            }
        }
    }

    if ($ctx['page'] === 'plan') {
        try {
            $byDay = Plan::forUser($user_id);
        } catch (Throwable $e) {
            $byDay = [];
        }
        $planLines = [];
        foreach ($byDay as $day => $entry) {
            if ($entry) {
                $planLines[] = $day . ': ' . $entry['title'] . ' (#' . (int)$entry['id'] . ')';
            } else {
                $planLines[] = $day . ': (empty)';
            }
        }
        if ($planLines) {
            $lines[] = '- This week\'s plan:';
            foreach ($planLines as $l) $lines[] = '  ' . $l;
        }
    }

    if ($ctx['visible_ids'] && $ctx['recipe_id'] === null
        && !in_array($ctx['page'], ['plan', 'shopping'], true)) {
        // Re-fetch each id and confirm ownership before naming them to the model.
        $ids = $ctx['visible_ids'];
        $pdo = db();
        $place = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare(
            "SELECT id, title, cuisine, time_minutes, difficulty
               FROM recipes
              WHERE user_id = ? AND id IN ($place)
              ORDER BY FIELD(id, $place)"
        );
        $params = array_merge([$user_id], $ids, $ids);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        if ($rows) {
            $lines[] = '- Recipes currently visible on the page (' . count($rows) . '):';
            foreach ($rows as $r) {
                $lines[] = sprintf(
                    '  - #%d %s (%s, %dm, %s)',
                    (int)$r['id'], $r['title'],
                    $r['cuisine'] ?: 'no cuisine',
                    (int)$r['time_minutes'], $r['difficulty']
                );
            }
        }
    }

    return count($lines) > 1 ? implode("\n", $lines) : '';
}

/**
 * Anthropic server-side web_search tool definition. The model executes
 * searches transparently — results come back in the same response, no
 * client-side handling needed.
 */
function ai_web_search_tool(int $maxUses = 4): array {
    return [
        'type'     => 'web_search_20250305',
        'name'     => 'web_search',
        'max_uses' => $maxUses,
    ];
}

/**
 * Tool definitions advertised to the chat model so it can take real actions
 * during a conversation (save a memory, add to shopping list, log a cook,
 * etc.). The controller is responsible for executing each tool_use block.
 */
function ai_chat_tools(): array {
    $allowedCats = PANTRY_CATEGORIES;
    return [
        [
            'name'        => 'recipe_search',
            'description' => 'Search the user\'s saved recipe book by free-text query (matches title, cuisine, summary, ingredient names, step text, and notes). Use this whenever the user mentions a saved recipe by partial name or by a remembered ingredient ("that pasta with capers", "my chickpea curry"). Returns up to 8 hits with full bodies (ingredients + steps), so you don\'t need to call recipe_get for those. Prefer this over guessing from the title-only library list.',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'query'   => ['type' => 'string', 'description' => 'Free-text query. Empty string = list any recipes matching the optional filters.'],
                    'cuisine' => ['type' => 'string', 'description' => 'Optional cuisine filter (exact match), e.g. "Italian", "Thai". Omit or use "" to skip.'],
                    'tag'     => ['type' => 'string', 'description' => 'Optional tag filter (exact), e.g. "weeknight". Omit or use "" to skip.'],
                    'time'    => ['type' => 'string', 'enum' => ['', '< 30 min', '30–60 min', '> 60 min'], 'description' => 'Optional total-time bucket.'],
                    'favorites_only' => ['type' => 'boolean', 'description' => 'If true, only return ♥-marked recipes.'],
                    'limit'   => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20, 'description' => 'Max hits to return (default 8).'],
                ],
                'required' => ['query'],
            ],
        ],
        [
            'name'        => 'recipe_get',
            'description' => 'Fetch one of the user\'s saved recipes by id, with full body (ingredients + steps + tags + notes). Use when the user references a recipe id you already know (e.g. from the # Current view block or a prior recipe_search hit) and you need the full body to act on it.',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'description' => 'recipes.id'],
                ],
                'required' => ['id'],
            ],
        ],
        [
            'name'        => 'remember_preference',
            'description' => 'Save a stable fact about the user so it influences future suggestions. Use for dietary needs, allergies, dislikes, favorite cuisines, equipment, household size, skill, goals, etc. Do NOT save transient state like "wants tacos tonight".',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'category' => [
                        'type' => 'string',
                        'enum' => Memory::CATEGORIES,
                        'description' => 'Bucket for the fact.',
                    ],
                    'fact'   => ['type' => 'string', 'description' => 'Short, durable statement (e.g. "vegetarian", "loves Thai food", "no cilantro", "has air fryer").'],
                    'weight' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10, 'description' => 'How important the fact is. Default 5.'],
                ],
                'required' => ['category', 'fact'],
            ],
        ],
        [
            'name'        => 'forget_preference',
            'description' => 'Remove a stored memory by id when the user says it is wrong or no longer applies.',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'description' => 'ai_memories.id to delete.'],
                ],
                'required' => ['id'],
            ],
        ],
        [
            'name'        => 'add_to_shopping_list',
            'description' => 'Add an item to the user\'s shopping list.',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'name' => ['type' => 'string'],
                    'qty'  => ['type' => 'string', 'description' => 'Optional quantity, e.g. "2".'],
                    'unit' => ['type' => 'string', 'description' => 'Optional unit, e.g. "lb".'],
                ],
                'required' => ['name'],
            ],
        ],
        [
            'name'        => 'bulk_add_to_pantry',
            'description' => 'Add many ingredients to the user\'s pantry at once. Use this whenever the user pastes a recipe, fridge dump, grocery haul, photo description, or any list of food items they want stocked. Strip out non-ingredient lines (instructions, headers, prose), normalise each name (lowercase, singular, e.g. "yellow onion", "olive oil"), and assign a category from the allowed set. Deduplicate. ALWAYS call once with confirm=false first to return a preview to the user, then call again with the same items and confirm=true ONLY after the user explicitly says yes.',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'items' => [
                        'type' => 'array',
                        'minItems' => 1,
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'name'     => ['type' => 'string', 'description' => 'Concise lowercase singular ingredient (e.g. "yellow onion").'],
                                'qty'      => ['type' => ['string', 'null'], 'description' => 'Optional quantity as a string, e.g. "2" or "1.5".'],
                                'unit'     => ['type' => 'string', 'description' => 'Optional unit, e.g. "lb", "cup". Empty string if none.'],
                                'category' => ['type' => 'string', 'enum' => $allowedCats, 'description' => 'Pantry aisle/bucket.'],
                                'in_stock' => ['type' => 'boolean', 'description' => 'true if the user has it on hand now (default true). Use false if they want it on the want-list.'],
                            ],
                            'required' => ['name', 'category'],
                        ],
                    ],
                    'confirm' => ['type' => 'boolean', 'description' => 'false = preview only (return parsed list, write nothing). true = actually write to pantry. Default false.'],
                ],
                'required' => ['items'],
            ],
        ],
        [
            'name'        => 'set_meal_plan_day',
            'description' => 'Assign one of the user\'s recipes to a specific day of the meal plan.',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'day'       => ['type' => 'string', 'enum' => ['Mon','Tue','Wed','Thu','Fri','Sat','Sun']],
                    'recipe_id' => ['type' => 'integer'],
                ],
                'required' => ['day', 'recipe_id'],
            ],
        ],
        [
            'name'        => 'save_recipe_to_book',
            'description' => 'Save a complete recipe to the user\'s personal recipe book. Use this AFTER the user agrees to add a recipe you\'ve discovered (e.g. via web_search). Always call once with confirm=false to show the user a clean preview, then again with confirm=true ONLY after they say yes. Provide the FULL structured recipe — do not leave fields blank.',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'recipe' => [
                        'type' => 'object',
                        'properties' => [
                            'title'        => ['type' => 'string'],
                            'cuisine'      => ['type' => 'string'],
                            'summary'      => ['type' => 'string', 'description' => 'One-line tagline.'],
                            'time_minutes' => ['type' => 'integer', 'minimum' => 1],
                            'servings'     => ['type' => 'integer', 'minimum' => 1],
                            'difficulty'   => ['type' => 'string', 'enum' => ['Easy', 'Medium', 'Hard']],
                            'glyph'        => ['type' => 'string', 'description' => 'A single emoji that fits the dish.'],
                            'color'        => ['type' => 'string', 'enum' => ['mint','butter','peach','lilac','sky','blush','lime','coral']],
                            'tags'         => ['type' => 'array', 'items' => ['type' => 'string']],
                            'ingredients'  => [
                                'type' => 'array',
                                'minItems' => 1,
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'qty'   => ['type' => ['string', 'null']],
                                        'unit'  => ['type' => 'string'],
                                        'name'  => ['type' => 'string'],
                                        'aisle' => ['type' => 'string'],
                                    ],
                                    'required' => ['name'],
                                ],
                            ],
                            'steps' => ['type' => 'array', 'minItems' => 1, 'items' => ['type' => 'string']],
                        ],
                        'required' => ['title', 'ingredients', 'steps'],
                    ],
                    'source_url' => ['type' => 'string', 'description' => 'Where the recipe was discovered (web URL, blog, etc.). Stored in the recipe notes.'],
                    'confirm'    => ['type' => 'boolean', 'description' => 'false = preview only. true = actually save. Default false.'],
                ],
                'required' => ['recipe'],
            ],
        ],
        [
            'name'        => 'log_cooked_recipe',
            'description' => 'Record that the user cooked a recipe (optionally with a 1–5 rating + notes). Call when the user says they made/cooked/tried a dish.',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'recipe_id'    => ['type' => 'integer', 'description' => 'Optional id of an existing library recipe.'],
                    'recipe_title' => ['type' => 'string'],
                    'rating'       => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5],
                    'notes'        => ['type' => 'string'],
                ],
                'required' => ['recipe_title'],
            ],
        ],
    ];
}

/** Token usage tally for a response (for surfacing to the UI). */
function ai_usage(array $response): array {
    $u = $response['usage'] ?? [];
    return [
        'input_tokens'              => (int)($u['input_tokens']               ?? 0),
        'output_tokens'             => (int)($u['output_tokens']              ?? 0),
        'cache_creation_input_tokens' => (int)($u['cache_creation_input_tokens'] ?? 0),
        'cache_read_input_tokens'   => (int)($u['cache_read_input_tokens']    ?? 0),
    ];
}
