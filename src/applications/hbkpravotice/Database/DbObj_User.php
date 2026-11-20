<?php


#[\AllowDynamicProperties]
class DbObj_User extends DbObj implements ICRUD
{
	public static $TABLE_NAME = "users";

	/* ICRUD interface functions */
	public function Create()
	{
		$query = '
		INSERT INTO 
		'.$this->GetTable().'
		(
			login, password, name, rights
		)
		VALUES (
			"'.$this->login.'", 
			"'.$this->password.'",
			"'.$this->name.'",
			'.$this->rights.'
		);
		';
		$result = $this->Database->Query($query);
	}
	public function Read()
	{
		$query = '
		SELECT * FROM
		'.$this->GetTable().'
		WHERE
		id='.intval($this->id).'
		LIMIT 1;
		';
		$result = $this->Database->Query($query);
		if ($this->Database->GetNumRows($result) > 0)
		{
			$row = $result->fetch_assoc();
			$this->login = 			$row['login'];
			$this->password = 		$row['password'];
			$this->name = 			$row['name'];
			$this->rights = 		$row['rights'];
		}
	}
	public function Update()
	{
		$query = '
		UPDATE
		'.$this->GetTable().'
		SET
		login="'.$this->login.'",
		password="'.$this->password.'",
		name="'.$this->name.'"
		rights='.$this->rights.'
		WHERE
		id='.$this->id.'
		LIMIT 1;
		';
		$result = $this->Database->Query($query);
	}
	public function Delete()
	{
		$query = '
		DELETE FROM
		'.$this->GetTable().'
		WHERE
		id='.intval($this->id).'
		LIMIT 1;
		';
		$result = $this->Database->Query($query);
	}

	public static function ReadAll($database, $dbPrefix="", $addCond= "")
	{
		$query = '
		SELECT * FROM
		'.self::GetTableName($dbPrefix, self::$TABLE_NAME).' 
		'.$addCond.'
		;';
		$result = $database->Query($query);
		$all = array();
		while ($row = $result->fetch_assoc())
		{
			$selfIns = new DbObj_User($database, $dbPrefix);
			$selfIns->id = 				$row['id'];
			$selfIns->login = 			$row['login'];
			$selfIns->password = 		$row['password'];
			$selfIns->name = 			$row['name'];
			$selfIns->rights = 			$row['rights'];

			$all[] = $selfIns;
		}
		return $all;
	}

	/* Database columns  *******************/
	public $id=0;
	public $login="";
	public $password="";
	public $name="";
	public $rights=0;

	/* Public  *****************************/
	public function __construct($database, $dbPrefix="")
	{
		parent::__construct($database, self::$TABLE_NAME, $dbPrefix);
	}
	public function GetName()
	{
		return $this->name;
	}
}

?>