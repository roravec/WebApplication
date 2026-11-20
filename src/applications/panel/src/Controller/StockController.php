<?php
namespace Panel\Controller;

use Panel\Audit;
use Panel\Invoicing\Calculator;
use Panel\Invoicing\Numbering;
use Panel\PanelContext;
use Panel\Rights;
use Panel\Stock\Stock;
use WebApplication\Csrf;
use WebApplication\Http\Request;
use WebApplication\Http\Response;
use WebApplication\Session;

/** Sklad: stock overview, warehouses, stock items (cards), stock documents (docs/sklad-specifikacia.md). */
class StockController
{
    /** @var PanelContext */
    private $ctx;

    public function __construct(PanelContext $ctx)
    {
        $this->ctx = $ctx;
    }

    private function stock(): Stock
    {
        return new Stock($this->ctx->db());
    }

    private function notFound(string $what): Response
    {
        return Response::html($this->ctx->page('notfound', [], $what . ' neexistuje', 'stock'), 404);
    }

    private function csrfFail(): Response
    {
        return Response::text('Formulár vypršal, načítajte stránku znova.', 400);
    }

    // ------------------------------------------------------------------ overview

    public function index(Request $req, array $params): string
    {
        $ids = $this->ctx->companyIds();
        $f = ['q' => trim((string)$req->query('q', '')), 'warehouse' => (int)$req->query('warehouse', 0), 'all' => (bool)$req->query('all')];
        $warehouses = $this->stock()->warehouses($ids);
        $whIds = array_map('intval', array_column($warehouses, 'id'));
        $wh = in_array($f['warehouse'], $whIds, true) ? $f['warehouse'] : null;
        $items = $this->stock()->items($ids, $f['q'], $wh, !$f['all']);
        return $this->ctx->page('stock/index', ['filters' => $f, 'warehouses' => $warehouses, 'items' => $items,
            'canEdit' => $this->ctx->can(Rights::STOCK_EDIT)], 'Sklad', 'stock');
    }

    // ------------------------------------------------------------------ warehouses

    public function warehouses(Request $req, array $params): string
    {
        return $this->ctx->page('stock/warehouses', ['warehouses' => $this->stock()->warehouses($this->ctx->companyIds()),
            'canEdit' => $this->ctx->can(Rights::STOCK_EDIT)], 'Sklady', 'stock');
    }

    public function warehouseForm(Request $req, array $params, array $errors = [], ?array $values = null)
    {
        $w = isset($params['id']) ? $this->stock()->warehouse((int)$params['id']) : null;
        if (isset($params['id']) && (!$w || !$this->ctx->canCompany((int)$w['company_id']))) {
            return $this->notFound('Sklad');
        }
        $companies = $this->ctx->companies()->all($this->ctx->companyIds(), true);
        if (!$w && !$companies) {
            return $this->ctx->page('invoices/no-company', [], 'Nový sklad', 'stock');
        }
        $values = $values ?? ($w ?: ['company_id' => (int)$companies[0]['id'], 'code' => '', 'name' => '', 'location' => '', 'kind' => 'other', 'active' => 1]);
        return $this->ctx->page('stock/warehouse-form', ['w' => $w, 'values' => $values, 'errors' => $errors, 'companies' => $companies],
            $w ? 'Sklad ' . $w['name'] : 'Nový sklad', 'stock');
    }

