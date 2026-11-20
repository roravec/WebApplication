<?php
namespace Panel\Reports;

use WebApplication\Database\Database;

/**
 * Money of a company (module E): receivables (unpaid issued invoices), payables (unpaid received documents) by due date,
 * money in / out by month (recorded payments of invoices, paid received documents) and what is due in the next days.
 * Payments the panel does not know about (e.g. card payments of e-shop orders without an invoice payment) are not in it.
 */
class Money
{
    const BUCKETS = ['over60' => 'po splatnosti viac ako 60 dní', 'over30' => 'po splatnosti 31–60 dní', 'over0' => 'po splatnosti 1–30 dní',
        'due7' => 'splatné do 7 dní', 'due30' => 'splatné o 8–30 dní', 'later' => 'neskôr / bez splatnosti'];

    /** @var Database */ private $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    private function t(string $name): string
    {
        return $this->db->table($name);
    }

    public static function bucket(?string $due, string $today): string
    {
        if ($due === null || $due === '') {
            return 'later';
        }
        $days = (int)round((strtotime($due) - strtotime($today)) / 86400);
        return $days < -60 ? 'over60' : ($days < -30 ? 'over30' : ($days < 0 ? 'over0' : ($days <= 7 ? 'due7' : ($days <= 30 ? 'due30' : 'later'))));
    }

    /** @return array rows (each with rest, bucket), buckets (key => sum), total, overdue */
    private function aging(array $rows, string $today): array
    {
        $b = array_fill_keys(array_keys(self::BUCKETS), 0.0);
        $total = 0.0;
        foreach ($rows as &$r) {
            $r['bucket'] = self::bucket($r['due_date'], $today);
            $b[$r['bucket']] += (float)$r['rest'];
            $total += (float)$r['rest'];
        }
        unset($r);
        usort($rows, function ($x, $y) { return strcmp((string)$x['due_date'], (string)$y['due_date']); });
        return ['rows' => $rows, 'buckets' => $b, 'total' => $total, 'overdue' => $b['over60'] + $b['over30'] + $b['over0']];
    }

    /** Unpaid issued invoices (EUR; credit notes to refund have a negative rest). */
    public function receivables(int $companyId, string $today): array
    {
        $rows = $this->db->query("SELECT i.id, i.number, i.buyer_name AS party, i.due_date, i.total, i.currency, i.doc_type,
                i.total - (SELECT COALESCE(SUM(p.amount), 0) FROM {$this->t('invoice_payment')} p WHERE p.invoice_id = i.id) AS rest
            FROM {$this->t('invoice')} i WHERE i.company_id = ? AND i.status = 'issued' AND i.currency = 'EUR'
            HAVING ABS(rest) > 0.005", [$companyId]);
        return $this->aging($rows, $today);
    }

    /** Unpaid received documents (EUR). */
    public function payables(int $companyId, string $today): array
    {
        $rows = $this->db->query("SELECT id, document_number AS number, supplier_name AS party, due_date, total, currency, total AS rest
            FROM {$this->t('expense')} WHERE company_id = ? AND status = 'open' AND currency = 'EUR' AND total <> 0", [$companyId]);
        return $this->aging($rows, $today);
    }

    /** Money in / out per month (Y-m => in, out, net) from the recorded payments. */
    public function flows(int $companyId, string $from, string $to): array
    {
        $out = [];
        for ($m = substr($from, 0, 7); $m <= substr($to, 0, 7); $m = date('Y-m', strtotime($m . '-01 +1 month'))) {
            $out[$m] = ['month' => $m, 'in' => 0.0, 'out' => 0.0, 'net' => 0.0];
        }
        foreach ($this->db->query("SELECT DATE_FORMAT(p.paid_on, '%Y-%m') AS m, SUM(p.amount) AS s FROM {$this->t('invoice_payment')} p
                JOIN {$this->t('invoice')} i ON i.id = p.invoice_id WHERE i.company_id = ? AND i.currency = 'EUR' AND p.paid_on BETWEEN ? AND ?
                GROUP BY m", [$companyId, $from, $to]) as $r) {
            if (isset($out[$r['m']])) {
                $out[$r['m']]['in'] += (float)$r['s'];
            }
        }
        foreach ($this->db->query("SELECT DATE_FORMAT(paid_on, '%Y-%m') AS m, SUM(total) AS s FROM {$this->t('expense')}
                WHERE company_id = ? AND status = 'paid' AND currency = 'EUR' AND paid_on BETWEEN ? AND ? GROUP BY m", [$companyId, $from, $to]) as $r) {
            if (isset($out[$r['m']])) {
                $out[$r['m']]['out'] += (float)$r['s'];
            }
        }
        foreach ($out as &$r) {
            $r['net'] = $r['in'] - $r['out'];
        }
        unset($r);
        return $out;
    }
}
