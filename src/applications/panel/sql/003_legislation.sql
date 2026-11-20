-- Legislation as DATA, not code (owner 2026-11-19: "these legal things change often").
-- VAT rates and the legal texts printed on invoices have validity dates; they are edited in the panel
-- (Nastavenia → Legislatíva) and an issued invoice keeps a copy of what applied on its date.

-- VAT rates per country. A rate applies on a date when valid_from <= date and (valid_to IS NULL or date <= valid_to).
CREATE TABLE IF NOT EXISTS `{p}vat_rate` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `country` CHAR(2) NOT NULL DEFAULT 'SK',
  `rate` DECIMAL(5,2) NOT NULL,
  `kind` VARCHAR(10) NOT NULL DEFAULT 'standard',        -- standard | reduced
  `valid_from` DATE NOT NULL,
  `valid_to` DATE NULL,
  `note` VARCHAR(190) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `country_from` (`country`, `valid_from`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Slovakia (zákon 222/2004 Z. z.; from 1. 1. 2025 novela 278/2024 Z. z.) — verify with the accountant, edit in the panel.
INSERT INTO `{p}vat_rate` (country, rate, kind, valid_from, valid_to, note) VALUES
  ('SK', 20.00, 'standard', '2011-01-01', '2024-12-31', 'základná sadzba'),
  ('SK', 10.00, 'reduced', '2011-01-01', '2024-12-31', 'znížená sadzba'),
  ('SK', 5.00, 'reduced', '2020-01-01', '2024-12-31', 'znížená sadzba'),
  ('SK', 23.00, 'standard', '2025-01-01', NULL, 'základná sadzba'),
  ('SK', 19.00, 'reduced', '2025-01-01', NULL, 'znížená sadzba'),
  ('SK', 5.00, 'reduced', '2025-01-01', NULL, 'znížená sadzba');

-- Texts printed on documents. For a code the row with the latest valid_from <= issue date applies.
CREATE TABLE IF NOT EXISTS `{p}legal_text` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(40) NOT NULL,
  `text` TEXT NOT NULL,
  `valid_from` DATE NOT NULL,
  `note` VARCHAR(190) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `code_from` (`code`, `valid_from`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Defaults — wording to be checked by the accountant; changed in the panel, never in code.
INSERT INTO `{p}legal_text` (code, text, valid_from, note) VALUES
  ('title_invoice', 'Faktúra', '2000-01-01', 'nadpis faktúry'),
  ('title_credit_note', 'Dobropis', '2000-01-01', 'nadpis dobropisu / opravnej faktúry'),
  ('credit_note_reference', 'Opravný doklad k faktúre č. {number} zo dňa {date}. Dôvod opravy: {reason}', '2000-01-01', 'dobropis — odkaz na pôvodnú faktúru'),
  ('non_vat_payer', 'Nie sme platiteľmi DPH.', '2000-01-01', 'dodávateľ nie je platiteľ DPH'),
  ('vat_AE', 'Prenesenie daňovej povinnosti.', '2000-01-01', 'kategória AE — prenesenie daňovej povinnosti (§ 69 ods. 12 / § 71 zákona o DPH)'),
  ('vat_E', 'Oslobodené od dane.', '2000-01-01', 'kategória E — oslobodenie: doplniť odkaz na ustanovenie zákona'),
  ('vat_K', 'Dodanie tovaru do iného členského štátu oslobodené od dane podľa § 43 zákona o DPH.', '2000-01-01', 'kategória K — dodanie do EÚ'),
  ('vat_G', 'Vývoz tovaru oslobodený od dane podľa § 47 zákona o DPH.', '2000-01-01', 'kategória G — vývoz mimo EÚ'),
  ('vat_Z', '', '2000-01-01', 'kategória Z — nulová sadzba (prázdne = netlačí sa)'),
  ('paid', 'Uhradené — neuhrádzajte.', '2000-01-01', 'platba kartou / v hotovosti'),
  ('prices_currency', 'Ceny sú uvedené v {currency}.', '2000-01-01', 'poznámka nad položkami');

-- What an issued invoice was printed with: texts (JSON code => text) and the print template version.
ALTER TABLE `{p}invoice` ADD COLUMN `legal_json` TEXT NULL;
ALTER TABLE `{p}invoice` ADD COLUMN `template` VARCHAR(40) NOT NULL DEFAULT '';
