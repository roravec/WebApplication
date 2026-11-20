-- Connected e-shops (PrestaShop installations, local or remote) managed through their Webservice API.
-- The key is ENCRYPTED (Panel\Secret). One installation can contain several shops (multistore).
CREATE TABLE IF NOT EXISTS `{p}shop_connection` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(120) NOT NULL,
  `platform` VARCHAR(20) NOT NULL DEFAULT 'prestashop',
  `url` VARCHAR(255) NOT NULL,                            -- shop base URL, e.g. https://www.erma.sk/store/
  `api_key` TEXT NOT NULL,                                -- encrypted
  `active` TINYINT NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