    public function warehouseSave(Request $req, array $params)
    {
        if (!Csrf::check($req)) {
            return $this->csrfFail();
        }
        $st = $this->stock();
        $w = isset($params['id']) ? $st->warehouse((int)$params['id']) : null;
        if (isset($params['id']) && (!$w || !$this->ctx->canCompany((int)$w['company_id']))) {
            return $this->notFound('Sklad');
        }
        $v = ['company_id' => $w ? (int)$w['company_id'] : (int)$req->post('company_id', 0),
              'code' => strtoupper(trim((string)$req->post('code', ''))), 'name' => trim((string)$req->post('name', '')),
              'location' => trim((string)$req->post('location', '')), 'kind' => $req->post('kind') === 'shop' ? 'shop' : 'other',
              'active' => $req->post('active') ? 1 : 0];
        $errors = [];
        if (!$this->ctx->canCompany($v['company_id'])) {
            $errors['company_id'] = 'Zvoľte firmu.';
        }
        if (!preg_match('/^[A-Z0-9_-]{1,20}$/', $v['code'])) {
            $errors['code'] = 'Kód: 1–20 znakov (písmená, čísla, - _).';
        }
        if ($v['name'] === '') {
            $errors['name'] = 'Zadajte názov.';
        }
        foreach ($st->warehouses([$v['company_id']]) as $o) {
            if ($o['code'] === $v['code'] && (!$w || (int)$o['id'] !== (int)$w['id'])) {
                $errors['code'] = 'Sklad s týmto kódom už existuje.';
            }
        }
        if ($errors) {
            return Response::html($this->warehouseForm($req, $params, $errors, $v), 422);
        }
        $id = $st->saveWarehouse($w ? (int)$w['id'] : null, $v);
        Audit::log($this->ctx->db(), $this->ctx->user(), 'warehouse', $id, $w ? 'update' : 'create', $v['code']);
        Session::flash('msg', 'Sklad uložený.');
        return Response::redirect($this->ctx->url('/stock/warehouses'));
    }

    // ------------------------------------------------------------------ items

    public function item(Request $req, array $params)
    {
        $st = $this->stock();
        $item = $st->item((int)$params['id']);
        if (!$item || !$this->ctx->canCompany((int)$item['company_id'])) {
            return $this->notFound('Skladová karta');
        }
        $warehouses = [];
        foreach ($st->warehouses([(int)$item['company_id']]) as $w) {
            $warehouses[(int)$w['id']] = $w;
        }
        return $this->ctx->page('stock/item', ['item' => $item, 'levels' => $st->levels((int)$item['id']), 'warehouses' => $warehouses,
            'lots' => $st->lots((int)$item['id']), 'moves' => $st->moves((int)$item['id']), 'canEdit' => $this->ctx->can(Rights::STOCK_EDIT)],
            $item['name'], 'stock');
    }

    public function itemForm(Request $req, array $params, array $errors = [], ?array $values = null)
    {
        $item = isset($params['id']) ? $this->stock()->item((int)$params['id']) : null;
        if (isset($params['id']) && (!$item || !$this->ctx->canCompany((int)$item['company_id']))) {
            return $this->notFound('Skladová karta');
        }
        $companies = $this->ctx->companies()->all($this->ctx->companyIds(), true);
        if (!$item && !$companies) {
            return $this->ctx->page('invoices/no-company', [], 'Nová karta', 'stock');
        }
        $values = $values ?? ($item ?: ['company_id' => (int)$companies[0]['id'], 'sku' => '', 'name' => '', 'unit' => 'ks', 'ean' => '',
            'min_qty' => '', 'note' => '', 'active' => 1]);
        return $this->ctx->page('stock/item-form', ['item' => $item, 'values' => $values, 'errors' => $errors, 'companies' => $companies],
            $item ? 'Upraviť kartu' : 'Nová skladová karta', 'stock');
    }

