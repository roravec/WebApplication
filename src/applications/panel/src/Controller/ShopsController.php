<?php
namespace Panel\Controller;

use Panel\Audit;
use Panel\PanelContext;
use Panel\Rights;
use Panel\Secret;
use Panel\Shop\PrestaShopApi;
use Panel\Shop\ShopProducts;
use Panel\Shop\ShopStock;
use WebApplication\Csrf;
use WebApplication\Http\Request;
use WebApplication\Http\Response;
use WebApplication\Session;

/**
 * Sklad → E-shopy: connected PrestaShop installations (Webservice) and the stock of their shops, edited directly in the
 * shop (docs/sklad-specifikacia.md). Connections (URL + encrypted key) are managed by an administrator.
 */
class ShopsController
{
    const PER_PAGE = 100;

    /** @var PanelContext */
    private $ctx;

    public function __construct(PanelContext $ctx)
    {
        $this->ctx = $ctx;
    }

    private function t(): string
    {
        return $this->ctx->db()->table('shop_connection');
    }

    private function connection(int $id): ?array
    {
        $r = $this->ctx->db()->fetchOne("SELECT * FROM {$this->t()} WHERE id = ?", [$id]);
        return $r === false ? null : $r;
    }

    private function api(array $c): PrestaShopApi
    {
        $key = (new Secret($this->ctx->app->getJwtSecret()))->decrypt((string)$c['api_key']);
        if ($key === null) {
            throw new \RuntimeException('Uložený kľúč sa nedá prečítať (zmenil sa jwtSecret?) — zadajte ho znova.');
        }
        return new PrestaShopApi((string)$c['url'], $key);
    }

    private function notFound(): Response
    {
        return Response::html($this->ctx->page('notfound', [], 'E-shop neexistuje', 'stock'), 404);
    }

    public function index(Request $req, array $params): string
    {
        $list = [];
        foreach ($this->ctx->db()->query("SELECT * FROM {$this->t()} ORDER BY active DESC, name") as $c) {
            $c['shops'] = [];
            $c['error'] = '';
            if ($c['active']) {
                try {
                    $c['shops'] = (new ShopStock($this->api($c)))->shops();
                } catch (\Throwable $e) {
                    $c['error'] = $e->getMessage();
                }
            }
            $list[] = $c;
        }
        return $this->ctx->page('stock/shops', ['connections' => $list, 'isAdmin' => $this->ctx->isAdmin()], 'E-shopy', 'stock');
    }

    // ------------------------------------------------------------------ connection (administrator)

    public function form(Request $req, array $params, array $errors = [], ?array $values = null)
    {
        $c = isset($params['cid']) ? $this->connection((int)$params['cid']) : null;
        if (isset($params['cid']) && !$c) {
            return $this->notFound();
        }
        $values = $values ?? ($c ?: ['name' => '', 'url' => 'https://', 'active' => 1]);
        return $this->ctx->page('stock/shop-form', ['c' => $c, 'values' => $values, 'errors' => $errors],
            $c ? 'Pripojenie ' . $c['name'] : 'Pripojiť e-shop', 'stock');
    }

