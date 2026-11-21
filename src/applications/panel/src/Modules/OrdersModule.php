<?php
namespace Panel\Modules;

use Panel\Controller\OrdersController;
use Panel\Controller\OrderToolsController;
use Panel\Module;
use Panel\PanelContext;
use Panel\Rights;
use WebApplication\Http\Router;

/** Objednávky e-shopov: list, detail, changes in the shop, Expedícia (invoice from an order → InvoicingModule). */
class OrdersModule implements Module
{
    public function routes(Router $r, callable $guard, PanelContext $ctx): void
    {
        $orders = new OrdersController($ctx);
        $tools = new OrderToolsController($ctx);
        $r->get('/orders', $guard(Rights::ORDERS_VIEW, [$orders, 'index']));
        $r->get('/shipping', $guard(Rights::ORDERS_VIEW, [$tools, 'shipping']));
        $r->post('/shipping', $guard(Rights::ORDERS_VIEW, [$tools, 'shippingAction']));   // writes check orders.edit inside
        // /orders/{id} = order in the shop tables of this database; /orders/{src}/{id} = order of shop connection {src}
        foreach (self::orderPaths() as $o) {
            $r->get($o, $guard(Rights::ORDERS_VIEW, [$orders, 'show']));
            $r->post($o . '/state', $guard(Rights::ORDERS_EDIT, [$orders, 'changeState']));
            $r->post($o . '/tracking', $guard(Rights::ORDERS_EDIT, [$tools, 'tracking']));
            $r->post($o . '/note', $guard(Rights::ORDERS_VIEW, [$tools, 'note']));
            $r->get($o . '/message', $guard(Rights::ORDERS_EDIT, [$tools, 'messageForm']));
            $r->post($o . '/message', $guard(Rights::ORDERS_EDIT, [$tools, 'message']));
            $r->get($o . '/address/{type:[a-z]+}', $guard(Rights::ORDERS_EDIT, [$tools, 'addressForm']));
            $r->post($o . '/address/{type:[a-z]+}', $guard(Rights::ORDERS_EDIT, [$tools, 'address']));
            $r->get($o . '/lines', $guard(Rights::ORDERS_EDIT, [$tools, 'linesForm']));
            $r->post($o . '/lines', $guard(Rights::ORDERS_EDIT, [$tools, 'linesSave']));
            $r->get($o . '/return', $guard(Rights::ORDERS_EDIT, [$tools, 'returnForm']));
            $r->post($o . '/return', $guard(Rights::ORDERS_EDIT, [$tools, 'returnSave']));
            $r->get($o . '/delivery-note', $guard(Rights::ORDERS_VIEW, [$tools, 'deliveryNote']));
        }
    }

    /** Paths of one order: source 0 (old links keep working) and source N. */
    public static function orderPaths(): array
    {
        return ['/orders/{id:\d+}', '/orders/{src:\d+}/{id:\d+}'];
    }

    public function nav(): array
    {
        return [['key' => 'orders', 'group' => 'sales', 'path' => '/orders', 'label' => 'Objednávky', 'permission' => Rights::ORDERS_VIEW,
            'order' => 10]];
    }

    public function settingsTabs(): array
    {
        return [];
    }

    public function permissions(): array
    {
        return [
            ['key' => Rights::ORDERS_VIEW, 'group' => 'Objednávky', 'sensitive' => false,
                'label' => 'Objednávky z e-shopov — prezeranie, poznámky, expedícia'],
            ['key' => Rights::ORDERS_EDIT, 'group' => 'Objednávky', 'sensitive' => false,
                'label' => 'Objednávky — zmeny v e-shope (stav, sledovanie, adresy, položky, vrátenie, správa zákazníkovi)'],
        ];
    }
}
