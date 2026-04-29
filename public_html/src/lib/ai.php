<?php
// public_html/src/lib/ai.php
// Thin wrapper around the Anthropic Messages API.
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
