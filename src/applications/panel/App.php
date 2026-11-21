<?php
/**
 * Employee section (erma.sk/panel): orders of the e-shops, e-shop products and stock, invoicing, costs, results,
 * own warehouses, settings — built from modules (src/Modules/, docs/panel-architektura.md).
 *
 * config:
 *   'panel' => ['folder' => 'panel', 'file' => 'App.php', 'class' => 'PanelApp', 'dbPrefix' => 'panel_', 'auth' => 'user',
 *               'shop' => ['prefix' => 'ps_', 'lang' => 'sk']]
 */

use Panel\Controller\AuthController;
use Panel\Controller\SetupController;
use Panel\Module;
use Panel\Modules;
use Panel\Navigation;
use Panel\PanelContext;
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
    /**
     * Parts of the panel in the order of the menu. A new part = a new class in src/Modules/ added here — it brings its
     * own routes, menu items and tabs of Nastavenia (docs/panel-architektura.md, rule 5). @return Module[]
     */
    public static function modules(): array
    {
        return [
            new Modules\DashboardModule(), new Modules\OrdersModule(), new Modules\EshopsModule(), new Modules\CustomersModule(),
            new Modules\InvoicingModule(), new Modules\ExpensesModule(), new Modules\ReportsModule(), new Modules\StockModule(),
            new Modules\CompaniesModule(), new Modules\LegislationModule(), new Modules\EmployeesModule(), new Modules\HelpModule(),
            new Modules\TasksModule(), new Modules\SettingsModule(), new Modules\RecordsModule(), new Modules\SystemModule(),
        ];
    }

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
        $nav = new Navigation();
        foreach (self::modules() as $module) {
            $nav->add($module);
        }
        $ctx->setNavigation($nav);

        $r = new Router();
        $auth = new AuthController($ctx);
        $r->get('/login', [$auth, 'form']);
        $r->post('/login', [$auth, 'login']);
        $r->post('/logout', [$auth, 'logout']);
        // first administrator of a new installation (only while there is no account; key from jwtSecret)
        $setup = new SetupController($ctx);
        $r->get('/setup', [$setup, 'form']);
        $r->post('/setup', [$setup, 'save']);

        // a logged-in employee with the permission ('' = any employee, array = any of them, Rights::ADMIN_ONLY)
        $guard = function ($permission, callable $handler) use ($ctx) {
            return function (Request $req, array $params) use ($ctx, $permission, $handler) {
                if (!$ctx->isLoggedIn()) {
                    return $ctx->redirectToLogin($req);
                }
                if (!Navigation::allowed($ctx, $permission)) {
                    return Response::html($ctx->page('forbidden', [], 'Prístup zamietnutý'), 403);
                }
                return $handler($req, $params);
            };
        };
        foreach ($nav->modules() as $module) {
            $module->routes($r, $guard, $ctx);
        }

        $r->setNotFound(function (Request $req) use ($ctx) {
            if (!$ctx->isLoggedIn()) {
                return $ctx->redirectToLogin($req);   // don't reveal anything to anonymous visitors
            }
            return Response::html($ctx->page('notfound', [], 'Stránka neexistuje'), 404);
        });
        return $r->dispatch($request);
    }
}
