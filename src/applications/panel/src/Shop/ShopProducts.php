<?php
namespace Panel\Shop;

/**
 * Stock settings of a product and its combinations, changed IN THE SHOP through the Webservice.
 *
 * SAFE UPDATE (verified on PrestaShop 1.7.6.7, 8.2.9 and 9.2.1 shop copies, 2026-11-19):
 * - read-only fields are removed before PUT (otherwise HTTP 400 "not writable"): the shop's own list from
   `<resource>?schema=synopsis` (read_only="true"; PS 9 added `new`), else the known ones (manufacturer_name, quantity, new);
 * - **`associations` must be removed** — with them, every PUT of a product moves the product (and shifts other
 *   products) in the category order (known PrestaShop 1.7 bug in category positions);
 * - empty nullable fields (`low_stock_threshold`) are removed — sending them empty turns NULL into 0;
 * Without these nodes a PUT changes nothing but the edited fields (+ date_upd) — checked by diffing all product tables.
 */
class ShopProducts
{
    const PRODUCT_FIELDS = [
        'price' => 'Predajná cena bez DPH',
        'active' => 'Aktívny (v predaji)',
        'minimal_quantity' => 'Minimálne množstvo na objednávku',
        'low_stock_threshold' => 'Upozorniť pri množstve (nízky stav)',
        'low_stock_alert' => 'Posielať e-mail pri nízkom stave',
        'wholesale_price' => 'Nákupná cena (bez DPH)',
        'available_now' => 'Text — na sklade',
        'available_later' => 'Text — keď nie je na sklade',
    ];
    const COMBINATION_FIELDS = ['minimal_quantity', 'low_stock_threshold', 'wholesale_price', 'price'];   // price = impact on the product price
    const LANG_FIELDS = ['available_now', 'available_later'];

    /** @var PrestaShopApi */ private $api;
    /** @var array resource => read-only field names (from the shop's schema) */ private $readOnly = [];

    public function __construct(PrestaShopApi $api)
    {
        $this->api = $api;
    }

    /** Product with its settings + combinations (with their attribute names). */
    public function product(array $shop, int $productId, int $langId): array
    {
        $ctx = ['id_shop' => $shop['id']];
        $p = $this->api->get('products/' . $productId, $ctx)->product;
        $out = ['id' => $productId, 'name' => PrestaShopApi::text($p->name, $langId), 'reference' => (string)$p->reference,
                'ean' => (string)$p->ean13, 'combinations' => [], 'tax_rate' => $this->taxRate($shop, (int)$p->id_tax_rules_group)];
        foreach (array_keys(self::PRODUCT_FIELDS) as $f) {
            $out[$f] = in_array($f, self::LANG_FIELDS, true) ? self::langValue($p->$f, $langId) : (string)$p->$f;
        }
        $values = [];
        foreach ($this->api->get('product_option_values', ['display' => '[id,name]'])->product_option_values->product_option_value ?? [] as $v) {
            $values[(int)$v->id] = PrestaShopApi::text($v->name, $langId);
        }
        foreach ($this->api->get('combinations', $ctx + ['display' => 'full', 'filter' => ['id_product' => $productId]])->combinations->combination ?? [] as $c) {
            $names = [];
            foreach ($c->associations->product_option_values->product_option_value ?? [] as $ov) {
                $names[] = $values[(int)$ov->id] ?? '';
            }
            $row = ['id' => (int)$c->id, 'label' => implode(', ', array_filter($names)), 'reference' => (string)$c->reference];
            foreach (self::COMBINATION_FIELDS as $f) {
                $row[$f] = (string)$c->$f;
            }
            $out['combinations'][] = $row;
        }
        return $out;
    }

    /** @param array $changes field => new value (only changed fields) */
    public function updateProduct(array $shop, int $productId, array $changes, int $langId): void
    {
        $ctx = ['id_shop' => $shop['id']];
        $doc = $this->api->get('products/' . $productId, $ctx);
        $p = $doc->product;
        foreach ($changes as $f => $v) {
            if (!isset(self::PRODUCT_FIELDS[$f])) {
                continue;
            }
            if (in_array($f, self::LANG_FIELDS, true)) {
                self::setLang($p->$f, $langId, (string)$v);
            } else {
                $p->$f = (string)$v;
            }
        }
        self::makeSafe($p, array_merge($this->readOnlyFields('products'), ['associations', 'position_in_category']));
        $this->api->put('products', $productId, $doc, $ctx);
    }

