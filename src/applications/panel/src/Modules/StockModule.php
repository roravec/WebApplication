<?php
namespace Panel\Modules;

use Panel\Controller\StockController;
use Panel\Module;
use Panel\PanelContext;
use Panel\Rights;
use WebApplication\Http\Router;

/** Sklad: own warehouses — items, documents, warehouses (the stock of e-shops is EshopsModule). */
class StockModule implements Module
{
    public function routes(Router $r, callable $guard, PanelContext $ctx): void
    {
        $stock = new StockController($ctx);
        $SV = Rights::STOCK_VIEW;
        $SE = Rights::STOCK_EDIT;
        $r->get('/stock', $guard($SV, [$stock, 'index']));
        $r->get('/stock/warehouses', $guard($SV, [$stock, 'warehouses']));
        $r->get('/stock/warehouses/new', $guard($SE, [$stock, 'warehouseForm']));
        $r->get('/stock/warehouses/{id:\d+}', $guard($SE, [$stock, 'warehouseForm']));
        $r->post('/stock/warehouses', $guard($SE, [$stock, 'warehouseSave']));
        $r->post('/stock/warehouses/{id:\d+}', $guard($SE, [$stock, 'warehouseSave']));
        $r->get('/stock/items/new', $guard($SE, [$stock, 'itemForm']));
        $r->get('/stock/items/{id:\d+}', $guard($SV, [$stock, 'item']));
        $r->get('/stock/items/{id:\d+}/edit', $guard($SE, [$stock, 'itemForm']));
        $r->post('/stock/items', $guard($SE, [$stock, 'itemSave']));
        $r->post('/stock/items/{id:\d+}', $guard($SE, [$stock, 'itemSave']));
        $r->get('/stock/docs', $guard($SV, [$stock, 'docs']));
        $r->get('/stock/docs/new', $guard($SE, [$stock, 'docForm']));
        $r->get('/stock/docs/{id:\d+}', $guard($SV, [$stock, 'doc']));
        $r->post('/stock/docs', $guard($SE, [$stock, 'docSave']));
    }

    public function nav(): array
    {
        return [['key' => 'stock', 'group' => 'stock', 'path' => '/stock', 'label' => 'Sklad', 'permission' => Rights::STOCK_VIEW, 'order' => 10]];
    }

    public function settingsTabs(): array
    {
        return [];
    }

    public function permissions(): array
    {
        return [
            ['key' => Rights::STOCK_VIEW, 'group' => 'Sklad', 'sensitive' => false,
                'label' => 'Sklad — prezeranie stavu, pohybov a skladu e-shopov'],
            ['key' => Rights::STOCK_EDIT, 'group' => 'Sklad', 'sensitive' => false,
                'label' => 'Sklad — príjemky, výdajky, prevodky, inventúra, karty a sklady; produkty a sklad v e-shope'],
        ];
    }

    public function recordTypes(): array
    {
        $row = function (PanelContext $ctx, string $table, string $cols, int $id): ?array {
            if (!$ctx->can(Rights::STOCK_VIEW)) {
                return null;
            }
            $r = $ctx->db()->fetchOne("SELECT id, company_id, $cols FROM " . $ctx->db()->table($table) . ' WHERE id = ?', [$id]);
            return $r && $ctx->canCompany((int)$r['company_id']) ? $r : null;
        };
        $lookup = function (string $type, string $table, string $where) {
            return function (PanelContext $ctx, string $q) use ($type, $table, $where): array {
                $ids = $ctx->companyIds();
                if (!$ids || !$ctx->can(Rights::STOCK_VIEW)) {
                    return [];
                }
                $like = '%' . addcslashes($q, '%_\\') . '%';
                return array_map(function ($id) use ($type) { return $type . ':' . $id; }, array_column($ctx->db()->query('SELECT id FROM ' . $ctx->db()->table($table)
                    . " WHERE $where AND company_id IN (" . implode(',', array_map('intval', $ids)) . ') ORDER BY id DESC LIMIT 5',
                    array_fill(0, substr_count($where, '?'), $like)), 'id'));
            };
        };
        return [
            ['type' => 'stock_item', 'label' => 'Skladová karta',
                'resolve' => function (PanelContext $ctx, array $ids) use ($row): ?array {
                    $r = $row($ctx, 'stock_item', 'sku, name', $ids[0]);
                    return $r ? ['title' => $r['sku'] . ' ' . $r['name'], 'url' => '/stock/items/' . $r['id'], 'company_id' => (int)$r['company_id']] : null;
                },
                'lookup' => $lookup('stock_item', 'stock_item', '(sku LIKE ? OR name LIKE ?)')],
            ['type' => 'stock_doc', 'label' => 'Skladový doklad',
                'resolve' => function (PanelContext $ctx, array $ids) use ($row): ?array {
                    $r = $row($ctx, 'stock_doc', 'number', $ids[0]);
                    return $r ? ['title' => 'Doklad ' . $r['number'], 'url' => '/stock/docs/' . $r['id'], 'company_id' => (int)$r['company_id']] : null;
                },
                'lookup' => $lookup('stock_doc', 'stock_doc', 'number LIKE ?')],
            ['type' => 'warehouse', 'label' => 'Sklad',
                'resolve' => function (PanelContext $ctx, array $ids) use ($row): ?array {
                    $r = $row($ctx, 'warehouse', 'code, name', $ids[0]);
                    return $r ? ['title' => 'Sklad ' . $r['name'], 'url' => $ctx->can(Rights::STOCK_EDIT) ? '/stock/warehouses/' . $r['id'] : '/stock/warehouses',
                        'company_id' => (int)$r['company_id']] : null;
                }],
        ];
    }
}
