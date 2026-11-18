<?php
namespace WebApplication\Entity;

use WebApplication\Auth\Jwt;
use WebApplication\Database\Database;

/**
 * A user (type 0) or a device / service (type 1) that can log in. Table <prefix>client:
 * id, identifier, secret_hash, name, rights, status, type, last_seen, created_at
 */
class Client extends BaseEntity implements ICrud
{
    public static $ACCESS_TOKEN_VALID_FOR = 900;            // 15 minutes
    public static $REFRESH_TOKEN_VALID_FOR = 86400 * 30;    // 30 days
    protected static $TABLE_NAME = 'client';

    public $id = 0;
    public $identifier = '';
    /** @var string new password/secret in plain text, hashed on create()/update(); never read from DB */
    public $secret = '';
    /** @deprecated old code put the plain secret here; still accepted by create()/update() */
    public $secret_hash = '';
    public $name = '';
    public $rights = 0;       // larger number = stronger rights
    public $status = 0;       // 0 inactive, 1 active
    public $type = 0;         // 0 web user, 1 service/device
    public $last_seen = null;
    public $created_at = null;
    /** @var bool */
    private $loginVerified = false;

    public static function hashSecret(string $secret): string
    {
        return password_hash($secret, PASSWORD_DEFAULT);
    }

    public function create(): bool
    {
        $now = date('Y-m-d H:i:s');
        $this->created_at = $now;
        $this->last_seen = $now;
        $ok = $this->database->execute(
            'INSERT INTO ' . static::tableOf($this->database)
            . ' (identifier, secret_hash, name, rights, status, type, last_seen, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$this->identifier, self::hashSecret($this->newSecret()), $this->name, (int)$this->rights,
             (int)$this->status, (int)$this->type, $this->last_seen, $this->created_at]
        );
        if ($ok) {
            $this->id = $this->database->lastInsertId();
        }
        $this->secret = $this->secret_hash = '';
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

    /** Saves all fields; the password only changes when $secret (or legacy $secret_hash) is set. */
    public function update(): bool
    {
        $sql = 'UPDATE ' . static::tableOf($this->database) . ' SET identifier = ?, name = ?, rights = ?, status = ?, type = ?, last_seen = ?';
        $params = [$this->identifier, $this->name, (int)$this->rights, (int)$this->status, (int)$this->type, $this->last_seen];
        if ($this->newSecret() !== '') {
            $sql .= ', secret_hash = ?';
            $params[] = self::hashSecret($this->newSecret());
        }
        $sql .= ' WHERE id = ? LIMIT 1';
        $params[] = (int)$this->id;
        $ok = $this->database->execute($sql, $params);
        $this->secret = $this->secret_hash = '';
        return $ok;
    }

    public function delete(int $id = 0): bool
    {
        if ($id !== 0) {
            $this->id = $id;
        }
        return $this->database->execute('DELETE FROM ' . static::tableOf($this->database) . ' WHERE id = ? LIMIT 1', [(int)$this->id]);
    }

    /** Updates last_seen to now. */
    public function touch(): void
    {
        $this->last_seen = date('Y-m-d H:i:s');
        $this->database->execute('UPDATE ' . static::tableOf($this->database) . ' SET last_seen = ? WHERE id = ? LIMIT 1',
            [$this->last_seen, (int)$this->id]);
    }

    public function exists(): bool { return $this->identifier !== '' && $this->id > 0; }

    /** Checks identifier + secret and loads this object on success. */
    public function login(string $identifier, string $secret): bool
    {
        $client = self::readByIdentifierAndSecret($this->database, $identifier, $secret);
        if ($client === null) {
            return false;
        }
        $this->fillFrom($client);
        $this->loginVerified = true;
        return true;
    }

    public function logout(): void
    {
        $this->id = 0;
        $this->identifier = $this->name = $this->secret = $this->secret_hash = '';
        $this->rights = $this->status = $this->type = 0;
        $this->last_seen = $this->created_at = null;
        $this->loginVerified = false;
    }

