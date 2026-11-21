<?php
namespace Panel\Shop;

/**
 * Editing the lines of an order IN THE SHOP through the standard Webservice (owner 2026-11-21: "len Webservice" — no
 * module in the shops): quantities, unit prices, removed and added products, carrier and shipping price.
 * The Webservice stores order_details / orders / order_carriers as they are sent and recalculates nothing, so the panel
 * does what the PrestaShop admin does when an order is edited (AdminOrdersController 1.7.6, ajaxProcessEditProductOnOrder):
 * - order_details: PUT (quantity, unit and total prices), POST (new line — a copy of an existing line of the same order,
 *   so every field of the shop's version is filled, with the product's data) and DELETE;
 * - orders PUT: the totals change by the difference (products, shipping, paid — with / without VAT) + the carrier;
 *   the order's total discount can be set (planDiscount — totals only, no voucher record); wrapping and total_paid_real
 *   (what was paid) stay — like in the shop admin;
 * - order_carriers PUT: carrier, shipping price, weight of the products;
 * - stock (optional): stock_availables of the products change by the difference (ShopStock) — the admin does it too.
 * Known limits (accepted by the owner, Webservice only): the VAT rows of the lines (order_detail_tax — used only by
 * PrestaShop's own invoices and credit slips, which the shops do not issue) are not recalculated and new lines have none;
 * percentage discounts are not recalculated; the reserved quantity of the stock is recomputed by PrestaShop at the next
 * order state change. The hidden deprecated fields tax_rate / tax_name are kept when the panel knows them ($keep).
 * Safety: the order must have the reference the panel shows and exactly the lines / total the form was made from
 * (otherwise nothing is written); every write is checked in the shop's answer; problems are reported, never hidden.
 */
class ShopOrderLines extends ShopOrderEdit
{
    /** Fields of a copied line reset to 0 for a new product (refunds, returns, discounts, downloads …). */
    const RESET = '/refund|return|reinject|reduction|discount|ecotax|download_nb|quantity_in_stock/';

    const CHANGED = 'Objednávka sa v e-shope medzitým zmenila (položky, súčet alebo doprava) — načítajte úpravu znova. Nič sa nezmenilo.';

    /** One order_detail of the Webservice → line array used by plan(). */
    public static function line(\SimpleXMLElement $d): array
    {
        return ['id' => (int)$d->id, 'product_id' => (int)$d->product_id, 'attribute_id' => (int)$d->product_attribute_id,
            'name' => (string)$d->product_name, 'reference' => (string)$d->product_reference,
            'qty' => (int)$d->product_quantity, 'reinjected' => (int)$d->product_quantity_reinjected,
            'unit_incl' => (float)$d->unit_price_tax_incl, 'unit_excl' => (float)$d->unit_price_tax_excl,
            'total_incl' => (float)$d->total_price_tax_incl, 'total_excl' => (float)$d->total_price_tax_excl,
            'weight' => (float)$d->product_weight];
    }

    /** VAT rate of a line from its prices (units are stored with 6 decimals; a free line → from its totals). */
    public static function lineRate(array $l): float
    {
        return $l['unit_excl'] > 0 ? ShopOrdersApi::rate($l['unit_incl'], $l['unit_excl']) : ShopOrdersApi::rate($l['total_incl'], $l['total_excl']);
    }

    /**
     * The order as the shop has it now: order (<order>), lines (id => line()), order_carrier id (0 = none).
     */
    public function current(int $orderId, int $shopId, string $reference): array
    {
        $ctx = ['id_shop' => $shopId];
        $doc = $this->order($orderId, $shopId, $reference);
        $lines = [];
        $list = $this->api->get('order_details', $ctx + ['display' => 'full', 'filter' => ['id_order' => '[' . $orderId . ']'], 'sort' => '[id_ASC]']);
        foreach ($list->order_details->order_detail ?? [] as $d) {
            $lines[(int)$d->id] = self::line($d);
        }
        $oc = $this->api->get('order_carriers', $ctx + ['display' => '[id]', 'filter' => ['id_order' => '[' . $orderId . ']'], 'sort' => '[id_DESC]']);
        $ocId = isset($oc->order_carriers->order_carrier[0]) ? (int)$oc->order_carriers->order_carrier[0]->id : 0;
        return ['order' => $doc->order, 'lines' => $lines, 'order_carrier_id' => $ocId];
    }

