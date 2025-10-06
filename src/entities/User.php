<?php
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../entities/BaseEntity.php';
require_once __DIR__ . '/../interfaces/ICrud.php';

class User extends BaseEntity implements ICrud
{
    /**
     * Summary of TABLE_NAME
     * @var string
     */
	protected static $TABLE_NAME = "users";

    /**
     * Summary of hashPassword
     * @param mixed $inPassword
     * @return string
     */
    public static function hashPassword($inPassword)
	{
		return password_hash($inPassword, PASSWORD_DEFAULT);
	}

	/** CRUD functions section */

    /**
     * Summary of create
     * @return bool
     */
	public function create(): bool
	{
        $this->checkIfHashExists();
        $query = '
        INSERT INTO 
        '.$this->getTableName().'
        (
            login, password, name, rights
        )
        VALUES (
            ?, ?, ?, ?
        );
        ';
        $params = [
            $this->login,
            $this->hashedPassword,
            $this->name,
            $this->rights
        ];
        $result = $this->database->query($query, $params);
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
        if ($this->database->getNumRows($result) > 0)
        {
            $row = $result->fetch_assoc();
            $this->login = $row['login'];
            $this->hashedPassword = $row['password'];
            $this->name = $row['name'];
            $this->rights = $row['rights'];
        }
        return $this->database->getNumRows($result) > 0;
	}

    /**
     * Summary of update
     * @return bool
     */
    public function update(): bool
	{
		$this->CheckIfHashExists();
        $query = '
        UPDATE
        '.$this->getTableName().'
        SET
        login = ?,'; 
        $params = [$this->login];
        if ($this->password != '') { // update password only if it is not blank
            $query .= ' password = ?,';
            $params[] = $this->hashedPassword;
        }
        $query .= ' name = ?, rights = ?
        WHERE
        id = ?
        LIMIT 1;
        ';
        $params[] = $this->name;
        $params[] = $this->rights;
        $params[] = $this->id;
        $result = $this->database->query($query, $params);
        return $result !== false;
	}

    /**
     * Summary of delete
     * @param mixed $id
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
        $result = $this->database->query($query, $params);
        return $result !== false;
	}
    /** CRUD functions section ends */

	public function login($login, $password)
	{
        $this->login = $login;
        $this->password = $password;
        $query = '
        SELECT * FROM
        '.$this->getTableName().'
        WHERE
        LOWER(login) = LOWER(?)
        LIMIT 1;
        ';
        $params = [$this->login];
        $result = $this->database->query($query, $params);
        if ($result && $this->database->getNumRows($result) > 0)
        {
            $row = $result->fetch_assoc();
            $this->hashedPassword = $row['password'];
            if (password_verify($this->password, $this->hashedPassword))
            {
                $this->id = $row['id'];
                $this->login = $row['login'];
                $this->name = $row['name'];
                $this->rights = $row['rights'];
                $this->loginVerified = true;
            }
        }
	}
	public function manualLogin($id, $login, $name, $rights)
	{
		$this->id = $id;
		$this->login = $login;
		$this->rights = $rights;
		$this->name = $name;
		$this->loginVerified = true;
	}
	public function logout()
	{
		$this->id = 	0;
		$this->login = 	'';
		$this->password = '';
		$this->hashedPassword = '';
		$this->name = '';
		$this->rights = 0;
		$this->loginVerified = false;
	}

    public function getName()
	{
		return $this->name;
	}
	public function getRights()
	{
		return $this->rights;
	}

	public function getLoginVerified()
	{
		return $this->loginVerified;
	}
	private function checkIfHashExists()
	{
		if ($this->hashedPassword == '')
		{
			$this->hashedPassword = User::hashPassword($this->password);
		}
	}

	public static function readAll($database, $addCond= "")
	{
        $query = '
        SELECT * FROM
        '.$database->getPrefix().self::$TABLE_NAME.'
        '.$addCond.'
        ;';
        $result = $database->query($query);
        $users = [];
        if ($result) {
            while ($row = $result->fetch_assoc())
            {
                $user = new User($database);
                $user->id = $row['id'];
                $user->login = $row['login'];
                $user->hashedPassword = $row['password'];
                $user->name = $row['name'];
                $user->rights = $row['rights'];
                $users[] = $user;
            }
        }
        return $users;
	}

	/** Database columns  *******************/
	public $id=0;
	public $login="";
	public $password="";
	public $name="";
	public $rights=0; // more means stronger rights
    /** Database columns section ends ********/

    /** Privates */
	private $hashedPassword = '';
	private $loginVerified = false;
}

?>