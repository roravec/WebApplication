<?php
namespace Panel\Modules;

use Panel\Controller\ReportsController;
use Panel\Core\ProvidesJobs;
use Panel\Module;
use Panel\PanelContext;
use Panel\Rights;
use WebApplication\Http\Request;
use WebApplication\Http\Response;
use WebApplication\Http\Router;

/** Výsledky (company result, money, sales and margin, purchase prices); the order cost rules are in Nastavenia. */
class ReportsModule implements Module, ProvidesJobs
{
    public function routes(Router $r, callable $guard, PanelContext $ctx): void
    {
        $reports = new ReportsController($ctx);
        $r->get('/reports', function () use ($ctx) { return Response::redirect($ctx->url('/reports/result')); });
        $r->get('/reports/result', $guard(Rights::REPORTS_VIEW, [$reports, 'result']));
        $r->get('/reports/money', $guard(Rights::REPORTS_VIEW, [$reports, 'money']));
        $r->get('/reports/sales', $guard(Rights::REPORTS_VIEW, [$reports, 'sales']));
        $r->get('/reports/purchase-prices', $guard(Rights::REPORTS_VIEW, [$reports, 'purchasePrices']));
        $r->post('/reports/purchase-prices', $guard(Rights::STOCK_EDIT, [$reports, 'purchasePricesSave']));
        $r->get('/reports/purchase-prices/continue', $guard(Rights::STOCK_EDIT, [$reports, 'purchasePricesContinue']));
        $r->get('/settings/order-costs', $guard(Rights::REPORTS_VIEW, [$reports, 'orderCosts']));
        $r->post('/settings/order-costs', $guard(Rights::EXPENSES_EDIT, [$reports, 'orderCostsSave']));
        // before 2026-11-21 under Výsledky
        $r->get('/reports/order-costs', function (Request $req) use ($ctx) {
            $q = $req->query();
            return Response::redirect($ctx->url('/settings/order-costs') . ($q ? '?' . http_build_query($q) : ''), 301);
        });
    }

    public function nav(): array
    {
        return [['key' => 'reports', 'group' => 'finance', 'path' => '/reports/result', 'label' => 'Výsledky',
            'permission' => Rights::REPORTS_VIEW, 'order' => 30]];
    }

    public function settingsTabs(): array
    {
        return [['key' => 'order-costs', 'path' => '/settings/order-costs', 'label' => 'Náklady objednávok',
            'permission' => Rights::REPORTS_VIEW, 'order' => 40]];
    }

    public function permissions(): array
    {
        return [
            ['key' => Rights::REPORTS_VIEW, 'group' => 'Výsledky', 'sensitive' => true,
                'label' => 'Výsledky — predaje, marža, nákupné ceny, výsledok firmy a peniaze'],
        ];
    }

    public function recordTypes(): array
    {
        return [];
    }

    public function jobs(): array
    {
        return [['key' => 'reports.monthly_summary', 'label' => 'Mesačný súhrn firmy e-mailom', 'every' => 60,
            'run' => function (PanelContext $sys): string {
                $out = [];
                foreach ((new \Panel\Reports\MonthlySummary($sys))->sendDue($sys->mailer()) as $r) {
                    $out[] = ($r['ok'] ? '' : 'CHYBA ') . $r['message'];
                }
                return $out ? implode('; ', $out) : 'nič na odoslanie';
            }]];
    }
}
