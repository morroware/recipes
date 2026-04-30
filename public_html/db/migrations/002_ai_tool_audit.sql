-- public_html/db/migrations/002_ai_tool_audit.sql
-- Adds an audit log of every assistant tool call. Captures input, result,
-- and an optional reversible `undo_payload` so the chat can offer one-click
-- "Undo" for simple actions (favorite toggle, plan day, shopping check, etc).
--
-- Safe to run on an existing install: CREATE TABLE IF NOT EXISTS, no DDL on
-- existing tables. cPanel-friendly.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS ai_tool_audit (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id         INT UNSIGNED NOT NULL,
  conversation_id INT UNSIGNED NULL,
  tool            VARCHAR(64) NOT NULL,
  input_json      MEDIUMTEXT NULL,
  result_json     MEDIUMTEXT NULL,
  undo_token      VARCHAR(32) NULL,
  undo_payload    MEDIUMTEXT NULL,
  ok              TINYINT(1) NOT NULL DEFAULT 1,
  reversed_at     DATETIME NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ai_tool_audit_user_date (user_id, created_at),
  KEY idx_ai_tool_audit_undo (user_id, undo_token),
  CONSTRAINT fk_ai_tool_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_ai_tool_audit_conv FOREIGN KEY (conversation_id) REFERENCES ai_conversations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
