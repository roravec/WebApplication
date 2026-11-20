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
        return $this->ctx->page('dashboard', [
            'canOrders' => $canOrders,
            'shopAvailable' => $available,
            'stats' => $stats,
            'latest' => array_slice($latest, 0, 8),
            'shopErrors' => $errors,
            'drafts' => $drafts,
            'overdue' => $overdue,
        ], 'Prehľad', 'dashboard');
    }
}