    /** Carriers of the shop that are not deleted: id => ['name', 'active']. */
    public function carriers(int $shopId): array
    {
        $out = [];
        $list = $this->api->get('carriers', ['id_shop' => $shopId, 'display' => '[id,name,active,deleted]', 'filter' => ['deleted' => '[0]'], 'sort' => '[id_ASC]']);
        foreach ($list->carriers->carrier ?? [] as $c) {
            $name = (string)$c->name;
            $out[(int)$c->id] = ['name' => $name === '0' ? 'Vyzdvihnutie v obchode' : $name, 'active' => (int)$c->active === 1];
        }
        return $out;
    }

    /**
     * Data of a product (or its combination) for a new order line, with the shop's current price with / without VAT
     * (Webservice "price" fields = PrestaShop's own price calculation incl. sales) for the order's country and currency.
     */
    public function product(int $shopId, int $langId, int $countryId, int $currencyId, int $productId, int $attributeId): array
    {
        $ctx = ['id_shop' => $shopId];
        $params = $ctx;
        foreach (['panel_incl' => 1, 'panel_excl' => 0] as $k => $tax) {
            $params['price[' . $k . '][use_tax]'] = $tax;
            $params['price[' . $k . '][product_attribute]'] = $attributeId;
            $params['price[' . $k . '][decimals]'] = 6;
            if ($countryId) {
                $params['price[' . $k . '][country]'] = $countryId;
            }
            if ($currencyId) {
                $params['price[' . $k . '][currency]'] = $currencyId;
            }
        }
        try {
            $p = $this->api->get('products/' . $productId, $params)->product;
        } catch (\RuntimeException $e) {
            throw new \RuntimeException('Produkt ' . $productId . ' sa v e-shope nenašiel (' . $e->getMessage() . ')', $e->getCode());
        }
        $d = ['product_id' => $productId, 'attribute_id' => $attributeId, 'name' => PrestaShopApi::text($p->name, $langId),
            'reference' => (string)$p->reference, 'supplier_reference' => (string)$p->supplier_reference,
            'ean13' => (string)$p->ean13, 'isbn' => (string)$p->isbn, 'upc' => (string)$p->upc, 'mpn' => (string)($p->mpn ?? ''),
            'weight' => (float)$p->weight, 'wholesale_price' => (float)$p->wholesale_price, 'base_price' => (float)$p->price,
            'id_tax_rules_group' => (int)$p->id_tax_rules_group, 'incl' => (float)$p->panel_incl, 'excl' => (float)$p->panel_excl];
        if ($attributeId) {
            $c = $this->api->get('combinations/' . $attributeId, $ctx)->combination;
            if ((int)$c->id_product !== $productId) {
                throw new \RuntimeException('Kombinácia ' . $attributeId . ' nepatrí produktu ' . $productId . '.');
            }
            foreach (['reference', 'supplier_reference', 'ean13', 'isbn', 'upc', 'mpn'] as $f) {
                if (isset($c->$f) && (string)$c->$f !== '') {
                    $d[$f] = (string)$c->$f;
                }
            }
            $d['weight'] += (float)$c->weight;
            $d['base_price'] += (float)$c->price;
            if ((float)$c->wholesale_price > 0) {
                $d['wholesale_price'] = (float)$c->wholesale_price;
            }
            $ids = [];
            foreach ($c->associations->product_option_values->product_option_value ?? [] as $v) {
                $ids[] = (int)$v->id;
            }
            if ($ids) {   // "Name - Veľkosť : M, Farba : čierna" like PrestaShop names the line
                $values = $this->api->get('product_option_values', ['display' => '[id,id_attribute_group,name]', 'filter' => ['id' => '[' . implode('|', $ids) . ']']]);
                $groupIds = [];
                foreach ($values->product_option_values->product_option_value ?? [] as $v) {
                    $groupIds[] = (int)$v->id_attribute_group;
                }
                $groups = [];
                $g = $this->api->get('product_options', ['display' => '[id,public_name]', 'filter' => ['id' => '[' . implode('|', array_unique($groupIds)) . ']']]);
                foreach ($g->product_options->product_option ?? [] as $o) {
                    $groups[(int)$o->id] = PrestaShopApi::text($o->public_name, $langId);
                }
                $parts = [];
                foreach ($values->product_option_values->product_option_value ?? [] as $v) {
                    $parts[] = ($groups[(int)$v->id_attribute_group] ?? '') . ' : ' . PrestaShopApi::text($v->name, $langId);
                }
                $d['name'] .= ' - ' . implode(', ', $parts);
            }
        }
        return $d;
    }

