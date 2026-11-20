<?php


#[\AllowDynamicProperties]
class DbObj_SoundFile extends DbObj implements ICRUD
{
	public static $TABLE_NAME = "soundfile";

	/* ICRUD interface functions */
	public function Create()
	{
		$query = '
		INSERT INTO 
		'.$this->GetTable().'
		(
			name
		)
		VALUES (
			"'.$this->name.'"
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
		}
	}
	public function Update()
	{
		$query = '
		UPDATE
		'.$this->GetTable().'
		SET
		name="'.$this->name.'"
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
		'.self::GetTableName($dbPrefix, self::$TABLE_NAME).'
		ORDER BY name;';
		$result = $database->Query($query);
		$all = array();
		while ($row = $result->fetch_assoc())
		{
			$selfIns = new DbObj_SoundFile($database, self::$TABLE_NAME, $dbPrefix);
			$selfIns->id = $row['id'];
			$selfIns->name = $row['name'];
			$all[] = $selfIns;
		}
		return $all;
	}

	/* Database columns  *******************/
	public $id=0;
	public $name="";

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