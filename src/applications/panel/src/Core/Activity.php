<?php
namespace Panel\Core;

use WebApplication\Database\Database;

/**
 * Activity (docs/panel-architektura.md chapter 4): the history of each record and the feed "Čo sa deje" — both read
 * the shared log (table audit, written by Panel\Audit::log with the record's reference). The feed shows only rows
 * whose record the reader may see (Refs::resolve).
 */
class Activity
{
    /** @var Database */
    private $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    private function t(): string
    {
        return $this->db->table('audit');
    }

    /** History of one record, newest first. */
    public function forRef(string $ref, int $limit = 200): array
    {
        return $this->db->query("SELECT * FROM {$this->t()} WHERE ref = ? ORDER BY id DESC LIMIT " . max(1, min(1000, $limit)), [$ref]);
    }

    /**
     * Newest rows the reader may see, older than $beforeId (0 = from the newest). Reads in batches and filters by
     * Refs::resolve; at most $scan rows are looked at per call (long runs of invisible rows end the page early).
     * @return array{rows: array, next: int} rows with 'record' (resolved), next = id for the next page (0 = end)
     */
    public function feed(Refs $refs, int $beforeId = 0, int $limit = 50, int $scan = 1000): array
    {
        $out = [];
        $cursor = $beforeId;
        $seen = 0;
        while (count($out) < $limit && $seen < $scan) {
            $batch = $this->db->query("SELECT * FROM {$this->t()} WHERE ref IS NOT NULL" . ($cursor > 0 ? ' AND id < ' . (int)$cursor : '')
                . ' ORDER BY id DESC LIMIT 100');
            if (!$batch) {
                return ['rows' => $out, 'next' => 0];
            }
            foreach ($batch as $row) {
                $seen++;
                $cursor = (int)$row['id'];
                $r = $refs->resolve((string)$row['ref']);
                if ($r !== null) {
                    $row['record'] = $r;
                    $out[] = $row;
                    if (count($out) >= $limit) {
                        break;
                    }
                }
            }
        }
        return ['rows' => $out, 'next' => $cursor];
    }
}
