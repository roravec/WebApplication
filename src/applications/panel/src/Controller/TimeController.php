<?php
namespace Panel\Controller;

use Panel\Audit;
use Panel\PanelContext;
use Panel\Rights;
use Panel\Tasks\Projects;
use Panel\Tasks\Tasks;
use Panel\Tasks\TimeEntries;
use WebApplication\Csrf;
use WebApplication\Http\Request;
use WebApplication\Http\Response;
use WebApplication\Session;

/**
 * Práca → Výkazy práce (docs/panel-architektura.md 6.1): my week (or a colleague's with time.all), adding time by hand
 * (to a task by its number, to a project, or general), the stopwatch (start at a task, stop = the time is written).
 */
class TimeController
{
    /** @var PanelContext */
    private $ctx;

    public function __construct(PanelContext $ctx)
    {
        $this->ctx = $ctx;
    }

    private function time(): TimeEntries
    {
        return new TimeEntries($this->ctx->db());
    }

    /** Monday of the ISO week "2026-W47" (default: this week). */
    public static function monday(string $week): string
    {
        if (preg_match('/^(\d{4})-W(\d{2})$/', $week, $m)) {
            return date('Y-m-d', strtotime($m[1] . 'W' . $m[2]));
        }
        return date('Y-m-d', strtotime('monday this week'));
    }

    public function week(Request $req, array $params): string
    {
        $me = $this->ctx->user();
        $person = $me->getId();
        $people = [];
        if ($this->ctx->can(Rights::TIME_ALL)) {
            $people = array_column($this->ctx->db()->query('SELECT id, name FROM ' . $this->ctx->db()->table('client') . ' WHERE type = 0 ORDER BY status DESC, name'),
                'name', 'id');
            $want = (int)$req->query('person', $person);
            $person = isset($people[$want]) ? $want : $person;
        }
        $mon = self::monday((string)$req->query('week', ''));
        $sun = date('Y-m-d', strtotime($mon . ' +6 days'));
        $entries = $this->time()->forPerson($person, $mon, $sun);
        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $days[date('Y-m-d', strtotime("$mon +$i days"))] = [];
        }
        foreach ($entries as $e) {
            $days[$e['work_date']][] = $e;
        }
        return $this->ctx->page('time/week', ['days' => $days, 'monday' => $mon, 'person' => $person, 'people' => $people, 'mine' => $person === $me->getId(),
            'timer' => $this->time()->timer($me->getId()), 'projects' => (new Projects($this->ctx->db()))->visible($this->ctx),
            'week' => date('o-\WW', strtotime($mon))], 'Výkazy práce', 'time');
    }

    public function add(Request $req, array $params): Response
    {
        if (!Csrf::check($req)) {
            return Response::text('Formulár vypršal, načítajte stránku znova.', 400);
        }
        $back = Response::redirect($this->ctx->url('/time?week=' . rawurlencode((string)$req->post('week', ''))));
        $minutes = TimeEntries::parse((string)$req->post('hours', ''));
        $date = (string)$req->post('date', '');
        $code = trim((string)$req->post('task', ''));
        $projectId = (int)$req->post('project_id', 0) ?: null;
        $taskId = null;
        if ($minutes === null) {
            Session::flash('error', 'Čas zadajte ako hodiny, napr. 2, 1,5 alebo 1:30 (najviac 24 h).');
            return $back;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date > date('Y-m-d', strtotime('+1 day'))) {
            Session::flash('error', 'Zadajte dátum práce (nie v budúcnosti).');
            return $back;
        }
        if ($code !== '') {
            $t = (new Tasks($this->ctx->db()))->findByCode($code);
            if ($t === null || !(new Tasks($this->ctx->db()))->canSee($this->ctx, $t)) {
                Session::flash('error', 'Úloha „' . $code . '“ neexistuje alebo k nej nemáte prístup — číslo je napr. LED-12.');
                return $back;
            }
            $taskId = (int)$t['id'];
            $projectId = $t['project_id'] !== null ? (int)$t['project_id'] : null;
        } elseif ($projectId) {
            $p = (new Projects($this->ctx->db()))->find($projectId);
            if ($p === null || !(new Projects($this->ctx->db()))->canSee($this->ctx, $p)) {
                $projectId = null;
            }
        }
        $id = $this->time()->add($this->ctx->user()->getId(), $projectId, $taskId, $date, $minutes, (string)$req->post('note', ''), (bool)$req->post('billable'));
        if ($taskId) {
            Audit::log($this->ctx->db(), $this->ctx->user(), 'time', $id, 'update', 'výkaz ' . TimeEntries::format($minutes) . ' h (' . $date . ')', 'task:' . $taskId);
        }
        Session::flash('msg', 'Zapísané ' . TimeEntries::format($minutes) . ' h.');
        $to = (string)$req->post('back', '');
        return $to !== '' && preg_match('#^/tasks/\d+$#', $to) ? Response::redirect($this->ctx->url($to . '#time')) : $back;
    }

    public function delete(Request $req, array $params): Response
    {
        if (!Csrf::check($req)) {
            return Response::text('Formulár vypršal, načítajte stránku znova.', 400);
        }
        $e = $this->time()->find((int)$params['id']);
        if ($e === null || (int)$e['client_id'] !== $this->ctx->user()->getId()) {
            Session::flash('error', 'Zmazať môžete len svoj záznam.');
        } else {
            $this->time()->delete((int)$e['id']);
            Session::flash('msg', 'Záznam zmazaný.');
        }
        return Response::redirect($this->ctx->url('/time?week=' . date('o-\WW', strtotime($e['work_date'] ?? 'now'))));
    }

    /** POST /time/timer — start (task_id / project_id) or stop (stop=1) the stopwatch. */
    public function timer(Request $req, array $params): Response
    {
        if (!Csrf::check($req)) {
            return Response::text('Formulár vypršal, načítajte stránku znova.', 400);
        }
        $me = $this->ctx->user()->getId();
        $back = (string)$req->post('back', '/time');
        $back = preg_match('#^/(tasks/\d+|tasks/my|time)$#', $back) ? $back : '/time';
        if ($req->post('stop')) {
            $id = $this->time()->stop($me, (string)$req->post('note', ''));
            $e = $id ? $this->time()->find($id) : null;
            if ($e && $e['task_id']) {
                Audit::log($this->ctx->db(), $this->ctx->user(), 'time', $id, 'update', 'stopky ' . TimeEntries::format((int)$e['minutes']) . ' h', 'task:' . (int)$e['task_id']);
            }
            Session::flash('msg', $e ? 'Stopky zastavené — zapísané ' . TimeEntries::format((int)$e['minutes']) . ' h.' : 'Stopky nebežali.');
            return Response::redirect($this->ctx->url($back));
        }
        $taskId = (int)$req->post('task_id', 0) ?: null;
        $projectId = null;
        if ($taskId) {
            $t = (new Tasks($this->ctx->db()))->find($taskId);
            if ($t === null || !(new Tasks($this->ctx->db()))->canSee($this->ctx, $t)) {
                return Response::redirect($this->ctx->url($back));
            }
            $projectId = $t['project_id'] !== null ? (int)$t['project_id'] : null;
        }
        $prev = $this->time()->start($me, $projectId, $taskId);
        Session::flash('msg', 'Stopky bežia.' . ($prev ? ' Predchádzajúce sa zastavili a čas sa zapísal.' : ''));
        return Response::redirect($this->ctx->url($back));
    }
}
