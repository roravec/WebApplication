<?php
namespace Panel;

use Panel\Invoicing\Companies;
use Panel\Invoicing\Customers;
use Panel\Invoicing\Invoices;
use Panel\Shop\OrderSource;
use Panel\Shop\PrestaShopApi;
use Panel\Shop\ShopCache;
use Panel\Shop\ShopOrders;
use Panel\Shop\ShopOrdersApi;
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
    /** @var array|null */ private $connections = null;
    /** @var OrderSource[]|null */ private $orderSources = null;
    /** @var \Panel\Core\Access|null */ private $access = null;
    /** @var \Panel\Core\Refs|null */ private $refs = null;
    /** @var Client|null|false person this context acts for; false = the logged-in one */ private $userOverride = false;
    /** @var Navigation|null */ private $navigation = null;

    public function __construct(Application $app, Request $request)
    {
        $this->app = $app;
        $this->request = $request;
        $this->view = new View(dirname(__DIR__) . '/views');
        $this->view->share('app', $app)->share('ctx', $this)->share('f', new Format());
    }

    /** The menu and tabs (built from the modules in App.php). */
    public function setNavigation(Navigation $navigation): void
    {
        $this->navigation = $navigation;
    }

    public function navigation(): Navigation
    {
        if ($this->navigation === null) {
            $this->navigation = new Navigation();   // e.g. tests rendering a page without the app
        }
        return $this->navigation;
    }

    public function user(): ?Client
    {
        return $this->userOverride !== false ? $this->userOverride : $this->authUser();
    }

    /** The person logged in through the framework (session / JWT). */
    protected function authUser(): ?Client
    {
        $auth = $this->app->getClientAuth();
        return $auth ? $auth->getClient() : null;
    }

    /**
     * The same panel seen by another person (null = nobody — the scheduler, cron): access, record references and order
     * sources are his. Used to check "may HE see it" (notifications) and to run jobs / API calls.
     */
    public function asUser(?Client $user): self
    {
        $c = clone $this;
        $c->userOverride = $user;
        $c->access = null;
        $c->refs = null;
        $c->shopOrders = null;
        $c->orderSources = null;
        return $c;
    }

    /** Context of the scheduler / cron: no person, no restrictions of a person (jobs check what they need). */
    public function system(): self
    {
        return $this->asUser(null);
    }

    /** API call with a key: the key's owner, permissions limited to the key's (never more than the owner has now). */
    public function asApiKey(Client $owner, array $permissions): self
    {
        $c = $this->asUser($owner);
        $c->access()->limitTo($permissions);
        return $c;
    }

    public function notifications(): \Panel\Core\Notifications
    {
        return new \Panel\Core\Notifications($this->db());
    }

    /** Unread notifications of the logged-in person (the bell in the layout). */
    public function unreadNotifications(): int
    {
        $u = $this->user();
        try {
            return $u ? $this->notifications()->unreadCount($u->getId()) : 0;
        } catch (\PDOException $e) {
            return 0;   // before the migration ran
        }
    }

    public function isLoggedIn(): bool
    {
        return $this->user() !== null;
    }

    /** What the logged-in person may do (nobody logged in = nothing) — Core\Access, docs/panel-architektura.md ch. 5. */
    public function access(): \Panel\Core\Access
    {
        if ($this->access === null) {
            $u = $this->user();
            $this->access = new \Panel\Core\Access($this->db(), $u ? $u->getId() : 0, $u ? $u->getRights() : 0);
        }
        return $this->access;
    }

    /** @param string $permission Rights::ORDERS_VIEW, … or Rights::ADMIN_ONLY */
    public function can(string $permission): bool
    {
        return $this->user() !== null && $this->access()->can($permission);
    }

    /** Správca (everything, also future modules, every company and e-shop). */
    public function isAdmin(): bool
    {
        return $this->user() !== null && $this->access()->isAdmin();
    }

    /** @return int[] companies the employee may work with */
    public function companyIds(): array
    {
        return $this->user() !== null ? $this->access()->companyIds() : [];
    }

    public function canCompany(int $companyId): bool
    {
        return $this->user() !== null && $this->access()->canCompany($companyId);
    }

    /** All companies incl. future ones — also sees data without a company (e-shops not assigned to any company). */
    public function allCompanies(): bool
    {
        return $this->user() !== null && $this->access()->allCompanies();
    }

    /** @param int $source order source (0 = e-shops in this database, N = shop connection N) */
    public function canShop(int $source, int $shopId): bool
    {
        return $this->user() !== null && $this->access()->canShop($source, $shopId);
    }

    /**
     * May the person see the money of this e-shop (sales, margin, purchase prices, costs)? Its e-shop scope AND the
     * company the e-shop belongs to (an e-shop without a company: only people with all companies).
     */
    public function shopVisible(int $source, int $shopId): bool
    {
        if (!$this->canShop($source, $shopId)) {
            return false;
        }
        $company = $this->companies()->companyForShop($source, $shopId);
        return $company ? $this->canCompany($company) : $this->allCompanies();
    }

    /** Order source restricted to the person's e-shops (Access::shopIdsFor); no-one logged in (cron) = unrestricted. */
    private function scoped(\Panel\Shop\OrderSource $source): \Panel\Shop\OrderSource
    {
        if ($this->user() !== null) {
            $source->restrictShops($this->access()->shopIdsFor($source->id()));
        }
        return $source;
    }

    // ------------------------------------------------------------------ core services (docs/panel-architektura.md ch. 4)

    /** Record references of this person (resolve = title + URL only of what he may see; remembered per request). */
    public function refs(): \Panel\Core\Refs
    {
        if ($this->refs === null) {
            $this->refs = new \Panel\Core\Refs($this);
        }
        return $this->refs;
    }

    public function links(): \Panel\Core\Links
    {
        return new \Panel\Core\Links($this->db());
    }

    public function comments(): \Panel\Core\Comments
    {
        return new \Panel\Core\Comments($this->db());
    }

    public function activity(): \Panel\Core\Activity
    {
        return new \Panel\Core\Activity($this->db());
    }

    /**
     * Everything the box "Komentáre · Prepojenia · História" of a record page needs (views/core/record.php); null when
     * the person may not see the record.
     */
    public function recordPanel(string $ref): ?array
    {
        $record = $this->refs()->resolve($ref);
        if ($record === null) {
            return null;
        }
        $links = [];
        foreach ($this->links()->forRef($ref) as $l) {
            $other = $this->refs()->resolve($l['other']);
            if ($other !== null) {   // links to records the person may not see are not shown at all
                $links[] = $l + ['record' => $other];
            }
        }
        return ['record' => $record, 'comments' => $this->comments()->forRef($ref), 'links' => $links,
            'history' => $this->activity()->forRef($ref), 'people' => $this->comments()->people()];
    }

    public function companies(): Companies
    {
        return new Companies($this->app->getDatabase());
    }

    public function invoices(): Invoices
    {
        return new Invoices($this->app->getDatabase());
    }

    public function customers(): Customers
    {
        return new Customers($this->app->getDatabase());
    }

    public function renderer(): InvoiceRenderer
    {
        return new InvoiceRenderer($this);
    }

    /** E-mail sending with the server settings from config.php (apps.<panel>.mail). */
    public function mailer(): Mailer
    {
        return new Mailer((array)$this->app->getConfig()->get('apps.' . $this->app->getApplicationName() . '.mail', []));
    }

    /** Monthly packages for the accountant; PDFs = the archived PDFs of the issued documents. */
    public function accounting(): \Panel\Invoicing\AccountingPackage
    {
        $renderer = $this->renderer();
        return (new \Panel\Invoicing\AccountingPackage($this->db(), $this->invoices(), $this->companies(),
            function (array $inv) use ($renderer) { return $renderer->pdf($inv)[0]; }))->withExpenses($this->expenses());
    }

    /** Received documents and costs (docs/sprava-firmy-specifikacia.md, module C). */
    public function expenses(): \Panel\Expenses\Expenses
    {
        return new \Panel\Expenses\Expenses($this->db());
    }

    public function db(): \WebApplication\Database\Database
    {
        return $this->app->getDatabase();
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

    /** Colour theme: 'dark' (default) or 'light' — chosen by the toggle (js/theme.js), stored in cookie panel_theme. */
    public function theme(): string
    {
        return $this->request->cookie('panel_theme') === 'light' ? 'light' : 'dark';
    }

    /** Cookie path for the theme cookie = the panel's base path (e.g. "/panel"). */
    public function themeCookiePath(): string
    {
        $path = rtrim((string)parse_url($this->url('/'), PHP_URL_PATH), '/');
        return $path === '' ? '/' : $path;
    }

    /** Page inside the panel layout. */
    public function page(string $template, array $vars, string $title, string $section = ''): string
    {
        return $this->view->render($template, $vars + [
            'title' => $title,
            'section' => $section,
            'helpPage' => $template,   // the "? Pomocník" link opens the guide of this page (HelpModule)
            'user' => $this->user(),
            'flash' => Session::flash('msg'),
            'flashError' => Session::flash('error'),
        ], 'layout');
    }

    /** Orders in the PrestaShop tables of this database (order source 0). */
    public function shopOrders(): ShopOrders
    {
        if ($this->shopOrders === null) {
            $cfg = $this->shopConfig();
            $local = $this->localConnection();
            $this->shopOrders = new ShopOrders($this->app->getDatabase(), (string)($cfg['prefix'] ?? 'ps_'), (string)($cfg['lang'] ?? 'sk'),
                $local ? (string)$local['name'] : 'E-shopy (databáza)');
            $this->scoped($this->shopOrders);
        }
        return $this->shopOrders;
    }

    /** Sales and margin of the e-shops in this database (docs/sprava-firmy-specifikacia.md, module A). */
    public function salesReport(): \Panel\Reports\SalesReport
    {
        $cfg = $this->shopConfig();
        return new \Panel\Reports\SalesReport($this->app->getDatabase(), (string)($cfg['prefix'] ?? 'ps_'), (string)($cfg['lang'] ?? 'sk'));
    }

    /** Order cost rules (module D): shipping, payment fees, packaging per order. */
    public function orderCosts(): \Panel\Reports\OrderCosts
    {
        return new \Panel\Reports\OrderCosts($this->app->getDatabase(), (string)($this->shopConfig()['prefix'] ?? 'ps_'));
    }

    /**
     * Per shop and date: net amounts for a VAT payer, gross for a non-payer; a non-payer whose company setting says
     * "pripočítať DPH" counts purchase prices (entered without VAT in the shop) with the standard rate of that date.
     * For SalesReport::compute.
     */
    public function vatBasis(): callable
    {
        $companies = $this->companies();
        $legislation = new \Panel\Invoicing\Legislation($this->db());
        $cache = [];
        return function (int $shop, string $date) use ($companies, $legislation, &$cache) {
            $company = $companies->companyForShop(0, $shop);
            $k = (int)$company . '|' . substr($date, 0, 7);
            if (!isset($cache[$k])) {
                if (!$company) {
                    $cache[$k] = ['net' => false, 'cost_factor' => 1.0];   // no company: as a non-payer, prices as entered
                } else {
                    $payer = (bool)$companies->vatOn($company, $date)['vat_payer'];
                    $addVat = !$payer && $companies->exportSettings($company)['costs']['purchase_vat'] === 'add_vat';
                    $rate = $addVat ? (float)$legislation->standardRate('SK', $date) : 0.0;
                    $cache[$k] = ['net' => $payer, 'cost_factor' => 1 + $rate / 100];
                }
            }
            return $cache[$k];
        };
    }

    /** E-shops of this database (order source 0) invoiced by the company. @return int[] */
    public function companyShops(int $companyId): array
    {
        $out = [];
        foreach ($this->companies()->shopMap() as $key => $cid) {
            if ($cid === $companyId && strpos($key, '0:') === 0) {
                $out[] = (int)substr($key, 2);
            }
        }
        return $out;
    }

    private function shopConfig(): array
    {
        return (array)$this->app->getConfig()->get('apps.' . $this->app->getApplicationName() . '.shop', []);
    }

    // ------------------------------------------------------------------ connected e-shops (Nastavenia → E-shopy)

    /** @return array id => connection row (all, also inactive) */
    public function shopConnections(): array
    {
        if ($this->connections === null) {
            $this->connections = [];
            foreach ($this->db()->query('SELECT * FROM ' . $this->db()->table('shop_connection') . ' ORDER BY name') as $c) {
                $this->connections[(int)$c['id']] = $c;
            }
        }
        return $this->connections;
    }

    /** The active connection marked "this is the e-shop whose database the panel reads" (writes for order source 0). */
    public function localConnection(): ?array
    {
        foreach ($this->shopConnections() as $c) {
            if ($c['active'] && $c['local_db']) {
                return $c;
            }
        }
        return null;
    }

    public function shopApi(array $connection): PrestaShopApi
    {
        $key = (new Secret($this->app->getJwtSecret()))->decrypt((string)$connection['api_key']);
        if ($key === null) {
            throw new \RuntimeException('Uložený kľúč e-shopu „' . $connection['name'] . '“ sa nedá prečítať (zmenil sa jwtSecret?) — zadajte ho znova v Nastavenia → E-shopy.');
        }
        return new PrestaShopApi((string)$connection['url'], $key);
    }

    /**
     * Where orders are read from: 0 = shop tables in this database (when present), then every active connection that is
     * not "this database" — read through its Webservice. @return OrderSource[] source id => source
     */
    public function orderSources(): array
    {
        if ($this->orderSources === null) {
            $this->orderSources = [];
            if ($this->shopOrders()->available()) {
                $this->orderSources[0] = $this->shopOrders();
            }
            foreach ($this->shopConnections() as $id => $c) {
                if (!$c['active'] || $c['local_db']) {
                    continue;
                }
                try {
                    $this->orderSources[$id] = $this->scoped(new ShopOrdersApi($this->shopApi($c), $id, (string)$c['name'],
                        (string)($this->shopConfig()['lang'] ?? 'sk'), new ShopCache($this->db(), $id)));
                } catch (\Throwable $e) {
                    // unreadable key / bad URL — the source is left out; Nastavenia → E-shopy shows the connection
                }
            }
        }
        return $this->orderSources;
    }

    public function orderSource(int $id): ?OrderSource
    {
        return $this->orderSources()[$id] ?? null;
    }

    /** Webservice through which the state of orders of this source is changed (null = none). */
    public function orderWriteApi(int $source): ?PrestaShopApi
    {
        $c = $source === 0 ? $this->localConnection() : ($this->shopConnections()[$source] ?? null);
        return $c && $c['active'] ? $this->shopApi($c) : null;
    }

    /** Panel URL of an order: /orders/{id} for source 0 (unchanged links), /orders/{source}/{id} for a connection. */
    public function orderUrl(int $source, int $orderId, string $suffix = ''): string
    {
        return $this->url('/orders/' . ($source ? $source . '/' : '') . $orderId . $suffix);
    }
}
