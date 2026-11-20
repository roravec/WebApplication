-- Framework tables of the panel (same as sql/schema.sql of the framework): users (client), API tokens, log, texts.
-- They must exist before 001 (which reads {p}client). On the dev server they came from dev/seed.php; on a fresh
-- hosting nothing created them (first deploy 2026-11-20 → 500), so the panel creates them itself now.

CREATE TABLE IF NOT EXISTS `{p}client` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `identifier` VARCHAR(191) NOT NULL,
  `secret_hash` VARCHAR(255) NOT NULL,
  `name` VARCHAR(128) NOT NULL DEFAULT '',
  `rights` INT NOT NULL DEFAULT 0,
  `status` TINYINT NOT NULL DEFAULT 1,
  `type` TINYINT NOT NULL DEFAULT 0,
  `last_seen` DATETIME NULL,
  `created_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `identifier` (`identifier`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{p}token` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `userid` INT UNSIGNED NOT NULL,
  `token` VARCHAR(128) NOT NULL,
  `issued_at` DATETIME NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `revoked` TINYINT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `token` (`token`),
  KEY `userid` (`userid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{p}log` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `timestamp` DATETIME NOT NULL,
  `userId` INT NOT NULL DEFAULT 0,
  `clientIp` VARCHAR(45) NOT NULL DEFAULT '',
  `action` VARCHAR(64) NOT NULL DEFAULT '',
  `targetType` VARCHAR(64) NOT NULL DEFAULT '',
  `targetId` INT NOT NULL DEFAULT 0,
  `status` INT NOT NULL DEFAULT 0,
  `message` TEXT,
  PRIMARY KEY (`id`),
  KEY `timestamp` (`timestamp`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{p}text` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `appid` VARCHAR(64) NOT NULL,
  `language` VARCHAR(8) NOT NULL,
  `identifier` VARCHAR(128) NOT NULL,
  `text` TEXT,
  PRIMARY KEY (`id`),
  UNIQUE KEY `app_lang_ident` (`appid`, `language`, `identifier`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