    /**
     * What to write for the requested changes — pure calculation, no shop calls.
     * @param array $lines current lines, id => line()
     * @param array $req 'lines' => id => ['qty' => int (0 = remove), 'price' => float|null (unit price with VAT, null = keep)],
     *                   'add' => list of ['product' => product(), 'qty' => int, 'price' => float|null (null = the shop's price)]
     * @return array list of ['op' => update|delete|add, 'id', 'line' (new line values), 'd_incl', 'd_excl', 'stock' (delta), 'text']
     */
    public static function plan(array $lines, array $req): array
    {
        $ops = [];
        foreach ($lines as $id => $l) {
            if (!isset($req['lines'][$id])) {
                continue;
            }
            $qty = (int)$req['lines'][$id]['qty'];
            $price = $req['lines'][$id]['price'] ?? null;
            if ($qty <= 0) {
                $ops[] = ['op' => 'delete', 'id' => $id, 'line' => $l, 'd_incl' => -$l['total_incl'], 'd_excl' => -$l['total_excl'],
                    'stock' => max(0, $l['qty'] - $l['reinjected']), 'text' => 'odstránené: ' . $l['name'] . ' (' . $l['qty'] . ' ks)'];
                continue;
            }
            $priceChanged = $price !== null && abs((float)$price - round($l['unit_incl'], 2)) >= 0.005;
            if ($qty === $l['qty'] && !$priceChanged) {
                continue;
            }
            $n = $l;
            $n['qty'] = $qty;
            if ($priceChanged) {
                $n['unit_incl'] = round((float)$price, 2);
                $n['unit_excl'] = round($n['unit_incl'] / (1 + self::lineRate($l) / 100), 6);
            }
            $n['total_incl'] = round($n['unit_incl'] * $qty, 2);
            $n['total_excl'] = round($n['unit_excl'] * $qty, 2);
            $text = [];
            if ($qty !== $l['qty']) {
                $text[] = $l['qty'] . ' → ' . $qty . ' ks';
            }
            if ($priceChanged) {
                $text[] = 'cena/ks ' . self::money($l['unit_incl']) . ' → ' . self::money($n['unit_incl']);
            }
            $ops[] = ['op' => 'update', 'id' => $id, 'line' => $n, 'price_changed' => $priceChanged,
                'd_incl' => $n['total_incl'] - $l['total_incl'], 'd_excl' => $n['total_excl'] - $l['total_excl'],
                'stock' => $l['qty'] - $qty, 'text' => $l['name'] . ': ' . implode(', ', $text)];
        }
        foreach ($req['add'] ?? [] as $a) {
            $p = $a['product'];
            $qty = (int)$a['qty'];
            if ($qty <= 0) {
                continue;
            }
            foreach ($lines as $id => $l) {
                $kept = !isset($req['lines'][$id]) || (int)$req['lines'][$id]['qty'] > 0;
                if ($kept && $l['product_id'] === $p['product_id'] && $l['attribute_id'] === $p['attribute_id']) {
                    throw new \InvalidArgumentException($l['name'] . ' už v objednávke je — zmeňte jeho množstvo.');
                }
            }
            $rate = ShopOrdersApi::rate($p['incl'], $p['excl']);
            if (($a['price'] ?? null) !== null) {
                $incl = round((float)$a['price'], 2);
                $excl = round($incl / (1 + $rate / 100), 6);
            } else {
                $incl = round($p['incl'], 6);
                $excl = round($p['excl'], 6);
            }
            $n = ['id' => 0, 'product_id' => $p['product_id'], 'attribute_id' => $p['attribute_id'], 'name' => $p['name'],
                'reference' => $p['reference'], 'qty' => $qty, 'reinjected' => 0, 'unit_incl' => $incl, 'unit_excl' => $excl,
                'total_incl' => round($incl * $qty, 2), 'total_excl' => round($excl * $qty, 2), 'weight' => $p['weight']];
            $ops[] = ['op' => 'add', 'id' => 0, 'line' => $n, 'product' => $p, 'd_incl' => $n['total_incl'], 'd_excl' => $n['total_excl'],
                'stock' => -$qty, 'text' => 'pridané: ' . $p['name'] . ' (' . $qty . ' ks × ' . self::money($incl) . ')'];
        }
        return $ops;
    }

