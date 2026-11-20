# WebApplication

Small PHP framework that runs several web applications (sites, admin panels, APIs) from one
installation on shared hosting. Each request is routed to one app by domain (and optionally path);
each app has its own folder, database table prefix and login type.

- PHP **7.3 – 8.4** (tested on both ends), MySQL/MariaDB via PDO.
- No build step — deploy by copying `src/`. Optional Composer libraries live in `src/vendor` (see `composer.json`;
  `src/bootstrap.php` loads `vendor/autoload.php` when it exists; deprecation notices from `src/vendor` are not logged).
- Apps written for the previous version keep working (`IWebApp`, old database API via a compatibility layer).

## Layout

```
src/                     ← deployed to the web root
  index.php              front controller (every non-file request ends here)
  bootstrap.php          autoloader (namespace WebApplication\ → framework/)
  framework/             the framework (not web-accessible)
  applications/<app>/    one folder per app; static assets public, PHP/views not
  config/                config.php (git-ignored) + config.template.php (not web-accessible)
  var/log/               log files app-YYYY-MM-DD.log.php (not web-accessible)
sql/schema.sql           tables the framework uses per app: client, token, log, text
tests/                   php tests/run.php
dev/                     Docker dev environment (PHP version selectable) + test runner
```

## Configuration — `src/config/config.php`

Copy `config.template.php`. The important part:

```php
'routes' => [                    // host or host/path → app; longest match wins; '*' = default
    'erma.sk' => 'erma',
    'www.erma.sk' => 'erma',
    'erma.sk/panel' => 'panel',  // app mounted on a path
],
'apps' => [
    'erma'  => ['folder' => 'erma',  'file' => 'WebApp.php', 'class' => 'WebApp',   'dbPrefix' => 'ermaweb_', 'auth' => 'none'],
    'panel' => ['folder' => 'panel', 'file' => 'App.php',    'class' => 'PanelApp', 'dbPrefix' => 'panel_',   'auth' => 'user'],
],
```

`auth`: `none`, `user` (browser login: session + optional remember-me cookie) or `api`
(devices/clients: `Authorization: Bearer <access token>` + `X-Refresh-Token`).

`debug => true` shows error details in the browser — **never on production**. Errors are always logged to `var/log/`.

Servers still using the old `ApplicationConfig.php` / `DatabaseConfig.php` / `JwtSecret.php` keep working;
the app is then chosen by the first part of the host name (`www.` is ignored).

## Writing an app

New style — implement `WebApplication\Http\RequestHandler` and return a `Response`.
`src/applications/demo/` is a complete example (routes, templates, login form with CSRF, JSON):

```php
class PanelApp implements RequestHandler
{
    public function handle(Request $request, Application $app): Response
    {
        $view = new View(__DIR__ . '/views');
        $r = new Router();                                   // paths are relative to the app's mount point
        $r->get('/', function () use ($view) { return $view->render('home', [], 'layout'); });
        $r->get('/invoices/{id:\d+}', function (Request $req, array $p) use ($app) {
            $db = $app->getDatabase();
            $row = $db->fetchOne('SELECT * FROM ' . $db->table('invoice') . ' WHERE id = ?', [$p['id']]);
            return $row ? Response::json($row) : Response::json(['error' => 'not found'], 404);
        });
        return $r->dispatch($request);
    }
}
```

Rules that keep apps safe:
- **Database:** values always as parameters (`?`), table names via `$db->table('name')` (adds the prefix).
- **Templates:** escape everything with `<?= $e($value) ?>`.
- **Forms:** `<?= Csrf::field() ?>` in the form, `Csrf::check($request)` before acting on POST.
- **Login:** `$app->getClientAuth()->login(...)`, `->isLoggedIn()`, `->getClient()`; the user is re-checked on
  every request (deactivated users are logged out immediately).

Old style — implement the global `IWebApp` (`get/post/put/delete/methodNotAllowed($rootApplication): string`).
Still supported; returned and echoed output is sent, `http_response_code()` is respected.

### What the app gets from `$app` (`Application`)

| Method | |
|---|---|
| `getRequest()` | method, host, path, `appPath()`, `appSegments()`, `query()`, `post()`, `input()`, `json()`, `file()` (upload), `header()`, `cookie()`, `bearerToken()`, `isHttps()`, `ip()` |
| `getDatabase()` | PDO wrapper: `query`, `fetchOne`, `fetchValue`, `execute`, `affected`, `lastInsertId`, `transaction`, `table` |
| `getClientAuth()` | `UserAuth` / `ApiAuth` / null |
| `url('/x')`, `asset('css/a.css')` | links inside the app / to files in the app folder |
| `getTextFromDatabase($lang, $id)` | page texts from `<prefix>text` (one query per page, cached) |
| `getUriSegment($i)`, `getHeader($n)`, `getApplicationName()`, `getLogger()`, `getConfig()`, `response()` | |