    public function save(Request $req, array $params)
    {
        if (!Csrf::check($req)) {
            return Response::text('Formulár vypršal, načítajte stránku znova.', 400);
        }
        $c = isset($params['cid']) ? $this->connection((int)$params['cid']) : null;
        if (isset($params['cid']) && !$c) {
            return $this->notFound();
        }
        $v = ['name' => trim((string)$req->post('name', '')), 'url' => trim((string)$req->post('url', '')),
              'active' => $req->post('active') ? 1 : 0, 'local_db' => $req->post('local_db') ? 1 : 0];
        $key = trim((string)$req->post('api_key', ''));
        $errors = [];
        if ($v['name'] === '') {
            $errors['name'] = 'Zadajte názov.';
        }
        if (!preg_match('#^https?://[^\s/]+#i', $v['url'])) {
            $errors['url'] = 'Adresa e-shopu, napr. https://www.example.sk/ (s podpriečinkom, ak je e-shop v ňom).';
        }
        if (!$c && $key === '') {
            $errors['api_key'] = 'Zadajte kľúč Webservice z e-shopu.';
        }
        if ($key !== '' && !preg_match('/^[A-Za-z0-9]{32}$/', $key)) {
            $errors['api_key'] = 'Kľúč Webservice má 32 znakov (písmená a čísla).';
        }
        $shops = null;
        if (!$errors) {
            // check the connection before saving
            try {
                $plainKey = $key !== '' ? $key : (new Secret($this->ctx->app->getJwtSecret()))->decrypt((string)$c['api_key']);
                $shops = (new ShopStock(new PrestaShopApi($v['url'], (string)$plainKey)))->shops();
            } catch (\Throwable $e) {
                $errors['test'] = 'Spojenie s e-shopom zlyhalo: ' . $e->getMessage();
            }
        }
        if ($errors) {
            return Response::html($this->form($req, $params, $errors, $v), 422);
        }
        $db = $this->ctx->db();
        if ($c) {
            $db->execute("UPDATE {$this->t()} SET name = ?, url = ?, active = ?, local_db = ? WHERE id = ?",
                [$v['name'], $v['url'], $v['active'], $v['local_db'], (int)$c['id']]);
            if ($key !== '') {
                $db->execute("UPDATE {$this->t()} SET api_key = ? WHERE id = ?", [(new Secret($this->ctx->app->getJwtSecret()))->encrypt($key), (int)$c['id']]);
            }
            $id = (int)$c['id'];
        } else {
            $db->execute("INSERT INTO {$this->t()} (name, url, api_key, active, local_db, created_at) VALUES (?, ?, ?, ?, ?, ?)",
                [$v['name'], $v['url'], (new Secret($this->ctx->app->getJwtSecret()))->encrypt($key), $v['active'], $v['local_db'], date('Y-m-d H:i:s')]);
            $id = $db->lastInsertId();
        }
        if ($v['local_db']) {   // only one connection can be "this database"
            $db->execute("UPDATE {$this->t()} SET local_db = 0 WHERE id <> ?", [$id]);
        }
        (new \Panel\Shop\ShopCache($db, $id))->clear();   // URL / key / shop may have changed
        Audit::log($db, $this->ctx->user(), 'shop_connection', $id, $c ? 'update' : 'create', $v['url']);
        Session::flash('msg', 'Pripojenie uložené — e-shopov: ' . count($shops) . ' (' . implode(', ', array_column($shops, 'name')) . ').');
        return Response::redirect($this->ctx->url('/stock/shops'));
    }

    // ------------------------------------------------------------------ stock of one shop

    public function stock(Request $req, array $params)
    {
        $c = $this->connection((int)$params['cid']);
        if (!$c || !$c['active']) {
            return $this->notFound();
        }
        $f = ['q' => trim((string)$req->query('q', '')), 'inactive' => (bool)$req->query('inactive'), 'page' => max(1, (int)$req->query('page', 1))];
        $error = '';
        $shop = null;
        $rows = [];
        try {
            $ss = new ShopStock($this->api($c));
            $shop = $ss->shop((int)$params['sid']);
            if (!$shop) {
                return $this->notFound();
            }
            $rows = $ss->rows($shop, $f['q']);
            if (!$f['inactive']) {
                $rows = array_values(array_filter($rows, function ($r) { return $r['active']; }));
            }
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }
        $total = count($rows);
        $pages = max(1, (int)ceil($total / self::PER_PAGE));
        $page = min($f['page'], $pages);
        return $this->ctx->page('stock/shop-stock', ['c' => $c, 'shop' => $shop, 'rows' => array_slice($rows, ($page - 1) * self::PER_PAGE, self::PER_PAGE),
            'total' => $total, 'page' => $page, 'pages' => $pages, 'filters' => $f, 'error' => $error,
            'canEdit' => $this->ctx->can(Rights::STOCK_EDIT)], 'Sklad e-shopu' . ($shop ? ' ' . $shop['name'] : ''), 'stock');
    }

