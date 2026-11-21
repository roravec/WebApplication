<?php
namespace Panel\Modules;

use Panel\Controller\ApiController;
use Panel\Controller\GitController;
use Panel\Controller\TasksController;
use Panel\Controller\TimeController;
use Panel\Core\ProvidesCalendar;
use Panel\Core\ProvidesJobs;
use Panel\Core\ProvidesListeners;
use Panel\Module;
use Panel\PanelContext;
use Panel\Rights;
use Panel\Tasks\Projects;
use Panel\Tasks\Tasks;
use WebApplication\Http\Request;
use WebApplication\Http\Response;
use WebApplication\Http\Router;

/**
 * Práca: projects, tasks (list, kanban, my tasks), time sheets, commits at tasks (docs/panel-architektura.md 6.1, 6.11).
 */
class TasksModule implements Module, ProvidesListeners, ProvidesJobs, ProvidesCalendar
{
    public function routes(Router $r, callable $guard, PanelContext $ctx): void
    {
        $c = new TasksController($ctx);
        $time = new TimeController($ctx);
        $git = new GitController($ctx);
        $V = Rights::TASKS_VIEW;
        $E = Rights::TASKS_EDIT;
        $r->get('/projects', $guard($V, [$c, 'projectsList']));
        $r->get('/projects/new', $guard(Rights::PROJECTS_MANAGE, [$c, 'projectForm']));
        $r->post('/projects', $guard(Rights::PROJECTS_MANAGE, [$c, 'projectSave']));
        $r->get('/projects/{id:\d+}', $guard($V, [$c, 'board']));
        $r->get('/projects/{id:\d+}/list', $guard($V, function (Request $req, array $p) use ($c) { return $c->board($req, $p + ['view' => 'list']); }));
        $r->get('/projects/{id:\d+}/settings', $guard($V, [$c, 'projectForm']));       // owner or projects.manage — checked inside
        $r->post('/projects/{id:\d+}/settings', $guard($V, [$c, 'projectSave']));
        $r->get('/tasks', $guard($V, [$c, 'all']));
        $r->get('/tasks/my', $guard($V, [$c, 'my']));
        $r->get('/tasks/new', $guard($E, [$c, 'form']));
        $r->post('/tasks', $guard($E, [$c, 'save']));
        $r->get('/tasks/{id:\d+}', $guard($V, [$c, 'show']));
        $r->get('/tasks/{id:\d+}/edit', $guard($E, [$c, 'form']));
        $r->post('/tasks/{id:\d+}', $guard($E, [$c, 'save']));
        $r->post('/tasks/{id:\d+}/move', $guard($E, [$c, 'move']));
        $r->post('/tasks/{id:\d+}/done', $guard($E, [$c, 'done']));
        $r->post('/tasks/{id:\d+}/watch', $guard($V, [$c, 'watch']));
        $r->post('/tasks/{id:\d+}/check', $guard($E, [$c, 'check']));
        $r->post('/tasks/{id:\d+}/check/{cid:\d+}', $guard($E, [$c, 'check']));

        $r->get('/time', $guard(Rights::TIME_TRACK, [$time, 'week']));
        $r->post('/time', $guard(Rights::TIME_TRACK, [$time, 'add']));
        $r->post('/time/{id:\d+}/delete', $guard(Rights::TIME_TRACK, [$time, 'delete']));
        $r->post('/time/timer', $guard(Rights::TIME_TRACK, [$time, 'timer']));

        $r->get('/settings/projects', $guard(Rights::PROJECTS_MANAGE, [$git, 'settings']));
        $r->post('/settings/projects/format', $guard(Rights::PROJECTS_MANAGE, [$git, 'formatSave']));
        $r->post('/settings/projects/repos', $guard(Rights::PROJECTS_MANAGE, [$git, 'repoAdd']));
        $r->post('/settings/projects/repos/{id:\d+}/toggle', $guard(Rights::PROJECTS_MANAGE, [$git, 'repoToggle']));
        // receivers: no login — GitHub signature / GitLab token / API key
        $r->post('/git/github/{id:\d+}', [$git, 'github']);
        $r->post('/git/gitlab/{id:\d+}', [$git, 'gitlab']);
        $api = new ApiController($ctx);
        $r->post('/api/v1/git/push', $api->guard([$git, 'apiPush']));
    }

