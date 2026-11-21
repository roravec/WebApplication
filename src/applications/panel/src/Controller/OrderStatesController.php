<?php
namespace Panel\Controller;

use Panel\Audit;
use Panel\PanelContext;
use Panel\Shop\ShopCache;
use Panel\Shop\ShopOrderStates;
use Panel\Shop\ShopStock;
use WebApplication\Csrf;
use WebApplication\Http\Request;
use WebApplication\Http\Response;
use WebApplication\Session;

/**
 * Objednávky → Stavy objednávok (administrators): the order states of an e-shop edited IN THE SHOP through its
 * Webservice (Shop\ShopOrderStates) — name, e-mail template + sending, colour, flags; new state. `?src=` = order source
 * (0 = the e-shop of this database = the connection marked "this database", N = connection N).
 */
class OrderStatesController
{
    /** @var PanelContext */
    private $ctx;

    public function __construct(PanelContext $ctx)
    {
        $this->ctx = $ctx;
    }

    /** @return array{0: int, 1: ShopOrderStates, 2: int}|string [source, states, language id] or an error message */
    private function shop(Request $req)
    {
        $src = (int)$req->query('src', 0);
        try {
            $api = $this->ctx->orderWriteApi($src);
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
        if ($api === null) {
            return $src === 0
                ? 'Nie je pripojený e-shop tejto databázy — Sklad → E-shopy: pripojenie s kľúčom Webservice, „Aktívne“ a „E-shop je v tej istej databáze ako panel“.'
                : 'Pripojenie e-shopu je neaktívne (Sklad → E-shopy).';
        }
        try {
            $langId = (new ShopStock($api))->langId();
        } catch (\Throwable $e) {
            return 'E-shop neodpovedal: ' . $e->getMessage();
        }
        return [$src, new ShopOrderStates($api), $langId];
    }

    private function sources(): array
    {
        $out = [];
        foreach ($this->ctx->orderSources() as $id => $s) {
            $out[$id] = $s->label();
        }
        return $out;
    }

    /** GET /orders/states */
    public function index(Request $req, array $params): string
    {
        $shop = $this->shop($req);
        $states = [];
        $error = is_string($shop) ? $shop : null;
        if (!$error) {
            try {
                $states = $shop[1]->all($shop[2]);
            } catch (\Throwable $e) {
                $error = 'Stavy sa nedajú načítať: ' . $e->getMessage();
            }
        }
        return $this->ctx->page('orders/states', ['states' => $states, 'error' => $error, 'src' => (int)$req->query('src', 0),
            'sources' => $this->sources()], 'Stavy objednávok', 'orders');
    }

    /** GET /orders/states/{sid} (0 = new) */
    public function form(Request $req, array $params, array $errors = [], ?array $values = null)
    {
        $shop = $this->shop($req);
        if (is_string($shop)) {
            Session::flash('error', $shop);
            return Response::redirect($this->ctx->url('/orders/states'));
        }
        list($src, $repo, $langId) = $shop;
        $sid = (int)$params['sid'];
        try {
            $states = $repo->all($langId);
        } catch (\Throwable $e) {
            Session::flash('error', 'Stavy sa nedajú načítať: ' . $e->getMessage());
            return Response::redirect($this->ctx->url('/orders/states?src=' . $src));
        }
        if ($sid && !isset($states[$sid])) {
            return Response::html($this->ctx->page('notfound', [], 'Stav neexistuje', 'orders'), 404);
        }
        $values = $values ?? ($sid ? $states[$sid] : ['name' => '', 'template' => '', 'color' => '#4169e1', 'send_email' => false,
            'paid' => false, 'shipped' => false, 'delivery' => false, 'pdf_delivery' => false, 'logable' => false, 'hidden' => false]);
        return $this->ctx->page('orders/state-form', ['sid' => $sid, 'src' => $src, 'values' => $values, 'errors' => $errors,
            'state' => $sid ? $states[$sid] : null, 'templates' => ShopOrderStates::templates($states)],
            $sid ? 'Stav ' . $states[$sid]['name'] : 'Nový stav objednávky', 'orders');
    }

    /** POST /orders/states/{sid} */
    public function save(Request $req, array $params)
    {
        if (!Csrf::check($req)) {
            return Response::text('Formulár vypršal, načítajte stránku znova.', 400);
        }
        $shop = $this->shop($req);
        if (is_string($shop)) {
            Session::flash('error', $shop);
            return Response::redirect($this->ctx->url('/orders/states'));
        }
        list($src, $repo, $langId) = $shop;
        $sid = (int)$params['sid'];
        $v = ['name' => trim((string)$req->post('name', '')), 'template' => trim((string)$req->post('template', '')),
            'color' => trim((string)$req->post('color', ''))];
        foreach (ShopOrderStates::FLAGS as $f => $label) {
            $v[$f] = (bool)$req->post($f);
        }
        $errors = ShopOrderStates::errors($v);
        if ($errors) {
            return Response::html($this->form($req, $params, $errors, $v), 422);
        }
        try {
            $id = $repo->save($sid, $v, $langId);
        } catch (\Throwable $e) {
            return Response::html($this->form($req, $params, ['form' => 'E-shop stav neuložil: ' . $e->getMessage()], $v), 502);
        }
        if ($src) {
            (new ShopCache($this->ctx->db(), $src))->clear('states');   // orders read through the Webservice cache the states
        }
        $flags = array_keys(array_filter(array_intersect_key($v, ShopOrderStates::FLAGS)));
        Audit::log($this->ctx->db(), $this->ctx->user(), 'shop_state', $id, $sid ? 'update' : 'create',
            $v['name'] . ' (e-mail: ' . ($v['send_email'] ? $v['template'] : 'nie') . '; ' . implode(', ', $flags) . ')');
        Session::flash('msg', 'Stav „' . $v['name'] . '“ uložený v e-shope.' . ($v['send_email'] ? ' Pri prechode do neho dostane zákazník e-mail (šablóna ' . $v['template'] . ').' : ''));
        return Response::redirect($this->ctx->url('/orders/states' . ($src ? '?src=' . $src : '')));
    }
}