    /** GET — stock settings of one product and its combinations (in the shop). */
    public function product(Request $req, array $params, array $errors = [])
    {
        $c = $this->connection((int)$params['cid']);
        if (!$c || !$c['active']) {
            return $this->notFound();
        }
        try {
            $api = $this->api($c);
            $ss = new ShopStock($api);
            $shop = $ss->shop((int)$params['sid']);
            if (!$shop) {
                return $this->notFound();
            }
            $product = (new ShopProducts($api))->product($shop, (int)$params['pid'], $ss->langId());
        } catch (\Throwable $e) {
            Session::flash('error', $e->getMessage());
            return Response::redirect($this->ctx->url('/stock/shops/' . $c['id'] . '/' . (int)$params['sid']));
        }
        return $this->ctx->page('stock/shop-product', ['c' => $c, 'shop' => $shop, 'p' => $product, 'errors' => $errors,
            'canEdit' => $this->ctx->can(Rights::STOCK_EDIT)], $product['name'] ?: 'Produkt', 'stock');
    }

    /** POST — save changed product / combination settings in the shop. */
    public function productSave(Request $req, array $params)
    {
        if (!Csrf::check($req)) {
            return Response::text('Formulár vypršal, načítajte stránku znova.', 400);
        }
        $c = $this->connection((int)$params['cid']);
        if (!$c || !$c['active']) {
            return $this->notFound();
        }
        $pid = (int)$params['pid'];
        $back = $this->ctx->url('/stock/shops/' . $c['id'] . '/' . (int)$params['sid'] . '/product/' . $pid);
        $int = function ($v, $min) { return preg_match('/^\d+$/', trim((string)$v)) && (int)$v >= $min; };
        $money = function ($v) {
            $x = \Panel\Invoicing\Calculator::parse($v);
            return $x !== null && $x >= 0 ? number_format($x, 6, '.', '') : null;
        };
        $errors = [];
        $productChanges = [];
        foreach (array_keys(ShopProducts::PRODUCT_FIELDS) as $f) {
            $new = trim((string)$req->post($f, ''));
            $orig = (string)$req->post('orig_' . $f, '');
            if ($f === 'low_stock_alert') {
                $new = $req->post($f) ? '1' : '0';
            }
            if ($new === $orig || ($f === 'wholesale_price' && $money($new) !== null && $money($new) === $money($orig))) {
                continue;
            }
            if ($f === 'minimal_quantity' && !$int($new, 1)) {
                $errors[] = 'Minimálne množstvo: celé číslo ≥ 1';
            } elseif ($f === 'low_stock_threshold' && $new !== '' && !$int($new, 0)) {
                $errors[] = 'Nízky stav: celé číslo';
            } elseif ($f === 'low_stock_threshold' && $new === '') {
                $new = '0';   // the Webservice can't set "not set" (NULL) back
            } elseif ($f === 'wholesale_price') {
                $new = $money($new);
                if ($new === null) {
                    $errors[] = 'Nákupná cena: číslo ≥ 0';
                }
            } elseif (in_array($f, ShopProducts::LANG_FIELDS, true) && mb_strlen($new, 'UTF-8') > 255) {
                $errors[] = 'Text dostupnosti: najviac 255 znakov';
            }
            $productChanges[$f] = $new;
        }
        $comboChanges = [];
        foreach ((array)$req->post('combo', []) as $cid => $row) {
            if (!is_array($row)) {
                continue;
            }
            foreach (ShopProducts::COMBINATION_FIELDS as $f) {
                $new = trim((string)($row[$f] ?? ''));
                $orig = (string)($row['orig_' . $f] ?? '');
                if ($new === $orig || ($f === 'wholesale_price' && $money($new) !== null && $money($new) === $money($orig))) {
                    continue;
                }
                if ($f === 'minimal_quantity' && !$int($new, 1)) {
                    $errors[] = 'Kombinácia ' . $cid . ': minimálne množstvo ≥ 1';
                    continue;
                }
                if ($f === 'low_stock_threshold' && $new !== '' && !$int($new, 0)) {
                    $errors[] = 'Kombinácia ' . $cid . ': nízky stav celé číslo';
                    continue;
                }
                if ($f === 'wholesale_price') {
                    $new = $money($new);
                    if ($new === null) {
                        $errors[] = 'Kombinácia ' . $cid . ': nákupná cena číslo ≥ 0';
                        continue;
                    }
                }
                $comboChanges[(int)$cid][$f] = $new === '' ? '0' : $new;
            }
        }
        if ($errors) {
            Session::flash('error', implode(' · ', array_unique($errors)));
            return Response::redirect($back);
        }
        $done = 0;
        try {
            $api = $this->api($c);
            $ss = new ShopStock($api);
            $shop = $ss->shop((int)$params['sid']);
            if (!$shop) {
                return $this->notFound();
            }
            $sp = new ShopProducts($api);
            if ($productChanges) {
                $sp->updateProduct($shop, $pid, $productChanges, $ss->langId());
                $done++;
                Audit::log($this->ctx->db(), $this->ctx->user(), 'shop_product', $pid, 'update',
                    $shop['name'] . ': ' . json_encode($productChanges, JSON_UNESCAPED_UNICODE));
            }
            foreach ($comboChanges as $cid => $ch) {
                $sp->updateCombination($shop, $cid, $pid, $ch);
                $done++;
                Audit::log($this->ctx->db(), $this->ctx->user(), 'shop_combination', $cid, 'update',
                    $shop['name'] . ' #' . $pid . ': ' . json_encode($ch, JSON_UNESCAPED_UNICODE));
            }
        } catch (\Throwable $e) {
            Session::flash('error', ($done ? 'Čiastočne uložené (' . $done . '). ' : '') . $e->getMessage());
            return Response::redirect($back);
        }
        Session::flash('msg', $done ? 'Uložené v e-shope.' : 'Nič sa nezmenilo.');
        return Response::redirect($back);
    }

