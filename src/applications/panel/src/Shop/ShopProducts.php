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
        'minimal_quantity' => 'Minimálne množstvo na objednávku',
        'low_stock_threshold' => 'Upozorniť pri množstve (nízky stav)',
        'low_stock_alert' => 'Posielať e-mail pri nízkom stave',
        'wholesale_price' => 'Nákupná cena (bez DPH)',
        'available_now' => 'Text — na sklade',
        'available_later' => 'Text — keď nie je na sklade',
    ];
    const COMBINATION_FIELDS = ['minimal_quantity', 'low_stock_threshold', 'wholesale_price'];
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
                'ean' => (string)$p->ean13, 'active' => (int)$p->active === 1, 'combinations' => []];
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
