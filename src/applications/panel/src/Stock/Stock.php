<?php
namespace Panel\Stock;

use Panel\Audit;
use Panel\Invoicing\Calculator;
use Panel\Invoicing\Companies;
use Panel\Invoicing\Numbering;
use WebApplication\Database\Database;
use WebApplication\Entity\Client;

/**
 * Warehouse (docs/sklad-specifikacia.md): warehouses, stock items, batches (lots) with purchase price, documents.
 * Quantity of an item in a warehouse = Σ qty_left of its lots. Issues take the OLDEST lots first (FIFO) and carry their
 * purchase price; transfers keep a lot's price and receipt date; negative stock is never allowed.
 * Posted documents are final (a correction = an opposite document). Every document runs in one transaction with the
 * lots locked (SELECT … FOR UPDATE), so two people issuing the same goods at once can't go below zero.
 */
class Stock
{
    const EPS = 0.0005;

    /** @var Database */ private $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    private function t(string $n): string
    {
        return $this->db->table($n);
    }

    // ------------------------------------------------------------------ warehouses

    /** @param int[] $companyIds */
    public function warehouses(array $companyIds, bool $activeOnly = false): array
    {
        if (!$companyIds) {
            return [];
        }
        return $this->db->query("SELECT w.*, c.name AS company FROM {$this->t('warehouse')} w JOIN {$this->t('company')} c ON c.id = w.company_id
            WHERE w.company_id IN (" . implode(',', array_map('intval', $companyIds)) . ')' . ($activeOnly ? ' AND w.active = 1' : '')
            . ' ORDER BY c.name, w.active DESC, w.name');
    }

    public function warehouse(int $id): ?array
    {
        $r = $this->db->fetchOne("SELECT * FROM {$this->t('warehouse')} WHERE id = ?", [$id]);
        return $r === false ? null : $r;
    }

    public function saveWarehouse(?int $id, array $d): int
    {
        $vals = [$d['code'], $d['name'], $d['location'], $d['kind'], $d['active'] ? 1 : 0];
        if ($id) {
            $this->db->execute("UPDATE {$this->t('warehouse')} SET code = ?, name = ?, location = ?, kind = ?, active = ? WHERE id = ?",
                array_merge($vals, [$id]));
            return $id;
        }
        $this->db->execute("INSERT INTO {$this->t('warehouse')} (code, name, location, kind, active, company_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)",
            array_merge($vals, [(int)$d['company_id'], date('Y-m-d H:i:s')]));
        return $this->db->lastInsertId();
    }

    // ------------------------------------------------------------------ items

    const ITEM_FIELDS = ['sku', 'name', 'unit', 'ean', 'min_qty', 'note', 'active'];

    /** Items with total quantity and value over the given warehouses. @param int[] $companyIds */
    public function items(array $companyIds, string $q = '', ?int $warehouseId = null, bool $onlyInStock = false): array
    {
        if (!$companyIds) {
            return [];
        }
        $w = ['i.company_id IN (' . implode(',', array_map('intval', $companyIds)) . ')'];
        $p = [];
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $w[] = '(i.sku LIKE ? OR i.name LIKE ? OR i.ean = ?)';
            array_push($p, $like, $like, $q);
        }
        $lotWhere = 'l.item_id = i.id AND l.qty_left > 0' . ($warehouseId ? ' AND l.warehouse_id = ' . (int)$warehouseId : '');
        $sql = "SELECT i.*, c.name AS company,
                (SELECT COALESCE(SUM(l.qty_left), 0) FROM {$this->t('stock_lot')} l WHERE $lotWhere) AS qty,
                (SELECT COALESCE(SUM(l.qty_left * l.unit_cost), 0) FROM {$this->t('stock_lot')} l WHERE $lotWhere) AS value
            FROM {$this->t('stock_item')} i JOIN {$this->t('company')} c ON c.id = i.company_id
            WHERE " . implode(' AND ', $w) . ($onlyInStock ? ' HAVING qty > 0' : '') . ' ORDER BY i.active DESC, i.name';
        return $this->db->query($sql, $p);
    }

