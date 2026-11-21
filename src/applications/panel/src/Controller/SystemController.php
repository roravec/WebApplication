<?php
namespace Panel\Controller;

use Panel\Core\Notifications;
use Panel\Core\Scheduler;
use Panel\PanelContext;
use WebApplication\Csrf;
use WebApplication\Http\Request;
use WebApplication\Http\Response;
use WebApplication\Session;

/**
 * Core services without a record (docs/panel-architektura.md chapter 4): scheduler (cron URL, tick, Nastavenia →
 * Systém), notifications (bell, list, e-mail preference).
 */
class SystemController
{
    /** @var PanelContext */
    private $ctx;

    public function __construct(PanelContext $ctx)
    {
        $this->ctx = $ctx;
    }

    private function scheduler(): Scheduler
    {
        return new Scheduler($this->ctx->db());
    }

    private function cronToken(): string
    {
        $app = $this->ctx->app;
        return (string)$app->getConfig()->get('apps.' . $app->getApplicationName() . '.cron_token', '');
    }

    // ------------------------------------------------------------------ scheduler

    /** GET /cron?token=… (and the older /cron/accounting) — a cron of the hosting; 404 without the right token. */
    public function cron(Request $req, array $params): Response
    {
        $token = $this->cronToken();
        if (strlen($token) < 20 || !hash_equals($token, (string)$req->query('token', ''))) {
            return Response::text('Not found', 404);
        }
        $out = [];
        foreach ($this->scheduler()->run($this->ctx->system()) as list($key, $ok, $msg)) {
            $out[] = ($ok ? 'OK ' : 'CHYBA ') . $key . ': ' . $msg;
        }
        return Response::text($out ? implode("\n", $out) : 'Nič nie je na rade.');
    }

    /**
     * POST /cron/tick — sent quietly by open panel pages (js/panel.js), so the jobs run also without a cron. Any
     * logged-in employee; no CSRF token on purpose: it can only start jobs that are due anyway (at most once a minute).
     */
    public function tick(Request $req, array $params): Response
    {
        $n = 0;
        if ($this->scheduler()->tickDue(time())) {
            @set_time_limit(60);
            $n = count($this->scheduler()->run($this->ctx->system()));
        }
        return Response::json(['ran' => $n]);
    }

    public function settings(Request $req, array $params): string
    {
        $token = $this->cronToken();
        $req0 = $this->ctx->request;
        $base = ($req0->isHttps() ? 'https' : 'http') . '://' . $req0->host() . $this->ctx->url('/cron');
        return $this->ctx->page('settings/system', ['jobs' => Scheduler::jobs(), 'state' => $this->scheduler()->state(),
            'cronUrl' => strlen($token) >= 20 ? $base . '?token=' . $token : '', 'mailOk' => $this->ctx->mailer()->configured()],
            'Systém — plánovač', 'settings:system');
    }

    /** POST /settings/system/run/{key} — run one job now. */
    public function runJob(Request $req, array $params): Response
    {
        if (!Csrf::check($req)) {
            return Response::text('Formulár vypršal, načítajte stránku znova.', 400);
        }
        $key = (string)$params['key'];
        if (!isset(Scheduler::jobs()[$key])) {
            return Response::html($this->ctx->page('notfound', [], 'Úloha neexistuje', 'settings:system'), 404);
        }
        $r = $this->scheduler()->run($this->ctx->system(), $key, true);
        if (!$r) {
            Session::flash('error', 'Úloha práve beží — skúste o chvíľu.');
        } else {
            Session::flash($r[0][1] ? 'msg' : 'error', Scheduler::jobs()[$key]['label'] . ': ' . $r[0][2]);
        }
        return Response::redirect($this->ctx->url('/settings/system'));
    }

    // ------------------------------------------------------------------ calendar

    public function calendar(Request $req, array $params): string
    {
        $month = preg_match('/^\d{4}-\d{2}$/', (string)$req->query('month', '')) ? (string)$req->query('month') : date('Y-m');
        $weeks = \Panel\Core\Calendar::monthGrid($month);
        $events = \Panel\Core\Calendar::events($this->ctx, $weeks[0][0], end($weeks)[6]);
        $today = date('Y-m-d');
        $upcoming = \Panel\Core\Calendar::events($this->ctx, $today, date('Y-m-d', strtotime('+14 days')));
        return $this->ctx->page('core/calendar', ['month' => $month, 'weeks' => $weeks, 'events' => $events, 'upcoming' => $upcoming],
            'Kalendár', 'calendar');
    }

    // ------------------------------------------------------------------ integrations

    public function integrations(Request $req, array $params, array $errors = [], ?array $values = null): string
    {
        $files = new \Panel\Core\Files($this->ctx);
        $int = $files->integrations();
        $fio = (int)$this->ctx->db()->fetchValue('SELECT COUNT(*) FROM ' . $this->ctx->db()->table('company_bank') . " WHERE api_token IS NOT NULL AND api_token <> ''");
        return $this->ctx->page('settings/integrations', ['types' => \Panel\Core\Integrations::TYPES, 'rows' => ['nextcloud' => $int->row('nextcloud')],
            'values' => $values ?? ['nextcloud' => $int->formValues('nextcloud')], 'errors' => $errors,
            'shops' => $this->ctx->shopConnections(), 'fio' => $fio, 'mailOk' => $this->ctx->mailer()->configured()],
            'Systém — integrácie', 'settings:system');
    }

