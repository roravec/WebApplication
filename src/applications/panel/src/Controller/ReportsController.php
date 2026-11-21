<?php
namespace Panel\Controller;

use Panel\PanelContext;
use Panel\Reports\SalesReport;
use WebApplication\Http\Request;
use WebApplication\Http\Response;

/** Výsledky → Predaje a marža (docs/sprava-firmy-specifikacia.md, module A). CSV with ?csv=1. */
class ReportsController
{
    /** @var PanelContext */
    private $ctx;

    public function __construct(PanelContext $ctx)
    {
        $this->ctx = $ctx;
    }

    public function sales(Request $req, array $params)
    {
        if (!$this->ctx->shopOrders()->available()) {
            return $this->ctx->page('reports/unavailable', [], 'Predaje a marža', 'reports');
        }
        $report = $this->ctx->salesReport();
        $allShops = $report->shops();
        // only e-shops of the companies the employee may see (+ e-shops not assigned to any company: admin only)
        $companies = $this->ctx->companies();
        $visible = [];
        foreach ($allShops as $id => $name) {
            $company = $companies->companyForShop(0, $id);
            if ($company ? $this->ctx->canCompany($company) : $this->ctx->isAdmin()) {
                $visible[$id] = $name;
            }
        }
        $f = $this->filters($req, $visible);
        $net = $this->vatBasis();
        $oc = $this->ctx->orderCosts();
        $data = $f['shops'] ? $report->compute($f, $net, $oc->rules() ? $oc->loader() : null) : null;
        if ($req->query('csv') && $data) {
            return $this->csv($data, $f);
        }
        return $this->ctx->page('reports/sales', ['f' => $f, 'shops' => $visible, 'data' => $data, 'groups' => SalesReport::GROUPS],
            'Predaje a marža', 'reports');
    }

    // ------------------------------------------------------------------ module B: purchase prices

    const PP_PER_PAGE = 100;
    const PP_QUEUE = 'pp_queue';

    /** e-shops (id => name) of this database the employee may see */
    private function visibleShops(): array
    {
        $companies = $this->ctx->companies();
        $out = [];
        foreach ($this->ctx->salesReport()->shops() as $id => $name) {
            $company = $companies->companyForShop(0, $id);
            if ($company ? $this->ctx->canCompany($company) : $this->ctx->isAdmin()) {
                $out[$id] = $name;
            }
        }
        return $out;
    }

    /** Výsledky → Nákupné ceny: products without a purchase price (sold first), completed in the shop via Webservice. */
    public function purchasePrices(Request $req, array $params, array $errors = [], array $posted = [])
    {
        if (!$this->ctx->shopOrders()->available()) {
            return $this->ctx->page('reports/unavailable', [], 'Nákupné ceny', 'reports');
        }
        $visible = $this->visibleShops();
        $want = array_map('intval', (array)$req->query('shop', []));
        $shops = $want ? array_values(array_intersect($want, array_keys($visible))) : array_keys($visible);
        $filter = in_array($req->query('filter'), ['missing_sold', 'missing_sold_all', 'missing', 'all'], true) ? (string)$req->query('filter') : 'missing_sold';
        $q = trim((string)$req->query('q', ''));
        $all = $this->ctx->salesReport()->purchasePriceList($shops, $filter, $q);
        $page = max(1, (int)$req->query('page', 1));
        $pages = max(1, (int)ceil(count($all) / self::PP_PER_PAGE));
        $page = min($page, $pages);
        $conn = $this->ctx->localConnection();
        $queue = \WebApplication\Session::get(self::PP_QUEUE, []);
        return $this->ctx->page('reports/purchase-prices', [
            'shops' => $visible, 'picked' => $want ? $shops : [], 'filter' => $filter, 'q' => $q, 'rows' => array_slice($all, ($page - 1) * self::PP_PER_PAGE, self::PP_PER_PAGE),
            'total' => count($all), 'page' => $page, 'pages' => $pages, 'errors' => $errors, 'posted' => $posted,
            'canEdit' => $this->ctx->can(\Panel\Rights::STOCK_EDIT) && $conn !== null, 'connection' => $conn,
            'canStock' => $this->ctx->can(\Panel\Rights::STOCK_EDIT), 'queue' => is_array($queue) ? count($queue) : 0,
        ], 'Nákupné ceny', 'reports');
    }

