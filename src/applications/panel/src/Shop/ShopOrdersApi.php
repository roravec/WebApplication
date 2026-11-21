<?php
namespace Panel\Shop;

/**
 * Orders of a connected PrestaShop read ONLY through its Webservice (no database access) — the same data as
 * ShopOrders gives from the shared database: list, detail (lines, addresses, payments, state history), states, shops.
 *
 * Webservice facts (PrestaShop 1.7.6.7, checked on the dev1 copy 2026-11-20):
 * - MULTISTORE: lists of orders and customers contain only the shop of the context (`id_shop`, default = the default
 *   shop; there is no "all shops" context) → every list is read per shop (in parallel) and merged; one order
 *   (`orders/{id}`), order_details, order_histories and addresses are not limited by the context;
 * - only one sort field works (`[date_add_DESC,id_DESC]` → HTTP 400) → sorted by id (= order of creation);
 * - lists have no total count → the ids matching the filter are fetched (`display=[id]`, ~85 kB for 2 500 orders);
 * - filters are AND only → a search (reference OR customer OR e-mail …) = several queries in parallel, merged here;
 * - order_details has no VAT rate → computed from the line totals (incl / excl), snapped to whole percent;
 * - a customer is read through the list with `display` (a single customer would include the password hash).
 * Rarely-changing lists (states, shops, currencies, countries, carriers, language) are cached (ShopCache).
 */
class ShopOrdersApi implements OrderSource
{
    const LIST_FIELDS = '[id,reference,id_shop,date_add,total_paid_tax_incl,payment,current_state,id_customer,id_address_invoice,id_currency]';
    const LISTS_TTL = 3600;
    const DEFAULT_LIST_TTL = 60;

    /** @var PrestaShopApi */ private $api;
    /** @var int */ private $id;
    /** @var string */ private $label;
    /** @var string */ private $langIso;
    /** @var ShopCache|null */ private $cache;
    /** @var array */ private $memo = [];

    public function __construct(PrestaShopApi $api, int $connectionId, string $label, string $langIso = 'sk', ?ShopCache $cache = null)
    {
        $this->api = $api;
        $this->id = $connectionId;
        $this->label = $label;
        $this->langIso = $langIso;
        $this->cache = $cache;
    }

