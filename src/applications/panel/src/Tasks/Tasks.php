<?php
namespace Panel\Tasks;

use Panel\PanelContext;
use Panel\Rights;
use WebApplication\Database\Database;

/**
 * Tasks (docs/panel-architektura.md 6.1): in a project (number = project key + number, "LED-12") or personal
 * ("U-45" — seen by its author and its assignee). Columns of the project = states; a task in a "done" column is closed.
 * Watchers (author, assignee, who asks) hear about changes and comments.
 */
class Tasks
{
    const PRIORITIES = [1 => 'nízka', 2 => 'bežná', 3 => 'vysoká', 4 => 'súrna'];
    const PERSONAL_KEY = 'U';

    /** @var Database */
    private $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    private function t(string $name): string
    {
        return $this->db->table($name);
    }

    private function select(): string
    {
        return "SELECT t.*, COALESCE(p.`key`, '" . self::PERSONAL_KEY . "') AS project_key, p.name AS project_name, p.company_id, p.status AS project_status,
                CONCAT(COALESCE(p.`key`, '" . self::PERSONAL_KEY . "'), '-', t.number) AS code, col.name AS column_name, col.done AS column_done,
                a.name AS assignee_name, cr.name AS author_name
            FROM {$this->t('task')} t
            LEFT JOIN {$this->t('project')} p ON p.id = t.project_id
            LEFT JOIN {$this->t('project_column')} col ON col.id = t.column_id
            LEFT JOIN {$this->t('client')} a ON a.id = t.assignee_id
            LEFT JOIN {$this->t('client')} cr ON cr.id = t.created_by";
    }

    public function find(int $id): ?array
    {
        $r = $this->db->fetchOne($this->select() . ' WHERE t.id = ?', [$id]);
        return $r === false ? null : $r;
    }

    /** "LED-12" / "U-5" → task. */
    public function findByCode(string $code): ?array
    {
        if (!preg_match('/^([A-Z][A-Z0-9]{0,9})-(\d{1,9})$/', strtoupper(trim($code)), $m)) {
            return null;
        }
        $r = $m[1] === self::PERSONAL_KEY
            ? $this->db->fetchOne($this->select() . ' WHERE t.project_id IS NULL AND t.number = ?', [(int)$m[2]])
            : $this->db->fetchOne($this->select() . ' WHERE p.`key` = ? AND t.number = ?', [$m[1], (int)$m[2]]);
        return $r === false ? null : $r;
    }

    /** May the person see the task? (project → Projects::canSee; personal → author or assignee) */
    public function canSee(PanelContext $ctx, array $task): bool
    {
        $u = $ctx->user();
        if ($u === null || !$ctx->can(Rights::TASKS_VIEW)) {
            return false;
        }
        if ($task['project_id'] === null) {
            return (int)$task['created_by'] === $u->getId() || (int)$task['assignee_id'] === $u->getId();
        }
        $p = (new Projects($this->db))->find((int)$task['project_id']);
        return $p !== null && (new Projects($this->db))->canSee($ctx, $p);
    }

    public function canEdit(PanelContext $ctx, array $task): bool
    {
        if (!$this->canSee($ctx, $task) || !$ctx->can(Rights::TASKS_EDIT)) {
            return false;
        }
        if ($task['project_id'] === null) {
            return true;
        }
        $p = (new Projects($this->db))->find((int)$task['project_id']);
        return (new Projects($this->db))->canEdit($ctx, $p);
    }

    /** SQL condition (alias t) of the tasks the person may see. */
    public function visibleSql(PanelContext $ctx): array
    {
        $u = $ctx->user();
        if ($u === null || !$ctx->can(Rights::TASKS_VIEW)) {
            return ['0 = 1', []];
        }
        list($pw, $pp) = (new Projects($this->db))->visibleSql($ctx, 'vp');
        return ["((t.project_id IS NULL AND (t.created_by = ? OR t.assignee_id = ?))
            OR t.project_id IN (SELECT vp.id FROM {$this->t('project')} vp WHERE $pw))", array_merge([$u->getId(), $u->getId()], $pp)];
    }

