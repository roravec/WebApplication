<?php
namespace Panel\Controller;

use Panel\Audit;
use Panel\PanelContext;
use Panel\Rights;
use Panel\Shop\OrderSource;
use Panel\Shop\ShopOrdersApi;
use Panel\Shop\ShopOrderState;
use WebApplication\Csrf;
use WebApplication\Http\Request;
use WebApplication\Http\Response;
use WebApplication\Session;

/**
 * Orders from all e-shops — read from the PrestaShop tables of this database (source 0) or through the Webservice of a
 * connected PrestaShop (source = connection id, Shop\ShopOrdersApi). State changes always go through the Webservice.
 */
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
        $sources = $this->ctx->orderSources();
        if (!$sources) {
            return $this->ctx->page('orders/unavailable', [], 'Objednávky', 'orders');
        }
        $srcId = (int)$req->query('src', (string)array_keys($sources)[0]);
        $source = $sources[$srcId] ?? reset($sources);
        $filters = [
            'src' => $source->id(),
            'shop' => (int)$req->query('shop', 0),
            'state' => (int)$req->query('state', 0),
            'from' => (string)$req->query('from', ''),
            'to' => (string)$req->query('to', ''),
            'q' => (string)$req->query('q', ''),
        ];
        $page = max(1, (int)$req->query('page', 1));
        $error = null;
        $shops = $states = $rows = [];
        $total = 0;
        try {
            $shops = $source->shops();
            $states = $source->states();
            $result = $source->search($filters, $page, self::PER_PAGE);
            $rows = $result['rows'];
            $total = $result['total'];
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }
        return $this->ctx->page('orders/list', [
            'filters' => $filters,
            'sources' => $sources,
            'error' => $error,
            'shops' => $shops,
            'states' => $states,
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int)ceil($total / self::PER_PAGE)),
        ], 'Objednávky', 'orders');
    }

    /** GET /orders/{id} (source 0) and /orders/{src}/{id} (connected e-shop). */
    public function show(Request $req, array $params)
    {
        $src = (int)($params['src'] ?? 0);
        $source = $this->ctx->orderSource($src);
        try {
            $order = $source ? $source->find((int)$params['id']) : null;
        } catch (\Throwable $e) {
            return $this->ctx->page('orders/unavailable', ['error' => $e->getMessage()], 'Objednávky', 'orders');
        }
        if ($order === null) {
            return Response::html($this->ctx->page('notfound', [], 'Objednávka neexistuje', 'orders'), 404);
        }
        $invoice = $this->ctx->invoices()->findByOrder($src, (int)$order['id_order']);
        $companyId = $this->ctx->companies()->companyForShop($src, (int)$order['id_shop']);
        $canChange = $this->ctx->can(Rights::ORDERS_EDIT) && $this->writeConnection($src) !== null;
        $states = [];
        if ($canChange) {
            try {
                $states = $source->states();
            } catch (\Throwable $e) {
                $canChange = false;
            }
        }
        // e-mails to the customer: invoices sent from the panel + what the shop itself sent (its mail log; SQL source only)
        $panelEmails = $invoice ? $this->ctx->invoices()->emails((int)$invoice['id']) : [];
        $shopEmails = $source instanceof \Panel\Shop\ShopOrders ? $source->mailLog((string)$order['customer_email'], (string)$order['date_add']) : null;
        $log = new \Panel\Shop\OrderLog($this->ctx->db());
        $orderId = (int)$order['id_order'];
        return $this->ctx->page('orders/detail', ['o' => $order, 'source' => $source, 'invoice' => $invoice, 'invoiceCompany' => $companyId,
            'panelEmails' => $panelEmails, 'shopEmails' => $shopEmails,
            'notes' => $log->notes($src, $orderId), 'messages' => $log->messages($src, $orderId), 'returns' => $log->returns($src, $orderId),
            'canEdit' => $canChange,
            'canInvoice' => $this->ctx->can(Rights::INVOICES_EDIT) && $companyId && $this->ctx->canCompany($companyId),
            'canSeeInvoice' => $this->ctx->can(Rights::INVOICES_VIEW),
            'canChangeState' => $canChange, 'states' => $states],
            'Objednávka ' . $order['reference'], 'orders');
    }

    /** Connection whose Webservice changes orders of this source (source 0 → the one marked "this database"). */
    private function writeConnection(int $src): ?array
    {
        if ($src === 0) {
            return $this->ctx->localConnection();
        }
        $c = $this->ctx->shopConnections()[$src] ?? null;
        return $c && $c['active'] ? $c : null;
    }

    /** POST /orders/[{src}/]{id}/state — change the order state IN THE SHOP (order_histories via Webservice). */
    public function changeState(Request $req, array $params)
    {
        if (!Csrf::check($req)) {
            return Response::text('Formulár vypršal, načítajte stránku znova.', 400);
        }
        $src = (int)($params['src'] ?? 0);
        $source = $this->ctx->orderSource($src);
        try {
            $order = $source ? $source->find((int)$params['id']) : null;
        } catch (\Throwable $e) {
            return $this->ctx->page('orders/unavailable', ['error' => $e->getMessage()], 'Objednávky', 'orders');
        }
        if ($order === null) {
            return Response::html($this->ctx->page('notfound', [], 'Objednávka neexistuje', 'orders'), 404);
        }
        $back = Response::redirect($this->ctx->orderUrl($src, (int)$order['id_order']));
        $c = $this->writeConnection($src);
        $stateId = (int)$req->post('state', 0);
        $names = array_column($source->states(), 'name', 'id_order_state');
        if ($c === null || !isset($names[$stateId])) {
            Session::flash('error', $c === null ? 'Nie je pripojený e-shop tejto databázy (Sklad → E-shopy).' : 'Neplatný stav.');
            return $back;
        }
        $sendEmail = (bool)$req->post('sendemail');
        try {
            $new = (new ShopOrderState($this->ctx->shopApi($c)))->change((int)$order['id_order'], (int)$order['id_shop'],
                (string)$order['reference'], $stateId, $sendEmail);
        } catch (\Throwable $e) {
            Session::flash('error', 'Stav sa nezmenil: ' . $e->getMessage());
            return $back;
        }
        if ($source instanceof ShopOrdersApi) {
            $source->forgetOrders();
        }
        Audit::log($this->ctx->db(), $this->ctx->user(), 'shop_order', (int)$order['id_order'], 'state',
            ($src ? $source->label() . ' ' : '') . $order['reference'] . ': ' . $order['state'] . ' → ' . ($names[$new] ?? $new)
            . ($sendEmail ? ' (s e-mailom)' : ''));
        Session::flash('msg', 'Stav v e-shope: ' . ($names[$new] ?? $new) . '.');
        return $back;
    }
}
