<?php
/**
 * User of the oldest core (core/User.php): table <prefix>users (id, login, password = password_hash, name, rights).
 * Ported apps (hbkpravotice, bnhl) log in with it and manage their users in their administration.
 * Same API as before; fixed while porting (2026-11-20): values are escaped (login / name went into SQL as typed),
 * Update() had no comma before "rights" (SQL error — editing a user never worked) and kept the old hash when a new
 * password was set.
 */
class User implements ICRUD
{
    public static $TABLE_NAME = 'users';

    public $id = 0;
    public $login = '';
    public $password = '';
    public $name = '';
    public $rights = 0;   // more means better rights
    private $hashedPassword = '';
    /** @var \WebApplication\Database\LegacyDatabase */
    private $Database = null;
    private $DbPrefix = '';
    private $loginVerified = false;

    public function __construct($database, $dbPrefix = '')
    {
        $this->Database = $database;
        $this->DbPrefix = $dbPrefix;
    }

    private function e($v): string
    {
        return $this->Database->EscapeString((string)$v);
    }

    public function Create()
    {
        $this->CheckIfHashExists();
        $this->Database->Query('INSERT INTO ' . $this->GetTable() . ' (login, password, name, rights) VALUES ("'
            . $this->e($this->login) . '", "' . $this->e($this->hashedPassword) . '", "' . $this->e($this->name) . '", ' . (int)$this->rights . ')');
        $this->id = (int)$this->Database->GetLastInsertID();
    }

    public function Read()
    {
        $result = $this->Database->Query('SELECT * FROM ' . $this->GetTable() . ' WHERE id=' . (int)$this->id . ' LIMIT 1');
        if ($this->Database->GetNumRows($result) > 0) {
            $row = $result->fetch_assoc();
            $this->login = $row['login'];
            $this->hashedPassword = $row['password'];
            $this->name = $row['name'];
            $this->rights = $row['rights'];
        }
    }

    public function Login($login, $password)
    {
        $this->login = $login;
        $this->password = $password;
        $result = $this->Database->Query('SELECT * FROM ' . $this->GetTable() . ' WHERE LOWER(login)=LOWER("' . $this->e($login) . '") LIMIT 1');
        if ($this->Database->GetNumRows($result) > 0) {
            $row = $result->fetch_assoc();
            $this->id = $row['id'];
            $this->login = $row['login'];
            $this->hashedPassword = $row['password'];
            $this->name = $row['name'];
            $this->rights = $row['rights'];
            if (password_verify((string)$password, (string)$this->hashedPassword)) {
                $this->loginVerified = true;
            }
        }
    }

    public function ManualLogin($inId, $inLogin, $inName, $inRights)
    {
        $this->id = $inId;
        $this->login = $inLogin;
        $this->rights = $inRights;
        $this->name = $inName;
        $this->loginVerified = true;
    }

    public function Logout()
    {
        $this->id = 0;
        $this->login = '';
        $this->password = '';
        $this->hashedPassword = '';
        $this->name = '';
        $this->rights = 0;
        $this->loginVerified = false;
    }

    public function Update()
    {
        $query = 'UPDATE ' . $this->GetTable() . ' SET login="' . $this->e($this->login) . '",';
        if ($this->password != '') {   // the password changes only when a new one was entered
            $this->hashedPassword = self::HashPassword($this->password);
            $query .= ' password="' . $this->e($this->hashedPassword) . '",';
        }
        $query .= ' name="' . $this->e($this->name) . '", rights=' . (int)$this->rights . ' WHERE id=' . (int)$this->id . ' LIMIT 1';
        $this->Database->Query($query);
    }

    public function Delete()
    {
        $this->Database->Query('DELETE FROM ' . $this->GetTable() . ' WHERE id=' . (int)$this->id . ' LIMIT 1');
    }

    public static function ReadAll($database, $dbPrefix = '', $addCond = '')
    {
        $result = $database->Query('SELECT * FROM ' . $dbPrefix . self::$TABLE_NAME . ' ' . $addCond);
        $all = [];
        while ($result && ($row = $result->fetch_assoc())) {
            $u = new User($database, $dbPrefix);
            $u->id = $row['id'];
            $u->login = $row['login'];
            $u->hashedPassword = $row['password'];
            $u->name = $row['name'];
            $u->rights = $row['rights'];
            $all[] = $u;
        }
        return $all;
    }

    public function GetName()
    {
        return $this->name;
    }

    public function GetRightsLevel()
    {
        return $this->rights;
    }

    public function GetLoginVerified()
    {
        return $this->loginVerified;
    }

    private function GetTable()
    {
        return $this->DbPrefix . self::$TABLE_NAME;
    }

    public static function HashPassword($inPassword)
    {
        return password_hash((string)$inPassword, PASSWORD_DEFAULT);
    }

    private function CheckIfHashExists()
    {
        if ($this->hashedPassword == '') {
            $this->hashedPassword = self::HashPassword($this->password);
        }
    }
}
