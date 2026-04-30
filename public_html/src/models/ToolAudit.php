<?php
// public_html/src/models/ToolAudit.php
// Audit log of every assistant tool call. Optionally stores an undo_payload
// so the chat surface can offer one-click "Undo" on simple actions.

declare(strict_types=1);

class ToolAudit {

    /** Append an audit row. Returns the inserted id. */
    public static function record(
        int $user_id,
        ?int $conversation_id,
        string $tool,
        array $input,
        array $result,
        bool $ok = true,
        ?string $undo_token = null,
        ?array $undo_payload = null
    ): int {
        $stmt = db()->prepare(
            'INSERT INTO ai_tool_audit
                (user_id, conversation_id, tool, input_json, result_json,
                 undo_token, undo_payload, ok, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([
            $user_id,
            $conversation_id,
            mb_substr($tool, 0, 64),
            json_encode($input,  JSON_UNESCAPED_UNICODE),
            json_encode($result, JSON_UNESCAPED_UNICODE),
            $undo_token,
            $undo_payload === null ? null : json_encode($undo_payload, JSON_UNESCAPED_UNICODE),
            $ok ? 1 : 0,
        ]);
        return (int)db()->lastInsertId();
    }

    /** Find a not-yet-reversed undo entry by token, scoped to the user. */
    public static function findByUndoToken(int $user_id, string $token): ?array {
        if ($token === '') return null;
        $stmt = db()->prepare(
            'SELECT id, tool, input_json, result_json, undo_token, undo_payload,
                    ok, reversed_at, created_at
               FROM ai_tool_audit
              WHERE user_id = ? AND undo_token = ? AND reversed_at IS NULL
              LIMIT 1'
        );
        $stmt->execute([$user_id, $token]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Mark an audit row as reversed (so the same token can't be replayed). */
    public static function markReversed(int $user_id, int $id): bool {
        $stmt = db()->prepare(
            'UPDATE ai_tool_audit
                SET reversed_at = NOW()
              WHERE id = ? AND user_id = ? AND reversed_at IS NULL'
        );
        $stmt->execute([$id, $user_id]);
        return $stmt->rowCount() > 0;
    }

    /** Short token for undo links. ~8 chars, alphanumeric, URL-safe. */
    public static function newToken(): string {
        return rtrim(strtr(base64_encode(random_bytes(6)), '+/', '-_'), '=');
    }
}
