<?php
namespace Panel\Controller;

use Panel\PanelContext;
use Panel\Rights;
use WebApplication\Http\Request;

class DashboardController
{
    /** @var PanelContext */
    private $ctx;

    public function __construct(PanelContext $ctx)
    {
        $this->ctx = $ctx;
    }

    public function index(Request $req, array $params): string
    {
        $canOrders = $this->ctx->can(Rights::ORDERS_VIEW);
        $stats = null;
        $latest = [];
        $errors = [];
        foreach ($canOrders ? $this->ctx->orderSources() : [] as $source) {
            try {   // every source separately — an unreachable e-shop does not hide the others
                $s = $source->stats();
                $rows = $source->search([], 1, 8)['rows'];
            } catch (\Throwable $e) {
                $errors[] = $source->label() . ': ' . $e->getMessage();
                continue;
            }
            if ($stats === null) {
                $stats = $s;
            } else {
                foreach (['today', 'week', 'month', 'month_total'] as $k) {
                    $stats[$k] += $s[$k];
                }
                $stats['per_shop'] = array_merge($stats['per_shop'], $s['per_shop']);
            }
            $latest = array_merge($latest, $rows);
        }
        usort($latest, function ($a, $b) { return strcmp((string)$b['date_add'], (string)$a['date_add']); });
        $available = $stats !== null;
        $drafts = null;
        $overdue = null;
        if ($this->ctx->can(Rights::INVOICES_VIEW)) {
            $drafts = $this->ctx->invoices()->search(['status' => 'draft'], $this->ctx->companyIds(), 1, 10);
            $overdue = $this->ctx->invoices()->search(['payment' => 'overdue'], $this->ctx->companyIds(), 1, 10);
        }
        // monthly package for the accountant (companies with "send automatically"): the hosting has no reliable cron, so
        // the first visit of the dashboard after the set day sends it (safe against parallel visits; cron URL as well)
        try {
            $sent = [];
            $failed = [];
            foreach ($this->ctx->accounting()->sendDue($this->ctx->mailer()) as $r) {
                if ($r['ok']) {
                    $sent[] = $r['message'];
                } else {
                    $failed[] = $r['message'];
                }
            }
            if ($sent) {
                \WebApplication\Session::flash('msg', implode(' ', $sent));
            }
            if ($failed && $this->ctx->can(Rights::INVOICES_VIEW)) {
                \WebApplication\Session::flash('error', implode(' ', $failed));
            }
        } catch (\Throwable $e) {
            $this->ctx->app->getLogger()->error('Accounting package: automatic sending failed', ['error' => $e->getMessage()]);
        }
        // monthly summary for the owner (module F) — same trigger as the accountant's package
        try {
            foreach ((new \Panel\Reports\MonthlySummary($this->ctx))->sendDue($this->ctx->mailer()) as $r) {
                if (!$r['ok'] && $this->ctx->can(Rights::REPORTS_VIEW)) {
                    \WebApplication\Session::flash('error', $r['message']);
                }
            }
        } catch (\Throwable $e) {
            $this->ctx->app->getLogger()->error('Monthly summary failed', ['error' => $e->getMessage()]);
        }
        list($business, $todo) = $this->business();
        return $this->ctx->page('dashboard', [
            'canOrders' => $canOrders,
            'shopAvailable' => $available,
            'stats' => $stats,
            'latest' => array_slice($latest, 0, 8),
            'shopErrors' => $errors,
            'drafts' => $drafts,
            'overdue' => $overdue,
            'business' => $business,
            'todo' => $todo,
        ], 'Prehľad', 'dashboard');
    }

