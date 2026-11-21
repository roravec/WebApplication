<?php
namespace Panel\Controller;

use Panel\Audit;
use Panel\Core\Permissions;
use Panel\Core\Roles;
use Panel\PanelContext;
use Panel\Rights;
use WebApplication\Csrf;
use WebApplication\Entity\Client;
use WebApplication\Http\Request;
use WebApplication\Http\Response;
use WebApplication\Session;

/**
 * Nastavenia → Zamestnanci a roly (docs/panel-architektura.md chapter 5): accounts (table panel_client, type 0), roles,
 * exceptions, companies and e-shops of each person, overview "who may do what"; Môj účet.
 * Rule (like API keys later): who is not Správca may change only people with no more rights than he has and give only
 * what he has himself; nobody can take away his own Správca / Zamestnanci a roly or deactivate himself.
 * Every change of rights goes to the activity log (entity employee / role).
 */
class EmployeesController
{
    const MIN_PASSWORD = 10;

    /** Employees log in with their e-mail (owner 2026-11-20) — stored lower-case in panel_client.identifier. */
    public static function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    public static function emailError(string $email): ?string
    {
        return strlen($email) <= 191 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false
            ? null : 'Zadajte platný e-mail — slúži na prihlásenie.';
    }

    /** @var PanelContext */
    private $ctx;

    public function __construct(PanelContext $ctx)
    {
        $this->ctx = $ctx;
    }

    private function repo(): Roles
    {
        return new Roles($this->ctx->db());
    }

    /** Access of the logged-in editor (Roles::person shape). */
    private function editor(): array
    {
        return $this->ctx->access()->person();
    }

    /** Ids of all existing companies (Roles::beyond ignores ids of deleted companies). @return int[] */
    private function allCompanyIds(): array
    {
        return array_map('intval', array_column($this->ctx->db()->query('SELECT id FROM ' . $this->ctx->db()->table('company')), 'id'));
    }

    /** Companies the editor may give: id => name. */
    private function companyChoices(): array
    {
        return array_column($this->ctx->companies()->all($this->ctx->companyIds()), 'name', 'id');
    }

    /**
     * E-shops the editor may give (his own e-shops, read from the order sources): list of [key "source:id_shop", name,
     * source]; error texts of unreachable sources in $errors.
     */
    private function shopChoices(array &$errors = []): array
    {
        $out = [];
        $sources = $this->ctx->orderSources();
        foreach ($sources as $id => $src) {
            try {
                foreach ($src->shops() as $s) {
                    $out[] = ['key' => $id . ':' . (int)$s['id_shop'], 'name' => (string)$s['name'], 'source' => count($sources) > 1 ? $src->label() : ''];
                }
            } catch (\Throwable $e) {
                $errors[] = $src->label() . ': ' . $e->getMessage();
            }
        }
        return $out;
    }

    private function shopNames(): array
    {
        $names = [];
        foreach ($this->shopChoices() as $s) {
            $names[$s['key']] = ($s['source'] !== '' ? $s['source'] . ' — ' : '') . $s['name'];
        }
        return $names;
    }

    // ------------------------------------------------------------------ people

    public function index(Request $req, array $params): string
    {
        $list = Client::readAll($this->ctx->db(), 'WHERE type = 0 ORDER BY status DESC, name');
        return $this->ctx->page('employees/list', ['employees' => $list, 'roleNames' => $this->repo()->roleNamesByClient()],
            'Zamestnanci a roly', 'settings:employees');
    }