    public function itemSave(Request $req, array $params)
    {
        if (!Csrf::check($req)) {
            return $this->csrfFail();
        }
        $st = $this->stock();
        $item = isset($params['id']) ? $st->item((int)$params['id']) : null;
        if (isset($params['id']) && (!$item || !$this->ctx->canCompany((int)$item['company_id']))) {
            return $this->notFound('Skladová karta');
        }
        $v = ['company_id' => $item ? (int)$item['company_id'] : (int)$req->post('company_id', 0)];
        foreach (Stock::ITEM_FIELDS as $k) {
            $v[$k] = trim((string)$req->post($k, ''));
        }
        $v['active'] = $req->post('active') ? 1 : 0;
        $errors = [];
        if (!$this->ctx->canCompany($v['company_id'])) {
            $errors['company_id'] = 'Zvoľte firmu.';
        }
        if ($v['sku'] === '' || mb_strlen($v['sku'], 'UTF-8') > 64) {
            $errors['sku'] = 'Zadajte kód (najviac 64 znakov).';
        } else {
            $other = $st->itemBySku($v['company_id'], $v['sku']);
            if ($other && (!$item || (int)$other['id'] !== (int)$item['id'])) {
                $errors['sku'] = 'Karta s týmto kódom už existuje.';
            }
        }
        if ($v['name'] === '') {
            $errors['name'] = 'Zadajte názov.';
        }
        if ($v['min_qty'] !== '') {
            $mq = Calculator::parse($v['min_qty']);
            if ($mq === null || $mq < 0) {
                $errors['min_qty'] = 'Minimálne množstvo: číslo alebo prázdne.';
            } else {
                $v['min_qty'] = Calculator::fmt($mq, 3);
            }
        }
        if ($errors) {
            return Response::html($this->itemForm($req, $params, $errors, $v), 422);
        }
        $id = $st->saveItem($item ? (int)$item['id'] : null, $v['company_id'], $v);
        Audit::log($this->ctx->db(), $this->ctx->user(), 'stock_item', $id, $item ? 'update' : 'create', $v['sku']);
        Session::flash('msg', 'Karta uložená.');
        return Response::redirect($this->ctx->url('/stock/items/' . $id));
    }

    // ------------------------------------------------------------------ documents

    public function docs(Request $req, array $params): string
    {
        $f = ['type' => (string)$req->query('type', ''), 'warehouse' => (int)$req->query('warehouse', 0)];
        $ids = $this->ctx->companyIds();
        return $this->ctx->page('stock/docs', ['filters' => $f, 'docs' => $this->stock()->docs($f, $ids),
            'warehouses' => $this->stock()->warehouses($ids), 'canEdit' => $this->ctx->can(Rights::STOCK_EDIT)], 'Skladové doklady', 'stock');
    }

    public function doc(Request $req, array $params)
    {
        $d = $this->stock()->doc((int)$params['id']);
        if (!$d || !$this->ctx->canCompany((int)$d['company_id'])) {
            return $this->notFound('Doklad');
        }
        return $this->ctx->page('stock/doc', ['d' => $d], Numbering::STOCK_TYPES[$d['doc_type']] . ' ' . $d['number'], 'stock');
    }

    public function docForm(Request $req, array $params, array $errors = [], ?array $values = null, ?array $lines = null)
    {
        $type = (string)($values['doc_type'] ?? $req->query('type', 'receipt'));
        if (!isset(Numbering::STOCK_TYPES[$type])) {
            $type = 'receipt';
        }
        $companies = $this->ctx->companies()->all($this->ctx->companyIds(), true);
        if (!$companies) {
            return $this->ctx->page('invoices/no-company', [], Numbering::STOCK_TYPES[$type], 'stock');
        }
        $companyId = (int)($values['company_id'] ?? $req->query('company', 0));
        if (!$this->ctx->canCompany($companyId)) {
            $companyId = (int)$companies[0]['id'];
        }
        $st = $this->stock();
        $warehouses = $st->warehouses([$companyId], true);
        $values = $values ?? ['doc_type' => $type, 'company_id' => $companyId, 'doc_date' => date('Y-m-d'),
            'warehouse_id' => (int)$req->query('warehouse', $warehouses ? (int)$warehouses[0]['id'] : 0), 'target_warehouse_id' => 0,
            'partner' => '', 'reference' => '', 'note' => ''];
        return $this->ctx->page('stock/doc-form', ['type' => $type, 'values' => $values, 'errors' => $errors, 'companies' => $companies,
            'warehouses' => $warehouses, 'items' => array_values(array_filter($st->items([$companyId]), function ($i) { return (int)$i['active'] === 1; })),
            'lines' => $lines ?? [['item_id' => (int)$req->query('item', 0), 'qty' => '', 'cost' => '', 'lot' => '', 'exp' => '']]],
            ['receipt' => 'Nová príjemka', 'issue' => 'Nová výdajka', 'transfer' => 'Nová prevodka', 'adjust' => 'Inventúra'][$type], 'stock');
    }

