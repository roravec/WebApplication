<?php
namespace WebApplication\Entity;

use WebApplication\Database\Database;

/** Refresh token of an API client. Table <prefix>token: id, userid, token, issued_at, expires_at, revoked */
class Token extends BaseEntity implements ICrud
{
    protected static $TABLE_NAME = 'token';

    public $id = 0;
    public $userid = 0;
    public $token = '';
    public $issued_at = '';
    public $expires_at = '';
    public $revoked = 0;

    public function create(): bool
    {
        $ok = $this->database->execute(
            'INSERT INTO ' . static::tableOf($this->database) . ' (userid, token, expires_at, revoked, issued_at) VALUES (?, ?, ?, ?, ?)',
            [(int)$this->userid, $this->token, $this->expires_at, (int)$this->revoked, $this->issued_at]
        );
        if ($ok) {
            $this->id = $this->database->lastInsertId();
        }
        return $ok;
    }

    public function read(int $id = 0): bool
    {
        if ($id !== 0) {
            $this->id = $id;
        }
        $row = $this->database->fetchOne('SELECT * FROM ' . static::tableOf($this->database) . ' WHERE id = ? LIMIT 1', [(int)$this->id]);
        if ($row === false) {
            return false;
        }
        $this->fill($row);
        return true;
    }

    public function update(): bool
    {
        return $this->database->execute(
            'UPDATE ' . static::tableOf($this->database) . ' SET userid = ?, token = ?, expires_at = ?, revoked = ?, issued_at = ? WHERE id = ? LIMIT 1',
            [(int)$this->userid, $this->token, $this->expires_at, (int)$this->revoked, $this->issued_at, (int)$this->id]
        );
    }

    public function delete(int $id = 0): bool
    {
        if ($id !== 0) {
            $this->id = $id;
        }
        return $this->database->execute('DELETE FROM ' . static::tableOf($this->database) . ' WHERE id = ? LIMIT 1', [(int)$this->id]);
    }

    public function revoke(): bool
    {
        $this->revoked = 1;
        return $this->update();
    }

    /** Valid (not revoked, not expired) token with this value, or null. */
    public static function validateRefreshToken(Database $db, string $tokenValue): ?Token
    {
        $row = $db->fetchOne('SELECT * FROM ' . static::tableOf($db) . ' WHERE token = ? AND revoked = 0 AND expires_at > ? LIMIT 1',
            [$tokenValue, date('Y-m-d H:i:s')]);
        if ($row === false) {
            return null;
        }
        $t = new self($db);
        $t->fill($row);
        return $t;
    }

    public static function revokeByValue(Database $db, string $tokenValue, int $userId = 0): bool
    {
        $sql = 'UPDATE ' . static::tableOf($db) . ' SET revoked = 1 WHERE token = ? AND revoked = 0';
        $params = [$tokenValue];
        if ($userId > 0) {
            $sql .= ' AND userid = ?';
            $params[] = $userId;
        }
        return $db->execute($sql, $params);
    }

    /** Housekeeping: delete expired and revoked tokens. */
    public static function purge(Database $db): int
    {
        return $db->affected('DELETE FROM ' . static::tableOf($db) . ' WHERE revoked = 1 OR expires_at < ?', [date('Y-m-d H:i:s')]);
    }

    private function fill(array $row): void
    {
        $this->id = (int)$row['id'];
        $this->userid = (int)$row['userid'];
        $this->token = (string)$row['token'];
        $this->issued_at = (string)$row['issued_at'];
        $this->expires_at = (string)$row['expires_at'];
        $this->revoked = (int)$row['revoked'];
    }
}