    public function form(Request $req, array $params, array $errors = [], ?array $values = null): string
    {
        $db = $this->ctx->db();
        $client = null;
        if (isset($params['id'])) {
            $client = Client::readById($db, (int)$params['id']);
            if ($client === null || (int)$client->type !== 0) {
                return $this->ctx->page('notfound', [], 'Zamestnanec neexistuje', 'settings:employees');
            }
        }
        $repo = $this->repo();
        $current = $client ? $repo->person($client->getId(), $client->getRights())
            : ['admin' => false, 'roles' => [], 'allow' => [], 'deny' => [], 'all_companies' => false, 'companies' => [], 'all_shops' => true, 'shops' => []];
        $values = $values ?? [
            'identifier' => $client ? $client->identifier : '',
            'name' => $client ? $client->name : '',
            'status' => $client ? (int)$client->status : 1,
            'access' => $current,
        ];
        $shopErrors = [];
        $shops = $this->shopChoices($shopErrors);
        // someone with more rights than the editor: read only (he may not change him, not even the password)
        $beyond = $client ? $repo->beyond($this->editor(), $current, $this->allCompanyIds()) : [];
        return $this->ctx->page('employees/form', [
            'client' => $client, 'values' => $values, 'errors' => $errors, 'minPassword' => self::MIN_PASSWORD,
            'roles' => $repo->all(), 'groups' => Permissions::grouped(), 'companies' => $this->companyChoices(), 'shops' => $shops,
            'shopErrors' => $shopErrors, 'editorAdmin' => $this->ctx->isAdmin(), 'editorPerms' => $this->ctx->access()->permissions(),
            'readOnly' => $beyond, 'effective' => $repo->effective($values['access']),
        ], $client ? 'Upraviť zamestnanca' : 'Nový zamestnanec', 'settings:employees');
    }

    /** Access as posted on the employee form. */
    private function postedAccess(Request $req): array
    {
        $allow = $deny = [];
        foreach ((array)$req->post('perm', []) as $key => $mode) {
            if ($mode === 'allow') {
                $allow[] = (string)$key;
            } elseif ($mode === 'deny') {
                $deny[] = (string)$key;
            }
        }
        $shopKeys = array_values(array_filter(array_map('strval', (array)$req->post('shops', [])), function ($k) { return (bool)preg_match('/^\d+:\d+$/', $k); }));
        return [
            'admin' => (bool)$req->post('admin'),
            'roles' => array_values(array_unique(array_map('intval', (array)$req->post('roles', [])))),
            'allow' => Permissions::clean($allow),
            'deny' => Permissions::clean($deny),
            'all_companies' => (bool)$req->post('all_companies'),
            'companies' => array_values(array_unique(array_filter(array_map('intval', (array)$req->post('companies', []))))),
            'all_shops' => (bool)$req->post('all_shops'),
            'shops' => array_values(array_unique($shopKeys)),
        ];
    }

