<?php
namespace Panel\Controller;

use Panel\Audit;
use Panel\Invoicing\Pdf;
use Panel\PanelContext;
use Panel\Rights;
use Panel\Shop\OrderLog;
use Panel\Shop\OrderSource;
use Panel\Shop\PrestaShopApi;
use Panel\Shop\ShopOrderEdit;
use Panel\Shop\ShopOrderLines;
use Panel\Shop\ShopOrdersApi;
use Panel\Shop\ShopOrderState;
use Panel\Shop\ShopStock;
use Panel\ShopMail;
use WebApplication\Csrf;
use WebApplication\Http\Request;
use WebApplication\Http\Response;
use WebApplication\Session;

/**
 * Order management (owner 2026-11-20 "úprava objednávky, expedícia, vrátenie a dobropisy"): tracking number, delivery /
 * invoice address, internal notes, messages to the customer in the shop's look, returns (restock in the shop + credit
 * note + state), delivery notes and picking lists (PDF) and Objednávky → Expedícia (bulk tracking numbers / states / PDFs).
 * Changes in the shop ONLY through its Webservice (Shop\ShopOrderEdit, ShopOrderState, ShopStock) — never SQL into ps_*.
 */
class OrderToolsController
{
    const BULK_MAX = 100;

    /** @var PanelContext */
    private $ctx;

    public function __construct(PanelContext $ctx)
    {
        $this->ctx = $ctx;
    }

    private function log(): OrderLog
    {
        return new OrderLog($this->ctx->db());
    }

    /** @return array{0: int, 1: OrderSource, 2: array}|Response [source id, source, order] */
    private function load(array $params)
    {
        $src = (int)($params['src'] ?? 0);
        $source = $this->ctx->orderSource($src);
        try {
            $order = $source ? $source->find((int)$params['id']) : null;
        } catch (\Throwable $e) {
            return Response::html($this->ctx->page('orders/unavailable', ['error' => $e->getMessage()], 'Objednávky', 'orders'), 502);
        }
        if ($order === null) {
            return Response::html($this->ctx->page('notfound', [], 'Objednávka neexistuje', 'orders'), 404);
        }
        return [$src, $source, $order];
    }

    /** load() for a POST: CSRF check first. */
    private function loadPost(Request $req, array $params)
    {
        if (!Csrf::check($req)) {
            return Response::text('Formulár vypršal, načítajte stránku znova.', 400);
        }
        return $this->load($params);
    }

    /** Webservice that changes orders of this source; null = no connection (Nastavenia → E-shopy). */
    private function api(int $src): ?PrestaShopApi
    {
        try {
            return $this->ctx->orderWriteApi($src);
        } catch (\Throwable $e) {
            Session::flash('error', $e->getMessage());
            return null;
        }
    }