    public function item(int $id): ?array
    {
        $r = $this->db->fetchOne("SELECT * FROM {$this->t('stock_item')} WHERE id = ?", [$id]);
        return $r === false ? null : $r;
    }

    public function itemBySku(int $companyId, string $sku): ?array
    {
        $r = $this->db->fetchOne("SELECT * FROM {$this->t('stock_item')} WHERE company_id = ? AND sku = ?", [$companyId, $sku]);
        return $r === false ? null : $r;
    }

    public function saveItem(?int $id, int $companyId, array $d): int
    {
        $now = date('Y-m-d H:i:s');
        $vals = [$d['sku'], $d['name'], $d['unit'] ?: 'ks', $d['ean'], $d['min_qty'] === '' || $d['min_qty'] === null ? null : $d['min_qty'],
                 $d['note'], $d['active'] ? 1 : 0];
        if ($id) {
            $this->db->execute("UPDATE {$this->t('stock_item')} SET sku = ?, name = ?, unit = ?, ean = ?, min_qty = ?, note = ?, active = ?,
                updated_at = ? WHERE id = ?", array_merge($vals, [$now, $id]));
            return $id;
        }
        $this->db->execute("INSERT INTO {$this->t('stock_item')} (sku, name, unit, ean, min_qty, note, active, company_id, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", array_merge($vals, [$companyId, $now, $now]));
        return $this->db->lastInsertId();
    }

    /** Quantity per warehouse of one item: warehouse_id => [qty, value]. */
    public function levels(int $itemId): array
    {
        $out = [];
        foreach ($this->db->query("SELECT warehouse_id, SUM(qty_left) AS qty, SUM(qty_left * unit_cost) AS value
            FROM {$this->t('stock_lot')} WHERE item_id = ? AND qty_left > 0 GROUP BY warehouse_id", [$itemId]) as $r) {
            $out[(int)$r['warehouse_id']] = ['qty' => (float)$r['qty'], 'value' => (float)$r['value']];
        }
        return $out;
    }

    public function qty(int $itemId, int $warehouseId): float
    {
        return (float)$this->db->fetchValue("SELECT COALESCE(SUM(qty_left), 0) FROM {$this->t('stock_lot')}
            WHERE item_id = ? AND warehouse_id = ?", [$itemId, $warehouseId]);
    }

    /** Lots with something left, oldest first (FIFO order). */
    public function lots(int $itemId, ?int $warehouseId = null): array
    {
        return $this->db->query("SELECT l.*, w.name AS warehouse, d.number AS doc_number FROM {$this->t('stock_lot')} l
            JOIN {$this->t('warehouse')} w ON w.id = l.warehouse_id JOIN {$this->t('stock_doc')} d ON d.id = l.doc_id
            WHERE l.item_id = ? AND l.qty_left > 0" . ($warehouseId ? ' AND l.warehouse_id = ' . (int)$warehouseId : '') . '
            ORDER BY l.warehouse_id, l.received_on, l.id', [$itemId]);
    }

    /** Last purchase price of an item (for inventory surpluses). */
    public function lastCost(int $itemId): float
    {
        return (float)$this->db->fetchValue("SELECT unit_cost FROM {$this->t('stock_lot')} WHERE item_id = ? ORDER BY received_on DESC, id DESC LIMIT 1", [$itemId]);
    }

    public function moves(int $itemId, int $limit = 50): array
    {
        return $this->db->query("SELECT m.*, d.number, d.doc_type, d.doc_date, d.id AS doc_id, w.name AS warehouse
            FROM {$this->t('stock_move')} m JOIN {$this->t('stock_doc')} d ON d.id = m.doc_id JOIN {$this->t('warehouse')} w ON w.id = m.warehouse_id
            WHERE m.item_id = ? ORDER BY d.doc_date DESC, m.id DESC LIMIT " . (int)$limit, [$itemId]);
    }

    // ------------------------------------------------------------------ documents

    /** @param array $f type, warehouse, from, to; @param int[] $companyIds */
    public function docs(array $f, array $companyIds, int $limit = 200): array
    {
        if (!$companyIds) {
            return [];
        }
        $w = ['d.company_id IN (' . implode(',', array_map('intval', $companyIds)) . ')'];
        $p = [];
        if (!empty($f['type']) && isset(Numbering::STOCK_TYPES[$f['type']])) {
            $w[] = 'd.doc_type = ?';
            $p[] = $f['type'];
        }
        if (!empty($f['warehouse'])) {
            $w[] = '(d.warehouse_id = ? OR d.target_warehouse_id = ?)';
            array_push($p, (int)$f['warehouse'], (int)$f['warehouse']);
        }
        return $this->db->query("SELECT d.*, w.name AS warehouse, tw.name AS target_warehouse FROM {$this->t('stock_doc')} d
            JOIN {$this->t('warehouse')} w ON w.id = d.warehouse_id LEFT JOIN {$this->t('warehouse')} tw ON tw.id = d.target_warehouse_id
            WHERE " . implode(' AND ', $w) . ' ORDER BY d.doc_date DESC, d.id DESC LIMIT ' . (int)$limit, $p);
    }

    public function doc(int $id): ?array
    {
        $d = $this->db->fetchOne("SELECT d.*, w.name AS warehouse, tw.name AS target_warehouse FROM {$this->t('stock_doc')} d
            JOIN {$this->t('warehouse')} w ON w.id = d.warehouse_id LEFT JOIN {$this->t('warehouse')} tw ON tw.id = d.target_warehouse_id
            WHERE d.id = ?", [$id]);
        if ($d === false) {
            return null;
        }
        $d['moves'] = $this->db->query("SELECT m.*, i.sku, i.name, i.unit, w.name AS warehouse, l.lot_code, l.received_on
            FROM {$this->t('stock_move')} m JOIN {$this->t('stock_item')} i ON i.id = m.item_id
            JOIN {$this->t('warehouse')} w ON w.id = m.warehouse_id JOIN {$this->t('stock_lot')} l ON l.id = m.lot_id
            WHERE m.doc_id = ? ORDER BY m.id", [$id]);
        return $d;
    }

    /**
     * Posts a stock document. $h: company_id, doc_type, doc_date, warehouse_id, target_warehouse_id, partner, reference, note.
     * $lines: receipt — item_id, qty, unit_cost, lot_code, expires_on; issue/transfer — item_id, qty;
     *         adjust — item_id, counted (the quantity found), unit_cost (for a surplus, default = last price).
     * @return int document id
     * @throws \RuntimeException with a readable reason (not enough stock, wrong warehouse …) — nothing is saved then
     */
    public function post(array $h, array $lines, Client $user): int
    {
        $type = (string)$h['doc_type'];
        if (!isset(Numbering::STOCK_TYPES[$type])) {
            throw new \RuntimeException('Neznámy typ skladového dokladu.');
        }
        if (!$lines) {
            throw new \RuntimeException('Doklad nemá žiadne položky.');
        }
        $companyId = (int)$h['company_id'];
        return $this->db->transaction(function () use ($h, $lines, $user, $type, $companyId) {
            $wh = $this->warehouse((int)$h['warehouse_id']);
            if (!$wh || (int)$wh['company_id'] !== $companyId || !$wh['active']) {
                throw new \RuntimeException('Neplatný sklad.');
            }
            $target = null;
            if ($type === 'transfer') {
                $target = $this->warehouse((int)$h['target_warehouse_id']);
                if (!$target || (int)$target['company_id'] !== $companyId || !$target['active'] || (int)$target['id'] === (int)$wh['id']) {
                    throw new \RuntimeException('Prevodka potrebuje iný, aktívny cieľový sklad tej istej firmy.');
                }
            }
            $series = $this->series($companyId, $type);
            $num = Numbering::allocate($this->db, $series, $companyId, (string)$h['doc_date']);
            $this->db->execute("INSERT INTO {$this->t('stock_doc')} (company_id, doc_type, number, series_id, doc_date, warehouse_id,
                target_warehouse_id, partner, reference, note, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [$companyId, $type, $num['number'], (int)$series['id'], $h['doc_date'], (int)$wh['id'], $target ? (int)$target['id'] : null,
                 mb_substr((string)($h['partner'] ?? ''), 0, 190, 'UTF-8'), mb_substr((string)($h['reference'] ?? ''), 0, 100, 'UTF-8'),
                 (string)($h['note'] ?? ''), $user->getId(), date('Y-m-d H:i:s')]);
            $docId = $this->db->lastInsertId();
            $total = 0.0;
            foreach ($lines as $l) {
                $item = $this->item((int)$l['item_id']);
                if (!$item || (int)$item['company_id'] !== $companyId) {
                    throw new \RuntimeException('Neplatná skladová karta.');
                }
                $itemId = (int)$item['id'];
                switch ($type) {
                    case 'receipt':
                        $total += $this->addLot($docId, $itemId, (int)$wh['id'], (string)$h['doc_date'], (float)$l['qty'], (float)$l['unit_cost'],
                            (string)($l['lot_code'] ?? ''), $l['expires_on'] ?? null);
                        break;
                    case 'issue':
                        $total += $this->consume($docId, $item, $wh, (float)$l['qty']);
                        break;
                    case 'transfer':
                        foreach ($this->take($docId, $item, $wh, (float)$l['qty']) as $piece) {
                            $this->addLot($docId, $itemId, (int)$target['id'], $piece['received_on'], $piece['qty'], $piece['unit_cost'],
                                $piece['lot_code'], $piece['expires_on']);
                            $total += $piece['qty'] * $piece['unit_cost'];
                        }
                        break;
                    case 'adjust':
                        $diff = round((float)$l['counted'] - $this->lockedQty($itemId, (int)$wh['id']), 3);
                        if ($diff > self::EPS) {
                            $cost = isset($l['unit_cost']) && $l['unit_cost'] !== '' && $l['unit_cost'] !== null ? (float)$l['unit_cost'] : $this->lastCost($itemId);
                            $total += $this->addLot($docId, $itemId, (int)$wh['id'], (string)$h['doc_date'], $diff, $cost, 'inventúra', null);
                        } elseif ($diff < -self::EPS) {
                            $total -= $this->consume($docId, $item, $wh, -$diff);
                        }
                        break;
                }
            }
            $this->db->execute("UPDATE {$this->t('stock_doc')} SET total_cost = ? WHERE id = ?", [Calculator::fmt($total), $docId]);
            Audit::log($this->db, $user, 'stock_doc', $docId, 'create', $num['number']);
            return $docId;
        });
    }

    /** Series for a stock document type; created with the default format when the company has none yet. */
    private function series(int $companyId, string $type): array
    {
        $companies = new Companies($this->db);
        $s = $companies->defaultSeries($companyId, $type);
        if ($s === null) {
            $id = $companies->saveSeries(null, $companyId, ['doc_type' => $type, 'name' => Numbering::STOCK_TYPES[$type],
                'format' => Numbering::STOCK_DEFAULT_FORMATS[$type], 'vs_format' => '', 'reset_yearly' => 1, 'is_default' => 1, 'active' => 1]);
            $s = $companies->oneSeries($id);
        }
        return $s;
    }

    /** @return float value of the new lot */
    private function addLot(int $docId, int $itemId, int $warehouseId, string $date, float $qty, float $cost, string $lotCode, $expires): float
    {
        if ($qty <= self::EPS || $cost < 0) {
            throw new \RuntimeException('Množstvo musí byť kladné a cena nezáporná.');
        }
        $this->db->execute("INSERT INTO {$this->t('stock_lot')} (item_id, warehouse_id, doc_id, received_on, qty_received, qty_left, unit_cost, lot_code, expires_on)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)", [$itemId, $warehouseId, $docId, $date, Calculator::fmt($qty, 3), Calculator::fmt($qty, 3),
            Calculator::fmt($cost, 4), mb_substr($lotCode, 0, 64, 'UTF-8'), $expires ?: null]);
        $lotId = $this->db->lastInsertId();
        $this->move($docId, $itemId, $warehouseId, $lotId, $qty, $cost);
        return round($qty * $cost, 2);
    }

    /** Issue FIFO; @return float cost of what was issued */
    private function consume(int $docId, array $item, array $wh, float $qty): float
    {
        $cost = 0.0;
        foreach ($this->take($docId, $item, $wh, $qty) as $piece) {
            $cost += $piece['qty'] * $piece['unit_cost'];
        }
        return round($cost, 2);
    }

    /**
     * Takes $qty from the oldest lots (locked), writes the − movements.
     * @return array pieces: qty, unit_cost, received_on, lot_code, expires_on
     */
    private function take(int $docId, array $item, array $wh, float $qty): array
    {
        if ($qty <= self::EPS) {
            throw new \RuntimeException('Množstvo musí byť kladné.');
        }
        $lots = $this->db->query("SELECT * FROM {$this->t('stock_lot')} WHERE item_id = ? AND warehouse_id = ? AND qty_left > 0
            ORDER BY received_on, id FOR UPDATE", [(int)$item['id'], (int)$wh['id']]);
        $available = array_sum(array_map(function ($l) { return (float)$l['qty_left']; }, $lots));
        if ($available + self::EPS < $qty) {
            $n = function (float $x) { return rtrim(rtrim(number_format($x, 3, ',', ' '), '0'), ','); };
            throw new \RuntimeException('Nedostatok tovaru „' . $item['name'] . '“ v sklade ' . $wh['name'] . ': na sklade '
                . $n($available) . ' ' . $item['unit'] . ', požadované ' . $n($qty) . ' ' . $item['unit'] . '.');
        }
        $pieces = [];
        $left = $qty;
        foreach ($lots as $l) {
            if ($left <= self::EPS) {
                break;
            }
            $use = min($left, (float)$l['qty_left']);
            $this->db->execute("UPDATE {$this->t('stock_lot')} SET qty_left = qty_left - ? WHERE id = ?", [Calculator::fmt($use, 3), (int)$l['id']]);
            $this->move($docId, (int)$item['id'], (int)$wh['id'], (int)$l['id'], -$use, (float)$l['unit_cost']);
            $pieces[] = ['qty' => $use, 'unit_cost' => (float)$l['unit_cost'], 'received_on' => $l['received_on'],
                         'lot_code' => (string)$l['lot_code'], 'expires_on' => $l['expires_on']];
            $left = round($left - $use, 3);
        }
        return $pieces;
    }

    private function lockedQty(int $itemId, int $warehouseId): float
    {
        $rows = $this->db->query("SELECT qty_left FROM {$this->t('stock_lot')} WHERE item_id = ? AND warehouse_id = ? AND qty_left > 0 FOR UPDATE",
            [$itemId, $warehouseId]);
        return (float)array_sum(array_column($rows, 'qty_left'));
    }

    private function move(int $docId, int $itemId, int $warehouseId, int $lotId, float $qty, float $cost): void
    {
        $this->db->execute("INSERT INTO {$this->t('stock_move')} (doc_id, item_id, warehouse_id, lot_id, qty, unit_cost) VALUES (?, ?, ?, ?, ?, ?)",
            [$docId, $itemId, $warehouseId, $lotId, Calculator::fmt($qty, 3), Calculator::fmt($cost, 4)]);
    }
}
