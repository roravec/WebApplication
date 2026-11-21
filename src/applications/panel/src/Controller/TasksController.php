<?php
namespace Panel\Controller;

use Panel\Audit;
use Panel\Core\Events;
use Panel\PanelContext;
use Panel\Rights;
use Panel\Tasks\Git;
use Panel\Tasks\Projects;
use Panel\Tasks\Tasks;
use Panel\Tasks\TimeEntries;
use WebApplication\Csrf;
use WebApplication\Entity\Client;
use WebApplication\Http\Request;
use WebApplication\Http\Response;
use WebApplication\Session;

/**
 * Práca → Projekty, Úlohy, Moje úlohy (docs/panel-architektura.md 6.1): projects with members and kanban columns,
 * tasks (list, kanban with drag & drop, detail with checklist, subtasks, time, commits, the core record box).
 * Every handler checks the project / task access (Projects / Tasks::canSee / canEdit / canManage); a task or project the
 * person may not see is "not found".
 */
class TasksController
{
    /** @var PanelContext */
    private $ctx;

    public function __construct(PanelContext $ctx)
    {
        $this->ctx = $ctx;
    }

    private function projects(): Projects
    {
        return new Projects($this->ctx->db());
    }

    private function tasks(): Tasks
    {
        return new Tasks($this->ctx->db());
    }

    private function notFound(string $what = 'Úloha'): Response
    {
        return Response::html($this->ctx->page('notfound', [], $what . ' neexistuje alebo k nej nemáte prístup', 'tasks'), 404);
    }

    private function bad(): Response
    {
        return Response::text('Formulár vypršal, načítajte stránku znova.', 400);
    }

    /** Active employees, id => name (assignee and member choices). */
    private function people(): array
    {
        return array_column($this->ctx->db()->query('SELECT id, name FROM ' . $this->ctx->db()->table('client') . ' WHERE type = 0 AND status = 1 ORDER BY name'),
            'name', 'id');
    }

