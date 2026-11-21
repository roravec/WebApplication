<?php
namespace Panel\Modules;

use Panel\Controller\OrderStatesController;
use Panel\Controller\ShopsController;
use Panel\Module;
use Panel\PanelContext;
use Panel\Rights;
use WebApplication\Http\Request;
use WebApplication\Http\Response;
use WebApplication\Http\Router;

/**
 * E-shopy: daily work (products, prices, sales, stock in the shop) in the menu; the connections (Webservice key) and the
 * ORDER STATES (they belong to the PrestaShop installation = shared by its shops) in Nastavenia → E-shopy.
 */
class EshopsModule implements Module
{
    public function routes(Router $r, callable $guard, PanelContext $ctx): void
    {
        $shops = new ShopsController($ctx);
        $states = new OrderStatesController($ctx);
        $SV = Rights::STOCK_VIEW;
        $SE = Rights::STOCK_EDIT;
        $SETTINGS = Rights::SETTINGS_ESHOPS;
        $r->get('/eshops', $guard($SV, function (Request $req, array $p) use ($shops) { return $shops->index($req, $p + ['mode' => 'work']); }));
        $r->get('/eshops/{cid:\d+}/{sid:\d+}', $guard($SV, [$shops, 'stock']));
        $r->post('/eshops/{cid:\d+}/{sid:\d+}', $guard($SE, [$shops, 'stockSave']));
        $r->get('/eshops/{cid:\d+}/{sid:\d+}/product/{pid:\d+}', $guard($SV, [$shops, 'product']));
        $r->post('/eshops/{cid:\d+}/{sid:\d+}/product/{pid:\d+}', $guard($SE, [$shops, 'productSave']));
        $r->post('/eshops/{cid:\d+}/{sid:\d+}/product/{pid:\d+}/sale', $guard($SE, [$shops, 'saleAdd']));
        $r->post('/eshops/{cid:\d+}/{sid:\d+}/product/{pid:\d+}/sale/{spid:\d+}/delete', $guard($SE, [$shops, 'saleDelete']));

        $r->get('/settings/eshops', $guard($SETTINGS, function (Request $req, array $p) use ($shops) { return $shops->index($req, $p + ['mode' => 'settings']); }));
        $r->get('/settings/eshops/new', $guard($SETTINGS, [$shops, 'form']));
        $r->post('/settings/eshops', $guard($SETTINGS, [$shops, 'save']));
        $r->get('/settings/eshops/{cid:\d+}/edit', $guard($SETTINGS, [$shops, 'form']));
        $r->post('/settings/eshops/{cid:\d+}/edit', $guard($SETTINGS, [$shops, 'save']));
        // order states of an installation, edited in the shop (?src = order source)
        $r->get('/settings/eshops/states', $guard($SETTINGS, [$states, 'index']));
        $r->get('/settings/eshops/states/{sid:\d+}', $guard($SETTINGS, [$states, 'form']));
        $r->post('/settings/eshops/states/{sid:\d+}', $guard($SETTINGS, [$states, 'save']));

        // old addresses (bookmarks, links in e-mails) — before 2026-11-21 under Nastavenia → E-shopy and Objednávky
        $to = function (string $path) use ($ctx) {
            return function (Request $req, array $p) use ($ctx, $path) {
                foreach ($p as $k => $v) {
                    $path = str_replace('{' . $k . '}', (string)$v, $path);
                }
                $q = $req->query();
                return Response::redirect($ctx->url($path) . ($q ? '?' . http_build_query($q) : ''), 301);
            };
        };
        $r->get('/stock/shops', $to('/eshops'));
        $r->get('/stock/shops/new', $to('/settings/eshops/new'));
        $r->get('/stock/shops/{cid:\d+}/edit', $to('/settings/eshops/{cid}/edit'));
        $r->get('/stock/shops/{cid:\d+}/{sid:\d+}', $to('/eshops/{cid}/{sid}'));
        $r->get('/stock/shops/{cid:\d+}/{sid:\d+}/product/{pid:\d+}', $to('/eshops/{cid}/{sid}/product/{pid}'));
        $r->get('/orders/states', $to('/settings/eshops/states'));
        $r->get('/orders/states/{sid:\d+}', $to('/settings/eshops/states/{sid}'));
    }

    public function nav(): array
    {
        return [['key' => 'eshops', 'group' => 'sales', 'path' => '/eshops', 'label' => 'E-shopy', 'permission' => Rights::STOCK_VIEW,
            'order' => 20]];
    }

    public function settingsTabs(): array
    {
        return [['key' => 'eshops', 'path' => '/settings/eshops', 'label' => 'E-shopy', 'permission' => Rights::SETTINGS_ESHOPS, 'order' => 20]];
    }

    public function permissions(): array
    {
        return [
            ['key' => Rights::SETTINGS_ESHOPS, 'group' => 'Nastavenia', 'sensitive' => true,
                'label' => 'Nastavenia → E-shopy — pripojenia (kľúče Webservice) a stavy objednávok'],
        ];
    }

    public function recordTypes(): array
    {
        return [];
    }
}
