# Writing a panel module

For developers adding a part to the employee section (`src/applications/panel`). The plan and the rules behind it:
`docs/panel-architektura.md` (rule 5: a module plugs itself in; chapter 5: permissions). Framework basics:
[README.md](README.md).

Contents: 1 what a module is · 2 step by step · 3 permissions · 4 checklist.

## 1. What a module is

A class in `src/Modules/<Name>Module.php` implementing `Panel\Module`, listed once in `PanelApp::modules()`
(`App.php`). It brings everything the panel needs to know about it:

| Method | Returns |
|---|---|
| `routes(Router $r, callable $guard, PanelContext $ctx)` | its routes, each wrapped in `$guard(permission, handler)` |
| `nav()` | items of the left menu (`key`, `group` from `Navigation::GROUPS`, `path`, `label`, `permission`, `order`, optional `tabs`) |
| `settingsTabs()` | tabs of Nastavenia (`key`, `path`, `label`, `permission`, `order`); pages use the section `settings:<key>` |
| `permissions()` | its permissions: `key` (`module.action`), `label` (Slovak, what it allows), `group` (heading on the forms), `sensitive` |

Business logic lives in its own classes (repository / service, e.g. `src/Expenses/Expenses.php`), HTTP in a controller
(`src/Controller/…`), HTML in `views/…`, tables in a migration `sql/NNN_name.sql`, the user guide in
`help/<slug>.md` + `help/_index.php`.

## 2. Step by step

1. **Migration** `sql/NNN_name.sql` — `CREATE TABLE IF NOT EXISTS {p}…`, must work on an EMPTY database and be
   harmless when re-run half-applied. Every record belongs to a company (`company_id`) — the system is not only for
   ERMA Group.
2. **Permission keys** — add constants to `Panel\Rights` (`const TASKS_VIEW = 'tasks.view';`) so other modules can
   check them without typos.
3. **Module class**:

   ```php
   namespace Panel\Modules;

   use Panel\Controller\TasksController;
   use Panel\Module;
   use Panel\PanelContext;
   use Panel\Rights;
   use WebApplication\Http\Router;

   /** Úlohy: list, kanban, my tasks. */
   class TasksModule implements Module
   {
       public function routes(Router $r, callable $guard, PanelContext $ctx): void
       {
           $c = new TasksController($ctx);
           $r->get('/tasks', $guard(Rights::TASKS_VIEW, [$c, 'index']));
           $r->post('/tasks', $guard(Rights::TASKS_EDIT, [$c, 'save']));
       }

       public function nav(): array
       {
           return [['key' => 'tasks', 'group' => 'work', 'path' => '/tasks', 'label' => 'Úlohy',
               'permission' => Rights::TASKS_VIEW, 'order' => 10]];
       }

       public function settingsTabs(): array
       {
           return [];
       }

       public function permissions(): array
       {
           return [
               ['key' => Rights::TASKS_VIEW, 'group' => 'Úlohy', 'sensitive' => false, 'label' => 'Úlohy — prezeranie'],
               ['key' => Rights::TASKS_EDIT, 'group' => 'Úlohy', 'sensitive' => false,
                   'label' => 'Úlohy — zakladanie a úpravy, komentáre'],
           ];
       }
   }
   ```

4. **List it** in `PanelApp::modules()` (the order = order in the menu and on the permission forms).
5. **Controller**: every page through `$ctx->page($template, $vars, $title, $section)`; every POST checks
   `Csrf::check($req)`; every change of business data `Audit::log(...)`; error messages say what to do.
6. **Guide**: `help/<slug>.md` (simple Slovak, step by step, common errors) and the page → guide mapping in
   `help/_index.php` — the test "Pomocník …" fails for a page without a guide.
7. **Tests** in `tests/PanelInvoicingTest.php` (or a new `tests/Panel…Test.php`): logic + permissions (who may /
   may not, lists filtered).

New permissions appear on the employee and role forms automatically; nobody has them until a Správca gives them
(Správca has them at once).

## 3. Permissions

**One check for everything** — `Panel\Core\Access` (through `PanelContext`):

| Call | Meaning |
|---|---|
| `$guard(Rights::X, $handler)` | route: login + permission (`''` = any employee, array = any of them) |
| `$ctx->can(Rights::X)` | buttons, parts of a page, checks inside a handler (e.g. an action needing a second permission) |
| `$ctx->companyIds()` / `canCompany($id)` / `allCompanies()` | company scope — every query of company data takes `companyIds()` |
| `$ctx->canShop($source, $shopId)` | e-shop scope (`$source` 0 = shop tables in this database, N = connection N) |
| `$ctx->shopVisible($source, $shopId)` | money of an e-shop: its e-shop scope AND the company it belongs to |
| `$ctx->isAdmin()` | Správca — only for things no role may give (rare; prefer a permission) |

Rules:

- **Check on the server**, never only by hiding a button. A handler that serves several permissions (e.g. statement
  import: invoices.edit or expenses.edit) checks inside.
- **Filter lists in the database**, not after loading (`WHERE company_id IN (...)`). Order sources are already
  restricted to the person's e-shops by `PanelContext` (`OrderSource::restrictShops`) — use `$ctx->orderSources()` /
  `$ctx->orderSource($id)`, never `new ShopOrders(...)` in a controller.
- **A record of another company / e-shop = "not found"** (404), not "forbidden" — don't reveal it exists.
- **Sensitive** permissions (`'sensitive' => true`): money (prices, margins, results), settings, keys, irreversible
  steps (un-issuing, deleting). Give them their own key instead of bundling them into `*.view`.
- Never add "admin only" checks — register a permission a role can give (`Rights::ADMIN_ONLY` is not used by any route).
- Layer 3 (sharing of single records: private / project / selected people) comes with tasks and documents (phase 2,
  4) through the record references of the core — design records with an owner and a company from the start.

Who has what (for orientation — the module does not touch it): Správca = `panel_client.rights >= 100` (everything);
others = permissions of their roles (`{p}role`, `{p}role_permission`, `{p}client_role`) + exceptions (`{p}permission`,
`allow` 1 / 0) within their companies (`{p}company_access` or `{p}client_scope.all_companies`) and e-shops
(`{p}shop_access` or `all_shops`). Editing is in `EmployeesController` with the rule "nobody gives more than he has".

## 4. Checklist

- [ ] migration works on an empty database; records have `company_id`
- [ ] constants in `Rights`, `permissions()` with clear Slovak labels, sensitive ones marked
- [ ] every route guarded, every list filtered by company / e-shop in SQL, foreign records → 404
- [ ] CSRF on every POST, `Audit::log` on every change, messages say what to do
- [ ] guide in `help/` + `_index.php`; developer notes here / in docs if the module adds a pattern
- [ ] tests incl. permissions; `php tests/run.php` green on the dev server (PHP 8.5)
