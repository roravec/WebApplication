<?php
namespace Panel\Modules;

use Panel\Controller\ExpensesController;
use Panel\Module;
use Panel\PanelContext;
use Panel\Rights;
use WebApplication\Http\Router;

/** Náklady: received documents, recurring costs, categories. */
class ExpensesModule implements Module
{
    public function routes(Router $r, callable $guard, PanelContext $ctx): void
    {
        $expenses = new ExpensesController($ctx);
        $XV = Rights::EXPENSES_VIEW;
        $XE = Rights::EXPENSES_EDIT;
        $r->get('/expenses', $guard($XV, [$expenses, 'index']));
        $r->get('/expenses/new', $guard($XE, [$expenses, 'form']));
        $r->post('/expenses', $guard($XE, [$expenses, 'save']));
        $r->get('/expenses/categories', $guard($XV, [$expenses, 'categories']));
        $r->post('/expenses/categories', $guard($XE, [$expenses, 'categoriesSave']));
        $r->get('/expenses/recurring', $guard($XV, [$expenses, 'recurringIndex']));
        $r->get('/expenses/recurring/new', $guard($XE, [$expenses, 'recurringForm']));
        $r->post('/expenses/recurring', $guard($XE, [$expenses, 'recurringSave']));
        $r->get('/expenses/recurring/{id:\d+}', $guard($XV, [$expenses, 'recurringForm']));
        $r->post('/expenses/recurring/{id:\d+}', $guard($XE, [$expenses, 'recurringSave']));
        $r->get('/expenses/{id:\d+}', $guard($XV, [$expenses, 'show']));
        $r->get('/expenses/{id:\d+}/edit', $guard($XE, [$expenses, 'form']));
        $r->post('/expenses/{id:\d+}', $guard($XE, [$expenses, 'save']));
        $r->get('/expenses/{id:\d+}/files/{fid:\d+}', $guard($XV, [$expenses, 'file']));
        $r->post('/expenses/{id:\d+}/files', $guard($XE, [$expenses, 'addFile']));
        $r->post('/expenses/{id:\d+}/paid', $guard($XE, [$expenses, 'paid']));
        $r->post('/expenses/{id:\d+}/unpaid', $guard($XE, [$expenses, 'unpaid']));
        $r->post('/expenses/{id:\d+}/cancel', $guard($XE, [$expenses, 'cancel']));
    }

    public function nav(): array
    {
        return [['key' => 'expenses', 'group' => 'finance', 'path' => '/expenses', 'label' => 'Náklady', 'permission' => Rights::EXPENSES_VIEW,
            'order' => 20]];
    }

    public function settingsTabs(): array
    {
        return [];
    }
}
