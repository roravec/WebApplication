<?php
namespace Panel\Core;

use Panel\Mailer;
use Panel\PanelContext;
use WebApplication\Database\Database;
use WebApplication\Entity\Client;

/**
 * Notifications (docs/panel-architektura.md chapter 4): the bell in the panel (unread count refreshed by short
 * polling) + e-mail by the person's choice — immediately (sent by the scheduler within a minute or two), a daily digest
 * in the morning, or none. A person is notified only about a record he may see (checked as HIM, Core\Refs).
 */
class Notifications
{
    const MODES = ['immediate' => 'hneď (do pár minút)', 'daily' => 'raz denne — súhrn ráno', 'off' => 'neposielať (len zvonček v paneli)'];
    const DIGEST_HOUR = 7;

    /** @var Database */
    private $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    private function t(string $name = 'notification'): string
    {
        return $this->db->table($name);
    }

    public function mode(int $clientId): string
    {
        $m = $this->db->fetchValue("SELECT email_mode FROM {$this->t('notify_pref')} WHERE client_id = ?", [$clientId]);
        return isset(self::MODES[(string)$m]) ? (string)$m : 'immediate';
    }

    public function setMode(int $clientId, string $mode): void
    {
        if (!isset(self::MODES[$mode])) {
            throw new \InvalidArgumentException('Neznámy spôsob upozornení.');
        }
        $this->db->execute("INSERT INTO {$this->t('notify_pref')} (client_id, email_mode) VALUES (?, ?) ON DUPLICATE KEY UPDATE email_mode = VALUES(email_mode)",
            [$clientId, $mode]);
    }

    /**
     * Notify one person about a record. Skipped (false) when he is not an active employee or may not see the record.
     * @param PanelContext $ctx any context — the check runs as the recipient
     */
    public function notify(PanelContext $ctx, int $clientId, string $ref, string $event, string $text, string $actorName = ''): bool
    {
        $client = Client::readById($this->db, $clientId);
        if ($client === null || (int)$client->type !== 0 || !$client->isActive() || !$ctx->asUser($client)->refs()->canSee($ref)) {
            return false;
        }
        $mode = $this->mode($clientId);
        $this->db->execute("INSERT INTO {$this->t()} (client_id, ref, event, text, actor_name, created_at, email_state) VALUES (?, ?, ?, ?, ?, ?, ?)",
            [$clientId, $ref, $event, mb_substr($text, 0, 500, 'UTF-8'), $actorName, date('Y-m-d H:i:s'),
             $mode === 'immediate' ? 'pending' : ($mode === 'daily' ? 'daily' : 'none')]);
        return true;
    }

    public function unreadCount(int $clientId): int
    {
        return (int)$this->db->fetchValue("SELECT COUNT(*) FROM {$this->t()} WHERE client_id = ? AND read_at IS NULL", [$clientId]);
    }

    public function forPerson(int $clientId, int $limit = 100): array
    {
        return $this->db->query("SELECT * FROM {$this->t()} WHERE client_id = ? ORDER BY id DESC LIMIT " . max(1, min(500, $limit)), [$clientId]);
    }

    public function find(int $id): ?array
    {
        $r = $this->db->fetchOne("SELECT * FROM {$this->t()} WHERE id = ?", [$id]);
        return $r === false ? null : $r;
    }

    /** Marks one (or all, $id = null) notifications of the person as read. */
    public function markRead(int $clientId, ?int $id = null): void
    {
        $this->db->execute("UPDATE {$this->t()} SET read_at = ? WHERE client_id = ? AND read_at IS NULL" . ($id ? ' AND id = ' . (int)$id : ''),
            [date('Y-m-d H:i:s'), $clientId]);
    }

    /** Immediate e-mails (scheduler, every run): one e-mail per person with all his pending notifications. */
    public function sendPending(PanelContext $system, Mailer $mailer): string
    {
        return $this->send($system, $mailer, 'pending', 'Nové upozornenie v paneli');
    }

    /** Daily digest (scheduler, once a day from DIGEST_HOUR): unread notifications of people with the daily mode. */
    public function sendDigest(PanelContext $system, Mailer $mailer): string
    {
        return $this->send($system, $mailer, 'daily', 'Súhrn upozornení z panela');
    }

    private function send(PanelContext $system, Mailer $mailer, string $state, string $subject): string
    {
        $rows = $this->db->query("SELECT * FROM {$this->t()} WHERE email_state = ? ORDER BY client_id, id LIMIT 500", [$state]);
        if (!$rows) {
            return 'nič na odoslanie';
        }
        if (!$mailer->configured()) {
            return 'e-maily nie sú nastavené (config.php → apps.panel.mail) — upozornenia sú len v paneli';
        }
        $byPerson = [];
        foreach ($rows as $r) {
            $byPerson[(int)$r['client_id']][] = $r;
        }
        $sent = $failed = 0;
        foreach ($byPerson as $clientId => $list) {
            $ids = implode(',', array_map('intval', array_column($list, 'id')));
            $client = Client::readById($this->db, $clientId);
            $unread = array_values(array_filter($list, function ($r) { return $r['read_at'] === null; }));
            if ($client === null || !$client->isActive() || !filter_var($client->getIdentifier(), FILTER_VALIDATE_EMAIL) || !$unread) {
                $this->db->execute("UPDATE {$this->t()} SET email_state = 'none' WHERE id IN ($ids)");   // read in the panel already / no e-mail
                continue;
            }
            $lines = [];
            foreach ($unread as $r) {
                $lines[] = '• ' . $r['text'] . ($r['actor_name'] !== '' ? ' (' . $r['actor_name'] . ')' : '') . "\n  " . $system->refs()->shortUrl($r['ref']);
            }
            try {
                $mailer->send(['from' => $mailer->defaultFrom() ?: $this->fallbackFrom(), 'from_name' => 'ERMA panel', 'to' => [$client->getIdentifier()],
                    'subject' => count($unread) > 1 ? $subject . ' (' . count($unread) . ')' : $subject . ': ' . mb_substr($unread[0]['text'], 0, 80, 'UTF-8'),
                    'text' => "Dobrý deň,\n\nv paneli máte " . (count($unread) > 1 ? count($unread) . ' nové upozornenia' : 'nové upozornenie') . ":\n\n"
                        . implode("\n\n", $lines) . "\n\nOdkazy sa otvoria po prihlásení do panela. Ako chodia tieto e-maily, zmeníte v paneli:"
                        . " Môj účet → Upozornenia e-mailom.\n"]);
                $this->db->execute("UPDATE {$this->t()} SET email_state = 'sent' WHERE id IN ($ids)");
                $sent++;
            } catch (\Throwable $e) {
                $this->db->execute("UPDATE {$this->t()} SET email_state = 'failed' WHERE id IN ($ids)");
                $failed++;
            }
        }
        return "e-mailov: $sent" . ($failed ? ", zlyhalo: $failed (pošta servera)" : '');
    }

    private function fallbackFrom(): string
    {
        return (string)$this->db->fetchValue("SELECT email FROM {$this->t('company')} WHERE active = 1 AND email <> '' ORDER BY id LIMIT 1");
    }
}
