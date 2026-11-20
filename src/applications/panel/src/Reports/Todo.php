<?php
namespace Panel\Reports;

use WebApplication\Database\Database;

/**
 * What needs doing (module F — dashboard and the monthly summary): received documents without a file / number,
 * unpaid after the due date, e-shop orders without an invoice. Counts only; the dashboard links to the lists.
 */
class Todo
{
    /** @var Database */ private $db;
    /** @var string */ private $p;

    public function __construct(Database $db, string $shopPrefix = 'ps_')
    {
        if (!preg_match('/^[A-Za-z0-9_]*$/', $shopPrefix)) {
            throw new \InvalidArgumentException('Invalid shop table prefix');
        }
        $this->db = $db;
        $this->p = $shopPrefix;
    }

    private function t(string $name): string
    {
        return $this->db->table($name);
    }

    /** @return array no_file, no_number, overdue, overdue_sum — received documents of the last 12 months (not cancelled) */
    public function expenses(array $companyIds, string $today): array
    {
        $ids = implode(',', array_map('intval', $companyIds ?: [0]));
        $from = date('Y-m-d', strtotime($today . ' -12 months'));
        $r = $this->db->fetchOne("SELECT
                SUM(CASE WHEN NOT EXISTS (SELECT 1 FROM {$this->t('expense_file')} f WHERE f.expense_id = e.id) THEN 1 ELSE 0 END) AS no_file,
                SUM(CASE WHEN e.doc_type = 'invoice' AND e.document_number = '' THEN 1 ELSE 0 END) AS no_number,
                SUM(CASE WHEN e.status = 'open' AND e.due_date < ? THEN 1 ELSE 0 END) AS overdue,
                SUM(CASE WHEN e.status = 'open' AND e.due_date < ? THEN e.total ELSE 0 END) AS overdue_sum
            FROM {$this->t('expense')} e WHERE e.company_id IN ($ids) AND e.status <> 'cancelled' AND e.delivery_date >= ?", [$today, $today, $from]);
        return ['no_file' => (int)$r['no_file'], 'no_number' => (int)$r['no_number'], 'overdue' => (int)$r['overdue'], 'overdue_sum' => (float)$r['overdue_sum']];
    }

    /**
     * Valid orders of the e-shops (this database) from the last $days days without an issued or draft invoice.
     * @return array count, oldest (date or null)
     */
    public function ordersWithoutInvoice(array $shops, string $today, int $days = 60): array
    {
        $shops = array_values(array_filter(array_map('intval', $shops)));
        if (!$shops) {
            return ['count' => 0, 'oldest' => null];
        }
        $r = $this->db->fetchOne("SELECT COUNT(*) AS n, MIN(o.date_add) AS oldest FROM {$this->p}orders o
            WHERE o.valid = 1 AND o.date_add >= ? AND o.id_shop IN (" . implode(',', $shops) . ")
            AND NOT EXISTS (SELECT 1 FROM {$this->t('invoice')} i WHERE i.shop_connection_id = 0 AND i.shop_order_id = o.id_order
                AND i.status IN ('issued', 'draft') AND i.doc_type = 'invoice')", [date('Y-m-d', strtotime($today . ' -' . $days . ' days'))]);
        return ['count' => (int)$r['n'], 'oldest' => $r['oldest'] ? substr((string)$r['oldest'], 0, 10) : null];
    }
}
