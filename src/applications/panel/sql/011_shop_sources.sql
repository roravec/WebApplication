-- Orders of e-shops reached only through the Webservice (ShopOrdersApi): every PrestaShop installation numbers its
-- orders, shops and customers from 1 → links to them carry the shop connection (0 = the PrestaShop whose tables are in
-- this database, as before).
ALTER TABLE `{p}invoice` ADD COLUMN `shop_connection_id` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `shop_id`,
  DROP INDEX `shop_order`, ADD UNIQUE KEY `shop_order` (`shop_connection_id`, `shop_order_id`);
ALTER TABLE `{p}customer` ADD COLUMN `shop_connection_id` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `shop_customer_id`,
  DROP INDEX `shop_customer`, ADD KEY `shop_customer` (`shop_connection_id`, `shop_customer_id`);
ALTER TABLE `{p}company_shop` ADD COLUMN `connection_id` INT UNSIGNED NOT NULL DEFAULT 0 FIRST,
  DROP PRIMARY KEY, ADD PRIMARY KEY (`connection_id`, `shop_id`);

-- Short cache of rarely-changing Webservice answers (order states, shops, currencies, countries, carriers) — Shop\ShopCache.
CREATE TABLE IF NOT EXISTS `{p}shop_cache` (
  `connection_id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(60) NOT NULL,
  `data` MEDIUMTEXT NOT NULL,
  `fetched_at` DATETIME NOT NULL,
  PRIMARY KEY (`connection_id`, `name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
