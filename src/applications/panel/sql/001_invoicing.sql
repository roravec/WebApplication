-- Invoicing: companies (suppliers), number series, customers, invoices, permissions, audit log.
-- {p} = the panel's table prefix. Applied automatically by Panel\Schema (one statement per ";" at line end).
-- Spec: docs/fakturacia-specifikacia.md. Money DECIMAL(15,2), unit prices DECIMAL(15,4), quantities DECIMAL(15,3).

-- Permissions per employee (admin = client.rights >= 100 has all of them) and access to companies.
CREATE TABLE IF NOT EXISTS `{p}permission` (
  `client_id` INT UNSIGNED NOT NULL,
  `permission` VARCHAR(40) NOT NULL,
  PRIMARY KEY (`client_id`, `permission`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{p}company_access` (
  `client_id` INT UNSIGNED NOT NULL,
  `company_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`client_id`, `company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Supplier companies. IČ DPH and VAT payer status are in company_vat (they change over time).
CREATE TABLE IF NOT EXISTS `{p}company` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(190) NOT NULL,
  `street` VARCHAR(190) NOT NULL DEFAULT '',
  `city` VARCHAR(120) NOT NULL DEFAULT '',
  `zip` VARCHAR(20) NOT NULL DEFAULT '',
  `country` CHAR(2) NOT NULL DEFAULT 'SK',
  `ico` VARCHAR(20) NOT NULL DEFAULT '',
  `dic` VARCHAR(20) NOT NULL DEFAULT '',
  `registration` VARCHAR(255) NOT NULL DEFAULT '',       -- zápis v OR: súd, oddiel, vložka
  `email` VARCHAR(190) NOT NULL DEFAULT '',
  `phone` VARCHAR(60) NOT NULL DEFAULT '',
  `web` VARCHAR(190) NOT NULL DEFAULT '',
  `slogan` VARCHAR(190) NOT NULL DEFAULT '',
  `due_days` INT NOT NULL DEFAULT 14,
  `invoice_note` TEXT NULL,                              -- default note printed on invoices
  `accent_color` VARCHAR(7) NOT NULL DEFAULT '#f74040',
  `logo` MEDIUMBLOB NULL,
  `logo_mime` VARCHAR(40) NOT NULL DEFAULT '',
  `stamp` MEDIUMBLOB NULL,                               -- stamp + signature image
  `stamp_mime` VARCHAR(40) NOT NULL DEFAULT '',
  `active` TINYINT NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- VAT mode history: the row with the latest valid_from <= date applies. No row = not a VAT payer.
CREATE TABLE IF NOT EXISTS `{p}company_vat` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `valid_from` DATE NOT NULL,
  `vat_payer` TINYINT NOT NULL DEFAULT 0,
  `icdph` VARCHAR(20) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `company_from` (`company_id`, `valid_from`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{p}company_bank` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `account_name` VARCHAR(190) NOT NULL DEFAULT '',
  `iban` VARCHAR(40) NOT NULL,
  `bic` VARCHAR(11) NOT NULL DEFAULT '',
  `is_default` TINYINT NOT NULL DEFAULT 0,
  `active` TINYINT NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `company` (`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Which company invoices the orders of which e-shop (PrestaShop id_shop).
CREATE TABLE IF NOT EXISTS `{p}company_shop` (
  `shop_id` INT UNSIGNED NOT NULL,
  `company_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`shop_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Number series per company and document type. format tokens: {RRRR} {RR} {MM} {NNN…} (counter, zero padded).
CREATE TABLE IF NOT EXISTS `{p}series` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `doc_type` VARCHAR(20) NOT NULL,                       -- invoice | credit_note
  `name` VARCHAR(100) NOT NULL,
  `format` VARCHAR(60) NOT NULL,
  `vs_format` VARCHAR(30) NOT NULL DEFAULT '',           -- variabilný symbol; '' = digits of the number
  `reset_yearly` TINYINT NOT NULL DEFAULT 1,
  `is_default` TINYINT NOT NULL DEFAULT 0,
  `active` TINYINT NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `company_type` (`company_id`, `doc_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Next number per series and year (year 0 = series without yearly reset). Locked with SELECT … FOR UPDATE.
CREATE TABLE IF NOT EXISTS `{p}series_counter` (
  `series_id` INT UNSIGNED NOT NULL,
  `year` SMALLINT UNSIGNED NOT NULL,
  `next_number` INT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (`series_id`, `year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Customers belong to one company (companies using the system don't see each other's customers).
CREATE TABLE IF NOT EXISTS `{p}customer` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `type` VARCHAR(3) NOT NULL DEFAULT 'b2c',              -- b2c | b2b
  `name` VARCHAR(190) NOT NULL,
  `street` VARCHAR(190) NOT NULL DEFAULT '',
  `city` VARCHAR(120) NOT NULL DEFAULT '',
  `zip` VARCHAR(20) NOT NULL DEFAULT '',
  `country` CHAR(2) NOT NULL DEFAULT 'SK',
  `ico` VARCHAR(20) NOT NULL DEFAULT '',
  `dic` VARCHAR(20) NOT NULL DEFAULT '',
  `icdph` VARCHAR(20) NOT NULL DEFAULT '',
  `email` VARCHAR(190) NOT NULL DEFAULT '',
  `phone` VARCHAR(60) NOT NULL DEFAULT '',
  `due_days` INT NULL,
  `note` TEXT NULL,
  `shop_customer_id` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `company` (`company_id`),
  KEY `name` (`name`),
  KEY `ico` (`ico`),
  KEY `shop_customer` (`shop_customer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Invoices and credit notes. status draft → issued; an issued document is never changed or deleted.
-- supplier_json = snapshot of the company at issue time. shop_order_id is UNIQUE: one invoice per shop order.
CREATE TABLE IF NOT EXISTS `{p}invoice` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `doc_type` VARCHAR(20) NOT NULL DEFAULT 'invoice',
  `status` VARCHAR(10) NOT NULL DEFAULT 'draft',
  `series_id` INT UNSIGNED NULL,
  `number` VARCHAR(40) NULL,
  `variable_symbol` VARCHAR(10) NOT NULL DEFAULT '',
  `issue_date` DATE NOT NULL,
  `delivery_date` DATE NOT NULL,
  `due_date` DATE NOT NULL,
  `currency` CHAR(3) NOT NULL DEFAULT 'EUR',
  `payment_method` VARCHAR(20) NOT NULL DEFAULT 'transfer',
  `bank_account_id` INT UNSIGNED NULL,
  `bank_account_name` VARCHAR(190) NOT NULL DEFAULT '',
  `iban` VARCHAR(40) NOT NULL DEFAULT '',
  `bic` VARCHAR(11) NOT NULL DEFAULT '',
  `vat_payer` TINYINT NOT NULL DEFAULT 0,
  `supplier_json` TEXT NULL,
  `customer_id` INT UNSIGNED NULL,
  `buyer_type` VARCHAR(3) NOT NULL DEFAULT 'b2c',
  `buyer_name` VARCHAR(190) NOT NULL DEFAULT '',
  `buyer_street` VARCHAR(190) NOT NULL DEFAULT '',
  `buyer_city` VARCHAR(120) NOT NULL DEFAULT '',
  `buyer_zip` VARCHAR(20) NOT NULL DEFAULT '',
  `buyer_country` CHAR(2) NOT NULL DEFAULT 'SK',
  `buyer_ico` VARCHAR(20) NOT NULL DEFAULT '',
  `buyer_dic` VARCHAR(20) NOT NULL DEFAULT '',
  `buyer_icdph` VARCHAR(20) NOT NULL DEFAULT '',
  `buyer_email` VARCHAR(190) NOT NULL DEFAULT '',
  `buyer_phone` VARCHAR(60) NOT NULL DEFAULT '',
  `shop_id` INT UNSIGNED NULL,
  `shop_order_id` INT UNSIGNED NULL,
  `order_reference` VARCHAR(40) NOT NULL DEFAULT '',
  `original_invoice_id` INT UNSIGNED NULL,               -- credit note → corrected invoice
  `correction_reason` VARCHAR(255) NOT NULL DEFAULT '',
  `note` TEXT NULL,                                      -- printed on the invoice
  `internal_note` TEXT NULL,                             -- never printed
  `total_net` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `total_vat` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `total` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `issued_by_name` VARCHAR(128) NOT NULL DEFAULT '',     -- "Vystavil"
  `created_by` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  `issued_by` INT UNSIGNED NULL,
  `issued_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `company_number` (`company_id`, `number`),
  UNIQUE KEY `shop_order` (`shop_order_id`),
  KEY `status` (`status`),
  KEY `issue_date` (`issue_date`),
  KEY `original` (`original_invoice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{p}invoice_line` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `invoice_id` INT UNSIGNED NOT NULL,
  `position` INT NOT NULL DEFAULT 0,
  `name` VARCHAR(255) NOT NULL,
  `sku` VARCHAR(64) NOT NULL DEFAULT '',
  `quantity` DECIMAL(15,3) NOT NULL DEFAULT 1,
  `unit` VARCHAR(10) NOT NULL DEFAULT 'ks',
  `unit_price` DECIMAL(15,4) NOT NULL DEFAULT 0,         -- without VAT
  `vat_rate` DECIMAL(5,2) NOT NULL DEFAULT 0,
  `vat_category` VARCHAR(2) NOT NULL DEFAULT 'S',        -- EN 16931: S, Z, E, AE, K, G, O
  `net` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `vat` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `gross` DECIMAL(15,2) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `invoice` (`invoice_id`, `position`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Who did what and when (invoicing law: who issued / sent / corrected a document).
CREATE TABLE IF NOT EXISTS `{p}audit` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `created_at` DATETIME NOT NULL,
  `client_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `client_name` VARCHAR(128) NOT NULL DEFAULT '',
  `entity` VARCHAR(30) NOT NULL,
  `entity_id` INT UNSIGNED NOT NULL,
  `action` VARCHAR(30) NOT NULL,
  `details` TEXT NULL,
  PRIMARY KEY (`id`),
  KEY `entity` (`entity`, `entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Old rights levels → permissions (10 = view, 50 = invoicing; 100 stays admin).
INSERT IGNORE INTO `{p}permission` (client_id, permission)
  SELECT id, 'orders.view' FROM `{p}client` WHERE type = 0 AND rights >= 10 AND rights < 100;
INSERT IGNORE INTO `{p}permission` (client_id, permission)
  SELECT id, 'invoices.view' FROM `{p}client` WHERE type = 0 AND rights >= 10 AND rights < 100;
INSERT IGNORE INTO `{p}permission` (client_id, permission)
  SELECT id, 'invoices.edit' FROM `{p}client` WHERE type = 0 AND rights >= 50 AND rights < 100;
UPDATE `{p}client` SET rights = 10 WHERE type = 0 AND rights > 0 AND rights < 100;
