<?php
namespace Panel\Invoicing;

use Panel\Audit;
use WebApplication\Database\Database;
use WebApplication\Entity\Client;

/**
 * Invoices and credit notes.
 * Life cycle: draft (no number, editable, deletable) → issue() (number from the series, supplier snapshot, totals
 * recalculated with the company's VAT status on the delivery date) → issued: never changed or deleted;
 * corrections only by a credit note (dobropis) referring to the original invoice.
 */
class Invoices
{
    const PAYMENT_METHODS = [
        'transfer' => 'Bankovým prevodom',
        'card' => 'Platobnou kartou / online',
        'cod' => 'Dobierkou',
        'cash' => 'V hotovosti',
    ];
    const HEADER_FIELDS = ['company_id', 'doc_type', 'series_id', 'issue_date', 'delivery_date', 'due_date', 'currency',
        'payment_method', 'bank_account_id', 'customer_id', 'buyer_type', 'buyer_name', 'buyer_street', 'buyer_city',
        'buyer_zip', 'buyer_country', 'buyer_ico', 'buyer_dic', 'buyer_icdph', 'buyer_email', 'buyer_phone',
        'correction_reason', 'note', 'internal_note', 'issued_by_name'];
    const LINE_FIELDS = ['name', 'sku', 'quantity', 'unit', 'unit_price', 'vat_rate', 'vat_category'];
    /** note of the payment added automatically when a document paid by card / in cash is issued */
    const AUTO_PAYMENT_NOTE = 'uhradené pri vystavení';

    /** @var Database */ private $db;
    /** @var Companies */ private $companies;

    public function __construct(Database $db)
    {
        $this->db = $db;
        $this->companies = new Companies($db);
    }

    private function t(string $name): string
    {
        return $this->db->table($name);
    }

    public static function typeLabel(string $docType): string
    {
        return $docType === 'credit_note' ? 'Dobropis' : 'Faktúra';
    }

    // ------------------------------------------------------------------ reading

