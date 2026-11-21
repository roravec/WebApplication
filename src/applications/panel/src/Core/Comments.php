<?php
namespace Panel\Core;

use WebApplication\Database\Database;
use WebApplication\Entity\Client;

/**
 * Internal comments at any record (docs/panel-architektura.md chapter 4) with @mentions of colleagues ("@Ján Novák" —
 * the full name of an active employee). Never shown to customers. Only the author (or Správca) edits / deletes;
 * a deleted comment stays as "zmazaný" so a discussion keeps its sense. Notifications of mentions: step 1c.
 */
class Comments
{
    const MAX = 5000;

    /** @var Database */
    private $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    private function t(string $name = 'comment'): string
    {
        return $this->db->table($name);
    }

    /** @return array comments of the record, oldest first, each with 'mentions' (client id => name) */
    public function forRef(string $ref): array
    {
        $rows = $this->db->query("SELECT * FROM {$this->t()} WHERE ref = ? ORDER BY id", [$ref]);
        $ids = array_map('intval', array_column($rows, 'id'));
        $mentions = [];
        if ($ids) {
            foreach ($this->db->query("SELECT m.comment_id, c.id, c.name FROM {$this->t('comment_mention')} m JOIN {$this->t('client')} c ON c.id = m.client_id
                    WHERE m.comment_id IN (" . implode(',', $ids) . ')') as $m) {
                $mentions[(int)$m['comment_id']][(int)$m['id']] = $m['name'];
            }
        }
        foreach ($rows as &$r) {
            $r['mentions'] = $mentions[(int)$r['id']] ?? [];
        }
        return $rows;
    }

    public function find(int $id): ?array
    {
        $r = $this->db->fetchOne("SELECT * FROM {$this->t()} WHERE id = ?", [$id]);
        return $r === false ? null : $r;
    }

    /** @return int the new comment id */
    public function add(string $ref, string $body, ?Client $user): int
    {
        $body = self::clean($body);
        return $this->db->transaction(function (Database $db) use ($ref, $body, $user) {
            $db->execute("INSERT INTO {$this->t()} (ref, body, client_id, client_name, created_at) VALUES (?, ?, ?, ?, ?)",
                [$ref, $body, $user ? $user->getId() : null, $user ? $user->getName() : '', date('Y-m-d H:i:s')]);
            $id = (int)$db->lastInsertId();
            $this->saveMentions($id, $body);
            return $id;
        });
    }

    public function update(int $id, string $body): void
    {
        $body = self::clean($body);
        $this->db->transaction(function (Database $db) use ($id, $body) {
            $db->execute("UPDATE {$this->t()} SET body = ?, edited_at = ? WHERE id = ? AND deleted_at IS NULL", [$body, date('Y-m-d H:i:s'), $id]);
            $this->saveMentions($id, $body);
        });
    }

    public function delete(int $id): void
    {
        $this->db->execute("UPDATE {$this->t()} SET deleted_at = ? WHERE id = ?", [date('Y-m-d H:i:s'), $id]);
        $this->db->execute("DELETE FROM {$this->t('comment_mention')} WHERE comment_id = ?", [$id]);
    }

    /** People who wrote a (not deleted) comment at the record — they hear about new comments. @return int[] */
    public function participants(string $ref): array
    {
        return array_map('intval', array_column($this->db->query("SELECT DISTINCT client_id FROM {$this->t()} WHERE ref = ? AND deleted_at IS NULL
            AND client_id IS NOT NULL", [$ref]), 'client_id'));
    }

    /** @return array ref => number of (not deleted) comments, for lists */
    public function counts(array $refs): array
    {
        $refs = array_values(array_unique(array_map('strval', $refs)));
        if (!$refs) {
            return [];
        }
        $out = [];
        foreach ($this->db->query("SELECT ref, COUNT(*) AS n FROM {$this->t()} WHERE deleted_at IS NULL AND ref IN ("
                . implode(',', array_fill(0, count($refs), '?')) . ') GROUP BY ref', $refs) as $r) {
            $out[$r['ref']] = (int)$r['n'];
        }
        return $out;
    }

    /** People for @mentions: active employees, id => name (longest names first, so "Ján Novák ml." wins over "Ján Novák"). */
    public function people(): array
    {
        $out = array_column($this->db->query("SELECT id, name FROM {$this->t('client')} WHERE type = 0 AND status = 1 AND name <> ''"), 'name', 'id');
        uasort($out, function ($a, $b) { return mb_strlen($b, 'UTF-8') - mb_strlen($a, 'UTF-8'); });
        return array_map('strval', $out);
    }

    /** Ids of the active employees mentioned as "@Full Name" in the text (case-insensitive). @return int[] */
    public function mentionedIn(string $body): array
    {
        $found = [];
        $text = mb_strtolower($body, 'UTF-8');
        foreach ($this->people() as $id => $name) {
            $needle = '@' . mb_strtolower($name, 'UTF-8');
            $pos = mb_strpos($text, $needle, 0, 'UTF-8');
            if ($pos !== false) {
                $found[] = (int)$id;
                // the longer name is taken — blank it out so a shorter name inside it is not matched again
                $text = mb_substr($text, 0, $pos, 'UTF-8') . str_repeat(' ', mb_strlen($needle, 'UTF-8')) . mb_substr($text, $pos + mb_strlen($needle, 'UTF-8'), null, 'UTF-8');
            }
        }
        return $found;
    }

    private function saveMentions(int $commentId, string $body): void
    {
        $this->db->execute("DELETE FROM {$this->t('comment_mention')} WHERE comment_id = ?", [$commentId]);
        foreach ($this->mentionedIn($body) as $cid) {
            $this->db->execute("INSERT IGNORE INTO {$this->t('comment_mention')} (comment_id, client_id) VALUES (?, ?)", [$commentId, $cid]);
        }
    }

    public static function clean(string $body): string
    {
        return mb_substr(trim(str_replace("\r\n", "\n", $body)), 0, self::MAX, 'UTF-8');
    }

    /**
     * Comment text → HTML: escaped, line breaks, "@Name" of the mentioned people highlighted, references
     * ("invoice:15", "order:0:2481") turned into links when the reader may see the record (otherwise left as text).
     * @param array $mentions client id => name
     * @param callable $link fn(string $ref): ?array — Refs::resolve of the reader
     */
    public static function render(string $body, array $mentions, callable $link, callable $url): string
    {
        $h = htmlspecialchars($body, ENT_QUOTES, 'UTF-8');
        foreach ($mentions as $name) {
            $n = htmlspecialchars('@' . $name, ENT_QUOTES, 'UTF-8');
            $h = preg_replace('/' . preg_quote($n, '/') . '/iu', '<b class="mention">$0</b>', $h);
        }
        $types = implode('|', array_map('preg_quote', array_keys(Refs::types())));
        if ($types !== '') {
            $h = preg_replace_callback('/(?<![\w:\/])((?:' . $types . ')(?::\d{1,10})+)(?![\w:])/', function ($m) use ($link, $url) {
                $r = $link($m[1]);
                return $r ? '<a href="' . htmlspecialchars($url($r['url']), ENT_QUOTES, 'UTF-8') . '" title="' . htmlspecialchars($r['label'], ENT_QUOTES, 'UTF-8')
                    . '">' . htmlspecialchars($r['title'], ENT_QUOTES, 'UTF-8') . '</a>' : $m[0];
            }, $h);
        }
        return nl2br($h, false);
    }
}
