<?php


#[\AllowDynamicProperties]
class DbObj_Duel extends DbObj implements ICRUD
{
	public static $TABLE_NAME = "duel";

	/* ICRUD interface functions */
	public function Create()
	{
		$query = '
		INSERT INTO 
		'.$this->GetTable().'
		(
			name, note, team1, team2, afterGameStatus
		)
		VALUES (
			"'.$this->name.'", 
			"'.$this->note.'", 
			'.$this->team1.', 
			'.$this->team2.', 
			'.$this->afterGameStatus.'
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
			$this->name = $row["name"];
			$this->note = $row["note"];
			$this->team1 = $row["team1"];
			$this->team2 = $row["team2"];
			$this->afterGameStatus = $row["afterGameStatus"];
		}
	}
	public function Update()
	{
		$query = '
		UPDATE
		'.$this->GetTable().'
		SET
		name="'.$this->name.'",
		note="'.$this->note.'",
		team1='.$this->team1.',
		team2='.$this->team2.',
		afterGameStatus='.$this->afterGameStatus.'
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
			$selfIns = new DbObj_Duel($database, $dbPrefix);
			$selfIns->id = 				$row['id'];
			$selfIns->name = 			$row['name'];
			$selfIns->note = 			$row['note'];
			$selfIns->team1 = 			$row['team1'];
			$selfIns->team2 = 			$row['team2'];
			$selfIns->afterGameStatus = $row['afterGameStatus'];

			$all[] = $selfIns;
		}
		return $all;
	}

	/* Database columns  *******************/
	public $id=0;
	public $name="";
	public $note="";
	public $team1;
	public $team2;
	public $afterGameStatus;

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