<?php
namespace Panel\Shop;

/**
 * Stock of a PrestaShop shop, read and changed IN THE SHOP through its Webservice (the shop keeps its stock — owner's
 * decision 19. 11. 2026, docs/sklad-specifikacia.md). Products and combinations exactly as PrestaShop has them:
 * one row per product without combinations, otherwise one row per combination (id_product_attribute), each with its
 * own stock_available row. With a shop group that shares stock, the stock rows belong to the group.
 */
class ShopStock
{
    /** PrestaShop out_of_stock values */
    const OUT_OF_STOCK = [2 => 'podľa nastavenia e-shopu', 0 => 'neumožniť objednať', 1 => 'umožniť objednať'];

    /** @var PrestaShopApi */ private $api;

    public function __construct(PrestaShopApi $api)
    {
        $this->api = $api;
    }

    /** Shops of the installation: id, name, group_id, group_name, share_stock. */
    public function shops(): array
    {
        $groups = [];
        foreach ($this->api->get('shop_groups', ['display' => 'full'])->shop_groups->shop_group as $g) {
            $groups[(int)$g->id] = ['name' => (string)$g->name, 'share_stock' => (int)$g->share_stock === 1];
        }
        $out = [];
        foreach ($this->api->get('shops', ['display' => '[id,name,id_shop_group,active]'])->shops->shop as $s) {
            $gid = (int)$s->id_shop_group;
            $out[] = ['id' => (int)$s->id, 'name' => (string)$s->name, 'group_id' => $gid, 'group_name' => $groups[$gid]['name'] ?? '',
                      'share_stock' => $groups[$gid]['share_stock'] ?? false];
        }
        usort($out, function ($a, $b) { return $a['id'] - $b['id']; });
        return $out;
    }

    public function shop(int $shopId): ?array
    {
        foreach ($this->shops() as $s) {
            if ($s['id'] === $shopId) {
                return $s;
            }
        }
        return null;
    }

    /**
     * Stock rows of a shop: product + combination, name (with attribute values), reference, EAN, quantity, out_of_stock,
     * location, stock_available id. @param string $q filters by name / reference (in the panel, after loading)
     */
    public function rows(array $shop, string $q = ''): array
    {
        $ctx = ['id_shop' => $shop['id']];
        $langId = $this->langId();
        $products = [];
        foreach ($this->api->get('products', $ctx + ['display' => '[id,reference,ean13,name,active]', 'sort' => '[id_ASC]'])->products->product as $p) {
            $products[(int)$p->id] = ['name' => PrestaShopApi::text($p->name, $langId), 'reference' => (string)$p->reference,
                'ean' => (string)$p->ean13, 'active' => (int)$p->active === 1];
        }
        // attribute value names for combinations
        $values = [];
        foreach ($this->api->get('product_option_values', ['display' => '[id,name]'])->product_option_values->product_option_value ?? [] as $v) {
            $values[(int)$v->id] = PrestaShopApi::text($v->name, $langId);
        }
        $combos = [];
        foreach ($this->api->get('combinations', $ctx + ['display' => 'full'])->combinations->combination ?? [] as $c) {
            $names = [];
            foreach ($c->associations->product_option_values->product_option_value ?? [] as $ov) {
                $names[] = $values[(int)$ov->id] ?? '';
            }
            $combos[(int)$c->id] = ['product' => (int)$c->id_product, 'reference' => (string)$c->reference, 'ean' => (string)$c->ean13,
                'label' => implode(', ', array_filter($names))];
        }
        $filter = $shop['share_stock'] ? ['id_shop_group' => $shop['group_id']] : ['id_shop' => $shop['id']];
        $rows = [];
        foreach ($this->api->get('stock_availables', $ctx + ['display' => 'full', 'filter' => $filter])->stock_availables->stock_available ?? [] as $s) {
            $pid = (int)$s->id_product;
            $aid = (int)$s->id_product_attribute;
            if (!isset($products[$pid])) {
                continue;   // product not in this shop
            }
            $hasCombos = (bool)array_filter($combos, function ($c) use ($pid) { return $c['product'] === $pid; });
            if ($aid === 0 && $hasCombos) {
                continue;   // product total of combinations — PrestaShop computes it from the combinations
            }
            $p = $products[$pid];
            $c = $aid ? ($combos[$aid] ?? null) : null;
            $rows[] = [
                'stock_id' => (int)$s->id, 'product_id' => $pid, 'combination_id' => $aid,
                'name' => $p['name'] . ($c && $c['label'] !== '' ? ' — ' . $c['label'] : ''),
                'reference' => $c && $c['reference'] !== '' ? $c['reference'] : $p['reference'],
                'ean' => $c && $c['ean'] !== '' ? $c['ean'] : $p['ean'], 'active' => $p['active'],
                'quantity' => (int)$s->quantity, 'out_of_stock' => (int)$s->out_of_stock, 'location' => (string)$s->location,
                'depends_on_stock' => (int)$s->depends_on_stock,
            ];
        }
        if ($q !== '') {
            $needle = mb_strtolower($q, 'UTF-8');
            $rows = array_values(array_filter($rows, function ($r) use ($needle) {
                return mb_strpos(mb_strtolower($r['name'] . ' ' . $r['reference'] . ' ' . $r['ean'], 'UTF-8'), $needle) !== false;
            }));
        }
        usort($rows, function ($a, $b) { return strcmp(self::sortKey($a['name']), self::sortKey($b['name'])); });
        return $rows;
    }

