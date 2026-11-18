<?php
namespace WebApplication\Entity;

use WebApplication\Database\Database;

/** Audit log row. Table <prefix>log: id, timestamp, userId, clientIp, action, targetType, targetId, status, message */
class LogEntry extends BaseEntity implements ICrud
{
    protected static $TABLE_NAME = 'log';

    public $id = 0;
    public $timestamp = '';
    public $userId = 0;
    public $clientIp = '';
    public $action = '';
    public $targetType = '';
    public $targetId = 0;
    public $status = 0;
    public $message = '';

    public function create(): bool
    {
        $this->timestamp = date('Y-m-d H:i:s');
        $ok = $this->database->execute(
            'INSERT INTO ' . static::tableOf($this->database)
            . ' (timestamp, userId, clientIp, action, targetType, targetId, status, message) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$this->timestamp, (int)$this->userId, $this->clientIp, $this->action, $this->targetType,
             (int)$this->targetId, (int)$this->status, $this->message]
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
            'UPDATE ' . static::tableOf($this->database)
            . ' SET userId = ?, clientIp = ?, action = ?, targetType = ?, targetId = ?, status = ?, message = ? WHERE id = ? LIMIT 1',
            [(int)$this->userId, $this->clientIp, $this->action, $this->targetType, (int)$this->targetId,
             (int)$this->status, $this->message, (int)$this->id]
        );
    }

    public function delete(int $id = 0): bool
    {
        if ($id !== 0) {
            $this->id = $id;
        }
        return $this->database->execute('DELETE FROM ' . static::tableOf($this->database) . ' WHERE id = ? LIMIT 1', [(int)$this->id]);
    }

    public function exists(): bool { return $this->id > 0; }

    /** @return LogEntry[] newest first */
    public static function readAll(Database $db, int $limit = 100): array
    {
        $out = [];
        foreach ($db->query('SELECT * FROM ' . static::tableOf($db) . ' ORDER BY id DESC LIMIT ' . max(1, $limit)) as $row) {
            $e = new self($db);
            $e->fill($row);
            $out[] = $e;
        }
        return $out;
    }

    private function fill(array $row): void
    {
        $this->id = (int)$row['id'];
        $this->timestamp = (string)$row['timestamp'];
        $this->userId = (int)$row['userId'];
        $this->clientIp = (string)$row['clientIp'];
        $this->action = (string)$row['action'];
        $this->targetType = (string)$row['targetType'];
        $this->targetId = (int)$row['targetId'];
        $this->status = (int)$row['status'];
        $this->message = (string)$row['message'];
    }
}