    public function save(Request $req, array $params)
    {
        if (!Csrf::check($req)) {
            return Response::text('Formulár vypršal, načítajte stránku znova.', 400);
        }
        $db = $this->ctx->db();
        $client = isset($params['id']) ? Client::readById($db, (int)$params['id']) : new Client($db);
        if ($client === null || ($client->exists() && (int)$client->type !== 0)) {
            return Response::html($this->ctx->page('notfound', [], 'Zamestnanec neexistuje', 'settings:employees'), 404);
        }
        $repo = $this->repo();
        $editor = $this->editor();
        $companyIds = $this->allCompanyIds();
        $old = $client->exists() ? $repo->person($client->getId(), $client->getRights())
            : ['admin' => false, 'roles' => [], 'allow' => [], 'deny' => [], 'all_companies' => false, 'companies' => [], 'all_shops' => true, 'shops' => []];
        if ($client->exists() && $repo->beyond($editor, $old, $companyIds)) {
            Session::flash('error', 'Tohto zamestnanca nemôžete upravovať — má viac práv ako vy.');
            return Response::redirect($this->ctx->url('/employees/' . $client->getId()));
        }
        $v = [
            'identifier' => self::normalizeEmail((string)$req->post('identifier', '')),
            'name' => trim((string)$req->post('name', '')),
            'status' => $req->post('status') ? 1 : 0,
            'access' => $this->postedAccess($req),
        ];
        // only existing companies (a company the editor does not see makes the access "beyond" him → refused below)
        $v['access']['companies'] = array_values(array_intersect($v['access']['companies'], $companyIds));
        $password = (string)$req->post('password', '');
        $errors = [];
        if (($msg = self::emailError($v['identifier'])) !== null) {
            $errors['identifier'] = $msg;
        } else {
            $other = Client::readByIdentifier($db, $v['identifier']);
            if ($other !== null && $other->getId() !== $client->getId()) {
                $errors['identifier'] = 'S týmto e-mailom už existuje iný účet.';
            }
        }
        if ($v['name'] === '') {
            $errors['name'] = 'Zadajte meno.';
        }
        if (!$client->exists() && $password === '') {
            $errors['password'] = 'Zadajte heslo.';
        }
        if ($password !== '' && strlen($password) < self::MIN_PASSWORD) {
            $errors['password'] = 'Heslo musí mať aspoň ' . self::MIN_PASSWORD . ' znakov.';
        }
        $me = $this->ctx->user();
        if ($client->exists() && $client->getId() === $me->getId()) {
            if (!$v['status'] || ($old['admin'] && !$v['access']['admin'])) {
                $errors['access'] = 'Sám sebe nemôžete odobrať práva správcu ani deaktivovať účet.';
            } elseif (!in_array(Rights::SETTINGS_EMPLOYEES, $repo->effective($v['access']), true)) {
                $errors['access'] = 'Sám sebe nemôžete odobrať právo „Zamestnanci a roly“ — inak by ste sa k nastaveniu už nedostali.';
            }
        }
        if (!$v['access']['all_shops'] && !$v['access']['shops'] && !$v['access']['admin']) {
            $errors['shops'] = 'Vyberte aspoň jeden e-shop, alebo zaškrtnite „Všetky e-shopy“.';
        }
        $beyond = $repo->beyond($editor, $v['access'], $companyIds);
        if ($beyond) {
            $errors['access'] = 'Nemôžete dať viac práv, ako máte sami: ' . implode(', ', $beyond) . '.';
        }
        if ($errors) {
            return Response::html($this->form($req, $params, $errors, $v), 422);
        }
        $isNew = !$client->exists();
        $before = ['identifier' => (string)$client->identifier, 'name' => (string)$client->name, 'status' => (int)$client->status];
        $client->identifier = $v['identifier'];
        $client->name = $v['name'];
        $client->rights = $v['access']['admin'] ? Rights::ADMIN : Rights::EMPLOYEE;
        $client->status = $v['status'];
        $client->type = 0;
        $client->secret = $password;
        $ok = $client->exists() ? $client->update() : $client->create();
        if ($ok) {
            $repo->savePerson($client->getId(), $v['access']);
            $changes = [];
            foreach (['name' => 'meno', 'identifier' => 'e-mail'] as $k => $label) {
                if (!$isNew && $before[$k] !== $v[$k]) {
                    $changes[] = $label . ': ' . $v[$k];
                }
            }
            if (!$isNew && $before['status'] !== $v['status']) {
                $changes[] = $v['status'] ? 'aktívny' : 'deaktivovaný';
            }
            if (!$isNew && $password !== '') {
                $changes[] = 'nové heslo';
            }
            $rights = $repo->describeChange($old, $v['access'], $this->companyChoices(), $this->shopNames());
            if ($rights !== '') {
                $changes[] = $rights;
            }
            if ($isNew || $changes) {
                Audit::log($db, $me, 'employee', $client->getId(), $isNew ? 'create' : 'update', $v['name'] . ($changes ? ' — ' . implode('; ', $changes) : ''));
            }
        }
        Session::flash($ok ? 'msg' : 'error', $ok ? 'Uložené. Zmeny práv platia hneď.' : 'Uloženie zlyhalo.');
        return Response::redirect($this->ctx->url('/employees'));
    }

    /** Kto čo smie: permissions × active people, their companies and e-shops. */
    public function overview(Request $req, array $params): string
    {
        $repo = $this->repo();
        $people = [];
        foreach (Client::readAll($this->ctx->db(), 'WHERE type = 0 AND status = 1 ORDER BY name') as $c) {
            $a = $repo->person($c->getId(), $c->getRights());
            $people[] = ['client' => $c, 'access' => $a, 'effective' => array_flip($repo->effective($a))];
        }
        $companyNames = array_column($this->ctx->companies()->all($this->allCompanyIds()), 'name', 'id');
        return $this->ctx->page('employees/overview', ['people' => $people, 'groups' => Permissions::grouped(),
            'roleNames' => $repo->roleNamesByClient(), 'companyNames' => $companyNames, 'shopNames' => $this->shopNames()],
            'Kto čo smie', 'settings:employees');
    }

