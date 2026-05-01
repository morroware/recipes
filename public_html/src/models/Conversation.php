<?php
// public_html/src/models/Conversation.php
// AI chat sessions + messages.

declare(strict_types=1);

class Conversation {

    public static function listForUser(int $user_id, int $limit = 50): array {
        $stmt = db()->prepare(
            'SELECT c.id, c.title, c.pinned, c.created_at, c.updated_at,
                    (SELECT COUNT(*) FROM ai_messages m WHERE m.conversation_id = c.id) AS message_count
               FROM ai_conversations c
              WHERE c.user_id = ?
              ORDER BY c.pinned DESC, c.updated_at DESC
              LIMIT ' . max(1, $limit)
        );
        $stmt->execute([$user_id]);
        return $stmt->fetchAll();
    }

    public static function findById(int $user_id, int $id): ?array {
        $stmt = db()->prepare(
            'SELECT id, title, pinned, created_at, updated_at
               FROM ai_conversations
              WHERE id = ? AND user_id = ? LIMIT 1'
        );
        $stmt->execute([$id, $user_id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function create(int $user_id, string $title = 'New conversation'): array {
        $title = trim($title);
        if ($title === '') $title = 'New conversation';
        $title = mb_substr($title, 0, 160);
        $stmt = db()->prepare(
            'INSERT INTO ai_conversations (user_id, title) VALUES (?, ?)'
        );
        $stmt->execute([$user_id, $title]);
        return self::findById($user_id, (int)db()->lastInsertId());
    }

    public static function rename(int $user_id, int $id, string $title): ?array {
        $title = trim($title);
        if ($title === '') return self::findById($user_id, $id);
        $stmt = db()->prepare(
            'UPDATE ai_conversations SET title = ? WHERE id = ? AND user_id = ?'
        );
        $stmt->execute([mb_substr($title, 0, 160), $id, $user_id]);
        return self::findById($user_id, $id);
    }

    public static function delete(int $user_id, int $id): bool {
        $stmt = db()->prepare('DELETE FROM ai_conversations WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $user_id]);
        return $stmt->rowCount() > 0;
    }

    public static function touch(int $user_id, int $id): void {
        $stmt = db()->prepare(
            'UPDATE ai_conversations SET updated_at = NOW() WHERE id = ? AND user_id = ?'
        );
        $stmt->execute([$id, $user_id]);
    }

    /** Append a plain-text message. Returns the inserted id. */
    public static function addMessage(
        int $conversation_id,
        string $role,
        string $content,
        ?int $tokens_in = null,
        ?int $tokens_out = null
    ): int {
        if (!in_array($role, ['user', 'assistant', 'system'], true)) {
            throw new InvalidArgumentException('bad_role');
        }
        $stmt = db()->prepare(
            'INSERT INTO ai_messages (conversation_id, role, content, tokens_in, tokens_out)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$conversation_id, $role, $content, $tokens_in, $tokens_out]);
        return (int)db()->lastInsertId();
    }

    /**
     * Append a structured message (Anthropic content-blocks array). Used to
     * persist assistant messages that contain `tool_use` blocks, and the
     * matching user-role `tool_result` blocks. Encoded as JSON in `content`.
     *
     * Plain-text messages stay plain text via addMessage(); only tool-bearing
     * turns take this path.
     */
    public static function addStructuredMessage(
        int $conversation_id,
        string $role,
        array $content_blocks,
        ?int $tokens_in = null,
        ?int $tokens_out = null
    ): int {
        if (!in_array($role, ['user', 'assistant', 'system'], true)) {
            throw new InvalidArgumentException('bad_role');
        }
        $json = json_encode($content_blocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new InvalidArgumentException('content_encode_failed');
        }
        $stmt = db()->prepare(
            'INSERT INTO ai_messages (conversation_id, role, content, tokens_in, tokens_out)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$conversation_id, $role, $json, $tokens_in, $tokens_out]);
        return (int)db()->lastInsertId();
    }

    /** Newest-last list of messages, optionally limited to the last N. */
    public static function messages(int $conversation_id, ?int $limit = null): array {
        if ($limit !== null) {
            $stmt = db()->prepare(
                'SELECT * FROM (
                    SELECT id, role, content, tokens_in, tokens_out, created_at
                      FROM ai_messages
                     WHERE conversation_id = ?
                     ORDER BY id DESC
                     LIMIT ' . max(1, $limit) . '
                 ) AS recent
                 ORDER BY id ASC'
            );
        } else {
            $stmt = db()->prepare(
                'SELECT id, role, content, tokens_in, tokens_out, created_at
                   FROM ai_messages
                  WHERE conversation_id = ?
                  ORDER BY id ASC'
            );
        }
        $stmt->execute([$conversation_id]);
        return $stmt->fetchAll();
    }

    /**
     * Heuristic: a string is a structured content payload if it begins with
     * `[` (a JSON array of content blocks). Plain-text messages never start
     * with `[` after we trim — they're prose or markdown.
     */
    public static function isStructuredContent(string $content): bool {
        $trim = ltrim($content);
        return $trim !== '' && $trim[0] === '[';
    }

    /**
     * Decode a stored content payload back into the shape the Messages API
     * expects: either a plain string (legacy / text-only messages) or a list
     * of content blocks (tool_use / tool_result / mixed).
     *
     * @return string|array
     */
    public static function decodeContent(string $content) {
        if (!self::isStructuredContent($content)) return $content;
        $decoded = json_decode($content, true);
        return is_array($decoded) ? $decoded : $content;
    }

    /**
     * Pull just the human-readable text out of a stored content payload.
     * Used by the chat UI so structured assistant turns render as "Sprout
     * said …" without the raw tool_use JSON leaking on screen.
     */
    public static function extractText(string $content): string {
        if (!self::isStructuredContent($content)) return $content;
        $decoded = json_decode($content, true);
        if (!is_array($decoded)) return '';
        $parts = [];
        foreach ($decoded as $block) {
            if (!is_array($block)) continue;
            if (($block['type'] ?? '') === 'text' && isset($block['text'])) {
                $parts[] = (string)$block['text'];
            }
        }
        return trim(implode("\n", $parts));
    }

    /**
     * Messages shaped for an Anthropic API request: role + content (string or
     * blocks array). Skips `system` messages since the controller composes
     * its own system prompt.
     *
     * Truncation safety: when $limit cuts in the middle of a tool sequence
     * (a tool_use whose tool_result is in the kept window, or a leading
     * orphan tool_result), the API would reject the call. We trim leading
     * messages until we land on the start of a clean "turn" — a plain user
     * text message — so the request always alternates correctly.
     */
    public static function messagesForApi(int $conversation_id, ?int $limit = null): array {
        $rows = self::messages($conversation_id, $limit);

        // Drop any leading rows that aren't a plain user-text message. This
        // guarantees we never send an orphan tool_result, an assistant-first
        // message, or a system row to the API.
        while ($rows) {
            $head = $rows[0];
            $role = (string)($head['role'] ?? '');
            $raw  = (string)($head['content'] ?? '');
            if ($role === 'user' && !self::isStructuredContent($raw)) break;
            array_shift($rows);
        }

        $out = [];
        foreach ($rows as $m) {
            if (!in_array($m['role'], ['user', 'assistant'], true)) continue;
            $out[] = ['role' => $m['role'], 'content' => self::decodeContent((string)$m['content'])];
        }
        return $out;
    }

    /**
     * Messages shaped for the chat UI: only conversational lines (user prose,
     * assistant prose). Tool-result user messages and tool-only assistant
     * turns are filtered out so the bubble log stays clean.
     *
     * Returns rows with `id`, `role`, `content` (text-only), `created_at`.
     */
    public static function messagesForDisplay(int $conversation_id): array {
        $rows = self::messages($conversation_id);
        $out = [];
        foreach ($rows as $m) {
            $role = (string)$m['role'];
            if (!in_array($role, ['user', 'assistant'], true)) continue;
            $raw  = (string)$m['content'];
            $isStructured = self::isStructuredContent($raw);
            // Tool-result user messages: skip from display.
            if ($role === 'user' && $isStructured) continue;
            $text = $isStructured ? self::extractText($raw) : $raw;
            // Tool-only assistant turns (no text block) are interstitial — skip.
            if ($role === 'assistant' && $isStructured && $text === '') continue;
            $out[] = [
                'id'         => (int)$m['id'],
                'role'       => $role,
                'content'    => $text,
                'created_at' => $m['created_at'] ?? null,
            ];
        }
        return $out;
    }
}
