<?php
namespace Panel\Controller;

use Panel\PanelContext;
use WebApplication\Csrf;
use WebApplication\Http\Request;
use WebApplication\Http\Response;
use WebApplication\Session;

class AuthController
{
    /** @var PanelContext */
    private $ctx;

    public function __construct(PanelContext $ctx)
    {
        $this->ctx = $ctx;
    }

    public function form(Request $req, array $params)
    {
        if ($this->ctx->isLoggedIn()) {
            return Response::redirect($this->ctx->url('/'));
        }
        return $this->render(null, (string)$req->query('next', ''));
    }

    public function login(Request $req, array $params): Response
    {
        $next = (string)$req->post('next', '');
        if (!Csrf::check($req)) {
            return Response::html($this->render('Formulár vypršal, skúste to znova.', $next), 400);
        }
        $ok = $this->ctx->app->getClientAuth()->login(
            EmployeesController::normalizeEmail((string)$req->post('login', '')), (string)$req->post('password', ''),
            (bool)$req->post('remember')
        );
        if (!$ok) {
            $this->ctx->app->getLogger()->warning('Panel login failed', ['login' => (string)$req->post('login', ''), 'ip' => $req->ip()]);
            usleep(500000);   // slow down password guessing
            return Response::html($this->render('Nesprávne prihlasovacie meno alebo heslo.', $next), 401);
        }
        // only allow redirects inside the panel
        $base = $this->ctx->url('/');
        $safe = $next !== '' && $next[0] === '/' && strpos($next, '//') === false && strpos($next, '\\') === false
            && strpos($next, rtrim($base, '/')) === 0;
        $target = $safe ? $next : $base;
        return Response::redirect($target);
    }

    public function logout(Request $req, array $params): Response
    {
        if (Csrf::check($req)) {
            $this->ctx->app->getClientAuth()->logout();
            Session::start($this->ctx->app->getApplicationName(), $req->isHttps());
            Session::flash('msg', 'Boli ste odhlásený.');
        }
        return Response::redirect($this->ctx->url('/login'));
    }

    private function render(?string $error, string $next): string
    {
        return $this->ctx->view->render('login', ['error' => $error, 'next' => $next, 'title' => 'Prihlásenie',
            'flash' => Session::flash('msg')]);
    }
}
