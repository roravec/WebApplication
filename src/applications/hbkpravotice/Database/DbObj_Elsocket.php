<?php


#[\AllowDynamicProperties]
class DbObj_Elsocket extends DbObj implements ICRUD
{
	public static $TABLE_NAME = "elsocket";

	/* ICRUD interface functions */
	public function Create()
	{
		$query = '
		INSERT INTO 
		'.$this->GetTable().'
		(
			location, socketId, state
		)
		VALUES (
			'.$this->location.', '.$this->socketId.', '.$this->state.'
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
		id='.$this->id.'
		LIMIT 1;
		';
		$result = $this->Database->Query($query);
		if ($this->Database->GetNumRows($result) > 0)
		{
			$row = $result->fetch_assoc();
			$this->location = $row["location"];
			$this->socketId = $row["socketId"];
			$this->state = $row["state"];
		}
	}
	public function ReadSocketIDinLocation()
	{
		$query = '
		SELECT * FROM
		'.$this->GetTable().'
		WHERE
		socketId='.$this->socketId.' AND location='.$this->location.'
		LIMIT 1;
		';
		$result = $this->Database->Query($query);
		if ($this->Database->GetNumRows($result) > 0)
		{
			$row = $result->fetch_assoc();
			$this->id = $row["id"];
			$this->location = $row["location"];
			$this->socketId = $row["socketId"];
			$this->state = $row["state"];
		}
	}
	public function Update()
	{
		$query = '
		UPDATE
		'.$this->GetTable().'
		SET
		location="'.$this->location.'",
		socketId="'.$this->socketId.'",
		state="'.$this->state.'"
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
		id='.$this->id.'
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
		//echo $query;
		$result = $database->Query($query);
		$all = array();
		while ($row = $result->fetch_assoc())
		{
			$selfIns = new DbObj_Elsocket($database, self::$TABLE_NAME, $dbPrefix);
			$selfIns->id = 			$row['id'];
			$selfIns->location = 	$row['location'];
			$selfIns->socketId = 	$row['socketId'];
			$selfIns->state = 		$row['state'];
			$all[] = $selfIns;
		}
		return $all;
	}

	/* Database columns  *******************/
	public $id;
	public $location;
	public $socketId;
	public $state;

	/* Public  *****************************/
	public function __construct($database, $dbPrefix="")
	{
		parent::__construct($database, self::$TABLE_NAME, $dbPrefix);
	}
	public function GetName()
	{
		return $this->socketId;
	}
}

?>