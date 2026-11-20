-- Order costs (docs/sprava-firmy-specifikacia.md, module D): what an order costs the company besides the goods —
-- shipping by carrier, payment fee by payment method (% + fixed), packaging per order. Rules with validity dates
-- (a new price = a new rule from a date, the old one gets an end date — older orders keep the old cost).
CREATE TABLE IF NOT EXISTS `{p}order_cost_rule` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `shop_id` INT UNSIGNED NULL,                       -- e-shop of this database; NULL = all e-shops
  `type` VARCHAR(12) NOT NULL,                       -- shipping | payment | packaging
  `match_key` VARCHAR(64) NOT NULL DEFAULT '',       -- shipping: carrier id_reference; payment: module name; '' = any
  `label` VARCHAR(128) NOT NULL DEFAULT '',          -- carrier / payment name when the rule was saved (display)
  `fixed` DECIMAL(10,2) NOT NULL DEFAULT 0,          -- per order
  `percent` DECIMAL(6,3) NOT NULL DEFAULT 0,         -- of the order total paid by the customer
  `valid_from` DATE NULL,
  `valid_to` DATE NULL,
  `note` VARCHAR(255) NOT NULL DEFAULT '',
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `type` (`type`, `shop_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