    /** POST: changed prices (prices[pid-aid]) or a CSV paste (code;price) → queue → written in the shop. */
    public function purchasePricesSave(Request $req, array $params)
    {
        if (!\WebApplication\Csrf::check($req)) {
            return Response::text('Formulár vypršal, načítajte stránku znova.', 400);
        }
        $report = $this->ctx->salesReport();
        $visible = $this->visibleShops();
        $parse = function ($v) {
            $v = str_replace([' ', "\xC2\xA0", ','], ['', '', '.'], trim((string)$v));
            return preg_match('/^\d{1,9}(\.\d{1,6})?$/', $v) ? (float)$v : null;
        };
        $queue = [];
        $errors = [];
        $old = (array)$req->post('old', []);
        foreach ((array)$req->post('prices', []) as $key => $value) {
            if (trim((string)$value) === '' || !preg_match('/^(\d+)-(\d+)$/', (string)$key, $m)) {
                continue;
            }
            $price = $parse($value);
            if ($price === null) {
                $errors[$key] = 'Neplatná cena „' . $value . '“.';
                continue;
            }
            if (isset($old[$key]) && abs((float)$old[$key] - $price) < 0.000001) {
                continue;   // unchanged
            }
            $queue[] = [(int)$m[1], (int)$m[2], $price];
        }
        // CSV paste: "code;price" per line (code = product or combination reference)
        $csv = trim((string)$req->post('csv', ''));
        if ($csv !== '') {
            $byRef = [];
            foreach ($report->purchasePriceList(array_keys($visible), 'all') as $r) {
                if ($r['reference'] !== '') {
                    $byRef[mb_strtolower($r['reference'], 'UTF-8')][] = $r;
                }
            }
            foreach (preg_split('/\r?\n/', $csv) as $i => $line) {
                $parts = preg_split('/[;\t]/', trim($line));
                if (count($parts) < 2 || trim($parts[0]) === '') {
                    continue;
                }
                $price = $parse($parts[1]);
                $hits = $byRef[mb_strtolower(trim($parts[0]), 'UTF-8')] ?? [];
                if ($price === null) {
                    $errors['csv' . $i] = 'Riadok ' . ($i + 1) . ': neplatná cena „' . $parts[1] . '“.';
                } elseif (!$hits) {
                    $errors['csv' . $i] = 'Riadok ' . ($i + 1) . ': kód „' . trim($parts[0]) . '“ sa v e-shopoch nenašiel.';
                } else {
                    foreach ($hits as $h) {
                        $queue[] = [$h['product_id'], $h['attribute_id'], $price];
                    }
                }
            }
        }
        if ($errors) {
            return Response::html($this->purchasePrices($req, $params, $errors, (array)$req->post('prices', [])), 422);
        }
        if (!$queue) {
            \WebApplication\Session::flash('msg', 'Nič sa nezmenilo.');
            return Response::redirect($this->ctx->url('/reports/purchase-prices') . $this->ppBack($req));
        }
        \WebApplication\Session::set(self::PP_QUEUE, $queue);
        \WebApplication\Session::set(self::PP_QUEUE . '_back', $this->ppBack($req));
        return $this->purchasePricesRun();
    }

    /** GET …/continue: next part of the queue (the page reloads itself until everything is written). */
    public function purchasePricesContinue(Request $req, array $params)
    {
        return $this->purchasePricesRun();
    }

