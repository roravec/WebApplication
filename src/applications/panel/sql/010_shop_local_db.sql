-- Which connected PrestaShop is the one whose tables (ps_*) the panel reads directly (orders list) — order state
-- changes for those orders go through this connection's Webservice.
ALTER TABLE `{p}shop_connection` ADD COLUMN `local_db` TINYINT NOT NULL DEFAULT 0;
