<?php
/**
 * erma.sk — public page for customers. Temporary placeholder (owner, 2026-11-19): a simple page for a company
 * that develops electronics; the real site will be designed later.
 */

use WebApplication\Application;
use WebApplication\Http\Request;
use WebApplication\Http\RequestHandler;
use WebApplication\Http\Response;
use WebApplication\Http\Router;
use WebApplication\View;

class ErmaApp implements RequestHandler
{
    public function handle(Request $request, Application $app): Response
    {
        $view = new View(__DIR__ . '/views');
        $view->share('app', $app);
        $company = (array)$app->getConfig()->get('apps.erma.company', []) + [
            'name' => 'ERMA Group s.r.o.',
            'slogan' => 'Smart IoT solutions',
            'street' => 'Pravotice 178',
            'city' => '956 35 Pravotice',
            'email' => 'info@erma.sk',
            'phone' => '+421 908 533 100',
            'ico' => '53036875',
        ];
        $panelUrl = (string)$app->getConfig()->get('apps.erma.panelUrl', '/panel');

        $r = new Router();
        $r->get('/', function () use ($view, $company, $panelUrl) {
            return $view->render('home', ['c' => $company, 'panelUrl' => $panelUrl]);
        });
        $r->setNotFound(function () use ($view, $company, $panelUrl) {
            return Response::html($view->render('home', ['c' => $company, 'panelUrl' => $panelUrl, 'notFound' => true]), 404);
        });
        return $r->dispatch($request);
    }
}
