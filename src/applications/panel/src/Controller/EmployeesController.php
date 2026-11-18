<?php
namespace Panel\Controller;

use Panel\PanelContext;
use Panel\Rights;
use WebApplication\Csrf;
use WebApplication\Entity\Client;
use WebApplication\Http\Request;
use WebApplication\Http\Response;
use WebApplication\Session;

/** Employee accounts (table panel_client, type 0) and the logged-in user's own account. */
class EmployeesController
{
    const MIN_PASSWORD = 10;

    /** @var PanelContext */
    private $ctx;

    public function __construct(PanelContext $ctx)
    {
        $this->ctx = $ctx;
    }

    public function index(Request $req, array $params): string
    {
        $list = Client::readAll($this->ctx->app->getDatabase(), 'WHERE type = 0 ORDER BY status DESC, name');
        return $this->ctx->page('employees/list', ['employees' => $list], 'Zamestnanci', 'employees');
    }

    public function form(Request $req, array $params, array $errors = [], ?array $values = null): string
    {
        $client = null;
        if (isset($params['id'])) {
            $client = Client::readById($this->ctx->app->getDatabase(), (int)$params['id']);
            if ($client === null) {
                return $this->ctx->page('notfound', [], 'Zamestnanec neexistuje', 'employees');
            }
        }
        $values = $values ?? [
            'identifier' => $client ? $client->identifier : '',
            'name' => $client ? $client->name : '',
            'rights' => $client ? $client->getRights() : Rights::VIEW,
            'status' => $client ? $client->status : 1,
        ];
        return $this->ctx->page('employees/form', ['client' => $client, 'values' => $values, 'errors' => $errors,
            'minPassword' => self::MIN_PASSWORD], $client ? 'Upraviť zamestnanca' : 'Nový zamestnanec', 'employees');
    }

    public function save(Request $req, array $params)
    {
        if (!Csrf::check($req)) {
            return Response::text('Formulár vypršal, načítajte stránku znova.', 400);
        }
        $db = $this->ctx->app->getDatabase();
        $client = isset($params['id']) ? Client::readById($db, (int)$params['id']) : new Client($db);
        if ($client === null) {
            return Response::html($this->ctx->page('notfound', [], 'Zamestnanec neexistuje', 'employees'), 404);
        }
        $v = [
            'identifier' => trim((string)$req->post('identifier', '')),
            'name' => trim((string)$req->post('name', '')),
            'rights' => (int)$req->post('rights', Rights::VIEW),
            'status' => $req->post('status') ? 1 : 0,
        ];
        $password = (string)$req->post('password', '');
        $errors = [];
        if (!preg_match('/^[A-Za-z0-9._@+-]{3,100}$/', $v['identifier'])) {
            $errors['identifier'] = 'Prihlasovacie meno: 3–100 znakov (písmená, čísla, . _ @ + -).';
        } else {
            $other = Client::readByIdentifier($db, $v['identifier']);
            if ($other !== null && $other->getId() !== $client->getId()) {
                $errors['identifier'] = 'Toto prihlasovacie meno už existuje.';
            }
        }
        if ($v['name'] === '') {
            $errors['name'] = 'Zadajte meno.';
        }
        if (!array_key_exists($v['rights'], Rights::labels())) {
            $errors['rights'] = 'Neplatné oprávnenie.';
        }
        if (!$client->exists() && $password === '') {
            $errors['password'] = 'Zadajte heslo.';
        }
        if ($password !== '' && strlen($password) < self::MIN_PASSWORD) {
            $errors['password'] = 'Heslo musí mať aspoň ' . self::MIN_PASSWORD . ' znakov.';
        }
        $me = $this->ctx->user();
        if ($client->getId() === $me->getId() && ($v['rights'] < Rights::ADMIN || !$v['status'])) {
            $errors['rights'] = 'Sám sebe nemôžete odobrať práva správcu ani deaktivovať účet.';
        }
        if ($errors) {
            return Response::html($this->form($req, $params, $errors, $v), 422);
        }
        $client->identifier = $v['identifier'];
        $client->name = $v['name'];
        $client->rights = $v['rights'];
        $client->status = $v['status'];
        $client->type = 0;
        $client->secret = $password;
        $ok = $client->exists() ? $client->update() : $client->create();
        Session::flash($ok ? 'msg' : 'error', $ok ? 'Uložené.' : 'Uloženie zlyhalo.');
        return Response::redirect($this->ctx->url('/employees'));
    }

    public function accountForm(Request $req, array $params, array $errors = []): string
    {
        return $this->ctx->page('employees/account', ['errors' => $errors, 'minPassword' => self::MIN_PASSWORD],
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
