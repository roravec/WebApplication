-- Warehouse (sklad): warehouses, stock items, batches (lots) with purchase price, stock documents and movements.
-- Spec: docs/sklad-specifikacia.md. The panel is the master of stock; shops get quantities from it (step 2).

CREATE TABLE IF NOT EXISTS `{p}warehouse` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `code` VARCHAR(20) NOT NULL,
  `name` VARCHAR(120) NOT NULL,
  `location` VARCHAR(255) NOT NULL DEFAULT '',           -- where it physically is (free text)
  `kind` VARCHAR(10) NOT NULL DEFAULT 'other',           -- shop | other
  `active` TINYINT NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `company_code` (`company_id`, `code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Stock item (skladová karta). Quantity is kept per warehouse in the lots.
CREATE TABLE IF NOT EXISTS `{p}stock_item` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `sku` VARCHAR(64) NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `unit` VARCHAR(10) NOT NULL DEFAULT 'ks',
  `ean` VARCHAR(20) NOT NULL DEFAULT '',
  `min_qty` DECIMAL(15,3) NULL,                           -- warn below this quantity (all warehouses)
  `note` TEXT NULL,
  `active` TINYINT NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `company_sku` (`company_id`, `sku`),
  KEY `name` (`name`),
  KEY `ean` (`ean`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Stock documents: receipt (príjemka), issue (výdajka), transfer (prevodka), adjust (inventúra).
-- Posted = final; a correction is an opposite document.
CREATE TABLE IF NOT EXISTS `{p}stock_doc` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `doc_type` VARCHAR(10) NOT NULL,
  `number` VARCHAR(40) NOT NULL,
  `series_id` INT UNSIGNED NULL,
  `doc_date` DATE NOT NULL,
  `warehouse_id` INT UNSIGNED NOT NULL,
  `target_warehouse_id` INT UNSIGNED NULL,                -- transfer
  `partner` VARCHAR(190) NOT NULL DEFAULT '',             -- supplier / recipient
  `reference` VARCHAR(100) NOT NULL DEFAULT '',           -- supplier invoice no., order …
  `note` TEXT NULL,
  `total_cost` DECIMAL(15,2) NOT NULL DEFAULT 0,          -- value of the movement at purchase prices
  `created_by` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `company_number` (`company_id`, `number`),
  KEY `date` (`doc_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Batch (dávka): one receipt line of one item into one warehouse at one purchase price.
CREATE TABLE IF NOT EXISTS `{p}stock_lot` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `item_id` INT UNSIGNED NOT NULL,
  `warehouse_id` INT UNSIGNED NOT NULL,
  `doc_id` INT UNSIGNED NOT NULL,                         -- document that created it (receipt / transfer / adjust)
  `received_on` DATE NOT NULL,                            -- FIFO order (kept on transfer)
  `qty_received` DECIMAL(15,3) NOT NULL,
  `qty_left` DECIMAL(15,3) NOT NULL,
  `unit_cost` DECIMAL(15,4) NOT NULL,                     -- purchase price per unit, without VAT
  `lot_code` VARCHAR(64) NOT NULL DEFAULT '',             -- supplier's batch / serial number
  `expires_on` DATE NULL,
  PRIMARY KEY (`id`),
  KEY `fifo` (`item_id`, `warehouse_id`, `qty_left`, `received_on`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Movement: + into a lot, − out of a lot (one row per lot touched).
CREATE TABLE IF NOT EXISTS `{p}stock_move` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `doc_id` INT UNSIGNED NOT NULL,
  `item_id` INT UNSIGNED NOT NULL,
  `warehouse_id` INT UNSIGNED NOT NULL,
  `lot_id` INT UNSIGNED NOT NULL,
  `qty` DECIMAL(15,3) NOT NULL,
  `unit_cost` DECIMAL(15,4) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `doc` (`doc_id`),
  KEY `item` (`item_id`, `warehouse_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
