<?php
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../entities/BaseEntity.php';
require_once __DIR__ . '/../interfaces/ICrud.php';
require_once __DIR__ . '/../entities/Token.php';

class Client extends BaseEntity implements ICrud
{
    /**
     * Summary of TABLE_NAME
     * @var string
     */
	protected static $TABLE_NAME = "client";

    /**
     * Summary of hashPassword
     * @param string $secret
     * @return string
     */
    public static function hashSecret($secret)
	{
		return password_hash($secret, PASSWORD_DEFAULT);
	}

	/** CRUD functions section */

    /**
     * Summary of create
     * @return bool
     */
	public function create(): bool
	{
        $this->generateSecretHash();
        $this->created_at = date('Y-m-d H:i:s');
        $this->last_seen = date('Y-m-d H:i:s');
        $query = '
        INSERT INTO 
        '.$this->getTableName().'
        (
            identifier, secret_hash, name, rights, status, type, last_seen, created_at
        )
        VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?
        );
        ';
        $params = [
            $this->identifier,
            $this->hashedSecret,
            $this->name,
            $this->rights,
            $this->status,
            $this->type,
            $this->last_seen,
            $this->created_at
        ];
        $result = $this->database->execute($query, $params);
        return $result !== false;
	}

    /**
     * Summary of read
     * @param mixed $id
     * @return bool
     */
	public function read($id=0): bool
	{
        $query = '
        SELECT * FROM
        '.$this->getTableName().'
        WHERE
        id = ?
        LIMIT 1;
        ';
        if ($id != 0)
        {
            $this->id = $id; // set id only if provided
        }
        $params = [intval($this->id)];
        $result = $this->database->query($query, $params);
        if ($result && count($result) > 0)
        {
            $row = $result[0];
            $this->identifier = $row['identifier'];
            //$this->secret_hash = $row['secret_hash'];
            $this->name = $row['name'];
            $this->rights = $row['rights'];
            $this->status = $row['status'];
            $this->type = $row['type'];
            $this->last_seen = $row['last_seen'];
            $this->created_at = $row['created_at'];
            return true;
        }
        return false;
	}

    /**
     * Summary of update
     * @return bool
     */
    public function update(): bool
	{
        $query = '
        UPDATE
        '.$this->getTableName().'
        SET
        identifier = ?,'; 
        $params = [$this->identifier];
        if ($this->secret_hash != '') { // update password only if it is not blank
            // secret should be in raw form this time
            $this->generateSecretHash();
            $query .= ' secret_hash = ?,';
            $params[] = $this->hashedSecret;
        }
        $query .= ' name = ?, rights = ?, status = ?, type = ?, last_seen = ?
        WHERE
        id = ?
        LIMIT 1;
        ';
        $params[] = $this->name;
        $params[] = $this->rights;
        $params[] = $this->status;
        $params[] = $this->type;
        $params[] = $this->last_seen;
        $params[] = $this->id;
        $result = $this->database->execute($query, $params);
        return $result !== false;
	}

    /**
     * Summary of delete
     * @param int $id
     * @return bool
     */
	public function delete($id=0): bool
	{
        if ($id != 0)
        {
            $this->id = $id;
        }
        $query = '
        DELETE FROM
        '.$this->getTableName().'
        WHERE
        id = ?
        LIMIT 1;
        ';
        $params = [intval($this->id)];
        $result = $this->database->execute($query, $params);
        return $result !== false;
	}
    /** CRUD functions section ends */

    /**
     * Reads user data and verifies password.
     * Use raw password.
     * Returns true if login is successful, false otherwise.
     * @param string $identifier
     * @param string $secret
     * @return bool login verified
     */
	public function login($identifier, $secret): bool
	{
        $client = Client::readByIdentifierAndSecret($this->database, $identifier, $secret);
        // get all data from client if login was successful
        if ($client !== null)
        {
            $this->id = $client->id;
            $this->identifier = $client->identifier;
            //$this->secret_hash = $client->secret_hash;
            $this->name = $client->name;
            $this->rights = $client->rights;
            $this->status = $client->status;
            $this->type = $client->type;
            $this->last_seen = $client->last_seen;
            $this->created_at = $client->created_at;
            $this->loginVerified = true;
        }
        return $client !== null && $client->getLoginVerified();
	}

    /**
     * Sets all attributes and sets its loginVerified attribute as true.
     * @param int $id
     * @param string $identifier
     * @param string $name
     * @param int $rights
     * @param int $type
     * @param int $status
     * @return void
     */
	public function manualLogin($id, $identifier, $name, $rights, $type=0, $status=0)
	{
		$this->id = $id;
		$this->identifier = $identifier;
		$this->rights = $rights;
		$this->name = $name;
		$this->type = $type;
		$this->status = $status;
        $this->last_seen = time();
        $this->created_at = time();
		$this->loginVerified = true;
	}

    /**
     * Sets the loginVerified attribute.
     * @param bool $value
     * @return void
     */
    public function setLoginVerified(bool $value): void
    {
        $this->loginVerified = $value;
    }

    /**
     * Sets all attributes to zero/null/false
     * @return void
     */
	public function logout()
	{
		$this->id = 	0;
        $this->identifier = '';
        $this->secret_hash = '';
        $this->name = '';
        $this->rights = 0;
        $this->status = 0;
        $this->type = 0;
        $this->last_seen = 0;
		$this->loginVerified = false;
	}

    public function getName(): string
	{
		return $this->name;
	}
	public function getRights(): int
	{
		return $this->rights;
	}

    public function getId(): int
    {
        return $this->id;
    }

    public function isActive(): bool
    {
        return $this->status === 1;
    }

    public function getType(): int
    {
        return $this->type;
    }

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

	public function getLoginVerified(): bool
	{
		return $this->loginVerified;
	}

	private function generateSecretHash(): void
	{
		$this->hashedSecret = Client::hashSecret($this->secret_hash);
	}

	public static function readAll($database, $addCond= ""): array
	{
        $query = '
        SELECT * FROM
        '.$database->getPrefix().self::$TABLE_NAME.'
        '.$addCond.'
        ;';
        $result = $database->query($query);
        $users = [];
        if ($result) {
            foreach ($result as $row)
            {
                $user = new Client($database);
                $user->id = $row['id'];
                $user->identifier = $row['identifier'];
                //$user->secret_hash = $row['secret_hash'];
                $user->name = $row['name'];
                $user->rights = $row['rights'];
                $user->status = $row['status'];
                $user->type = $row['type'];
                $user->last_seen = $row['last_seen'];
                $user->created_at = $row['created_at'];
                $users[] = $user;
            }
        }
        return $users;
	}

    /**
     * Summary of readByIdentifierAndSecret - Secret should be in raw form
     * @param mixed $database
     * @param mixed $identifier
     * @param mixed $secret
     * @return ?Client
     */
    public static function readByIdentifierAndSecret($database, $identifier, $secret): ?Client
    {
        $query = '
        SELECT * FROM
        '.$database->getPrefix().self::$TABLE_NAME.'
        WHERE
        LOWER(identifier) = LOWER(?)
        LIMIT 1;
        ';
        $params = [$identifier];
        $result = $database->query($query, $params);

        if (!empty($result))
        {
            $row = $result[0]; // first row
            if (!password_verify($secret, $row['secret_hash']))
            {
                return null;
            }

            $client = new Client($database);
            $client->id = $row['id'];
            $client->identifier = $row['identifier'];
            //$client->secret_hash = $row['secret_hash'];
            $client->name = $row['name'];
            $client->rights = $row['rights'];
            $client->type = $row['type'];
            $client->status = $row['status'];
            $client->last_seen = $row['last_seen'];
            $client->created_at = $row['created_at'];
            $client->loginVerified = true;
            return $client;
        }

        return null;
    }

    public static function readByIdentifier($database, $identifier): ?Client
    {
        $query = '
        SELECT * FROM
        '.$database->getPrefix().self::$TABLE_NAME.'
        WHERE
        LOWER(identifier) = LOWER(?)
        LIMIT 1;
        ';
        $params = [$identifier];
        $result = $database->query($query, $params);

        if (!empty($result))
        {
            $row = $result[0]; // first row
            $client = new Client($database);
            $client->id = $row['id'];
            $client->identifier = $row['identifier'];
            $client->name = $row['name'];
            //$client->secret_hash = $row['secret_hash'];
            $client->rights = $row['rights'];
            $client->type = $row['type'];
            $client->status = $row['status'];
            $client->last_seen = $row['last_seen'];
            $client->created_at = $row['created_at'];
            return $client;
        }

        return null;
    }

    public static function readById($database, $id): ?Client
    {
        $query = '
        SELECT * FROM
        '.$database->getPrefix().self::$TABLE_NAME.'
        WHERE
        id = ?
        LIMIT 1;
        ';
        $params = [intval($id)];
        $result = $database->query($query, $params);

        if ($result && $database->getNumRows($result) > 0)
        {
            $row = $result->fetch_assoc();
            $client = new Client($database);
            $client->id = $row['id'];
            $client->identifier = $row['identifier'];
            //$client->secret_hash = $row['secret_hash'];
            $client->name = $row['name'];
            $client->rights = $row['rights'];
            $client->type = $row['type'];
            $client->status = $row['status'];
            $client->last_seen = $row['last_seen'];
            $client->created_at = $row['created_at'];
            return $client;
        }

        return null;
    }

    /** Generate a short-lived JWT token for the client */
    public function generateAccessToken(string $secret): string
    {
        $payload = [
            'sub' => $this->id,
            'identifier' => $this->identifier,
            'type' => $this->type,
            'role' => $this->rights,
            'appid' => $this->database->getPrefix(),
            'exp' => time() + 3600
        ];
        return JwtUtils::encode($payload, $secret);
    }

    /** Create a Client instance from a JWT access token */
    public static function fromToken(Database $db, string $token, string $secret): ?Client
    {
        $payload = JwtUtils::decode($token, $secret);
        if (!$payload || JwtUtils::isExpired($payload))
            return null;

        $client = new Client($db);
        if ($client->read($payload['sub']))
        {
            $client->loginVerified = true;
            return $client;
        }
        return null;
    }

    /** Generate a long-lived token stored in the database */
    public function generateRefreshToken(): string
    {
        $token = new Token($this->database);
        $token->userid = $this->id;
        $token->token = bin2hex(random_bytes(32));
        $token->issued_at = date('Y-m-d H:i:s');
        $token->expires_at = date('Y-m-d H:i:s', time() + 86400 * 30); // 30 days
        $token->revoked = 0;
        $token->create();
        return $token->token;
    }

	/** Database columns  *******************/
	public $id=0;           // [int]
	public $identifier="";  // [varchar 256]
	public $secret_hash=""; // [text]
	public $name="";        // [varchar 128]
	public $rights=0;       // [int] larger number means stronger rights
	public $status=0;       // [int] 0 = inactive, 1 = active
	public $type=0;         // [int] 0 = webuser, 1 = service
	public $last_seen=0;    // [timestamp]
	public $created_at=0;   // [timestamp]
    /** Database columns section ends ********/

    /** Privates */
	private $hashedSecret = '';
	private $loginVerified = false;
}

?>