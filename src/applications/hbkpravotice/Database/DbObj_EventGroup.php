<?php


#[\AllowDynamicProperties]
class DbObj_EventGroup extends DbObj implements ICRUD
{
	public static $TABLE_NAME = "eventgroup";

	/* ICRUD interface functions */
	public function Create()
	{
		$query = '
		INSERT INTO 
		'.$this->GetTable().'
		(
			groupname, penaltySmall, penaltyMedium, penaltyLarge
		)
		VALUES (
			"'.$this->groupname.'", '.$this->penaltySmall.', '.$this->penaltyMedium.', '.$this->penaltyLarge.'
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
			$this->groupname = $row["groupname"];
			$this->penaltySmall = $row["penaltySmall"];
			$this->penaltyMedium = $row["penaltyMedium"];
			$this->penaltyLarge = $row["penaltyLarge"];
		}
	}
	public function Update()
	{
		$query = '
		UPDATE
		'.$this->GetTable().'
		SET
		groupname="'.$this->groupname.'",
		penaltySmall='.$this->penaltySmall.',
		penaltyMedium='.$this->penaltyMedium.',
		penaltyLarge='.$this->penaltyLarge.'
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
		'.self::GetTableName($dbPrefix, self::$TABLE_NAME).';
		';
		$result = $database->Query($query);
		$all = array();
		while ($row = $result->fetch_assoc())
		{
			$selfIns = new DbObj_EventGroup($database, self::$TABLE_NAME, $dbPrefix);
			$selfIns->id = $row['id'];
			$selfIns->groupname = $row['groupname'];
			$selfIns->penaltySmall = $row['penaltySmall'];
			$selfIns->penaltyMedium = $row['penaltyMedium'];
			$selfIns->penaltyLarge = $row['penaltyLarge'];
			$all[] = $selfIns;
		}
		return $all;
	}

	/* Database columns  *******************/
	public $id=0;
	public $groupname="";
    public $penaltySmall=0;
    public $penaltyMedium=0;
    public $penaltyLarge=0;

	/* Public  *****************************/
	public function __construct($database, $dbPrefix="")
	{
		parent::__construct($database, self::$TABLE_NAME, $dbPrefix);
	}
	public function GetName()
	{
		return $this->groupname;
	}
}

?>