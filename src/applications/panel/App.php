<?php
/**
 * Employee section (erma.sk/panel): login, overview, orders from all e-shops (read-only), employees.
 * Invoicing will be added here (docs/fakturacia-specifikacia.md).
 *
 * config:
 *   'panel' => ['folder' => 'panel', 'file' => 'App.php', 'class' => 'PanelApp', 'dbPrefix' => 'panel_', 'auth' => 'user',
 *               'shop' => ['prefix' => 'ps_', 'lang' => 'sk']]
 */

use Panel\Controller\AuthController;
use Panel\Controller\DashboardController;
use Panel\Controller\EmployeesController;
use Panel\Controller\OrdersController;
use Panel\PanelContext;
use Panel\Rights;
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
        $ctx = new PanelContext($app, $request);
        $auth = new AuthController($ctx);

        $r = new Router();
        $r->get('/login', [$auth, 'form']);
        $r->post('/login', [$auth, 'login']);
        $r->post('/logout', [$auth, 'logout']);

        // everything below requires a logged-in employee with the given rights
        $guard = function (int $rights, callable $handler) use ($ctx) {
            return function (Request $req, array $params) use ($ctx, $rights, $handler) {
                if (!$ctx->isLoggedIn()) {
                    return $ctx->redirectToLogin($req);
                }
                if (!$ctx->can($rights)) {
                    return Response::html($ctx->page('forbidden', [], 'Prístup zamietnutý'), 403);
                }
                return $handler($req, $params);
            };
        };

        $dashboard = new DashboardController($ctx);
        $orders = new OrdersController($ctx);
        $employees = new EmployeesController($ctx);

        $r->get('/', $guard(Rights::VIEW, [$dashboard, 'index']));
        $r->get('/orders', $guard(Rights::VIEW, [$orders, 'index']));
        $r->get('/orders/{id:\d+}', $guard(Rights::VIEW, [$orders, 'show']));
        $r->get('/employees', $guard(Rights::ADMIN, [$employees, 'index']));
        $r->get('/employees/new', $guard(Rights::ADMIN, [$employees, 'form']));
        $r->get('/employees/{id:\d+}', $guard(Rights::ADMIN, [$employees, 'form']));
        $r->post('/employees', $guard(Rights::ADMIN, [$employees, 'save']));
        $r->post('/employees/{id:\d+}', $guard(Rights::ADMIN, [$employees, 'save']));
        $r->get('/account', $guard(Rights::VIEW, [$employees, 'accountForm']));
        $r->post('/account', $guard(Rights::VIEW, [$employees, 'accountSave']));

        $r->setNotFound(function (Request $req) use ($ctx) {
            if (!$ctx->isLoggedIn()) {
                return $ctx->redirectToLogin($req);   // don't reveal anything to anonymous visitors
            }
            return Response::html($ctx->page('notfound', [], 'Stránka neexistuje'), 404);
        });
        return $r->dispatch($request);
    }
}