    public function nav(): array
    {
        return [
            ['key' => 'tasks', 'group' => 'work', 'path' => '/tasks/my', 'label' => 'Moje úlohy', 'permission' => Rights::TASKS_VIEW, 'order' => 10],
            ['key' => 'projects', 'group' => 'work', 'path' => '/projects', 'label' => 'Projekty', 'permission' => Rights::TASKS_VIEW, 'order' => 20],
            ['key' => 'time', 'group' => 'work', 'path' => '/time', 'label' => 'Výkazy práce', 'permission' => Rights::TIME_TRACK, 'order' => 30],
        ];
    }

    public function settingsTabs(): array
    {
        return [['key' => 'projects', 'path' => '/settings/projects', 'label' => 'Projekty', 'permission' => Rights::PROJECTS_MANAGE, 'order' => 45]];
    }

    public function permissions(): array
    {
        return [
            ['key' => Rights::TASKS_VIEW, 'group' => 'Úlohy a projekty', 'sensitive' => false,
                'label' => 'Úlohy — prezeranie projektov, kde je členom (a osobné úlohy)'],
            ['key' => Rights::TASKS_EDIT, 'group' => 'Úlohy a projekty', 'sensitive' => false,
                'label' => 'Úlohy — zakladanie a úpravy, presúvanie na kanbane, osobné úlohy'],
            ['key' => Rights::PROJECTS_ALL, 'group' => 'Úlohy a projekty', 'sensitive' => false,
                'label' => 'Projekty — vidí všetky projekty svojich firiem, nielen kde je členom'],
            ['key' => Rights::PROJECTS_MANAGE, 'group' => 'Úlohy a projekty', 'sensitive' => false,
                'label' => 'Projekty — zakladanie, členovia, stĺpce, archív; Nastavenia → Projekty (git repozitáre)'],
            ['key' => Rights::TIME_TRACK, 'group' => 'Úlohy a projekty', 'sensitive' => false, 'label' => 'Výkazy práce — vlastné hodiny a stopky'],
            ['key' => Rights::TIME_ALL, 'group' => 'Úlohy a projekty', 'sensitive' => true, 'label' => 'Výkazy práce — vidí hodiny všetkých'],
        ];
    }

    public function recordTypes(): array
    {
        return [
            ['type' => 'task', 'label' => 'Úloha',
                'resolve' => function (PanelContext $ctx, array $ids): ?array {
                    $repo = new Tasks($ctx->db());
                    $t = $repo->find($ids[0]);
                    return $t && $repo->canSee($ctx, $t) ? ['title' => $t['code'] . ' ' . $t['title'], 'url' => '/tasks/' . $t['id'],
                        'company_id' => (int)($t['company_id'] ?? 0)] : null;
                },
                'lookup' => function (PanelContext $ctx, string $q): array {
                    $repo = new Tasks($ctx->db());
                    $t = $repo->findByCode($q);
                    $out = $t ? ['task:' . $t['id']] : [];
                    foreach ($repo->search($ctx, ['q' => $q, 'status' => 'all'], 5) as $x) {
                        $out[] = 'task:' . $x['id'];
                    }
                    return array_values(array_unique($out));
                }],
            ['type' => 'project', 'label' => 'Projekt',
                'resolve' => function (PanelContext $ctx, array $ids): ?array {
                    $repo = new Projects($ctx->db());
                    $p = $repo->find($ids[0]);
                    return $p && $repo->canSee($ctx, $p) ? ['title' => $p['key'] . ' ' . $p['name'], 'url' => '/projects/' . $p['id'],
                        'company_id' => (int)$p['company_id']] : null;
                },
                'lookup' => function (PanelContext $ctx, string $q): array {
                    $out = [];
                    foreach ((new Projects($ctx->db()))->visible($ctx) as $p) {
                        if (stripos($p['key'] . ' ' . $p['name'], $q) !== false) {
                            $out[] = 'project:' . $p['id'];
                        }
                    }
                    return array_slice($out, 0, 5);
                }],
        ];
    }

