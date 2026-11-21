<?php
namespace Panel\Tasks;

use Panel\Secret;
use WebApplication\Database\Database;

/**
 * Commits at tasks (docs/panel-architektura.md 6.11, owner 2026-11-21): repositories send their pushes (GitHub /
 * GitLab webhook, or the API from a post-receive hook); the task numbers at the START of the commit message — in the
 * configurable format (setting git.task_format, {TASK} = LED-123, default "#{TASK}") — link the commit to the tasks.
 * A commit without a number is stored without a link. The panel keeps only the commit info (hash, message, author,
 * branch, link), never the code.
 */
class Git
{
    const PROVIDERS = ['github' => 'GitHub', 'gitlab' => 'GitLab', 'api' => 'Iný (API — vlastný server, post-receive)'];
    const DEFAULT_FORMAT = '#{TASK}';
    const TASK_REGEX = '[A-Z][A-Z0-9]{0,9}-\d{1,9}';

    /** @var Database */ private $db;
    /** @var Secret|null */ private $secret;

    public function __construct(Database $db, ?Secret $secret = null)
    {
        $this->db = $db;
        $this->secret = $secret;
    }

    private function t(string $name): string
    {
        return $this->db->table($name);
    }

    // ------------------------------------------------------------------ the format

    public function format(): string
    {
        $f = (string)$this->db->fetchValue("SELECT value FROM {$this->t('setting')} WHERE name = 'git.task_format'");
        return self::formatError($f) === null ? $f : self::DEFAULT_FORMAT;
    }

