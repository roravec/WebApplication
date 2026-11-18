<?php
/**
 * Example of a new-style app (reference for new apps such as the employee panel).
 * Shows: routing, templates with escaping, login with session + CSRF, JSON endpoint.
 *
 * config: 'demo' => ['folder' => 'demo', 'file' => 'App.php', 'class' => 'DemoApp', 'dbPrefix' => 'demo_', 'auth' => 'user']
 */

use WebApplication\Application;
use WebApplication\Csrf;
use WebApplication\Http\Request;
use WebApplication\Http\RequestHandler;
use WebApplication\Http\Response;
use WebApplication\Http\Router;
use WebApplication\Session;
use WebApplication\View;

class DemoApp implements RequestHandler
{
    /** @var Application */
    private $app;
    /** @var View */
    private $view;

    public function handle(Request $request, Application $app): Response
    {
        $this->app = $app;
        $this->view = new View(__DIR__ . '/views');
        $this->view->share('app', $app);

        $r = new Router();
        $r->get('/', [$this, 'home']);
        $r->get('/hello/{name}', [$this, 'hello']);
        $r->get('/login', [$this, 'loginForm']);
        $r->post('/login', [$this, 'login']);
        $r->post('/logout', [$this, 'logout']);
        $r->get('/api/status', [$this, 'status']);
        $r->setNotFound(function () {
            return Response::html($this->page('notfound', [], 'Not found'), 404);
        });
        return $r->dispatch($request);
    }

    public function home(Request $req, array $params): string
    {
        return $this->page('home', ['flash' => Session::flash('msg')], 'Demo');
    }

    public function hello(Request $req, array $params): string
    {
        // $params['name'] comes from the URL — the template escapes it
        return $this->page('hello', ['name' => $params['name']], 'Hello');
    }

    public function loginForm(Request $req, array $params): string
    {
        return $this->page('login', ['error' => null], 'Login');
    }

    public function login(Request $req, array $params): Response
    {
        if (!Csrf::check($req)) {
            return Response::text('Invalid form, reload the page.', 400);
        }
        $ok = $this->app->getClientAuth()->login((string)$req->post('login', ''), (string)$req->post('password', ''),
            (bool)$req->post('remember'));
        if (!$ok) {
            return Response::html($this->page('login', ['error' => 'Wrong login or password'], 'Login'), 401);
        }
        Session::flash('msg', 'Logged in');
        return Response::redirect($this->app->url('/'));
    }

    public function logout(Request $req, array $params): Response
    {
        if (Csrf::check($req)) {
            $this->app->getClientAuth()->logout();
        }
        return Response::redirect($this->app->url('/'));
    }

    public function status(Request $req, array $params): Response
    {
        $auth = $this->app->getClientAuth();
        return Response::json([
            'app' => $this->app->getApplicationName(),
            'loggedIn' => $auth !== null && $auth->isLoggedIn(),
            'time' => date('c'),
        ]);
    }

    private function page(string $template, array $vars, string $title): string
    {
        $auth = $this->app->getClientAuth();
        return $this->view->render($template, $vars + [
            'title' => $title,
            'user' => $auth ? $auth->getClient() : null,
        ], 'layout');
    }
}