    private function purchasePricesRun(): Response
    {
        $queue = \WebApplication\Session::get(self::PP_QUEUE, []);
        $back = (string)\WebApplication\Session::get(self::PP_QUEUE . '_back', '');
        $conn = $this->ctx->localConnection();
        if (!$queue || $conn === null) {
            \WebApplication\Session::remove(self::PP_QUEUE);
            return Response::redirect($this->ctx->url('/reports/purchase-prices') . $back);
        }
        $report = $this->ctx->salesReport();
        $products = new \Panel\Shop\ShopProducts($this->ctx->shopApi($conn));
        $t0 = microtime(true);
        $done = (int)\WebApplication\Session::get(self::PP_QUEUE . '_done', 0);
        $failed = [];
        while ($queue && microtime(true) - $t0 < 20) {
            list($pid, $aid, $price) = array_shift($queue);
            $value = number_format($price, 6, '.', '');
            try {
                foreach ($report->productShops($pid) as $sid) {
                    if ($aid) {
                        $products->updateCombination(['id' => $sid], $aid, $pid, ['wholesale_price' => $value]);
                    } else {
                        $products->updateProduct(['id' => $sid], $pid, ['wholesale_price' => $value], 0);
                    }
                }
                \Panel\Audit::log($this->ctx->db(), $this->ctx->user(), $aid ? 'shop_combination' : 'shop_product', $aid ?: $pid, 'purchase_price',
                    '#' . $pid . ($aid ? '/' . $aid : '') . ' → ' . $value);
                $done++;
            } catch (\Throwable $e) {
                $failed[] = '#' . $pid . ($aid ? '/' . $aid : '') . ': ' . $e->getMessage();
            }
        }
        if ($failed) {
            \WebApplication\Session::flash('error', 'Nepodarilo sa uložiť: ' . implode('; ', array_slice($failed, 0, 5)) . (count($failed) > 5 ? ' …' : ''));
        }
        if ($queue) {
            \WebApplication\Session::set(self::PP_QUEUE, $queue);
            \WebApplication\Session::set(self::PP_QUEUE . '_done', $done);
            $url = $this->ctx->url('/reports/purchase-prices/continue');
            return Response::html('<!doctype html><meta charset="utf-8"><meta http-equiv="refresh" content="1;url=' . htmlspecialchars($url) . '">'
                . '<p style="font-family:sans-serif">Ukladám nákupné ceny do e-shopu: uložených ' . $done . ', zostáva ' . count($queue) . '…</p>');
        }
        \WebApplication\Session::remove(self::PP_QUEUE);
        \WebApplication\Session::remove(self::PP_QUEUE . '_done');
        \WebApplication\Session::flash('msg', 'Nákupné ceny uložené do e-shopu: ' . $done . '. Prehľady ich hneď používajú (pri starších objednávkach ako odhad).');
        return Response::redirect($this->ctx->url('/reports/purchase-prices') . $back);
    }

    private function ppBack(Request $req): string
    {
        $q = array_intersect_key((array)$req->query(), array_flip(['shop', 'filter', 'q', 'page']));
        return $q ? '?' . http_build_query($q) : '';
    }

    /**
     * Per shop and date: net amounts for a VAT payer, gross for a non-payer; a non-payer whose company setting says
     * "pripočítať DPH" counts purchase prices (entered without VAT in the shop) with the standard rate of that date.
     */
    private function vatBasis(): callable
    {
        return $this->ctx->vatBasis();
    }

    private function filters(Request $req, array $visible): array
    {
        $valid = function ($d) { return is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false; };
        $from = (string)$req->query('from', '');
        $to = (string)$req->query('to', '');
        if (!$valid($from)) {
            $from = date('Y-m-01', strtotime(date('Y-m-01') . ' -11 months'));   // last 12 months incl. this one
        }
        if (!$valid($to)) {
            $to = date('Y-m-t');
        }
        if ($to < $from) {
            list($from, $to) = [$to, $from];
        }
        $want = array_map('intval', (array)$req->query('shop', []));
        $shops = $want ? array_values(array_intersect($want, array_keys($visible))) : array_keys($visible);
        $group = (string)$req->query('group', 'month');
        return ['from' => $from, 'to' => $to, 'shops' => $shops, 'picked' => $want ? $shops : [],
            'group' => isset(SalesReport::GROUPS[$group]) ? $group : 'month'];
    }

    // ------------------------------------------------------------------ module E: result, money, VAT turnover

    /** @return array{0: array, 1: array}|Response [companies the employee may see, the chosen one] */
    private function pickCompany(Request $req, string $title)
    {
        $companies = $this->ctx->companies()->all($this->ctx->companyIds());
        if (!$companies) {
            return Response::html($this->ctx->page('invoices/no-company', [], $title, 'reports'));
        }
        $byId = array_column($companies, null, 'id');
        $cid = (int)$req->query('company', 0);
        return [$companies, $byId[$cid] ?? $companies[0]];
    }