    public function updateCombination(array $shop, int $combinationId, int $productId, array $changes): void
    {
        $ctx = ['id_shop' => $shop['id']];
        $doc = $this->api->get('combinations/' . $combinationId, $ctx);
        $c = $doc->combination;
        if ((int)$c->id_product !== $productId) {
            throw new \RuntimeException('Kombinácia nepatrí k tomuto produktu.');
        }
        foreach ($changes as $f => $v) {
            if (in_array($f, self::COMBINATION_FIELDS, true)) {
                $c->$f = (string)$v;
            }
        }
        self::makeSafe($c, array_merge($this->readOnlyFields('combinations'), ['associations']));
        $this->api->put('combinations', $combinationId, $doc, $ctx);
    }

    /**
     * VAT rate of a tax rules group for Slovakia (to show the price with VAT next to the price without it); 0 = no tax
     * (e.g. a shop of a non-payer). Read from the shop: tax_rules (country SK) → taxes.
     */
    public function taxRate(array $shop, int $groupId): float
    {
        if ($groupId <= 0) {
            return 0.0;
        }
        try {
            $ctx = ['id_shop' => $shop['id']];
            $country = $this->api->get('countries', $ctx + ['display' => '[id]', 'filter' => ['iso_code' => '[SK]']])->countries->country[0] ?? null;
            if ($country === null) {
                return 0.0;
            }
            $rule = $this->api->get('tax_rules', $ctx + ['display' => '[id_tax]', 'filter' => ['id_tax_rules_group' => '[' . $groupId . ']',
                'id_country' => '[' . (int)$country->id . ']']])->tax_rules->tax_rule[0] ?? null;
            if ($rule === null || !(int)$rule->id_tax) {
                return 0.0;
            }
            return (float)$this->api->get('taxes/' . (int)$rule->id_tax, $ctx)->tax->rate;
        } catch (\Throwable $e) {
            return 0.0;   // key without tax resources: the price with VAT is just not shown
        }
    }

    /** Sales (specific prices) of a product in this shop: id, combination, reduction, type, with VAT, from, to, from quantity. */
    public function specificPrices(array $shop, int $productId): array
    {
        $out = [];
        $list = $this->api->get('specific_prices', ['id_shop' => $shop['id'], 'display' => 'full', 'filter' => ['id_product' => '[' . $productId . ']']]);
        foreach ($list->specific_prices->specific_price ?? [] as $sp) {
            if ((int)$sp->id_shop !== 0 && (int)$sp->id_shop !== (int)$shop['id']) {
                continue;
            }
            $out[] = ['id' => (int)$sp->id, 'combination' => (int)$sp->id_product_attribute, 'reduction' => (float)$sp->reduction,
                'type' => (string)$sp->reduction_type, 'tax' => (int)$sp->reduction_tax === 1, 'price' => (float)$sp->price,
                'from' => (string)$sp->from, 'to' => (string)$sp->to, 'from_quantity' => (int)$sp->from_quantity,
                'all_shops' => (int)$sp->id_shop === 0, 'limited' => (int)$sp->id_group || (int)$sp->id_customer || (int)$sp->id_country
                    || (int)$sp->id_currency || (int)$sp->id_cart];
        }
        return $out;
    }

