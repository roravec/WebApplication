-- Core step 1c (docs/panel-architektura.md chapter 4): notifications (bell + e-mail) and the scheduler.

-- one notification for one person about one record (comment mention, reply in a discussion …)
CREATE TABLE IF NOT EXISTS `{p}notification` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `client_id` INT UNSIGNED NOT NULL,
  `ref` VARCHAR(80) NOT NULL,
  `event` VARCHAR(40) NOT NULL,
  `text` VARCHAR(500) NOT NULL,
  `actor_name` VARCHAR(128) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL,
  `read_at` DATETIME NULL,
  `email_state` VARCHAR(10) NOT NULL DEFAULT 'none',   -- none | pending (immediate e-mail) | daily (digest) | sent | failed
  PRIMARY KEY (`id`),
  KEY `person` (`client_id`, `read_at`, `id`),
  KEY `email` (`email_state`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- how a person wants e-mails about notifications; no row = immediately
CREATE TABLE IF NOT EXISTS `{p}notify_pref` (
  `client_id` INT UNSIGNED NOT NULL,
  `email_mode` VARCHAR(10) NOT NULL DEFAULT 'immediate',   -- immediate | daily | off
  PRIMARY KEY (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- scheduler: last run of each job (the jobs are registered by the modules); running_until = lock against parallel runs
CREATE TABLE IF NOT EXISTS `{p}job_run` (
  `job` VARCHAR(60) NOT NULL,
  `last_run_at` DATETIME NULL,
  `last_ok` TINYINT(1) NOT NULL DEFAULT 1,
  `last_message` VARCHAR(1000) NOT NULL DEFAULT '',
  `running_until` DATETIME NULL,
  PRIMARY KEY (`job`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
