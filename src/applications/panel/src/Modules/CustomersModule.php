<?php
namespace Panel\Modules;

use Panel\Controller\CustomersController;
use Panel\Module;
use Panel\PanelContext;
use Panel\Rights;
use WebApplication\Http\Router;

/** Odberatelia (customers of the invoicing companies) + IČO lookup in the register. */
class CustomersModule implements Module
{
    public function routes(Router $r, callable $guard, PanelContext $ctx): void
    {
        $customers = new CustomersController($ctx);
        $VIEW = Rights::INVOICES_VIEW;
        $EDIT = Rights::INVOICES_EDIT;
        $r->get('/customers', $guard($VIEW, [$customers, 'index']));
        $r->get('/customers/lookup', $guard($EDIT, [$customers, 'lookup']));
        $r->get('/lookup/ico', $guard('', [$customers, 'rpo']));
        $r->get('/customers/new', $guard($EDIT, [$customers, 'form']));
        $r->get('/customers/{id:\d+}', $guard($EDIT, [$customers, 'form']));
        $r->post('/customers', $guard($EDIT, [$customers, 'save']));
        $r->post('/customers/{id:\d+}', $guard($EDIT, [$customers, 'save']));
    }

    public function nav(): array
    {
        return [['key' => 'customers', 'group' => 'sales', 'path' => '/customers', 'label' => 'Odberatelia',
            'permission' => Rights::INVOICES_VIEW, 'order' => 30]];
    }

    public function settingsTabs(): array
    {
        return [];
    }

    public function permissions(): array
    {
        return [];   // uses the permissions of other modules
    }
}