    /**
     * Change of the carrier / shipping price — pure calculation. @param array $o order fields (id_carrier,
     * total_shipping_tax_incl, total_shipping_tax_excl, carrier_tax_rate) @param array|null $req ['carrier_id', 'price' (with VAT)]
     * @return array|null ['carrier_id', 'incl', 'excl', 'd_incl', 'd_excl'] or null = nothing changes
     */
    public static function planShipping(array $o, ?array $req): ?array
    {
        if ($req === null) {
            return null;
        }
        $oldIncl = (float)$o['total_shipping_tax_incl'];
        $oldExcl = (float)$o['total_shipping_tax_excl'];
        $carrier = (int)$req['carrier_id'] ?: (int)$o['id_carrier'];
        $priceChanged = abs((float)$req['price'] - round($oldIncl, 2)) >= 0.005;
        if ($carrier === (int)$o['id_carrier'] && !$priceChanged) {
            return null;
        }
        $incl = $priceChanged ? round((float)$req['price'], 2) : $oldIncl;
        $excl = $priceChanged ? round($incl / (1 + (float)$o['carrier_tax_rate'] / 100), 2) : $oldExcl;
        return ['carrier_id' => $carrier, 'incl' => $incl, 'excl' => $excl, 'd_incl' => $incl - $oldIncl, 'd_excl' => $excl - $oldExcl,
            'carrier_changed' => $carrier !== (int)$o['id_carrier'], 'price_changed' => $priceChanged];
    }

    /**
     * New order totals after the differences (products, shipping, discount) — like the shop admin; payments stay.
     * total paid = products + shipping + wrapping − discounts (PrestaShop), so a bigger discount lowers it.
     */
    public static function totals(array $o, float $dProdIncl, float $dProdExcl, float $dShipIncl, float $dShipExcl,
                                  float $dDiscIncl = 0.0, float $dDiscExcl = 0.0): array
    {
        $add = function ($field, $d) use ($o) { return round((float)($o[$field] ?? 0) + $d, 2); };
        return ['total_products' => $add('total_products', $dProdExcl), 'total_products_wt' => $add('total_products_wt', $dProdIncl),
            'total_shipping' => $add('total_shipping', $dShipIncl), 'total_shipping_tax_incl' => $add('total_shipping_tax_incl', $dShipIncl),
            'total_shipping_tax_excl' => $add('total_shipping_tax_excl', $dShipExcl),
            'total_discounts' => $add('total_discounts', $dDiscIncl), 'total_discounts_tax_incl' => $add('total_discounts_tax_incl', $dDiscIncl),
            'total_discounts_tax_excl' => $add('total_discounts_tax_excl', $dDiscExcl),
            'total_paid' => $add('total_paid', $dProdIncl + $dShipIncl - $dDiscIncl),
            'total_paid_tax_incl' => $add('total_paid_tax_incl', $dProdIncl + $dShipIncl - $dDiscIncl),
            'total_paid_tax_excl' => $add('total_paid_tax_excl', $dProdExcl + $dShipExcl - $dDiscExcl)];
    }

