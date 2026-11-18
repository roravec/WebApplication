-- Tables used by the framework, per app. Replace {prefix} with the app's dbPrefix (e.g. ermaweb_).
-- Existing live tables may differ slightly in column types; the framework only relies on the column names.

CREATE TABLE IF NOT EXISTS `{prefix}client` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `identifier` VARCHAR(191) NOT NULL,          -- login name / e-mail / device id
  `secret_hash` VARCHAR(255) NOT NULL,         -- password_hash()
  `name` VARCHAR(128) NOT NULL DEFAULT '',
  `rights` INT NOT NULL DEFAULT 0,             -- larger = stronger
  `status` TINYINT NOT NULL DEFAULT 1,         -- 0 inactive, 1 active
  `type` TINYINT NOT NULL DEFAULT 0,           -- 0 web user, 1 service/device
  `last_seen` DATETIME NULL,
  `created_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `identifier` (`identifier`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{prefix}token` (
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

CREATE TABLE IF NOT EXISTS `{prefix}log` (
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

-- Page texts: $app->getTextFromDatabase($language, $identifier)
CREATE TABLE IF NOT EXISTS `{prefix}text` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `appid` VARCHAR(64) NOT NULL,
  `language` VARCHAR(8) NOT NULL,
  `identifier` VARCHAR(128) NOT NULL,
  `text` TEXT,
  PRIMARY KEY (`id`),
  UNIQUE KEY `app_lang_ident` (`appid`, `language`, `identifier`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
