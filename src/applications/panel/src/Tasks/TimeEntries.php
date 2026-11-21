<?php
namespace Panel\Tasks;

use WebApplication\Database\Database;

/**
 * Time sheets (docs/panel-architektura.md 6.1 "Výkazy práce"): hours of a person at a task / project / general work,
 * billable or not; a stopwatch (one running per person). Input for project finances and costs (phase 3, 6).
 */
class TimeEntries
{
    const MAX_MINUTES = 24 * 60;

    /** @var Database */
    private $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    private function t(string $name = 'time_entry'): string
    {
        return $this->db->table($name);
    }

    /** "1:30", "1,5", "1.5 h", "90 min", "45m" → minutes; null = not a time. */
    public static function parse(string $s): ?int
    {
        $s = strtolower(trim(str_replace(',', '.', $s)));
        if (preg_match('/^(\d{1,2}):([0-5]\d)$/', $s, $m)) {
            $min = (int)$m[1] * 60 + (int)$m[2];
        } elseif (preg_match('/^(\d+)\s*(m|min)$/', $s, $m)) {
            $min = (int)$m[1];
        } elseif (preg_match('/^(\d+(?:\.\d+)?)\s*h?$/', $s, $m)) {
            $min = (int)round((float)$m[1] * 60);
        } else {
            return null;
        }
        return $min > 0 && $min <= self::MAX_MINUTES ? $min : null;
    }

    public static function format(int $minutes): string
    {
        return intdiv($minutes, 60) . ':' . str_pad((string)($minutes % 60), 2, '0', STR_PAD_LEFT);
    }

    public function add(int $clientId, ?int $projectId, ?int $taskId, string $date, int $minutes, string $note, bool $billable, string $source = 'manual'): int
    {
        $this->db->execute("INSERT INTO {$this->t()} (client_id, project_id, task_id, work_date, minutes, note, billable, source, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [$clientId, $projectId, $taskId, $date, $minutes, mb_substr(trim($note), 0, 500, 'UTF-8'), $billable ? 1 : 0, $source, date('Y-m-d H:i:s')]);
        return (int)$this->db->lastInsertId();
    }

    public function find(int $id): ?array
    {
        $r = $this->db->fetchOne("SELECT * FROM {$this->t()} WHERE id = ?", [$id]);
        return $r === false ? null : $r;
    }

    public function delete(int $id): void
    {
        $this->db->execute("DELETE FROM {$this->t()} WHERE id = ?", [$id]);
    }

    /** Entries of a person in a period, with the task code / project name. */
    public function forPerson(int $clientId, string $from, string $to): array
    {
        return $this->db->query("SELECT e.*, p.`key` AS project_key, p.name AS project_name, t.title AS task_title,
                CONCAT(COALESCE(tp.`key`, 'U'), '-', t.number) AS task_code
            FROM {$this->t()} e LEFT JOIN {$this->t('project')} p ON p.id = e.project_id
            LEFT JOIN {$this->t('task')} t ON t.id = e.task_id LEFT JOIN {$this->t('project')} tp ON tp.id = t.project_id
            WHERE e.client_id = ? AND e.work_date BETWEEN ? AND ? ORDER BY e.work_date, e.id", [$clientId, $from, $to]);
    }

    public function forTask(int $taskId): array
    {
        return $this->db->query("SELECT e.*, c.name AS person FROM {$this->t()} e JOIN {$this->t('client')} c ON c.id = e.client_id
            WHERE e.task_id = ? ORDER BY e.work_date DESC, e.id DESC", [$taskId]);
    }

    public function taskMinutes(int $taskId): int
    {
        return (int)$this->db->fetchValue("SELECT COALESCE(SUM(minutes), 0) FROM {$this->t()} WHERE task_id = ?", [$taskId]);
    }

    // ------------------------------------------------------------------ stopwatch

    public function timer(int $clientId): ?array
    {
        $r = $this->db->fetchOne("SELECT * FROM {$this->t('time_timer')} WHERE client_id = ?", [$clientId]);
        return $r === false ? null : $r;
    }

    /** Starts the stopwatch (a running one is stopped and written first). @return int|null id of the entry of the stopped one */
    public function start(int $clientId, ?int $projectId, ?int $taskId): ?int
    {
        $prev = $this->stop($clientId, '');
        $this->db->execute("INSERT INTO {$this->t('time_timer')} (client_id, task_id, project_id, started_at) VALUES (?, ?, ?, ?)",
            [$clientId, $taskId, $projectId, date('Y-m-d H:i:s')]);
        return $prev;
    }

    /** Stops the stopwatch and writes the time (at least 1 minute). @return int|null entry id (null = nothing ran) */
    public function stop(int $clientId, string $note, ?int $now = null): ?int
    {
        $t = $this->timer($clientId);
        if ($t === null) {
            return null;
        }
        $this->db->execute("DELETE FROM {$this->t('time_timer')} WHERE client_id = ?", [$clientId]);
        $min = (int)max(1, min(self::MAX_MINUTES, round((($now ?? time()) - strtotime($t['started_at'])) / 60)));
        return $this->add($clientId, $t['project_id'] !== null ? (int)$t['project_id'] : null, $t['task_id'] !== null ? (int)$t['task_id'] : null,
            date('Y-m-d', strtotime($t['started_at'])), $min, $note, false, 'timer');
    }
}
