-- Monthly package for the accountant (owner 2026-11-20): one e-mail per company and month with the Omega import file,
-- the PDFs of all issued documents, an overview of documents and payments (CSV). Sent automatically after the month
-- (setting of the company) or by a button. A document goes into exactly one package (invoice.accounting_package_id);
-- a package can be downloaded / sent again with exactly the same content.
CREATE TABLE IF NOT EXISTS `{p}accounting_package` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `period` CHAR(7) NOT NULL,                         -- YYYY-MM
  `status` VARCHAR(10) NOT NULL DEFAULT 'sending',   -- sending | sent | failed
  `doc_ids` TEXT NULL,                               -- JSON: documents of the package
  `omega_ids` TEXT NULL,                             -- JSON: documents in the Omega file (not exported to Omega before)
  `recipients` VARCHAR(500) NOT NULL DEFAULT '',
  `error` VARCHAR(500) NOT NULL DEFAULT '',
  `trigger` VARCHAR(10) NOT NULL DEFAULT 'manual',   -- auto | manual
  `created_by` INT UNSIGNED NOT NULL DEFAULT 0,
  `attempted_at` DATETIME NOT NULL,
  `sent_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `company_period` (`company_id`, `period`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `{p}invoice` ADD COLUMN `accounting_package_id` INT UNSIGNED NULL, ADD KEY `accounting_package` (`accounting_package_id`);
