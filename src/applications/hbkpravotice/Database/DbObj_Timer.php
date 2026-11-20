<?php


#[\AllowDynamicProperties]
class DbObj_Timer extends DbObj implements ICRUD
{
	public static $TABLE_NAME = "timer";

	/* ICRUD interface functions */
	public function Create()
	{
		$query = '
		INSERT INTO 
		'.$this->GetTable().'
		(
			lastRecordedTime, active, location
		)
		VALUES (
			'.$this->lastRecordedTime.', 
			'.$this->active.',
			'.$this->location.'
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
			$this->lastRecordedTime =$row['lastRecordedTime'];
			$this->active = 			$row['active'];
			$this->location = 			$row['location'];
		}
	}
	public function Update()
	{
		$query = '
		UPDATE
		'.$this->GetTable().'
		SET
		lastRecordedTime='.$this->lastRecordedTime.',
		location='.$this->location.',
		active='.$this->active.'
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
		//echo $query;
		$result = $database->Query($query);
		$all = array();
		while ($row = $result->fetch_assoc())
		{
			$selfIns = new DbObj_Timer($database, $dbPrefix);
			$selfIns->id = 				$row['id'];
			$selfIns->lastRecordedTime =$row['lastRecordedTime'];
			$selfIns->active = 			$row['active'];
			$selfIns->location = 			$row['location'];

			$all[] = $selfIns;
		}
		return $all;
	}

	/* Database columns  *******************/
	public $id=0;
	public $lastRecordedTime;
	public $active;
	public $location;

	/* Public  *****************************/
	public function __construct($database, $dbPrefix="")
	{
		parent::__construct($database, self::$TABLE_NAME, $dbPrefix);
	}
	public function GetName()
	{
		return $this->id;
	}
}

?>