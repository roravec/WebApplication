-- Order management in the panel (owner 2026-11-20): internal notes, messages to the customer, returns. Only the panel's
-- own records — what changes in the shop (state, tracking number, address, stock) goes through the Webservice.
-- source = order source (0 = shop tables of this database, else the shop connection id), order_id = id_order there.

CREATE TABLE IF NOT EXISTS `{p}order_note` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `source` INT UNSIGNED NOT NULL DEFAULT 0,
  `order_id` INT UNSIGNED NOT NULL,
  `text` TEXT NOT NULL,
  `client_id` INT UNSIGNED NULL,
  `client_name` VARCHAR(100) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `order` (`source`, `order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{p}order_message` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `source` INT UNSIGNED NOT NULL DEFAULT 0,
  `order_id` INT UNSIGNED NOT NULL,
  `recipients` VARCHAR(500) NOT NULL DEFAULT '',
  `subject` VARCHAR(255) NOT NULL DEFAULT '',
  `body` TEXT NOT NULL,
  `status` VARCHAR(10) NOT NULL DEFAULT 'sent',      -- sent | failed
  `error` VARCHAR(500) NOT NULL DEFAULT '',
  `client_id` INT UNSIGNED NULL,
  `client_name` VARCHAR(100) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `order` (`source`, `order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{p}order_return` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `source` INT UNSIGNED NOT NULL DEFAULT 0,
  `order_id` INT UNSIGNED NOT NULL,
  `lines` TEXT NOT NULL,                             -- JSON [{id_order_detail, product_id, product_attribute_id, name, reference, quantity}]
  `shipping` TINYINT NOT NULL DEFAULT 0,             -- shipping refunded too
  `restocked` TINYINT NOT NULL DEFAULT 0,            -- quantities added back to the shop's stock
  `credit_note_id` INT UNSIGNED NULL,                -- draft credit note made from the order's invoice
  `state` VARCHAR(100) NOT NULL DEFAULT '',          -- order state set in the shop (name)
  `note` VARCHAR(500) NOT NULL DEFAULT '',
  `problems` TEXT NULL,                              -- steps that failed (shown on the order)
  `client_id` INT UNSIGNED NULL,
  `client_name` VARCHAR(100) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `order` (`source`, `order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- small panel-wide settings (e.g. the order states shown in Expedícia per order source)
CREATE TABLE IF NOT EXISTS `{p}setting` (
  `name` VARCHAR(100) NOT NULL,
  `value` TEXT NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