    /** POST — save the changed rows in the shop (only rows whose values differ from what was loaded). */
    public function stockSave(Request $req, array $params)
    {
        if (!Csrf::check($req)) {
            return Response::text('Formulár vypršal, načítajte stránku znova.', 400);
        }
        $c = $this->connection((int)$params['cid']);
        if (!$c || !$c['active']) {
            return $this->notFound();
        }
        $back = $this->ctx->url('/stock/shops/' . $c['id'] . '/' . (int)$params['sid']) . '?' . http_build_query(array_filter([
            'q' => (string)$req->post('q', ''), 'inactive' => (string)$req->post('inactive', ''), 'page' => (string)$req->post('page', '')]));
        $ok = 0;
        $errors = [];
        try {
            $ss = new ShopStock($this->api($c));
            $shop = $ss->shop((int)$params['sid']);
            if (!$shop) {
                return $this->notFound();
            }
            foreach ((array)$req->post('row', []) as $stockId => $r) {
                if (!is_array($r)) {
                    continue;
                }
                $changes = [];
                foreach (['quantity', 'out_of_stock', 'location'] as $k) {
                    if (isset($r[$k], $r['orig_' . $k]) && (string)$r[$k] !== (string)$r['orig_' . $k]) {
                        $changes[$k] = $k === 'location' ? trim((string)$r[$k]) : (int)$r[$k];
                    }
                }
                if (isset($changes['quantity']) && !preg_match('/^-?\d+$/', trim((string)$r['quantity']))) {
                    $errors[] = ($r['name'] ?? $stockId) . ': množstvo musí byť celé číslo';
                    continue;
                }
                if (!$changes) {
                    continue;
                }
                try {
                    $new = $ss->update($shop, (int)$stockId, $changes);
                    $ok++;
                    $desc = [];
                    foreach ($changes as $k => $val) {
                        $desc[] = $k . ' ' . $r['orig_' . $k] . ' → ' . $new[$k];
                    }
                    Audit::log($this->ctx->db(), $this->ctx->user(), 'shop_stock', (int)$stockId, 'update',
                        $shop['name'] . ': ' . mb_substr((string)($r['name'] ?? ''), 0, 80, 'UTF-8') . ' — ' . implode(', ', $desc));
                } catch (\RuntimeException $e) {
                    $errors[] = ($r['name'] ?? $stockId) . ': ' . $e->getMessage();
                }
            }
        } catch (\Throwable $e) {
            $errors[] = $e->getMessage();
        }
        if ($ok) {
            Session::flash('msg', 'Zmenené v e-shope: ' . $ok . ' ' . ($ok === 1 ? 'položka' : ($ok < 5 ? 'položky' : 'položiek')) . '.');
        }
        if ($errors) {
            Session::flash('error', implode(' · ', array_slice($errors, 0, 5)));
        }
        if (!$ok && !$errors) {
            Session::flash('msg', 'Nič sa nezmenilo.');
        }
        return Response::redirect($back);
    }
}
