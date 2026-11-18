<?php
namespace WebApplication;

use WebApplication\Auth\ApiAuth;
use WebApplication\Auth\IAuth;
use WebApplication\Auth\UserAuth;
use WebApplication\Database\Database;
use WebApplication\Database\LegacyDatabase;
use WebApplication\Http\Request;
use WebApplication\Http\RequestHandler;
use WebApplication\Http\Response;

/**
 * Root application: picks the app for the request, prepares database/auth, runs the app, sends the response.
 * The app receives this object ($rootApplication) and uses the getters below.
 */
class Application
{
    /** @var Config */            private $config;
    /** @var Request */           private $request;
    /** @var Logger */            private $logger;
    /** @var AppDefinition|null */ private $definition = null;
    /** @var Database|null */     private $database = null;
    /** @var LegacyDatabase|null */ private $legacyDatabase = null;
    /** @var IAuth|null */        private $clientAuth = null;
    /** @var Response */          private $response;
    /** @var array texts cache: "lang" => [identifier => text] */
    private $texts = [];

    public function __construct(?Config $config = null, ?Request $request = null, ?Logger $logger = null)
    {
        $this->logger = $logger ?: new Logger();
        $this->config = $config ?: Config::load();
        $this->request = $request ?: Request::fromGlobals();
        $this->response = Response::html('');
        date_default_timezone_set((string)$this->config->get('timezone', 'Europe/Bratislava'));
    }

    /** Handle the current request and send the response. */
    public function run(): void
    {
        (new ErrorHandler($this->logger, (bool)$this->config->get('debug')))->register();
        $this->handle()->send();
    }

    /** Handle the request and return the response (used by run() and by tests). */
    public function handle(): Response
    {
        $this->definition = (new AppResolver($this->config))->resolve($this->request);
        if ($this->definition === null) {
            $this->logger->info('No app for request', ['host' => $this->request->host(), 'path' => $this->request->path()]);
            return Response::html(ErrorHandler::page(404, 'Page not found'), 404);
        }
        $this->request = $this->request->withBasePath($this->definition->basePath);

        if ($this->definition->auth === 'user') {
            Session::start($this->definition->name, $this->request->isHttps());
            $this->clientAuth = new UserAuth($this);
        } elseif ($this->definition->auth === 'api') {
            $this->clientAuth = new ApiAuth($this);
        }

        $instance = $this->loadApp($this->definition);
        if ($instance instanceof RequestHandler) {
            return $instance->handle($this->request, $this);
        }
        return $this->dispatchLegacy($instance);
    }

    private function loadApp(AppDefinition $d)
    {
        $file = $d->path() . '/' . $d->file;
        if (!is_file($file)) {
            throw new \RuntimeException("App '{$d->name}': file not found: $file");
        }
        require_once $file;
        if (!class_exists($d->class)) {
            throw new \RuntimeException("App '{$d->name}': class {$d->class} not found in $file");
        }
        $class = $d->class;
        $instance = new $class();
        if (!($instance instanceof \IWebApp) && !($instance instanceof RequestHandler)) {
            throw new \RuntimeException("App '{$d->name}': {$d->class} must implement IWebApp or RequestHandler");
        }
        if (property_exists($instance, 'name')) {
            $instance->name = $d->name;
        }
        return $instance;
    }

