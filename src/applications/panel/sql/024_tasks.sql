-- Phase 2 (docs/panel-architektura.md 6.1 + 6.11): projects, tasks (list, kanban, my tasks), time sheets, commits at tasks.
-- Projects and tasks belong to a company; who sees a project: its members (also external people) + people with
-- projects.all in its company. A task number = project key + number in the project ("LED-123"); personal tasks "U-45".

CREATE TABLE IF NOT EXISTS `{p}project` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `key` VARCHAR(10) NOT NULL,                     -- LED, ERMA … (unique, upper-case letters / digits)
  `name` VARCHAR(190) NOT NULL,
  `description` TEXT NULL,
  `status` VARCHAR(10) NOT NULL DEFAULT 'active', -- active | archived
  `next_number` INT UNSIGNED NOT NULL DEFAULT 1,
  `created_by` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `key` (`key`),
  KEY `company` (`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- role in the project: owner (manages it) | member (works on tasks) | reader (only reads) | external (invited from outside)
CREATE TABLE IF NOT EXISTS `{p}project_member` (
  `project_id` INT UNSIGNED NOT NULL,
  `client_id` INT UNSIGNED NOT NULL,
  `role` VARCHAR(10) NOT NULL DEFAULT 'member',
  PRIMARY KEY (`project_id`, `client_id`),
  KEY `client` (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- columns of the kanban (= states of the tasks); done = the task is finished in this column
CREATE TABLE IF NOT EXISTS `{p}project_column` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(60) NOT NULL,
  `position` INT NOT NULL DEFAULT 0,
  `done` TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `project` (`project_id`, `position`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{p}task` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_id` INT UNSIGNED NULL,                 -- NULL = personal task (no project)
  `number` INT UNSIGNED NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT NULL,
  `column_id` INT UNSIGNED NULL,
  `position` INT NOT NULL DEFAULT 0,
  `assignee_id` INT UNSIGNED NULL,
  `priority` TINYINT NOT NULL DEFAULT 2,          -- 1 low, 2 normal, 3 high, 4 urgent
  `start_date` DATE NULL,
  `due_date` DATE NULL,
  `labels` VARCHAR(500) NOT NULL DEFAULT '',      -- comma separated
  `estimate_minutes` INT UNSIGNED NULL,
  `parent_id` INT UNSIGNED NULL,                  -- subtask of
  `created_by` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  `closed_at` DATETIME NULL,
  `reminded_on` DATE NULL,                        -- last due-date reminder (scheduler)
  PRIMARY KEY (`id`),
  UNIQUE KEY `number` (`project_id`, `number`),
  KEY `column` (`column_id`, `position`),
  KEY `assignee` (`assignee_id`, `closed_at`),
  KEY `due` (`due_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{p}task_check` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `task_id` INT UNSIGNED NOT NULL,
  `text` VARCHAR(255) NOT NULL,
  `done` TINYINT(1) NOT NULL DEFAULT 0,
  `position` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `task` (`task_id`, `position`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{p}task_watcher` (
  `task_id` INT UNSIGNED NOT NULL,
  `client_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`task_id`, `client_id`),
  KEY `client` (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- time sheets: hours at a task / project (or general work), billable or not
CREATE TABLE IF NOT EXISTS `{p}time_entry` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `client_id` INT UNSIGNED NOT NULL,
  `project_id` INT UNSIGNED NULL,
  `task_id` INT UNSIGNED NULL,
  `work_date` DATE NOT NULL,
  `minutes` INT UNSIGNED NOT NULL,
  `note` VARCHAR(500) NOT NULL DEFAULT '',
  `billable` TINYINT(1) NOT NULL DEFAULT 0,
  `source` VARCHAR(10) NOT NULL DEFAULT 'manual', -- manual | timer | commit
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `person_date` (`client_id`, `work_date`),
  KEY `project` (`project_id`, `work_date`),
  KEY `task` (`task_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- running stopwatch of a person (one at a time)
CREATE TABLE IF NOT EXISTS `{p}time_timer` (
  `client_id` INT UNSIGNED NOT NULL,
  `task_id` INT UNSIGNED NULL,
  `project_id` INT UNSIGNED NULL,
  `started_at` DATETIME NOT NULL,
  PRIMARY KEY (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- git repositories sending their commits (GitHub / GitLab webhook, or the API from a post-receive hook)
CREATE TABLE IF NOT EXISTS `{p}git_repo` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `provider` VARCHAR(10) NOT NULL,                -- github | gitlab | api
  `name` VARCHAR(190) NOT NULL,                   -- owner/repo
  `web_url` VARCHAR(255) NOT NULL DEFAULT '',
  `secret` TEXT NOT NULL,                         -- webhook secret, encrypted (Panel\Secret)
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL,
  `last_push_at` DATETIME NULL,
  `last_message` VARCHAR(500) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{p}git_commit` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `repo_id` INT UNSIGNED NOT NULL,
  `sha` VARCHAR(64) NOT NULL,
  `message` TEXT NOT NULL,
  `author_name` VARCHAR(190) NOT NULL DEFAULT '',
  `author_email` VARCHAR(190) NOT NULL DEFAULT '',
  `client_id` INT UNSIGNED NULL,                  -- author matched to an employee by e-mail
  `branch` VARCHAR(190) NOT NULL DEFAULT '',
  `url` VARCHAR(500) NOT NULL DEFAULT '',
  `committed_at` DATETIME NOT NULL,
  `received_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `repo_sha` (`repo_id`, `sha`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{p}task_commit` (
  `task_id` INT UNSIGNED NOT NULL,
  `commit_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`task_id`, `commit_id`),
  KEY `commit` (`commit_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- the task number in commit messages: at the START of the message, {TASK} = LED-123 (owner 2026-11-21: configurable)
INSERT IGNORE INTO `{p}setting` (name, value, updated_at) VALUES ('git.task_format', '#{TASK}', NOW());

INSERT IGNORE INTO `{p}role` (name, description) VALUES
  ('Vývoj', 'projekty a úlohy, výkazy práce, vlastné API kľúče (CI, testovacie prípravky)'),
  ('Externý spolupracovník', 'len projekty, kam ho pozvú: úlohy, komentáre, výkazy — nič iné');
INSERT IGNORE INTO `{p}role_permission` (role_id, permission)
  SELECT r.id, p.perm FROM `{p}role` r JOIN (
    SELECT 'tasks.view' AS perm UNION ALL SELECT 'tasks.edit' UNION ALL SELECT 'time.track' UNION ALL SELECT 'api.keys') p WHERE r.name = 'Vývoj';
INSERT IGNORE INTO `{p}role_permission` (role_id, permission)
  SELECT r.id, p.perm FROM `{p}role` r JOIN (
    SELECT 'tasks.view' AS perm UNION ALL SELECT 'tasks.edit' UNION ALL SELECT 'time.track') p WHERE r.name = 'Externý spolupracovník';