    public function setFormat(string $format): void
    {
        $this->db->execute("INSERT INTO {$this->t('setting')} (name, value, updated_at) VALUES ('git.task_format', ?, ?)
            ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = VALUES(updated_at)", [$format, date('Y-m-d H:i:s')]);
    }

    public static function formatError(string $format): ?string
    {
        if (substr_count($format, '{TASK}') !== 1) {
            return 'Formát musí obsahovať presne raz {TASK} (miesto pre číslo úlohy, napr. LED-123).';
        }
        $rest = str_replace('{TASK}', '', $format);
        if (strlen($format) > 20 || !preg_match('/^[#\[\]\(\):\/\-_.!@*]{0,10}$/', $rest)) {
            return 'Okolo {TASK} môžu byť len znaky # [ ] ( ) : / - _ . ! @ * (najviac 10), napr. #{TASK}, [{TASK}], {TASK}:';
        }
        return null;
    }

    /**
     * Task numbers at the start of a commit message in the format: "#LED-12 #LED-14 oprava" → [LED-12, LED-14];
     * several numbers may follow each other (separated by spaces / commas). A number later in the text does not count.
     * @return string[] upper-case codes
     */
    public static function taskCodes(string $message, string $format): array
    {
        list($before, $after) = explode('{TASK}', $format, 2);
        $one = preg_quote($before, '/') . '(' . self::TASK_REGEX . ')' . preg_quote($after, '/');
        $line = ltrim(strtok(str_replace("\r", '', $message), "\n") ?: '');
        if (!preg_match('/^(?:' . $one . '[\s,;]*)+/i', $line, $m)) {
            return [];
        }
        preg_match_all('/' . $one . '/i', $m[0], $all);
        return array_values(array_unique(array_map('strtoupper', $all[1])));
    }

    // ------------------------------------------------------------------ repositories

    public function repos(): array
    {
        return $this->db->query("SELECT r.*, (SELECT COUNT(*) FROM {$this->t('git_commit')} c WHERE c.repo_id = r.id) AS commits
            FROM {$this->t('git_repo')} r ORDER BY r.active DESC, r.name");
    }

    public function repo(int $id): ?array
    {
        $r = $this->db->fetchOne("SELECT * FROM {$this->t('git_repo')} WHERE id = ?", [$id]);
        return $r === false ? null : $r;
    }

    /** @return array [id, the webhook secret in plain text — shown once to put into GitHub / GitLab] */
    public function addRepo(string $provider, string $name, string $webUrl): array
    {
        if (!isset(self::PROVIDERS[$provider])) {
            throw new \InvalidArgumentException('Vyberte, kde je repozitár.');
        }
        $name = trim($name);
        if (!preg_match('#^[A-Za-z0-9_.\-]+(/[A-Za-z0-9_.\-]+)+$#', $name) || strlen($name) > 190) {
            throw new \InvalidArgumentException('Názov repozitára ako na GitHube / GitLabe: vlastník/repozitár (napr. erma/ledboard-fw).');
        }
        if ($webUrl !== '' && !preg_match('#^https?://#', $webUrl)) {
            throw new \InvalidArgumentException('Adresa repozitára musí začínať https://');
        }
        if ($this->secret === null) {
            throw new \RuntimeException('Chýba jwtSecret v config.php — tajný kľúč sa nedá bezpečne uložiť.');
        }
        $plain = bin2hex(random_bytes(20));
        $this->db->execute("INSERT INTO {$this->t('git_repo')} (provider, name, web_url, secret, created_at) VALUES (?, ?, ?, ?, ?)",
            [$provider, $name, $webUrl !== '' ? $webUrl : ($provider === 'github' ? 'https://github.com/' . $name : ''), $this->secret->encrypt($plain), date('Y-m-d H:i:s')]);
        return [(int)$this->db->lastInsertId(), $plain];
    }

    public function setActive(int $id, bool $active): void
    {
        $this->db->execute("UPDATE {$this->t('git_repo')} SET active = ? WHERE id = ?", [$active ? 1 : 0, $id]);
    }

    private function secretOf(array $repo): ?string
    {
        return $this->secret ? $this->secret->decrypt((string)$repo['secret']) : null;
    }

    /** GitHub: X-Hub-Signature-256 = sha256 HMAC of the body with the secret. */
    public function verifyGithub(array $repo, string $body, string $signature): bool
    {
        $s = $this->secretOf($repo);
        return $s !== null && $signature !== '' && hash_equals('sha256=' . hash_hmac('sha256', $body, $s), $signature);
    }

    /** GitLab: X-Gitlab-Token = the secret. */
    public function verifyGitlab(array $repo, string $token): bool
    {
        $s = $this->secretOf($repo);
        return $s !== null && $token !== '' && hash_equals($s, $token);
    }

    /** GitHub / GitLab push payload → [branch, commits: list of sha, message, author_name, author_email, timestamp, url]. */
    public static function parsePush(array $payload): array
    {
        $branch = preg_replace('#^refs/heads/#', '', (string)($payload['ref'] ?? ''));
        $out = [];
        foreach ((array)($payload['commits'] ?? []) as $c) {
            if (!is_array($c) || empty($c['id'])) {
                continue;
            }
            $out[] = ['sha' => (string)$c['id'], 'message' => (string)($c['message'] ?? ''), 'author_name' => (string)($c['author']['name'] ?? ''),
                'author_email' => (string)($c['author']['email'] ?? ''), 'timestamp' => (string)($c['timestamp'] ?? ''), 'url' => (string)($c['url'] ?? '')];
        }
        return [$branch, $out];
    }

    /**
     * Stores the commits of a push and links them to the tasks by the numbers at the start of their messages.
     * @return array ['stored' => int, 'linked' => [task id => [commit rows]]] (only new commit ↔ task pairs)
     */
    public function receive(int $repoId, string $branch, array $commits): array
    {
        $format = $this->format();
        $tasks = new Tasks($this->db);
        $stored = 0;
        $linked = [];
        foreach (array_slice($commits, 0, 200) as $c) {
            $sha = strtolower(trim((string)$c['sha']));
            if (!preg_match('/^[0-9a-f]{7,64}$/', $sha)) {
                continue;
            }
            $id = $this->db->fetchValue("SELECT id FROM {$this->t('git_commit')} WHERE repo_id = ? AND sha = ?", [$repoId, $sha]);
            if (!$id) {
                $email = strtolower(trim((string)$c['author_email']));
                $client = $email !== '' ? $this->db->fetchValue("SELECT id FROM {$this->t('client')} WHERE type = 0 AND LOWER(identifier) = ?", [$email]) : null;
                $ts = strtotime((string)$c['timestamp']) ?: time();
                $this->db->execute("INSERT INTO {$this->t('git_commit')} (repo_id, sha, message, author_name, author_email, client_id, branch, url, committed_at, received_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", [$repoId, $sha, mb_substr((string)$c['message'], 0, 10000, 'UTF-8'),
                    mb_substr((string)$c['author_name'], 0, 190, 'UTF-8'), mb_substr($email, 0, 190, 'UTF-8'), $client ?: null, mb_substr($branch, 0, 190, 'UTF-8'),
                    preg_match('#^https?://#', (string)$c['url']) ? mb_substr((string)$c['url'], 0, 500, 'UTF-8') : '', date('Y-m-d H:i:s', $ts), date('Y-m-d H:i:s')]);
                $id = (int)$this->db->lastInsertId();
                $stored++;
            }
            foreach (self::taskCodes((string)$c['message'], $format) as $code) {
                $task = $tasks->findByCode($code);
                if ($task !== null && $this->db->affected("INSERT IGNORE INTO {$this->t('task_commit')} (task_id, commit_id) VALUES (?, ?)", [$task['id'], $id]) > 0) {
                    $linked[(int)$task['id']][] = $this->db->fetchOne("SELECT * FROM {$this->t('git_commit')} WHERE id = ?", [$id]);
                }
            }
        }
        $this->db->execute("UPDATE {$this->t('git_repo')} SET last_push_at = ?, last_message = ? WHERE id = ?",
            [date('Y-m-d H:i:s'), 'commitov: ' . count($commits) . ', nových: ' . $stored . ', priradených k úlohám: ' . array_sum(array_map('count', $linked)), $repoId]);
        return ['stored' => $stored, 'linked' => $linked];
    }

    /** Commits of a task, newest first, with the repository and the employee. */
    public function forTask(int $taskId): array
    {
        return $this->db->query("SELECT c.*, r.name AS repo_name, r.provider, e.name AS employee FROM {$this->t('task_commit')} tc
            JOIN {$this->t('git_commit')} c ON c.id = tc.commit_id JOIN {$this->t('git_repo')} r ON r.id = c.repo_id
            LEFT JOIN {$this->t('client')} e ON e.id = c.client_id WHERE tc.task_id = ? ORDER BY c.committed_at DESC, c.id DESC", [$taskId]);
    }
}
