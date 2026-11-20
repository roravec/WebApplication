<?php


#[\AllowDynamicProperties]
class DbObj_Teamhasplayer extends DbObj implements ICRUD
{
	public static $TABLE_NAME = "teamhasplayer";

	/* ICRUD interface functions */
	public function Create()
	{
		$query = '
		INSERT INTO 
		'.$this->GetTable().'
		(
			team, player, number
		)
		VALUES (
			'.$this->team.', 
			'.$this->player.', 
			'.$this->number.'
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
			$this->team = $row["team"];
			$this->player = $row["player"];
			$this->number = $row["number"];
		}
	}
	public function Find($playerid, $teamid)
	{
		$this->player = $playerid;
		$this->team = $teamid;

		$query = '
		SELECT * FROM
		'.$this->GetTable().'
		WHERE
		player='.intval($this->player).' 
		AND team='.intval($this->team).' 
		LIMIT 1;
		';
		$result = $this->Database->Query($query);
		if ($this->Database->GetNumRows($result) > 0)
		{
			$row = $result->fetch_assoc();
			$this->id = $row["id"];
			$this->team = $row["team"];
			$this->player = $row["player"];
			$this->number = $row["number"];
		}
	}
	public function Update()
	{
		$query = '
		UPDATE
		'.$this->GetTable().'
		SET
		team='.$this->team.',
		player='.$this->player.',
		number='.$this->number.'
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
	public function DeleteOccurences()
	{
		$query = '
		DELETE FROM
		'.$this->GetTable().'
		WHERE ';
		if ($this->team > 0) 		$query .= 'team='.intval($this->team);
		else if ($this->player > 0) $query .= 'player='.intval($this->player);
		$query .='
		;
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
			$selfIns = new DbObj_Teamhasplayer($database, $dbPrefix);
			$selfIns->id = 				$row['id'];
			$selfIns->team = 			$row['team'];
			$selfIns->player = 			$row['player'];
			$selfIns->number = 			$row['number'];

			$all[] = $selfIns;
		}
		return $all;
	}

	public function DuplicateExists()
	{
		$query = '
		SELECT * FROM
		'.$this->GetTable().'
		WHERE
		team='.intval($this->team).' AND player='.intval($this->player).'
		;
		';
		$result = $this->Database->Query($query);
		if ($this->Database->GetNumRows($result) > 0)
		{
			return true;
		}
		return false;
	}

	/* Database columns  *******************/
	public $id=0;
	public $team=0;
	public $player=0;
	public $number=0;

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