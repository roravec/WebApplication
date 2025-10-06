<?php
require_once "./interfaces/IDatabase.php";

class Database implements IDatabase
{
    private $pdo;
    private $prefix = '';

    public function __construct(
        string $host,
        string $dbname,
        string $user,
        string $pass,
        int $port = 3306,
        string $charset = "utf8mb4"
    ) 
    {
        $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=$charset";
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ];
        $this->pdo = new PDO($dsn, $user, $pass, $options);
    }

    /**
     * Returns defined database table prefix for current application.
     * @return string
     */
    public function getPrefix(): string
    {
        return $this->prefix;
    }

    /**
     * Sets prefix for table names used in queries.
     * @param string $prefix
     */
    public function setPrefix(string $prefix=''): void
    {
        $this->prefix = $prefix;
    }

    /**
     * Executes a SELECT query and returns all rows.
     * @param string $sql
     * @param array<string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public function query(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Executes a SELECT query and returns a single row.
     * @param string $sql
     * @param array<string, mixed> $params
     * @return array<string, mixed>|false
     */
    public function fetchOne(string $sql, array $params = [])
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch();
    }

    /**
     * Executes an INSERT, UPDATE, or DELETE query.
     * @param string $sql
     * @param array<string, mixed> $params
     * @return bool
     */
    public function execute(string $sql, array $params = []): bool
    {
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute($params);
    }

    /**
     * Returns the last inserted ID.
     * @return int
     */
    public function lastInsertId(): int
    {
        return intval($this->pdo->lastInsertId());
    }

    /**
     * Closes the database connection.
     * @return void
     */
    public function close(): void
    {
        $this->pdo = null;
    }

    /**
     * Returns the number of rows from a SELECT query.
     * @param string $sql
     * @param array<string, mixed> $params
     * @return int
     */
    public function getNumRows(string $sql, array $params = []): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount(); // Works reliably for SELECT with buffered queries
    }
}
?>