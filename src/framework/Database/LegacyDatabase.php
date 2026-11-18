<?php
namespace WebApplication\Database;

use PDO;
use PDOException;
use WebApplication\Logger;

/**
 * Compatibility layer with the old core/Database.php (mysqli) API, for porting existing apps:
 * Query(), EscapeString(), GetNumRows(), GetLastInsertID(), result->fetch_assoc(), result->num_rows.
 *
 * Shares the PDO connection with the new Database. New code should use Database with parameters;
 * string-built SQL + EscapeString() is kept only so old apps keep working while they are migrated.
 */
class LegacyDatabase
{
    /** @var Database */
    private $db;
    /** @var Logger|null */
    private $logger;
    /** @var int */
    private $lastInsertId = 0;
    /** @var string */
    public $error = '';

    public function __construct(Database $db, ?Logger $logger = null)
    {
        $this->db = $db;
        $this->logger = $logger;
    }

    public function Connect() { return 1; }
    public function Close() { /* shared connection stays open */ }

    /** @return LegacyResult|bool result object for SELECT-like statements, true for others, false on error */
    public function Query($query, $echoQuery = 0)
    {
        if ($echoQuery) {
            echo '<br>Query: ' . htmlspecialchars($query, ENT_QUOTES, 'UTF-8') . '<br>';
        }
        $this->error = '';
        $pdo = $this->db->pdo();
        // mysqli returned every value as a string; PDO on PHP 8.1+ returns ints/floats → keep old behaviour
        $pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);
        try {
            $stmt = $pdo->query($query);
            if ($stmt->columnCount() > 0) {
                return new LegacyResult($stmt->fetchAll(PDO::FETCH_ASSOC));
            }
            $id = (int)$this->db->pdo()->lastInsertId();
            if ($id > 0) {
                $this->lastInsertId = $id;
            }
            return true;
        } catch (PDOException $e) {
            $this->error = $e->getMessage();
            if ($this->logger) {
                $this->logger->error('Legacy query failed: ' . $e->getMessage(), ['sql' => substr($query, 0, 500)]);
            }
            return false;
        } finally {
            $pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, false);
        }
    }

    public function real_escape_string($inString)
    {
        $quoted = $this->db->pdo()->quote((string)$inString);
        return substr($quoted, 1, -1);   // PDO::quote adds surrounding quotes
    }

    public function EscapeString($inString)
    {
        return $this->real_escape_string($inString);
    }

    public function GetNumRows($queryResult)
    {
        return $queryResult instanceof LegacyResult ? $queryResult->num_rows : 0;
    }

    public function GetConnection()
    {
        return $this->db->pdo();
    }

    public function GetLastInsertID()
    {
        return $this->lastInsertId;
    }

    /** The new-style database (same connection). */
    public function modern(): Database
    {
        return $this->db;
    }
}