    /** Sort key that ignores case and Slovak / Czech diacritics (no intl extension needed). */
    public static function sortKey(string $s): string
    {
        return strtr(mb_strtolower($s, 'UTF-8'), ['á' => 'a', 'ä' => 'a', 'č' => 'c', 'ď' => 'd', 'é' => 'e', 'ě' => 'e', 'í' => 'i',
            'ĺ' => 'l', 'ľ' => 'l', 'ň' => 'n', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'ŕ' => 'r', 'ř' => 'r', 'š' => 's', 'ť' => 't',
            'ú' => 'u', 'ů' => 'u', 'ü' => 'u', 'ý' => 'y', 'ž' => 'z']);
    }

    /**
     * Changes a stock row in the shop. $changes: quantity (int), out_of_stock (0|1|2), location (string).
     * @return array the row as the shop has it after the change
     */
    public function update(array $shop, int $stockId, array $changes): array
    {
        $ctx = ['id_shop' => $shop['id']];
        $doc = $this->api->get('stock_availables/' . $stockId, $ctx);
        $s = $doc->stock_available;
        $belongs = $shop['share_stock'] ? (int)$s->id_shop_group === $shop['group_id'] : (int)$s->id_shop === $shop['id'];
        if (!$belongs) {
            throw new \RuntimeException('Skladový záznam nepatrí tomuto e-shopu.');
        }
        if (array_key_exists('quantity', $changes)) {
            $s->quantity = (string)(int)$changes['quantity'];
        }
        if (array_key_exists('out_of_stock', $changes) && isset(self::OUT_OF_STOCK[(int)$changes['out_of_stock']])) {
            $s->out_of_stock = (string)(int)$changes['out_of_stock'];
        }
        if (array_key_exists('location', $changes)) {
            $s->location = mb_substr((string)$changes['location'], 0, 64, 'UTF-8');
        }
        $res = $this->api->put('stock_availables', $stockId, $doc, $ctx);
        $n = $res->stock_available;
        return ['quantity' => (int)$n->quantity, 'out_of_stock' => (int)$n->out_of_stock, 'location' => (string)$n->location];
    }

    /** Language id for Slovak texts (or the shop default = first). */
    public function langId(): int
    {
        static $cache = [];
        $k = spl_object_hash($this->api);
        if (!isset($cache[$k])) {
            $cache[$k] = 0;
            foreach ($this->api->get('languages', ['display' => '[id,iso_code]'])->languages->language ?? [] as $l) {
                if ((string)$l->iso_code === 'sk') {
                    $cache[$k] = (int)$l->id;
                }
            }
        }
        return $cache[$k];
    }
}
