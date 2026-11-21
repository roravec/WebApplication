-- Core step 1d (docs/panel-architektura.md 4 + 4.1): integrations (shared settings of external services, secrets
-- encrypted with Panel\Secret) and files of records in the company Nextcloud.

CREATE TABLE IF NOT EXISTS `{p}integration` (
  `name` VARCHAR(40) NOT NULL,                 -- nextcloud, …
  `config` TEXT NOT NULL,                      -- JSON; secret values encrypted (Panel\Secret)
  `enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `status` VARCHAR(10) NOT NULL DEFAULT '',    -- ok | error | '' (not checked yet)
  `status_message` VARCHAR(500) NOT NULL DEFAULT '',
  `checked_at` DATETIME NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- the Nextcloud folder of a record, fixed when it is created (a renamed company / record keeps its folder)
CREATE TABLE IF NOT EXISTS `{p}record_folder` (
  `ref` VARCHAR(80) NOT NULL,
  `path` VARCHAR(500) NOT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`ref`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- share links created by the panel (download for people outside the company, upload-only for customers)
CREATE TABLE IF NOT EXISTS `{p}file_share` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ref` VARCHAR(80) NOT NULL,
  `kind` VARCHAR(10) NOT NULL,                 -- read | upload
  `remote_id` VARCHAR(40) NOT NULL,            -- id of the share in Nextcloud
  `url` VARCHAR(500) NOT NULL,
  `expires` DATE NULL,
  `has_password` TINYINT(1) NOT NULL DEFAULT 0,
  `client_id` INT UNSIGNED NULL,
  `client_name` VARCHAR(128) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL,
  `revoked_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `ref` (`ref`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
