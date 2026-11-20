#!/usr/bin/env bash
# Dev only: import the PrestaShop tables (ps_*) from a ps-pack database dump into the dev database,
# so the panel can show real orders. Visitor-statistics tables are imported without rows.
# Customer e-mails are anonymised (customer<id>@example.invalid).
#
#   ./import-shop.sh [dump.sql.gz]     default: newest ~/webstore/backups/database-*.sql.gz that holds the shop
#                                      (ps-pack dumps every database — molesk, ermaskretail … — into its own file)
set -euo pipefail
cd "$(dirname "$0")"
newest_shop_dump() {
    local f
    for f in $(ls -t "$HOME"/webstore/backups/database-*.sql.gz | grep -v TRUNCATED); do
        if zgrep -q 'CREATE TABLE `ps_configuration`' "$f"; then echo "$f"; return; fi
    done
}
DUMP=${1:-$(newest_shop_dump)}
[ -n "$DUMP" ] || { echo "no dump with the shop tables in ~/webstore/backups"; exit 1; }
export COMPOSE_PROJECT_NAME=${COMPOSE_PROJECT_NAME:-webapplication}
NODATA="ps_connections ps_connections_page ps_connections_source ps_guest ps_pagenotfound ps_page_viewed ps_statssearch ps_log"

echo "== importing ps_* from $DUMP"
zcat "$DUMP" | awk -v nodata="$NODATA" '
    BEGIN { n = split(nodata, a, " "); for (i = 1; i <= n; i++) skip[a[i]] = 1; keep = 1 }
    /^DROP TABLE IF EXISTS `/ { t = $5; gsub(/[`;]/, "", t); keep = (t ~ /^ps_/) }
    /^CREATE TABLE `/         { t = $3; gsub(/`/, "", t);    keep = (t ~ /^ps_/) }
    /^INSERT INTO `/          { t = $3; gsub(/`/, "", t);    keep = (t ~ /^ps_/) && !(t in skip) }
    /^SET FOREIGN_KEY_CHECKS=1/ { keep = 1 }
    keep' | docker compose exec -T db mariadb -uwebapp -pwebapp webapp

echo "== anonymising customer e-mails"
docker compose exec -T db mariadb -uwebapp -pwebapp webapp -e "
    UPDATE ps_customer SET email = CONCAT('customer', id_customer, '@example.invalid');
    UPDATE ps_emailsubscription SET email = CONCAT('subscriber', id, '@example.invalid');
    SELECT COUNT(*) AS orders FROM ps_orders;"