    /** POST /settings/integrations/{name} — save + check the connection right away. */
    public function integrationSave(Request $req, array $params): Response
    {
        if (!Csrf::check($req)) {
            return Response::text('Formulár vypršal, načítajte stránku znova.', 400);
        }
        $name = (string)$params['name'];
        if (!isset(\Panel\Core\Integrations::TYPES[$name])) {
            return Response::html($this->ctx->page('notfound', [], 'Integrácia neexistuje', 'settings:system'), 404);
        }
        $files = new \Panel\Core\Files($this->ctx);
        $int = $files->integrations();
        $values = [];
        foreach (\Panel\Core\Integrations::TYPES[$name][1] as $k => $f) {
            $values[$k] = (string)$req->post($k, '');
        }
        $enabled = (bool)$req->post('enabled');
        if ($values['url'] !== '' && !preg_match('#^https?://[^\s/]+#i', $values['url'])) {
            $form = $int->formValues($name);
            return Response::html($this->integrations($req, $params, ['url' => 'Adresa musí začínať https://'], [$name => array_merge($form, $values)]), 422);
        }
        try {
            $int->save($name, $values, $enabled);
        } catch (\RuntimeException $e) {
            Session::flash('error', $e->getMessage());
            return Response::redirect($this->ctx->url('/settings/integrations'));
        }
        \Panel\Audit::log($this->ctx->db(), $this->ctx->user(), 'integration', 0, 'save', $name . ($enabled ? '' : ' (vypnutá)'));
        return $this->integrationCheck($req, $params, true);
    }

    /** POST /settings/integrations/{name}/check — test the connection now. */
    public function integrationCheck(Request $req, array $params, bool $saved = false): Response
    {
        if (!$saved && !Csrf::check($req)) {
            return Response::text('Formulár vypršal, načítajte stránku znova.', 400);
        }
        $files = new \Panel\Core\Files($this->ctx);
        $int = $files->integrations();
        if (!$files->configured()) {
            Session::flash($saved ? 'msg' : 'error', $saved ? 'Uložené (Nextcloud je vypnutý alebo nie je vyplnený celý).' : 'Nextcloud nie je nastavený celý.');
            return Response::redirect($this->ctx->url('/settings/integrations'));
        }
        try {
            $v = $files->client()->check();
            $int->setStatus('nextcloud', true, 'Nextcloud ' . $v . ' — pripojenie funguje');
            Session::flash('msg', ($saved ? 'Uložené. ' : '') . 'Pripojenie k Nextcloudu funguje (verzia ' . $v . ').');
        } catch (\RuntimeException $e) {
            $int->setStatus('nextcloud', false, $e->getMessage());
            Session::flash('error', ($saved ? 'Uložené, ale ' : '') . 'pripojenie nefunguje: ' . $e->getMessage());
        }
        return Response::redirect($this->ctx->url('/settings/integrations'));
    }

    // ------------------------------------------------------------------ notifications

    public function notificationsList(Request $req, array $params): string
    {
        $me = $this->ctx->user();
        return $this->ctx->page('core/notifications', ['rows' => $this->ctx->notifications()->forPerson($me->getId()),
            'mode' => $this->ctx->notifications()->mode($me->getId())], 'Upozornenia', 'notifications');
    }

    /** GET /notifications/count — for the bell (polled by js/panel.js). */
    public function notificationsCount(Request $req, array $params): Response
    {
        return Response::json(['unread' => $this->ctx->unreadNotifications()]);
    }

    /** GET /notifications/{id} — marks it read and opens its record. */
    public function notificationOpen(Request $req, array $params): Response
    {
        $me = $this->ctx->user();
        $n = $this->ctx->notifications()->find((int)$params['id']);
        if ($n === null || (int)$n['client_id'] !== $me->getId()) {
            return Response::html($this->ctx->page('notfound', [], 'Upozornenie neexistuje', 'notifications'), 404);
        }
        $this->ctx->notifications()->markRead($me->getId(), (int)$n['id']);
        $r = $this->ctx->refs()->resolve($n['ref']);
        if ($r === null) {
            Session::flash('error', 'Záznam z upozornenia už neexistuje alebo k nemu už nemáte prístup.');
            return Response::redirect($this->ctx->url('/notifications'));
        }
        return Response::redirect($this->ctx->url($r['url']) . ($n['event'] === 'comment' || $n['event'] === 'mention' ? '#comments' : ''));
    }

    public function notificationsReadAll(Request $req, array $params): Response
    {
        if (!Csrf::check($req)) {
            return Response::text('Formulár vypršal, načítajte stránku znova.', 400);
        }
        $this->ctx->notifications()->markRead($this->ctx->user()->getId());
        Session::flash('msg', 'Všetko označené ako prečítané.');
        return Response::redirect($this->ctx->url('/notifications'));
    }

    /** POST /account/notifications — how this person wants e-mails about notifications. */
    public function notifyPrefSave(Request $req, array $params): Response
    {
        if (!Csrf::check($req)) {
            return Response::text('Formulár vypršal, načítajte stránku znova.', 400);
        }
        $mode = (string)$req->post('email_mode', '');
        if (!isset(Notifications::MODES[$mode])) {
            Session::flash('error', 'Vyberte, ako chcete dostávať upozornenia.');
        } else {
            $this->ctx->notifications()->setMode($this->ctx->user()->getId(), $mode);
            Session::flash('msg', 'Upozornenia e-mailom: ' . Notifications::MODES[$mode] . '.');
        }
        return Response::redirect($this->ctx->url((string)$req->post('back', '') === 'notifications' ? '/notifications' : '/account'));
    }
}
