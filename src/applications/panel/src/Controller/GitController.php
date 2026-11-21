<?php
namespace Panel\Controller;

use Panel\Audit;
use Panel\Core\Events;
use Panel\PanelContext;
use Panel\Tasks\Git;
use WebApplication\Csrf;
use WebApplication\Http\Request;
use WebApplication\Http\Response;
use WebApplication\Session;

/**
 * Commits at tasks (docs/panel-architektura.md 6.11): Nastavenia → Projekty (format of the task number in commits,
 * repositories) and the receivers — GitHub webhook /git/github/{id} (HMAC signature), GitLab webhook /git/gitlab/{id}
 * (secret token), and POST /api/v1/git/push with an API key (own server, post-receive hook — dev/git-hook-post-receive).
 */
class GitController
{
    /** @var PanelContext */
    private $ctx;

    public function __construct(PanelContext $ctx)
    {
        $this->ctx = $ctx;
    }

    private function git(): Git
    {
        try {
            $secret = new \Panel\Secret($this->ctx->app ? $this->ctx->app->getJwtSecret() : '');
        } catch (\RuntimeException $e) {
            $secret = null;
        }
        return new Git($this->ctx->db(), $secret);
    }

    private function base(): string
    {
        $r = $this->ctx->request;
        return ($r->isHttps() ? 'https' : 'http') . '://' . $r->host() . $this->ctx->url('');
    }

    public function settings(Request $req, array $params, array $errors = [], array $new = []): string
    {
        $git = $this->git();
        return $this->ctx->page('settings/projects', ['format' => $git->format(), 'repos' => $git->repos(), 'errors' => $errors, 'new' => $new,
            'base' => rtrim($this->base(), '/'), 'providers' => Git::PROVIDERS], 'Projekty — git a čísla úloh', 'settings:projects');
    }

    public function formatSave(Request $req, array $params)
    {
        if (!Csrf::check($req)) {
            return Response::text('Formulár vypršal, načítajte stránku znova.', 400);
        }
        $f = trim((string)$req->post('format', ''));
        if (($msg = Git::formatError($f)) !== null) {
            return Response::html($this->settings($req, $params, ['format' => $msg]), 422);
        }
        $this->git()->setFormat($f);
        Audit::log($this->ctx->db(), $this->ctx->user(), 'git', 0, 'save', 'formát čísla úlohy v commite: ' . $f);
        Session::flash('msg', 'Uložené. Príklad správy commitu: ' . str_replace('{TASK}', 'LED-12', $f) . ' oprava časovača');
        return Response::redirect($this->ctx->url('/settings/projects'));
    }

    public function repoAdd(Request $req, array $params)
    {
        if (!Csrf::check($req)) {
            return Response::text('Formulár vypršal, načítajte stránku znova.', 400);
        }
        try {
            list($id, $secret) = $this->git()->addRepo((string)$req->post('provider', ''), (string)$req->post('name', ''), trim((string)$req->post('web_url', '')));
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            return Response::html($this->settings($req, $params, ['repo' => $e->getMessage()]), 422);
        }
        $repo = $this->git()->repo($id);
        Audit::log($this->ctx->db(), $this->ctx->user(), 'git', $id, 'create', $repo['provider'] . ' ' . $repo['name']);
        return $this->settings($req, $params, [], ['repo' => $repo, 'secret' => $secret]);
    }

    public function repoToggle(Request $req, array $params)
    {
        if (!Csrf::check($req)) {
            return Response::text('Formulár vypršal, načítajte stránku znova.', 400);
        }
        $repo = $this->git()->repo((int)$params['id']);
        if ($repo !== null) {
            $this->git()->setActive((int)$repo['id'], !(int)$repo['active']);
            Session::flash('msg', 'Repozitár ' . $repo['name'] . ((int)$repo['active'] ? ' vypnutý — commity z neho sa už neprijímajú.' : ' znova zapnutý.'));
        }
        return Response::redirect($this->ctx->url('/settings/projects'));
    }

    // ------------------------------------------------------------------ receivers

