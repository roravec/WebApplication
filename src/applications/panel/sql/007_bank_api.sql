-- Automatic download of bank movements: provider (fio) and its API token per bank account, ENCRYPTED (Panel\Secret,
-- key from config.php). The last download is remembered for the next "from" date.
ALTER TABLE `{p}company_bank` ADD COLUMN `api_provider` VARCHAR(20) NOT NULL DEFAULT '';
ALTER TABLE `{p}company_bank` ADD COLUMN `api_token` TEXT NULL;
ALTER TABLE `{p}company_bank` ADD COLUMN `api_last_download` DATETIME NULL;