    public function setLoginVerified(bool $value): void { $this->loginVerified = $value; }
    public function getLoginVerified(): bool { return $this->loginVerified; }
    public function getName(): string { return (string)$this->name; }
    public function getRights(): int { return (int)$this->rights; }
    public function getId(): int { return (int)$this->id; }
    public function isActive(): bool { return (int)$this->status === 1; }
    public function getType(): int { return (int)$this->type; }
    public function getIdentifier(): string { return (string)$this->identifier; }

    /**
     * @param string $where optional SQL after the table name, e.g. 'WHERE type = ? ORDER BY name' — put values in $params
     * @return Client[]
     */
    public static function readAll(Database $database, string $where = '', array $params = []): array
    {
        $rows = $database->query('SELECT * FROM ' . static::tableOf($database) . ' ' . $where, $params);
        $out = [];
        foreach ($rows as $row) {
            $c = new static($database);
            $c->fill($row);
            $out[] = $c;
        }
        return $out;
    }

    public static function readById(Database $database, int $id): ?Client
    {
        return self::readOne($database, 'id = ?', [$id]);
    }

    public static function readByIdentifier(Database $database, string $identifier): ?Client
    {
        return self::readOne($database, 'LOWER(identifier) = LOWER(?)', [$identifier]);
    }

    /** Client with this identifier whose stored hash matches $secret (rehashes old hashes), or null. */
    public static function readByIdentifierAndSecret(Database $database, string $identifier, string $secret): ?Client
    {
        $row = $database->fetchOne('SELECT * FROM ' . static::tableOf($database) . ' WHERE LOWER(identifier) = LOWER(?) LIMIT 1', [$identifier]);
        if ($row === false || !password_verify($secret, (string)$row['secret_hash'])) {
            return null;
        }
        if (password_needs_rehash($row['secret_hash'], PASSWORD_DEFAULT)) {
            $database->execute('UPDATE ' . static::tableOf($database) . ' SET secret_hash = ? WHERE id = ?', [self::hashSecret($secret), $row['id']]);
        }
        $c = new static($database);
        $c->fill($row);
        $c->loginVerified = true;
        return $c;
    }

    public function generateAccessToken(string $secret, string $appId): string
    {
        return Jwt::encode([
            'sub' => $this->getId(),
            'identifier' => $this->identifier,
            'type' => $this->getType(),
            'role' => $this->getRights(),
            'appid' => $appId,
            'exp' => time() + self::$ACCESS_TOKEN_VALID_FOR,
        ], $secret);
    }

    public static function fromToken(Database $db, string $token, string $secret): ?Client
    {
        $payload = Jwt::decode($token, $secret);
        if ($payload === null || !isset($payload['sub'])) {
            return null;
        }
        $client = self::readById($db, (int)$payload['sub']);
        if ($client !== null) {
            $client->loginVerified = true;
        }
        return $client;
    }

    public function generateRefreshToken(): string
    {
        $token = new Token($this->database);
        $token->userid = $this->getId();
        $token->token = bin2hex(random_bytes(32));
        $token->issued_at = date('Y-m-d H:i:s');
        $token->expires_at = date('Y-m-d H:i:s', time() + self::$REFRESH_TOKEN_VALID_FOR);
        $token->revoked = 0;
        $token->create();
        return $token->token;
    }

    private static function readOne(Database $database, string $cond, array $params): ?Client
    {
        $row = $database->fetchOne('SELECT * FROM ' . static::tableOf($database) . ' WHERE ' . $cond . ' LIMIT 1', $params);
        if ($row === false) {
            return null;
        }
        $c = new static($database);
        $c->fill($row);
        return $c;
    }

    private function fill(array $row): void
    {
        $this->id = (int)$row['id'];
        $this->identifier = (string)$row['identifier'];
        $this->name = (string)$row['name'];
        $this->rights = (int)$row['rights'];
        $this->status = (int)$row['status'];
        $this->type = (int)$row['type'];
        $this->last_seen = $row['last_seen'] ?? null;
        $this->created_at = $row['created_at'] ?? null;
    }

    private function fillFrom(Client $c): void
    {
        foreach (['id', 'identifier', 'name', 'rights', 'status', 'type', 'last_seen', 'created_at'] as $f) {
            $this->$f = $c->$f;
        }
    }

    private function newSecret(): string
    {
        return $this->secret !== '' ? $this->secret : (string)$this->secret_hash;
    }
}