    /**
     * @param array $f project (id), assignee (id), status (open | done | all), q (code / title), label, due (overdue | week)
     * @return array tasks visible to the person
     */
    public function search(PanelContext $ctx, array $f, int $limit = 300): array
    {
        list($w, $p) = $this->visibleSql($ctx);
        $where = [$w];
        if (!empty($f['project'])) {
            $where[] = 't.project_id = ?';
            $p[] = (int)$f['project'];
        } elseif (($f['project'] ?? null) === 0) {
            $where[] = 't.project_id IS NULL';
        }
        if (!empty($f['assignee'])) {
            $where[] = 't.assignee_id = ?';
            $p[] = (int)$f['assignee'];
        }
        $status = $f['status'] ?? 'open';
        if ($status === 'open') {
            $where[] = 't.closed_at IS NULL';
        } elseif ($status === 'done') {
            $where[] = 't.closed_at IS NOT NULL';
        }
        if (!empty($f['label'])) {
            $where[] = "CONCAT(',', t.labels, ',') LIKE ?";
            $p[] = '%,' . addcslashes(trim((string)$f['label']), '%_\\') . ',%';
        }
        if (($f['due'] ?? '') === 'overdue') {
            $where[] = 't.due_date < ?';
            $p[] = date('Y-m-d');
        } elseif (($f['due'] ?? '') === 'week') {
            $where[] = 't.due_date <= ?';
            $p[] = date('Y-m-d', strtotime('+7 days'));
        }
        $q = trim((string)($f['q'] ?? ''));
        if ($q !== '') {
            $where[] = "(t.title LIKE ? OR CONCAT(COALESCE(p.`key`, '" . self::PERSONAL_KEY . "'), '-', t.number) = ?)";
            array_push($p, '%' . addcslashes($q, '%_\\') . '%', strtoupper($q));
        }
        return $this->db->query($this->select() . ' WHERE ' . implode(' AND ', $where)
            . ' ORDER BY t.closed_at IS NOT NULL, t.due_date IS NULL, t.due_date, t.priority DESC, t.id DESC LIMIT ' . max(1, min(1000, $limit)), $p);
    }

