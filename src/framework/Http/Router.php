<?php
namespace WebApplication\Http;

/**
 * Small router for use inside an app. Paths are app-relative (Request::appPath()).
 *
 *   $r = new Router();
 *   $r->get('/', [$this, 'home']);
 *   $r->get('/invoices/{id}', function (Request $req, array $params) { ... });
 *   $r->post('/invoices', [$this, 'create']);
 *   return $r->dispatch($request);
 *
 * Handlers get (Request $request, array $params) and return a Response or a string (→ HTML).
 * {name} matches one segment; {name:\d+} uses a custom regex.
 */
class Router
{
    /** @var array<int, array{0:string,1:string,2:callable}> */
    private $routes = [];
    /** @var callable|null */
    private $notFound = null;

    public function get(string $pattern, callable $handler): self { return $this->add('GET', $pattern, $handler); }
    public function post(string $pattern, callable $handler): self { return $this->add('POST', $pattern, $handler); }
    public function put(string $pattern, callable $handler): self { return $this->add('PUT', $pattern, $handler); }
    public function patch(string $pattern, callable $handler): self { return $this->add('PATCH', $pattern, $handler); }
    public function delete(string $pattern, callable $handler): self { return $this->add('DELETE', $pattern, $handler); }

    public function add(string $method, string $pattern, callable $handler): self
    {
        $this->routes[] = [strtoupper($method), $pattern, $handler];
        return $this;
    }

    public function setNotFound(callable $handler): self
    {
        $this->notFound = $handler;
        return $this;
    }

    public function dispatch(Request $request): Response
    {
        $path = $request->appPath();
        $allowed = [];
        foreach ($this->routes as $route) {
            list($method, $pattern, $handler) = $route;
            $params = self::match($pattern, $path);
            if ($params === null) {
                continue;
            }
            $isHeadForGet = $request->method() === 'HEAD' && $method === 'GET';
            if ($method !== $request->method() && !$isHeadForGet) {
                $allowed[] = $method;
                continue;
            }
            return self::toResponse(call_user_func($handler, $request, $params));
        }
        if ($allowed) {
            return Response::text('Method Not Allowed', 405)->setHeader('Allow', implode(', ', array_unique($allowed)));
        }
        if ($this->notFound) {
            return self::toResponse(call_user_func($this->notFound, $request, []));
        }
        return Response::text('Not Found', 404);
    }

    /** @return array|null named parameters, or null when the pattern does not match */
    public static function match(string $pattern, string $path): ?array
    {
        $pattern = $pattern === '' ? '/' : $pattern;
        $regex = preg_replace_callback('/\{([a-zA-Z_][a-zA-Z0-9_]*)(?::([^}]+))?\}/', function ($m) {
            return '(?P<' . $m[1] . '>' . (isset($m[2]) ? $m[2] : '[^/]+') . ')';
        }, rtrim($pattern, '/') ?: '/');
        if (!preg_match('#^' . $regex . '$#', $path, $m)) {
            return null;
        }
        $params = [];
        foreach ($m as $k => $v) {
            if (is_string($k)) {
                $params[$k] = $v;
            }
        }
        return $params;
    }

    private static function toResponse($result): Response
    {
        if ($result instanceof Response) {
            return $result;
        }
        return Response::html((string)$result);
    }
}
