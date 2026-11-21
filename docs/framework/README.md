# WebApplication — developer guide

For anyone (people or Claude sessions) who writes or changes an app on the WebApplication framework. Read it once
before your first change; keep it open while working. The short overview is in the repo `README.md`; the plan of the
panel in `docs/panel-architektura.md`; user guides of the panel in `src/applications/panel/help/`.

**Rule: a change of the framework's public API = a change of this guide in the same step.**

Contents: 1 how a request flows · 2 a new app step by step · 3 API reference · 4 security checklist ·
5 conventions · 6 patterns of the panel (bigger apps) · 7 tests · 8 deploy · 9 common mistakes.

## 1. How a request flows

```
browser ─► src/index.php ─► Application::run()
             │  Config::load()            config/config.php (or WA_CONFIG_FILE) — DB, jwtSecret, routes, apps
             │  AppResolver               host (+ path) → app definition  ('erma.sk/panel' → panel); longest match wins
             │  Request::withBasePath()   paths inside the app are relative to its mount point (/panel/orders → /orders)
             │  auth: none | user | api   user = session + login (UserAuth); api = Bearer token (ApiAuth)
             ▼
          your app: class implementing RequestHandler::handle(Request, Application): Response
             │  (old style: global IWebApp with get/post/put/delete returning a string — still supported)
             ▼
          Response::send()            status, headers, body
```

Errors: `ErrorHandler` logs everything to `src/var/log/` and shows a plain error page (details only with
`'debug' => true`, never on production).

## 2. A new app, step by step

1. **Folder**: `src/applications/<name>/` with `App.php`. Small apps can live in this repo (`demo`, `panel`); real
   sites have their **own repository** and are listed in `apps.json` (`python dev/apps.py sync` copies them for the
   dev server, `package NAME` into the deploy package). Never commit another app's files into this repo.
2. **Entry class** (copy `src/applications/demo/App.php`):
   ```php
   use WebApplication\Application;
   use WebApplication\Http\{Request, RequestHandler, Response, Router};
   use WebApplication\View;

   class ShopToolApp implements RequestHandler
   {
       public function handle(Request $request, Application $app): Response
       {
           $view = new View(__DIR__ . '/views');
           $view->share('app', $app);
           $r = new Router();
           $r->get('/', function () use ($view) { return $view->render('home', [], 'layout'); });
           $r->get('/items/{id:\d+}', function (Request $req, array $p) use ($app, $view) {
               $db = $app->getDatabase();
               $item = $db->fetchOne('SELECT * FROM ' . $db->table('item') . ' WHERE id = ?', [(int)$p['id']]);
               return $item ? $view->render('item', ['item' => $item], 'layout') : Response::html('Not found', 404);
           });
           return $r->dispatch($request);
       }
   }
   ```
3. **Config** (`config/config.php` on the server, `dev/config.php` on the dev server):
   ```php
   'routes' => ['tool.erma.sk' => 'shoptool', 'erma.sk/tool' => 'shoptool'],
   'apps'   => ['shoptool' => ['folder' => 'shoptool', 'file' => 'App.php', 'class' => 'ShopToolApp',
                               'dbPrefix' => 'shoptool_', 'auth' => 'user']],
   ```
   App-specific settings go into the app's entry (read with `$app->getConfig()->get('apps.shoptool.something')`) —
   **passwords and keys only in config.php**, never in the database or the repo.
4. **Database**: own **table prefix** (`dbPrefix`); every table name through `$db->table('item')`. Tables are created
   by **migrations** — numbered SQL files applied once in order. The panel's `Panel\Schema::migrate()`
   (`src/applications/panel/src/Schema.php`, tables `{p}…`, lock against parallel runs) is the pattern to copy.
   Production database `ermaskretail` is shared with other services → **only add tables with your own prefix,
   never drop or import the whole database**.
