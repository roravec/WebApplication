-- Permission system, phase 1 step a (docs/panel-architektura.md chapter 5, owner 2026-11-21):
-- roles (named, editable sets of permissions), exceptions per person, scope = companies + e-shops of the person.
-- Správca = client.rights >= 100 stays as before (everything, every company, every e-shop) — no role row for it.
-- Existing employees keep EXACTLY their access: their permission rows become "allowed" exceptions, their companies stay,
-- e-shops = all (as before). The roles below are only offered — nobody gets one by this migration.

CREATE TABLE IF NOT EXISTS `{p}role` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `description` VARCHAR(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{p}role_permission` (
  `role_id` INT UNSIGNED NOT NULL,
  `permission` VARCHAR(40) NOT NULL,
  PRIMARY KEY (`role_id`, `permission`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{p}client_role` (
  `client_id` INT UNSIGNED NOT NULL,
  `role_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`client_id`, `role_id`),
  KEY `role` (`role_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- scope flags; no row = only the listed companies, all e-shops
CREATE TABLE IF NOT EXISTS `{p}client_scope` (
  `client_id` INT UNSIGNED NOT NULL,
  `all_companies` TINYINT(1) NOT NULL DEFAULT 0,
  `all_shops` TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- e-shops of a person with all_shops = 0: (order source = 0 this database / connection id, id_shop) like company_shop
CREATE TABLE IF NOT EXISTS `{p}shop_access` (
  `client_id` INT UNSIGNED NOT NULL,
  `connection_id` INT UNSIGNED NOT NULL,
  `shop_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`client_id`, `connection_id`, `shop_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- the old per-person permissions become exceptions: allow = 1 extra permission, 0 = taken away although a role gives it
ALTER TABLE `{p}permission` ADD COLUMN `allow` TINYINT(1) NOT NULL DEFAULT 1;

INSERT IGNORE INTO `{p}client_scope` (client_id, all_companies, all_shops)
  SELECT id, 0, 1 FROM `{p}client` WHERE type = 0 AND rights < 100;

-- offered roles (editable in Nastavenia → Zamestnanci → Roly)
INSERT IGNORE INTO `{p}role` (name, description) VALUES
  ('Fakturácia a účtovníctvo', 'faktúry, úhrady, náklady, výsledky; objednávky len prezerá'),
  ('Expedícia', 'objednávky a ich stavy, sklad e-shopov len prezerá, faktúry prezerá'),
  ('Sklad a nákup', 'sklad (príjemky, výdajky, inventúra), sklad e-shopov, prezeranie objednávok a nákladov'),
  ('Len čítanie', 'prezeranie objednávok, faktúr, skladu a nákladov bez zmien; bez výsledkov firmy');
INSERT IGNORE INTO `{p}role_permission` (role_id, permission)
  SELECT r.id, p.perm FROM `{p}role` r JOIN (
    SELECT 'orders.view' AS perm UNION ALL SELECT 'invoices.view' UNION ALL SELECT 'invoices.edit' UNION ALL SELECT 'expenses.view'
    UNION ALL SELECT 'expenses.edit' UNION ALL SELECT 'reports.view') p WHERE r.name = 'Fakturácia a účtovníctvo';
INSERT IGNORE INTO `{p}role_permission` (role_id, permission)
  SELECT r.id, p.perm FROM `{p}role` r JOIN (
    SELECT 'orders.view' AS perm UNION ALL SELECT 'orders.edit' UNION ALL SELECT 'invoices.view' UNION ALL SELECT 'stock.view') p
  WHERE r.name = 'Expedícia';
INSERT IGNORE INTO `{p}role_permission` (role_id, permission)
  SELECT r.id, p.perm FROM `{p}role` r JOIN (
    SELECT 'orders.view' AS perm UNION ALL SELECT 'stock.view' UNION ALL SELECT 'stock.edit' UNION ALL SELECT 'expenses.view') p
  WHERE r.name = 'Sklad a nákup';
INSERT IGNORE INTO `{p}role_permission` (role_id, permission)
  SELECT r.id, p.perm FROM `{p}role` r JOIN (
    SELECT 'orders.view' AS perm UNION ALL SELECT 'invoices.view' UNION ALL SELECT 'stock.view' UNION ALL SELECT 'expenses.view') p
  WHERE r.name = 'Len čítanie';