    /**
     * @param array $f company, doc_type, status, year, q
     * @param int[] $companyIds companies the employee may see
     * @return array{rows: array, total: int, sum: string}
     */
    public function search(array $f, array $companyIds, int $page = 1, int $perPage = 50): array
    {
        if (!$companyIds) {
            return ['rows' => [], 'total' => 0, 'sum' => '0.00'];
        }
        $w = ['i.company_id IN (' . implode(',', array_map('intval', $companyIds)) . ')'];
        $p = [];
        if (!empty($f['company'])) {
            $w[] = 'i.company_id = ?';
            $p[] = (int)$f['company'];
        }
        if (!empty($f['doc_type']) && isset(Numbering::TYPES[$f['doc_type']])) {
            $w[] = 'i.doc_type = ?';
            $p[] = $f['doc_type'];
        }
        if (!empty($f['status']) && in_array($f['status'], ['draft', 'issued'], true)) {
            $w[] = 'i.status = ?';
            $p[] = $f['status'];
        }
        if (!empty($f['year'])) {
            $w[] = 'i.issue_date BETWEEN ? AND ?';
            array_push($p, (int)$f['year'] . '-01-01', (int)$f['year'] . '-12-31');
        }
        // payment state (issued documents): unpaid = nothing/partly paid, overdue = unpaid after due date, paid
        $paidSql = "(SELECT COALESCE(SUM(pp.amount), 0) FROM {$this->t('invoice_payment')} pp WHERE pp.invoice_id = i.id)";
        if (!empty($f['payment']) && isset(self::PAYMENT_STATES[$f['payment']])) {
            $open = "ABS($paidSql) < ABS(i.total) - 0.005";
            $w[] = "i.status = 'issued'";
            if ($f['payment'] === 'paid') {
                $w[] = "NOT ($open)";
            } elseif ($f['payment'] === 'overdue') {
                $w[] = $open . ' AND i.due_date < ?';
                $p[] = date('Y-m-d');
            } else {
                $w[] = $open;
            }
        }
        $q = trim((string)($f['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $w[] = '(i.number LIKE ? OR i.buyer_name LIKE ? OR i.buyer_ico LIKE ? OR i.order_reference LIKE ? OR i.variable_symbol = ?)';
            array_push($p, $like, $like, $like, $like, $q);
        }
        $where = 'WHERE ' . implode(' AND ', $w);
        $from = "FROM {$this->t('invoice')} i JOIN {$this->t('company')} c ON c.id = i.company_id $where";
        $agg = $this->db->fetchOne("SELECT COUNT(*) AS n, COALESCE(SUM(CASE WHEN i.status = 'issued' THEN i.total END), 0) AS s $from", $p);
        $perPage = max(1, min(200, $perPage));
        $offset = max(0, ($page - 1) * $perPage);
        $rows = $this->db->query("SELECT i.id, i.company_id, c.name AS company, i.doc_type, i.status, i.number, i.issue_date, i.due_date,
                i.buyer_name, i.total, i.currency, i.order_reference, i.shop_connection_id, i.shop_order_id, $paidSql AS paid
            $from ORDER BY (i.status = 'draft') DESC, i.issue_date DESC, i.id DESC LIMIT $perPage OFFSET $offset", $p);
        return ['rows' => $rows, 'total' => (int)$agg['n'], 'sum' => Calculator::fmt((float)$agg['s'])];
    }

    public function find(int $id): ?array
    {
        $inv = $this->db->fetchOne("SELECT * FROM {$this->t('invoice')} WHERE id = ?", [$id]);
        if ($inv === false) {
            return null;
        }
        $inv['lines'] = $this->lines($id);
        $inv['supplier'] = $inv['supplier_json'] ? json_decode($inv['supplier_json'], true) : null;
        $inv['legal'] = !empty($inv['legal_json']) ? json_decode($inv['legal_json'], true) : null;   // texts frozen at issue
        $inv['breakdown'] = Calculator::compute($inv['lines'], (bool)$inv['vat_payer'])['breakdown'];
        return $inv;
    }

    public function lines(int $invoiceId): array
    {
        return $this->db->query("SELECT * FROM {$this->t('invoice_line')} WHERE invoice_id = ? ORDER BY position, id", [$invoiceId]);
    }

    /** @param int $source order source (0 = shop tables in this database, N = shop connection N — see Shop\OrderSource) */
    public function findByOrder(int $source, int $shopOrderId): ?array
    {
        $row = $this->db->fetchOne("SELECT id, number, status, doc_type FROM {$this->t('invoice')} WHERE shop_connection_id = ? AND shop_order_id = ?",
            [$source, $shopOrderId]);
        return $row === false ? null : $row;
    }

    /** Credit notes referring to an invoice. */
    public function creditNotesOf(int $invoiceId): array
    {
        return $this->db->query("SELECT id, number, status, total, issue_date FROM {$this->t('invoice')} WHERE original_invoice_id = ? ORDER BY id", [$invoiceId]);
    }

    /**
     * Issued documents of a company for an export (by issue date), with lines.
     * @return array full documents as from find()
     */
    public function forExport(int $companyId, string $from, string $to, bool $onlyNotExported): array
    {
        $ids = $this->db->query("SELECT id FROM {$this->t('invoice')} WHERE company_id = ? AND status = 'issued'
            AND issue_date BETWEEN ? AND ?" . ($onlyNotExported ? ' AND omega_exported_at IS NULL' : '') . '
            ORDER BY doc_type, issue_date, number', [$companyId, $from, $to]);
        $out = [];
        foreach ($ids as $r) {
            $out[] = $this->find((int)$r['id']);
        }
        return $out;
    }

    /** @param int[] $ids */
    public function markExported(array $ids): void
    {
        if ($ids) {
            $this->db->execute("UPDATE {$this->t('invoice')} SET omega_exported_at = ? WHERE id IN ("
                . implode(',', array_map('intval', $ids)) . ')', [date('Y-m-d H:i:s')]);
        }
    }

    // ------------------------------------------------------------------ payments (úhrady)

    const PAYMENT_STATES = ['paid' => 'uhradená', 'partial' => 'čiastočne uhradená', 'unpaid' => 'neuhradená', 'overdue' => 'po splatnosti'];

    public function payments(int $invoiceId): array
    {
        return $this->db->query("SELECT * FROM {$this->t('invoice_payment')} WHERE invoice_id = ? ORDER BY paid_on, id", [$invoiceId]);
    }

    public function paidSum(int $invoiceId): float
    {
        return (float)$this->db->fetchValue("SELECT COALESCE(SUM(amount), 0) FROM {$this->t('invoice_payment')} WHERE invoice_id = ?", [$invoiceId]);
    }

    /** paid | partial | unpaid | overdue — works for credit notes too (negative total, refunds negative). */
    public static function paymentState(array $inv, float $paid, ?string $today = null): string
    {
        $total = abs((float)$inv['total']);
        $paid = (float)$inv['total'] < 0 ? -$paid : $paid;
        if ($total < 0.005 || $paid >= $total - 0.005) {
            return 'paid';
        }
        if ($paid > 0.005) {
            return 'partial';
        }
        return ($inv['due_date'] < ($today ?? date('Y-m-d'))) ? 'overdue' : 'unpaid';
    }

    public function addPayment(int $invoiceId, string $date, float $amount, string $method, string $note, ?Client $user, ?string $bankRef = null): int
    {
        $this->db->execute("INSERT INTO {$this->t('invoice_payment')} (invoice_id, paid_on, amount, method, note, created_by, created_at, bank_ref)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)", [$invoiceId, $date, Calculator::fmt($amount), $method, mb_substr($note, 0, 255, 'UTF-8'),
            $user ? $user->getId() : 0, date('Y-m-d H:i:s'), $bankRef !== null && $bankRef !== '' ? $bankRef : null]);
        $id = $this->db->lastInsertId();
        Audit::log($this->db, $user, 'invoice', $invoiceId, 'payment', Calculator::fmt($amount) . ' (' . $date . ')');
        return $id;
    }

    /** Issued, not fully paid documents of a company: id, number, variable_symbol, total, paid, due_date, buyer_name. */
    public function openDocuments(int $companyId): array
    {
        return $this->db->query("SELECT i.id, i.number, i.variable_symbol, i.total, i.due_date, i.buyer_name,
                (SELECT COALESCE(SUM(p.amount), 0) FROM {$this->t('invoice_payment')} p WHERE p.invoice_id = i.id) AS paid
            FROM {$this->t('invoice')} i WHERE i.company_id = ? AND i.status = 'issued'
            HAVING ABS(paid) < ABS(total) - 0.005 ORDER BY i.issue_date, i.id", [$companyId]);
    }

    /**
     * Document a bank transaction pays: same variable symbol (leading zeros ignored), still open; when several match,
     * the one whose remaining amount equals the transaction. @param array $open from openDocuments()
     */
    public static function matchTransaction(array $entry, array $open): ?array
    {
        $vs = ltrim((string)$entry['vs'], '0');
        if ($vs === '') {
            return null;
        }
        $found = array_values(array_filter($open, function ($d) use ($vs) { return ltrim((string)$d['variable_symbol'], '0') === $vs; }));
        foreach ($found as $d) {
            if (abs(((float)$d['total'] - (float)$d['paid']) - (float)$entry['amount']) < 0.005) {
                return $d;
            }
        }
        return $found[0] ?? null;
    }

    /** @param string[] $refs @return string[] the refs already imported */
    public function importedRefs(array $refs): array
    {
        $refs = array_values(array_filter($refs, 'strlen'));
        if (!$refs) {
            return [];
        }
        $q = implode(',', array_fill(0, count($refs), '?'));
        return array_column($this->db->query("SELECT bank_ref FROM {$this->t('invoice_payment')} WHERE bank_ref IN ($q)", $refs), 'bank_ref');
    }

    public function deletePayment(int $invoiceId, int $paymentId, Client $user): bool
    {
        $row = $this->db->fetchOne("SELECT amount, paid_on FROM {$this->t('invoice_payment')} WHERE id = ? AND invoice_id = ?", [$paymentId, $invoiceId]);
        if ($row === false) {
            return false;
        }
        $this->db->execute("DELETE FROM {$this->t('invoice_payment')} WHERE id = ?", [$paymentId]);
        Audit::log($this->db, $user, 'invoice', $invoiceId, 'payment_delete', $row['amount'] . ' (' . $row['paid_on'] . ')');
        return true;
    }

    /** E-mails sent with a document. */
    public function emails(int $invoiceId): array
    {
        return $this->db->query("SELECT * FROM {$this->t('invoice_email')} WHERE invoice_id = ? ORDER BY id DESC", [$invoiceId]);
    }

    /** @param string $kind invoice | reminder */
    public function logEmail(int $invoiceId, array $recipients, string $subject, string $status, string $error, ?Client $user, string $kind = 'invoice'): void
    {
        $this->db->execute("INSERT INTO {$this->t('invoice_email')} (invoice_id, recipients, subject, status, error, client_id, created_at, kind)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)", [$invoiceId, mb_substr(implode(', ', $recipients), 0, 500, 'UTF-8'), mb_substr($subject, 0, 255, 'UTF-8'),
            $status, mb_substr($error, 0, 500, 'UTF-8'), $user ? $user->getId() : 0, date('Y-m-d H:i:s'), $kind]);
        Audit::log($this->db, $user, 'invoice', $invoiceId, $status === 'sent' ? ($kind === 'reminder' ? 'remind' : 'send') : 'send_failed',
            implode(', ', $recipients));
    }

    /** Archived PDF of an issued document: ['pdf', 'sha256', 'size', 'created_at'] or null. */
    public function storedPdf(int $invoiceId): ?array
    {
        $row = $this->db->fetchOne("SELECT pdf, sha256, size, created_at FROM {$this->t('invoice_pdf')} WHERE invoice_id = ?", [$invoiceId]);
        return $row === false ? null : $row;
    }

    /** Stores the PDF of an issued document once (INSERT IGNORE — an archived PDF is never replaced). */
    public function storePdf(int $invoiceId, string $pdf): void
    {
        $this->db->execute("INSERT IGNORE INTO {$this->t('invoice_pdf')} (invoice_id, pdf, sha256, size, created_at) VALUES (?, ?, ?, ?, ?)",
            [$invoiceId, $pdf, hash('sha256', $pdf), strlen($pdf), date('Y-m-d H:i:s')]);
    }

    /** Years that have documents (for the filter). */
    public function years(array $companyIds): array
    {
        if (!$companyIds) {
            return [];
        }
        return array_map('intval', array_column($this->db->query("SELECT DISTINCT YEAR(issue_date) AS y FROM {$this->t('invoice')}
            WHERE company_id IN (" . implode(',', array_map('intval', $companyIds)) . ') ORDER BY y DESC'), 'y'));
    }

    // ------------------------------------------------------------------ drafts

    /** Default header of a new draft for a company. */
    public function defaults(int $companyId, string $docType, Client $user): array
    {
        $c = $this->companies->find($companyId);
        $today = date('Y-m-d');
        $series = $this->companies->defaultSeries($companyId, $docType);
        $banks = $this->companies->banks($companyId, true);
        $h = array_fill_keys(self::HEADER_FIELDS, '');
        return array_merge($h, [
            'company_id' => $companyId, 'doc_type' => $docType, 'series_id' => $series ? (int)$series['id'] : null,
            'issue_date' => $today, 'delivery_date' => $today,
            'due_date' => date('Y-m-d', strtotime('+' . (int)($c['due_days'] ?? 14) . ' days')),
            'currency' => 'EUR', 'payment_method' => 'transfer', 'bank_account_id' => $banks ? (int)$banks[0]['id'] : null,
            'customer_id' => null, 'buyer_type' => 'b2c', 'buyer_country' => 'SK',
            'note' => (string)($c['invoice_note'] ?? ''), 'issued_by_name' => $user->getName(),
        ]);
    }

    /**
     * Creates or updates a draft. Totals are calculated here.
     * @return int invoice id
     */
    public function saveDraft(?int $id, array $h, array $lines, Client $user, array $extra = []): int
    {
        return $this->db->transaction(function () use ($id, $h, $lines, $user, $extra) {
            if ($id) {
                $status = $this->db->fetchValue("SELECT status FROM {$this->t('invoice')} WHERE id = ? FOR UPDATE", [$id]);
                if ($status !== 'draft') {
                    throw new \RuntimeException('Vystavený doklad sa nedá meniť.');
                }
            }
            $vat = $this->companies->vatOn((int)$h['company_id'], (string)$h['delivery_date']);
            $calc = Calculator::compute($lines, $vat['vat_payer']);
            $now = date('Y-m-d H:i:s');
            $data = [];
            foreach (self::HEADER_FIELDS as $f) {
                $v = $h[$f] ?? null;
                if (in_array($f, ['series_id', 'bank_account_id', 'customer_id'], true)) {
                    $v = $v ? (int)$v : null;
                }
                $data[$f] = $v === null ? null : (is_int($v) ? $v : (string)$v);
            }
            $data += ['vat_payer' => $vat['vat_payer'] ? 1 : 0, 'total_net' => $calc['net'], 'total_vat' => $calc['vat'],
                      'total' => $calc['total'], 'updated_at' => $now];
            foreach (['shop_id', 'shop_connection_id', 'shop_order_id', 'order_reference', 'original_invoice_id'] as $f) {
                if (array_key_exists($f, $extra)) {
                    $data[$f] = $extra[$f];
                }
            }
            if ($id) {
                $set = implode(', ', array_map(function ($f) { return "$f = ?"; }, array_keys($data)));
                $this->db->execute("UPDATE {$this->t('invoice')} SET $set WHERE id = ?", array_merge(array_values($data), [$id]));
                $this->db->execute("DELETE FROM {$this->t('invoice_line')} WHERE invoice_id = ?", [$id]);
            } else {
                $data += ['status' => 'draft', 'created_by' => $user->getId(), 'created_at' => $now];
                $cols = implode(', ', array_keys($data));
                $q = implode(', ', array_fill(0, count($data), '?'));
                $this->db->execute("INSERT INTO {$this->t('invoice')} ($cols) VALUES ($q)", array_values($data));
                $id = $this->db->lastInsertId();
            }
            $this->insertLines($id, $calc['lines']);
            Audit::log($this->db, $user, 'invoice', $id, isset($data['created_at']) ? 'create' : 'update');
            return $id;
        });
    }

    private function insertLines(int $invoiceId, array $lines): void
    {
        $pos = 0;
        foreach ($lines as $l) {
            $this->db->execute("INSERT INTO {$this->t('invoice_line')}
                (invoice_id, position, name, sku, quantity, unit, unit_price, vat_rate, vat_category, net, vat, gross)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [$invoiceId, ++$pos, (string)$l['name'], (string)($l['sku'] ?? ''), Calculator::fmt((float)$l['quantity'], 3),
                 (string)($l['unit'] ?? 'ks'), Calculator::fmt((float)$l['unit_price'], 4), $l['vat_rate'], $l['vat_category'],
                 $l['net'], $l['vat'], $l['gross']]);
        }
    }

    public function deleteDraft(int $id, Client $user): void
    {
        $this->db->transaction(function () use ($id, $user) {
            $n = $this->db->affected("DELETE FROM {$this->t('invoice')} WHERE id = ? AND status = 'draft'", [$id]);
            if ($n !== 1) {
                throw new \RuntimeException('Vystavený doklad sa nedá zmazať.');
            }
            $this->db->execute("DELETE FROM {$this->t('invoice_line')} WHERE invoice_id = ?", [$id]);
            Audit::log($this->db, $user, 'invoice', $id, 'delete');
        });
    }

    /**
     * Why an issued document can NOT go back to a draft (empty = it can). Only for a document issued by mistake that never
     * left the company (owner 2026-11-21: a test invoice FA2026/000001 got a number the shop had already used): it is the
     * last one issued in its number series (no gap in the series), was never sent by e-mail, is not in a package for the
     * accountant nor in an Omega export, has no credit note and no payment except the automatic one (card / cash).
     */
    public function unissueErrors(array $inv): array
    {
        if ($inv['status'] !== 'issued') {
            return ['Doklad nie je vystavený.'];
        }
        $id = (int)$inv['id'];
        $err = [];
        $later = $this->db->fetchValue("SELECT number FROM {$this->t('invoice')} WHERE company_id = ? AND series_id = ? AND status = 'issued'
            AND id <> ? AND (issued_at > ? OR (issued_at = ? AND id > ?)) ORDER BY issued_at DESC, id DESC LIMIT 1",
            [(int)$inv['company_id'], (int)$inv['series_id'], $id, $inv['issued_at'], $inv['issued_at'], $id]);
        if ($later !== null) {
            $err[] = 'Po ňom bol vystavený doklad ' . $later . ' z toho istého číselného radu (v rade by chýbalo číslo) — opravte ho dobropisom.';
        }
        $sent = (int)$this->db->fetchValue("SELECT COUNT(*) FROM {$this->t('invoice_email')} WHERE invoice_id = ? AND status = 'sent'", [$id]);
        if ($sent) {
            $err[] = 'Bol poslaný e-mailom — odberateľ ho má, opravte ho dobropisom.';
        }
        if (!empty($inv['accounting_package_id'])) {
            $err[] = 'Je v balíku pre účtovníka.';
        }
        if (!empty($inv['omega_exported_at'])) {
            $err[] = 'Bol exportovaný do Omegy.';
        }
        if ($this->creditNotesOf($id)) {
            $err[] = 'Má dobropis.';
        }
        foreach ($this->payments($id) as $p) {
            if ($p['note'] !== self::AUTO_PAYMENT_NOTE) {
                $err[] = 'Má zapísanú úhradu — najprv ju zmažte.';
                break;
            }
        }
        return $err;
    }

    /**
     * Returns a document issued by mistake to a draft (administrators; conditions: unissueErrors): the number is released
     * (the series counter goes back when this was its last number), the archived PDF and the automatic payment are removed,
     * the frozen supplier / legal texts / template are cleared (set again at the next issue). The audit log keeps the
     * number, the reason and the SHA-256 of the removed PDF. @return string the released number
     */
    public function unissue(int $id, string $reason, Client $user): string
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new \InvalidArgumentException('Zadajte dôvod.');
        }
        return $this->db->transaction(function () use ($id, $reason, $user) {
            $status = $this->db->fetchValue("SELECT status FROM {$this->t('invoice')} WHERE id = ? FOR UPDATE", [$id]);
            if ($status !== 'issued') {
                throw new \RuntimeException('Doklad nie je vystavený.');
            }
            $inv = $this->find($id);
            $err = $this->unissueErrors($inv);
            if ($err) {
                throw new \RuntimeException(implode(' ', $err));
            }
            $back = null;
            $series = $inv['series_id'] ? $this->companies->oneSeries((int)$inv['series_id']) : null;
            if ($series) {
                $year = Numbering::counterYear($series, $inv['issue_date']);
                $next = (int)$this->db->fetchValue("SELECT next_number FROM {$this->t('series_counter')} WHERE series_id = ? AND year = ? FOR UPDATE",
                    [(int)$series['id'], $year]);
                if ($next > 1 && Numbering::format((string)$series['format'], $next - 1, $inv['issue_date']) === $inv['number']) {
                    Numbering::setNext($this->db, $series, $year, $next - 1);
                    $back = $next - 1;
                }
            }
            $sha = $this->db->fetchValue("SELECT sha256 FROM {$this->t('invoice_pdf')} WHERE invoice_id = ?", [$id]);
            $this->db->execute("DELETE FROM {$this->t('invoice_pdf')} WHERE invoice_id = ?", [$id]);
            $this->db->execute("DELETE FROM {$this->t('invoice_payment')} WHERE invoice_id = ? AND note = ?", [$id, self::AUTO_PAYMENT_NOTE]);
            $this->db->execute("UPDATE {$this->t('invoice')} SET status = 'draft', number = NULL, variable_symbol = '', supplier_json = NULL,
                bank_account_name = '', iban = '', bic = '', legal_json = NULL, template = '', issued_by = NULL, issued_at = NULL, updated_at = ?
                WHERE id = ?", [date('Y-m-d H:i:s'), $id]);
            Audit::log($this->db, $user, 'invoice', $id, 'unissue', $inv['number'] . ' — ' . mb_substr($reason, 0, 300, 'UTF-8')
                . ($sha ? ' (zmazané PDF, SHA-256 ' . $sha . ')' : '') . ($back !== null ? '; číselný rad späť na ' . $back : ''));
            return (string)$inv['number'];
        });
    }

    /** Problems that prevent issuing (empty = can be issued). */
    public function issueErrors(array $inv): array
    {
        $err = [];
        if (!$inv['lines']) {
            $err[] = 'Doklad nemá žiadne položky.';
        }
        foreach ($inv['lines'] as $l) {
            if (trim($l['name']) === '') {
                $err[] = 'Každá položka musí mať názov.';
                break;
            }
        }
        if (trim($inv['buyer_name']) === '' || trim($inv['buyer_street'] . $inv['buyer_city']) === '') {
            $err[] = 'Chýba meno alebo adresa odberateľa.';
        }
        if (!$inv['series_id']) {
            $err[] = 'Nie je zvolený číselný rad (nastavte ho vo Firmách).';
        }
        if ($inv['payment_method'] === 'transfer' && !$inv['bank_account_id']) {
            $err[] = 'Pri platbe prevodom treba bankový účet.';
        }
        if ($inv['due_date'] < $inv['issue_date']) {
            $err[] = 'Dátum splatnosti je pred dátumom vystavenia.';
        }
        if ($inv['doc_type'] === 'credit_note' && (!$inv['original_invoice_id'] || trim($inv['correction_reason']) === '')) {
            $err[] = 'Dobropis musí mať pôvodnú faktúru a dôvod opravy.';
        }
        if ($inv['doc_type'] === 'invoice' && (float)$inv['total'] < 0) {
            $err[] = 'Faktúra nemôže mať zápornú sumu — použite dobropis.';
        }
        // legal rules that depend on data with validity dates (Nastavenia → Legislatíva)
        $company = $this->companies->find((int)$inv['company_id']);
        $country = $company ? (string)$company['country'] : 'SK';
        if ($this->companies->vatOn((int)$inv['company_id'], (string)$inv['delivery_date'])['vat_payer']) {
            $legal = new Legislation($this->db);
            $bad = [];
            foreach ($inv['lines'] as $l) {
                if (($l['vat_category'] ?? 'S') === 'S' && !$legal->isValidRate($country, (string)$inv['delivery_date'], (float)$l['vat_rate'])) {
                    $bad[Calculator::fmt((float)$l['vat_rate'])] = true;
                }
                if (in_array($l['vat_category'] ?? '', ['AE', 'K'], true) && trim((string)$inv['buyer_icdph']) === '') {
                    $err[] = 'Pri prenesení daňovej povinnosti / dodaní do EÚ musí mať odberateľ IČ DPH.';
                }
            }
            if ($bad) {
                $err[] = 'Sadzba DPH ' . implode(', ', array_keys($bad)) . ' % neplatí k dátumu dodania ' . $inv['delivery_date']
                    . ' (platné sadzby: Nastavenia → Legislatíva).';
            }
        }
        return array_values(array_unique($err));
    }

    /** Things that don't block issuing but should be checked (missing legal data, deadlines). */
    public function issueWarnings(array $inv): array
    {
        $w = [];
        $company = $this->companies->find((int)$inv['company_id']);
        if ($company && trim((string)$company['registration']) === '' && strlen((string)$company['ico']) >= 8) {
            $w[] = 'Firma nemá vyplnený zápis v obchodnom registri (Firmy) — povinný údaj na faktúre pre firmy zapísané v OR.';
        }
        $payer = $this->companies->vatOn((int)$inv['company_id'], (string)$inv['delivery_date'])['vat_payer'];
        if ($payer && strtotime($inv['issue_date']) > strtotime($inv['delivery_date'] . ' +15 days')) {
            $w[] = 'Platiteľ DPH má faktúru vyhotoviť do 15 dní od dodania — dátum vystavenia je neskôr.';
        }
        return $w;
    }

    /** Issues a draft: number, supplier snapshot, final totals. Atomic. */
    public function issue(int $id, Client $user): array
    {
        return $this->db->transaction(function () use ($id, $user) {
            $status = $this->db->fetchValue("SELECT status FROM {$this->t('invoice')} WHERE id = ? FOR UPDATE", [$id]);
            if ($status !== 'draft') {
                throw new \RuntimeException('Doklad už je vystavený.');
            }
            $inv = $this->find($id);
            $err = $this->issueErrors($inv);
            if ($err) {
                throw new \RuntimeException(implode(' ', $err));
            }
            $series = $this->companies->oneSeries((int)$inv['series_id']);
            if ($series === null || (int)$series['company_id'] !== (int)$inv['company_id'] || $series['doc_type'] !== $inv['doc_type']) {
                throw new \RuntimeException('Číselný rad nepatrí k tejto firme alebo typu dokladu.');
            }
            $supplier = $this->companies->supplierSnapshot((int)$inv['company_id'], $inv['delivery_date']);
            $calc = Calculator::compute($inv['lines'], $supplier['vat_payer']);
            $this->db->execute("DELETE FROM {$this->t('invoice_line')} WHERE invoice_id = ?", [$id]);
            $this->insertLines($id, $calc['lines']);

            $bank = $inv['bank_account_id'] ? $this->companies->bank((int)$inv['bank_account_id']) : null;
            $num = Numbering::allocate($this->db, $series, (int)$inv['company_id'], $inv['issue_date']);
            $now = date('Y-m-d H:i:s');
            // freeze what the law said on the issue date: texts + print template version
            $texts = (new Legislation($this->db))->textsOn($inv['issue_date']);
            $this->db->execute("UPDATE {$this->t('invoice')} SET status = 'issued', number = ?, variable_symbol = ?, vat_payer = ?,
                supplier_json = ?, bank_account_name = ?, iban = ?, bic = ?, total_net = ?, total_vat = ?, total = ?,
                legal_json = ?, template = ?, issued_by = ?, issued_at = ?, updated_at = ? WHERE id = ?",
                [$num['number'], $num['vs'], $supplier['vat_payer'] ? 1 : 0, json_encode($supplier, JSON_UNESCAPED_UNICODE),
                 $bank ? $bank['account_name'] : '', $bank ? $bank['iban'] : '', $bank ? $bank['bic'] : '',
                 $calc['net'], $calc['vat'], $calc['total'], json_encode($texts, JSON_UNESCAPED_UNICODE), Templates::CURRENT,
                 $user->getId(), $now, $now, $id]);
            Audit::log($this->db, $user, 'invoice', $id, 'issue', $num['number']);
            // paid by card / in cash = paid when issued
            if (in_array($inv['payment_method'], ['card', 'cash'], true) && (float)$calc['total'] != 0) {
                $this->addPayment($id, $inv['issue_date'], (float)$calc['total'], $inv['payment_method'], self::AUTO_PAYMENT_NOTE, $user);
            }
            return $num;
        });
    }

    // ------------------------------------------------------------------ from a shop order / credit note

    /**
     * Draft invoice from a PrestaShop order (array from OrderSource::find(); `src` = its source).
     * VAT payer: prices without VAT + the shop's tax rate; non-payer: the prices the customer paid (no VAT).
     */
    public function createFromOrder(array $o, int $companyId, Client $user): int
    {
        $src = (int)($o['src'] ?? 0);
        if ($this->findByOrder($src, (int)$o['id_order']) !== null) {
            throw new \RuntimeException('K tejto objednávke už faktúra existuje.');
        }
        $h = $this->defaults($companyId, 'invoice', $user);
        $payer = $this->companies->vatOn($companyId, $h['delivery_date'])['vat_payer'];
        $a = $o['address_invoice'] ?: [];
        $vatNo = strtoupper(str_replace(' ', '', (string)($a['vat_number'] ?? '')));
        $buyer = [
            'type' => trim((string)($a['company'] ?? '')) !== '' ? 'b2b' : 'b2c',
            'name' => trim((string)($a['company'] ?? '')) !== '' ? trim($a['company'])
                      : trim(($a['firstname'] ?? $o['customer_firstname']) . ' ' . ($a['lastname'] ?? $o['customer_lastname'])),
            'street' => trim(($a['address1'] ?? '') . ' ' . ($a['address2'] ?? '')),
            'city' => (string)($a['city'] ?? ''), 'zip' => (string)($a['postcode'] ?? ''),
            'country' => strtoupper((string)($a['country_iso'] ?? 'SK')) ?: 'SK',
            'ico' => preg_replace('/\s+/', '', (string)($a['dni'] ?? '')),
            // Slovak IČ DPH = "SK" + DIČ → the DIČ can be derived (needed e.g. for the Peppol address 0245:DIČ)
            'dic' => preg_match('/^\d+$/', $vatNo) ? $vatNo : (preg_match('/^SK(\d{10})$/', $vatNo, $mm) ? $mm[1] : ''),
            'icdph' => preg_match('/^[A-Z]{2}/', $vatNo) ? $vatNo : '',
            'email' => (string)$o['customer_email'], 'phone' => (string)(($a['phone'] ?? '') ?: ($a['phone_mobile'] ?? '')),
            'due_days' => null, 'note' => '',
        ];
        $customers = new Customers($this->db);
        $existing = $o['id_customer'] ? $customers->findByShopCustomer($companyId, $src, (int)$o['id_customer']) : null;
        $customerId = $customers->save($existing ? (int)$existing['id'] : null, $companyId, $buyer, (int)$o['id_customer'] ?: null, $src);

        $method = self::paymentFromShop((string)$o['payment']);
        $h = array_merge($h, [
            'customer_id' => $customerId, 'buyer_type' => $buyer['type'], 'buyer_name' => $buyer['name'],
            'buyer_street' => $buyer['street'], 'buyer_city' => $buyer['city'], 'buyer_zip' => $buyer['zip'],
            'buyer_country' => $buyer['country'], 'buyer_ico' => $buyer['ico'], 'buyer_dic' => $buyer['dic'],
            'buyer_icdph' => $buyer['icdph'], 'buyer_email' => $buyer['email'], 'buyer_phone' => $buyer['phone'],
            'payment_method' => $method, 'currency' => (string)($o['currency'] ?: 'EUR'),
        ]);
        if ($method !== 'transfer') {
            $h['due_date'] = $h['issue_date'];
        } elseif ($existing && $existing['due_days'] !== null && $existing['due_days'] !== '') {
            $h['due_date'] = date('Y-m-d', strtotime($h['issue_date'] . ' +' . (int)$existing['due_days'] . ' days'));
        }
        $lines = [];
        foreach ($o['lines'] as $l) {
            $lines[] = ['name' => $l['product_name'], 'sku' => (string)$l['product_reference'], 'quantity' => $l['product_quantity'],
                'unit' => 'ks', 'unit_price' => $payer ? $l['unit_price_tax_excl'] : $l['unit_price_tax_incl'],
                'vat_rate' => $l['tax_rate'], 'vat_category' => 'S'];
        }
        if ((float)$o['total_shipping_tax_incl'] > 0) {
            $lines[] = ['name' => 'Doprava' . ($o['carrier'] ? ': ' . $o['carrier'] : ''), 'sku' => '', 'quantity' => 1, 'unit' => 'ks',
                'unit_price' => $payer ? $o['total_shipping_tax_excl'] : $o['total_shipping_tax_incl'],
                'vat_rate' => $o['carrier_tax_rate'] ?? 0, 'vat_category' => 'S'];
        }
        if ((float)$o['total_wrapping_tax_incl'] > 0) {
            $lines[] = ['name' => 'Darčekové balenie', 'sku' => '', 'quantity' => 1, 'unit' => 'ks',
                'unit_price' => $payer ? $o['total_wrapping_tax_excl'] : $o['total_wrapping_tax_incl'], 'vat_rate' => 0, 'vat_category' => 'S'];
        }
        if ((float)$o['total_discounts_tax_incl'] > 0) {
            $excl = (float)$o['total_discounts_tax_excl'];
            $incl = (float)$o['total_discounts_tax_incl'];
            $rate = ($payer && $excl > 0) ? round(($incl / $excl - 1) * 100) : 0;
            $lines[] = ['name' => 'Zľava', 'sku' => '', 'quantity' => 1, 'unit' => 'ks',
                'unit_price' => -($payer ? $excl : $incl), 'vat_rate' => $rate, 'vat_category' => 'S'];
        }
        return $this->saveDraft(null, $h, $lines, $user, ['shop_id' => (int)$o['id_shop'], 'shop_connection_id' => $src,
            'shop_order_id' => (int)$o['id_order'],
            'order_reference' => (string)$o['reference']]);
    }

    public static function paymentFromShop(string $payment): string
    {
        $p = mb_strtolower($payment, 'UTF-8');
        if (strpos($p, 'dobierk') !== false || strpos($p, 'cash on delivery') !== false || strpos($p, 'cod') === 0) {
            return 'cod';
        }
        if (strpos($p, 'prevod') !== false || strpos($p, 'bank') !== false || strpos($p, 'wire') !== false) {
            return 'transfer';
        }
        if (strpos($p, 'hotovos') !== false || strpos($p, 'v obchode') !== false || strpos($p, 'predajn') !== false) {
            return 'cash';
        }
        return 'card';   // payment gateways (GoPay, cards, PayPal …): already paid
    }

    /**
     * Draft credit note for an issued invoice: same buyer, all lines with negative quantity (edit to a partial one).
     * @param array|null $pick only these lines: list of ['sku' => , 'name' => , 'quantity' => ] (returned goods; matched by
     *                   code + name, each invoice line once) and/or ['shipping' => true] (the "Doprava…" line); null = all
     * @param string $reason reason of the correction (printed on the credit note)
     */
    public function createCreditNote(int $invoiceId, Client $user, ?array $pick = null, string $reason = ''): int
    {
        if ($pick !== null) {
            return $this->createPartialCreditNote($invoiceId, $user, $pick, $reason);
        }
        $inv = $this->find($invoiceId);
        if ($inv === null || $inv['status'] !== 'issued' || $inv['doc_type'] !== 'invoice') {
            throw new \RuntimeException('Dobropis sa dá vytvoriť len k vystavenej faktúre.');
        }
        $h = $this->defaults((int)$inv['company_id'], 'credit_note', $user);
        foreach (self::HEADER_FIELDS as $f) {
            if (strpos($f, 'buyer_') === 0 || in_array($f, ['customer_id', 'currency', 'payment_method', 'bank_account_id'], true)) {
                $h[$f] = $inv[$f];
            }
        }
        $h['delivery_date'] = $inv['delivery_date'];
        $h['note'] = '';
        $lines = [];
        foreach ($inv['lines'] as $l) {
            $l['quantity'] = -(float)$l['quantity'];
            $lines[] = $l;
        }
        $id = $this->saveDraft(null, $h, $lines, $user, ['original_invoice_id' => $invoiceId]);
        Audit::log($this->db, $user, 'invoice', $invoiceId, 'credit_note', 'koncept dobropisu #' . $id);
        return $id;
    }

    /** Credit note draft with only the returned goods (+ shipping) — see createCreditNote(). */
    private function createPartialCreditNote(int $invoiceId, Client $user, array $pick, string $reason): int
    {
        $inv = $this->find($invoiceId);
        if ($inv === null || $inv['status'] !== 'issued' || $inv['doc_type'] !== 'invoice') {
            throw new \RuntimeException('Dobropis sa dá vytvoriť len k vystavenej faktúre.');
        }
        $available = $inv['lines'];
        $lines = [];
        $missing = [];
        foreach ($pick as $p) {
            $found = null;
            foreach ($available as $i => $l) {
                $isShipping = mb_strpos((string)$l['name'], 'Doprava', 0, 'UTF-8') === 0;
                if (!empty($p['shipping']) ? $isShipping
                        : (!$isShipping && (string)$l['sku'] === (string)($p['sku'] ?? '') && (string)$l['name'] === (string)($p['name'] ?? ''))) {
                    $found = $i;
                    break;
                }
            }
            if ($found === null) {
                $missing[] = !empty($p['shipping']) ? 'doprava' : (string)($p['name'] ?? '');
                continue;
            }
            $l = $available[$found];
            unset($available[$found]);   // each invoice line once
            $qty = !empty($p['shipping']) ? (float)$l['quantity'] : min((float)$p['quantity'], (float)$l['quantity']);
            $l['quantity'] = -$qty;
            $lines[] = $l;
        }
        if (!$lines) {
            throw new \RuntimeException('Vrátené položky sa na faktúre ' . $inv['number'] . ' nenašli — dobropis vytvorte ručne pri faktúre.');
        }
        $h = $this->defaults((int)$inv['company_id'], 'credit_note', $user);
        foreach (self::HEADER_FIELDS as $f) {
            if (strpos($f, 'buyer_') === 0 || in_array($f, ['customer_id', 'currency', 'payment_method', 'bank_account_id'], true)) {
                $h[$f] = $inv[$f];
            }
        }
        $h['delivery_date'] = $inv['delivery_date'];
        $h['note'] = '';
        $h['correction_reason'] = $reason;
        $id = $this->saveDraft(null, $h, $lines, $user, ['original_invoice_id' => $invoiceId, 'shop_id' => $inv['shop_id'],
            'shop_connection_id' => $inv['shop_connection_id'], 'order_reference' => $inv['order_reference']]);
        Audit::log($this->db, $user, 'invoice', $invoiceId, 'credit_note', 'koncept dobropisu #' . $id . ' (vrátenie tovaru'
            . ($missing ? '; na faktúre chýba: ' . implode(', ', $missing) : '') . ')');
        return $id;
    }
}