    // ------------------------------------------------------------------ roles

    public function roles(Request $req, array $params): string
    {
        return $this->ctx->page('employees/roles', ['roles' => $this->repo()->all()], 'Roly', 'settings:employees');
    }

    public function roleForm(Request $req, array $params, array $errors = [], ?array $values = null): string
    {
        $repo = $this->repo();
        $role = isset($params['id']) ? $repo->find((int)$params['id']) : null;
        if (isset($params['id']) && $role === null) {
            return $this->ctx->page('notfound', [], 'Rola neexistuje', 'settings:employees');
        }
        $values = $values ?? ['name' => $role['name'] ?? '', 'description' => $role['description'] ?? '', 'permissions' => $role['permissions'] ?? []];
        $mine = $this->ctx->access()->permissions();
        $holders = [];
        if ($role) {
            foreach ($repo->holders($role['id']) as $id) {
                $c = Client::readById($this->ctx->db(), $id);
                if ($c) {
                    $holders[] = $c;
                }
            }
        }
        return $this->ctx->page('employees/role-form', ['role' => $role, 'values' => $values, 'errors' => $errors,
            'groups' => Permissions::grouped(), 'editorAdmin' => $this->ctx->isAdmin(), 'editorPerms' => $mine, 'holders' => $holders,
            'readOnly' => $role && !$this->ctx->isAdmin() ? array_values(array_diff($role['permissions'], $mine)) : []],
            $role ? 'Rola ' . $role['name'] : 'Nová rola', 'settings:employees');
    }

    public function roleSave(Request $req, array $params)
    {
        if (!Csrf::check($req)) {
            return Response::text('Formulár vypršal, načítajte stránku znova.', 400);
        }
        $repo = $this->repo();
        $role = isset($params['id']) ? $repo->find((int)$params['id']) : null;
        if (isset($params['id']) && $role === null) {
            return Response::html($this->ctx->page('notfound', [], 'Rola neexistuje', 'settings:employees'), 404);
        }
        $mine = $this->ctx->access()->permissions();
        if ($role && !$this->ctx->isAdmin() && array_diff($role['permissions'], $mine)) {
            Session::flash('error', 'Túto rolu nemôžete upravovať — dáva práva, ktoré sami nemáte.');
            return Response::redirect($this->ctx->url('/employees/roles/' . $role['id']));
        }
        $v = ['name' => trim((string)$req->post('name', '')), 'description' => mb_substr(trim((string)$req->post('description', '')), 0, 255, 'UTF-8'),
            'permissions' => Permissions::clean((array)$req->post('permissions', []))];
        $errors = [];
        if (($msg = $repo->nameError($v['name'], $role['id'] ?? null)) !== null) {
            $errors['name'] = $msg;
        }
        $extra = $this->ctx->isAdmin() ? [] : array_diff($v['permissions'], $mine);
        if ($extra) {
            $errors['permissions'] = 'Nemôžete dať viac práv, ako máte sami: ' . implode(', ', array_map([Permissions::class, 'label'], $extra)) . '.';
        }
        if ($errors) {
            return Response::html($this->roleForm($req, $params, $errors, $v), 422);
        }
        $id = $repo->save($role['id'] ?? null, $v['name'], $v['description'], $v['permissions']);
        $old = $role['permissions'] ?? [];
        $add = array_map(function ($p) { return '+' . Permissions::label($p); }, array_diff($v['permissions'], $old));
        $del = array_map(function ($p) { return '−' . Permissions::label($p); }, array_diff($old, $v['permissions']));
        $details = $v['name'] . (($add || $del) ? ' — ' . implode(', ', array_merge($add, $del)) : '')
            . ($role && $role['name'] !== $v['name'] ? ' (predtým ' . $role['name'] . ')' : '');
        Audit::log($this->ctx->db(), $this->ctx->user(), 'role', $id, $role ? 'update' : 'create', $details);
        Session::flash('msg', 'Rola uložená' . ($role && $role['holders'] ? ' — zmena platí hneď pre ' . $role['holders'] . ' ' . ($role['holders'] === 1 ? 'človeka' : 'ľudí') : '') . '.');
        return Response::redirect($this->ctx->url('/employees/roles'));
    }

