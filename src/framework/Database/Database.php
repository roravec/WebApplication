<?php
namespace WebApplication\Database;

use PDO;

/**
 * PDO wrapper. Always use parameters for values: query('SELECT * FROM ' . $db->table('client') . ' WHERE id = ?', [$id]).
 * Table names can't be parameters — build them with table() (adds the app's prefix).
 */
class Database
{
    /** @var PDO|null */
    private $pdo;
    /** @var string */
    private $prefix = '';

    public function __construct(string $host, string $dbname, string $user, string $pass, int $port = 3306, string $charset = 'utf8mb4')
    {
        $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=$charset";
        $this->pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    /** For tests / existing connections. */
    public static function fromPdo(PDO $pdo, string $prefix = ''): self
    {
        $db = (new \ReflectionClass(self::class))->newInstanceWithoutConstructor();
        $db->pdo = $pdo;
        $db->prefix = $prefix;
        return $db;
    }

    public function getPrefix(): string { return $this->prefix; }
    public function setPrefix(string $prefix = ''): void { $this->prefix = $prefix; }

    /** Prefixed, quoted table name: table('client') → `ermaweb_client` */
    public function table(string $name): string
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
            throw new \InvalidArgumentException('Invalid table name: ' . $name);
        }
        return '`' . $this->prefix . $name . '`';
    }

    /** All rows. */
    public function query(string $sql, array $params = []): array
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** First row or false. */
    public function fetchOne(string $sql, array $params = [])
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        $stmt->closeCursor();
        return $row;
    }

    /** First column of the first row, or null. */
    public function fetchValue(string $sql, array $params = [])
    {
        $row = $this->fetchOne($sql, $params);
        return $row === false ? null : reset($row);
    }

    public function execute(string $sql, array $params = []): bool
    {
        return $this->pdo()->prepare($sql)->execute($params);
    }

    /** Number of rows changed by an UPDATE/DELETE/INSERT. */
    public function affected(string $sql, array $params = []): int
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    public function lastInsertId(): int
    {
        return (int)$this->pdo()->lastInsertId();
    }

    public function getNumRows(string $sql, array $params = []): int
    {
        return count($this->query($sql, $params));
    }

    /**
     * Run $fn inside a transaction; commits on success, rolls back on exception.
     *   $db->transaction(function (Database $db) { ... });
     */
    public function transaction(callable $fn)
    {
        $pdo = $this->pdo();
        $pdo->beginTransaction();
        try {
            $result = $fn($this);
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            throw new \RuntimeException('Database connection is closed');
        }
        return $this->pdo;
    }

    public function close(): void
    {
        $this->pdo = null;
    }
}
