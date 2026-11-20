<?php
namespace Panel\Expenses;

use Panel\Audit;
use WebApplication\Database\Database;
use WebApplication\Entity\Client;

/**
 * Received documents and costs (docs/sprava-firmy-specifikacia.md, module C): supplier invoices, receipts, credit
 * notes. Never deleted (10-year retention) — cancelled with a reason; every change in the audit log.
 * Amounts: total = what was paid / is to be paid; total_vat = deductible VAT (VAT payer only), total_net = total − VAT.
 */
class Expenses
{
    const DOC_TYPES = ['invoice' => 'Faktúra', 'receipt' => 'Pokladničný blok', 'credit_note' => 'Dobropis', 'other' => 'Iný doklad'];
    const PAYMENT_METHODS = ['transfer' => 'Prevodom', 'card' => 'Kartou', 'cash' => 'V hotovosti', 'collection' => 'Inkaso', 'other' => 'Iné'];
    const STATUSES = ['open' => 'Neuhradený', 'paid' => 'Uhradený', 'cancelled' => 'Stornovaný'];
    const KINDS = ['goods' => 'Tovar', 'order' => 'Náklady na objednávky', 'overhead' => 'Réžia'];
    const MAX_FILE = 10485760;   // 10 MB
    const FILE_TYPES = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/heic' => 'heic',
        'application/xml' => 'xml', 'text/xml' => 'xml'];

    /** @var Database */ private $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    private function t(string $name): string
    {
        return $this->db->table($name);
    }

    // ------------------------------------------------------------------ categories

    public function categories(bool $activeOnly = false): array
    {
        return $this->db->query("SELECT * FROM {$this->t('expense_category')}" . ($activeOnly ? ' WHERE active = 1' : '') . ' ORDER BY position, name');
    }

    /** @param array $rows [id|'' => ['name', 'kind', 'active']] — new rows have an empty id; nothing is deleted (old documents use them) */
    public function saveCategories(array $rows, ?Client $user): void
    {
        $this->db->transaction(function () use ($rows, $user) {
            $pos = 0;
            foreach ($rows as $r) {
                $name = trim((string)($r['name'] ?? ''));
                $kind = isset(self::KINDS[$r['kind'] ?? '']) ? $r['kind'] : 'overhead';
                $pos += 10;
                if (!empty($r['id'])) {
                    $this->db->execute("UPDATE {$this->t('expense_category')} SET name = ?, kind = ?, active = ?, position = ? WHERE id = ?",
                        [$name !== '' ? $name : 'Bez názvu', $kind, empty($r['active']) ? 0 : 1, $pos, (int)$r['id']]);
                } elseif ($name !== '') {
                    $this->db->execute("INSERT INTO {$this->t('expense_category')} (name, kind, active, position) VALUES (?, ?, 1, ?)", [$name, $kind, $pos]);
                }
            }
            Audit::log($this->db, $user, 'expense_category', 0, 'save', count($rows) . ' kategórií');
        });
    }

    // ------------------------------------------------------------------ documents

    /**
     * @param array $f companies (int[] — the employee's), company, from, to (delivery date), category, status, q, shop
     * @return array rows (page), total (count), sums (total, net, vat, open)
     */
    public function search(array $f, int $page = 1, int $perPage = 50): array
    {
        $w = ['e.company_id IN (' . (implode(',', array_map('intval', $f['companies'] ?: [0]))) . ')'];
        $p = [];
        foreach (['company' => 'e.company_id = ?', 'category' => 'e.category_id = ?', 'shop' => 'e.shop_id = ?'] as $k => $sql) {
            if (!empty($f[$k])) {
                $w[] = $sql;
                $p[] = (int)$f[$k];
            }
        }
        if (!empty($f['from'])) {
            $w[] = 'e.delivery_date >= ?';
            $p[] = $f['from'];
        }
        if (!empty($f['to'])) {
            $w[] = 'e.delivery_date <= ?';
            $p[] = $f['to'];
        }
        if (!empty($f['status'])) {
            if ($f['status'] === 'overdue') {
                $w[] = "e.status = 'open' AND e.due_date < ?";
                $p[] = date('Y-m-d');
            } else {
                $w[] = 'e.status = ?';
                $p[] = $f['status'];
            }
        } else {
            $w[] = "e.status <> 'cancelled'";
        }
        if (!empty($f['q'])) {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $f['q']) . '%';
            $w[] = '(e.supplier_name LIKE ? OR e.document_number LIKE ? OR e.variable_symbol LIKE ? OR e.supplier_ico LIKE ? OR e.note LIKE ?)';
            array_push($p, $like, $like, $like, $like, $like);
        }
        $where = 'WHERE ' . implode(' AND ', $w);
        $sum = $this->db->fetchOne("SELECT COUNT(*) AS n, COALESCE(SUM(e.total), 0) AS total, COALESCE(SUM(e.total_net), 0) AS net,
                COALESCE(SUM(e.total_vat), 0) AS vat, COALESCE(SUM(CASE WHEN e.status = 'open' THEN e.total ELSE 0 END), 0) AS open
            FROM {$this->t('expense')} e $where", $p);
        $rows = $this->db->query("SELECT e.*, c.name AS category, (SELECT COUNT(*) FROM {$this->t('expense_file')} f WHERE f.expense_id = e.id) AS files
            FROM {$this->t('expense')} e LEFT JOIN {$this->t('expense_category')} c ON c.id = e.category_id
            $where ORDER BY e.delivery_date DESC, e.id DESC LIMIT " . (int)$perPage . ' OFFSET ' . (int)(($page - 1) * $perPage), $p);
        return ['rows' => $rows, 'total' => (int)$sum['n'], 'sums' => $sum];
    }

    public function find(int $id): ?array
    {
        $e = $this->db->fetchOne("SELECT e.*, c.name AS category FROM {$this->t('expense')} e
            LEFT JOIN {$this->t('expense_category')} c ON c.id = e.category_id WHERE e.id = ?", [$id]);
        if ($e === false) {
            return null;
        }
        $e['files'] = $this->db->query("SELECT id, filename, mime, size, sha256, created_at FROM {$this->t('expense_file')} WHERE expense_id = ? ORDER BY id", [$id]);
        return $e;
    }

    /** Another (not cancelled) document of the same supplier with the same number — entered twice? */
    public function duplicate(int $companyId, string $ico, string $supplier, string $number, ?int $exceptId): ?array
    {
        if (trim($number) === '') {
            return null;
        }
        $row = $this->db->fetchOne("SELECT id, delivery_date, total FROM {$this->t('expense')} WHERE company_id = ? AND status <> 'cancelled'
            AND document_number = ? AND (" . ($ico !== '' ? 'supplier_ico = ?' : 'supplier_name = ?') . ') AND id <> ? LIMIT 1',
            [$companyId, $number, $ico !== '' ? $ico : $supplier, (int)$exceptId]);
        return $row === false ? null : $row;
    }

    const FIELDS = ['company_id', 'shop_id', 'category_id', 'doc_type', 'supplier_name', 'supplier_ico', 'supplier_dic', 'supplier_icdph',
        'supplier_street', 'supplier_zip', 'supplier_city', 'supplier_country', 'document_number', 'variable_symbol', 'supplier_iban',
        'issue_date', 'delivery_date', 'due_date', 'currency', 'total_net', 'total_vat', 'total', 'payment_method', 'note'];

    /** @return int id */
    public function save(?int $id, array $v, ?Client $user): int
    {
        $now = date('Y-m-d H:i:s');
        $vals = [];
        foreach (self::FIELDS as $f) {
            $vals[$f] = $v[$f] ?? null;
        }
        if ($id) {
            $old = $this->find($id);
            $set = implode(', ', array_map(function ($f) { return "$f = ?"; }, self::FIELDS));
            $this->db->execute("UPDATE {$this->t('expense')} SET $set, updated_at = ? WHERE id = ?", array_merge(array_values($vals), [$now, $id]));
            $changes = [];
            foreach (self::FIELDS as $f) {
                if ((string)$old[$f] !== (string)$vals[$f]) {
                    $changes[] = $f . ': ' . $old[$f] . ' → ' . $vals[$f];
                }
            }
            Audit::log($this->db, $user, 'expense', $id, 'update', mb_substr(implode('; ', $changes), 0, 1000, 'UTF-8'));
            return $id;
        }
        $cols = implode(', ', self::FIELDS);
        $marks = implode(', ', array_fill(0, count(self::FIELDS), '?'));
        // a document paid by card / cash is paid on the day it was issued
        $paid = in_array($vals['payment_method'], ['card', 'cash'], true);
        $this->db->execute("INSERT INTO {$this->t('expense')} ($cols, status, paid_on, created_by, created_at, updated_at) VALUES ($marks, ?, ?, ?, ?, ?)",
            array_merge(array_values($vals), [$paid ? 'paid' : 'open', $paid ? ($vals['issue_date'] ?: $vals['delivery_date']) : null,
                $user ? $user->getId() : null, $now, $now]));
        $newId = (int)$this->db->lastInsertId();
        Audit::log($this->db, $user, 'expense', $newId, 'create', $vals['supplier_name'] . ' ' . $vals['document_number'] . ' ' . $vals['total'] . ' ' . $vals['currency']);
        return $newId;
    }

    public function addFile(int $expenseId, string $name, string $mime, string $bytes, ?Client $user): int
    {
        $this->db->execute("INSERT INTO {$this->t('expense_file')} (expense_id, filename, mime, size, sha256, data, created_by, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)", [$expenseId, mb_substr($name, 0, 255, 'UTF-8'), $mime, strlen($bytes), hash('sha256', $bytes), $bytes,
            $user ? $user->getId() : null, date('Y-m-d H:i:s')]);
        $id = (int)$this->db->lastInsertId();
        Audit::log($this->db, $user, 'expense', $expenseId, 'file', $name . ' (' . strlen($bytes) . ' B)');
        return $id;
    }

    public function file(int $fileId): ?array
    {
        $f = $this->db->fetchOne("SELECT * FROM {$this->t('expense_file')} WHERE id = ?", [$fileId]);
        return $f === false ? null : $f;
    }

    /** @return array all files of the documents (for the accountant's package) */
    public function filesOf(array $expenseIds): array
    {
        $ids = array_values(array_filter(array_map('intval', $expenseIds)));
        return $ids ? $this->db->query("SELECT * FROM {$this->t('expense_file')} WHERE expense_id IN (" . implode(',', $ids) . ') ORDER BY expense_id, id') : [];
    }

    public function markPaid(int $id, string $date, string $ref, ?Client $user): void
    {
        $this->db->execute("UPDATE {$this->t('expense')} SET status = 'paid', paid_on = ?, paid_ref = ?, updated_at = ? WHERE id = ? AND status = 'open'",
            [$date, mb_substr($ref, 0, 255, 'UTF-8'), date('Y-m-d H:i:s'), $id]);
        Audit::log($this->db, $user, 'expense', $id, 'paid', $date . ($ref !== '' ? ' ' . $ref : ''));
    }

    public function markOpen(int $id, ?Client $user): void
    {
        $this->db->execute("UPDATE {$this->t('expense')} SET status = 'open', paid_on = NULL, paid_ref = '', bank_ref = NULL, updated_at = ? WHERE id = ? AND status = 'paid'",
            [date('Y-m-d H:i:s'), $id]);
        Audit::log($this->db, $user, 'expense', $id, 'unpaid');
    }

    public function cancel(int $id, string $reason, ?Client $user): void
    {
        $this->db->execute("UPDATE {$this->t('expense')} SET status = 'cancelled', cancel_reason = ?, updated_at = ? WHERE id = ? AND status <> 'cancelled'",
            [mb_substr($reason, 0, 255, 'UTF-8'), date('Y-m-d H:i:s'), $id]);
        Audit::log($this->db, $user, 'expense', $id, 'cancel', $reason);
    }

    public function history(int $id): array
    {
        return $this->db->query("SELECT created_at, client_name, action, details FROM {$this->t('audit')} WHERE entity = 'expense' AND entity_id = ?
            ORDER BY id DESC LIMIT 50", [$id]);
    }

    /** Documents for the accountant's package of a month: delivered in the month (or earlier and not sent yet), not cancelled. */
    public function forAccounting(int $companyId, string $from, string $to): array
    {
        return $this->db->query("SELECT e.*, c.name AS category FROM {$this->t('expense')} e LEFT JOIN {$this->t('expense_category')} c ON c.id = e.category_id
            WHERE e.company_id = ? AND e.status <> 'cancelled' AND e.accounting_package_id IS NULL AND e.delivery_date BETWEEN ? AND ?
            ORDER BY e.delivery_date, e.id", [$companyId, date('Y-m-d', strtotime($from . ' -1 year')), $to]);
    }

    public function byIds(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        return $ids ? $this->db->query("SELECT e.*, c.name AS category FROM {$this->t('expense')} e LEFT JOIN {$this->t('expense_category')} c
            ON c.id = e.category_id WHERE e.id IN (" . implode(',', $ids) . ') ORDER BY e.delivery_date, e.id') : [];
    }

    // ------------------------------------------------------------------ payment from the bank statement (C2)

    /** Unpaid documents of a company (for pairing outgoing payments). */
    public function openDocuments(int $companyId): array
    {
        return $this->db->query("SELECT id, supplier_name, supplier_iban, document_number, variable_symbol, total, currency, due_date, delivery_date
            FROM {$this->t('expense')} WHERE company_id = ? AND status = 'open' AND total > 0 ORDER BY due_date IS NULL, due_date, id", [$companyId]);
    }

    /**
     * Document paid by an outgoing payment (amount < 0): variable symbol (+ the same amount first), else the supplier's
     * IBAN with the same amount. @return array|null the document + 'by' => 'vs' | 'iban'
     */
    public static function matchPayment(array $entry, array $open): ?array
    {
        $amount = -(float)$entry['amount'];
        if ($amount <= 0) {
            return null;
        }
        $same = function ($d) use ($amount) { return abs((float)$d['total'] - $amount) < 0.005; };
        $vs = ltrim((string)$entry['vs'], '0');
        if ($vs !== '') {
            $found = array_values(array_filter($open, function ($d) use ($vs) { return ltrim((string)$d['variable_symbol'], '0') === $vs; }));
            foreach ($found as $d) {
                if ($same($d)) {
                    return $d + ['by' => 'vs'];
                }
            }
            if ($found) {
                return $found[0] + ['by' => 'vs'];
            }
        }
        $iban = strtoupper(str_replace(' ', '', (string)($entry['payer_iban'] ?? '')));
        if ($iban !== '') {
            foreach ($open as $d) {
                if (strtoupper((string)$d['supplier_iban']) === $iban && $same($d)) {
                    return $d + ['by' => 'iban'];
                }
            }
        }
        return null;
    }

    /** @param string[] $refs @return string[] bank movements already used to pay a received document */
    public function importedRefs(array $refs): array
    {
        $refs = array_values(array_filter($refs, 'strlen'));
        if (!$refs) {
            return [];
        }
        $q = implode(',', array_fill(0, count($refs), '?'));
        return array_column($this->db->query("SELECT bank_ref FROM {$this->t('expense')} WHERE bank_ref IN ($q)", $refs), 'bank_ref');
    }

    /** Mark an open document paid by a bank movement. false = it is not open any more. Throws on a movement used before. */
    public function payFromBank(int $id, string $date, string $bankRef, string $note, ?Client $user): bool
    {
        $ok = $this->db->affected("UPDATE {$this->t('expense')} SET status = 'paid', paid_on = ?, paid_ref = ?, bank_ref = ?, updated_at = ?
            WHERE id = ? AND status = 'open'", [$date, mb_substr($note, 0, 255, 'UTF-8'), $bankRef !== '' ? $bankRef : null, date('Y-m-d H:i:s'), $id]) === 1;
        if ($ok) {
            Audit::log($this->db, $user, 'expense', $id, 'paid', $date . ' z výpisu' . ($note !== '' ? ': ' . $note : ''));
        }
        return $ok;
    }

    /** A new document from an outgoing movement that has no document yet (bank fee, card payment …) — paid, without a file. */
    public function createFromBank(int $companyId, array $entry, int $categoryId, ?Client $user): int
    {
        $amount = round(-(float)$entry['amount'], 2);
        $name = trim((string)$entry['payer']) ?: (trim((string)$entry['message']) ?: 'Platba z účtu');
        $id = $this->save(null, ['company_id' => $companyId, 'shop_id' => null, 'category_id' => $categoryId, 'doc_type' => 'other',
            'supplier_name' => mb_substr($name, 0, 255, 'UTF-8'), 'supplier_ico' => '', 'supplier_dic' => '', 'supplier_icdph' => '',
            'supplier_street' => '', 'supplier_zip' => '', 'supplier_city' => '', 'supplier_country' => 'SK', 'document_number' => '',
            'variable_symbol' => (string)$entry['vs'], 'supplier_iban' => mb_substr(strtoupper((string)($entry['payer_iban'] ?? '')), 0, 34, 'UTF-8'),
            'issue_date' => $entry['date'], 'delivery_date' => $entry['date'], 'due_date' => null, 'currency' => (string)($entry['currency'] ?: 'EUR'),
            'total_net' => $amount, 'total_vat' => 0, 'total' => $amount, 'payment_method' => 'transfer',
            'note' => trim('Z výpisu: ' . (string)$entry['message'])], $user);
        $this->payFromBank($id, (string)$entry['date'], (string)$entry['ref'], 'výpis ' . $entry['date'], $user);
        return $id;
    }

    // ------------------------------------------------------------------ recurring costs (C2)

    const INTERVALS = [1 => 'mesačne', 3 => 'štvrťročne', 6 => 'polročne', 12 => 'ročne'];
    const RECURRING_FIELDS = ['company_id', 'shop_id', 'category_id', 'name', 'supplier_name', 'supplier_ico', 'supplier_dic', 'supplier_icdph',
        'supplier_street', 'supplier_zip', 'supplier_city', 'supplier_country', 'supplier_iban', 'variable_symbol', 'currency', 'total', 'total_vat',
        'payment_method', 'interval_months', 'due_days', 'day', 'next_date', 'end_date', 'active', 'note'];

    public function recurringList(array $companyIds): array
    {
        $ids = implode(',', array_map('intval', $companyIds ?: [0]));
        return $this->db->query("SELECT r.*, c.name AS category FROM {$this->t('expense_recurring')} r
            LEFT JOIN {$this->t('expense_category')} c ON c.id = r.category_id
            WHERE r.company_id IN ($ids) ORDER BY r.active DESC, r.next_date, r.name");
    }

    public function recurring(int $id): ?array
    {
        $r = $this->db->fetchOne("SELECT * FROM {$this->t('expense_recurring')} WHERE id = ?", [$id]);
        return $r === false ? null : $r;
    }

    public function saveRecurring(?int $id, array $v, ?Client $user): int
    {
        $vals = [];
        foreach (self::RECURRING_FIELDS as $f) {
            $vals[$f] = $v[$f] ?? null;
        }
        $now = date('Y-m-d H:i:s');
        if ($id) {
            $set = implode(', ', array_map(function ($f) { return "`$f` = ?"; }, self::RECURRING_FIELDS));
            $this->db->execute("UPDATE {$this->t('expense_recurring')} SET $set, updated_at = ? WHERE id = ?", array_merge(array_values($vals), [$now, $id]));
            Audit::log($this->db, $user, 'expense_recurring', $id, 'update', $vals['name'] . ' ' . $vals['total'] . ' ' . $vals['currency']);
            return $id;
        }
        $cols = implode(', ', array_map(function ($f) { return "`$f`"; }, self::RECURRING_FIELDS));
        $marks = implode(', ', array_fill(0, count(self::RECURRING_FIELDS), '?'));
        $this->db->execute("INSERT INTO {$this->t('expense_recurring')} ($cols, created_by, created_at, updated_at) VALUES ($marks, ?, ?, ?)",
            array_merge(array_values($vals), [$user ? $user->getId() : null, $now, $now]));
        $newId = (int)$this->db->lastInsertId();
        Audit::log($this->db, $user, 'expense_recurring', $newId, 'create', $vals['name'] . ' ' . $vals['total'] . ' ' . $vals['currency']);
        return $newId;
    }

    /** $months later on the given day of the month (31 → the last day of a shorter month, no drift). */
    public static function addMonths(string $date, int $months, int $day): string
    {
        $y = (int)substr($date, 0, 4);
        $m = (int)substr($date, 5, 2) + $months;
        $y += intdiv($m - 1, 12);
        $m = ($m - 1) % 12 + 1;
        $last = (int)date('t', mktime(0, 0, 0, $m, 1, $y));
        return sprintf('%04d-%02d-%02d', $y, $m, min(max(1, $day), $last));
    }

    /**
     * Create the documents of recurring costs that are due (next_date ≤ today), catching up missed periods (max 24 per
     * run). Safe against parallel runs (next_date is moved first). Called by the dashboard, the cron URL and the
     * accountant's package. @return int[] ids of the created documents
     */
    public function generateRecurring(?string $today = null, ?Client $user = null): array
    {
        $today = $today ?? date('Y-m-d');
        $created = [];
        foreach ($this->db->query("SELECT * FROM {$this->t('expense_recurring')} WHERE active = 1 AND next_date <= ? ORDER BY next_date, id", [$today]) as $r) {
            for ($n = 0; $n < 24 && $r['next_date'] <= $today && ($r['end_date'] === null || $r['next_date'] <= $r['end_date']); $n++) {
                $date = (string)$r['next_date'];
                $next = self::addMonths($date, (int)$r['interval_months'], (int)$r['day']);
                $id = $this->db->transaction(function () use ($r, $date, $next, $user) {
                    if ($this->db->affected("UPDATE {$this->t('expense_recurring')} SET next_date = ? WHERE id = ? AND next_date = ? AND active = 1",
                            [$next, (int)$r['id'], $date]) !== 1) {
                        return 0;   // another run took it
                    }
                    $total = (float)$r['total'];
                    $vat = (float)$r['total_vat'];
                    $id = $this->save(null, ['company_id' => (int)$r['company_id'], 'shop_id' => $r['shop_id'], 'category_id' => $r['category_id'],
                        'doc_type' => 'invoice', 'supplier_name' => $r['supplier_name'], 'supplier_ico' => $r['supplier_ico'], 'supplier_dic' => $r['supplier_dic'],
                        'supplier_icdph' => $r['supplier_icdph'], 'supplier_street' => $r['supplier_street'], 'supplier_zip' => $r['supplier_zip'],
                        'supplier_city' => $r['supplier_city'], 'supplier_country' => $r['supplier_country'], 'document_number' => '',
                        'variable_symbol' => $r['variable_symbol'], 'supplier_iban' => $r['supplier_iban'], 'issue_date' => $date, 'delivery_date' => $date,
                        'due_date' => (int)$r['due_days'] > 0 ? date('Y-m-d', strtotime($date . ' +' . (int)$r['due_days'] . ' days')) : $date,
                        'currency' => $r['currency'], 'total_net' => round($total - $vat, 2), 'total_vat' => $vat, 'total' => $total,
                        'payment_method' => $r['payment_method'],
                        'note' => trim('Opakovaný náklad „' . $r['name'] . '“ — doplňte číslo dokladu a priložte súbor, ak ho máte. ' . $r['note'])], $user);
                    $this->db->execute("UPDATE {$this->t('expense')} SET recurring_id = ? WHERE id = ?", [(int)$r['id'], $id]);
                    return $id;
                });
                if (!$id) {
                    break;
                }
                $created[] = $id;
                $r['next_date'] = $next;
            }
            if ($r['end_date'] !== null && $r['next_date'] > $r['end_date']) {
                $this->db->execute("UPDATE {$this->t('expense_recurring')} SET active = 0 WHERE id = ?", [(int)$r['id']]);
            }
        }
        return $created;
    }

    public function markSent(array $ids, int $packageId): void
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if ($ids) {
            $this->db->execute("UPDATE {$this->t('expense')} SET accounting_package_id = ? WHERE accounting_package_id IS NULL AND id IN ("
                . implode(',', $ids) . ')', [$packageId]);
        }
    }
}
