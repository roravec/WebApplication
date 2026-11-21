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
}