5. **Templates**: `views/<name>.php`, a `layout.php` with `<?= $content ?>`. Escape everything: `<?= $e($value) ?>`.
6. **Assets**: files in the app folder, linked with `$app->asset('css/app.css')`; add `?v=` + `filemtime()` so a
   browser loads a changed file right after an FTP upload (see the panel's `views/layout.php`).
7. **Login** (auth `user`): framework users are in `<prefix>client` (created by the framework schema / the panel's
   migration `000_framework_tables.sql`); `$app->getClientAuth()->login($email, $password, $remember)`, `isLoggedIn()`,
   `getClient()`. Forms need CSRF (section 4).
8. **Tests**: add a test file or tests to `tests/` (section 7).
9. **Deploy**: section 8.

## 3. API reference

### Application (`$app`)

| Method | What |
|---|---|
| `getRequest()` | the current `Request` |
| `getDatabase()` | `Database` with the app's prefix (lazy connection) |
| `getLegacyDatabase()` | mysqli-like layer for apps ported from the old core only |
| `getClientAuth()` | `UserAuth` / `ApiAuth` / null (auth none) |
| `getConfig()` | `Config` — `get('apps.panel.mail.host')` (dotted keys) |
| `getLogger()` | `Logger` |
| `url('/x')` | absolute path of a page of this app (mount point included) |
| `asset('css/a.css')` | URL of a file in the app folder |
| `getTextFromDatabase($lang, $id)` | page texts from `<prefix>text` (one query per request) |
| `getJwtSecret()` | secret for signing / encrypting (never print it) |
| `getApplicationName()`, `getDefinition()`, `isDebugEnabled()`, `getUriSegment($i)`, `getHeader($n)` | |

### Request

`method()`, `host()`, `path()`, `appPath()` (path inside the app), `segments()`, `appSegments()`,
`query($key, $default)` (GET), `post($key, $default)` (form fields; without a key = all), `input($key)` (GET or POST),
`json()` (JSON body or null), `rawBody()`, `file($key)` (uploaded file array or null — check `error` and
`is_uploaded_file`), `header($name)`, `headers()`, `cookie($name)`, `bearerToken()`, `isHttps()`, `ip()`.

### Response

`Response::html($html, $status)`, `::text()`, `::json($data, $status)`, `::redirect($url, $status = 302)`,
`new Response($body, $status, $headers)` (downloads: `Content-Type`, `Content-Disposition`). Methods `status()`,
`body()`, `header($name)`, `setHeader()`, `setStatus()`, `setBody()`. A handler may also return a string (= HTML 200).

### Router

```php
$r->get('/orders', $handler);                   // also post / put / patch / delete
$r->get('/orders/{id:\d+}', $handler);           // {name} = one segment; {name:regex} = custom
$r->get('/files/{path:.+}', $handler);           // regex may span segments
$r->setNotFound(fn (Request $req) => ...);       // 404 handler
return $r->dispatch($request);                   // first matching route wins (order matters)
```
Handlers: `function (Request $req, array $params)` or `[$controller, 'method']`; return `Response` or string.
Wrap handlers to check login / permissions in one place (see the panel's `$guard` in `App.php`).

### View

```php
$view = new View(__DIR__ . '/views');
$view->share('app', $app);                       // available in every template
echo $view->render('orders/list', ['rows' => $rows], 'layout');   // template, variables, optional layout
```
In a template: `$e($value)` escapes, `$view->partial('orders/row', [...])` includes another template, the layout gets
`$content`. Template names: letters, digits, `_`, `-`, `/` only.

### Session and flash messages

`Session::get/set/remove($key)`, `Session::flash('msg', 'Uložené.')` then `Session::flash('msg')` on the next page
(read once), `regenerate()` after login, `destroy()`. Sessions are per app (own cookie), `HttpOnly`, `SameSite`.

### CSRF

In every form that changes something: `<?= WebApplication\Csrf::field() ?>`; in the handler first:
`if (!Csrf::check($request)) { return Response::text('Formulár vypršal, načítajte stránku znova.', 400); }`.

### Database

```php
$db->query('SELECT * FROM ' . $db->table('item') . ' WHERE company_id = ? AND name LIKE ?', [$cid, "%$q%"]); // rows
$db->fetchOne($sql, $params);        // one row or false
$db->fetchValue($sql, $params);      // one value or null
$db->execute($sql, $params);         // bool
$db->affected($sql, $params);        // number of changed rows
$db->lastInsertId();
$db->transaction(function (Database $db) { ... return $x; });   // commit, or rollback + rethrow on an exception
```
**Values only as parameters (`?`)**; never put user input into SQL text. Identifiers (table names) through
`table()`. Row locks: `SELECT … FOR UPDATE` inside a transaction (see `Panel\Invoicing\Numbering`).

### Logger

`$app->getLogger()->info('Imported', ['count' => 5])`, `warning()`, `error()`; `withChannel('import')`. Never log
passwords, keys, tokens or full customer data.

### Authentication

- **UserAuth** (`auth => user`): `login($identifier, $password, $remember)`, `logout()`, `isLoggedIn()`,
  `getClient()` (`Client`: `getId()`, `getName()`, `getIdentifier()`, `getRights()`, `isActive()`), `hasRights($level)`.
  A deactivated user is logged out on the next request. Remember-me cookie is signed with `jwtSecret` and expires.
- **ApiAuth** (`auth => api`): clients send `Authorization: Bearer <access token>` (+ `X-Refresh-Token` to refresh);
  `login()` → `saveAuthorization()` → `tokenResponseData()`; `refresh()` rotates the refresh token (`Token` entity,
  `<prefix>token`). `Jwt::encode/decode` for own signed tokens.
- Passwords: `Client::hashSecret()` (never store or compare plain passwords).

## 4. Security checklist (before every commit)

- [ ] SQL: only parameters, `table()` for names.
- [ ] Output: every value from a user, the database or another system escaped with `$e()` (also in attributes).
- [ ] Forms that change data: CSRF field + `Csrf::check()`; changes only on POST, never on GET.
- [ ] Permissions: checked **on the server** for every action (not only by hiding a button); lists filtered in SQL.
- [ ] Uploads: size limit, `is_uploaded_file`, type checked by content (not by the name), stored outside the web root
      or behind a permission check; never executed.
- [ ] Secrets (DB password, API keys, tokens) only in `config.php` or encrypted (`Panel\Secret`), never in the repo,
      logs, URLs or error messages.
- [ ] External calls (APIs): timeouts, errors turned into readable messages, no secrets in the message.
- [ ] Redirects only to own URLs (`$app->url()`), not to a URL taken from the request.
- [ ] Errors never show stack traces to visitors (`debug` off on production).

## 5. Conventions

- **PHP 8.5** (hosting and dev). Existing code is 7.3-style (no typed properties) — keep the style of the file you edit;
  no dynamic properties, explicit `?Type` for null defaults, no deprecated functions (e.g. `curl_close`).
- Pure PHP libraries via Composer only (`composer.json` in the repo root, `src/vendor` not in git, deployed with `src/`).
- UTF-8 everywhere (input with broken encoding is rejected in the panel).
- Code and comments in English; **texts for people in Slovak** (simple, say what to do in an error).
- Line endings: the repo is checked out with CRLF (`core.autocrlf=true`); after editing run
  `python -X utf8 ~/.claude/tools/to_checkout_eol.py <repo>`.
- The owner commits himself — never commit, push or tag.

## 6. Patterns of the panel (use them in bigger apps)

- **Modules** (`src/applications/panel/src/Modules/`, interface `Panel\Module`): each part registers its routes, menu
  items (`nav()`), tabs of Nastavenia (`settingsTabs()`); `App.php` only lists them. `Panel\Navigation` builds the menu
  filtered by permissions. A page states its menu section in `PanelContext::page($template, $vars, $title, 'invoices:payments')`.
- **Context object** (`Panel\PanelContext`): one place for the user, permissions, database, services, `page()`.
- **Permissions** (`Panel\Rights`): permission strings per module; route guard `$guard($permission, $handler)`;
  administrators `Rights::ADMIN_ONLY`; companies the user may access (`canCompany()`).
- **Migrations**: `sql/NNN_name.sql`, `{p}` = table prefix, applied automatically on the next request after a deploy.
- **Audit** (`Panel\Audit::log`): who did what with which record — for every change of business data.
- **Secrets in the database** encrypted with `Panel\Secret` (AES-256-GCM, key from `jwtSecret`).
- **External systems** (PrestaShop Webservice, Fio, …): a small client class, timeouts, readable errors, tests with a
  fake (see `FakePrestaShopApi` in the tests).
- **User guides**: every page has a guide in `help/<slug>.md`, mapped in `help/_index.php`; a test fails when a page
  has none. Write them in simple Slovak, step by step.
- **Legal rules** as data with validity dates (docs/legislativa.md); issued documents freeze what applied.

## 7. Tests

`php tests/run.php` — a minimal runner (`test()`, `ok()`, `eq()`, `contains()`, `throws()`); tests with a database run
when `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` are set (otherwise skipped).
- Dev server: `cd ~/webapplication-dev/dev && COMPOSE_PROJECT_NAME=webapplication docker compose run --rm test`.
- Without the dev server (Windows, temporary): official PHP zip + MariaDB zip in a scratch folder, then
  `DB_HOST="127.0.0.1;port=3307" DB_NAME=webapp DB_USER=webapp DB_PASS=webapp php tests/run.php` (HANDOVER,
  "Dev environment").
- Every new feature: tests of the logic, of permissions (denied without the permission / outside the company) and of
  external calls with a fake. Pages: at least load them once (status 200, no PHP warnings).

## 8. Deploy

Shared hosting, FTP only. Copy `src/` (incl. `src/vendor`) into the web root — as a package built by
`python dev/apps.py package NAME` on the dev server, or single changed files of one app. **Never overwrite** the root
`.htaccess` or `config/config.php` (created once on the server). Database changes deploy themselves (migrations).
After a deploy: open the changed pages; the panel's guides tell users what changed. Plan and checklists:
`docs/nasadenie-a-udrzba.md`.

## 9. Common mistakes

- Forgetting `$e()` in a template (XSS) or building SQL from strings (SQL injection).
- A POST handler without `Csrf::check()`.
- Checking a permission only in the template (button hidden) but not in the handler.
- Writing into the e-shop's `ps_*` tables by SQL — **forbidden**; changes in a shop only through the PrestaShop
  Webservice (owner's rule).
- Hard-coding a company value (VAT rate, number format, accountant code) — they are settings or legal data.
- A new route that conflicts with an existing pattern (routes are matched in order: specific before generic).
- Adding a page without a user guide (the test catches it) or changing a feature without updating its guide.
