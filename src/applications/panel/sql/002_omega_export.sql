-- Export to the accountant's Omega (KROS): per-company export settings (JSON) and when a document was last exported.
ALTER TABLE `{p}company` ADD COLUMN `export_settings` TEXT NULL;
ALTER TABLE `{p}invoice` ADD COLUMN `omega_exported_at` DATETIME NULL;
