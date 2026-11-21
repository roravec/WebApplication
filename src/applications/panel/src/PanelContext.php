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
    /** @var Rights|null */ private $rights = null;
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
        $auth = $this->app->getClientAuth();
        return $auth ? $auth->getClient() : null;
    }

    public function isLoggedIn(): bool
    {
        return $this->user() !== null;
    }

    /** Permissions of the logged-in employee (nobody logged in = no permissions). */
    public function rights(): Rights
    {
        if ($this->rights === null) {
            $u = $this->user();
            $this->rights = new Rights($this->app->getDatabase(), $u ? $u->getId() : 0, $u ? $u->getRights() : 0);
        }
        return $this->rights;
    }

    /** @param string $permission Rights::ORDERS_VIEW, … or Rights::ADMIN_ONLY */
    public function can(string $permission): bool
    {
        return $this->user() !== null && $this->rights()->can($permission);
    }

    public function isAdmin(): bool
    {
        return $this->user() !== null && $this->rights()->isAdmin();
    }

    /** @return int[] companies the employee may work with */
    public function companyIds(): array
    {
        return $this->user() !== null ? $this->rights()->companyIds() : [];
    }

    public function canCompany(int $companyId): bool
    {
        return $this->user() !== null && $this->rights()->canCompany($companyId);
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
                    $this->orderSources[$id] = new ShopOrdersApi($this->shopApi($c), $id, (string)$c['name'],
                        (string)($this->shopConfig()['lang'] ?? 'sk'), new ShopCache($this->db(), $id));
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