    public function docSave(Request $req, array $params)
    {
        if (!Csrf::check($req)) {
            return $this->csrfFail();
        }
        $type = (string)$req->post('doc_type', '');
        $v = ['doc_type' => isset(Numbering::STOCK_TYPES[$type]) ? $type : 'receipt', 'company_id' => (int)$req->post('company_id', 0),
              'doc_date' => (string)$req->post('doc_date', ''), 'warehouse_id' => (int)$req->post('warehouse_id', 0),
              'target_warehouse_id' => (int)$req->post('target_warehouse_id', 0), 'partner' => trim((string)$req->post('partner', '')),
              'reference' => trim((string)$req->post('reference', '')), 'note' => trim((string)$req->post('note', ''))];
        $errors = [];
        if (!$this->ctx->canCompany($v['company_id'])) {
            $errors['doc'] = 'Nemáte prístup k tejto firme.';
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v['doc_date']) || !strtotime($v['doc_date'])) {
            $errors['doc'] = 'Zadajte dátum.';
        }
        $lines = [];
        $posted = [];
        $items = (array)$req->post('line_item', []);
        foreach ($items as $i => $itemId) {
            $row = ['item_id' => (int)$itemId, 'qty' => trim((string)($req->post('line_qty')[$i] ?? '')),
                    'cost' => trim((string)($req->post('line_cost')[$i] ?? '')), 'lot' => trim((string)($req->post('line_lot')[$i] ?? '')),
                    'exp' => trim((string)($req->post('line_exp')[$i] ?? ''))];
            if (!$row['item_id'] && $row['qty'] === '') {
                continue;
            }
            $lines[] = $row;
            $qty = Calculator::parse($row['qty']);
            $cost = $row['cost'] === '' ? null : Calculator::parse($row['cost']);
            $okQty = $qty !== null && ($v['doc_type'] === 'adjust' ? $qty >= 0 : $qty > 0);
            if (!$row['item_id'] || !$okQty || ($v['doc_type'] === 'receipt' && ($cost === null || $cost < 0))
                || ($row['cost'] !== '' && $cost === null) || ($row['exp'] !== '' && !strtotime($row['exp']))) {
                $errors['lines'] = $v['doc_type'] === 'receipt' ? 'Každý riadok: karta, množstvo (> 0) a nákupná cena za MJ bez DPH.'
                    : ($v['doc_type'] === 'adjust' ? 'Každý riadok: karta a zistené množstvo (≥ 0).' : 'Každý riadok: karta a množstvo (> 0).');
                continue;
            }
            $posted[] = ['item_id' => $row['item_id'], 'qty' => $qty, 'counted' => $qty, 'unit_cost' => $cost,
                         'lot_code' => $row['lot'], 'expires_on' => $row['exp'] !== '' ? $row['exp'] : null];
        }
        if (!$lines) {
            $errors['lines'] = 'Pridajte aspoň jednu položku.';
        }
        if (!$errors) {
            try {
                $id = $this->stock()->post($v, $posted, $this->ctx->user());
                Session::flash('msg', 'Doklad ' . ($this->stock()->doc($id)['number'] ?? '') . ' je zaúčtovaný.');
                return Response::redirect($this->ctx->url('/stock/docs/' . $id));
            } catch (\RuntimeException $e) {
                $errors['doc'] = $e->getMessage();
            }
        }
        return Response::html($this->docForm($req, $params, $errors, $v, $lines), 422);
    }
}
