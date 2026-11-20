-- Archive of issued documents: the PDF exactly as it was issued (10-year retention, zákon o účtovníctve § 35) and its
-- SHA-256. Created right after issuing; never regenerated or changed afterwards.
CREATE TABLE IF NOT EXISTS `{p}invoice_pdf` (
  `invoice_id` INT UNSIGNED NOT NULL,
  `pdf` LONGBLOB NOT NULL,
  `sha256` CHAR(64) NOT NULL,
  `size` INT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`invoice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