    /**
     * New total discount of the order (owner 2026-11-21 "urob aj tú zľavu k objednávke") — pure calculation. The Webservice
     * has no order_cart_rules, so the order's discount TOTALS change (PrestaShop shows them in the order's summary and
     * the panel's invoice takes them), without a named voucher. The amount replaces the current discount.
     * @param float $prodIncl, $prodExcl products of the order after the line changes (the base of a percentage)
     * @param array|null $req ['mode' => amount (with VAT) | percent (of the products with VAT), 'value']
     * @return array|null ['incl', 'excl', 'd_incl', 'd_excl', 'text'] or null = no change
     */
    public static function planDiscount(array $o, float $prodIncl, float $prodExcl, ?array $req): ?array
    {
        if ($req === null) {
            return null;
        }
        $oldIncl = (float)($o['total_discounts_tax_incl'] ?? 0);
        $oldExcl = (float)($o['total_discounts_tax_excl'] ?? 0);
        $v = (float)$req['value'];
        $percent = ($req['mode'] ?? '') === 'percent';
        $incl = $percent ? round($prodIncl * $v / 100, 2) : round($v, 2);
        if (abs($incl - round($oldIncl, 2)) < 0.005) {
            return null;
        }
        // without VAT in the ratio of the products (one rate in the order = exactly that rate)
        $excl = $prodIncl > 0 ? round($incl * $prodExcl / $prodIncl, 2) : $incl;
        return ['incl' => $incl, 'excl' => $excl, 'd_incl' => $incl - $oldIncl, 'd_excl' => $excl - $oldExcl,
            'text' => 'zľava ' . self::money($oldIncl) . ' → ' . self::money($incl) . ($percent ? ' (' . rtrim(rtrim(number_format($v, 2, ',', ''), '0'), ',') . ' % z tovaru)' : '')];
    }

