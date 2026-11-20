-- Payments of issued documents and e-mails sent with them. Neither changes the issued document itself.

-- Payments (úhrady). A credit note's refund is a negative amount. Paid = sum of payments reaches the total.
CREATE TABLE IF NOT EXISTS `{p}invoice_payment` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `invoice_id` INT UNSIGNED NOT NULL,
  `paid_on` DATE NOT NULL,
  `amount` DECIMAL(15,2) NOT NULL,
  `method` VARCHAR(20) NOT NULL DEFAULT 'transfer',
  `note` VARCHAR(255) NOT NULL DEFAULT '',
  `created_by` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `invoice` (`invoice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Every e-mail sent with a document (to whom, when, by whom, result).
CREATE TABLE IF NOT EXISTS `{p}invoice_email` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `invoice_id` INT UNSIGNED NOT NULL,
  `recipients` VARCHAR(500) NOT NULL,
  `subject` VARCHAR(255) NOT NULL,
  `status` VARCHAR(10) NOT NULL,                       -- sent | failed
  `error` VARCHAR(500) NOT NULL DEFAULT '',
  `client_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `invoice` (`invoice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Per-company e-mail settings (sender, reply-to, copy, subject and text templates) as JSON.
-- SMTP credentials are NOT here — they are in config.php (apps.panel.mail).
ALTER TABLE `{p}company` ADD COLUMN `mail_settings` TEXT NULL;

-- Documents paid by card / in cash were paid when issued: record that for the existing ones.
INSERT INTO `{p}invoice_payment` (invoice_id, paid_on, amount, method, note, created_at)
  SELECT id, issue_date, total, payment_method, 'uhradené pri vystavení', NOW() FROM `{p}invoice`
  WHERE status = 'issued' AND payment_method IN ('card', 'cash') AND total <> 0;
