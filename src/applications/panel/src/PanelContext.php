<?php
namespace Panel;

use Panel\Shop\ShopOrders;
use WebApplication\Application;
use WebApplication\Entity\Client;
use WebApplication\Http\Request;
use WebApplication\Http\Response;
use WebApplication\Session;
use WebApplication\View;

/** Shared things for all panel controllers: app, request, view, logged-in employee, shop data access. */
class PanelContext
{
    /** @var Application */ public $app;
    /** @var Request */ public $request;
    /** @var View */ public $view;
    /** @var ShopOrders|null */ private $shopOrders = null;

    public function __construct(Application $app, Request $request)
    {
        $this->app = $app;
        $this->request = $request;
        $this->view = new View(dirname(__DIR__) . '/views');
        $this->view->share('app', $app)->share('ctx', $this)->share('f', new Format());
    }

    public function user(): ?Client
    {
        $auth = $this->app->getClientAuth();
        return $auth ? $auth->getClient() : null;
    }

    public function isLoggedIn(): bool
    {
        return $this->user() !== null;
    }

    public function can(int $rights): bool
    {
        $u = $this->user();
        return $u !== null && $u->getRights() >= $rights;
    }

    /** Redirect to the login page, remembering the requested page (path + query). */
    public function redirectToLogin(Request $req): Response
    {
        $next = $req->path();
        $query = $req->query();
        if ($query) {
            $next .= '?' . http_build_query($query);
        }
        return Response::redirect($this->url('/login') . '?next=' . rawurlencode($next));
    }

    public function url(string $path = '/'): string
    {
        return $this->app->url($path);
    }

    /** Page inside the panel layout. */
    public function page(string $template, array $vars, string $title, string $section = ''): string
    {
        return $this->view->render($template, $vars + [
            'title' => $title,
            'section' => $section,
            'user' => $this->user(),
            'flash' => Session::flash('msg'),
            'flashError' => Session::flash('error'),
        ], 'layout');
    }

    public function shopOrders(): ShopOrders
    {
        if ($this->shopOrders === null) {
            $cfg = (array)$this->app->getConfig()->get('apps.' . $this->app->getApplicationName() . '.shop', []);
            $this->shopOrders = new ShopOrders($this->app->getDatabase(), (string)($cfg['prefix'] ?? 'ps_'), (string)($cfg['lang'] ?? 'sk'));
        }
        return $this->shopOrders;
    }
}
