<?php


#[\AllowDynamicProperties]
class DbObj_Player extends DbObj implements ICRUD
{
	public static $TABLE_NAME = "player";

	/* ICRUD interface functions */
	public function Create()
	{
		$query = '
		INSERT INTO 
		'.$this->GetTable().'
		(
			name, imagePath, dateOfBirth, skillLevel, notes
		)
		VALUES (
			"'.$this->name.'",
			"'.$this->imagePath.'",
			'.$this->dateOfBirth.',
			'.$this->skillLevel.',
			"'.$this->notes.'"
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
			$this->name = $row["name"];
			$this->imagePath = $row["imagePath"];
			$this->dateOfBirth = $row["dateOfBirth"];
			$this->skillLevel = $row["skillLevel"];
			$this->notes = $row["notes"];
		}
	}
	public function Update()
	{
		$query = '
		UPDATE
		'.$this->GetTable().'
		SET
		name="'.$this->name.'",
		imagePath="'.$this->imagePath.'",
		dateOfBirth='.$this->dateOfBirth.',
		skillLevel='.$this->skillLevel.',
		notes="'.$this->notes.'"
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

	public static function ReadAll($database, $dbPrefix="")
	{
		$query = '
		SELECT * FROM
		'.self::GetTableName($dbPrefix, self::$TABLE_NAME).' ORDER BY name;
		';
		$result = $database->Query($query);
		$all = array();
		while ($row = $result->fetch_assoc())
		{
			$selfIns = new DbObj_Player($database, self::$TABLE_NAME, $dbPrefix);
			$selfIns->id = $row['id'];
			$selfIns->name = $row['name'];
			$selfIns->imagePath = $row['imagePath'];
			$selfIns->dateOfBirth = $row['dateOfBirth'];
			$selfIns->skillLevel = $row['skillLevel'];
			$selfIns->notes = $row['notes'];
			$all[] = $selfIns;
		}
		return $all;
	}

	/* Database columns  *******************/
	public $id=0;
	public $name="";
	public $imagePath="";
	public $dateOfBirth=0;
	public $skillLevel=0;
	public $notes="";

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