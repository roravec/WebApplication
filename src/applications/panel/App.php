<?php
/**
 * Employee section (erma.sk/panel): login, overview, orders from all e-shops (read-only), invoicing
 * (companies, number series, customers, invoices, credit notes, monthly package for the accountant —
 * docs/fakturacia-specifikacia.md), employees.
 *
 * config:
 *   'panel' => ['folder' => 'panel', 'file' => 'App.php', 'class' => 'PanelApp', 'dbPrefix' => 'panel_', 'auth' => 'user',
 *               'shop' => ['prefix' => 'ps_', 'lang' => 'sk']]
 */

use Panel\Controller\AccountingController;
use Panel\Controller\AuthController;
use Panel\Controller\BankImportController;
use Panel\Controller\CompaniesController;
use Panel\Controller\CustomersController;
use Panel\Controller\DashboardController;
use Panel\Controller\EmployeesController;
use Panel\Controller\InvoicesController;
use Panel\Controller\LegislationController;
use Panel\Controller\OrdersController;
use Panel\Controller\ShopsController;
use Panel\Controller\StockController;
use Panel\PanelContext;
use Panel\Rights;
use Panel\Schema;
use WebApplication\Application;
use WebApplication\Http\Request;
use WebApplication\Http\RequestHandler;
use WebApplication\Http\Response;
use WebApplication\Http\Router;