    /** Is there a usable Webservice connection for this source (without flashing an error)? */
    private function hasApi(int $src): bool
    {
        try {
            return $this->ctx->orderWriteApi($src) !== null;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function back(int $src, array $o, string $anchor = ''): Response
    {
        return Response::redirect($this->ctx->orderUrl($src, (int)$o['id_order']) . $anchor);
    }

    private function forget(OrderSource $source): void
    {
        if ($source instanceof ShopOrdersApi) {
            $source->forgetOrders();
        }
    }

    private function audit(int $src, OrderSource $source, array $o, string $action, string $details): void
    {
        Audit::log($this->ctx->db(), $this->ctx->user(), 'shop_order', (int)$o['id_order'], $action,
            ($src ? $source->label() . ' ' : '') . $o['reference'] . ': ' . $details, 'order:' . $src . ':' . (int)$o['id_order']);
    }

    // ------------------------------------------------------------------ tracking number (+ state)

    /** POST /orders/[{src}/]{id}/tracking — tracking number in the shop, optionally a new state (e.g. shipped, with e-mail). */
    public function tracking(Request $req, array $params)
    {
        $l = $this->loadPost($req, $params);
        if ($l instanceof Response) {
            return $l;
        }
        list($src, $source, $o) = $l;
        $api = $this->api($src);
        if ($api === null) {
            Session::flash('error', Session::flash('error') ?: 'Nie je pripojený e-shop tejto databázy (Nastavenia → E-shopy).');
            return $this->back($src, $o);
        }
        $number = trim((string)$req->post('tracking', ''));
        $state = (int)$req->post('state', 0);
        try {
            if ($number !== (string)($o['tracking'] ?? '')) {
                (new ShopOrderEdit($api))->setTracking((int)$o['id_order'], (int)$o['id_shop'], (string)$o['reference'], mb_substr($number, 0, 64, 'UTF-8'));
                $this->audit($src, $source, $o, 'tracking', 'číslo zásielky ' . ($number !== '' ? $number : '(zmazané)'));
            }
            $msg = 'Číslo zásielky uložené v e-shope.';
            if ($state) {
                $names = array_column($source->states(), 'name', 'id_order_state');
                if (!isset($names[$state])) {
                    throw new \RuntimeException('Neplatný stav.');
                }
                $email = (bool)$req->post('sendemail');
                $new = (new ShopOrderState($api))->change((int)$o['id_order'], (int)$o['id_shop'], (string)$o['reference'], $state, $email);
                $this->audit($src, $source, $o, 'state', $o['state'] . ' → ' . ($names[$new] ?? $new) . ($email ? ' (s e-mailom)' : ''));
                $msg .= ' Stav: ' . ($names[$new] ?? $new) . '.';
            }
            Session::flash('msg', $msg);
        } catch (\Throwable $e) {
            Session::flash('error', 'Nepodarilo sa: ' . $e->getMessage());
        }
        $this->forget($source);
        return $this->back($src, $o);
    }

    // ------------------------------------------------------------------ internal notes

    public function note(Request $req, array $params)
    {
        $l = $this->loadPost($req, $params);
        if ($l instanceof Response) {
            return $l;
        }
        list($src, , $o) = $l;
        $text = trim((string)$req->post('text', ''));
        if ($text !== '') {
            $this->log()->addNote($src, (int)$o['id_order'], mb_substr($text, 0, 5000, 'UTF-8'), $this->ctx->user());
            Session::flash('msg', 'Poznámka pridaná.');
        }
        return $this->back($src, $o, '#comments');
    }

    // ------------------------------------------------------------------ message to the customer

    /** Sender of e-mails about an order: the company that invoices the shop (its e-mail settings), shown with the shop name. */
    private function sender(int $src, array $o): array
    {
        $cid = $this->ctx->companies()->companyForShop($src, (int)$o['id_shop']);
        if (!$cid) {
            return ['error' => 'E-shop nie je priradený firme (Firmy → E-shopy) — panel nevie, z akej adresy písať.'];
        }
        $company = $this->ctx->companies()->find($cid);
        $ms = $this->ctx->companies()->mailSettings($cid);
        $from = $ms['from_email'] ?: (string)$company['email'];
        $brand = $src === 0 && $this->ctx->shopOrders()->available() ? $this->ctx->shopOrders()->brand((int)$o['id_shop']) : null;
        if ($brand !== null && $brand['name'] === '') {
            $brand = null;
        }
        return ['from' => $from, 'from_name' => $brand ? $brand['name'] : ($ms['from_name'] ?: (string)$company['name']),
            'reply_to' => $ms['reply_to'] ?: $from, 'brand' => $brand, 'company' => $company,
            'error' => filter_var($from, FILTER_VALIDATE_EMAIL) ? '' : 'Firma nemá platný e-mail odosielateľa (Firmy → E-mail s faktúrou).'];
    }

    public function messageForm(Request $req, array $params, array $errors = [], ?array $values = null)
    {
        $l = $this->load($params);
        if ($l instanceof Response) {
            return $l;
        }
        list($src, , $o) = $l;
        $s = $this->sender($src, $o);
        $shop = $s['brand']['name'] ?? (string)$o['shop'];
        $values = $values ?? ['to' => (string)$o['customer_email'], 'subject' => 'Vaša objednávka ' . $o['reference'] . ' — ' . $shop,
            'text' => "Dobrý deň,\n\n\n\nS pozdravom\n" . $shop . (!empty($s['brand']['web']) ? "\n" . $s['brand']['web'] : '')];
        return $this->ctx->page('orders/message', ['o' => $o, 'src' => $src, 'values' => $values, 'errors' => $errors, 'sender' => $s,
            'configured' => $this->ctx->mailer()->configured(), 'sent' => $this->log()->messages($src, (int)$o['id_order'])],
            'Správa zákazníkovi — ' . $o['reference'], 'orders');
    }

    public function message(Request $req, array $params)
    {
        $l = $this->loadPost($req, $params);
        if ($l instanceof Response) {
            return $l;
        }
        list($src, $source, $o) = $l;
        $v = ['to' => trim((string)$req->post('to', '')), 'subject' => trim((string)$req->post('subject', '')), 'text' => trim((string)$req->post('text', ''))];
        $to = \Panel\Mailer::parseList($v['to']);
        $s = $this->sender($src, $o);
        $errors = [];
        if (!$to) {
            $errors['to'] = 'Zadajte platnú e-mailovú adresu.';
        }
        if ($v['subject'] === '' || $v['text'] === '') {
            $errors['text'] = 'Vyplňte predmet aj text.';
        }
        if ($s['error'] !== '') {
            $errors['from'] = $s['error'];
        }
        if ($errors) {
            return Response::html($this->messageForm($req, $params, $errors, $v), 422);
        }
        $error = '';
        try {
            $this->ctx->mailer()->send(ShopMail::message(['from' => $s['from'], 'from_name' => $s['from_name'], 'reply_to' => $s['reply_to'],
                'to' => $to, 'subject' => $v['subject'], 'text' => $v['text']], $s['brand'],
                $s['brand'] ? 'E-shop ' . $s['brand']['name'] . ' prevádzkuje ' . $s['company']['name'] . '.' : ''));
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }
        $this->log()->logMessage($src, (int)$o['id_order'], $to, $v['subject'], $v['text'], $error, $this->ctx->user());
        if ($error !== '') {
            return Response::html($this->messageForm($req, $params, ['send' => 'E-mail sa nepodarilo odoslať: ' . $error], $v), 502);
        }
        $this->audit($src, $source, $o, 'message', 'správa zákazníkovi „' . $v['subject'] . '“');
        Session::flash('msg', 'Správa odoslaná na ' . implode(', ', $to) . '.');
        return $this->back($src, $o);
    }

    // ------------------------------------------------------------------ address

    public function addressForm(Request $req, array $params, array $errors = [], ?array $values = null)
    {
        $l = $this->load($params);
        if ($l instanceof Response) {
            return $l;
        }
        list($src, , $o) = $l;
        $type = ($params['type'] ?? '') === 'invoice' ? 'invoice' : 'delivery';
        $a = $o['address_' . $type] ?? [];
        if ($values === null) {
            $values = [];
            foreach (ShopOrderEdit::ADDRESS_FIELDS as $k => $label) {
                $values[$k] = (string)($a[$k] ?? '');
            }
        }
        return $this->ctx->page('orders/address', ['o' => $o, 'src' => $src, 'type' => $type, 'values' => $values, 'errors' => $errors,
            'country' => (string)($a['country'] ?? ''), 'invoice' => $this->ctx->invoices()->findByOrder($src, (int)$o['id_order'])],
            ($type === 'invoice' ? 'Fakturačná' : 'Dodacia') . ' adresa — ' . $o['reference'], 'orders');
    }

    public function address(Request $req, array $params)
    {
        $l = $this->loadPost($req, $params);
        if ($l instanceof Response) {
            return $l;
        }
        list($src, $source, $o) = $l;
        $type = ($params['type'] ?? '') === 'invoice' ? 'invoice' : 'delivery';
        $v = [];
        foreach (ShopOrderEdit::ADDRESS_FIELDS as $k => $label) {
            $v[$k] = mb_substr(trim((string)$req->post($k, '')), 0, $k === 'other' ? 300 : 128, 'UTF-8');
        }
        $errors = [];
        foreach (['lastname' => 'Zadajte priezvisko.', 'address1' => 'Zadajte ulicu.', 'city' => 'Zadajte mesto.'] as $k => $m) {
            if ($v[$k] === '') {
                $errors[$k] = $m;
            }
        }
        $api = $this->api($src);
        if ($api === null) {
            $errors['form'] = 'Nie je pripojený e-shop tejto databázy (Nastavenia → E-shopy).';
        }
        if ($errors) {
            return Response::html($this->addressForm($req, $params, $errors, $v), 422);
        }
        try {
            $newId = (new ShopOrderEdit($api))->changeAddress((int)$o['id_order'], (int)$o['id_shop'], (string)$o['reference'], $type, $v);
        } catch (\Throwable $e) {
            return Response::html($this->addressForm($req, $params, ['form' => 'E-shop adresu neuložil: ' . $e->getMessage()], $v), 502);
        }
        $this->forget($source);
        $this->audit($src, $source, $o, 'address', ($type === 'invoice' ? 'fakturačná' : 'dodacia') . ' adresa → nová #' . $newId
            . ' (' . trim($v['firstname'] . ' ' . $v['lastname']) . ', ' . $v['address1'] . ', ' . $v['postcode'] . ' ' . $v['city'] . ')');
        $inv = $this->ctx->invoices()->findByOrder($src, (int)$o['id_order']);
        Session::flash('msg', 'Adresa zmenená v e-shope.' . ($inv && $type === 'invoice' ? ($inv['status'] === 'issued'
            ? ' Vystavená faktúra má pôvodnú adresu — ak treba, opravte ju dobropisom a novou faktúrou.'
            : ' Koncept faktúry má pôvodnú adresu — upravte ho.') : ''));
        return $this->back($src, $o);
    }

    // ------------------------------------------------------------------ returns

    public function returnForm(Request $req, array $params, array $errors = [], array $posted = [])
    {
        $l = $this->load($params);
        if ($l instanceof Response) {
            return $l;
        }
        list($src, $source, $o) = $l;
        $invoice = $this->ctx->invoices()->findByOrder($src, (int)$o['id_order']);
        $states = [];
        try {
            $states = $source->states();
        } catch (\Throwable $e) {
        }
        return $this->ctx->page('orders/return', ['o' => $o, 'src' => $src, 'returned' => $this->log()->returned($src, (int)$o['id_order']),
            'invoice' => $invoice, 'states' => $states, 'errors' => $errors, 'posted' => $posted,
            'canCredit' => $invoice && $invoice['status'] === 'issued' && $this->ctx->can(Rights::INVOICES_EDIT)
                && $this->ctx->canCompany((int)$invoice['company_id']),
            'canRestock' => $this->ctx->can(Rights::STOCK_EDIT), 'hasApi' => $this->hasApi($src)],
            'Vrátenie tovaru — ' . $o['reference'], 'orders');
    }

    public function returnSave(Request $req, array $params)
    {
        $l = $this->loadPost($req, $params);
        if ($l instanceof Response) {
            return $l;
        }
        list($src, $source, $o) = $l;
        $returned = $this->log()->returned($src, (int)$o['id_order']);
        $qty = (array)$req->post('qty', []);
        $lines = [];
        $errors = [];
        foreach ($o['lines'] as $line) {
            $id = (int)($line['id_order_detail'] ?? 0);
            $q = (int)($qty[$id] ?? 0);
            if ($q <= 0) {
                continue;
            }
            $max = (int)$line['product_quantity'] - ($returned[$id] ?? 0);
            if ($q > $max) {
                $errors[] = $line['product_name'] . ': najviac ' . $max . ' ks.';
                continue;
            }
            $lines[] = ['id_order_detail' => $id, 'product_id' => (int)$line['product_id'], 'product_attribute_id' => (int)$line['product_attribute_id'],
                'name' => (string)$line['product_name'], 'reference' => (string)$line['product_reference'], 'quantity' => $q];
        }
        $shipping = (bool)$req->post('shipping');
        if (!$lines && !$shipping) {
            $errors[] = 'Zadajte, koľko kusov sa vracia (alebo len dopravu).';
        }
        $invoice = $this->ctx->invoices()->findByOrder($src, (int)$o['id_order']);
        $wantCredit = (bool)$req->post('credit_note') && $invoice && $invoice['status'] === 'issued' && $this->ctx->can(Rights::INVOICES_EDIT)
            && $this->ctx->canCompany((int)$invoice['company_id']);
        $wantRestock = (bool)$req->post('restock') && $lines && $this->ctx->can(Rights::STOCK_EDIT);
        $state = (int)$req->post('state', 0);
        $api = ($wantRestock || $state) && $this->hasApi($src) ? $this->ctx->orderWriteApi($src) : null;
        if (($wantRestock || $state) && $api === null) {
            $errors[] = 'Na naskladnenie a zmenu stavu treba pripojený e-shop (Nastavenia → E-shopy).';
        }
        if ($errors) {
            return Response::html($this->returnForm($req, $params, $errors, $req->post()), 422);
        }
        $user = $this->ctx->user();
        $problems = [];
        $restocked = false;
        if ($wantRestock) {
            $stock = new ShopStock($api);
            $done = 0;
            foreach ($lines as $line) {
                try {
                    $stock->addQuantity((int)$o['id_shop'], $line['product_id'], $line['product_attribute_id'], $line['quantity']);
                    $done++;
                } catch (\Throwable $e) {
                    $problems[] = 'Naskladnenie ' . $line['name'] . ': ' . $e->getMessage();
                }
            }
            $restocked = $done > 0;
        }
        $creditId = null;
        if ($wantCredit) {
            $pick = array_map(function ($line) { return ['sku' => $line['reference'], 'name' => $line['name'], 'quantity' => $line['quantity']]; }, $lines);
            if ($shipping) {
                $pick[] = ['shipping' => true];
            }
            try {
                $creditId = $this->ctx->invoices()->createCreditNote((int)$invoice['id'], $user, $pick,
                    trim((string)$req->post('reason', '')) ?: 'Vrátenie tovaru');
            } catch (\Throwable $e) {
                $problems[] = 'Dobropis: ' . $e->getMessage();
            }
        }
        $stateName = '';
        if ($state && $api !== null) {
            try {
                $names = array_column($source->states(), 'name', 'id_order_state');
                if (!isset($names[$state])) {
                    throw new \RuntimeException('neplatný stav');
                }
                $new = (new ShopOrderState($api))->change((int)$o['id_order'], (int)$o['id_shop'], (string)$o['reference'], $state, (bool)$req->post('sendemail'));
                $stateName = (string)($names[$new] ?? $new);
            } catch (\Throwable $e) {
                $problems[] = 'Zmena stavu: ' . $e->getMessage();
            }
        }
        $note = trim((string)$req->post('note', ''));
        $this->log()->addReturn($src, (int)$o['id_order'], $lines, $shipping, $restocked, $creditId, $stateName, $note, $problems, $user);
        $this->forget($source);
        $pieces = array_sum(array_column($lines, 'quantity'));
        $this->audit($src, $source, $o, 'return', 'vrátené ' . $pieces . ' ks' . ($shipping ? ' + doprava' : '') . ($restocked ? ', naskladnené' : '')
            . ($creditId ? ', koncept dobropisu #' . $creditId : '') . ($stateName !== '' ? ', stav ' . $stateName : ''));
        if ($problems) {
            Session::flash('error', 'Vrátenie zapísané, ale: ' . implode(' ', $problems));
        } else {
            Session::flash('msg', 'Vrátenie zapísané' . ($restocked ? ', tovar naskladnený v e-shope' : '')
                . ($creditId ? ', pripravený koncept dobropisu — skontrolujte ho a vystavte' : '') . ($stateName !== '' ? ', stav ' . $stateName : '') . '.');
        }
        if ($creditId && $this->ctx->can(Rights::INVOICES_VIEW)) {
            return Response::redirect($this->ctx->url('/invoices/' . $creditId));
        }
        return $this->back($src, $o);
    }

    // ------------------------------------------------------------------ order lines (products, quantities, prices, carrier)

    /** GET /orders/[{src}/]{id}/lines — edit the products / quantities / prices / carrier of the order IN THE SHOP. */
    public function linesForm(Request $req, array $params, array $errors = [], array $posted = [])
    {
        $l = $this->load($params);
        if ($l instanceof Response) {
            return $l;
        }
        list($src, , $o) = $l;
        $api = $this->hasApi($src) ? $this->ctx->orderWriteApi($src) : null;
        $carriers = $products = [];
        $loadError = null;
        if ($api === null) {
            $loadError = 'Nie je pripojený e-shop tejto databázy (Nastavenia → E-shopy) — položky sa nedajú meniť.';
        } else {
            try {
                $carriers = (new ShopOrderLines($api))->carriers((int)$o['id_shop']);
            } catch (\Throwable $e) {
                $loadError = 'Dopravcov sa nepodarilo načítať: ' . $e->getMessage();
            }
            try {   // product picker: products and combinations exactly as the shop has them
                $stock = new ShopStock($api);
                $shop = $stock->shop((int)$o['id_shop']);
                $products = $shop ? $stock->rows($shop) : [];
            } catch (\Throwable $e) {
                $errors[] = 'Zoznam produktov sa nenačítal (' . $e->getMessage() . ') — produkt pridáte zadaním „[ID produktu:ID kombinácie]“.';
            }
        }
        return $this->ctx->page('orders/lines', ['o' => $o, 'src' => $src, 'carriers' => $carriers, 'products' => $products,
            'errors' => $errors, 'posted' => $posted, 'loadError' => $loadError,
            'invoice' => $this->ctx->invoices()->findByOrder($src, (int)$o['id_order']),
            'canStock' => $this->ctx->can(Rights::STOCK_EDIT)],
            'Upraviť položky — ' . $o['reference'], 'orders');
    }

    /** POST /orders/[{src}/]{id}/lines */
    public function linesSave(Request $req, array $params)
    {
        $l = $this->loadPost($req, $params);
        if ($l instanceof Response) {
            return $l;
        }
        list($src, $source, $o) = $l;
        $num = function ($v) {
            $v = str_replace([' ', ','], ['', '.'], trim((string)$v));
            return $v === '' ? null : (is_numeric($v) ? (float)$v : false);
        };
        $errors = [];
        $qty = (array)$req->post('qty', []);
        $price = (array)$req->post('price', []);
        $lines = $expect = $keep = [];
        foreach ($o['lines'] as $line) {
            $id = (int)$line['id_order_detail'];
            $q = trim((string)($qty[$id] ?? ''));
            $p = $num($price[$id] ?? '');
            if (!preg_match('/^\d{1,5}$/', $q) || $p === false || ($p !== null && $p < 0)) {
                $errors[] = $line['product_name'] . ': zadajte počet kusov (0 = odstrániť) a cenu za kus.';
                continue;
            }
            $lines[$id] = ['qty' => (int)$q, 'price' => $p];
            $keep[$id] = isset($line['tax_rate_db']) ? ['tax_rate' => $line['tax_rate_db'], 'tax_name' => (string)($line['tax_name'] ?? '')] : [];
        }
        foreach ((array)$req->post('orig_qty', []) as $id => $q) {
            $expect['lines'][(int)$id] = ['qty' => (int)$q, 'price' => (float)(((array)$req->post('orig_price', []))[$id] ?? -1)];
        }
        $expect += ['lines' => [], 'total' => (float)$req->post('orig_total', -1), 'carrier' => (int)$req->post('orig_carrier', -1),
            'shipping' => (float)$req->post('orig_shipping', -1), 'discount' => (float)$req->post('orig_discount', -1)];
        $add = [];
        $addQty = (array)$req->post('add_qty', []);
        $addPrice = (array)$req->post('add_price', []);
        foreach ((array)$req->post('add_product', []) as $i => $text) {
            $text = trim((string)$text);
            if ($text === '') {
                continue;
            }
            $q = trim((string)($addQty[$i] ?? ''));
            $p = $num($addPrice[$i] ?? '');
            if (!preg_match('/\[(\d+):(\d+)\]\s*$/', $text, $m)) {
                $errors[] = 'Produkt „' . $text . '“: vyberte ho zo zoznamu (končí sa [ID produktu:ID kombinácie]).';
            } elseif (!preg_match('/^[1-9]\d{0,4}$/', $q) || $p === false || ($p !== null && $p < 0)) {
                $errors[] = 'Produkt „' . $text . '“: zadajte počet kusov (aspoň 1) a cenu za kus alebo ju nechajte prázdnu (= cena v e-shope).';
            } else {
                $add[] = ['product_id' => (int)$m[1], 'attribute_id' => (int)$m[2], 'qty' => (int)$q, 'price' => $p];
            }
        }
        $shipPrice = $num($req->post('shipping_price', ''));
        if ($shipPrice === false || $shipPrice === null || $shipPrice < 0) {
            $errors[] = 'Zadajte cenu dopravy (0 = zadarmo).';
        }
        $discMode = $req->post('discount_mode') === 'percent' ? 'percent' : 'amount';
        $discValue = $num($req->post('discount_value', ''));
        if ($discValue === false || $discValue === null || $discValue < 0 || ($discMode === 'percent' && $discValue > 100)) {
            $errors[] = 'Zľava: zadajte sumu s DPH alebo percento 0–100 (0 = bez zľavy).';
        }
        $api = $this->hasApi($src) ? $this->ctx->orderWriteApi($src) : null;
        if ($api === null) {
            $errors[] = 'Nie je pripojený e-shop tejto databázy (Nastavenia → E-shopy).';
        }
        if ($errors) {
            return Response::html($this->linesForm($req, $params, $errors, $req->post()), 422);
        }
        $withStock = (bool)$req->post('stock') && $this->ctx->can(Rights::STOCK_EDIT);
        try {
            $r = (new ShopOrderLines($api))->apply((int)$o['id_order'], (int)$o['id_shop'], (string)$o['reference'],
                ['lines' => $lines, 'add' => $add, 'shipping' => ['carrier_id' => (int)$req->post('carrier', 0), 'price' => $shipPrice],
                 'discount' => ['mode' => $discMode, 'value' => $discValue]],
                $expect, $keep, $withStock ? new ShopStock($api) : null);
        } catch (\InvalidArgumentException $e) {
            return Response::html($this->linesForm($req, $params, [$e->getMessage()], $req->post()), 422);
        } catch (\Throwable $e) {
            return Response::html($this->linesForm($req, $params, ['Nič sa nezmenilo: ' . $e->getMessage()], $req->post()), 502);
        }
        if (!$r['done'] && !$r['problems']) {
            Session::flash('msg', 'Bez zmeny — položky, doprava aj zľava sú rovnaké.');
            return $this->back($src, $o);
        }
        $this->forget($source);
        $summary = implode('; ', $r['done']);
        $this->audit($src, $source, $o, 'lines', $summary . ($r['problems'] ? ' | PROBLÉMY: ' . implode(' ', $r['problems']) : ''));
        $this->log()->addNote($src, (int)$o['id_order'], 'Úprava objednávky v e-shope: ' . ($summary !== '' ? $summary : '—')
            . ($r['total'] !== null ? '. Nový súčet ' . number_format($r['total'], 2, ',', ' ') . ' ' . ($o['currency'] ?: '€') : '')
            . ($r['problems'] ? '. Problémy: ' . implode(' ', $r['problems']) : ''), $this->ctx->user());
        $notes = [];
        $paid = array_sum(array_map(function ($p) { return (float)$p['amount']; }, $o['payments']));
        if ($r['total'] !== null && $o['payments'] && abs($paid - $r['total']) >= 0.005) {
            $notes[] = 'Zaplatené ' . number_format($paid, 2, ',', ' ') . ' — nový súčet ' . number_format($r['total'], 2, ',', ' ')
                . ($paid > $r['total'] ? ': zákazníkovi treba vrátiť rozdiel.' : ': zákazník má doplatiť rozdiel.');
        }
        $inv = $this->ctx->invoices()->findByOrder($src, (int)$o['id_order']);
        if ($inv) {
            $notes[] = $inv['status'] === 'issued' ? 'Vystavená faktúra má pôvodné položky — opravte ju dobropisom a novou faktúrou.'
                : 'Koncept faktúry má pôvodné položky — upravte ho.';
        }
        if ($r['problems']) {
            Session::flash('error', 'Niečo sa neuložilo: ' . implode(' ', $r['problems']) . ($r['done'] ? ' Uložené: ' . $summary . '.' : ''));
        }
        if ($r['done']) {
            Session::flash('msg', 'Zmenené v e-shope: ' . $summary . '.' . ($notes ? ' ' . implode(' ', $notes) : ''));
        }
        return $this->back($src, $o);
    }

    // ------------------------------------------------------------------ delivery notes / picking list

    /** GET /orders/[{src}/]{id}/delivery-note — dodací list (PDF). */
    public function deliveryNote(Request $req, array $params)
    {
        $l = $this->load($params);
        if ($l instanceof Response) {
            return $l;
        }
        return $this->pdf('delivery-note', [$l[2]], 'dodaci-list-' . preg_replace('/[^A-Za-z0-9_-]+/', '-', (string)$l[2]['reference']));
    }

    private function pdf(string $template, array $orders, string $name): Response
    {
        try {
            $html = $this->ctx->view->render('orders/' . $template, ['orders' => $orders, 'fontBase' => Pdf::fontDir(), 'f' => new \Panel\Format()]);
            $pdf = Pdf::fromHtml($html);
        } catch (\Throwable $e) {
            return Response::text('PDF sa nepodarilo vytvoriť: ' . $e->getMessage(), 500);
        }
        return new Response($pdf, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="' . $name . '.pdf"',
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    // ------------------------------------------------------------------ Expedícia (shipping)

    /** States shown in Expedícia (saved per source; default: paid and not shipped + cash on delivery waiting). */
    private function shippingStates(int $src, array $states): array
    {
        $saved = $this->log()->setting('shipping_states.' . $src);
        if (is_array($saved) && $saved) {
            return array_map('intval', $saved);
        }
        $out = [];
        foreach ($states as $s) {
            $name = mb_strtolower((string)$s['name'], 'UTF-8');
            if (empty($s['shipped']) && empty($s['delivery']) && (!empty($s['paid']) || strpos($name, 'dobierk') !== false || strpos($name, 'príprav') !== false)
                && strpos($name, 'vráten') === false && strpos($name, 'zrušen') === false && strpos($name, 'odoslan') === false) {
                $out[] = (int)$s['id_order_state'];
            }
        }
        return $out;
    }

    public function shipping(Request $req, array $params)
    {
        $sources = $this->ctx->orderSources();
        if (!$sources) {
            return $this->ctx->page('orders/unavailable', [], 'Expedícia', 'orders');
        }
        $src = (int)$req->query('src', (string)array_keys($sources)[0]);
        $source = $sources[$src] ?? reset($sources);
        $src = $source->id();
        $error = null;
        $states = $rows = $shops = [];
        $picked = [];
        try {
            $states = $source->states();
            $shops = $source->shops();
            $picked = $req->query('states') ? array_map('intval', (array)$req->query('states')) : $this->shippingStates($src, $states);
            $rows = $picked ? $source->search(['states' => $picked, 'shop' => (int)$req->query('shop', 0)], 1, 200)['rows'] : [];
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }
        $details = [];
        foreach ($rows as $r) {   // carrier + tracking: one detail per order (SQL source: cheap; Webservice: the list is short)
            try {
                $d = $source->find((int)$r['id_order']);
                $details[(int)$r['id_order']] = ['carrier' => (string)($d['carrier'] ?? ''), 'tracking' => (string)($d['tracking'] ?? ''),
                    'items' => array_sum(array_map('intval', array_column($d['lines'] ?? [], 'product_quantity')))];
            } catch (\Throwable $e) {
                $details[(int)$r['id_order']] = ['carrier' => '?', 'tracking' => '', 'items' => 0];
            }
        }
        return $this->ctx->page('orders/shipping', ['sources' => $sources, 'src' => $src, 'source' => $source, 'states' => $states,
            'picked' => $picked, 'shops' => $shops, 'shop' => (int)$req->query('shop', 0), 'rows' => $rows, 'details' => $details,
            'notes' => $this->log()->noteCounts($src, array_column($rows, 'id_order')), 'error' => $error,
            'canEdit' => $this->ctx->can(Rights::ORDERS_EDIT) && $this->hasApi($src)], 'Expedícia', 'orders');
    }

    /** POST /shipping — bulk: save states filter, tracking numbers, state change, delivery notes, picking list. */
    public function shippingAction(Request $req, array $params)
    {
        if (!Csrf::check($req)) {
            return Response::text('Formulár vypršal, načítajte stránku znova.', 400);
        }
        $src = (int)$req->post('src', 0);
        $source = $this->ctx->orderSource($src);
        if ($source === null) {
            return Response::redirect($this->ctx->url('/shipping'));
        }
        $back = Response::redirect($this->ctx->url('/shipping?src=' . $src));
        $action = (string)$req->post('action', '');
        if ($action === 'save_states') {
            if ($this->ctx->can(Rights::ORDERS_EDIT)) {
                $this->log()->setSetting('shipping_states.' . $src, array_values(array_map('intval', (array)$req->post('states', []))));
                Session::flash('msg', 'Stavy pre expedíciu uložené.');
            }
            return $back;
        }
        $ids = array_slice(array_values(array_unique(array_map('intval', (array)$req->post('ids', [])))), 0, self::BULK_MAX);
        if ($action === 'tracking') {
            // every row with a changed number (selection not needed)
            $ids = [];
            foreach ((array)$req->post('tracking', []) as $id => $number) {
                if (trim((string)$number) !== trim((string)($req->post('tracking_old', [])[$id] ?? ''))) {
                    $ids[] = (int)$id;
                }
            }
        }
        if (!$ids) {
            Session::flash('error', $action === 'tracking' ? 'Žiadne zmenené číslo zásielky.' : 'Označte objednávky.');
            return $back;
        }
        $orders = [];
        foreach ($ids as $id) {
            try {
                $o = $source->find($id);
                if ($o !== null) {
                    $orders[] = $o;
                }
            } catch (\Throwable $e) {
            }
        }
        if ($action === 'delivery' || $action === 'picking') {
            return $this->pdf($action === 'delivery' ? 'delivery-note' : 'picking-list', $orders, ($action === 'delivery' ? 'dodacie-listy-' : 'vychystanie-') . date('Y-m-d'));
        }
        if (!$this->ctx->can(Rights::ORDERS_EDIT)) {
            return Response::html($this->ctx->page('forbidden', [], 'Prístup zamietnutý'), 403);
        }
        $api = $this->api($src);
        if ($api === null) {
            Session::flash('error', 'Nie je pripojený e-shop tejto databázy (Nastavenia → E-shopy).');
            return $back;
        }
        $ok = 0;
        $fail = [];
        $names = array_column($source->states(), 'name', 'id_order_state');
        $state = (int)$req->post('state', 0);
        if ($action === 'state' && !isset($names[$state])) {
            Session::flash('error', 'Zvoľte stav.');
            return $back;
        }
        foreach ($orders as $o) {
            try {
                if ($action === 'tracking') {
                    $number = mb_substr(trim((string)($req->post('tracking', [])[$o['id_order']] ?? '')), 0, 64, 'UTF-8');
                    (new ShopOrderEdit($api))->setTracking((int)$o['id_order'], (int)$o['id_shop'], (string)$o['reference'], $number);
                    $this->audit($src, $source, $o, 'tracking', 'číslo zásielky ' . ($number !== '' ? $number : '(zmazané)') . ' (expedícia)');
                } elseif ($action === 'state') {
                    $email = (bool)$req->post('sendemail');
                    $new = (new ShopOrderState($api))->change((int)$o['id_order'], (int)$o['id_shop'], (string)$o['reference'], $state, $email);
                    $this->audit($src, $source, $o, 'state', $o['state'] . ' → ' . ($names[$new] ?? $new) . ($email ? ' (s e-mailom)' : '') . ' (expedícia)');
                }
                $ok++;
            } catch (\Throwable $e) {
                $fail[] = $o['reference'] . ' (' . $e->getMessage() . ')';
            }
        }
        $this->forget($source);
        Session::flash($fail ? 'error' : 'msg', ($action === 'tracking' ? 'Čísla zásielok uložené: ' : 'Stav zmenený: ') . $ok . '.'
            . ($fail ? ' Nepodarilo sa: ' . implode('; ', $fail) : ''));
        return $back;
    }
}
