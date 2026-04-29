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
    $lines[] = 'Existing recipe library (' . count($recipes) . '):';
    foreach (array_slice($recipes, 0, 40) as $r) {
        $tags = isset($r['tags']) && is_array($r['tags']) ? implode(',', $r['tags']) : '';
        $lines[] = sprintf(
            '  - %s (%s, %dm, %s)%s',
            $r['title'],
            $r['cuisine'] ?: 'no cuisine',
            (int)$r['time_minutes'],
            $r['difficulty'],
            $tags ? ' #' . $tags : ''
        );
    }
    if (count($recipes) > 40) {
        $lines[] = '  …and ' . (count($recipes) - 40) . ' more.';
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
 * Tool definitions advertised to the chat model so it can take real actions
 * during a conversation (save a memory, add to shopping list, log a cook,
 * etc.). The controller is responsible for executing each tool_use block.
 */
function ai_chat_tools(): array {
    return [
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
