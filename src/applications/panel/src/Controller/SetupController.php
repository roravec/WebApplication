<?php
namespace Panel\Controller;

use Panel\PanelContext;
use Panel\Rights;
use WebApplication\Csrf;
use WebApplication\Entity\Client;
use WebApplication\Http\Request;
use WebApplication\Http\Response;
use WebApplication\Session;

/**
 * First administrator of a new installation: /setup?key=<setupKey>. Works ONLY while the panel has no employee account
 * at all, and only with the key derived from jwtSecret (config.php) — nobody without access to config.php can use it.
 * After the first account exists the page answers 404 for good. The owner sets his own password here (nobody else
 * ever sees it). Key: SetupController::key(jwtSecret) — Claude computes it (docs/nasadenie-a-udrzba.md).
 */
class SetupController
{
    /** @var PanelContext */
    private $ctx;

    public function __construct(PanelContext $ctx)
    {
        $this->ctx = $ctx;
    }

    public static function key(string $jwtSecret): string
    {
        return substr(hash_hmac('sha256', 'panel-first-admin', $jwtSecret), 0, 32);
    }

    public function form(Request $req, array $params, array $errors = [], array $values = [])
    {
        if (!$this->allowed($req)) {
            return Response::text('Stránka neexistuje.', 404);
        }
        return $this->ctx->view->render('setup', ['errors' => $errors, 'values' => $values + ['identifier' => '', 'name' => ''],
            'key' => (string)$req->query('key', ''), 'minPassword' => EmployeesController::MIN_PASSWORD, 'title' => 'Prvý správca']);
    }

    public function save(Request $req, array $params)
    {
        if (!$this->allowed($req)) {
            return Response::text('Stránka neexistuje.', 404);
        }
        if (!Csrf::check($req)) {
            return Response::text('Formulár vypršal, načítajte stránku znova.', 400);
        }
        $v = ['identifier' => EmployeesController::normalizeEmail((string)$req->post('identifier', '')),
              'name' => trim((string)$req->post('name', ''))];
        $password = (string)$req->post('password', '');
        $errors = [];
        if (($msg = EmployeesController::emailError($v['identifier'])) !== null) {
            $errors['identifier'] = $msg;
        }
        if ($v['name'] === '') {
            $errors['name'] = 'Zadajte meno.';
        }
        if (strlen($password) < EmployeesController::MIN_PASSWORD) {
            $errors['password'] = 'Heslo musí mať aspoň ' . EmployeesController::MIN_PASSWORD . ' znakov.';
        } elseif ($password !== (string)$req->post('password2', '')) {
            $errors['password2'] = 'Heslá sa nezhodujú.';
        }
        if ($errors) {
            return Response::html($this->form($req, $params, $errors, $v), 422);
        }

        $db = $this->ctx->app->getDatabase();
        if ((int)$db->fetchValue('SELECT GET_LOCK(?, 10)', ['panel_setup_' . $db->getPrefix()]) !== 1) {
            return Response::text('Skúste to znova o chvíľu.', 503);
        }
        try {
            if ($this->hasEmployees()) {   // somebody was faster
                return Response::text('Stránka neexistuje.', 404);
            }
            $client = new Client($db);
            $client->identifier = $v['identifier'];
            $client->name = $v['name'];
            $client->rights = Rights::ADMIN;
            $client->status = 1;
            $client->type = 0;
            $client->secret = $password;
            if (!$client->create()) {
                return Response::text('Uloženie zlyhalo.', 500);
            }
        } finally {
            $db->fetchValue('SELECT RELEASE_LOCK(?)', ['panel_setup_' . $db->getPrefix()]);
        }
        $this->ctx->app->getLogger()->info('Panel: first administrator created', ['login' => $v['identifier'], 'ip' => $req->ip()]);
        Session::flash('msg', 'Účet správcu je vytvorený — prihláste sa.');
        return Response::redirect($this->ctx->url('/login'));
    }

    private function allowed(Request $req): bool
    {
        $secret = (string)$this->ctx->app->getConfig()->get('jwtSecret', '');
        $given = (string)($req->query('key', '') ?: $req->post('key', ''));
        return $secret !== '' && $given !== '' && hash_equals(self::key($secret), $given) && !$this->hasEmployees();
    }

    private function hasEmployees(): bool
    {
        $db = $this->ctx->app->getDatabase();
        return (int)$db->fetchValue('SELECT COUNT(*) FROM ' . $db->table('client') . ' WHERE type = 0') > 0;
    }
}
