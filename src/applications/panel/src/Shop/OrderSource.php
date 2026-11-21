<?php
namespace Panel\Shop;

/**
 * Where the panel reads e-shop orders from (docs/sklad-specifikacia.md, "Objednávky"):
 * - id 0 = the PrestaShop whose tables are in the panel's database (ShopOrders, SQL, read-only),
 * - id N = shop connection N reached only through its Webservice (ShopOrdersApi) — any PrestaShop, also remote.
 * Every installation numbers orders, shops and customers from 1, so the panel always keeps (source id, id) together.
 * Rows and orders returned by both carry the same keys (PrestaShop column names) + `src`.
 */
interface OrderSource
{
    public function id(): int;

    public function label(): string;

    public function available(): bool;

    /** @return array list of [id_shop, name] */
    public function shops(): array;

    /** @return array list of [id_order_state, name, color, paid, shipped, delivery] (not deleted), sorted by name */
    public function states(): array;

    /**
     * Orders, newest first. @param array $f shop, state, from, to (Y-m-d), q (reference / id / customer / e-mail / company)
     * @return array{rows: array, total: int}
     */
    public function search(array $f, int $page = 1, int $perPage = 50): array;

    /** One order with lines, address_invoice, address_delivery, payments, history; null if it does not exist. */
    public function find(int $id): ?array;

    /** Only id_order, reference, id_shop — cheap (record references, links); null = does not exist / foreign e-shop. */
    public function brief(int $id): ?array;

    /** today, week, month, month_total, per_shop [name, orders, total] (last 30 days). */
    public function stats(): array;

    /**
     * Only these shops from now on (the person's e-shops, Core\Access::shopIdsFor); null = all. shops(), search(),
     * find() and stats() then never return anything of another shop (find() = null) — set by PanelContext.
     * @param int[]|null $shopIds
     */
    public function restrictShops(?array $shopIds): void;
}
