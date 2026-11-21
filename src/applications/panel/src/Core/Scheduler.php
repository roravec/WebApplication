<?php
namespace Panel\Core;

use Panel\PanelContext;
use WebApplication\Database\Database;

/**
 * Scheduler (docs/panel-architektura.md chapter 4, rule 9 — shared hosting: no long-running processes): jobs
 * registered by the modules (Core\ProvidesJobs) run when due, triggered by ONE cron URL (/panel/cron?token=… — if the
 * hosting has a cron) and by a quiet tick from open panel pages (/panel/cron/tick, at most once a minute), so it works
 * even without a cron. Each job: lock (no parallel run), last run + result in job_run, errors never stop other jobs.
 */
class Scheduler
{
    const LOCK_MINUTES = 10;

    /** @var array|null key => job */
    private static $jobs = null;

    /** @var Database */
    private $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /** @return array key => ['key', 'label', 'every', 'hour', 'run'] */
    public static function jobs(): array
    {
        if (self::$jobs === null) {
            self::$jobs = self::fromModules(\PanelApp::modules());
        }
        return self::$jobs;
    }

    /** @param \Panel\Module[] $modules */
    public static function fromModules(array $modules): array
    {
        $out = [];
        foreach ($modules as $m) {
            if ($m instanceof ProvidesJobs) {
                foreach ($m->jobs() as $j) {
                    if (isset($out[$j['key']])) {
                        throw new \LogicException('Úloha ' . $j['key'] . ' je registrovaná dvakrát.');
                    }
                    $out[$j['key']] = ['key' => $j['key'], 'label' => (string)$j['label'], 'every' => max(1, (int)$j['every']),
                        'hour' => isset($j['hour']) ? (int)$j['hour'] : null, 'run' => $j['run']];
                }
            }
        }
        return $out;
    }

    /** @return array key => job_run row */
    public function state(): array
    {
        $out = [];
        foreach ($this->db->query('SELECT * FROM ' . $this->db->table('job_run')) as $r) {
            $out[$r['job']] = $r;
        }
        return $out;
    }

    public static function isDue(array $job, ?array $state, int $now): bool
    {
        if ($job['hour'] !== null && (int)date('G', $now) < $job['hour']) {
            return false;
        }
        if ($state === null || $state['last_run_at'] === null) {
            return true;
        }
        $last = strtotime($state['last_run_at']);
        if ($job['every'] >= 1440) {   // daily: once per calendar day (from 'hour'), not "24 hours after the last run"
            return date('Y-m-d', $last) !== date('Y-m-d', $now);
        }
        return $now - $last >= $job['every'] * 60 - 5;
    }

    /**
     * Runs the due jobs (or one job by key, $force = also when not due). @param PanelContext $system context without a person
     * @return array list of [key, ok, message]
     */
    public function run(PanelContext $system, ?string $only = null, bool $force = false, ?int $now = null): array
    {
        $now = $now ?? time();
        $state = $this->state();
        $out = [];
        foreach (self::jobs() as $key => $job) {
            if (($only !== null && $key !== $only) || (!$force && !self::isDue($job, $state[$key] ?? null, $now)) || !$this->lock($key, $now)) {
                continue;
            }
            try {
                $msg = (string)($job['run'])($system);
                $ok = true;
            } catch (\Throwable $e) {
                $msg = $e->getMessage();
                $ok = false;
            }
            $this->db->execute('UPDATE ' . $this->db->table('job_run') . ' SET last_run_at = ?, last_ok = ?, last_message = ?, running_until = NULL WHERE job = ?',
                [date('Y-m-d H:i:s', $now), $ok ? 1 : 0, mb_substr($msg, 0, 1000, 'UTF-8'), $key]);
            $out[] = [$key, $ok, $msg];
        }
        return $out;
    }

    /** Takes the job (false = another run has it now). */
    private function lock(string $key, int $now): bool
    {
        $t = $this->db->table('job_run');
        $this->db->execute("INSERT IGNORE INTO $t (job) VALUES (?)", [$key]);
        return $this->db->affected("UPDATE $t SET running_until = ? WHERE job = ? AND (running_until IS NULL OR running_until < ?)",
            [date('Y-m-d H:i:s', $now + self::LOCK_MINUTES * 60), $key, date('Y-m-d H:i:s', $now)]) > 0;
    }

    /** The quiet tick from panel pages: true at most once a minute (the caller then runs the due jobs). */
    public function tickDue(int $now): bool
    {
        $t = $this->db->table('setting');
        $this->db->execute("INSERT IGNORE INTO $t (name, value, updated_at) VALUES ('scheduler.tick', '0', ?)", [date('Y-m-d H:i:s', $now)]);
        return $this->db->affected("UPDATE $t SET value = ?, updated_at = ? WHERE name = 'scheduler.tick' AND CAST(value AS UNSIGNED) < ?",
            [(string)$now, date('Y-m-d H:i:s', $now), $now - 60]) > 0;
    }
}
