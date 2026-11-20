-- Bank statement import: the bank's reference of the transaction, so the same statement can't be imported twice.
ALTER TABLE `{p}invoice_payment` ADD COLUMN `bank_ref` VARCHAR(64) NULL;
ALTER TABLE `{p}invoice_payment` ADD UNIQUE KEY `bank_ref` (`bank_ref`);

-- What kind of e-mail was sent with a document: invoice | reminder (upomienka).
ALTER TABLE `{p}invoice_email` ADD COLUMN `kind` VARCHAR(10) NOT NULL DEFAULT 'invoice';
