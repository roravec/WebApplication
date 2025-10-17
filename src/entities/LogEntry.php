<?php
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../entities/BaseEntity.php';
require_once __DIR__ . '/../interfaces/ICrud.php';


class LogEntry extends BaseEntity implements ICrud
{
    /**
     * Summary of TABLE_NAME
     * @var string
     */
	protected static $TABLE_NAME = "log";

    public function create(): bool
    {
        $query = '
        INSERT INTO 
        '.$this->getTableName().'
        (
            timestamp, userId, clientIp, action, targetType, targetId, status, message
        )
        VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?
        );
        ';
        $params = [
            $this->timestamp,
            $this->userId,
            $this->clientIp,
            $this->action,
            $this->targetType,
            $this->targetId,
            $this->status,
            $this->message
        ];
        $result = $this->database->query($query, $params);
        return $result !== false;
    }

    public function read($id=0): bool
    {
        if ($id > 0)
        {
            $this->id = $id;
        }
        $query = '
        SELECT * FROM '.$this->getTableName().'
        WHERE id = ?;
        ';
        $params = [$this->id];
        $result = $this->database->query($query, $params);
        if ($result && count($result) > 0)
        {
            $this->id = $result[0]['id'];
            $this->timestamp = $result[0]['timestamp'];
            $this->userId = $result[0]['userId'];
            $this->clientIp = $result[0]['clientIp'];
            $this->action = $result[0]['action'];
            $this->targetType = $result[0]['targetType'];
            $this->targetId = $result[0]['targetId'];
            $this->status = $result[0]['status'];
            $this->message = $result[0]['message'];
            return true;
        }
        return false;
    }

    public function update(): bool
    {
        if ($this->id <= 0)
        {
            return false;
        }
        $query = '
        UPDATE '.$this->getTableName().'
        SET
            timestamp = ?, userId = ?, clientIp = ?, action = ?, targetType = ?, targetId = ?, status = ?, message = ?
        WHERE
            id = ?;
        ';
        $params = [
            $this->timestamp,
            $this->userId,
            $this->clientIp,
            $this->action,
            $this->targetType,
            $this->targetId,
            $this->status,
            $this->message,
            $this->id
        ];
        $result = $this->database->query($query, $params);
        return $result !== false;
    }

    public function delete($id = 0): bool
    {
        if ($id > 0)
        {
            $this->id = $id;
        }
        $query = '
        DELETE FROM '.$this->getTableName().'
        WHERE id = ?;
        ';
        $params = [$this->id];
        $result = $this->database->query($query, $params);
        return $result !== false;
    }

	/** Database columns  *******************/
    public $id=0;
    public $timestamp="";
    public $userId=0;
    public $clientIp="";
    public $action="";
    public $targetType="";
    public $targetId=0;
    public $status=0;
    public $message="";
    /** Database columns section ends ********/
}

?>