    /**
     * Module F: this month per company (revenue, profit so far vs. the same days last year, VAT turnover of a non-payer)
     * and what needs doing — each part only with the permission for it.
     * @return array{0: array, 1: array} business rows, todo items [level, text, url]
     */
    private function business(): array
    {
        $business = [];
        $todo = [];
        $ids = $this->ctx->companyIds();
        if (!$ids) {
            return [[], []];
        }
        $today = date('Y-m-d');
        $shopsOk = $this->ctx->shopOrders()->available();
        $sales = $shopsOk ? $this->ctx->salesReport() : null;
        try {
            if ($this->ctx->can(Rights::REPORTS_VIEW)) {
                $oc = $sales ? $this->ctx->orderCosts() : null;
                $res = new \Panel\Reports\BusinessResult($this->ctx->db(), $sales, $oc && $oc->rules() ? $oc->loader() : null);
                $net = $this->ctx->vatBasis();
                $vt = new \Panel\Reports\VatTurnover($this->ctx->db());
                $lastYear = date('Y-m-d', strtotime('-1 year'));
                foreach (array_slice($this->ctx->companies()->all($ids, true), 0, 4) as $c) {
                    $cid = (int)$c['id'];
                    $shops = $this->ctx->companyShops($cid);
                    $cur = $res->compute($cid, $shops, date('Y-m-01'), $today, $net)['total'];
                    $prev = $res->compute($cid, $shops, substr($lastYear, 0, 8) . '01', $lastYear, $net)['total'];
                    $vat = $this->ctx->companies()->vatOn($cid, $today)['vat_payer'] ? null : $vt->status($cid, $sales, $shops, $today);
                    $business[] = ['company' => $c, 'cur' => $cur, 'prev' => $prev, 'vat' => $vat];
                    if ($vat && $vat['level'] !== 'ok') {
                        $todo[] = [$vat['level'] === 'warn' ? 'warn' : 'err', $c['name'] . ': ' . $vat['text'], $this->ctx->url('/reports/result?company=' . $cid)];
                    }
                    if ($cur['missing_cost']) {
                        $todo[] = ['warn', $cur['missing_cost'] . ' predaných položiek tento mesiac bez nákupnej ceny — zisk je nepresný.', $this->ctx->url('/reports/purchase-prices')];
                    }
                }
            }
            $t = new \Panel\Reports\Todo($this->ctx->db(), (string)$this->ctx->app->getConfig()->get('apps.' . $this->ctx->app->getApplicationName() . '.shop.prefix', 'ps_'));
            if ($this->ctx->can(Rights::EXPENSES_VIEW)) {
                $x = $t->expenses($ids, $today);
                if ($x['overdue']) {
                    $todo[] = ['err', $x['overdue'] . ' neuhradených prijatých dokladov po splatnosti (' . (new \Panel\Format())->money($x['overdue_sum']) . ').', $this->ctx->url('/expenses?status=overdue')];
                }
                if ($x['no_number']) {
                    $todo[] = ['warn', $x['no_number'] . ' prijatých faktúr bez čísla dokladu (opakované náklady) — doplňte, keď príde faktúra.', $this->ctx->url('/expenses?q=Opakovan')];
                }
                if ($x['no_file']) {
                    $todo[] = ['info', $x['no_file'] . ' prijatých dokladov bez súboru (PDF / fotka) — účtovník ich potrebuje.', $this->ctx->url('/expenses')];
                }
            }
            if ($sales && $this->ctx->can(Rights::INVOICES_EDIT) && $this->ctx->can(Rights::ORDERS_VIEW)) {
                $shops = [];
                foreach ($ids as $cid) {
                    $shops = array_merge($shops, $this->ctx->companyShops((int)$cid));
                }
                $o = $t->ordersWithoutInvoice($shops, $today);
                if ($o['count']) {
                    $todo[] = ['warn', $o['count'] . ' zaplatených / prijatých objednávok za 60 dní bez faktúry (najstaršia ' . date('j. n.', strtotime($o['oldest'])) . ').', $this->ctx->url('/orders')];
                }
            }
        } catch (\Throwable $e) {
            $this->ctx->app->getLogger()->error('Dashboard business overview failed', ['error' => $e->getMessage()]);
        }
        return [$business, $todo];
    }
}