    /** Store + link commits, history and events at the tasks. @return string summary */
    private function receive(array $repo, string $branch, array $commits): string
    {
        $r = $this->git()->receive((int)$repo['id'], $branch, $commits);
        foreach ($r['linked'] as $taskId => $list) {
            foreach ($list as $c) {
                $first = strtok((string)$c['message'], "\n");
                Audit::log($this->ctx->db(), null, 'task', (int)$taskId, 'commit',
                    substr($c['sha'], 0, 8) . ' ' . mb_substr($first, 0, 150, 'UTF-8') . ' (' . ($c['author_name'] ?: $c['author_email']) . ', ' . $repo['name']
                    . ($c['branch'] !== '' ? ' ' . $c['branch'] : '') . ')', 'task:' . $taskId);
                Events::emit($this->ctx->system(), 'task.commit', ['ref' => 'task:' . $taskId, 'actor' => null, 'commit' => $c, 'repo' => $repo]);
            }
        }
        return 'ok: nových commitov ' . $r['stored'] . ', priradených k úlohám ' . array_sum(array_map('count', $r['linked']));
    }

    /** POST /git/github/{id} — GitHub webhook (content type application/json, events: push). */
    public function github(Request $req, array $params): Response
    {
        $git = $this->git();
        $repo = $git->repo((int)$params['id']);
        if ($repo === null || $repo['provider'] !== 'github' || !$git->verifyGithub($repo, $req->rawBody(), (string)$req->header('x-hub-signature-256', ''))) {
            return Response::text('Nesprávny podpis alebo repozitár.', 403);
        }
        if (!(int)$repo['active']) {
            return Response::text('Repozitár je v paneli vypnutý.', 410);
        }
        $event = (string)$req->header('x-github-event', '');
        if ($event === 'ping') {
            return Response::text('pong — panel je pripojený');
        }
        if ($event !== 'push') {
            return Response::text('Udalosť ' . $event . ' sa nespracúva (stačí „push“).');
        }
        list($branch, $commits) = Git::parsePush((array)$req->json());
        return Response::text($this->receive($repo, $branch, $commits));
    }

    /** POST /git/gitlab/{id} — GitLab webhook (Push events, secret token). */
    public function gitlab(Request $req, array $params): Response
    {
        $git = $this->git();
        $repo = $git->repo((int)$params['id']);
        if ($repo === null || $repo['provider'] !== 'gitlab' || !$git->verifyGitlab($repo, (string)$req->header('x-gitlab-token', ''))) {
            return Response::text('Nesprávny tajný kľúč alebo repozitár.', 403);
        }
        if (!(int)$repo['active']) {
            return Response::text('Repozitár je v paneli vypnutý.', 410);
        }
        if ((string)$req->header('x-gitlab-event', '') !== 'Push Hook') {
            return Response::text('Spracúvajú sa len Push events.');
        }
        list($branch, $commits) = Git::parsePush((array)$req->json());
        return Response::text($this->receive($repo, $branch, $commits));
    }

    /**
     * POST /api/v1/git/push (API key of a person with tasks.edit) — own git server (post-receive hook):
     * {"repo": "erma/ledboard-fw", "branch": "main", "commits": [{"sha", "message", "author_name", "author_email", "timestamp", "url"}]}
     */
    public function apiPush(PanelContext $ctx, Request $req): Response
    {
        if (!$ctx->can(\Panel\Rights::TASKS_EDIT)) {
            return Response::json(['error' => 'Kľúč nemá právo Úlohy — zakladanie a úpravy (tasks.edit).'], 403);
        }
        $j = (array)$req->json();
        $repo = null;
        foreach ($this->git()->repos() as $r) {
            if ($r['provider'] === 'api' && $r['name'] === (string)($j['repo'] ?? '') && (int)$r['active']) {
                $repo = $r;
            }
        }
        if ($repo === null) {
            return Response::json(['error' => 'Repozitár „' . ($j['repo'] ?? '') . '“ nie je v paneli pridaný ako „Iný (API)“ (Nastavenia → Projekty).'], 404);
        }
        $commits = [];
        foreach ((array)($j['commits'] ?? []) as $c) {
            if (is_array($c)) {
                $commits[] = ['sha' => (string)($c['sha'] ?? ''), 'message' => (string)($c['message'] ?? ''), 'author_name' => (string)($c['author_name'] ?? ''),
                    'author_email' => (string)($c['author_email'] ?? ''), 'timestamp' => (string)($c['timestamp'] ?? ''), 'url' => (string)($c['url'] ?? '')];
            }
        }
        return Response::json(['result' => $this->receive($repo, (string)($j['branch'] ?? ''), $commits)]);
    }
}
