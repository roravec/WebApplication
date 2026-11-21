<?php
namespace Panel\Modules;

use Panel\Controller\EmployeesController;
use Panel\Module;
use Panel\PanelContext;
use Panel\Rights;
use WebApplication\Http\Router;

/** Nastavenia → Zamestnanci (accounts, permissions, access to companies) + Môj účet. */
class EmployeesModule implements Module
{
    public function routes(Router $r, callable $guard, PanelContext $ctx): void
    {
        $employees = new EmployeesController($ctx);
        $ADMIN = Rights::ADMIN_ONLY;
        $r->get('/employees', $guard($ADMIN, [$employees, 'index']));
        $r->get('/employees/new', $guard($ADMIN, [$employees, 'form']));
        $r->get('/employees/{id:\d+}', $guard($ADMIN, [$employees, 'form']));
        $r->post('/employees', $guard($ADMIN, [$employees, 'save']));
        $r->post('/employees/{id:\d+}', $guard($ADMIN, [$employees, 'save']));
        $r->get('/account', $guard('', [$employees, 'accountForm']));
        $r->post('/account', $guard('', [$employees, 'accountSave']));
    }

    public function nav(): array
    {
        return [];
    }

    public function settingsTabs(): array
    {
        return [['key' => 'employees', 'path' => '/employees', 'label' => 'Zamestnanci', 'permission' => Rights::ADMIN_ONLY, 'order' => 60]];
    }
}