    /**
     * Applies the changes in the shop.
     * @param array $req as plan() (add: ['product_id', 'attribute_id', 'qty', 'price']) + 'shipping' => ['carrier_id', 'price']|null
     * @param array $expect what the form was made from: 'lines' => id => ['qty', 'price' (unit with VAT, 2 decimals)],
     *                      'total' (total_paid_tax_incl), 'carrier' (id), 'shipping' (with VAT)
     * @param array $keep id => ['tax_rate', 'tax_name'] — hidden fields the Webservice does not show (a PUT without them clears them)
     * @param ShopStock|null $stock changes the stock by the difference (null = stock stays)
     * @return array ['done' => string[], 'problems' => string[], 'total' => float|null new total with VAT]
     */
    public function apply(int $orderId, int $shopId, string $reference, array $req, array $expect, array $keep = [], ?ShopStock $stock = null): array
    {
        $ctx = ['id_shop' => $shopId];
        $cur = $this->current($orderId, $shopId, $reference);
        $o = [];
        foreach ($cur['order']->children() as $k => $v) {
            if ($v->count() === 0) {
                $o[$k] = (string)$v;
            }
        }
        $lines = $cur['lines'];
        // the form must have been made from what the shop has now
        $ids = array_keys($lines);
        $formIds = array_map('intval', array_keys($expect['lines'] ?? []));
        sort($ids);
        sort($formIds);
        $same = $ids === $formIds
            && abs(round((float)$o['total_paid_tax_incl'], 2) - (float)($expect['total'] ?? -1)) < 0.005
            && (int)$o['id_carrier'] === (int)($expect['carrier'] ?? -1)
            && abs(round((float)$o['total_shipping_tax_incl'], 2) - (float)($expect['shipping'] ?? -1)) < 0.005
            && (!isset($expect['discount']) || abs(round((float)($o['total_discounts_tax_incl'] ?? 0), 2) - (float)$expect['discount']) < 0.005);
        foreach ($same ? $lines : [] as $id => $l) {
            $e = $expect['lines'][$id];
            if ($l['qty'] !== (int)$e['qty'] || abs(round($l['unit_incl'], 2) - (float)$e['price']) >= 0.005) {
                $same = false;
            }
        }
        if (!$same) {
            throw new \RuntimeException(self::CHANGED);
        }
        $adds = [];
        if (!empty($req['add'])) {
            $country = 0;
            try {
                $a = $this->api->get('addresses/' . (int)$o['id_address_delivery'], $ctx);
                $country = (int)$a->address->id_country;
            } catch (\RuntimeException $e) {
                // without the address the price is for the shop's default country
            }
            foreach ($req['add'] as $a) {
                $adds[] = ['product' => $this->product($shopId, (int)$o['id_lang'], $country, (int)$o['id_currency'], (int)$a['product_id'], (int)$a['attribute_id']),
                    'qty' => (int)$a['qty'], 'price' => $a['price']];
            }
        }
        $ops = self::plan($lines, ['lines' => $req['lines'] ?? [], 'add' => $adds]);
        $ship = self::planShipping($o, $req['shipping'] ?? null);
        $allIncl = array_sum(array_column($ops, 'd_incl'));
        $allExcl = array_sum(array_column($ops, 'd_excl'));
        $discAll = self::planDiscount($o, (float)$o['total_products_wt'] + $allIncl, (float)$o['total_products'] + $allExcl, $req['discount'] ?? null);
        if (!$ops && !$ship && !$discAll) {
            return ['done' => [], 'problems' => [], 'total' => null];
        }
        $left = count($lines) - count(array_filter($ops, function ($op) { return $op['op'] === 'delete'; }))
            + count(array_filter($ops, function ($op) { return $op['op'] === 'add'; }));
        if ($left < 1) {
            throw new \InvalidArgumentException('V objednávke musí ostať aspoň jeden produkt — ak sa celá ruší, zmeňte jej stav na Zrušená.');
        }
        $all = self::totals($o, $allIncl, $allExcl, $ship ? $ship['d_incl'] : 0.0, $ship ? $ship['d_excl'] : 0.0,
            $discAll ? $discAll['d_incl'] : 0.0, $discAll ? $discAll['d_excl'] : 0.0);
        foreach ($all as $f => $v) {
            if ($v < 0) {
                throw new \InvalidArgumentException('Súčet objednávky by bol záporný (' . $f . ' = ' . self::money($v) . ') — skontrolujte zľavy a ceny.');
            }
        }

        // 1. lines
        $done = $problems = $stockMoves = [];
        $dIncl = $dExcl = 0.0;
        $deleted = array_column(array_filter($ops, function ($op) { return $op['op'] === 'delete'; }), 'id');
        $template = (int)current(array_diff(array_keys($lines), $deleted));   // a line that stays (a copy for new lines)
        foreach ($ops as $op) {
            try {
                if ($op['op'] === 'delete') {
                    $this->api->delete('order_details', $op['id'], $ctx);
                    unset($lines[$op['id']]);
                } elseif ($op['op'] === 'update') {
                    $this->putLine($op, $ctx, $keep[$op['id']] ?? []);
                    $lines[$op['id']] = $op['line'];
                } else {
                    $newId = $this->addLine($op, $template, $ctx);
                    $lines[$newId] = ['id' => $newId] + $op['line'];
                }
            } catch (\Throwable $e) {
                $problems[] = ucfirst($op['text']) . ' — e-shop to neuložil: ' . $e->getMessage();
                continue;
            }
            $dIncl += $op['d_incl'];
            $dExcl += $op['d_excl'];
            $done[] = $op['text'];
            if ($op['stock'] !== 0) {
                $stockMoves[] = $op;
            }
        }

        // 2. carrier: shipping price, weight of the products
        $shipOk = false;
        if ($ship || ($done && $cur['order_carrier_id'])) {
            try {
                if (!$cur['order_carrier_id']) {
                    throw new \RuntimeException('objednávka nemá v e-shope dopravcu (order_carrier)');
                }
                $doc = $this->api->get('order_carriers/' . $cur['order_carrier_id'], $ctx);
                $oc = $doc->order_carrier;
                $oc->weight = sprintf('%.6F', array_sum(array_map(function ($l) { return $l['weight'] * $l['qty']; }, $lines)));
                if ($ship) {
                    $oc->id_carrier = (string)$ship['carrier_id'];
                    $oc->shipping_cost_tax_incl = sprintf('%.6F', $ship['incl']);
                    $oc->shipping_cost_tax_excl = sprintf('%.6F', $ship['excl']);
                }
                $this->strip($oc, 'order_carriers', ['associations']);
                $res = $this->api->put('order_carriers', $cur['order_carrier_id'], $doc, $ctx);
                if ($ship && (int)$res->order_carrier->id_carrier !== $ship['carrier_id']) {
                    throw new \RuntimeException('e-shop dopravcu nezmenil');
                }
                $shipOk = (bool)$ship;
            } catch (\Throwable $e) {
                if ($ship) {
                    $problems[] = 'Doprava — e-shop ju nezmenil: ' . $e->getMessage();
                }   // weight only: not worth a message
            }
        }
        if ($shipOk) {
            $done[] = 'doprava: ' . ($ship['carrier_changed'] ? 'iný dopravca, ' : '') . self::money($o['total_shipping_tax_incl']) . ' → ' . self::money($ship['incl']);
        }

        // 3. order totals (only what really changed in the shop) + the discount (a percentage of the products as they are now)
        $disc = self::planDiscount($o, (float)$o['total_products_wt'] + $dIncl, (float)$o['total_products'] + $dExcl, $req['discount'] ?? null);
        if ($disc) {
            $done[] = $disc['text'];
        }
        $total = null;
        if ($done) {
            $new = self::totals($o, $dIncl, $dExcl, $shipOk ? $ship['d_incl'] : 0.0, $shipOk ? $ship['d_excl'] : 0.0,
                $disc ? $disc['d_incl'] : 0.0, $disc ? $disc['d_excl'] : 0.0);
            try {
                $doc = $this->order($orderId, $shopId, $reference);   // fresh copy (date_upd)
                foreach ($new as $f => $v) {
                    $doc->order->$f = sprintf('%.6F', $v);
                }
                if ($shipOk) {
                    $doc->order->id_carrier = (string)$ship['carrier_id'];
                }
                $this->strip($doc->order, 'orders', ['associations']);
                $res = $this->api->put('orders', $orderId, $doc, $ctx);
                if (abs((float)$res->order->total_paid_tax_incl - $new['total_paid_tax_incl']) >= 0.005) {
                    throw new \RuntimeException('e-shop súčet neprijal (má ' . self::money((float)$res->order->total_paid_tax_incl) . ')');
                }
                $total = $new['total_paid_tax_incl'];
            } catch (\Throwable $e) {
                $problems[] = 'Položky sú zmenené, ale súčty objednávky v e-shope NIE (' . $e->getMessage() . ') — opravte ich v administrácii e-shopu.';
            }
        }

        // 4. stock by the difference
        foreach ($stock ? $stockMoves : [] as $op) {
            $l = $op['line'];
            try {
                $q = $stock->addQuantity($shopId, $l['product_id'], $l['attribute_id'], $op['stock']);
                $done[] = 'sklad ' . $l['name'] . ' ' . ($op['stock'] > 0 ? '+' : '') . $op['stock'] . ' (teraz ' . $q . ')';
            } catch (\Throwable $e) {
                $problems[] = 'Sklad ' . $l['name'] . ' (' . ($op['stock'] > 0 ? '+' : '') . $op['stock'] . '): ' . $e->getMessage();
            }
        }
        return ['done' => $done, 'problems' => $problems, 'total' => $total];
    }

