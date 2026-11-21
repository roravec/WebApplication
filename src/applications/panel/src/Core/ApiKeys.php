<?php
namespace Panel\Core;

use WebApplication\Database\Database;
use WebApplication\Entity\Client;

/**
 * API keys (docs/panel-architektura.md chapters 4 + 5): a key belongs to a person and acts as him, limited to the
 * permissions chosen for it — and at every call also to what the person has at that moment (a person who loses a
 * permission, or is deactivated, takes it from his keys too). The key is "pk_<prefix>_<secret>"; only the SHA-256 of
 * the secret is stored, the key is shown once. Revocable; every call is logged; at most RATE calls per minute.
 */
class ApiKeys
{
    const RATE = 120;
    const EXPIRY_DAYS = [30, 90, 365, 0];   // 0 = without expiry

    /** @var Database */
    private $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    private function t(string $name = 'api_key'): string
    {
        return $this->db->table($name);
    }

    /**
     * @param string[] $permissions must be a subset of the author's permissions (checked by the caller as well)
     * @return array [key row, the full key — show it once]
     */
    public function create(Client $owner, string $name, array $permissions, int $days): array
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name, 'UTF-8') > 100) {
            throw new \InvalidArgumentException('Zadajte názov kľúča — čo ho bude používať (najviac 100 znakov).');
        }
        $permissions = Permissions::clean($permissions);
        $prefix = bin2hex(random_bytes(4));
        $secret = rtrim(strtr(base64_encode(random_bytes(30)), '+/', '-_'), '=');
        $this->db->execute("INSERT INTO {$this->t()} (client_id, name, prefix, hash, permissions, created_at, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?)",
            [$owner->getId(), $name, $prefix, hash('sha256', $secret), json_encode($permissions), date('Y-m-d H:i:s'),
             $days > 0 ? date('Y-m-d', strtotime('+' . $days . ' days')) : null]);
        return [$this->find($this->db->lastInsertId()), 'pk_' . $prefix . '_' . $secret];
    }

    public function find(int $id): ?array
    {
        $r = $this->db->fetchOne("SELECT * FROM {$this->t()} WHERE id = ?", [$id]);
        return $r === false ? null : self::decode($r);
    }

    private static function decode(array $r): array
    {
        $r['permissions'] = json_decode((string)$r['permissions'], true) ?: [];
        return $r;
    }

    public function forOwner(int $clientId): array
    {
        return array_map([self::class, 'decode'], $this->db->query("SELECT * FROM {$this->t()} WHERE client_id = ? ORDER BY revoked_at IS NULL DESC, id DESC", [$clientId]));
    }

    /** All keys with the owner's name (Nastavenia → Systém → API kľúče). */
    public function all(): array
    {
        return array_map([self::class, 'decode'], $this->db->query("SELECT k.*, c.name AS owner_name FROM {$this->t()} k
            LEFT JOIN {$this->t('client')} c ON c.id = k.client_id ORDER BY k.revoked_at IS NULL DESC, k.id DESC"));
    }

    public function revoke(int $id, string $by): void
    {
        $this->db->execute("UPDATE {$this->t()} SET revoked_at = ?, revoked_by = ? WHERE id = ? AND revoked_at IS NULL", [date('Y-m-d H:i:s'), $by, $id]);
    }

    /**
     * The key of a request, or a reason why not. @return array [key row|null, owner Client|null, error message]
     */
    public function authenticate(string $token, string $ip): array
    {
        if (!preg_match('/^pk_([0-9a-f]{8})_([A-Za-z0-9_-]{20,80})$/', $token, $m)) {
            return [null, null, 'Chýba alebo je neplatný API kľúč (hlavička Authorization: Bearer pk_…).'];
        }
        $r = $this->db->fetchOne("SELECT * FROM {$this->t()} WHERE prefix = ?", [$m[1]]);
        if ($r === false || !hash_equals((string)$r['hash'], hash('sha256', $m[2]))) {
            return [null, null, 'Neplatný API kľúč.'];
        }
        if ($r['revoked_at'] !== null) {
            return [null, null, 'API kľúč bol zrušený.'];
        }
        if ($r['expires_at'] !== null && $r['expires_at'] < date('Y-m-d')) {
            return [null, null, 'Platnosť API kľúča skončila ' . $r['expires_at'] . '.'];
        }
        $owner = Client::readById($this->db, (int)$r['client_id']);
        if ($owner === null || !$owner->isActive() || (int)$owner->type !== 0) {
            return [null, null, 'Autor API kľúča už nemá aktívny účet.'];
        }
        $this->db->execute("UPDATE {$this->t()} SET last_used_at = ?, last_ip = ? WHERE id = ?", [date('Y-m-d H:i:s'), mb_substr($ip, 0, 45), $r['id']]);
        return [self::decode($r), $owner, ''];
    }

    /** More than RATE calls of the key in the last minute? */
    public function overLimit(int $keyId): bool
    {
        return (int)$this->db->fetchValue("SELECT COUNT(*) FROM {$this->t('api_log')} WHERE key_id = ? AND created_at >= ?",
            [$keyId, date('Y-m-d H:i:s', time() - 60)]) >= self::RATE;
    }

    public function log(int $keyId, string $method, string $path, int $status, string $ip): void
    {
        $this->db->execute("INSERT INTO {$this->t('api_log')} (key_id, method, path, status, ip, created_at) VALUES (?, ?, ?, ?, ?, ?)",
            [$keyId, mb_substr($method, 0, 8), mb_substr($path, 0, 255, 'UTF-8'), $status, mb_substr($ip, 0, 45), date('Y-m-d H:i:s')]);
    }

    /** Last calls of a key. */
    public function calls(int $keyId, int $limit = 20): array
    {
        return $this->db->query("SELECT * FROM {$this->t('api_log')} WHERE key_id = ? ORDER BY id DESC LIMIT " . max(1, min(200, $limit)), [$keyId]);
    }
}
