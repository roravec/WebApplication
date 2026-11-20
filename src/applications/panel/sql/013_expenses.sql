-- Received documents and costs (docs/sprava-firmy-specifikacia.md, module C). Kept 10 years: never deleted, only
-- cancelled with a reason (audit). Files (PDF / photo) in the database like the PDFs of issued invoices.

CREATE TABLE IF NOT EXISTS `{p}expense_category` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `kind` VARCHAR(20) NOT NULL DEFAULT 'overhead',   -- goods (tovar) | order (náklady na objednávky) | overhead (réžia)
  `position` INT NOT NULL DEFAULT 0,
  `active` TINYINT NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `{p}expense_category` (`id`, `name`, `kind`, `position`) VALUES
  (1, 'Nákup tovaru', 'goods', 10),
  (2, 'Doprava a poštovné', 'order', 20),
  (3, 'Platobné poplatky (GoPay, banka)', 'order', 30),
  (4, 'Obaly a balenie', 'order', 40),
  (5, 'Marketing a reklama', 'overhead', 50),
  (6, 'Nájom a energie', 'overhead', 60),
  (7, 'Telefón a internet', 'overhead', 70),
  (8, 'Softvér, hosting a služby', 'overhead', 80),
  (9, 'Účtovníctvo a právne služby', 'overhead', 90),
  (10, 'Mzdy a odvody', 'overhead', 100),
  (11, 'Dane a poplatky', 'overhead', 110),
  (12, 'Ostatné', 'overhead', 120);

CREATE TABLE IF NOT EXISTS `{p}expense` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `shop_id` INT UNSIGNED NULL,                       -- cost centre: e-shop (shop tables of this database); NULL = company
  `category_id` INT UNSIGNED NULL,
  `doc_type` VARCHAR(20) NOT NULL DEFAULT 'invoice', -- invoice | receipt (pokladničný blok) | credit_note | other
  `supplier_name` VARCHAR(255) NOT NULL DEFAULT '',
  `supplier_ico` VARCHAR(20) NOT NULL DEFAULT '',
  `supplier_dic` VARCHAR(20) NOT NULL DEFAULT '',
  `supplier_icdph` VARCHAR(20) NOT NULL DEFAULT '',
  `supplier_street` VARCHAR(255) NOT NULL DEFAULT '',
  `supplier_zip` VARCHAR(20) NOT NULL DEFAULT '',
  `supplier_city` VARCHAR(100) NOT NULL DEFAULT '',
  `supplier_country` CHAR(2) NOT NULL DEFAULT 'SK',
  `document_number` VARCHAR(64) NOT NULL DEFAULT '',
  `variable_symbol` VARCHAR(20) NOT NULL DEFAULT '',
  `supplier_iban` VARCHAR(34) NOT NULL DEFAULT '',
  `issue_date` DATE NULL,
  `delivery_date` DATE NOT NULL,                     -- dátum dodania — the month the cost belongs to
  `due_date` DATE NULL,
  `currency` CHAR(3) NOT NULL DEFAULT 'EUR',
  `total_net` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `total_vat` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `total` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `payment_method` VARCHAR(20) NOT NULL DEFAULT 'transfer', -- transfer | card | cash | collection | other
  `status` VARCHAR(12) NOT NULL DEFAULT 'open',      -- open (neuhradený) | paid | cancelled
  `paid_on` DATE NULL,
  `paid_ref` VARCHAR(255) NOT NULL DEFAULT '',
  `note` TEXT NULL,
  `cancel_reason` VARCHAR(255) NOT NULL DEFAULT '',
  `recurring_id` INT UNSIGNED NULL,
  `accounting_package_id` INT UNSIGNED NULL,
  `created_by` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `company_date` (`company_id`, `delivery_date`),
  KEY `status_due` (`status`, `due_date`),
  KEY `supplier_doc` (`supplier_ico`, `document_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{p}expense_file` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `expense_id` INT UNSIGNED NOT NULL,
  `filename` VARCHAR(255) NOT NULL,
  `mime` VARCHAR(100) NOT NULL,
  `size` INT UNSIGNED NOT NULL,
  `sha256` CHAR(64) NOT NULL,
  `data` LONGBLOB NOT NULL,
  `created_by` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `expense` (`expense_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- the monthly package for the accountant also carries the received documents
ALTER TABLE `{p}accounting_package` ADD COLUMN `expense_ids` TEXT NULL AFTER `omega_ids`;
