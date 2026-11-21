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
        $r->get('/companies/new', $guard(Rights::ADMIN_ONLY, [$companies, 'form']));
        $r->post('/companies', $guard(Rights::ADMIN_ONLY, [$companies, 'save']));
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
}
