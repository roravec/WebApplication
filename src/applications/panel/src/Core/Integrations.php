<?php
namespace Panel\Core;

use Panel\Secret;
use WebApplication\Database\Database;

/**
 * Integrations (docs/panel-architektura.md chapter 4): one place for the settings of external services — secret
 * values encrypted (Panel\Secret, key from config jwtSecret), connection status + last check, shown in Nastavenia →
 * Systém → Integrácie. Services with their own screens (PrestaShop connections, Fio tokens at bank accounts, e-mail in
 * config.php) are shown there with their status and a link.
 */
class Integrations
{
    /** name => [label, fields: key => [label, secret?, hint]] — services configured on the Integrácie page */
    const TYPES = [
        'nextcloud' => ['Nextcloud (súbory záznamov)', [
            'url' => ['Adresa Nextcloudu', false, 'napr. https://home.ermagroup.net'],
            'user' => ['Používateľ panela v Nextcloude', false, 'technický účet, napr. erma-panel'],
            'password' => ['Heslo aplikácie', true, 'Nextcloud → Nastavenia → Zabezpečenie → „Vytvoriť nové heslo aplikácie“ (nie hlavné heslo)'],
            'root' => ['Priečinok panela', false, 'v ňom panel zakladá priečinky záznamov, predvolene „Panel“'],
        ]],
    ];

    /** @var Database */ private $db;
    /** @var Secret|null */ private $secret;

    public function __construct(Database $db, ?Secret $secret)
    {
        $this->db = $db;
        $this->secret = $secret;
    }

    private function t(): string
    {
        return $this->db->table('integration');
    }

    public function row(string $name): ?array
    {
        $r = $this->db->fetchOne("SELECT * FROM {$this->t()} WHERE name = ?", [$name]);
        return $r === false ? null : $r;
    }

    /** Decrypted settings of an enabled integration; null = not set up / disabled / secrets unreadable. */
    public function config(string $name): ?array
    {
        $r = $this->row($name);
        if ($r === null || !(int)$r['enabled']) {
            return null;
        }
        $cfg = json_decode((string)$r['config'], true) ?: [];
        foreach (self::TYPES[$name][1] ?? [] as $k => $f) {
            if ($f[1] && isset($cfg[$k]) && $cfg[$k] !== '') {
                $plain = $this->secret ? $this->secret->decrypt((string)$cfg[$k]) : null;
                if ($plain === null) {
                    return null;   // jwtSecret changed — enter the secret again
                }
                $cfg[$k] = $plain;
            }
        }
        return $cfg;
    }

    /** Settings for the form: secrets never leave the server (only "stored" yes / no). */
    public function formValues(string $name): array
    {
        $r = $this->row($name);
        $cfg = $r ? (json_decode((string)$r['config'], true) ?: []) : [];
        $out = ['enabled' => $r ? (bool)$r['enabled'] : true];
        foreach (self::TYPES[$name][1] as $k => $f) {
            $out[$k] = $f[1] ? '' : (string)($cfg[$k] ?? '');
            $out[$k . '_stored'] = $f[1] && !empty($cfg[$k]);
        }
        return $out;
    }

    /** @param array $values plain values; an empty secret = keep the stored one */
    public function save(string $name, array $values, bool $enabled): void
    {
        if (!isset(self::TYPES[$name])) {
            throw new \InvalidArgumentException('Neznáma integrácia.');
        }
        $old = ($r = $this->row($name)) ? (json_decode((string)$r['config'], true) ?: []) : [];
        $cfg = [];
        foreach (self::TYPES[$name][1] as $k => $f) {
            $v = trim((string)($values[$k] ?? ''));
            if ($f[1]) {
                if ($v === '') {
                    $cfg[$k] = $old[$k] ?? '';
                } else {
                    if ($this->secret === null) {
                        throw new \RuntimeException('Chýba jwtSecret v config.php — heslo sa nedá bezpečne uložiť.');
                    }
                    $cfg[$k] = $this->secret->encrypt($v);
                }
            } else {
                $cfg[$k] = $v;
            }
        }
        $this->db->execute("INSERT INTO {$this->t()} (name, config, enabled, updated_at) VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE config = VALUES(config), enabled = VALUES(enabled), updated_at = VALUES(updated_at), status = '', status_message = ''",
            [$name, json_encode($cfg), $enabled ? 1 : 0, date('Y-m-d H:i:s')]);
    }

    public function setStatus(string $name, bool $ok, string $message): void
    {
        $this->db->execute("UPDATE {$this->t()} SET status = ?, status_message = ?, checked_at = ? WHERE name = ?",
            [$ok ? 'ok' : 'error', mb_substr($message, 0, 500, 'UTF-8'), date('Y-m-d H:i:s'), $name]);
    }
}
