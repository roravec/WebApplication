<?php
namespace Panel\Shop;

use WebApplication\Database\Database;

/**
 * READ-ONLY access to PrestaShop orders (tables <prefix>orders, order_detail, …) in the shared database = order
 * source 0 (OrderSource; shops reached only through the Webservice: ShopOrdersApi).
 * The panel never writes into the shop's tables by SQL — changes go through the PrestaShop Webservice API
 * (docs/sklad-specifikacia.md). Works with PrestaShop 1.7.6 – 8.x table layout.
 */
class ShopOrders implements OrderSource
{
    /** @var Database */ private $db;
    /** @var string */ private $p;
    /** @var string */ private $langIso;
    /** @var int|null */ private $langId = null;
    /** @var string */ private $label;
    /** @var int[]|null shops the person may see (null = all) */ private $allowed = null;

    public function __construct(Database $db, string $prefix = 'ps_', string $langIso = 'sk', string $label = 'E-shopy (databáza)')
    {
        $this->label = $label;
        if (!preg_match('/^[A-Za-z0-9_]*$/', $prefix)) {
            throw new \InvalidArgumentException('Invalid shop table prefix');
        }
        $this->db = $db;
        $this->p = $prefix;
        $this->langIso = $langIso;
    }

    public function id(): int
    {
        return 0;
    }

    public function label(): string
    {
        return $this->label;
    }

    /** Is the shop database reachable (tables exist)? */
    public function available(): bool
    {
        try {
            $this->db->fetchValue("SELECT 1 FROM `{$this->p}orders` LIMIT 1");
            return true;
        } catch (\PDOException $e) {
            return false;
        }
    }

    public function langId(): int
    {
        if ($this->langId === null) {
            $id = $this->db->fetchValue("SELECT id_lang FROM `{$this->p}lang` WHERE iso_code = ? LIMIT 1", [$this->langIso]);
            $this->langId = $id ? (int)$id : 1;
        }
        return $this->langId;
    }

    public function restrictShops(?array $shopIds): void
    {
        $this->allowed = $shopIds === null ? null : array_values(array_unique(array_map('intval', $shopIds)));
    }

    /** SQL condition limiting the column to the allowed shops ('1 = 1' when unrestricted). */
    private function shopCondition(string $column): string
    {
        if ($this->allowed === null) {
            return '1 = 1';
        }
        return $this->allowed ? $column . ' IN (' . implode(',', $this->allowed) . ')' : '0 = 1';
    }

    /** @return array<int, array{id_shop:int, name:string}> */
    public function shops(): array
    {
        return $this->db->query("SELECT id_shop, name FROM `{$this->p}shop` WHERE deleted = 0 AND {$this->shopCondition('id_shop')} ORDER BY id_shop");
    }

    /** @return array order states with name/color in the panel language */
    public function states(): array
    {
        return $this->db->query(
            "SELECT os.id_order_state, osl.name, os.color, os.paid, os.shipped, os.delivery, os.send_email
             FROM `{$this->p}order_state` os
             LEFT JOIN `{$this->p}order_state_lang` osl ON osl.id_order_state = os.id_order_state AND osl.id_lang = ?
             WHERE os.deleted = 0 ORDER BY osl.name",
            [$this->langId()]
        );
    }

