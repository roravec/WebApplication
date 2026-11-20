-- Received documents, part C2 (docs/sprava-firmy-specifikacia.md): payment from the bank statement + recurring costs.

-- the bank movement that paid the document (statement import / Fio) — one movement pays one document, imported once
ALTER TABLE `{p}expense` ADD COLUMN `bank_ref` VARCHAR(64) NULL AFTER `paid_ref`, ADD UNIQUE KEY `bank_ref` (`bank_ref`);

-- recurring costs (rent, subscriptions, phone …): the panel creates the document itself on the due date
CREATE TABLE IF NOT EXISTS `{p}expense_recurring` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `shop_id` INT UNSIGNED NULL,
  `category_id` INT UNSIGNED NULL,
  `name` VARCHAR(255) NOT NULL DEFAULT '',             -- e.g. "Nájom skladu"
  `supplier_name` VARCHAR(255) NOT NULL DEFAULT '',
  `supplier_ico` VARCHAR(20) NOT NULL DEFAULT '',
  `supplier_dic` VARCHAR(20) NOT NULL DEFAULT '',
  `supplier_icdph` VARCHAR(20) NOT NULL DEFAULT '',
  `supplier_street` VARCHAR(255) NOT NULL DEFAULT '',
  `supplier_zip` VARCHAR(20) NOT NULL DEFAULT '',
  `supplier_city` VARCHAR(100) NOT NULL DEFAULT '',
  `supplier_country` CHAR(2) NOT NULL DEFAULT 'SK',
  `supplier_iban` VARCHAR(34) NOT NULL DEFAULT '',
  `variable_symbol` VARCHAR(20) NOT NULL DEFAULT '',
  `currency` CHAR(3) NOT NULL DEFAULT 'EUR',
  `total` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `total_vat` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `payment_method` VARCHAR(20) NOT NULL DEFAULT 'transfer',
  `interval_months` TINYINT UNSIGNED NOT NULL DEFAULT 1, -- 1 monthly, 3 quarterly, 6, 12 yearly
  `due_days` SMALLINT UNSIGNED NOT NULL DEFAULT 0,       -- due date = document date + days
  `day` TINYINT UNSIGNED NOT NULL DEFAULT 1,             -- day of the month (31 = last day of shorter months)
  `next_date` DATE NOT NULL,                             -- date of the next document (delivery = issue date)
  `end_date` DATE NULL,                                  -- last document on or before this date (NULL = until stopped)
  `active` TINYINT NOT NULL DEFAULT 1,
  `note` VARCHAR(255) NOT NULL DEFAULT '',
  `created_by` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `due` (`active`, `next_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