    /**
     * New sale for everybody in this shop. @param string $type percentage | amount @param float $value 10 = 10 % or 10 €
     * @param bool $taxIncl amount with VAT (amount only) @param string $from / $to Y-m-d or '' (unlimited)
     */
    public function addSpecificPrice(array $shop, int $productId, int $combinationId, string $type, float $value, bool $taxIncl,
                                     string $from, string $to, int $fromQuantity = 1): int
    {
        if (!in_array($type, ['percentage', 'amount'], true) || $value <= 0 || ($type === 'percentage' && $value >= 100)) {
            throw new \InvalidArgumentException('Zľava: percento 0–100 alebo suma > 0.');
        }
        $x = function ($v) { return htmlspecialchars((string)$v, ENT_XML1, 'UTF-8'); };
        $xml = '<?xml version="1.0" encoding="UTF-8"?><prestashop><specific_price>'
            . '<id_shop_group>0</id_shop_group><id_shop>' . (int)$shop['id'] . '</id_shop><id_cart>0</id_cart><id_product>' . $productId . '</id_product>'
            . '<id_product_attribute>' . $combinationId . '</id_product_attribute><id_currency>0</id_currency><id_country>0</id_country>'
            . '<id_group>0</id_group><id_customer>0</id_customer><price>-1</price><from_quantity>' . max(1, $fromQuantity) . '</from_quantity>'
            . '<reduction>' . $x($type === 'percentage' ? round($value / 100, 4) : round($value, 6)) . '</reduction>'
            . '<reduction_tax>' . ($type === 'amount' && !$taxIncl ? 0 : 1) . '</reduction_tax><reduction_type>' . $x($type) . '</reduction_type>'
            . '<from>' . $x($from !== '' ? $from . ' 00:00:00' : '0000-00-00 00:00:00') . '</from>'
            . '<to>' . $x($to !== '' ? $to . ' 23:59:59' : '0000-00-00 00:00:00') . '</to>'
            . '</specific_price></prestashop>';
        return (int)$this->api->post('specific_prices', $xml, ['id_shop' => $shop['id']])->specific_price->id;
    }

    public function deleteSpecificPrice(array $shop, int $specificPriceId, int $productId): void
    {
        $sp = $this->api->get('specific_prices/' . $specificPriceId, ['id_shop' => $shop['id']])->specific_price;
        if ((int)$sp->id_product !== $productId || ((int)$sp->id_shop !== 0 && (int)$sp->id_shop !== (int)$shop['id'])) {
            throw new \RuntimeException('Akcia nepatrí k tomuto produktu / e-shopu.');
        }
        $this->api->delete('specific_prices', $specificPriceId, ['id_shop' => $shop['id']]);
    }

    /** Read-only fields of a resource as the shop declares them (schema=synopsis), else the known ones. */
    private function readOnlyFields(string $resource): array
    {
        if (!isset($this->readOnly[$resource])) {
            $known = ['manufacturer_name', 'quantity', 'new'];
            try {
                $schema = $this->api->get($resource, ['schema' => 'synopsis']);
                $node = $schema->children()[0] ?? null;
                $fields = [];
                foreach ($node ? $node->children() : [] as $name => $f) {
                    if ((string)$f['read_only'] === 'true' || (string)$f['readOnly'] === 'true') {
                        $fields[] = $name;
                    }
                }
                $this->readOnly[$resource] = array_values(array_unique(array_merge($fields, $resource === 'products' ? $known : ['quantity'])));
            } catch (\Throwable $e) {
                $this->readOnly[$resource] = $resource === 'products' ? $known : ['quantity'];
            }
        }
        return $this->readOnly[$resource];
    }

    /** Removes nodes that must not be sent back + empty nullable fields (see the class comment). */
    private static function makeSafe(\SimpleXMLElement $node, array $remove): void
    {
        foreach ($remove as $r) {
            unset($node->$r);
        }
        if (isset($node->low_stock_threshold) && trim((string)$node->low_stock_threshold) === '') {
            unset($node->low_stock_threshold);
        }
    }

    private static function langValue(\SimpleXMLElement $field, int $langId): string
    {
        foreach ($field->language ?? [] as $l) {
            if ((int)$l['id'] === $langId) {
                return (string)$l;
            }
        }
        return '';
    }

    private static function setLang(\SimpleXMLElement $field, int $langId, string $value): void
    {
        foreach ($field->language as $l) {
            if ((int)$l['id'] === $langId) {
                $l[0] = $value;
                return;
            }
        }
        throw new \RuntimeException('E-shop nemá jazyk pre tento text.');
    }
}
