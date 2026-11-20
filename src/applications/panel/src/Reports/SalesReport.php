<?php
namespace Panel\Reports;

use WebApplication\Database\Database;

/**
 * Sales and margin of the e-shops (docs/sprava-firmy-specifikacia.md, module A) — read only from the shop tables of
 * this database. Per order line: revenue after returns, purchase price FROM THE TIME OF THE ORDER (PrestaShop stores it
 * on the line), else the product's current purchase price (marked as an estimate), else none (marked); virtual
 * products (fees, services) need no purchase price. VAT basis per order: net for a VAT payer, gross for a non-payer
 * (callback — the company of the shop and its VAT status on the order date).
 */
class SalesReport
{
    const GROUPS = ['month' => 'Mesiac', 'shop' => 'E-shop', 'category' => 'Kategória', 'product' => 'Produkt'];

    /** @var Database */ private $db;
    /** @var string */ private $p;
    /** @var string */ private $langIso;

    public function __construct(Database $db, string $prefix = 'ps_', string $langIso = 'sk')
    {
        if (!preg_match('/^[A-Za-z0-9_]*$/', $prefix)) {
            throw new \InvalidArgumentException('Invalid shop table prefix');
        }
        $this->db = $db;
        $this->p = $prefix;
        $this->langIso = $langIso;
    }

    /** id_shop => name */
    public function shops(): array
    {
        $out = [];
        foreach ($this->db->query("SELECT id_shop, name FROM {$this->p}shop ORDER BY id_shop") as $s) {
            $out[(int)$s['id_shop']] = (string)$s['name'];
        }
        return $out;
    }