    /** IWebApp: call get/post/put/delete, collect returned + echoed output. */
    private function dispatchLegacy(\IWebApp $app): Response
    {
        $method = $this->request->method();
        ob_start();
        try {
            switch ($method) {
                case 'GET':
                case 'HEAD':
                    $body = $app->get($this);
                    break;
                case 'POST':
                    $body = $app->post($this);
                    break;
                case 'PUT':
                case 'PATCH':
                    $body = $app->put($this);
                    break;
                case 'DELETE':
                    $body = $app->delete($this);
                    break;
                default:
                    $body = $app->methodNotAllowed($method, $this);
                    $this->response->setStatus(405);
            }
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        $echoed = (string)ob_get_clean();
        // Old apps set the status with http_response_code(); keep it unless the app used $app->response().
        $status = http_response_code();
        if (is_int($status) && $status !== 200 && $this->response->status() === 200) {
            $this->response->setStatus($status);
        }
        return $this->response->setBody($echoed . $body);
    }

    // ---------------------------------------------------------------- getters for apps

    public function getRequest(): Request { return $this->request; }
    public function getConfig(): Config { return $this->config; }
    public function getLogger(): Logger { return $this->logger; }
    /** Response object for IWebApp apps: set status/headers here (body = what get()/post() return). */
    public function response(): Response { return $this->response; }
    public function getDefinition(): ?AppDefinition { return $this->definition; }
    public function getApplicationName(): string { return $this->definition ? $this->definition->name : ''; }
    public function getClientAuth(): ?IAuth { return $this->clientAuth; }
    public function isDebugEnabled(): bool { return (bool)$this->config->get('debug'); }
    public function getJwtSecret(): string { return (string)$this->config->get('jwtSecret', ''); }

    /** Database with the app's table prefix; connects on first use. */
    public function getDatabase(): Database
    {
        if ($this->database === null) {
            $c = $this->config->get('database');
            $this->database = new Database((string)$c['host'], (string)$c['name'], (string)$c['user'],
                (string)$c['password'], (int)$c['port'], (string)$c['charset']);
            $this->database->setPrefix($this->definition ? $this->definition->dbPrefix : '');
        }
        return $this->database;
    }

    /** For tests / CLI tools: use an existing connection. */
    public function setDatabase(Database $db): void
    {
        $this->database = $db;
        $this->legacyDatabase = null;
    }

    /** Old mysqli-style API (Query/EscapeString/fetch_assoc) on the same connection — for ported apps. */
    public function getLegacyDatabase(): LegacyDatabase
    {
        if ($this->legacyDatabase === null) {
            $this->legacyDatabase = new LegacyDatabase($this->getDatabase(), $this->logger->withChannel('sql'));
        }
        return $this->legacyDatabase;
    }

    /** Segment of the app-relative path: /panel/invoices/5 on app mounted at /panel → [0]=invoices, [1]=5 */
    public function getUriSegment(int $index): ?string
    {
        $s = $this->request->appSegments();
        return $s[$index] ?? null;
    }

    /** App-relative path segments. */
    public function detectPage(): array
    {
        return $this->request->appSegments();
    }

    public function getHeader(string $name): ?string
    {
        $v = $this->request->header($name);
        return $v === null ? null : (string)$v;
    }

    /** URL inside the current app: url('invoices/5') → /panel/invoices/5 */
    public function url(string $path = ''): string
    {
        $base = $this->definition ? rtrim($this->definition->basePath, '/') : '';
        return $base . '/' . ltrim($path, '/');
    }

    /** Public URL of a file in the app folder: asset('css/site.css') → /applications/erma/css/site.css */
    public function asset(string $path): string
    {
        $base = $this->definition ? $this->definition->publicPath() : '';
        return $base . '/' . ltrim($path, '/');
    }

    /**
     * Text from <prefix>text (columns language, identifier, appid, text); falls back to the identifier.
     * All texts of a language are loaded with one query and cached for the request.
     */
    public function getTextFromDatabase(string $language, string $textIdentifier): string
    {
        if (!isset($this->texts[$language])) {
            $this->texts[$language] = [];
            try {
                $db = $this->getDatabase();
                $rows = $db->query('SELECT identifier, text FROM ' . $db->table('text') . ' WHERE language = ? AND appid = ?',
                    [$language, $this->getApplicationName()]);
                foreach ($rows as $row) {
                    $this->texts[$language][$row['identifier']] = $row['text'];
                }
            } catch (\PDOException $e) {
                $this->logger->error('Loading texts failed: ' . $e->getMessage());
            }
        }
        return $this->texts[$language][$textIdentifier] ?? $textIdentifier;
    }

    /**
     * Maps a URL path to an .html file: /foo/bar → <dir>/foo-bar.html, / → index.html. Null if missing.
     */
    public function resolveHtmlFileFromPath(string $path, string $baseDir): ?string
    {
        $trimmed = trim($path, '/');
        if ($trimmed !== '' && !preg_match('#^[A-Za-z0-9_/-]+$#', $trimmed)) {
            return null;   // no "..", dots or other characters → no path traversal
        }
        $file = rtrim($baseDir, '/\\') . '/' . ($trimmed === '' ? 'index' : str_replace('/', '-', $trimmed)) . '.html';
        return is_file($file) ? $file : null;
    }

    // ---------------------------------------------------------------- legacy API of the old core (ported apps)

    public function GetDbPrefix(): string { return $this->definition ? $this->definition->dbPrefix : ''; }
    public function EscapeString($inString) { return $this->getLegacyDatabase()->EscapeString($inString); }
    public function Login($login, $password, $storeLogin = false) { return $this->clientAuth ? $this->clientAuth->login((string)$login, (string)$password, (bool)$storeLogin) : false; }
    public function Logout() { return $this->clientAuth ? $this->clientAuth->logout() : true; }
    public function IsLoggedIn() { return $this->clientAuth ? $this->clientAuth->isLoggedIn() : false; }
    public function GetUser() { return $this->clientAuth ? $this->clientAuth->getClient() : null; }
}
