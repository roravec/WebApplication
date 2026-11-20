<?php
namespace Panel\Reports;

use WebApplication\Database\Database;

/**
 * Result of a company (docs/sprava-firmy-specifikacia.md, module E) — a management view, not the accounting result:
 *   tržby e-shopov   = goods after discounts + shipping paid by customers (e-shops of this database, SalesReport)
 * + ostatné tržby    = issued invoices / credit notes NOT made from an order of those e-shops (by delivery date)
 * − predaný tovar    = purchase prices of the goods sold (SalesReport) — purchase invoices of goods are stock, not cost
 * − náklady objednávok = received documents of kind "order" (carriers, gateways) when the month has any, else the
 *                      estimate from the order cost rules (module D)
 * − réžia            = received documents of kind "overhead" (by delivery date)
 * = zisk
 * Amounts: without VAT for a VAT payer, with VAT for a non-payer (received documents: total − deductible VAT).
 * Only documents in EUR are summed; others are counted in `foreign` (shown as a warning).
 */
class BusinessResult
{
    /** @var Database */ private $db;
    /** @var SalesReport|null */ private $sales;
    /** @var callable|null */ private $orderCosts;

    /** @param callable|null $orderCosts OrderCosts::loader() when rules exist */
    public function __construct(Database $db, ?SalesReport $sales, ?callable $orderCosts = null)
    {
        $this->db = $db;
        $this->sales = $sales;
        $this->orderCosts = $orderCosts;
    }

    private function t(string $name): string
    {
        return $this->db->table($name);
    }

    const ZERO = ['shop_revenue' => 0.0, 'orders' => 0, 'cogs' => 0.0, 'margin' => 0.0, 'shipping' => 0.0, 'order_costs_est' => 0.0,
        'order_costs_actual' => 0.0, 'order_costs' => 0.0, 'order_costs_source' => '', 'other_revenue' => 0.0, 'overhead' => 0.0,
        'goods_purchases' => 0.0, 'revenue' => 0.0, 'profit' => 0.0, 'missing_cost' => 0];

    /**
     * @param int[] $shops e-shops of this database invoiced by the company
     * @param callable $net VAT basis for SalesReport (PanelContext::vatBasis)
     * @return array months (Y-m => row, every month of the period), total (row), shops (per e-shop rows), foreign (int)
     */
    public function compute(int $companyId, array $shops, string $from, string $to, callable $net): array
    {
        $months = [];
        for ($m = substr($from, 0, 7); $m <= substr($to, 0, 7); $m = date('Y-m', strtotime($m . '-01 +1 month'))) {
            $months[$m] = self::ZERO + ['month' => $m];
        }
        $shopRows = [];
        if ($this->sales && $shops) {
            $data = $this->sales->compute(['from' => $from, 'to' => $to, 'shops' => $shops, 'group' => 'shop'], $net, $this->orderCosts);
            foreach ($data['months'] as $x) {
                if (!isset($months[$x['label']])) {
                    continue;
                }
                $r = &$months[$x['label']];
                $r['shop_revenue'] = $x['revenue'] - $x['discounts'] + $x['shipping'];
                $r['orders'] = $x['orders'];
                $r['cogs'] = $x['cogs'];
                $r['margin'] = $x['margin'];
                $r['shipping'] = $x['shipping'];
                $r['order_costs_est'] = $data['costs'] ? $x['order_costs'] : 0.0;
                $r['missing_cost'] = $x['missing'];
                unset($r);
            }
            foreach ($data['rows'] as $x) {
                $shopRows[(int)$x['key']] = ['shop_id' => (int)$x['key'], 'name' => $x['label'], 'orders' => $x['orders'],
                    'revenue' => $x['revenue'] - $x['discounts'] + $x['shipping'], 'margin' => $x['margin'],
                    'contribution' => $data['costs'] ? $x['contribution'] : null, 'direct' => 0.0];
            }
        }
        $foreign = 0;
        // other revenue: issued documents that are not invoices of orders of these e-shops (they are in the shop revenue)
        foreach ($this->db->query("SELECT DATE_FORMAT(delivery_date, '%Y-%m') AS m, currency, SUM(total_net) AS net, COUNT(*) AS n
                FROM {$this->t('invoice')} WHERE company_id = ? AND status = 'issued' AND delivery_date BETWEEN ? AND ?
                AND NOT (shop_order_id IS NOT NULL AND shop_connection_id = 0" . ($shops ? ' AND shop_id IN (' . implode(',', array_map('intval', $shops)) . ')' : ' AND 1 = 0') . ")
                GROUP BY m, currency", [$companyId, $from, $to]) as $r) {
            if ($r['currency'] !== 'EUR') {
                $foreign += (int)$r['n'];
            } elseif (isset($months[$r['m']])) {
                $months[$r['m']]['other_revenue'] += (float)$r['net'];
            }
        }
        // received documents by kind (category); a document without a category is overhead
        foreach ($this->db->query("SELECT DATE_FORMAT(e.delivery_date, '%Y-%m') AS m, COALESCE(c.kind, 'overhead') AS kind, e.currency, e.shop_id,
                SUM(e.total_net) AS net, COUNT(*) AS n
                FROM {$this->t('expense')} e LEFT JOIN {$this->t('expense_category')} c ON c.id = e.category_id
                WHERE e.company_id = ? AND e.status <> 'cancelled' AND e.delivery_date BETWEEN ? AND ?
                GROUP BY m, kind, e.currency, e.shop_id", [$companyId, $from, $to]) as $r) {
            if ($r['currency'] !== 'EUR') {
                $foreign += (int)$r['n'];
                continue;
            }
            if (!isset($months[$r['m']])) {
                continue;
            }
            $k = $r['kind'] === 'goods' ? 'goods_purchases' : ($r['kind'] === 'order' ? 'order_costs_actual' : 'overhead');
            $months[$r['m']][$k] += (float)$r['net'];
            if ($r['shop_id'] !== null && $r['kind'] !== 'goods' && isset($shopRows[(int)$r['shop_id']])) {
                $shopRows[(int)$r['shop_id']]['direct'] += (float)$r['net'];
            }
        }
        $total = self::ZERO;
        foreach ($months as &$r) {
            if (abs($r['order_costs_actual']) > 0.004) {
                $r['order_costs'] = $r['order_costs_actual'];
                $r['order_costs_source'] = 'actual';
            } elseif (abs($r['order_costs_est']) > 0.004) {
                $r['order_costs'] = $r['order_costs_est'];
                $r['order_costs_source'] = 'estimate';
            }
            $r['revenue'] = $r['shop_revenue'] + $r['other_revenue'];
            $r['profit'] = $r['revenue'] - $r['cogs'] - $r['order_costs'] - $r['overhead'];
            foreach (self::ZERO as $k => $v) {
                if ($k !== 'order_costs_source') {
                    $total[$k] += $r[$k];
                }
            }
        }
        unset($r);
        $sources = array_unique(array_filter(array_column($months, 'order_costs_source')));
        $total['order_costs_source'] = count($sources) > 1 ? 'mixed' : (string)reset($sources);
        foreach ($shopRows as &$s) {
            $s['result'] = $s['contribution'] !== null ? $s['contribution'] - $s['direct'] : null;
        }
        unset($s);
        usort($shopRows, function ($a, $b) { return $b['revenue'] <=> $a['revenue']; });
        return ['months' => $months, 'total' => $total, 'shops' => $shopRows, 'foreign' => $foreign, 'costs' => $this->orderCosts !== null];
    }
}