    /** Výsledky → Výsledok firmy: profit by month (vs. the previous year), by e-shop, order costs estimate vs. real, VAT turnover. */
    public function result(Request $req, array $params)
    {
        $picked = $this->pickCompany($req, 'Výsledok firmy');
        if ($picked instanceof Response) {
            return $picked;
        }
        list($companies, $company) = $picked;
        $cid = (int)$company['id'];
        $year = (int)$req->query('year', date('Y'));
        $year = $year >= 2000 && $year <= (int)date('Y') ? $year : (int)date('Y');
        $to = $year === (int)date('Y') ? date('Y-m-t') : $year . '-12-31';
        $from = $year . '-01-01';
        $res = $this->businessResult();
        $shops = $this->ctx->companyShops($cid);
        $net = $this->vatBasis();
        $cur = $res->compute($cid, $shops, $from, $to, $net);
        $prev = $res->compute($cid, $shops, ($year - 1) . '-01-01', ($year - 1) . substr($to, 4), $net);
        $today = date('Y-m-d');
        $payer = $this->ctx->companies()->vatOn($cid, $today)['vat_payer'];
        $vat = $payer || $year !== (int)date('Y') ? null : (new \Panel\Reports\VatTurnover($this->ctx->db()))->status($cid, $this->shopSales(), $shops, $today);
        return $this->ctx->page('reports/result', ['companies' => $companies, 'company' => $company, 'year' => $year, 'cur' => $cur,
            'prev' => $prev, 'payer' => $payer, 'vat' => $vat, 'hasShops' => (bool)$shops], 'Výsledok firmy', 'reports');
    }

    /** Výsledky → Peniaze: receivables, payables by due date, money in / out by month. */
    public function money(Request $req, array $params)
    {
        $picked = $this->pickCompany($req, 'Peniaze');
        if ($picked instanceof Response) {
            return $picked;
        }
        list($companies, $company) = $picked;
        $cid = (int)$company['id'];
        $money = new \Panel\Reports\Money($this->ctx->db());
        $today = date('Y-m-d');
        $rec = $money->receivables($cid, $today);
        $pay = $money->payables($cid, $today);
        $soon = [];
        foreach (['in' => $rec['rows'], 'out' => $pay['rows']] as $dir => $rows) {
            foreach ($rows as $r) {
                if ($r['due_date'] !== null && $r['due_date'] <= date('Y-m-d', strtotime('+30 days'))) {
                    $soon[] = $r + ['dir' => $dir];
                }
            }
        }
        usort($soon, function ($a, $b) { return strcmp($a['due_date'], $b['due_date']); });
        return $this->ctx->page('reports/money', ['companies' => $companies, 'company' => $company, 'rec' => $rec, 'pay' => $pay, 'soon' => $soon,
            'flows' => $money->flows($cid, date('Y-m-01', strtotime(date('Y-m-01') . ' -11 months')), date('Y-m-t')),
            'canInvoices' => $this->ctx->can(\Panel\Rights::INVOICES_VIEW), 'canExpenses' => $this->ctx->can(\Panel\Rights::EXPENSES_VIEW)],
            'Peniaze', 'reports');
    }

    private function shopSales(): ?SalesReport
    {
        return $this->ctx->shopOrders()->available() ? $this->ctx->salesReport() : null;
    }

    private function businessResult(): \Panel\Reports\BusinessResult
    {
        $oc = $this->ctx->shopOrders()->available() ? $this->ctx->orderCosts() : null;
        return new \Panel\Reports\BusinessResult($this->ctx->db(), $this->shopSales(), $oc && $oc->rules() ? $oc->loader() : null);
    }

    // ------------------------------------------------------------------ module D: order cost rules

    /** Výsledky → Náklady objednávok: shipping by carrier, payment fees, packaging (rules with validity dates). */
    public function orderCosts(Request $req, array $params, array $errors = [], ?array $posted = null)
    {
        if (!$this->ctx->shopOrders()->available()) {
            return $this->ctx->page('reports/unavailable', [], 'Náklady objednávok', 'settings:order-costs');
        }
        $oc = $this->ctx->orderCosts();
        $shops = $this->visibleShops();
        $rules = array_values(array_filter($oc->rules(), function ($r) use ($shops) {
            return $r['shop_id'] === null || isset($shops[(int)$r['shop_id']]);
        }));
        return $this->ctx->page('reports/order-costs', ['rules' => $posted ?? $rules, 'shops' => $shops, 'carriers' => $oc->carriers(),
            'payments' => $oc->paymentModules(), 'errors' => $errors, 'global' => $this->ctx->isAdmin(),
            'canEdit' => $this->ctx->can(\Panel\Rights::EXPENSES_EDIT)], 'Náklady objednávok', 'settings:order-costs');
    }