spl_autoload_register(function ($class) {
    if (strncmp($class, 'Panel\\', 6) === 0) {
        $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, 6)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

class PanelApp implements RequestHandler
{
    public function handle(Request $request, Application $app): Response
    {
        // database tables of the panel are created / updated automatically after a deploy (sql/NNN_*.sql)
        $applied = Schema::migrate($app->getDatabase(), __DIR__ . '/sql');
        if ($applied) {
            $app->getLogger()->info('Panel database updated: ' . implode(', ', $applied));
        }

        // all texts are stored as UTF-8; reject broken input instead of failing in the database
        $valid = true;
        $input = [$request->post(), $request->query()];
        array_walk_recursive($input, function ($v) use (&$valid) { $valid = $valid && (!is_string($v) || mb_check_encoding($v, 'UTF-8')); });
        if (!$valid) {
            return Response::text('Neplatné kódovanie znakov (očakáva sa UTF-8).', 400);
        }

        $ctx = new PanelContext($app, $request);
        $auth = new AuthController($ctx);

        $r = new Router();
        $r->get('/login', [$auth, 'form']);
        $r->post('/login', [$auth, 'login']);
        $r->post('/logout', [$auth, 'logout']);
        // automatic monthly package for the accountant without a logged-in employee (token in config; 404 without it)
        $accounting = new AccountingController($ctx);
        $r->get('/cron/accounting', [$accounting, 'cron']);

        // everything below requires a logged-in employee with the given permission ('' = any employee)
        $guard = function (string $permission, callable $handler) use ($ctx) {
            return function (Request $req, array $params) use ($ctx, $permission, $handler) {
                if (!$ctx->isLoggedIn()) {
                    return $ctx->redirectToLogin($req);
                }
                if ($permission !== '' && !$ctx->can($permission)) {
                    return Response::html($ctx->page('forbidden', [], 'Prístup zamietnutý'), 403);
                }
                return $handler($req, $params);
            };
        };

        $dashboard = new DashboardController($ctx);
        $orders = new OrdersController($ctx);
        $invoices = new InvoicesController($ctx);
        $customers = new CustomersController($ctx);
        $companies = new CompaniesController($ctx);
        $employees = new EmployeesController($ctx);
        $legislation = new LegislationController($ctx);
        $bank = new BankImportController($ctx);
        $stock = new StockController($ctx);
        $shops = new ShopsController($ctx);
        $SV = Rights::STOCK_VIEW;
        $SE = Rights::STOCK_EDIT;
        $VIEW = Rights::INVOICES_VIEW;
        $EDIT = Rights::INVOICES_EDIT;
        $COMP = Rights::COMPANIES_MANAGE;
        $ADMIN = Rights::ADMIN_ONLY;

        $r->get('/', $guard('', [$dashboard, 'index']));
        $r->get('/orders', $guard(Rights::ORDERS_VIEW, [$orders, 'index']));
        // /orders/{id} = order in the shop tables of this database; /orders/{src}/{id} = order of shop connection {src}
        foreach (['/orders/{id:\d+}', '/orders/{src:\d+}/{id:\d+}'] as $o) {
            $r->get($o, $guard(Rights::ORDERS_VIEW, [$orders, 'show']));
            $r->post($o . '/invoice', $guard($EDIT, [$invoices, 'fromOrder']));
            $r->post($o . '/state', $guard(Rights::ORDERS_EDIT, [$orders, 'changeState']));
        }

        $r->get('/invoices', $guard($VIEW, [$invoices, 'index']));
        $r->get('/invoices/new', $guard($EDIT, [$invoices, 'create']));
        $r->post('/invoices', $guard($EDIT, [$invoices, 'save']));
        $r->get('/invoices/export', $guard($VIEW, [$invoices, 'exportForm']));
        $r->post('/invoices/export', $guard($VIEW, [$invoices, 'export']));
        $r->get('/accounting', $guard($VIEW, [$accounting, 'index']));
        $r->get('/accounting/download', $guard($VIEW, [$accounting, 'download']));
        $r->post('/accounting/send', $guard($EDIT, [$accounting, 'send']));
        $r->get('/invoices/{id:\d+}', $guard($VIEW, [$invoices, 'show']));
        $r->get('/invoices/{id:\d+}/edit', $guard($EDIT, [$invoices, 'edit']));
        $r->post('/invoices/{id:\d+}', $guard($EDIT, [$invoices, 'save']));
        $r->post('/invoices/{id:\d+}/issue', $guard($EDIT, [$invoices, 'issue']));
        $r->post('/invoices/{id:\d+}/delete', $guard($EDIT, [$invoices, 'delete']));
        $r->post('/invoices/{id:\d+}/credit-note', $guard($EDIT, [$invoices, 'creditNote']));
        $r->get('/invoices/{id:\d+}/print', $guard($VIEW, [$invoices, 'printView']));
        $r->get('/invoices/{id:\d+}/pdf', $guard($VIEW, [$invoices, 'pdf']));
        $r->get('/invoices/{id:\d+}/ubl', $guard($VIEW, [$invoices, 'ubl']));
        $r->post('/invoices/{id:\d+}/payments', $guard($EDIT, [$invoices, 'addPayment']));
        $r->post('/invoices/{id:\d+}/payments/{pid:\d+}/delete', $guard($EDIT, [$invoices, 'deletePayment']));
        $r->get('/invoices/{id:\d+}/send', $guard($EDIT, [$invoices, 'sendForm']));
        $r->post('/invoices/{id:\d+}/send', $guard($EDIT, [$invoices, 'send']));

        $r->get('/payments/import', $guard($EDIT, [$bank, 'form']));
        $r->post('/payments/import', $guard($EDIT, [$bank, 'upload']));
        $r->post('/payments/import/confirm', $guard($EDIT, [$bank, 'confirm']));
        $r->post('/payments/import/fio', $guard($EDIT, [$bank, 'download']));

        $r->get('/stock', $guard($SV, [$stock, 'index']));
        $r->get('/stock/warehouses', $guard($SV, [$stock, 'warehouses']));
        $r->get('/stock/warehouses/new', $guard($SE, [$stock, 'warehouseForm']));
        $r->get('/stock/warehouses/{id:\d+}', $guard($SE, [$stock, 'warehouseForm']));
        $r->post('/stock/warehouses', $guard($SE, [$stock, 'warehouseSave']));
        $r->post('/stock/warehouses/{id:\d+}', $guard($SE, [$stock, 'warehouseSave']));
        $r->get('/stock/items/new', $guard($SE, [$stock, 'itemForm']));
        $r->get('/stock/items/{id:\d+}', $guard($SV, [$stock, 'item']));
        $r->get('/stock/items/{id:\d+}/edit', $guard($SE, [$stock, 'itemForm']));
        $r->post('/stock/items', $guard($SE, [$stock, 'itemSave']));
        $r->post('/stock/items/{id:\d+}', $guard($SE, [$stock, 'itemSave']));
        $r->get('/stock/docs', $guard($SV, [$stock, 'docs']));
        $r->get('/stock/docs/new', $guard($SE, [$stock, 'docForm']));
        $r->get('/stock/docs/{id:\d+}', $guard($SV, [$stock, 'doc']));
        $r->post('/stock/docs', $guard($SE, [$stock, 'docSave']));
        $r->get('/stock/shops', $guard($SV, [$shops, 'index']));
        $r->get('/stock/shops/new', $guard($ADMIN, [$shops, 'form']));
        $r->post('/stock/shops', $guard($ADMIN, [$shops, 'save']));
        $r->get('/stock/shops/{cid:\d+}/edit', $guard($ADMIN, [$shops, 'form']));
        $r->post('/stock/shops/{cid:\d+}/edit', $guard($ADMIN, [$shops, 'save']));
        $r->get('/stock/shops/{cid:\d+}/{sid:\d+}', $guard($SV, [$shops, 'stock']));
        $r->post('/stock/shops/{cid:\d+}/{sid:\d+}', $guard($SE, [$shops, 'stockSave']));
        $r->get('/stock/shops/{cid:\d+}/{sid:\d+}/product/{pid:\d+}', $guard($SV, [$shops, 'product']));
        $r->post('/stock/shops/{cid:\d+}/{sid:\d+}/product/{pid:\d+}', $guard($SE, [$shops, 'productSave']));

        $r->get('/customers', $guard($VIEW, [$customers, 'index']));
        $r->get('/customers/lookup', $guard($EDIT, [$customers, 'lookup']));
        $r->get('/lookup/ico', $guard('', [$customers, 'rpo']));
        $r->get('/customers/new', $guard($EDIT, [$customers, 'form']));
        $r->get('/customers/{id:\d+}', $guard($EDIT, [$customers, 'form']));
        $r->post('/customers', $guard($EDIT, [$customers, 'save']));
        $r->post('/customers/{id:\d+}', $guard($EDIT, [$customers, 'save']));

        $r->get('/companies', $guard($COMP, [$companies, 'index']));
        $r->get('/companies/new', $guard($ADMIN, [$companies, 'form']));
        $r->post('/companies', $guard($ADMIN, [$companies, 'save']));
        $r->get('/companies/{id:\d+}', $guard($COMP, [$companies, 'form']));
        $r->post('/companies/{id:\d+}', $guard($COMP, [$companies, 'save']));
        $r->get('/companies/{id:\d+}/{kind:logo|stamp}', $guard('', [$companies, 'image']));
        $r->get('/companies/{id:\d+}/series/new', $guard($COMP, [$companies, 'seriesForm']));
        $r->get('/companies/{id:\d+}/series/{sid:\d+}', $guard($COMP, [$companies, 'seriesForm']));
        $r->post('/companies/{id:\d+}/series', $guard($COMP, [$companies, 'seriesSave']));
        $r->post('/companies/{id:\d+}/series/{sid:\d+}', $guard($COMP, [$companies, 'seriesSave']));

        $r->get('/settings/legal', $guard($ADMIN, [$legislation, 'form']));
        $r->post('/settings/legal', $guard($ADMIN, [$legislation, 'save']));

        $r->get('/employees', $guard($ADMIN, [$employees, 'index']));
        $r->get('/employees/new', $guard($ADMIN, [$employees, 'form']));
        $r->get('/employees/{id:\d+}', $guard($ADMIN, [$employees, 'form']));
        $r->post('/employees', $guard($ADMIN, [$employees, 'save']));
        $r->post('/employees/{id:\d+}', $guard($ADMIN, [$employees, 'save']));
        $r->get('/account', $guard('', [$employees, 'accountForm']));
        $r->post('/account', $guard('', [$employees, 'accountSave']));

        $r->setNotFound(function (Request $req) use ($ctx) {
            if (!$ctx->isLoggedIn()) {
                return $ctx->redirectToLogin($req);   // don't reveal anything to anonymous visitors
            }
            return Response::html($ctx->page('notfound', [], 'Stránka neexistuje'), 404);
        });
        return $r->dispatch($request);
    }
}
