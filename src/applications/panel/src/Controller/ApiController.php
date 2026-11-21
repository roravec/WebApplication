<?php
namespace Panel\Controller;

use Panel\Audit;
use Panel\Core\ApiKeys;
use Panel\Core\Events;
use Panel\Core\Permissions;
use Panel\PanelContext;
use Panel\Rights;
use WebApplication\Csrf;
use WebApplication\Http\Request;
use WebApplication\Http\Response;
use WebApplication\Session;

/**
 * API /api/v1/… (docs/panel-architektura.md chapter 4) — JSON, a key in "Authorization: Bearer pk_…" (or "X-Api-Key: pk_…"), acting as the
 * key's owner within the key's permissions (Core\ApiKeys). Core endpoints for every record: info, comments, search,
 * activity; modules add theirs (test results — phase 5, firmware — phase 10). Plus the key management screens.
 */
class ApiController
{
    /** @var PanelContext */
    private $ctx;

    public function __construct(PanelContext $ctx)
    {
        $this->ctx = $ctx;
    }

    private function keys(): ApiKeys
    {
        return new ApiKeys($this->ctx->db());
    }

    /**
     * Wraps an API handler: key → context of its owner limited to the key, rate limit, log, JSON errors.
     * @param callable $handler fn(PanelContext $ctx, Request $req, array $params, array $key): Response
     */
    public function guard(callable $handler): callable
    {
        return function (Request $req, array $params) use ($handler) {
            $keys = $this->keys();
            // "Authorization: Bearer pk_…"; X-Api-Key for clients / proxies that drop the Authorization header
            $token = (string)($req->bearerToken() ?? $req->header('x-api-key', ''));
            list($key, $owner, $error) = $keys->authenticate(trim($token), $req->ip());
            if ($key === null) {
                $keys->log(0, $req->method(), $req->path(), 401, $req->ip());
                return Response::json(['error' => $error], 401);
            }
            if ($keys->overLimit((int)$key['id'])) {
                $keys->log((int)$key['id'], $req->method(), $req->path(), 429, $req->ip());
                return Response::json(['error' => 'Príliš veľa volaní — najviac ' . ApiKeys::RATE . ' za minútu.'], 429);
            }
            try {
                $res = $handler($this->ctx->asApiKey($owner, $key['permissions']), $req, $params, $key);
            } catch (\Throwable $e) {
                $res = Response::json(['error' => 'Chyba servera.'], 500);
            }
            $keys->log((int)$key['id'], $req->method(), $req->path(), $res->status(), $req->ip());
            return $res;
        };
    }

    private static function notFound(): Response
    {
        return Response::json(['error' => 'Záznam neexistuje alebo k nemu kľúč nemá prístup.'], 404);
    }

    // ------------------------------------------------------------------ endpoints

    /** GET /api/v1/me — who the key acts as and what it may do. */
    public function me(PanelContext $ctx, Request $req, array $params, array $key): Response
    {
        $u = $ctx->user();
        return Response::json(['key' => ['name' => $key['name'], 'prefix' => $key['prefix'], 'expires' => $key['expires_at']],
            'user' => ['id' => $u->getId(), 'name' => $u->getName()], 'permissions' => $ctx->access()->permissions()]);
    }

    /** GET /api/v1/records/{ref} */
    public function record(PanelContext $ctx, Request $req, array $params): Response
    {
        $r = $ctx->refs()->resolve((string)$params['ref']);
        return $r ? Response::json(['ref' => $r['ref'], 'type' => $r['type'], 'label' => $r['label'], 'title' => $r['title'],
            'url' => $ctx->refs()->shortUrl($r['ref'])]) : self::notFound();
    }

    /** GET /api/v1/records/{ref}/comments */
    public function comments(PanelContext $ctx, Request $req, array $params): Response
    {
        $ref = (string)$params['ref'];
        if (!$ctx->refs()->canSee($ref)) {
            return self::notFound();
        }
        $out = [];
        foreach ($ctx->comments()->forRef($ref) as $c) {
            if ($c['deleted_at'] === null) {
                $out[] = ['id' => (int)$c['id'], 'author' => $c['client_name'], 'created_at' => $c['created_at'], 'body' => $c['body']];
            }
        }
        return Response::json(['comments' => $out]);
    }

    /** POST /api/v1/records/{ref}/comments  {"body": "…"} — e.g. a test fixture reporting a result to a record. */
    public function commentAdd(PanelContext $ctx, Request $req, array $params): Response
    {
        $ref = (string)$params['ref'];
        $record = $ctx->refs()->resolve($ref);
        if ($record === null) {
            return self::notFound();
        }
        $json = $req->json();
        $body = \Panel\Core\Comments::clean((string)($json['body'] ?? $req->post('body', '')));
        if ($body === '') {
            return Response::json(['error' => 'Chýba text komentára (body).'], 422);
        }
        $participants = $ctx->comments()->participants($ref);
        $id = $ctx->comments()->add($ref, $body, $ctx->user());
        Audit::log($ctx->db(), $ctx->user(), 'comment', $id, 'comment', '(API) ' . mb_substr($body, 0, 110, 'UTF-8'), $ref);
        Events::emit($ctx, 'comment.added', ['ref' => $ref, 'actor' => $ctx->user(), 'record' => $record, 'comment_id' => $id, 'body' => $body,
            'mentions' => $ctx->comments()->mentionedIn($body), 'thread' => (bool)$participants]);
        return Response::json(['id' => $id], 201);
    }