    /** Tasks of the person (assigned to him or watched, not closed) — "Moje úlohy". */
    public function mine(PanelContext $ctx, bool $watched = false): array
    {
        list($w, $p) = $this->visibleSql($ctx);
        $me = $ctx->user()->getId();
        $who = $watched ? "t.id IN (SELECT task_id FROM {$this->t('task_watcher')} WHERE client_id = ?) AND (t.assignee_id IS NULL OR t.assignee_id <> ?)"
            : 't.assignee_id = ?';
        return $this->db->query($this->select() . " WHERE $w AND t.closed_at IS NULL AND $who
            ORDER BY t.due_date IS NULL, t.due_date, t.priority DESC, t.id", array_merge($p, $watched ? [$me, $me] : [$me]));
    }

    /** Next number in the project (personal: among personal tasks) — inside a transaction. */
    private function nextNumber(?int $projectId): int
    {
        if ($projectId === null) {
            return (int)$this->db->fetchValue("SELECT COALESCE(MAX(number), 0) + 1 FROM {$this->t('task')} WHERE project_id IS NULL FOR UPDATE");
        }
        $n = (int)$this->db->fetchValue("SELECT next_number FROM {$this->t('project')} WHERE id = ? FOR UPDATE", [$projectId]);
        $this->db->execute("UPDATE {$this->t('project')} SET next_number = ? WHERE id = ?", [$n + 1, $projectId]);
        return $n;
    }

    /** @param array $v title, description, assignee_id, priority, start_date, due_date, labels, estimate_minutes, column_id, parent_id */
    public function create(?int $projectId, array $v, int $authorId): int
    {
        foreach (['assignee_id', 'start_date', 'due_date', 'estimate_minutes', 'column_id', 'parent_id'] as $k) {
            if (($v[$k] ?? null) === '' || ($v[$k] ?? null) === 0) {
                $v[$k] = null;   // empty form field = not set
            }
        }
        return $this->db->transaction(function (Database $db) use ($projectId, $v, $authorId) {
            $number = $this->nextNumber($projectId);
            $column = $v['column_id'] ?? null;
            if ($projectId !== null && !$column) {
                $column = (int)$db->fetchValue("SELECT id FROM {$this->t('project_column')} WHERE project_id = ? ORDER BY position, id LIMIT 1", [$projectId]);
            }
            $now = date('Y-m-d H:i:s');
            $pos = $column ? (int)$db->fetchValue("SELECT COALESCE(MAX(position), 0) + 1 FROM {$this->t('task')} WHERE column_id = ?", [$column]) : 0;
            $db->execute("INSERT INTO {$this->t('task')} (project_id, number, title, description, column_id, position, assignee_id, priority, start_date, due_date,
                    labels, estimate_minutes, parent_id, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [$projectId, $number, $v['title'], $v['description'] ?? '', $column ?: null, $pos, $v['assignee_id'] ?? null, (int)($v['priority'] ?? 2),
                 $v['start_date'] ?? null, $v['due_date'] ?? null, self::labels((string)($v['labels'] ?? '')), $v['estimate_minutes'] ?? null,
                 $v['parent_id'] ?? null, $authorId, $now, $now]);
            $id = (int)$db->lastInsertId();
            $this->watch($id, $authorId);
            if (!empty($v['assignee_id'])) {
                $this->watch($id, (int)$v['assignee_id']);
            }
            $this->syncClosed($id);
            return $id;
        });
    }

    /** @return array what changed: field => [old, new] (for the history + events) */
    public function update(int $id, array $v): array
    {
        $old = $this->find($id);
        $fields = ['title', 'description', 'assignee_id', 'priority', 'start_date', 'due_date', 'labels', 'estimate_minutes', 'column_id', 'parent_id'];
        $changes = [];
        $set = [];
        $params = [];
        foreach ($fields as $f) {
            if (!array_key_exists($f, $v)) {
                continue;
            }
            $new = $f === 'labels' ? self::labels((string)$v[$f]) : $v[$f];
            if ((string)$old[$f] !== (string)$new) {
                $changes[$f] = [$old[$f], $new];
                $set[] = "$f = ?";
                $params[] = $new === '' && in_array($f, ['assignee_id', 'start_date', 'due_date', 'estimate_minutes', 'column_id', 'parent_id'], true) ? null : $new;
            }
        }
        if ($set) {
            $params[] = date('Y-m-d H:i:s');
            $params[] = $id;
            $this->db->execute("UPDATE {$this->t('task')} SET " . implode(', ', $set) . ', updated_at = ? WHERE id = ?', $params);
            if (!empty($v['assignee_id'])) {
                $this->watch($id, (int)$v['assignee_id']);
            }
            $this->syncClosed($id);
        }
        return $changes;
    }

    /** Kanban: put the task into a column at a position (0 = top); renumbers the column. @return bool column changed */
    public function move(int $id, int $columnId, int $position): bool
    {
        $task = $this->find($id);
        $col = $this->db->fetchOne("SELECT * FROM {$this->t('project_column')} WHERE id = ?", [$columnId]);
        if ($task === null || $col === false || (int)$col['project_id'] !== (int)$task['project_id']) {
            throw new \InvalidArgumentException('Stĺpec nepatrí k projektu úlohy.');
        }
        $this->db->transaction(function (Database $db) use ($id, $columnId, $position) {
            $ids = array_map('intval', array_column($db->query("SELECT id FROM {$this->t('task')} WHERE column_id = ? AND id <> ? ORDER BY position, id",
                [$columnId, $id]), 'id'));
            array_splice($ids, max(0, min($position, count($ids))), 0, [$id]);
            foreach ($ids as $i => $tid) {
                $db->execute("UPDATE {$this->t('task')} SET position = ?, column_id = ? WHERE id = ?", [$i, $columnId, $tid]);
            }
            $db->execute("UPDATE {$this->t('task')} SET updated_at = ? WHERE id = ?", [date('Y-m-d H:i:s'), $id]);
            $this->syncClosed($id);
        });
        return (int)$task['column_id'] !== $columnId;
    }

    /** Personal task (no columns): done / open. */
    public function setDone(int $id, bool $done): void
    {
        $this->db->execute("UPDATE {$this->t('task')} SET closed_at = ?, updated_at = ? WHERE id = ? AND project_id IS NULL",
            [$done ? date('Y-m-d H:i:s') : null, date('Y-m-d H:i:s'), $id]);
    }

    private function syncClosed(int $id): void
    {
        $this->db->execute("UPDATE {$this->t('task')} t LEFT JOIN {$this->t('project_column')} c ON c.id = t.column_id
            SET t.closed_at = IF(c.done = 1, COALESCE(t.closed_at, ?), NULL) WHERE t.id = ? AND t.project_id IS NOT NULL", [date('Y-m-d H:i:s'), $id]);
    }

    public static function labels(string $labels): string
    {
        $out = [];
        foreach (preg_split('/[,;]+/', $labels) as $l) {
            $l = mb_substr(trim(preg_replace('/\s+/u', ' ', $l)), 0, 40, 'UTF-8');
            if ($l !== '' && !in_array(mb_strtolower($l, 'UTF-8'), array_map(function ($x) { return mb_strtolower($x, 'UTF-8'); }, $out), true)) {
                $out[] = $l;
            }
        }
        return mb_substr(implode(',', $out), 0, 500, 'UTF-8');
    }

    // ------------------------------------------------------------------ watchers, checklist

    public function watch(int $taskId, int $clientId): void
    {
        $this->db->execute("INSERT IGNORE INTO {$this->t('task_watcher')} (task_id, client_id) VALUES (?, ?)", [$taskId, $clientId]);
    }

    public function unwatch(int $taskId, int $clientId): void
    {
        $this->db->execute("DELETE FROM {$this->t('task_watcher')} WHERE task_id = ? AND client_id = ?", [$taskId, $clientId]);
    }

    /** @return int[] */
    public function watchers(int $taskId): array
    {
        return array_map('intval', array_column($this->db->query("SELECT client_id FROM {$this->t('task_watcher')} WHERE task_id = ?", [$taskId]), 'client_id'));
    }

    public function checklist(int $taskId): array
    {
        return $this->db->query("SELECT * FROM {$this->t('task_check')} WHERE task_id = ? ORDER BY position, id", [$taskId]);
    }

    public function addCheck(int $taskId, string $text): void
    {
        $text = mb_substr(trim($text), 0, 255, 'UTF-8');
        if ($text !== '') {
            $pos = (int)$this->db->fetchValue("SELECT COALESCE(MAX(position), 0) + 1 FROM {$this->t('task_check')} WHERE task_id = ?", [$taskId]);
            $this->db->execute("INSERT INTO {$this->t('task_check')} (task_id, text, position) VALUES (?, ?, ?)", [$taskId, $text, $pos]);
        }
    }

    public function toggleCheck(int $taskId, int $checkId): void
    {
        $this->db->execute("UPDATE {$this->t('task_check')} SET done = 1 - done WHERE id = ? AND task_id = ?", [$checkId, $taskId]);
    }

    public function deleteCheck(int $taskId, int $checkId): void
    {
        $this->db->execute("DELETE FROM {$this->t('task_check')} WHERE id = ? AND task_id = ?", [$checkId, $taskId]);
    }

    public function subtasks(int $taskId): array
    {
        return $this->db->query($this->select() . ' WHERE t.parent_id = ? ORDER BY t.closed_at IS NOT NULL, t.id', [$taskId]);
    }

    /** Open tasks with a due date ≤ $until not reminded today (scheduler). */
    public function dueForReminder(string $today, string $until): array
    {
        return $this->db->query($this->select() . " WHERE t.closed_at IS NULL AND t.assignee_id IS NOT NULL AND t.due_date IS NOT NULL AND t.due_date <= ?
            AND (t.reminded_on IS NULL OR t.reminded_on < ?) AND (p.status IS NULL OR p.status = 'active') LIMIT 500", [$until, $today]);
    }

    public function markReminded(int $id, string $today): void
    {
        $this->db->execute("UPDATE {$this->t('task')} SET reminded_on = ? WHERE id = ?", [$today, $id]);
    }
}
