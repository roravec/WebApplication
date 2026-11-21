<?php
namespace Panel\Modules;

use Panel\Controller\EmployeesController;
use Panel\Module;
use Panel\PanelContext;
use Panel\Rights;
use WebApplication\Http\Router;

/**
 * Nastavenia → Zamestnanci a roly: accounts, roles, permissions, companies and e-shops of each person, overview
 * "who may do what" (docs/panel-architektura.md chapter 5) + Môj účet.
 */
class EmployeesModule implements Module
{
    public function routes(Router $r, callable $guard, PanelContext $ctx): void
    {
        $employees = new EmployeesController($ctx);
        $SETTINGS = Rights::SETTINGS_EMPLOYEES;
        $r->get('/employees', $guard($SETTINGS, [$employees, 'index']));
        $r->get('/employees/new', $guard($SETTINGS, [$employees, 'form']));
        $r->get('/employees/{id:\d+}', $guard($SETTINGS, [$employees, 'form']));
        $r->post('/employees', $guard($SETTINGS, [$employees, 'save']));
        $r->post('/employees/{id:\d+}', $guard($SETTINGS, [$employees, 'save']));
        $r->get('/employees/overview', $guard($SETTINGS, [$employees, 'overview']));
        $r->get('/employees/roles', $guard($SETTINGS, [$employees, 'roles']));
        $r->get('/employees/roles/new', $guard($SETTINGS, [$employees, 'roleForm']));
        $r->get('/employees/roles/{id:\d+}', $guard($SETTINGS, [$employees, 'roleForm']));
        $r->post('/employees/roles', $guard($SETTINGS, [$employees, 'roleSave']));
        $r->post('/employees/roles/{id:\d+}', $guard($SETTINGS, [$employees, 'roleSave']));
        $r->post('/employees/roles/{id:\d+}/delete', $guard($SETTINGS, [$employees, 'roleDelete']));
        $r->get('/account', $guard('', [$employees, 'accountForm']));
        $r->post('/account', $guard('', [$employees, 'accountSave']));
    }

    public function nav(): array
    {
        return [];
    }

    public function settingsTabs(): array
    {
        return [['key' => 'employees', 'path' => '/employees', 'label' => 'Zamestnanci a roly', 'permission' => Rights::SETTINGS_EMPLOYEES, 'order' => 60]];
    }

    public function permissions(): array
    {
        return [
            ['key' => Rights::SETTINGS_EMPLOYEES, 'group' => 'Nastavenia', 'sensitive' => true,
                'label' => 'Nastavenia → Zamestnanci a roly — účty, roly, práva (len v rozsahu vlastných práv)'],
        ];
    }
}