    public function roleDelete(Request $req, array $params)
    {
        if (!Csrf::check($req)) {
            return Response::text('Formulár vypršal, načítajte stránku znova.', 400);
        }
        $repo = $this->repo();
        $role = $repo->find((int)$params['id']);
        if ($role === null) {
            return Response::html($this->ctx->page('notfound', [], 'Rola neexistuje', 'settings:employees'), 404);
        }
        if (!$this->ctx->isAdmin() && array_diff($role['permissions'], $this->ctx->access()->permissions())) {
            Session::flash('error', 'Túto rolu nemôžete zmazať — dáva práva, ktoré sami nemáte.');
            return Response::redirect($this->ctx->url('/employees/roles/' . $role['id']));
        }
        $me = $this->ctx->user();
        if (!$this->ctx->isAdmin() && in_array($me->getId(), $repo->holders($role['id']), true)) {
            $after = $repo->person($me->getId(), $me->getRights());
            $after['roles'] = array_values(array_diff($after['roles'], [$role['id']]));
            if (!in_array(Rights::SETTINGS_EMPLOYEES, $repo->effective($after), true)) {
                Session::flash('error', 'Túto rolu nemôžete zmazať — prišli by ste o právo „Zamestnanci a roly“.');
                return Response::redirect($this->ctx->url('/employees/roles/' . $role['id']));
            }
        }
        $repo->delete($role['id']);
        Audit::log($this->ctx->db(), $me, 'role', $role['id'], 'delete', $role['name'] . ($role['holders'] ? ' (odobratá ' . $role['holders'] . ' ľuďom)' : ''));
        Session::flash('msg', 'Rola „' . $role['name'] . '“ zmazaná.');
        return Response::redirect($this->ctx->url('/employees/roles'));
    }

    // ------------------------------------------------------------------ own account

    public function accountForm(Request $req, array $params, array $errors = []): string
    {
        $me = $this->ctx->user();
        $canKeys = $this->ctx->can(Rights::API_KEYS);
        return $this->ctx->page('employees/account', ['errors' => $errors, 'minPassword' => self::MIN_PASSWORD,
            'notifyMode' => $this->ctx->notifications()->mode($me->getId()), 'canApiKeys' => $canKeys,
            'apiKeys' => $canKeys ? (new \Panel\Core\ApiKeys($this->ctx->db()))->forOwner($me->getId()) : []],
            'Môj účet', 'account');
    }

    public function accountSave(Request $req, array $params)
    {
        if (!Csrf::check($req)) {
            return Response::text('Formulár vypršal, načítajte stránku znova.', 400);
        }
        $me = $this->ctx->user();
        $current = (string)$req->post('current', '');
        $new = (string)$req->post('password', '');
        $errors = [];
        if (Client::readByIdentifierAndSecret($this->ctx->app->getDatabase(), $me->getIdentifier(), $current) === null) {
            $errors['current'] = 'Súčasné heslo nie je správne.';
        }
        if (strlen($new) < self::MIN_PASSWORD) {
            $errors['password'] = 'Nové heslo musí mať aspoň ' . self::MIN_PASSWORD . ' znakov.';
        } elseif ($new !== (string)$req->post('password2', '')) {
            $errors['password2'] = 'Heslá sa nezhodujú.';
        }
        if ($errors) {
            return Response::html($this->accountForm($req, $params, $errors), 422);
        }
        $me->secret = $new;
        $me->update();
        Session::flash('msg', 'Heslo bolo zmenené.');
        return Response::redirect($this->ctx->url('/account'));
    }
}
