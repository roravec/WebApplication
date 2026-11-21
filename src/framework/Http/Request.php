<?php
namespace WebApplication\Http;

/** Immutable view of the current HTTP request. */
class Request
{
    /** @var string */ private $method;
    /** @var string */ private $host;
    /** @var string */ private $path;
    /** @var array */  private $query;
    /** @var array */  private $post;
    /** @var array */  private $headers;   // lower-case name => value
    /** @var array */  private $server;
    /** @var string */ private $rawBody;
    /** @var array */  private $cookies;
    /** @var array */  private $files;
    /** @var string */ private $basePath = '/';
    /** @var array|null */ private $json = null;

    public function __construct(string $method, string $host, string $path, array $query = [], array $post = [],
                                array $headers = [], array $server = [], string $rawBody = '', array $cookies = [], array $files = [])
    {
        $this->method = strtoupper($method);
        $this->host = strtolower(preg_replace('/:\d+$/', '', $host));
        $this->path = self::normalizePath($path);
        $this->query = $query;
        $this->post = $post;
        $this->headers = array_change_key_case($headers, CASE_LOWER);
        $this->server = $server;
        $this->rawBody = $rawBody;
        $this->cookies = $cookies;
        $this->files = $files;
    }

    public static function fromGlobals(): self
    {
        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (strncmp($k, 'HTTP_', 5) === 0) {
                $headers[str_replace('_', '-', substr($k, 5))] = $v;
            } elseif ($k === 'CONTENT_TYPE' || $k === 'CONTENT_LENGTH') {
                $headers[str_replace('_', '-', $k)] = $v;
            }
        }
        // Apache (CGI / FPM, often also mod_php) does not put "Authorization" into $_SERVER — API keys / JWT would be lost.
        // The shared root .htaccess must not be changed, so take it from where PHP still has it.
        if (!isset($headers['AUTHORIZATION'])) {
            $auth = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
            if ($auth === '' && function_exists('getallheaders')) {
                foreach ((array)getallheaders() as $name => $value) {
                    if (strcasecmp((string)$name, 'Authorization') === 0) {
                        $auth = (string)$value;
                    }
                }
            }
            if ($auth !== '') {
                $headers['AUTHORIZATION'] = $auth;
            }
        }
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        // Method override is only honoured for POST (HTML forms / clients without PUT/DELETE).
        if (strtoupper($method) === 'POST') {
            $override = $headers['X-HTTP-METHOD-OVERRIDE'] ?? ($_POST['_method'] ?? '');
            if (in_array(strtoupper($override), ['PUT', 'DELETE', 'PATCH'], true)) {
                $method = strtoupper($override);
            }
        }
        $path = self::pathFromUri((string)($_SERVER['REQUEST_URI'] ?? '/'));
        $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
        $body = (string)file_get_contents('php://input');
        return new self($method, $host, $path, $_GET, $_POST, $headers, $_SERVER, $body, $_COOKIE, $_FILES);
    }

    /**
     * Path of a request URI without query and fragment. Not parse_url(): it returns false for paths whose last segment
     * looks like "host:port" (e.g. /panel/r/invoice:7 — record short links), and the request would land on the wrong app.
     * Absolute-form URIs (http://host/path, sent through some proxies) are accepted too.
     */
    public static function pathFromUri(string $uri): string
    {
        $path = explode('#', explode('?', $uri, 2)[0], 2)[0];
        if (preg_match('#^[a-z][a-z0-9+.-]*://[^/]*(/.*)?$#i', $path, $m)) {
            $path = $m[1] ?? '';
        }
        return $path === '' ? '/' : $path;
    }

    /** Copy of the request with the path of the app's mount point (e.g. '/panel') remembered. */
    public function withBasePath(string $basePath): self
    {
        $r = clone $this;
        $r->basePath = $basePath === '' ? '/' : $basePath;
        return $r;
    }

    public function method(): string { return $this->method; }
    public function host(): string { return $this->host; }
    /** Full URL path, e.g. /panel/invoices/5 */
    public function path(): string { return $this->path; }
    public function basePath(): string { return $this->basePath; }

    /** Path inside the app, e.g. /invoices/5 for an app mounted on /panel. */
    public function appPath(): string
    {
        if ($this->basePath === '/' ) {
            return $this->path;
        }
        $rest = substr($this->path, strlen($this->basePath));
        return $rest === '' || $rest === false ? '/' : $rest;
    }

    /** Full path split into segments: /a/b/ → ['a','b'] */
    public function segments(): array
    {
        return self::split($this->path);
    }

    /** App-relative path segments. */
    public function appSegments(): array
    {
        return self::split($this->appPath());
    }

    public function query(?string $key = null, $default = null)
    {
        return $key === null ? $this->query : ($this->query[$key] ?? $default);
    }

    public function post(?string $key = null, $default = null)
    {
        return $key === null ? $this->post : ($this->post[$key] ?? $default);
    }

    /**
     * Uploaded file from a form with enctype="multipart/form-data": ['name', 'type', 'tmp_name', 'error', 'size'],
     * or null when nothing was uploaded for $key. Check 'error' === UPLOAD_ERR_OK and use is_uploaded_file().
     */
    public function file(string $key): ?array
    {
        $f = $this->files[$key] ?? null;
        if (!is_array($f) || !isset($f['error']) || is_array($f['error']) || $f['error'] === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        return $f;
    }

    /**
     * Several uploaded files from <input type="file" name="key[]" multiple> (or one from name="key"): list of
     * ['name', 'type', 'tmp_name', 'error', 'size'], empty slots (UPLOAD_ERR_NO_FILE) left out.
     */
    public function files(string $key): array
    {
        $f = $this->files[$key] ?? null;
        if (!is_array($f) || !isset($f['error'])) {
            return [];
        }
        if (!is_array($f['error'])) {
            return $f['error'] === UPLOAD_ERR_NO_FILE ? [] : [$f];
        }
        $out = [];
        foreach ($f['error'] as $i => $err) {
            if ($err !== UPLOAD_ERR_NO_FILE && !is_array($err)) {
                $out[] = ['name' => (string)($f['name'][$i] ?? ''), 'type' => (string)($f['type'][$i] ?? ''), 'tmp_name' => (string)($f['tmp_name'][$i] ?? ''),
                    'error' => (int)$err, 'size' => (int)($f['size'][$i] ?? 0)];
            }
        }
        return $out;
    }

    /** Value from JSON body, form body or query string (in that order). */
    public function input(string $key, $default = null)
    {
        $json = $this->json();
        if (is_array($json) && array_key_exists($key, $json)) {
            return $json[$key];
        }
        if (array_key_exists($key, $this->post)) {
            return $this->post[$key];
        }
        return $this->query[$key] ?? $default;
    }

    /** Decoded JSON body or null. */
    public function json(): ?array
    {
        if ($this->json === null && $this->rawBody !== '' && stripos($this->header('content-type', ''), 'json') !== false) {
            $decoded = json_decode($this->rawBody, true);
            $this->json = is_array($decoded) ? $decoded : [];
        }
        return $this->json;
    }

    public function rawBody(): string { return $this->rawBody; }

    /** Header by name, case-insensitive. */
    public function header(string $name, $default = null)
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function headers(): array { return $this->headers; }

    public function cookie(string $name, $default = null)
    {
        return $this->cookies[$name] ?? $default;
    }

    public function server(string $key, $default = null)
    {
        return $this->server[$key] ?? $default;
    }

    /** Bearer token from the Authorization header. */
    public function bearerToken(): ?string
    {
        $auth = (string)$this->header('authorization', '');
        if (preg_match('/^Bearer\s+(\S+)$/i', trim($auth), $m)) {
            return $m[1];
        }
        return null;
    }

    /** HTTPS directly or behind the hosting's proxy. */
    public function isHttps(): bool
    {
        $https = $this->server['HTTPS'] ?? '';
        if ($https !== '' && strtolower($https) !== 'off') {
            return true;
        }
        return strtolower((string)$this->header('x-forwarded-proto', '')) === 'https';
    }

    public function ip(): string
    {
        return (string)($this->server['REMOTE_ADDR'] ?? '');
    }

    public function isAjax(): bool
    {
        return strtolower((string)$this->header('x-requested-with', '')) === 'xmlhttprequest'
            || stripos((string)$this->header('accept', ''), 'application/json') !== false;
    }

    private static function normalizePath(string $path): string
    {
        $path = '/' . ltrim(rawurldecode($path), '/');
        $path = preg_replace('#/+#', '/', $path);
        return $path === '/' ? '/' : rtrim($path, '/');
    }

    private static function split(string $path): array
    {
        return array_values(array_filter(explode('/', $path), function ($s) { return $s !== ''; }));
    }
}