    public function orderCostsSave(Request $req, array $params)
    {
        if (!\WebApplication\Csrf::check($req)) {
            return Response::text('Formulár vypršal, načítajte stránku znova.', 400);
        }
        $oc = $this->ctx->orderCosts();
        $carriers = $oc->carriers();
        $payments = $oc->paymentModules();
        $rows = [];
        foreach ((array)$req->post('rule', []) as $r) {
            if (!is_array($r)) {
                continue;
            }
            // the row has a carrier and a payment select — the type decides which one applies;
            // the name shown later = carrier / payment name at the time of saving
            $type = (string)($r['type'] ?? '');
            $key = $type === 'shipping' ? (string)($r['carrier'] ?? '') : ($type === 'payment' ? (string)($r['payment'] ?? '') : '');
            $r['match_key'] = $key;
            $r['label'] = $key === '' ? '' : ($type === 'shipping' ? ($carriers[$key] ?? $key) : ($payments[$key] ?? $key));
            $rows[] = $r;
        }
        $errors = $oc->save($rows, array_keys($this->visibleShops()), $this->ctx->isAdmin(), $this->ctx->user());
        if ($errors) {
            return Response::html($this->orderCosts($req, $params, $errors, array_map(function ($r) {
                return $r + ['id' => '', 'shop_id' => '', 'type' => '', 'match_key' => '', 'fixed' => '', 'percent' => '', 'valid_from' => '',
                    'valid_to' => '', 'note' => ''];
            }, $rows)), 422);
        }
        \WebApplication\Session::flash('msg', 'Náklady objednávok uložené — Predaje a marža ich hneď započítajú.');
        return Response::redirect($this->ctx->url('/settings/order-costs'));
    }

    /** The grouped table for Excel (semicolon, UTF-8 with BOM, comma decimals). */
    private function csv(array $data, array $f): Response
    {
        $n = function ($v) { return number_format((float)$v, 2, ',', ''); };
        $out = "\xEF\xBB\xBF" . implode(';', [SalesReport::GROUPS[$data['group']], 'Objednávky', 'Kusy', 'Tržby z tovaru', 'Zľavy', 'Doprava',
                'Nákup tovaru', 'Hrubá marža', 'Marža %', 'Položky bez nákupnej ceny', 'Položky s odhadom', 'Náklady na dopravu',
                'Poplatky za platby', 'Balné', 'Príspevok na krytie', 'Príspevok %']) . "\r\n";
        // order costs are per order: in product / category groups only in the total
        $orderLevel = $data['costs'] && in_array($data['group'], ['month', 'shop'], true);
        foreach (array_merge($data['rows'], [['label' => 'SPOLU', 'total_row' => true] + $data['summary']]) as $r) {
            $costs = ($orderLevel || ($data['costs'] && !empty($r['total_row'])))
                ? [$n($r['ship_cost']), $n($r['pay_cost']), $n($r['pack_cost']), $n($r['contribution']),
                   $r['contribution_pct'] === null ? '' : $n($r['contribution_pct'])]
                : ['', '', '', '', ''];
            $out .= implode(';', array_merge(['"' . str_replace('"', '""', (string)$r['label']) . '"', $r['orders'], $r['qty'], $n($r['revenue']),
                    $n($r['discounts']), $n($r['shipping']), $n($r['cogs']), $n($r['margin']),
                    $r['margin_pct'] === null ? '' : $n($r['margin_pct']), $r['missing'], $r['estimated']], $costs)) . "\r\n";
        }
        $name = 'predaje-' . $data['group'] . '-' . $f['from'] . '_' . $f['to'] . '.csv';
        return new Response($out, 200, ['Content-Type' => 'text/csv; charset=utf-8', 'Content-Disposition' => 'attachment; filename="' . $name . '"']);
    }
}