    /** Assignee hears about an assignment, watchers about a state change and comments. */
    public function listeners(): array
    {
        $notifyWatchers = function (PanelContext $ctx, array $task, array $exclude, string $event, string $text): void {
            foreach ((new Tasks($ctx->db()))->watchers((int)$task['id']) as $cid) {
                if (!in_array($cid, $exclude, true)) {
                    $ctx->notifications()->notify($ctx, $cid, 'task:' . $task['id'], $event, $text, '');
                }
            }
        };
        return [
            ['task.assigned', function (PanelContext $ctx, array $d): void {
                $t = $d['task'];
                $actor = $d['actor'];
                if ($t['assignee_id'] !== null && (!$actor || (int)$t['assignee_id'] !== $actor->getId())) {
                    $ctx->notifications()->notify($ctx, (int)$t['assignee_id'], $d['ref'], 'task',
                        ($actor ? $actor->getName() : 'Niekto') . ' vám pridelil úlohu ' . $t['code'] . ' ' . $t['title']
                        . ($t['due_date'] ? ' (termín ' . date('j. n. Y', strtotime($t['due_date'])) . ')' : ''), $actor ? $actor->getName() : '');
                }
            }],
            ['task.status_changed', function (PanelContext $ctx, array $d) use ($notifyWatchers): void {
                $actor = $d['actor'];
                $notifyWatchers($ctx, $d['task'], [$actor ? $actor->getId() : 0], 'task',
                    ($actor ? $actor->getName() : 'Niekto') . ' zmenil stav úlohy ' . $d['task']['code'] . ': ' . $d['from'] . ' → ' . $d['to']);
            }],
            ['comment.added', function (PanelContext $ctx, array $d) use ($notifyWatchers): void {
                if (strpos($d['ref'], 'task:') !== 0) {
                    return;
                }
                $t = (new Tasks($ctx->db()))->find((int)substr($d['ref'], 5));
                $actor = $d['actor'];
                if ($t !== null) {   // mentioned people got their own notice (core) — Notifications dedupes the same person + record
                    $notifyWatchers($ctx, $t, array_merge([$actor ? $actor->getId() : 0], $d['mentions']), 'comment',
                        ($actor ? $actor->getName() : 'Niekto') . ' pridal komentár k úlohe ' . $t['code'] . ': „' . mb_substr((string)$d['body'], 0, 100, 'UTF-8') . '“');
                }
            }],
        ];
    }

    public function jobs(): array
    {
        return [['key' => 'tasks.due_reminders', 'label' => 'Úlohy — pripomenúť termín (dnes, zajtra, po termíne)', 'every' => 1440, 'hour' => 7,
            'run' => function (PanelContext $sys): string {
                $repo = new Tasks($sys->db());
                $today = date('Y-m-d');
                $n = 0;
                foreach ($repo->dueForReminder($today, date('Y-m-d', strtotime('+1 day'))) as $t) {
                    $when = $t['due_date'] < $today ? 'je po termíne (' . date('j. n.', strtotime($t['due_date'])) . ')' : ($t['due_date'] === $today ? 'má termín dnes' : 'má termín zajtra');
                    if ($sys->notifications()->notify($sys, (int)$t['assignee_id'], 'task:' . $t['id'], 'task', 'Úloha ' . $t['code'] . ' ' . $t['title'] . ' ' . $when)) {
                        $n++;
                    }
                    $repo->markReminded((int)$t['id'], $today);
                }
                return 'pripomenutých úloh: ' . $n;
            }]];
    }

    /** Due dates of the person's open tasks. */
    public function calendar(PanelContext $ctx, string $from, string $to): array
    {
        if (!$ctx->can(Rights::TASKS_VIEW) || $ctx->user() === null) {
            return [];
        }
        $out = [];
        foreach ((new Tasks($ctx->db()))->mine($ctx) as $t) {
            if ($t['due_date'] !== null && $t['due_date'] >= $from && $t['due_date'] <= $to) {
                $out[] = ['date' => $t['due_date'], 'title' => $t['code'] . ' ' . $t['title'], 'url' => '/tasks/' . $t['id'],
                    'kind' => $t['due_date'] < date('Y-m-d') ? 'over' : 'due'];
            }
        }
        return $out;
    }
}
