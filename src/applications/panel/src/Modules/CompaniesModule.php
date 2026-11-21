<?php
namespace Panel\Modules;

use Panel\Controller\CompaniesController;
use Panel\Module;
use Panel\PanelContext;
use Panel\Rights;
use WebApplication\Http\Router;

/** Nastavenia → Firmy: company data, bank accounts, number series, invoice look, e-mails, accountant, Omega. */
class CompaniesModule implements Module
{
    public function routes(Router $r, callable $guard, PanelContext $ctx): void
    {
        $companies = new CompaniesController($ctx);
        $COMP = Rights::COMPANIES_MANAGE;
        $r->get('/companies', $guard($COMP, [$companies, 'index']));
        $r->get('/companies/new', $guard(Rights::COMPANIES_CREATE, [$companies, 'form']));
        $r->post('/companies', $guard(Rights::COMPANIES_CREATE, [$companies, 'save']));
        $r->get('/companies/{id:\d+}', $guard($COMP, [$companies, 'form']));
        $r->post('/companies/{id:\d+}', $guard($COMP, [$companies, 'save']));
        $r->get('/companies/{id:\d+}/{kind:logo|stamp}', $guard('', [$companies, 'image']));
        $r->get('/companies/{id:\d+}/preview', $guard($COMP, [$companies, 'preview']));
        $r->get('/companies/{id:\d+}/series/new', $guard($COMP, [$companies, 'seriesForm']));
        $r->get('/companies/{id:\d+}/series/{sid:\d+}', $guard($COMP, [$companies, 'seriesForm']));
        $r->post('/companies/{id:\d+}/series', $guard($COMP, [$companies, 'seriesSave']));
        $r->post('/companies/{id:\d+}/series/{sid:\d+}', $guard($COMP, [$companies, 'seriesSave']));
    }

    public function nav(): array
    {
        return [];
    }

    public function settingsTabs(): array
    {
        return [['key' => 'companies', 'path' => '/companies', 'label' => 'Firmy', 'permission' => Rights::COMPANIES_MANAGE, 'order' => 10]];
    }

    public function permissions(): array
    {
        return [
            ['key' => Rights::COMPANIES_MANAGE, 'group' => 'Firmy', 'sensitive' => true,
                'label' => 'Firmy — nastavenia, bankové účty, číselné rady, vzhľad faktúry'],
            ['key' => Rights::COMPANIES_CREATE, 'group' => 'Firmy', 'sensitive' => true,
                'label' => 'Firmy — zakladanie nových firiem'],
        ];
    }

    public function recordTypes(): array
    {
        return [['type' => 'company', 'label' => 'Firma',
            'resolve' => function (PanelContext $ctx, array $ids): ?array {
                if (!$ctx->can(Rights::COMPANIES_MANAGE) || !$ctx->canCompany($ids[0])) {
                    return null;
                }
                $name = $ctx->db()->fetchValue('SELECT name FROM ' . $ctx->db()->table('company') . ' WHERE id = ?', [$ids[0]]);
                return $name ? ['title' => $name, 'url' => '/companies/' . $ids[0], 'company_id' => $ids[0]] : null;
            },
            'lookup' => function (PanelContext $ctx, string $q): array {
                $ids = $ctx->companyIds();
                if (!$ids || !$ctx->can(Rights::COMPANIES_MANAGE)) {
                    return [];
                }
                return array_map(function ($id) { return 'company:' . $id; }, array_column($ctx->db()->query('SELECT id FROM ' . $ctx->db()->table('company')
                    . ' WHERE (name LIKE ? OR ico = ?) AND id IN (' . implode(',', array_map('intval', $ids)) . ') LIMIT 5',
                    ['%' . addcslashes($q, '%_\\') . '%', $q]), 'id'));
            }]];
    }
}
