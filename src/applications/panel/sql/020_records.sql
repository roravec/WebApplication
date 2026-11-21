-- Core step 1b (docs/panel-architektura.md chapter 4): record references, links between records, activity per record,
-- comments with @mentions. A reference = "type:id" (invoice:15, order:0:2481 = order source 0 + id_order, expense:3 …),
-- types are registered by the modules (Module::recordTypes()).

-- activity: every audit row knows its record (the old entity/entity_id stay for compatibility)
ALTER TABLE `{p}audit` ADD COLUMN `ref` VARCHAR(80) NULL, ADD KEY `ref` (`ref`, `id`);
UPDATE `{p}audit` SET ref = CONCAT(entity, ':', entity_id)
  WHERE ref IS NULL AND entity IN ('invoice', 'company', 'customer', 'expense', 'stock_doc', 'stock_item', 'warehouse', 'employee', 'role');
-- orders of a connected e-shop: the details start with the connection name; the rest are orders of this database (source 0)
UPDATE `{p}audit` a JOIN `{p}shop_connection` c ON c.local_db = 0 AND a.details LIKE CONCAT(c.name, ' %')
  SET a.ref = CONCAT('order:', c.id, ':', a.entity_id) WHERE a.ref IS NULL AND a.entity = 'shop_order';
UPDATE `{p}audit` SET ref = CONCAT('order:0:', entity_id) WHERE ref IS NULL AND entity = 'shop_order';

-- any record ↔ any record, with a kind (relates, blocks, verifies, derived, replaces)
CREATE TABLE IF NOT EXISTS `{p}link` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `from_ref` VARCHAR(80) NOT NULL,
  `to_ref` VARCHAR(80) NOT NULL,
  `kind` VARCHAR(20) NOT NULL DEFAULT 'relates',
  `client_id` INT UNSIGNED NULL,
  `client_name` VARCHAR(128) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `pair` (`from_ref`, `to_ref`, `kind`),
  KEY `to_ref` (`to_ref`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- comments at any record (internal — never shown to customers)
CREATE TABLE IF NOT EXISTS `{p}comment` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ref` VARCHAR(80) NOT NULL,
  `body` TEXT NOT NULL,
  `client_id` INT UNSIGNED NULL,
  `client_name` VARCHAR(128) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL,
  `edited_at` DATETIME NULL,
  `deleted_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `ref` (`ref`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- who was @mentioned (notifications come with step 1c)
CREATE TABLE IF NOT EXISTS `{p}comment_mention` (
  `comment_id` INT UNSIGNED NOT NULL,
  `client_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`comment_id`, `client_id`),
  KEY `client` (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- internal notes of orders become comments (the order_note table stays as it was, nothing writes into it any more)
INSERT INTO `{p}comment` (ref, body, client_id, client_name, created_at)
  SELECT CONCAT('order:', source, ':', order_id), `text`, client_id, client_name, created_at FROM `{p}order_note` ORDER BY id;