    /** PUT of a changed line (fresh copy from the shop, hidden fields kept). */
    private function putLine(array $op, array $ctx, array $keep): void
    {
        $n = $op['line'];
        $doc = $this->api->get('order_details/' . $op['id'], $ctx);
        $d = $doc->order_detail;
        $d->product_quantity = (string)$n['qty'];
        $d->unit_price_tax_incl = sprintf('%.6F', $n['unit_incl']);
        $d->unit_price_tax_excl = sprintf('%.6F', $n['unit_excl']);
        $d->total_price_tax_incl = sprintf('%.6F', $n['total_incl']);
        $d->total_price_tax_excl = sprintf('%.6F', $n['total_excl']);
        if (!empty($op['price_changed'])) {   // the new price is final — like the shop admin, no reduction on top of it
            foreach (['reduction_percent', 'reduction_amount', 'reduction_amount_tax_incl', 'reduction_amount_tax_excl'] as $f) {
                if (isset($d->$f)) {
                    $d->$f = '0';
                }
            }
        }
        foreach (['tax_rate', 'tax_name'] as $f) {
            if (!isset($d->$f) && array_key_exists($f, $keep)) {
                $d->$f = (string)$keep[$f];
            }
        }
        $this->strip($d, 'order_details', ['associations']);
        $res = $this->api->put('order_details', $op['id'], $doc, $ctx);
        if ((int)$res->order_detail->product_quantity !== $n['qty']
            || abs((float)$res->order_detail->total_price_tax_incl - $n['total_incl']) >= 0.005) {
            throw new \RuntimeException('e-shop vrátil iné hodnoty');
        }
    }

