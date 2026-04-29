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

    /** Append a message. Returns the inserted id. */
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
}
