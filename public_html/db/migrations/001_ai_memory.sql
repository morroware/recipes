-- public_html/db/migrations/001_ai_memory.sql
-- Adds AI memory + conversation + cooking-log tables.
-- Safe to run on an existing install: every CREATE uses IF NOT EXISTS.

SET NAMES utf8mb4;

-- Persistent facts the assistant has learned about the user
-- (dietary needs, cuisine preferences, allergies, family info, equipment, etc.)
CREATE TABLE IF NOT EXISTS ai_memories (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     INT UNSIGNED NOT NULL,
  category    ENUM(
    'diet','allergy','dislike','like','cuisine',
    'household','equipment','skill','schedule','goal','other'
  ) NOT NULL DEFAULT 'other',
  fact        VARCHAR(512) NOT NULL,
  source      ENUM('user','assistant','system') NOT NULL DEFAULT 'assistant',
  weight      TINYINT UNSIGNED NOT NULL DEFAULT 5,
  pinned      TINYINT(1) NOT NULL DEFAULT 0,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ai_memories_user_cat (user_id, category),
  KEY idx_ai_memories_user_weight (user_id, weight),
  CONSTRAINT fk_ai_memories_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Chat sessions
CREATE TABLE IF NOT EXISTS ai_conversations (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     INT UNSIGNED NOT NULL,
  title       VARCHAR(160) NOT NULL DEFAULT 'New conversation',
  pinned      TINYINT(1) NOT NULL DEFAULT 0,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ai_conversations_user (user_id, updated_at),
  CONSTRAINT fk_ai_conversations_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_messages (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  conversation_id INT UNSIGNED NOT NULL,
  role            ENUM('user','assistant','system') NOT NULL,
  content         MEDIUMTEXT NOT NULL,
  tokens_in       INT UNSIGNED NULL,
  tokens_out      INT UNSIGNED NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ai_messages_conv (conversation_id, id),
  CONSTRAINT fk_ai_messages_conv FOREIGN KEY (conversation_id) REFERENCES ai_conversations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cooking history (which recipes the user has actually made)
CREATE TABLE IF NOT EXISTS cooking_log (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     INT UNSIGNED NOT NULL,
  recipe_id   INT UNSIGNED NULL,
  recipe_title VARCHAR(160) NOT NULL DEFAULT '',
  cooked_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  rating      TINYINT UNSIGNED NULL,
  notes       TEXT NULL,
  PRIMARY KEY (id),
  KEY idx_cooking_log_user_date (user_id, cooked_at),
  KEY idx_cooking_log_user_recipe (user_id, recipe_id),
  CONSTRAINT fk_cooking_log_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_cooking_log_recipe FOREIGN KEY (recipe_id) REFERENCES recipes(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