## Porting apps from the old core (`core/Application.php`, mysqli)

1. Move `WebApp_<name>/` to `src/applications/<name>/`, add the app to `config.php`.
2. Remove `require`s of the old core / old `IWebApp`; the class implements `IWebApp` as before.
3. Where the app got the old database object, use `$rootApplication->getLegacyDatabase()` — it provides
   `Query()`, `fetch_assoc()`, `num_rows`, `EscapeString()`, `GetNumRows()`, `GetLastInsertID()` with the same behaviour
   (values come back as strings like mysqli). `GetDbPrefix()`, `EscapeString()`, `Login()`, `Logout()`, `IsLoggedIn()`,
   `GetUser()` exist on the root application too.
4. Asset paths: `$rootApplication->asset('...')` → `/applications/<folder>/...`.
5. Then gradually replace string-built SQL + `EscapeString()` with parameterised `getDatabase()` queries.

### Apps of the OLDEST core (StadiumControl `WebServer/webapp/core`: `SetInitVariables()` + `GetHTML()`)

Those apps (hbkpravotice, bnhl — ported 2026-11-20) are run by `WebApplication\Legacy\OldCoreApplication`, which is
the old `$application` object on top of the framework: `GetTextFromDatabase` (texts by appid = app name),
`GetDatabase` (LegacyDatabase), `GetDbPrefix`, `EscapeString`, `DetectPage` / `GetContentFilename` (same page names as
before), `Login` / `Logout` / `IsLoggedIn` / `GetUser` with the app's own `<prefix>users` table (global classes `User`,
`ICRUD` in `framework/Legacy/oldcore/`). The app gets a small entry class (`applications/hbkpravotice/App.php`):

```php
\WebApplication\Legacy\OldCoreApplication::loadOldCore();      // User, ICRUD — before the app's own classes
require_once __DIR__ . '/WebApp.php';                           // the old page builder, class WebApp
class HbkpravoticeApp implements IWebApp {
    public function get($root): string { return \WebApplication\Legacy\OldCoreApplication::run($root, new WebApp()); }
    // post/put/delete = get (the old core built the page the same way for every method)
}
```

Changes made to the old files: includes of `./core/*` removed; relative includes → `__DIR__ . "/…"`; the old
`implements IWebApp` removed (same name as the framework's interface, different methods); `#[\AllowDynamicProperties]`
on the classes (they set undeclared properties; a comment on PHP 7.3); asset path = `/applications/<folder>` (an app
that writes `"/" . $AssetsPath` itself gets it without the leading slash). Login session = the framework session
(own cookie per app); "remember me" cookie `auth_token` is signed with `jwtSecret` and expires (the old core had the
key in its source and no expiry — old cookies are invalid, users log in once more). Checks: `dev/crawl-check.py`
(pages + assets of a site) and `tests/LegacyCoreTest.php`.

## Deploying to the hosting

Copy `src/` into the web root (FTP: as one zip + unpack script, not file by file). Never overwrite the root
`.htaccess` — it is shared with the shop and other folders. It must send unknown paths of the framework's domains
to `/index.php` (the existing rule does). `config/config.php` is created once on the server and not deployed.

## Development & tests

```bash
cd dev
docker compose up -d --build                      # http://<host>:8090/  (demo app, PHP 7.3)
docker compose exec web php /dev-tools/seed.php   # tables + user admin/admin
docker compose run --rm test                      # tests on PHP 7.3
PHP_VERSION=8.4 docker compose run --rm test      # tests on PHP 8.4
```

## Changes compared with the previous version

- App selection by explicit host/path table (`www.` domains and path-mounted apps work); 404 page for unknown hosts.
- Removed the `debug` request header that printed internal configuration to anyone.
- Errors are logged to `var/log/` and visitors see a plain error page; logs/config/framework code are not web-accessible.
- Fixed remember-me cookie (was written and read under different names), session is bound to its app,
  deactivated users are logged out, failed logins don't destroy the session, cookies are `HttpOnly` + `SameSite`.
- API auth no longer writes request headers (including tokens) into the log; refresh-token rotation fixed.
- `getTextFromDatabase()` works (table name was passed as a query parameter) and loads all texts in one query.
- New: Request/Response, in-app Router, View with escaping, CSRF, Session helpers, transactions, PHP 8.x support
  (no dynamic properties, explicit nullable types), legacy database compatibility layer, tests, Docker dev setup.
