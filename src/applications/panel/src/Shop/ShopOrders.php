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

    /** @return array<int, array{id_shop:int, name:string}> */
    public function shops(): array
    {
        return $this->db->query("SELECT id_shop, name FROM `{$this->p}shop` WHERE deleted = 0 ORDER BY id_shop");
    }

    /** @return array order states with name/color in the panel language */
    public function states(): array
    {
        return $this->db->query(
            "SELECT os.id_order_state, osl.name, os.color, os.paid, os.shipped, os.delivery
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

    /** One order with lines, addresses, payments, state history; null if not found. */
    public function find(int $id): ?array
    {
        $o = $this->db->fetchOne(
            "SELECT o.*, s.name AS shop, cur.iso_code AS currency, osl.name AS state, os.color AS state_color,
                    c.firstname AS customer_firstname, c.lastname AS customer_lastname, c.email AS customer_email,
                    c.company AS customer_company, car.name AS carrier
             FROM `{$this->p}orders` o
             JOIN `{$this->p}shop` s ON s.id_shop = o.id_shop
             LEFT JOIN `{$this->p}currency` cur ON cur.id_currency = o.id_currency
             LEFT JOIN `{$this->p}order_state` os ON os.id_order_state = o.current_state
             LEFT JOIN `{$this->p}order_state_lang` osl ON osl.id_order_state = o.current_state AND osl.id_lang = ?
             LEFT JOIN `{$this->p}customer` c ON c.id_customer = o.id_customer
             LEFT JOIN `{$this->p}carrier` car ON car.id_carrier = o.id_carrier
             WHERE o.id_order = ?",
            [$this->langId(), $id]
        );
        if ($o === false) {
            return null;
        }
        $o['src'] = 0;
        $o['payments_unavailable'] = false;
        $o['lines'] = $this->db->query(
            "SELECT product_name, product_reference, product_quantity, unit_price_tax_excl, unit_price_tax_incl,
                    total_price_tax_excl, total_price_tax_incl, tax_rate
             FROM `{$this->p}order_detail` WHERE id_order = ? ORDER BY id_order_detail",
            [$id]
        );
        $o['address_invoice'] = $this->address((int)$o['id_address_invoice']);
        $o['address_delivery'] = $this->address((int)$o['id_address_delivery']);
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
             FROM `{$this->p}orders` WHERE date_add >= ?",
            [date('Y-m-d'), date('Y-m-d', strtotime('-7 days')), $since30]
        );
        $perShop = $this->db->query(
            "SELECT s.name, COUNT(o.id_order) AS orders, COALESCE(SUM(o.total_paid_tax_incl), 0) AS total
             FROM `{$this->p}shop` s
             LEFT JOIN `{$this->p}orders` o ON o.id_shop = s.id_shop AND o.date_add >= ?
             WHERE s.deleted = 0 GROUP BY s.id_shop, s.name ORDER BY s.id_shop",
            [$since30]
        );
        return ['today' => (int)$row['today'], 'week' => (int)$row['week'], 'month' => (int)$row['month'],
                'month_total' => (float)$row['month_total'], 'per_shop' => $perShop];
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
        $w = [];
        $p = [];
        if (!empty($f['shop'])) {
            $w[] = 'o.id_shop = ?';
            $p[] = (int)$f['shop'];
        }
        if (!empty($f['state'])) {
            $w[] = 'o.current_state = ?';
            $p[] = (int)$f['state'];
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
