<?php
namespace Panel\Invoicing;

use WebApplication\Database\Database;

/** Customer directory per company (B2C persons, B2B companies). Invoices keep their own copy of the buyer's data. */
class Customers
{
    const FIELDS = ['type', 'name', 'street', 'city', 'zip', 'country', 'ico', 'dic', 'icdph', 'email', 'phone', 'due_days', 'note'];

    /** @var Database */ private $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * @param int[] $companyIds only customers of these companies
     * @return array{rows: array, total: int}
     */
    public function search(string $q, array $companyIds, int $page = 1, int $perPage = 50): array
    {
        if (!$companyIds) {
            return ['rows' => [], 'total' => 0];
        }
        $where = 'WHERE company_id IN (' . implode(',', array_map('intval', $companyIds)) . ')';
        $params = [];
        $q = trim($q);
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $where .= ' AND (name LIKE ? OR ico LIKE ? OR email LIKE ? OR city LIKE ?)';
            $params = [$like, $like, $like, $like];
        }
        $t = $this->db->table('customer');
        $total = (int)$this->db->fetchValue("SELECT COUNT(*) FROM $t $where", $params);
        $perPage = max(1, min(200, $perPage));
        $offset = max(0, ($page - 1) * $perPage);
        return ['rows' => $this->db->query("SELECT * FROM $t $where ORDER BY name LIMIT $perPage OFFSET $offset", $params), 'total' => $total];
    }

    public function find(int $id): ?array
    {
        $row = $this->db->fetchOne('SELECT * FROM ' . $this->db->table('customer') . ' WHERE id = ?', [$id]);
        return $row === false ? null : $row;
    }

    /** @param int $source order source of the shop customer (0 = shop tables in this database, N = shop connection N) */
    public function findByShopCustomer(int $companyId, int $source, int $shopCustomerId): ?array
    {
        $row = $this->db->fetchOne('SELECT * FROM ' . $this->db->table('customer') . ' WHERE company_id = ? AND shop_connection_id = ?
            AND shop_customer_id = ? ORDER BY id DESC LIMIT 1', [$companyId, $source, $shopCustomerId]);
        return $row === false ? null : $row;
    }

    /** $companyId is used for a new customer only (a customer never moves to another company). */
    public function save(?int $id, int $companyId, array $d, ?int $shopCustomerId = null, int $source = 0): int
    {
        $now = date('Y-m-d H:i:s');
        $vals = [];
        foreach (self::FIELDS as $f) {
            $vals[] = $f === 'due_days' ? (($d[$f] ?? '') === '' || $d[$f] === null ? null : (int)$d[$f]) : (string)($d[$f] ?? '');
        }
        $t = $this->db->table('customer');
        if ($id) {
            $set = implode(', ', array_map(function ($f) { return "$f = ?"; }, self::FIELDS));
            $this->db->execute("UPDATE $t SET $set, updated_at = ? WHERE id = ?", array_merge($vals, [$now, $id]));
            return $id;
        }
        $cols = implode(', ', self::FIELDS);
        $q = implode(', ', array_fill(0, count(self::FIELDS), '?'));
        $this->db->execute("INSERT INTO $t ($cols, company_id, shop_customer_id, shop_connection_id, created_at, updated_at) VALUES ($q, ?, ?, ?, ?, ?)",
            array_merge($vals, [$companyId, $shopCustomerId, $shopCustomerId ? $source : 0, $now, $now]));
        return $this->db->lastInsertId();
    }

    /** Validation shared by the customer form and the buyer part of the invoice form. @return array field => message */
    public static function validate(array $d, string $prefix = ''): array
    {
        $err = [];
        if (trim((string)$d['name']) === '') {
            $err[$prefix . 'name'] = 'Zadajte meno / obchodné meno.';
        }
        if (!preg_match('/^[A-Z]{2}$/', (string)$d['country'])) {
            $err[$prefix . 'country'] = 'Krajina: dvojpísmenový kód (SK, CZ, …).';
        }
        if ($d['type'] === 'b2b' && trim((string)$d['ico']) === '') {
            $err[$prefix . 'ico'] = 'Pri firme zadajte IČO.';
        }
        if ($d['ico'] !== '' && !preg_match('/^[0-9 ]{6,12}$/', (string)$d['ico'])) {
            $err[$prefix . 'ico'] = 'IČO: 6–12 číslic.';
        }
        if ($d['email'] !== '' && !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) {
            $err[$prefix . 'email'] = 'Neplatný e-mail.';
        }
        return $err;
    }
}