    /**
     * Orders, newest first.
     * @param array $f filters: shop (id), state (id), from (Y-m-d), to (Y-m-d), q (reference / id / customer name / e-mail / company)
     * @return array{rows: array, total: int}
     */
    public function search(array $f, int $page = 1, int $perPage = 50): array
    {
        list($where, $params) = $this->filters($f);
        $from = "FROM `{$this->p}orders` o
            JOIN `{$this->p}shop` s ON s.id_shop = o.id_shop
            LEFT JOIN `{$this->p}customer` c ON c.id_customer = o.id_customer
            LEFT JOIN `{$this->p}address` a ON a.id_address = o.id_address_invoice
            LEFT JOIN `{$this->p}order_state` os ON os.id_order_state = o.current_state
            LEFT JOIN `{$this->p}order_state_lang` osl ON osl.id_order_state = o.current_state AND osl.id_lang = " . $this->langId() . "
            LEFT JOIN `{$this->p}currency` cur ON cur.id_currency = o.id_currency
            $where";
        $total = (int)$this->db->fetchValue("SELECT COUNT(*) $from", $params);
        $perPage = max(1, min(200, $perPage));
        $offset = max(0, ($page - 1) * $perPage);
        $rows = $this->db->query(
            "SELECT 0 AS src, o.id_order, o.reference, o.id_shop, s.name AS shop, o.date_add, o.total_paid_tax_incl, o.payment,
                    o.current_state, osl.name AS state, os.color AS state_color, os.paid AS state_paid,
                    c.firstname, c.lastname, c.email, a.company, cur.iso_code AS currency
             $from ORDER BY o.date_add DESC, o.id_order DESC LIMIT $perPage OFFSET $offset",
            $params
        );
        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * Look of one e-shop for e-mails to its customers (owner 2026-11-20: the customer must see where he bought):
     * name, web address and its e-mail logo (PS_LOGO_MAIL, else PS_LOGO — the shop's own value, else the global one),
     * read from the shop tables. Logo bytes are fetched from the shop's web (same hosting); '' when unavailable.
     * Also its contact e-mail and phone (PS_SHOP_EMAIL / PS_SHOP_PHONE). For invoices the shop's invoice logo
     * (PS_LOGO_INVOICE, like the shop's own invoices had — owner 2026-11-21).
     * @param string $purpose mail | invoice
     * @return array{name:string, web:string, email:string, phone:string, logo:string, logo_mime:string}
     */
    public function brand(int $shopId, string $purpose = 'mail'): array
    {
        $b = ['name' => '', 'web' => '', 'email' => '', 'phone' => '', 'logo' => '', 'logo_mime' => ''];
        try {
            $s = $this->db->fetchOne("SELECT s.name, u.domain_ssl, u.physical_uri FROM {$this->p}shop s
                LEFT JOIN {$this->p}shop_url u ON u.id_shop = s.id_shop AND u.main = 1 WHERE s.id_shop = ?", [$shopId]);
            if ($s === false) {
                return $b;
            }
            $b['name'] = (string)$s['name'];
            $b['web'] = $s['domain_ssl'] ? 'https://' . $s['domain_ssl'] . ($s['physical_uri'] ?: '/') : '';
            $conf = function (string $key) use ($shopId) {
                return (string)$this->db->fetchValue("SELECT value FROM {$this->p}configuration WHERE name = ? AND (id_shop = ? OR id_shop IS NULL)
                    AND IFNULL(value, '') <> '' ORDER BY id_shop IS NULL, id_configuration DESC LIMIT 1", [$key, $shopId]);
            };
            $b['email'] = $conf('PS_SHOP_EMAIL');
            $b['phone'] = $conf('PS_SHOP_PHONE');
            $file = '';
            foreach ($purpose === 'invoice' ? ['PS_LOGO_INVOICE', 'PS_LOGO'] : ['PS_LOGO_MAIL', 'PS_LOGO'] as $key) {
                $file = $conf($key);
                if ($file !== '') {
                    break;
                }
            }
            if ($file !== '' && $b['web'] !== '' && preg_match('/^[A-Za-z0-9._-]+\.(png|jpe?g|gif)$/i', $file, $m)) {
                $ctx = stream_context_create(['http' => ['timeout' => 5], 'ssl' => ['verify_peer' => true]]);
                $bytes = @file_get_contents($b['web'] . 'img/' . $file, false, $ctx);
                if (is_string($bytes) && $bytes !== '' && strlen($bytes) < 1000000) {
                    $b['logo'] = $bytes;
                    $b['logo_mime'] = strtolower($m[1]) === 'png' ? 'image/png' : (strtolower($m[1]) === 'gif' ? 'image/gif' : 'image/jpeg');
                }
            }
        } catch (\Throwable $e) {
        }
        return $b;
    }

    /**
     * E-mails PrestaShop sent to this address since the order was placed (its own log, table mail — "Log e-mails" is
     * on in the shops): order confirmation, state changes, … Read only. [] when the log is not available.
     * @return array<array{template:string, subject:string, date_add:string}>
     */
    public function mailLog(string $email, string $since): array
    {
        if ($email === '') {
            return [];
        }
        try {
            return $this->db->query("SELECT template, subject, date_add FROM {$this->p}mail WHERE recipient = ? AND date_add >= ?
                ORDER BY date_add DESC, id_mail DESC LIMIT 50", [$email, date('Y-m-d H:i:s', strtotime($since) - 60)]);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** One order with lines, addresses, payments, state history; null if not found. */
    public function brief(int $id): ?array
    {
        $o = $this->db->fetchOne("SELECT id_order, reference, id_shop FROM `{$this->p}orders` WHERE id_order = ? AND {$this->shopCondition('id_shop')}", [$id]);
        return $o === false ? null : ['id_order' => (int)$o['id_order'], 'reference' => (string)$o['reference'], 'id_shop' => (int)$o['id_shop']];
    }

    public function find(int $id): ?array
    {
        $o = $this->db->fetchOne(
            "SELECT o.*, s.name AS shop, cur.iso_code AS currency, osl.name AS state, os.color AS state_color,
                    c.firstname AS customer_firstname, c.lastname AS customer_lastname, c.email AS customer_email,
                    c.company AS customer_company, car.name AS carrier, car.url AS carrier_url,
                    (SELECT oc.tracking_number FROM `{$this->p}order_carrier` oc WHERE oc.id_order = o.id_order ORDER BY oc.id_order_carrier DESC LIMIT 1) AS tracking
             FROM `{$this->p}orders` o
             JOIN `{$this->p}shop` s ON s.id_shop = o.id_shop
             LEFT JOIN `{$this->p}currency` cur ON cur.id_currency = o.id_currency
             LEFT JOIN `{$this->p}order_state` os ON os.id_order_state = o.current_state
             LEFT JOIN `{$this->p}order_state_lang` osl ON osl.id_order_state = o.current_state AND osl.id_lang = ?
             LEFT JOIN `{$this->p}customer` c ON c.id_customer = o.id_customer
             LEFT JOIN `{$this->p}carrier` car ON car.id_carrier = o.id_carrier
             WHERE o.id_order = ? AND {$this->shopCondition('o.id_shop')}",
            [$this->langId(), $id]
        );
        if ($o === false) {
            return null;
        }
        $o['src'] = 0;
        $o['payments_unavailable'] = false;
        $o['lines'] = $this->db->query(
            "SELECT id_order_detail, product_id, product_attribute_id, product_name, product_reference, product_quantity, unit_price_tax_excl,
                    unit_price_tax_incl, total_price_tax_excl, total_price_tax_incl, tax_rate, tax_name, product_weight,
                    product_quantity_reinjected
             FROM `{$this->p}order_detail` WHERE id_order = ? ORDER BY id_order_detail",
            [$id]
        );
        // order_detail.tax_rate is deprecated — PrestaShop 1.7+ leaves it 0 → the rate from the prices (like ShopOrdersApi);
        // the stored values stay in tax_rate_db / tax_name (an order line PUT must send them back, ShopOrderLines)
        foreach ($o['lines'] as &$l) {
            $l['tax_rate_db'] = $l['tax_rate'];
            if ((float)$l['tax_rate'] <= 0) {
                $l['tax_rate'] = (float)$l['unit_price_tax_excl'] > 0
                    ? ShopOrdersApi::rate((float)$l['unit_price_tax_incl'], (float)$l['unit_price_tax_excl'])
                    : ShopOrdersApi::rate((float)$l['total_price_tax_incl'], (float)$l['total_price_tax_excl']);
            }
        }
        unset($l);
        $o['address_invoice'] = $this->address((int)$o['id_address_invoice']);
        $o['address_delivery'] = $this->address((int)$o['id_address_delivery']);
        $o['pickup_point'] = $this->pickupPoint((int)$o['id_cart']);
        $o['payments'] = $this->db->query(
            "SELECT payment_method, amount, transaction_id, date_add FROM `{$this->p}order_payment`
             WHERE order_reference = ? ORDER BY date_add",
            [$o['reference']]
        );
        $o['history'] = $this->db->query(
            "SELECT h.date_add, osl.name AS state, os.color
             FROM `{$this->p}order_history` h
             LEFT JOIN `{$this->p}order_state` os ON os.id_order_state = h.id_order_state
             LEFT JOIN `{$this->p}order_state_lang` osl ON osl.id_order_state = h.id_order_state AND osl.id_lang = ?
             WHERE h.id_order = ? ORDER BY h.date_add, h.id_order_history",
            [$this->langId(), $id]
        );
        return $o;
    }

    /** Counts for the overview: orders today / last 7 days / last 30 days, and per shop in the last 30 days. */
    public function stats(): array
    {
        $since30 = date('Y-m-d', strtotime('-30 days'));
        $row = $this->db->fetchOne(
            "SELECT SUM(date_add >= ?) AS today, SUM(date_add >= ?) AS week, COUNT(*) AS month,
                    SUM(total_paid_tax_incl) AS month_total
             FROM `{$this->p}orders` WHERE date_add >= ? AND {$this->shopCondition('id_shop')}",
            [date('Y-m-d'), date('Y-m-d', strtotime('-7 days')), $since30]
        );
        $perShop = $this->db->query(
            "SELECT s.name, COUNT(o.id_order) AS orders, COALESCE(SUM(o.total_paid_tax_incl), 0) AS total
             FROM `{$this->p}shop` s
             LEFT JOIN `{$this->p}orders` o ON o.id_shop = s.id_shop AND o.date_add >= ?
             WHERE s.deleted = 0 AND {$this->shopCondition('s.id_shop')} GROUP BY s.id_shop, s.name ORDER BY s.id_shop",
            [$since30]
        );
        return ['today' => (int)$row['today'], 'week' => (int)$row['week'], 'month' => (int)$row['month'],
                'month_total' => (float)$row['month_total'], 'per_shop' => $perShop];
    }

    /**
     * Packeta pickup point chosen in the checkout — stored by the shop's Zásielkovňa module (shaim_zasilkovna_widget,
     * table <prefix>shaim_zasilkovna_widget_data, one row per cart). '' when there is none / the module is not installed.
     * @return string "place, street, zip, city, country" like the old shop invoices printed it
     */
    public function pickupPoint(int $cartId): string
    {
        static $has = [];
        $table = $this->p . 'shaim_zasilkovna_widget_data';
        if ($cartId <= 0) {
            return '';
        }
        if (!isset($has[$table])) {
            $has[$table] = (bool)$this->db->fetchValue('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$table]);
        }
        if (!$has[$table]) {
            return '';
        }
        $r = $this->db->fetchOne("SELECT place, street, psc, city, country, routingName FROM `$table` WHERE id_cart = ?", [$cartId]);
        if ($r === false) {
            return '';
        }
        $parts = array_filter(array_map('trim', [(string)$r['place'], (string)$r['street'], (string)$r['psc'], (string)$r['city'], (string)$r['country']]), 'strlen');
        return $parts ? implode(', ', $parts) : trim((string)$r['routingName']);
    }

    private function address(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $a = $this->db->fetchOne(
            "SELECT a.company, a.firstname, a.lastname, a.address1, a.address2, a.postcode, a.city, a.phone, a.phone_mobile,
                    a.vat_number, a.dni, cl.name AS country, co.iso_code AS country_iso
             FROM `{$this->p}address` a
             LEFT JOIN `{$this->p}country_lang` cl ON cl.id_country = a.id_country AND cl.id_lang = ?
             LEFT JOIN `{$this->p}country` co ON co.id_country = a.id_country
             WHERE a.id_address = ?",
            [$this->langId(), $id]
        );
        return $a === false ? null : $a;
    }

    private function filters(array $f): array
    {
        $w = [$this->shopCondition('o.id_shop')];
        $p = [];
        if (!empty($f['shop'])) {
            $w[] = 'o.id_shop = ?';
            $p[] = (int)$f['shop'];
        }
        if (!empty($f['state'])) {
            $w[] = 'o.current_state = ?';
            $p[] = (int)$f['state'];
        } elseif (!empty($f['states'])) {   // several states (Expedícia)
            $w[] = 'o.current_state IN (' . implode(',', array_map('intval', (array)$f['states'])) . ')';
        }
        if (!empty($f['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['from'])) {
            $w[] = 'o.date_add >= ?';
            $p[] = $f['from'] . ' 00:00:00';
        }
        if (!empty($f['to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['to'])) {
            $w[] = 'o.date_add <= ?';
            $p[] = $f['to'] . ' 23:59:59';
        }
        $q = trim((string)($f['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $w[] = "(o.reference LIKE ? OR o.id_order = ? OR c.email LIKE ? OR a.company LIKE ?
                     OR CONCAT(c.firstname, ' ', c.lastname) LIKE ? OR CONCAT(a.firstname, ' ', a.lastname) LIKE ?)";
            array_push($p, $like, ctype_digit($q) ? (int)$q : 0, $like, $like, $like, $like);
        }
        return [$w ? 'WHERE ' . implode(' AND ', $w) : '', $p];
    }
}
