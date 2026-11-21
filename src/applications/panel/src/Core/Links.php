<?php
namespace Panel\Core;

use WebApplication\Database\Database;
use WebApplication\Entity\Client;

/**
 * Links between any two records (docs/panel-architektura.md chapter 4): "this task relates to that order", "the credit
 * note was derived from the complaint" … Stored once (from → to, kind); each side shows it with its own wording.
 * Modules don't know each other — they only see "a record of type X". Who may see what: Refs::resolve (callers filter).
 */
class Links
{
    /** kind => [label from the "from" side, label from the "to" side] */
    const KINDS = [
        'relates' => ['súvisí s', 'súvisí s'],
        'blocks' => ['blokuje', 'je blokované záznamom'],
        'verifies' => ['overuje', 'je overené záznamom'],
        'derived' => ['vzniklo zo záznamu', 'z neho vzniklo'],
        'replaces' => ['nahrádza', 'je nahradené záznamom'],
    ];

    /** @var Database */
    private $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    private function t(): string
    {
        return $this->db->table('link');
    }

    /** @return int|null id of the new link; null = the same link exists already or both sides are the same record */
    public function add(string $from, string $to, string $kind, ?Client $user): ?int
    {
        if ($from === $to || !isset(self::KINDS[$kind])) {
            return null;
        }
        $exists = $this->db->fetchValue("SELECT id FROM {$this->t()} WHERE kind = ? AND ((from_ref = ? AND to_ref = ?)"
            . ($kind === 'relates' ? ' OR (from_ref = ? AND to_ref = ?))' : ')'),
            $kind === 'relates' ? [$kind, $from, $to, $to, $from] : [$kind, $from, $to]);
        if ($exists) {
            return null;
        }
        $this->db->execute("INSERT INTO {$this->t()} (from_ref, to_ref, kind, client_id, client_name, created_at) VALUES (?, ?, ?, ?, ?, ?)",
            [$from, $to, $kind, $user ? $user->getId() : null, $user ? $user->getName() : '', date('Y-m-d H:i:s')]);
        return $this->db->lastInsertId();
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

    /** Links of a record seen from it: list of [id, kind, other (ref), label, client_name, created_at], newest first. */
    public function forRef(string $ref): array
    {
        $out = [];
        foreach ($this->db->query("SELECT * FROM {$this->t()} WHERE from_ref = ? OR to_ref = ? ORDER BY id DESC", [$ref, $ref]) as $l) {
            $out_ = $l['from_ref'] === $ref;
            $out[] = ['id' => (int)$l['id'], 'kind' => $l['kind'], 'other' => $out_ ? $l['to_ref'] : $l['from_ref'],
                'label' => self::KINDS[$l['kind']][$out_ ? 0 : 1] ?? $l['kind'], 'client_name' => $l['client_name'], 'created_at' => $l['created_at']];
        }
        return $out;
    }
}
