<?php
namespace Panel\Shop;

use WebApplication\Database\Database;
use WebApplication\Entity\Client;

/** The panel's own records about shop orders: internal notes, messages to the customer, returns; small settings. */
class OrderLog
{
    /** @var Database */ private $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    private function t(string $name): string
    {
        return $this->db->table($name);
    }

    public function notes(int $source, int $orderId): array
    {
        return $this->db->query("SELECT * FROM {$this->t('order_note')} WHERE source = ? AND order_id = ? ORDER BY id", [$source, $orderId]);
    }

    public function addNote(int $source, int $orderId, string $text, ?Client $user): void
    {
        $this->db->execute("INSERT INTO {$this->t('order_note')} (source, order_id, text, client_id, client_name, created_at) VALUES (?, ?, ?, ?, ?, ?)",
            [$source, $orderId, $text, $user ? $user->getId() : null, $user ? $user->getName() : '', date('Y-m-d H:i:s')]);
    }

    /** @return array order_id => number of notes, for a list of orders */
    public function noteCounts(int $source, array $orderIds): array
    {
        $ids = array_values(array_filter(array_map('intval', $orderIds)));
        if (!$ids) {
            return [];
        }
        $out = [];
        foreach ($this->db->query("SELECT order_id, COUNT(*) AS n FROM {$this->t('order_note')} WHERE source = ? AND order_id IN (" . implode(',', $ids) . ')
                GROUP BY order_id', [$source]) as $r) {
            $out[(int)$r['order_id']] = (int)$r['n'];
        }
        return $out;
    }

    public function messages(int $source, int $orderId): array
    {
        return $this->db->query("SELECT * FROM {$this->t('order_message')} WHERE source = ? AND order_id = ? ORDER BY id", [$source, $orderId]);
    }

    public function logMessage(int $source, int $orderId, array $to, string $subject, string $body, string $error, ?Client $user): void
    {
        $this->db->execute("INSERT INTO {$this->t('order_message')} (source, order_id, recipients, subject, body, status, error, client_id, client_name, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", [$source, $orderId, mb_substr(implode(', ', $to), 0, 500, 'UTF-8'), mb_substr($subject, 0, 255, 'UTF-8'),
            $body, $error === '' ? 'sent' : 'failed', mb_substr($error, 0, 500, 'UTF-8'), $user ? $user->getId() : null, $user ? $user->getName() : '',
            date('Y-m-d H:i:s')]);
    }

    public function returns(int $source, int $orderId): array
    {
        $rows = $this->db->query("SELECT * FROM {$this->t('order_return')} WHERE source = ? AND order_id = ? ORDER BY id", [$source, $orderId]);
        foreach ($rows as &$r) {
            $r['lines'] = (array)json_decode((string)$r['lines'], true);
            $r['problems'] = $r['problems'] ? (array)json_decode((string)$r['problems'], true) : [];
        }
        return $rows;
    }

    /** @return array id_order_detail => quantity already returned */
    public function returned(int $source, int $orderId): array
    {
        $out = [];
        foreach ($this->returns($source, $orderId) as $r) {
            foreach ($r['lines'] as $l) {
                $out[(int)$l['id_order_detail']] = ($out[(int)$l['id_order_detail']] ?? 0) + (int)$l['quantity'];
            }
        }
        return $out;
    }

    public function addReturn(int $source, int $orderId, array $lines, bool $shipping, bool $restocked, ?int $creditNoteId, string $state,
                              string $note, array $problems, ?Client $user): int
    {
        $this->db->execute("INSERT INTO {$this->t('order_return')} (source, order_id, `lines`, shipping, restocked, credit_note_id, state, note, problems,
            client_id, client_name, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", [$source, $orderId, json_encode(array_values($lines),
            JSON_UNESCAPED_UNICODE), $shipping ? 1 : 0, $restocked ? 1 : 0, $creditNoteId, mb_substr($state, 0, 100, 'UTF-8'), mb_substr($note, 0, 500, 'UTF-8'),
            $problems ? json_encode($problems, JSON_UNESCAPED_UNICODE) : null, $user ? $user->getId() : null, $user ? $user->getName() : '', date('Y-m-d H:i:s')]);
        return (int)$this->db->lastInsertId();
    }

    public function setting(string $name, $default = null)
    {
        $v = $this->db->fetchValue("SELECT value FROM {$this->t('setting')} WHERE name = ?", [$name]);
        return $v === false || $v === null ? $default : json_decode((string)$v, true);
    }

    public function setSetting(string $name, $value): void
    {
        $this->db->execute("INSERT INTO {$this->t('setting')} (name, value, updated_at) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = VALUES(updated_at)", [$name, json_encode($value), date('Y-m-d H:i:s')]);
    }
}