    /** POST of a new line: a copy of an existing line of the order with the product's data. @return int the new line's id */
    private function addLine(array $op, int $templateId, array $ctx): int
    {
        $n = $op['line'];
        $p = $op['product'];
        $doc = $this->api->get('order_details/' . $templateId, $ctx);
        $d = $doc->order_detail;
        $names = [];
        foreach ($d->children() as $name => $v) {
            $names[] = $name;
        }
        foreach ($names as $name) {
            if (preg_match(self::RESET, $name)) {
                $d->$name = '0';
            }
        }
        $set = ['product_id' => $p['product_id'], 'product_attribute_id' => $p['attribute_id'], 'id_customization' => 0,
            'product_name' => mb_substr($p['name'], 0, 255, 'UTF-8'), 'product_quantity' => $n['qty'], 'product_quantity_in_stock' => $n['qty'],
            'product_price' => sprintf('%.6F', $p['base_price']), 'original_product_price' => sprintf('%.6F', $p['base_price']),
            'product_reference' => $p['reference'], 'product_supplier_reference' => $p['supplier_reference'],
            'product_ean13' => $p['ean13'], 'product_isbn' => $p['isbn'], 'product_upc' => $p['upc'], 'product_mpn' => $p['mpn'],
            'product_weight' => sprintf('%.6F', $p['weight']), 'id_tax_rules_group' => $p['id_tax_rules_group'],
            'unit_price_tax_incl' => sprintf('%.6F', $n['unit_incl']), 'unit_price_tax_excl' => sprintf('%.6F', $n['unit_excl']),
            'total_price_tax_incl' => sprintf('%.6F', $n['total_incl']), 'total_price_tax_excl' => sprintf('%.6F', $n['total_excl']),
            'total_shipping_price_tax_incl' => '0', 'total_shipping_price_tax_excl' => '0',
            'purchase_supplier_price' => sprintf('%.6F', $p['wholesale_price']), 'original_wholesale_price' => sprintf('%.6F', $p['wholesale_price']),
            'download_hash' => ''];
        foreach ($set as $f => $v) {
            if (isset($d->$f)) {
                $d->$f = (string)$v;
            }
        }
        $this->strip($d, 'order_details', ['id', 'associations']);
        $res = $this->api->post('order_details', $doc->asXML(), $ctx);
        $id = (int)$res->order_detail->id;
        if (!$id) {
            throw new \RuntimeException('e-shop novú položku neuložil');
        }
        return $id;
    }

    private static function money($v): string
    {
        return number_format((float)$v, 2, ',', ' ') . ' €';
    }
}