    /** GET /api/v1/search?q= */
    public function search(PanelContext $ctx, Request $req): Response
    {
        $out = [];
        foreach ($ctx->refs()->find((string)$req->query('q', ''), 50) as $r) {
            $out[] = ['ref' => $r['ref'], 'label' => $r['label'], 'title' => $r['title']];
        }
        return Response::json(['results' => $out]);
    }

    /** GET /api/v1/activity?before= */
    public function activity(PanelContext $ctx, Request $req): Response
    {
        $page = $ctx->activity()->feed($ctx->refs(), max(0, (int)$req->query('before', 0)));
        return Response::json(['activity' => array_map(function ($a) {
            return ['id' => (int)$a['id'], 'at' => $a['created_at'], 'who' => $a['client_name'], 'ref' => $a['ref'], 'action' => $a['action'],
                'details' => $a['details']];
        }, $page['rows']), 'next' => $page['next'] ?: null]);
    }

    // ------------------------------------------------------------------ key management (screens)

    /** POST /account/api-keys — a new key of the logged-in person (shown once). */
    public function create(Request $req, array $params)
    {
        if (!Csrf::check($req)) {
            return Response::text('Formulár vypršal, načítajte stránku znova.', 400);
        }
        $me = $this->ctx->user();
        $mine = $this->ctx->access()->permissions();
        $wanted = Permissions::clean((array)$req->post('permissions', []));
        $extra = array_diff($wanted, $mine);
        $days = (int)$req->post('days', 90);
        try {
            if ($extra) {
                throw new \InvalidArgumentException('Kľúč nemôže mať viac práv ako vy: ' . implode(', ', array_map([Permissions::class, 'label'], $extra)) . '.');
            }
            if (!in_array($days, ApiKeys::EXPIRY_DAYS, true)) {
                throw new \InvalidArgumentException('Vyberte platnosť kľúča.');
            }
            list($key, $token) = $this->keys()->create($me, (string)$req->post('name', ''), $wanted, $days);
        } catch (\InvalidArgumentException $e) {
            Session::flash('error', $e->getMessage());
            return Response::redirect($this->ctx->url('/account#api'));
        }
        Audit::log($this->ctx->db(), $me, 'api_key', (int)$key['id'], 'api_key', $key['name'] . ' (' . $key['prefix'] . '): '
            . (implode(', ', array_map([Permissions::class, 'label'], $key['permissions'])) ?: 'len čítanie záznamov podľa práv') . ($key['expires_at'] ? ', do ' . $key['expires_at'] : ''),
            'employee:' . $me->getId());
        $r = $this->ctx->request;
        return $this->ctx->page('core/api-key-new', ['key' => $key, 'token' => $token,
            'apiUrl' => ($r->isHttps() ? 'https' : 'http') . '://' . $r->host() . $this->ctx->url('/api/v1')], 'Nový API kľúč', 'account');
    }

    /** POST /account/api-keys/{id}/revoke (own key) and /settings/api-keys/{id}/revoke (any key — settings.system). */
    public function revoke(Request $req, array $params)
    {
        if (!Csrf::check($req)) {
            return Response::text('Formulár vypršal, načítajte stránku znova.', 400);
        }
        $any = ($params['scope'] ?? '') === 'all';
        $k = $this->keys()->find((int)$params['id']);
        $me = $this->ctx->user();
        if ($k === null || (!$any && (int)$k['client_id'] !== $me->getId()) || ($any && !$this->ctx->can(Rights::SETTINGS_SYSTEM))) {
            return Response::html($this->ctx->page('notfound', [], 'Kľúč neexistuje'), 404);
        }
        $this->keys()->revoke((int)$k['id'], $me->getName());
        Audit::log($this->ctx->db(), $me, 'api_key', (int)$k['id'], 'api_key_revoke', $k['name'] . ' (' . $k['prefix'] . ')', 'employee:' . (int)$k['client_id']);
        Session::flash('msg', 'Kľúč „' . $k['name'] . '“ zrušený — už nefunguje.');
        return Response::redirect($this->ctx->url($any ? '/settings/api-keys' : '/account#api'));
    }

    /** GET /settings/api-keys — all keys, last calls. */
    public function overview(Request $req, array $params): string
    {
        $keys = $this->keys()->all();
        foreach ($keys as &$k) {
            $k['calls'] = $this->keys()->calls((int)$k['id'], 5);
        }
        return $this->ctx->page('settings/api-keys', ['keys' => $keys], 'Systém — API kľúče', 'settings:system');
    }
}
