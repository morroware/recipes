-- Personal Recipe Book — schema.sql
-- MySQL 8.0+ / MariaDB 10.6+. utf8mb4 throughout.
-- Designed with user_id FKs on every owned row so multi-user is a future flip.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS ai_messages;
DROP TABLE IF EXISTS ai_conversations;
DROP TABLE IF EXISTS ai_memories;
DROP TABLE IF EXISTS cooking_log;
DROP TABLE IF EXISTS user_settings;
DROP TABLE IF EXISTS meal_plan;
DROP TABLE IF EXISTS shopping_items;
DROP TABLE IF EXISTS pantry_items;
DROP TABLE IF EXISTS steps;
DROP TABLE IF EXISTS ingredients;
DROP TABLE IF EXISTS recipe_tags;
DROP TABLE IF EXISTS recipes;
DROP TABLE IF EXISTS users;

SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------------
CREATE TABLE users (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  email         VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  display_name  VARCHAR(100) NOT NULL DEFAULT '',
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
CREATE TABLE recipes (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id      INT UNSIGNED NOT NULL,
  slug         VARCHAR(64)  NOT NULL,
  title        VARCHAR(160) NOT NULL,
  cuisine      VARCHAR(64)  NOT NULL DEFAULT '',
  summary      TEXT,
  time_minutes INT UNSIGNED NOT NULL DEFAULT 0,
  servings     INT UNSIGNED NOT NULL DEFAULT 1,
  difficulty   ENUM('Easy','Medium','Hard') NOT NULL DEFAULT 'Easy',
  glyph        VARCHAR(8)   NOT NULL DEFAULT '',
  color        ENUM('mint','butter','peach','lilac','sky','blush','lime','coral') NOT NULL DEFAULT 'mint',
  photo_url    VARCHAR(512) NULL,
  notes        TEXT,
  is_favorite  TINYINT(1) NOT NULL DEFAULT 0,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_recipes_user_slug (user_id, slug),
  KEY idx_recipes_user_title (user_id, title),
  KEY idx_recipes_user_fav (user_id, is_favorite),
  CONSTRAINT fk_recipes_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
CREATE TABLE recipe_tags (
  recipe_id INT UNSIGNED NOT NULL,
  tag       VARCHAR(64)  NOT NULL,
  PRIMARY KEY (recipe_id, tag),
  KEY idx_recipe_tags_tag (tag),
  CONSTRAINT fk_recipe_tags_recipe FOREIGN KEY (recipe_id) REFERENCES recipes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
CREATE TABLE ingredients (
  id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  recipe_id INT UNSIGNED NOT NULL,
  position  INT UNSIGNED NOT NULL DEFAULT 0,
  qty       DECIMAL(10,3) NULL,
  unit      VARCHAR(16) NOT NULL DEFAULT '',
  name      VARCHAR(128) NOT NULL,
  aisle     ENUM('Produce','Pantry','Dairy','Meat & Fish','Bakery','Frozen','Spices','Other') NOT NULL DEFAULT 'Other',
  PRIMARY KEY (id),
  KEY idx_ingredients_recipe_pos (recipe_id, position),
  KEY idx_ingredients_recipe_name (recipe_id, name),
  CONSTRAINT fk_ingredients_recipe FOREIGN KEY (recipe_id) REFERENCES recipes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
CREATE TABLE steps (
  id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  recipe_id INT UNSIGNED NOT NULL,
  position  INT UNSIGNED NOT NULL DEFAULT 0,
  text      TEXT NOT NULL,
  PRIMARY KEY (id),
  KEY idx_steps_recipe_pos (recipe_id, position),
  CONSTRAINT fk_steps_recipe FOREIGN KEY (recipe_id) REFERENCES recipes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
CREATE TABLE pantry_items (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id        INT UNSIGNED NOT NULL,
  name           VARCHAR(128) NOT NULL,
  key_normalized VARCHAR(128) NOT NULL,
  in_stock       TINYINT(1) NOT NULL DEFAULT 1,
  qty            DECIMAL(10,3) NULL,
  unit           VARCHAR(16) NOT NULL DEFAULT '',
  category       ENUM('Produce','Dairy','Meat & Fish','Bakery','Pantry','Spices','Frozen','Other') NOT NULL DEFAULT 'Other',
  last_bought    DATETIME NULL,
  purchase_count INT UNSIGNED NOT NULL DEFAULT 0,
  added_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_pantry_user_key (user_id, key_normalized),
  KEY idx_pantry_user_category (user_id, category),
  KEY idx_pantry_user_count (user_id, purchase_count),
  CONSTRAINT fk_pantry_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
CREATE TABLE shopping_items (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id          INT UNSIGNED NOT NULL,
  name             VARCHAR(128) NOT NULL,
  qty              DECIMAL(10,3) NULL,
  unit             VARCHAR(16) NOT NULL DEFAULT '',
  source_recipe_id INT UNSIGNED NULL,
  source_label     VARCHAR(128) NOT NULL DEFAULT '',
  aisle            VARCHAR(32) NOT NULL DEFAULT 'Other',
  checked          TINYINT(1) NOT NULL DEFAULT 0,
  position         INT UNSIGNED NOT NULL DEFAULT 0,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_shopping_user_checked (user_id, checked),
  CONSTRAINT fk_shopping_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_shopping_recipe FOREIGN KEY (source_recipe_id) REFERENCES recipes(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
CREATE TABLE meal_plan (
  id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id   INT UNSIGNED NOT NULL,
  day       ENUM('Mon','Tue','Wed','Thu','Fri','Sat','Sun') NOT NULL,
  recipe_id INT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_plan_user_day (user_id, day),
  CONSTRAINT fk_plan_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_plan_recipe FOREIGN KEY (recipe_id) REFERENCES recipes(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- ---------------------------------------------------------------------------
-- AI: persistent facts the assistant has learned about the user.
CREATE TABLE ai_memories (
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

-- ---------------------------------------------------------------------------
-- AI: chat sessions.
CREATE TABLE ai_conversations (
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

-- ---------------------------------------------------------------------------
CREATE TABLE ai_messages (
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

-- ---------------------------------------------------------------------------
-- Cooking history: which recipes the user has actually made (and how it went).
CREATE TABLE cooking_log (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id      INT UNSIGNED NOT NULL,
  recipe_id    INT UNSIGNED NULL,
  recipe_title VARCHAR(160) NOT NULL DEFAULT '',
  cooked_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  rating       TINYINT UNSIGNED NULL,
  notes        TEXT NULL,
  PRIMARY KEY (id),
  KEY idx_cooking_log_user_date (user_id, cooked_at),
  KEY idx_cooking_log_user_recipe (user_id, recipe_id),
  CONSTRAINT fk_cooking_log_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_cooking_log_recipe FOREIGN KEY (recipe_id) REFERENCES recipes(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
CREATE TABLE user_settings (
  user_id        INT UNSIGNED NOT NULL,
  density        ENUM('compact','cozy','airy')           NOT NULL DEFAULT 'cozy',
  theme          ENUM('rainbow','sunset','ocean','garden') NOT NULL DEFAULT 'rainbow',
  mode           ENUM('light','dark')                    NOT NULL DEFAULT 'light',
  font_pair      ENUM('default','serif','mono','rounded') NOT NULL DEFAULT 'default',
  radius         ENUM('sharp','default','round')          NOT NULL DEFAULT 'default',
  card_style     ENUM('mix','photo-only','glyph-only')    NOT NULL DEFAULT 'mix',
  sticker_rotate TINYINT(1) NOT NULL DEFAULT 1,
  dot_grid       TINYINT(1) NOT NULL DEFAULT 1,
  units          ENUM('metric','imperial') NOT NULL DEFAULT 'metric',
  PRIMARY KEY (user_id),
  CONSTRAINT fk_user_settings_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
