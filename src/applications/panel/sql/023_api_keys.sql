-- Core step 1f (docs/panel-architektura.md chapter 4 "API", chapter 5): API keys with a scope of permissions (never
-- more than their author has at the moment of the call), revocable, every call logged, rate limited.

CREATE TABLE IF NOT EXISTS `{p}api_key` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `client_id` INT UNSIGNED NOT NULL,              -- author / owner; the key acts as him
  `name` VARCHAR(100) NOT NULL,                   -- what uses it ("Testovací prípravok LedBoard", "CI GitHub")
  `prefix` VARCHAR(16) NOT NULL,                  -- public part of the key (shown in lists, logs)
  `hash` CHAR(64) NOT NULL,                       -- SHA-256 of the secret part; the key itself is shown only once
  `permissions` TEXT NOT NULL,                    -- JSON list of permission keys
  `created_at` DATETIME NOT NULL,
  `expires_at` DATE NULL,
  `last_used_at` DATETIME NULL,
  `last_ip` VARCHAR(45) NOT NULL DEFAULT '',
  `revoked_at` DATETIME NULL,
  `revoked_by` VARCHAR(128) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `prefix` (`prefix`),
  KEY `owner` (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{p}api_log` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `key_id` INT UNSIGNED NOT NULL DEFAULT 0,       -- 0 = unknown / invalid key
  `method` VARCHAR(8) NOT NULL,
  `path` VARCHAR(255) NOT NULL,
  `status` SMALLINT UNSIGNED NOT NULL,
  `ip` VARCHAR(45) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `key_time` (`key_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
