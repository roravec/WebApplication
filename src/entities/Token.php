<?php
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../entities/BaseEntity.php';
require_once __DIR__ . '/../interfaces/ICrud.php';

class Token extends BaseEntity implements ICrud
{
    /**
     * Summary of TABLE_NAME
     * @var string
     */
	protected static $TABLE_NAME = "token";

    public function create(): bool
    {
        $query = '
        INSERT INTO 
        '.$this->getTableName().'
        (
            userid, token, expires_at, revoked, issued_at
        )
        VALUES (
            ?, ?, ?, ?, ?
        );
        ';
        $params = [
            $this->userid,
            $this->token,
            $this->expires_at,
            $this->revoked,
            $this->issued_at
        ];
        $result = $this->database->execute($query, $params);
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
            $this->userid = $result[0]['userid'];
            $this->token = $result[0]['token'];
            $this->expires_at = $result[0]['expires_at'];
            $this->revoked = $result[0]['revoked'];
            $this->issued_at = $result[0]['issued_at'];
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
            userid = ?,
            token = ?,
            expires_at = ?,
            revoked = ?,
            issued_at = ?
        WHERE id = ?;
        ';
        $params = [
            $this->userid,
            $this->token,
            $this->expires_at,
            $this->revoked,
            $this->issued_at,
            $this->id
        ];
        $result = $this->database->execute($query, $params);
        return $result !== false;
    }

    public function delete($id = 0): bool
    {
        if ($id > 0)
        {
            $this->id = $id;
        }
        if ($this->id <= 0)
        {
            return false;
        }
        $query = '
        DELETE FROM '.$this->getTableName().'
        WHERE id = ?;
        ';
        $params = [$this->id];
        $result = $this->database->execute($query, $params);
        return $result !== false;
    }

    /**
     * Validates a refresh token and returns the corresponding Token object if valid.
     * @param Database $db
     * @param string $tokenValue
     * @return Token|null
     */
    public static function validateRefreshToken(Database $db, string $tokenValue): ?Token
    {
        $query = '
        SELECT * FROM '.$db->getPrefix().self::$TABLE_NAME.'
        WHERE token = ? AND revoked = 0 AND expires_at > ?
        LIMIT 1;
        ';
        $params = [$tokenValue, time()];
        $result = $db->query($query, $params);
        if ($result && count($result) > 0) {
            $row = $result[0];
            $token = new Token($db);
            $token->id = $row['id'];
            $token->userid = $row['userid'];
            $token->token = $row['token'];
            $token->expires_at = $row['expires_at'];
            $token->revoked = $row['revoked'];
            $token->issued_at = $row['issued_at'];
            return $token;
        }
        return null;
    }

    /**
     * Revokes a token by its value. Optionally, can restrict revocation to a specific user.
     * @param Database $db
     * @param string $tokenValue
     * @param int $userId
     * @return bool
     */
    public static function revokeByValue(Database $db, string $tokenValue, int $userId = 0): bool
    {
        $query = '
        UPDATE '.$db->getPrefix().self::$TABLE_NAME.'
        SET revoked = 1
        WHERE token = ? AND revoked = 0
        ';
        $params = [$tokenValue];

        if ($userId > 0) {
            $query .= ' AND userid = ?';
            $params[] = $userId;
        }

        $result = $db->execute($query, $params);
        return $result !== false;
    }


    public function revoke(): bool
    {
        $this->revoked = 1;
        return $this->update();
    }

	/** Database columns  *******************/
	public $id=0;
	public $userid=0;
	public $token="";
	public $expires_at="";
	public $revoked=0;
    public $issued_at="";
    /** Database columns section ends ********/
}

?>