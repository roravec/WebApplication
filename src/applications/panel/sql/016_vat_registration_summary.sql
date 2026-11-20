-- Module E: when a non-payer must register for VAT — legislation as DATA (Nastavenia → Legislatíva), never in code.
-- period: 'year' = turnover of the calendar year (registration from 1. 1. of the next year when over `threshold`,
-- from the supply that crosses `threshold_immediate`); 'rolling12' = the last 12 consecutive calendar months.
CREATE TABLE IF NOT EXISTS `{p}vat_registration_rule` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `country` CHAR(2) NOT NULL DEFAULT 'SK',
  `period` VARCHAR(10) NOT NULL DEFAULT 'year',
  `threshold` DECIMAL(12,2) NOT NULL,
  `threshold_immediate` DECIMAL(12,2) NULL,
  `valid_from` DATE NOT NULL,
  `valid_to` DATE NULL,
  `note` VARCHAR(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `country_from` (`country`, `valid_from`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Slovakia, zákon 222/2004 Z. z. § 4 — from 1. 1. 2025 (novela 102/2024 Z. z.): 50 000 € in the calendar
-- year → payer from 1. 1. of the next year; over 62 500 € → payer from the day of the supply that crossed it,
-- application within 5 working days. Until 31. 12. 2024: 49 790 € in 12 consecutive calendar months. Verify with the
-- accountant; edit in the panel.
INSERT INTO `{p}vat_registration_rule` (country, period, threshold, threshold_immediate, valid_from, valid_to, note) VALUES
  ('SK', 'rolling12', 49790.00, NULL, '2009-01-01', '2024-12-31', '§ 4 zákona o DPH — 12 po sebe nasledujúcich kalendárnych mesiacov'),
  ('SK', 'year', 50000.00, 62500.00, '2025-01-01', NULL, '§ 4 zákona o DPH — kalendárny rok; nad 62 500 € platiteľ dňom dodania, žiadosť do 5 pracovných dní');

-- Module F: monthly summary for the owner — sent once per company and month.
CREATE TABLE IF NOT EXISTS `{p}monthly_summary` (
  `company_id` INT UNSIGNED NOT NULL,
  `period` CHAR(7) NOT NULL,
  `status` VARCHAR(10) NOT NULL DEFAULT 'sending',   -- sending | sent | failed
  `attempted_at` DATETIME NOT NULL,
  `sent_at` DATETIME NULL,
  `error` VARCHAR(500) NOT NULL DEFAULT '',
  PRIMARY KEY (`company_id`, `period`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