    /**
     * @param array $f from (Y-m-d), to (Y-m-d, inclusive), shops (int[], [] = all), group (key of GROUPS)
     * @param callable $net fn(int $idShop, string $date): bool|array — true = amounts without VAT (VAT payer); or
     *                      ['net' => bool, 'cost_factor' => float] (purchase prices × factor, e.g. 1.23 = + VAT for a non-payer)
     * @param callable|null $orderCosts fn(int[] $orderIds): array id_order => ['shipping', 'payment', 'packaging', 'total',
     *                      'no_shipping'] (OrderCosts::loader, module D) — adds order costs and the contribution margin
     * @return array summary, rows (grouped), months (for the chart), top (products by margin), missing (no purchase price),
     *               costs (bool: order costs computed)
     */
    public function compute(array $f, callable $net, ?callable $orderCosts = null): array
    {
        $group = isset(self::GROUPS[$f['group'] ?? '']) ? $f['group'] : 'month';
        $p = $this->p;
        $where = 'o.valid = 1 AND o.date_add >= ? AND o.date_add < ?';
        $args = [$f['from'] . ' 00:00:00', date('Y-m-d', strtotime($f['to'] . ' +1 day')) . ' 00:00:00'];
        $shops = array_values(array_filter(array_map('intval', (array)($f['shops'] ?? []))));
        if ($shops) {
            $where .= ' AND o.id_shop IN (' . implode(',', $shops) . ')';
        }
        $lines = $this->db->query("SELECT o.id_order, o.id_shop, o.reference, o.date_add, o.conversion_rate,
                o.total_discounts_tax_excl, o.total_discounts_tax_incl, o.total_shipping_tax_excl, o.total_shipping_tax_incl,
                od.product_id, od.product_attribute_id, od.product_name, od.product_reference,
                od.product_quantity, od.product_quantity_refunded, od.product_quantity_return,
                od.total_price_tax_excl, od.total_price_tax_incl, od.original_wholesale_price, od.purchase_supplier_price,
                pa.wholesale_price AS attr_wp, ps.wholesale_price AS shop_wp, pr.wholesale_price AS prod_wp,
                pr.id_category_default, pr.is_virtual
            FROM {$p}orders o
            JOIN {$p}order_detail od ON od.id_order = o.id_order
            LEFT JOIN {$p}product pr ON pr.id_product = od.product_id
            LEFT JOIN {$p}product_shop ps ON ps.id_product = od.product_id AND ps.id_shop = o.id_shop
            LEFT JOIN {$p}product_attribute pa ON pa.id_product_attribute = od.product_attribute_id AND od.product_attribute_id > 0
            WHERE $where ORDER BY o.date_add, o.id_order, od.id_order_detail", $args);

        $shopNames = $this->shops();
        $catNames = $this->categoryNames(array_unique(array_map(function ($l) { return (int)$l['id_category_default']; }, $lines)));
        $netCache = [];
        $costs = $orderCosts ? $orderCosts(array_values(array_unique(array_map(function ($l) { return (int)$l['id_order']; }, $lines)))) : [];
        $empty = ['orders' => 0, 'qty' => 0, 'revenue' => 0.0, 'discounts' => 0.0, 'shipping' => 0.0, 'cogs' => 0.0,
                  'estimated' => 0, 'missing' => 0, 'ids' => [], 'ship_cost' => 0.0, 'pay_cost' => 0.0, 'pack_cost' => 0.0, 'no_ship_rule' => 0];
        $sum = $empty;
        $rows = [];
        $months = [];
        $products = [];
        $missing = [];
        $orderSeen = [];
        foreach ($lines as $l) {
            $shop = (int)$l['id_shop'];
            $date = substr((string)$l['date_add'], 0, 10);
            $month = substr($date, 0, 7);
            $nk = $shop . '|' . $month;
            if (!isset($netCache[$nk])) {
                $r = $net($shop, $date);
                $netCache[$nk] = is_array($r) ? $r + ['net' => false, 'cost_factor' => 1.0] : ['net' => (bool)$r, 'cost_factor' => 1.0];
            }
            $isNet = (bool)$netCache[$nk]['net'];
            $factor = (float)$netCache[$nk]['cost_factor'];   // e.g. 1.23: a non-payer counts purchase prices with VAT
            $rate = (float)$l['conversion_rate'] > 0 ? (float)$l['conversion_rate'] : 1.0;   // amounts → default currency
            $qty = (int)$l['product_quantity'];
            $eff = max(0, $qty - (int)$l['product_quantity_refunded'] - (int)$l['product_quantity_return']);
            $lineTotal = (float)($isNet ? $l['total_price_tax_excl'] : $l['total_price_tax_incl']);
            $revenue = $qty > 0 ? $lineTotal * $eff / $qty / $rate : 0.0;
            $cost = 0.0;
            $state = 'ok';
            foreach (['original_wholesale_price', 'purchase_supplier_price'] as $k) {
                if ((float)$l[$k] > 0) {
                    $cost = (float)$l[$k];
                    break;
                }
            }
            if ($cost <= 0) {
                foreach (['attr_wp', 'shop_wp', 'prod_wp'] as $k) {
                    if ((float)$l[$k] > 0) {
                        $cost = (float)$l[$k];
                        $state = 'estimated';
                        break;
                    }
                }
            }
            if ($cost <= 0 && !(int)$l['is_virtual']) {
                $state = 'missing';
            }
            $cogs = $cost * $eff * $factor;
            $catId = (int)$l['id_category_default'];
            $key = $group === 'month' ? $month : ($group === 'shop' ? (string)$shop : ($group === 'category' ? (string)$catId
                : $l['product_id'] . '-' . $l['product_attribute_id']));
            $label = $group === 'month' ? $month : ($group === 'shop' ? ($shopNames[$shop] ?? '#' . $shop)
                : ($group === 'category' ? ($catNames[$catId] ?? '(bez kategórie)') : (string)$l['product_name']));
            $orderAdd = !isset($orderSeen[$l['id_order']]);
            $orderSeen[$l['id_order']] = true;
            $disc = $orderAdd ? (float)($isNet ? $l['total_discounts_tax_excl'] : $l['total_discounts_tax_incl']) / $rate : 0.0;
            $ship = $orderAdd ? (float)($isNet ? $l['total_shipping_tax_excl'] : $l['total_shipping_tax_incl']) / $rate : 0.0;
            $oc = $orderAdd ? ($costs[(int)$l['id_order']] ?? null) : null;
            foreach ([&$sum, &$rows[$key], &$months[$month]] as $i => &$acc) {
                if ($acc === null) {
                    $acc = $empty + ['key' => $i === 1 ? $key : $month, 'label' => $i === 1 ? $label : $month];
                }
                // discounts / shipping are per order: in product / category groups they stay in the total only
                $orderLevel = $i !== 1 || $group === 'month' || $group === 'shop';
                if (!isset($acc['ids'][$l['id_order']])) {
                    $acc['ids'][$l['id_order']] = true;
                    $acc['orders']++;
                }
                $acc['qty'] += $eff;
                $acc['revenue'] += $revenue;
                $acc['cogs'] += $cogs;
                $acc['discounts'] += $orderLevel ? $disc : 0.0;
                $acc['shipping'] += $orderLevel ? $ship : 0.0;
                if ($oc !== null && $orderLevel) {
                    $acc['ship_cost'] += $oc['shipping'];
                    $acc['pay_cost'] += $oc['payment'];
                    $acc['pack_cost'] += $oc['packaging'];
                    $acc['no_ship_rule'] += !empty($oc['no_shipping']) ? 1 : 0;
                }
                $acc['estimated'] += $state === 'estimated' ? 1 : 0;
                $acc['missing'] += $state === 'missing' ? 1 : 0;
            }
            unset($acc);
            $pk = $l['product_id'] . '-' . $l['product_attribute_id'];
            if (!isset($products[$pk])) {
                $products[$pk] = ['product_id' => (int)$l['product_id'], 'attribute_id' => (int)$l['product_attribute_id'],
                    'name' => (string)$l['product_name'], 'reference' => (string)$l['product_reference'], 'shop' => $shopNames[$shop] ?? '',
                    'qty' => 0, 'revenue' => 0.0, 'cogs' => 0.0, 'state' => $state];
            }
            $products[$pk]['qty'] += $eff;
            $products[$pk]['revenue'] += $revenue;
            $products[$pk]['cogs'] += $cogs;
            if ($state === 'missing') {
                $products[$pk]['state'] = 'missing';
                $missing[$pk] = true;
            }
        }
        $finish = function (array $a) {
            unset($a['ids']);
            $base = $a['revenue'] - $a['discounts'];
            $a['margin'] = $base - $a['cogs'];
            $a['margin_pct'] = $base > 0 ? $a['margin'] / $base * 100 : null;
            $a['aov'] = $a['orders'] > 0 ? ($a['revenue'] - $a['discounts'] + $a['shipping']) / $a['orders'] : 0.0;
            // contribution margin (module D) = gross margin + shipping paid by the customer − order costs
            $a['order_costs'] = $a['ship_cost'] + $a['pay_cost'] + $a['pack_cost'];
            $a['contribution'] = $a['margin'] + $a['shipping'] - $a['order_costs'];
            $a['contribution_pct'] = $base > 0 ? $a['contribution'] / $base * 100 : null;
            return $a;
        };
        $rows = array_map($finish, $rows);
        if ($group === 'month') {
            ksort($rows);
        } else {
            uasort($rows, function ($a, $b) { return $b['revenue'] <=> $a['revenue']; });
        }
        ksort($months);
        foreach ($products as &$pr) {
            $pr['margin'] = $pr['revenue'] - $pr['cogs'];
        }
        unset($pr);
        $top = array_values(array_filter($products, function ($x) { return $x['state'] !== 'missing'; }));
        usort($top, function ($a, $b) { return $b['margin'] <=> $a['margin']; });
        $miss = array_values(array_intersect_key($products, $missing));
        usort($miss, function ($a, $b) { return $b['revenue'] <=> $a['revenue']; });
        return ['summary' => $finish($sum), 'rows' => array_values($rows), 'months' => array_values(array_map($finish, $months)),
            'top' => array_slice($top, 0, 15), 'missing' => $miss, 'group' => $group, 'costs' => $orderCosts !== null];
    }

    /**
     * Products (and combinations) of the given shops with their purchase price — for completing missing prices
     * (module B). Virtual products (fees, services) are left out. Inactive products stay in (a sold unique item is
     * switched off after the sale). The effective price = combination's, else the shop's, else the product's.
     * @param string $filter missing_sold (last 12 months) | missing_sold_all | missing | all
     * @return array of rows: product_id, attribute_id, name, combination, reference, price, shops (int[]), sold_qty, last_sold
     */
    public function purchasePriceList(array $shops, string $filter, string $q = ''): array
    {
        $shops = array_values(array_filter(array_map('intval', $shops)));
        if (!$shops) {
            return [];
        }
        $p = $this->p;
        $in = implode(',', $shops);
        $lang = (int)$this->db->fetchValue("SELECT id_lang FROM {$p}lang WHERE iso_code = ?", [$this->langIso]);
        $rows = $this->db->query("SELECT pr.id_product, COALESCE(pa.id_product_attribute, 0) AS aid, MAX(pl.name) AS name,
                pr.reference AS p_ref, pa.reference AS a_ref, pr.wholesale_price AS p_wp, pa.wholesale_price AS a_wp,
                MAX(ps.wholesale_price) AS s_wp, GROUP_CONCAT(DISTINCT ps.id_shop) AS shops
            FROM {$p}product pr
            JOIN {$p}product_shop ps ON ps.id_product = pr.id_product AND ps.id_shop IN ($in)
            LEFT JOIN {$p}product_attribute pa ON pa.id_product = pr.id_product
            LEFT JOIN {$p}product_lang pl ON pl.id_product = pr.id_product AND pl.id_shop = ps.id_shop AND pl.id_lang = ?
            WHERE pr.is_virtual = 0
            GROUP BY pr.id_product, aid", [$lang]);
        $sold = [];
        foreach ($this->db->query("SELECT od.product_id, od.product_attribute_id,
                    SUM(od.product_quantity - od.product_quantity_refunded - od.product_quantity_return) AS qty, MAX(o.date_add) AS last
                FROM {$p}order_detail od JOIN {$p}orders o ON o.id_order = od.id_order
                WHERE o.valid = 1 AND o.id_shop IN ($in) GROUP BY od.product_id, od.product_attribute_id") as $s) {
            $sold[$s['product_id'] . '-' . $s['product_attribute_id']] = $s;
        }
        $combos = [];
        foreach ($this->db->query("SELECT pac.id_product_attribute, GROUP_CONCAT(al.name ORDER BY al.name SEPARATOR ', ') AS label
                FROM {$p}product_attribute_combination pac JOIN {$p}attribute_lang al ON al.id_attribute = pac.id_attribute AND al.id_lang = ?
                GROUP BY pac.id_product_attribute", [$lang]) as $c) {
            $combos[(int)$c['id_product_attribute']] = (string)$c['label'];
        }
        $q = mb_strtolower(trim($q), 'UTF-8');
        $out = [];
        foreach ($rows as $r) {
            $aid = (int)$r['aid'];
            $price = $aid && (float)$r['a_wp'] > 0 ? (float)$r['a_wp'] : ((float)$r['s_wp'] > 0 ? (float)$r['s_wp'] : (float)$r['p_wp']);
            $s = $sold[$r['id_product'] . '-' . $aid] ?? null;
            $row = ['product_id' => (int)$r['id_product'], 'attribute_id' => $aid, 'name' => (string)$r['name'],
                'combination' => $aid ? ($combos[$aid] ?? '#' . $aid) : '', 'reference' => (string)($aid && $r['a_ref'] ? $r['a_ref'] : $r['p_ref']),
                'price' => $price, 'shops' => array_map('intval', explode(',', (string)$r['shops'])),
                'sold_qty' => $s ? (int)$s['qty'] : 0, 'last_sold' => $s ? (string)$s['last'] : ''];
            if ($filter !== 'all' && $price > 0) {
                continue;
            }
            if (($filter === 'missing_sold' || $filter === 'missing_sold_all') && $row['sold_qty'] <= 0) {
                continue;
            }
            if ($filter === 'missing_sold' && $row['last_sold'] < date('Y-m-d', strtotime('-12 months'))) {
                continue;   // default: sold in the last 12 months (= the default period of the sales report)
            }
            if ($q !== '' && mb_strpos(mb_strtolower($row['name'] . ' ' . $row['reference'] . ' ' . $row['combination'], 'UTF-8'), $q) === false) {
                continue;
            }
            $out[] = $row;
        }
        usort($out, function ($a, $b) {
            return [$b['sold_qty'] > 0, $b['last_sold'], $a['name']] <=> [$a['sold_qty'] > 0, $a['last_sold'], $b['name']];
        });
        return $out;
    }

    /** Shops (ids) a product belongs to — a new purchase price is written in each of them. */
    public function productShops(int $productId): array
    {
        return array_map('intval', array_column($this->db->query("SELECT id_shop FROM {$this->p}product_shop WHERE id_product = ?", [$productId]), 'id_shop'));
    }

    /** id_category => name in the panel language (any shop's text). */
    private function categoryNames(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) {
            return [];
        }
        $out = [];
        foreach ($this->db->query("SELECT cl.id_category, cl.name FROM {$this->p}category_lang cl JOIN {$this->p}lang l ON l.id_lang = cl.id_lang
                WHERE l.iso_code = ? AND cl.id_category IN (" . implode(',', $ids) . ") ORDER BY cl.id_shop", [$this->langIso]) as $c) {
            $out[(int)$c['id_category']] = $out[(int)$c['id_category']] ?? (string)$c['name'];
        }
        return $out;
    }
}