    public function id(): int
    {
        return $this->id;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function available(): bool
    {
        return true;   // errors (unreachable shop, missing permission) come as readable exceptions from the calls
    }

    public function shops(): array
    {
        return $this->cached('shops', self::LISTS_TTL, function () {
            $out = [];
            foreach (self::each($this->api->get('shops', ['display' => '[id,name]'])->shops, 'shop') as $s) {
                $out[] = ['id_shop' => (int)$s->id, 'name' => (string)$s->name];
            }
            usort($out, function ($a, $b) { return $a['id_shop'] - $b['id_shop']; });
            return $out;
        });
    }

    public function states(): array
    {
        return array_values(array_filter($this->allStates(), function ($s) { return !$s['deleted']; }));
    }

    public function search(array $f, int $page = 1, int $perPage = 50): array
    {
        $perPage = max(1, min(200, $perPage));
        $offset = max(0, ($page - 1) * $perPage);
        $q = self::clean((string)($f['q'] ?? ''));
        $plain = $q === '' && empty($f['shop']) && empty($f['state']) && empty($f['from']) && empty($f['to']);
        $load = function () use ($f, $q, $offset, $perPage) {
            $found = $this->matchingIds($f, $q);
            $byShop = [];
            foreach (array_slice($found, $offset, $perPage, true) as $id => $shop) {
                $byShop[$shop][] = $id;
            }
            $req = [];
            foreach ($byShop as $shop => $ids) {
                $req['o|' . $shop] = ['orders', ['id_shop' => $shop, 'display' => self::LIST_FIELDS, 'filter' => ['id' => '[' . implode('|', $ids) . ']']]];
            }
            $orders = [];
            foreach ($req ? $this->api->getMany($req) : [] as $res) {
                $orders = array_merge($orders, self::each($res->orders, 'order'));
            }
            return ['rows' => $this->listRows($orders), 'total' => count($found)];
        };
        // the unfiltered first pages (order list, overview) are cached a minute; cleared after a change from the panel
        return $plain && $page <= 2 ? $this->cached('orders:' . $page . ':' . $perPage, self::DEFAULT_LIST_TTL, $load) : $load();
    }

    public function find(int $id): ?array
    {
        // PrestaShop 9 returns an order (and its details, addresses …) only in the context of its shop → find the shop first
        $shop = null;
        $tries = [];
        foreach ($this->shops() as $sh) {
            $tries[(string)$sh['id_shop']] = ['orders', ['id_shop' => $sh['id_shop'], 'display' => '[id,id_shop]', 'filter' => ['id' => '[' . $id . ']']]];
        }
        foreach ($tries ? $this->api->getMany($tries, array_keys($tries)) : [] as $k => $x) {
            if ($x !== null && self::each($x->orders, 'order')) {
                $shop = (int)$k;
                break;
            }
        }
        if ($shop === null) {
            return null;
        }
        $ctx = ['id_shop' => $shop];
        $byOrder = $ctx + ['filter' => ['id_order' => '[' . $id . ']'], 'sort' => '[id_ASC]', 'display' => 'full'];
        try {
            $r = $this->api->getMany(['o' => ['orders/' . $id, $ctx], 'd' => ['order_details', $byOrder], 'h' => ['order_histories', $byOrder]]);
        } catch (\RuntimeException $e) {
            if ($e->getCode() === 404) {
                return null;
            }
            throw $e;
        }
        $o = [];
        foreach ($r['o']->order->children() as $k => $v) {
            if ($v->count() === 0 && $k !== 'secure_key') {
                $o[$k] = (string)$v;
            }
        }
        if (!isset($o['id'])) {
            return null;
        }
        $o['id_order'] = (int)$o['id'];
        $o['src'] = $this->id;
        $states = $this->allStates();
        $st = $this->stateById($states, (int)$o['current_state']);
        $o['state'] = $st['name'];
        $o['state_color'] = $st['color'];
        $o['shop'] = $this->shopName((int)$o['id_shop']);
        $o['currency'] = $this->currencies()[(int)$o['id_currency']] ?? '';
        $o['carrier'] = $this->carriers()[(int)$o['id_carrier']] ?? '';
        $o['carrier_url'] = '';
        $o['tracking'] = (string)($o['shipping_number'] ?? '');   // PrestaShop copies the order carrier's tracking number here

        $addrIds = array_unique(array_filter([(int)$o['id_address_invoice'], (int)$o['id_address_delivery']]));
        $r2 = $this->api->getMany([
            'c' => (int)$o['id_customer'] ? ['customers', ['id_shop' => (int)$o['id_shop'], 'display' => '[id,firstname,lastname,email,company]',
                'filter' => ['id' => '[' . (int)$o['id_customer'] . ']']]] : null,
            'a' => $addrIds ? ['addresses', $ctx + ['display' => 'full', 'filter' => ['id' => '[' . implode('|', $addrIds) . ']']]] : null,
            'p' => ['order_payments', $ctx + ['display' => 'full', 'filter' => ['order_reference' => '[' . self::clean($o['reference']) . ']'],
                'sort' => '[id_ASC]']],
        ], ['p']);
        $c = isset($r2['c']) ? (self::each($r2['c']->customers, 'customer')[0] ?? null) : null;
        $o['customer_firstname'] = $c ? (string)$c->firstname : '';
        $o['customer_lastname'] = $c ? (string)$c->lastname : '';
        $o['customer_email'] = $c ? (string)$c->email : '';
        $o['customer_company'] = $c ? (string)$c->company : '';
        $addresses = [];
        foreach (isset($r2['a']) ? self::each($r2['a']->addresses, 'address') : [] as $a) {
            $addresses[(int)$a->id] = $this->address($a);
        }
        $o['address_invoice'] = $addresses[(int)$o['id_address_invoice']] ?? null;
        $o['address_delivery'] = $addresses[(int)$o['id_address_delivery']] ?? null;
        $o['payments'] = [];
        $o['payments_unavailable'] = $r2['p'] === null;   // key without order_payments → shown as "unknown", not "none"
        foreach ($r2['p'] !== null ? self::each($r2['p']->order_payments, 'order_payment') : [] as $p) {
            $o['payments'][] = ['payment_method' => (string)$p->payment_method, 'amount' => (string)$p->amount,
                'transaction_id' => (string)$p->transaction_id, 'date_add' => (string)$p->date_add];
        }
        $o['lines'] = [];
        foreach (self::each($r['d']->order_details, 'order_detail') as $d) {
            $o['lines'][] = ['id_order_detail' => (int)$d->id, 'product_id' => (int)$d->product_id, 'product_attribute_id' => (int)$d->product_attribute_id,
                'product_name' => (string)$d->product_name, 'product_reference' => (string)$d->product_reference,
                'product_quantity' => (string)$d->product_quantity, 'unit_price_tax_excl' => (string)$d->unit_price_tax_excl,
                'unit_price_tax_incl' => (string)$d->unit_price_tax_incl, 'total_price_tax_excl' => (string)$d->total_price_tax_excl,
                'total_price_tax_incl' => (string)$d->total_price_tax_incl,
                'tax_rate' => self::rate((float)$d->total_price_tax_incl, (float)$d->total_price_tax_excl)];
        }
        $o['history'] = [];
        foreach (self::each($r['h']->order_histories, 'order_history') as $h) {
            $s = $this->stateById($states, (int)$h->id_order_state);
            $o['history'][] = ['date_add' => (string)$h->date_add, 'state' => $s['name'], 'color' => $s['color']];
        }
        return $o;
    }

    public function stats(): array
    {
        return $this->cached('stats', 300, function () {
            $since30 = date('Y-m-d', strtotime('-30 days'));
            $today = date('Y-m-d');
            $week = date('Y-m-d', strtotime('-7 days'));
            $res = ['today' => 0, 'week' => 0, 'month' => 0, 'month_total' => 0.0, 'per_shop' => []];
            $req = [];
            foreach ($this->shops() as $s) {
                $req[$s['id_shop']] = ['orders', ['id_shop' => $s['id_shop'], 'display' => '[id,date_add,total_paid_tax_incl]',
                    'filter' => ['date_add' => '[' . $since30 . ' 00:00:00,2999-12-31 23:59:59]'], 'date' => 1]];
            }
            $lists = $req ? $this->api->getMany($req) : [];
            foreach ($this->shops() as $s) {
                $row = ['name' => $s['name'], 'orders' => 0, 'total' => 0.0];
                foreach (self::each($lists[$s['id_shop']]->orders, 'order') as $o) {
                    $d = substr((string)$o->date_add, 0, 10);
                    $row['orders']++;
                    $row['total'] += (float)$o->total_paid_tax_incl;
                    $res['week'] += $d >= $week ? 1 : 0;
                    $res['today'] += $d >= $today ? 1 : 0;
                }
                $res['month'] += $row['orders'];
                $res['month_total'] += $row['total'];
                $res['per_shop'][] = $row;
            }
            return $res;
        });
    }

    /** Called after the panel changed something in the shop (order state) — the cached order lists are stale. */
    public function forgetOrders(): void
    {
        if ($this->cache !== null) {
            $this->cache->clear('orders:');
            $this->cache->clear('stats');
        }
        $this->memo = [];
    }

    // ------------------------------------------------------------------ internals

    /** @return array [filter params, extra params] for the list filters state / from / to (the shop = the context) */
    private function filters(array $f): array
    {
        $flt = [];
        $extra = [];
        if (!empty($f['state'])) {
            $flt['current_state'] = '[' . (int)$f['state'] . ']';
        } elseif (!empty($f['states'])) {   // several states (Expedícia)
            $flt['current_state'] = '[' . implode('|', array_map('intval', (array)$f['states'])) . ']';
        }
        $from = !empty($f['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['from']) ? $f['from'] : '';
        $to = !empty($f['to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['to']) ? $f['to'] : '';
        if ($from !== '' || $to !== '') {
            $flt['date_add'] = '[' . ($from ?: '1970-01-01') . ' 00:00:00,' . ($to ?: '2999-12-31') . ' 23:59:59]';
            $extra['date'] = 1;   // the Webservice filters dates only with date=1
        }
        return [$flt, $extra];
    }

    /** Shops whose orders are read: the filtered one or all. */
    private function scope(array $f): array
    {
        $all = array_column($this->shops(), 'id_shop');
        if (!empty($f['shop'])) {
            return in_array((int)$f['shop'], $all, true) ? [(int)$f['shop']] : [];
        }
        return $all;
    }

    /**
     * Orders matching the filters and the search text — the same as ShopOrders: reference, id, customer name / e-mail,
     * name / company of the INVOICE address (addresses → orders by id_address_invoice).
     * @return array id_order => id_shop, newest first
     */
    private function matchingIds(array $f, string $q): array
    {
        list($flt, $extra) = $this->filters($f);
        $shops = $this->scope($f);
        $ids = ['display' => '[id]'];
        $like = '%[' . $q . ']%';
        $words = preg_split('/\s+/', $q);
        $req = [];
        foreach ($shops as $s) {
            $ctx = $extra + ['id_shop' => $s] + $ids;
            if ($q === '') {
                $req['o|' . $s] = ['orders', $ctx + ['filter' => $flt]];
                continue;
            }
            $req['o|' . $s . '|ref'] = ['orders', $ctx + ['filter' => $flt + ['reference' => $like]]];
            if (ctype_digit($q)) {
                $req['o|' . $s . '|id'] = ['orders', $ctx + ['filter' => $flt + ['id' => '[' . (int)$q . ']']]];
            }
            foreach (['email', 'lastname', 'firstname'] as $field) {
                $req['c|' . $s . '|' . $field] = ['customers', ['id_shop' => $s] + $ids + ['filter' => [$field => $like]]];
            }
            if (count($words) >= 2) {
                $req['c|' . $s . '|name'] = ['customers', ['id_shop' => $s] + $ids
                    + ['filter' => ['firstname' => '%[' . $words[0] . ']%', 'lastname' => '%[' . end($words) . ']%']]];
            }
        }
        if ($q !== '' && $shops) {   // addresses are not limited by the shop context
            foreach (['company', 'lastname', 'firstname'] as $field) {
                $req['a|0|' . $field] = ['addresses', $ids + ['filter' => [$field => $like]]];
            }
            if (count($words) >= 2) {
                $req['a|0|name'] = ['addresses', $ids + ['filter' => ['firstname' => '%[' . $words[0] . ']%', 'lastname' => '%[' . end($words) . ']%']]];
            }
        }
        $found = [];
        $customers = [];
        $addresses = [];
        foreach ($req ? $this->api->getMany($req) : [] as $k => $res) {
            $key = explode('|', $k);
            if ($key[0] === 'o') {
                foreach (self::each($res->orders, 'order') as $o) {
                    $found[(int)$o->id] = (int)$key[1];
                }
            } elseif ($key[0] === 'c') {
                foreach (self::each($res->customers, 'customer') as $c) {
                    $customers[(int)$c->id] = true;
                }
            } else {
                foreach (self::each($res->addresses, 'address') as $a) {
                    $addresses[(int)$a->id] = true;
                }
            }
        }
        $req = [];
        foreach ($shops as $s) {
            foreach (['id_customer' => $customers, 'id_address_invoice' => $addresses] as $field => $set) {
                foreach (array_chunk(array_keys($set), 300) as $i => $chunk) {
                    $req['o|' . $s . '|' . $field . $i] = ['orders', $extra + ['id_shop' => $s] + $ids
                        + ['filter' => $flt + [$field => '[' . implode('|', $chunk) . ']']]];
                }
            }
        }
        foreach ($req ? $this->api->getMany($req) : [] as $k => $res) {
            foreach (self::each($res->orders, 'order') as $o) {
                $found[(int)$o->id] = (int)explode('|', $k)[1];
            }
        }
        krsort($found);
        return $found;
    }

    /** Order list rows (same keys as ShopOrders::search()). */
    private function listRows(array $orders): array
    {
        $cust = [];
        $addr = [];
        foreach ($orders as $o) {
            if ((int)$o->id_customer > 0) {
                $cust[(int)$o->id_shop][(int)$o->id_customer] = true;   // customers are listed per shop too
            }
            if ((int)$o->id_address_invoice > 0) {
                $addr[(int)$o->id_address_invoice] = true;
            }
        }
        $req = [];
        foreach ($cust as $shop => $ids) {
            $req['c|' . $shop] = ['customers', ['id_shop' => $shop, 'display' => '[id,firstname,lastname,email]',
                'filter' => ['id' => '[' . implode('|', array_keys($ids)) . ']']]];
        }
        if ($addr) {
            $req['a'] = ['addresses', ['display' => '[id,company]', 'filter' => ['id' => '[' . implode('|', array_keys($addr)) . ']']]];
        }
        $customers = [];
        $companies = [];
        foreach ($req ? $this->api->getMany($req) : [] as $k => $res) {
            if ($k === 'a') {
                foreach (self::each($res->addresses, 'address') as $a) {
                    $companies[(int)$a->id] = (string)$a->company;
                }
            } else {
                foreach (self::each($res->customers, 'customer') as $c) {
                    $customers[(int)$c->id] = $c;
                }
            }
        }
        $states = $this->allStates();
        $currencies = $this->currencies();
        $rows = [];
        foreach ($orders as $o) {
            $st = $this->stateById($states, (int)$o->current_state);
            $c = $customers[(int)$o->id_customer] ?? null;
            $rows[] = ['src' => $this->id, 'id_order' => (int)$o->id, 'reference' => (string)$o->reference, 'id_shop' => (int)$o->id_shop,
                'shop' => $this->shopName((int)$o->id_shop), 'date_add' => (string)$o->date_add,
                'total_paid_tax_incl' => (string)$o->total_paid_tax_incl, 'payment' => (string)$o->payment,
                'current_state' => (int)$o->current_state, 'state' => $st['name'], 'state_color' => $st['color'], 'state_paid' => $st['paid'],
                'firstname' => $c ? (string)$c->firstname : '', 'lastname' => $c ? (string)$c->lastname : '',
                'email' => $c ? (string)$c->email : '', 'company' => $companies[(int)$o->id_address_invoice] ?? '',
                'currency' => $currencies[(int)$o->id_currency] ?? ''];
        }
        usort($rows, function ($a, $b) { return $b['id_order'] - $a['id_order']; });
        return $rows;
    }

    private function address(\SimpleXMLElement $a): array
    {
        $country = $this->countries()[(int)$a->id_country] ?? ['iso' => '', 'name' => ''];
        $out = [];
        foreach (['company', 'firstname', 'lastname', 'address1', 'address2', 'postcode', 'city', 'phone', 'phone_mobile', 'vat_number', 'dni'] as $f) {
            $out[$f] = (string)$a->$f;
        }
        return $out + ['country' => $country['name'], 'country_iso' => $country['iso']];
    }

    private function langId(): int
    {
        return (int)$this->cached('lang', 86400, function () {
            $id = 0;
            $first = 0;
            foreach (self::each($this->api->get('languages', ['display' => '[id,iso_code]'])->languages, 'language') as $l) {
                $first = $first ?: (int)$l->id;
                if (strtolower((string)$l->iso_code) === $this->langIso) {
                    $id = (int)$l->id;
                }
            }
            return ['id' => $id ?: $first];
        })['id'];
    }

    /** All order states incl. deleted ones (old orders / history can point to them). */
    private function allStates(): array
    {
        return $this->cached('states', self::LISTS_TTL, function () {
            $lang = $this->langId();
            $out = [];
            foreach (self::each($this->api->get('order_states', ['display' => '[id,name,color,paid,shipped,delivery,deleted,send_email]'])->order_states, 'order_state') as $s) {
                $out[] = ['id_order_state' => (int)$s->id, 'name' => PrestaShopApi::text($s->name, $lang), 'color' => (string)$s->color,
                    'paid' => (int)$s->paid, 'shipped' => (int)$s->shipped, 'delivery' => (int)$s->delivery, 'deleted' => (int)$s->deleted,
                    'send_email' => (int)$s->send_email];
            }
            usort($out, function ($a, $b) { return strcmp(ShopStock::sortKey($a['name']), ShopStock::sortKey($b['name'])); });
            return $out;
        });
    }

    private function stateById(array $states, int $id): array
    {
        foreach ($states as $s) {
            if ($s['id_order_state'] === $id) {
                return $s;
            }
        }
        return ['name' => '#' . $id, 'color' => '', 'paid' => 0];
    }

    private function shopName(int $id): string
    {
        foreach ($this->shops() as $s) {
            if ($s['id_shop'] === $id) {
                return $s['name'];
            }
        }
        return '#' . $id;
    }

    /** @return array id => ISO code */
    private function currencies(): array
    {
        return $this->cached('currencies', self::LISTS_TTL, function () {
            $out = [];
            foreach (self::each($this->api->get('currencies', ['display' => '[id,iso_code]'])->currencies, 'currency') as $c) {
                $out[(int)$c->id] = (string)$c->iso_code;
            }
            return $out;
        });
    }

    /** @return array id => [iso, name] */
    private function countries(): array
    {
        return $this->cached('countries', 86400, function () {
            $lang = $this->langId();
            $out = [];
            foreach (self::each($this->api->get('countries', ['display' => '[id,iso_code,name]'])->countries, 'country') as $c) {
                $out[(int)$c->id] = ['iso' => (string)$c->iso_code, 'name' => PrestaShopApi::text($c->name, $lang)];
            }
            return $out;
        });
    }

    /** @return array id => name (also old versions of carriers — orders point to them) */
    private function carriers(): array
    {
        return $this->cached('carriers', self::LISTS_TTL, function () {
            $out = [];
            foreach (self::each($this->api->get('carriers', ['display' => '[id,name]'])->carriers, 'carrier') as $c) {
                $out[(int)$c->id] = (string)$c->name;
            }
            return $out;
        });
    }

    private function cached(string $name, int $ttl, callable $load): array
    {
        if (!isset($this->memo[$name])) {
            $this->memo[$name] = $this->cache !== null ? $this->cache->remember($name, $ttl, $load) : $load();
        }
        return $this->memo[$name];
    }

    /** Children of a list node, [] when the list is empty (PrestaShop then sends <orders/>). */
    private static function each(?\SimpleXMLElement $parent, string $child): array
    {
        $out = [];
        if ($parent !== null && isset($parent->$child)) {
            foreach ($parent->$child as $n) {
                $out[] = $n;
            }
        }
        return $out;
    }

    /** Search text safe inside a Webservice filter (its syntax uses [ ] | , %). */
    private static function clean(string $q): string
    {
        return mb_substr(trim(preg_replace('/[\[\]|,%]+/', ' ', $q)), 0, 60, 'UTF-8');
    }

    /** VAT rate of a line from its totals; whole percent when it is that (rounding of the totals). */
    public static function rate(float $incl, float $excl): float
    {
        if ($excl <= 0 || $incl <= $excl) {
            return 0.0;
        }
        $r = ($incl / $excl - 1) * 100;
        return abs($r - round($r)) < 0.3 ? (float)round($r) : round($r, 2);
    }
}
