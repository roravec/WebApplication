<?php
namespace Panel\Controller;

use Panel\PanelContext;
use WebApplication\Http\Request;
use WebApplication\Http\Response;

/** Orders from all e-shops — read-only view of the PrestaShop database. */
class OrdersController
{
    const PER_PAGE = 50;

    /** @var PanelContext */
    private $ctx;

    public function __construct(PanelContext $ctx)
    {
        $this->ctx = $ctx;
    }

    public function index(Request $req, array $params): string
    {
        $shop = $this->ctx->shopOrders();
        if (!$shop->available()) {
            return $this->ctx->page('orders/unavailable', [], 'Objednávky', 'orders');
        }
        $filters = [
            'shop' => (int)$req->query('shop', 0),
            'state' => (int)$req->query('state', 0),
            'from' => (string)$req->query('from', ''),
            'to' => (string)$req->query('to', ''),
            'q' => (string)$req->query('q', ''),
        ];
        $page = max(1, (int)$req->query('page', 1));
        $result = $shop->search($filters, $page, self::PER_PAGE);
        return $this->ctx->page('orders/list', [
            'filters' => $filters,
            'shops' => $shop->shops(),
            'states' => $shop->states(),
            'rows' => $result['rows'],
            'total' => $result['total'],
            'page' => $page,
            'pages' => max(1, (int)ceil($result['total'] / self::PER_PAGE)),
        ], 'Objednávky', 'orders');
    }

    public function show(Request $req, array $params)
    {
        $order = $this->ctx->shopOrders()->find((int)$params['id']);
        if ($order === null) {
            return Response::html($this->ctx->page('notfound', [], 'Objednávka neexistuje', 'orders'), 404);
        }
        return $this->ctx->page('orders/detail', ['o' => $order],
            'Objednávka ' . $order['reference'], 'orders');
    }
}