    private static function date($v): ?string
    {
        $v = trim((string)$v);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) ? $v : null;
    }

    // ------------------------------------------------------------------ projects

    public function projectsList(Request $req, array $params): string
    {
        $archived = (bool)$req->query('archived');
        return $this->ctx->page('tasks/projects', ['projects' => $this->projects()->visible($this->ctx, $archived), 'archived' => $archived,
            'canCreate' => $this->ctx->can(Rights::PROJECTS_MANAGE) && $this->ctx->companyIds()], 'Projekty', 'projects');
    }

    public function projectForm(Request $req, array $params, array $errors = [], ?array $values = null)
    {
        $p = isset($params['id']) ? $this->projects()->find((int)$params['id']) : null;
        if (isset($params['id']) && ($p === null || !$this->projects()->canManage($this->ctx, $p))) {
            return $this->notFound('Projekt');
        }
        if ($p === null && !$this->ctx->can(Rights::PROJECTS_MANAGE)) {
            return Response::html($this->ctx->page('forbidden', [], 'Prístup zamietnutý'), 403);
        }
        $me = $this->ctx->user();
        $values = $values ?? [
            'name' => $p['name'] ?? '', 'key' => $p['key'] ?? '', 'description' => $p['description'] ?? '', 'status' => $p['status'] ?? 'active',
            'company_id' => (int)($p['company_id'] ?? ($this->ctx->companyIds()[0] ?? 0)),
            'members' => $p ? array_map(function ($m) { return $m['role']; }, $this->projects()->members((int)$p['id'])) : [$me->getId() => 'owner'],
            'columns' => $p ? array_map(function ($c) { return ['id' => (int)$c['id'], 'name' => $c['name'], 'done' => (bool)$c['done']]; }, $this->projects()->columns((int)$p['id']))
                : array_map(function ($c) { return ['id' => 0, 'name' => $c[0], 'done' => (bool)$c[1]]; }, Projects::DEFAULT_COLUMNS),
        ];
        return $this->ctx->page('tasks/project-form', ['project' => $p, 'values' => $values, 'errors' => $errors, 'people' => $this->people(),
            'companies' => array_column($this->ctx->companies()->all($this->ctx->companyIds(), true), 'name', 'id')],
            $p ? 'Nastavenie projektu ' . $p['key'] : 'Nový projekt', 'projects');
    }

    public function projectSave(Request $req, array $params)
    {
        if (!Csrf::check($req)) {
            return $this->bad();
        }
        $repo = $this->projects();
        $p = isset($params['id']) ? $repo->find((int)$params['id']) : null;
        if (isset($params['id']) && ($p === null || !$repo->canManage($this->ctx, $p))) {
            return $this->notFound('Projekt');
        }
        if ($p === null && !$this->ctx->can(Rights::PROJECTS_MANAGE)) {
            return Response::html($this->ctx->page('forbidden', [], 'Prístup zamietnutý'), 403);
        }
        $members = [];
        foreach ((array)$req->post('member', []) as $cid => $role) {
            if (isset(Projects::ROLES[$role])) {
                $members[(int)$cid] = (string)$role;
            }
        }
        $columns = [];
        foreach ((array)$req->post('col', []) as $c) {
            if (is_array($c) && trim((string)($c['name'] ?? '')) !== '' && empty($c['remove'])) {
                $columns[] = ['id' => (int)($c['id'] ?? 0), 'name' => mb_substr(trim((string)$c['name']), 0, 60, 'UTF-8'), 'done' => !empty($c['done'])];
            }
        }
        $v = ['name' => trim((string)$req->post('name', '')), 'key' => strtoupper(trim((string)$req->post('key', $p['key'] ?? ''))),
            'description' => trim((string)$req->post('description', '')), 'status' => (string)$req->post('status', 'active'),
            'company_id' => $p ? (int)$p['company_id'] : (int)$req->post('company_id', 0), 'members' => $members, 'columns' => $columns];
        $errors = [];
        if ($v['name'] === '' || mb_strlen($v['name'], 'UTF-8') > 190) {
            $errors['name'] = 'Zadajte názov projektu.';
        }
        if (!$p && ($msg = $repo->keyError($v['key'], null)) !== null) {
            $errors['key'] = $msg;
        }
        if (!$p && !$this->ctx->canCompany($v['company_id'])) {
            $errors['company_id'] = 'Vyberte firmu, ku ktorej máte prístup.';
        }
        if (!$columns) {
            $errors['columns'] = 'Projekt potrebuje aspoň jeden stĺpec.';
        } elseif (!array_filter($columns, function ($c) { return $c['done']; })) {
            $errors['columns'] = 'Označte aspoň jeden stĺpec ako „hotovo“ (úlohy v ňom sú dokončené).';
        }
        if (!in_array('owner', $members, true)) {
            $errors['members'] = 'Projekt musí mať aspoň jedného vlastníka.';
        }
        if ($errors) {
            return Response::html($this->projectForm($req, $params, $errors, $v), 422);
        }
        $me = $this->ctx->user();
        if ($p === null) {
            $id = $repo->create($v['company_id'], $v['key'], $v['name'], $v['description'], $me->getId());
            Audit::log($this->ctx->db(), $me, 'project', $id, 'create', $v['key'] . ' ' . $v['name']);
        } else {
            $id = (int)$p['id'];
            $repo->update($id, $v['name'], $v['description'], $v['status']);
            Audit::log($this->ctx->db(), $me, 'project', $id, 'update', $v['name'] . ($v['status'] !== $p['status'] ? ($v['status'] === 'archived' ? ' — archivovaný' : ' — znova aktívny') : ''));
        }
        $repo->saveColumns($id, $columns);
        $repo->saveMembers($id, $members);
        Session::flash('msg', 'Projekt uložený.');
        return Response::redirect($this->ctx->url('/projects/' . $id));
    }

    /** GET /projects/{id} — kanban board (and /projects/{id}/list = the same tasks as a table). */
    public function board(Request $req, array $params)
    {
        $repo = $this->projects();
        $p = $repo->find((int)$params['id']);
        if ($p === null || !$repo->canSee($this->ctx, $p)) {
            return $this->notFound('Projekt');
        }
        $view = ($params['view'] ?? 'board') === 'list' ? 'list' : 'board';
        $f = ['project' => (int)$p['id'], 'assignee' => (int)$req->query('assignee', 0), 'label' => (string)$req->query('label', ''),
            'q' => (string)$req->query('q', ''), 'status' => $view === 'board' ? 'all' : (string)$req->query('status', 'open')];
        $tasks = $this->tasks()->search($this->ctx, $f, 1000);
        $byColumn = [];
        foreach ($tasks as $t) {
            // the board shows tasks finished in the last 14 days in their done column, older ones only in the list
            if ($view === 'board' && $t['closed_at'] !== null && strtotime($t['closed_at']) < strtotime('-14 days')) {
                continue;
            }
            $byColumn[(int)$t['column_id']][] = $t;
        }
        foreach ($byColumn as &$list) {
            usort($list, function ($a, $b) { return [(int)$a['position'], (int)$a['id']] <=> [(int)$b['position'], (int)$b['id']]; });
        }
        unset($list);
        return $this->ctx->page($view === 'board' ? 'tasks/board' : 'tasks/list', ['project' => $p, 'columns' => $repo->columns((int)$p['id']),
            'byColumn' => $byColumn, 'tasks' => $tasks, 'f' => $f, 'members' => $repo->members((int)$p['id']), 'canEdit' => $repo->canEdit($this->ctx, $p),
            'canManage' => $repo->canManage($this->ctx, $p), 'hours' => $view === 'list' ? $repo->hours((int)$p['id']) : []],
            $p['key'] . ' · ' . $p['name'], 'projects');
    }

    // ------------------------------------------------------------------ task lists

    /** GET /tasks — all tasks the person may see, with filters. */
    public function all(Request $req, array $params): string
    {
        $f = ['project' => $req->query('project', '') === '0' ? 0 : (int)$req->query('project', 0), 'assignee' => (int)$req->query('assignee', 0),
            'status' => (string)$req->query('status', 'open'), 'q' => (string)$req->query('q', ''), 'label' => (string)$req->query('label', ''),
            'due' => (string)$req->query('due', '')];
        return $this->ctx->page('tasks/all', ['tasks' => $this->tasks()->search($this->ctx, $f), 'f' => $f, 'people' => $this->people(),
            'projects' => $this->projects()->visible($this->ctx)], 'Všetky úlohy', 'tasks');
    }

    /** GET /tasks/my — my tasks by due date, watched tasks, the stopwatch. */
    public function my(Request $req, array $params): string
    {
        $today = date('Y-m-d');
        $groups = ['over' => [], 'today' => [], 'week' => [], 'later' => [], 'none' => []];
        foreach ($this->tasks()->mine($this->ctx) as $t) {
            $d = $t['due_date'];
            $groups[$d === null ? 'none' : ($d < $today ? 'over' : ($d === $today ? 'today' : ($d <= date('Y-m-d', strtotime('+7 days')) ? 'week' : 'later')))][] = $t;
        }
        $time = new TimeEntries($this->ctx->db());
        return $this->ctx->page('tasks/my', ['groups' => $groups, 'watched' => $this->tasks()->mine($this->ctx, true),
            'timer' => $time->timer($this->ctx->user()->getId()), 'canTrack' => $this->ctx->can(Rights::TIME_TRACK)], 'Moje úlohy', 'tasks');
    }

    // ------------------------------------------------------------------ one task

    public function show(Request $req, array $params)
    {
        $t = $this->tasks()->find((int)$params['id']);
        if ($t === null || !$this->tasks()->canSee($this->ctx, $t)) {
            return $this->notFound();
        }
        $me = $this->ctx->user();
        $time = new TimeEntries($this->ctx->db());
        $p = $t['project_id'] !== null ? $this->projects()->find((int)$t['project_id']) : null;
        $watchers = $this->tasks()->watchers((int)$t['id']);
        $names = $this->people();
        return $this->ctx->page('tasks/task', ['t' => $t, 'project' => $p, 'columns' => $p ? $this->projects()->columns((int)$p['id']) : [],
            'checklist' => $this->tasks()->checklist((int)$t['id']), 'subtasks' => $this->tasks()->subtasks((int)$t['id']),
            'parent' => $t['parent_id'] ? $this->tasks()->find((int)$t['parent_id']) : null,
            'watching' => in_array($me->getId(), $watchers, true), 'watcherNames' => array_values(array_intersect_key($names, array_flip($watchers))),
            'canEdit' => $this->tasks()->canEdit($this->ctx, $t), 'canTrack' => $this->ctx->can(Rights::TIME_TRACK),
            'entries' => $time->forTask((int)$t['id']), 'spent' => $time->taskMinutes((int)$t['id']), 'timer' => $time->timer($me->getId()),
            'commits' => (new Git($this->ctx->db()))->forTask((int)$t['id'])], $t['code'] . ' ' . $t['title'], 'tasks');
    }

    public function form(Request $req, array $params, array $errors = [], ?array $values = null)
    {
        $t = isset($params['id']) ? $this->tasks()->find((int)$params['id']) : null;
        if (isset($params['id']) && ($t === null || !$this->tasks()->canEdit($this->ctx, $t))) {
            return $this->notFound();
        }
        $projectId = $t ? ($t['project_id'] !== null ? (int)$t['project_id'] : 0) : (int)$req->query('project', 0);
        $p = $projectId ? $this->projects()->find($projectId) : null;
        if (!$t && $projectId && ($p === null || !$this->projects()->canEdit($this->ctx, $p))) {
            return $this->notFound('Projekt');
        }
        if (!$t && !$this->ctx->can(Rights::TASKS_EDIT)) {
            return Response::html($this->ctx->page('forbidden', [], 'Prístup zamietnutý'), 403);
        }
        $values = $values ?? ($t ? $t : ['title' => '', 'description' => '', 'assignee_id' => $p ? '' : $this->ctx->user()->getId(), 'priority' => 2,
            'start_date' => '', 'due_date' => '', 'labels' => '', 'estimate_minutes' => '', 'column_id' => '', 'parent_id' => (int)$req->query('parent', 0) ?: '']);
        $assignees = $p ? array_column($this->projects()->members((int)$p['id']), 'name', 'client_id') : [$this->ctx->user()->getId() => $this->ctx->user()->getName()];
        return $this->ctx->page('tasks/task-form', ['t' => $t, 'project' => $p, 'values' => $values, 'errors' => $errors, 'assignees' => $assignees,
            'columns' => $p ? $this->projects()->columns((int)$p['id']) : [],
            'editable' => array_filter($this->projects()->visible($this->ctx), function ($x) { return $this->projects()->canEdit($this->ctx, $x); })],
            $t ? 'Upraviť ' . $t['code'] : ($p ? 'Nová úloha v ' . $p['key'] : 'Nová osobná úloha'), 'tasks');
    }

    public function save(Request $req, array $params)
    {
        if (!Csrf::check($req)) {
            return $this->bad();
        }
        $repo = $this->tasks();
        $t = isset($params['id']) ? $repo->find((int)$params['id']) : null;
        if (isset($params['id']) && ($t === null || !$repo->canEdit($this->ctx, $t))) {
            return $this->notFound();
        }
        $projectId = $t ? ($t['project_id'] !== null ? (int)$t['project_id'] : 0) : (int)$req->post('project_id', 0);
        $p = $projectId ? $this->projects()->find($projectId) : null;
        if (!$t && ($projectId ? ($p === null || !$this->projects()->canEdit($this->ctx, $p)) : !$this->ctx->can(Rights::TASKS_EDIT))) {
            return $this->notFound('Projekt');
        }
        $members = $p ? $this->projects()->members((int)$p['id']) : [];
        $est = trim((string)$req->post('estimate', ''));
        $v = ['title' => mb_substr(trim((string)$req->post('title', '')), 0, 255, 'UTF-8'), 'description' => trim((string)$req->post('description', '')),
            'assignee_id' => (int)$req->post('assignee_id', 0) ?: '', 'priority' => max(1, min(4, (int)$req->post('priority', 2))),
            'start_date' => self::date($req->post('start_date')) ?? '', 'due_date' => self::date($req->post('due_date')) ?? '',
            'labels' => (string)$req->post('labels', ''), 'estimate_minutes' => $est === '' ? '' : (TimeEntries::parse($est) ?? -1),
            'parent_id' => (int)$req->post('parent_id', 0) ?: ''];
        if ($p && $req->post('column_id')) {
            $v['column_id'] = (int)$req->post('column_id');
        }
        $errors = [];
        if ($v['title'] === '') {
            $errors['title'] = 'Zadajte názov úlohy — čo treba urobiť.';
        }
        if ($v['assignee_id'] !== '' && $p && !isset($members[(int)$v['assignee_id']])) {
            $errors['assignee_id'] = 'Riešiteľ musí byť členom projektu (pridá ho vlastník projektu v nastavení projektu).';
        }
        if (!$p && $v['assignee_id'] !== '' && (int)$v['assignee_id'] !== $this->ctx->user()->getId()) {
            $errors['assignee_id'] = 'Osobnú úlohu môžete prideliť len sebe — úlohu pre kolegu založte v projekte.';
        }
        if ($v['estimate_minutes'] === -1) {
            $errors['estimate'] = 'Odhad zadajte ako hodiny, napr. 2, 1,5 alebo 1:30.';
        }
        if ($v['start_date'] !== '' && $v['due_date'] !== '' && $v['start_date'] > $v['due_date']) {
            $errors['due_date'] = 'Termín je pred začiatkom.';
        }
        if ($v['parent_id'] !== '') {
            $parent = $repo->find((int)$v['parent_id']);
            if ($parent === null || (int)$parent['project_id'] !== (int)$projectId || ($t && (int)$parent['id'] === (int)$t['id'])) {
                $errors['parent_id'] = 'Nadradená úloha musí byť v tom istom projekte.';
            }
        }
        if (isset($v['column_id']) && !in_array($v['column_id'], array_map('intval', array_column($this->projects()->columns((int)$p['id']), 'id')), true)) {
            unset($v['column_id']);
        }
        if ($errors) {
            return Response::html($this->form($req, $params + ($t ? [] : []), $errors, $v + ['project_id' => $projectId]), 422);
        }
        $me = $this->ctx->user();
        if ($t === null) {
            $id = $repo->create($p ? (int)$p['id'] : null, $v, $me->getId());
            $new = $repo->find($id);
            Audit::log($this->ctx->db(), $me, 'task', $id, 'create', $new['code'] . ' ' . $new['title']);
            if ($new['assignee_id'] !== null) {
                Events::emit($this->ctx, 'task.assigned', ['ref' => 'task:' . $id, 'actor' => $me, 'task' => $new]);
            }
            Session::flash('msg', 'Úloha ' . $new['code'] . ' založená.');
            return Response::redirect($this->ctx->url('/tasks/' . $id));
        }
        $changes = $repo->update((int)$t['id'], $v);
        $this->afterChange($t, $changes);
        Session::flash('msg', $changes ? 'Uložené.' : 'Nič sa nezmenilo.');
        return Response::redirect($this->ctx->url('/tasks/' . $t['id']));
    }

    /** History + events after a change of a task. */
    private function afterChange(array $old, array $changes): void
    {
        if (!$changes) {
            return;
        }
        $me = $this->ctx->user();
        $new = $this->tasks()->find((int)$old['id']);
        $labels = ['title' => 'názov', 'description' => 'popis', 'assignee_id' => 'riešiteľ', 'priority' => 'priorita', 'start_date' => 'začiatok',
            'due_date' => 'termín', 'labels' => 'štítky', 'estimate_minutes' => 'odhad', 'column_id' => 'stav', 'parent_id' => 'nadradená úloha'];
        $parts = [];
        foreach ($changes as $f => list($a, $b)) {
            if ($f === 'column_id') {
                $parts[] = 'stav: ' . $old['column_name'] . ' → ' . $new['column_name'];
            } elseif ($f === 'assignee_id') {
                $parts[] = 'riešiteľ: ' . ($new['assignee_name'] ?? 'nikto');
            } elseif ($f === 'priority') {
                $parts[] = 'priorita: ' . Tasks::PRIORITIES[(int)$b];
            } elseif (in_array($f, ['due_date', 'start_date', 'labels', 'title'], true)) {
                $parts[] = $labels[$f] . ': ' . ($b === '' || $b === null ? '—' : $b);
            } else {
                $parts[] = $labels[$f];
            }
        }
        Audit::log($this->ctx->db(), $me, 'task', (int)$old['id'], 'update', $new['code'] . ' — ' . implode('; ', $parts));
        if (isset($changes['assignee_id']) && $new['assignee_id'] !== null) {
            Events::emit($this->ctx, 'task.assigned', ['ref' => 'task:' . $old['id'], 'actor' => $me, 'task' => $new]);
        }
        if (isset($changes['column_id'])) {
            Events::emit($this->ctx, 'task.status_changed', ['ref' => 'task:' . $old['id'], 'actor' => $me, 'task' => $new,
                'from' => (string)$old['column_name'], 'to' => (string)$new['column_name'], 'done' => $new['closed_at'] !== null]);
        }
    }

    /** POST /tasks/{id}/move — kanban drag & drop (JSON) or the state select on the task page. */
    public function move(Request $req, array $params)
    {
        $json = $req->isAjax();
        if (!Csrf::check($req)) {
            return $json ? Response::json(['error' => 'Stránka vypršala — načítajte ju znova.'], 400) : $this->bad();
        }
        $repo = $this->tasks();
        $t = $repo->find((int)$params['id']);
        if ($t === null || !$repo->canEdit($this->ctx, $t) || $t['project_id'] === null) {
            return $json ? Response::json(['error' => 'Úlohu nemôžete presunúť.'], 403) : $this->notFound();
        }
        try {
            $changed = $repo->move((int)$t['id'], (int)$req->post('column_id', 0), (int)$req->post('position', 9999));
        } catch (\InvalidArgumentException $e) {
            return $json ? Response::json(['error' => $e->getMessage()], 422) : $this->notFound();
        }
        if ($changed) {
            $this->afterChange($t, ['column_id' => [$t['column_id'], (int)$req->post('column_id')]]);
        }
        if ($json) {
            return Response::json(['ok' => true]);
        }
        Session::flash('msg', 'Stav zmenený.');
        return Response::redirect($this->ctx->url('/tasks/' . $t['id']));
    }

    /** POST /tasks/{id}/done — personal task done / open again. */
    public function done(Request $req, array $params)
    {
        if (!Csrf::check($req)) {
            return $this->bad();
        }
        $t = $this->tasks()->find((int)$params['id']);
        if ($t === null || !$this->tasks()->canEdit($this->ctx, $t) || $t['project_id'] !== null) {
            return $this->notFound();
        }
        $done = $t['closed_at'] === null;
        $this->tasks()->setDone((int)$t['id'], $done);
        Audit::log($this->ctx->db(), $this->ctx->user(), 'task', (int)$t['id'], 'update', $t['code'] . ($done ? ' — hotová' : ' — znova otvorená'));
        return Response::redirect($this->ctx->url((string)$req->post('back', '') === 'my' ? '/tasks/my' : '/tasks/' . $t['id']));
    }

    public function watch(Request $req, array $params)
    {
        if (!Csrf::check($req)) {
            return $this->bad();
        }
        $t = $this->tasks()->find((int)$params['id']);
        if ($t === null || !$this->tasks()->canSee($this->ctx, $t)) {
            return $this->notFound();
        }
        $me = $this->ctx->user()->getId();
        if ($req->post('watch')) {
            $this->tasks()->watch((int)$t['id'], $me);
            Session::flash('msg', 'Sledujete úlohu — dostanete upozornenia o zmenách a komentároch.');
        } else {
            $this->tasks()->unwatch((int)$t['id'], $me);
            Session::flash('msg', 'Úlohu už nesledujete.');
        }
        return Response::redirect($this->ctx->url('/tasks/' . $t['id']));
    }

    /** POST /tasks/{id}/check (add) and /tasks/{id}/check/{cid} (toggle / delete). */
    public function check(Request $req, array $params)
    {
        if (!Csrf::check($req)) {
            return $this->bad();
        }
        $t = $this->tasks()->find((int)$params['id']);
        if ($t === null || !$this->tasks()->canEdit($this->ctx, $t)) {
            return $this->notFound();
        }
        if (isset($params['cid'])) {
            $req->post('delete') ? $this->tasks()->deleteCheck((int)$t['id'], (int)$params['cid']) : $this->tasks()->toggleCheck((int)$t['id'], (int)$params['cid']);
        } else {
            $this->tasks()->addCheck((int)$t['id'], (string)$req->post('text', ''));
        }
        return Response::redirect($this->ctx->url('/tasks/' . $t['id'] . '#checklist'));
    }
}
