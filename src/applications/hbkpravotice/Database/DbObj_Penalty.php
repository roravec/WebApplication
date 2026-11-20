<?php


#[\AllowDynamicProperties]
class DbObj_Penalty extends DbObj implements ICRUD
{
	public static $TABLE_NAME = "penalty";

	/* ICRUD interface functions */
	public function Create()
	{
		$query = '
		INSERT INTO 
		'.$this->GetTable().'
		(
			timestamp, game, team, player, duration, penType, timerid
		)
		VALUES (
			'.$this->timestamp.', 
			'.$this->game.', 
			'.$this->team.', 
			'.$this->player.', 
			'.$this->duration.', 
			'.$this->penType.',
			'.$this->timerid.'
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
			$this->timestamp = $row["timestamp"];
			$this->game = $row["game"];
			$this->team = $row["team"];
			$this->player = $row["player"];
			$this->duration = $row["duration"];
			$this->penType = $row["penType"];
			$this->timerid = $row["timerid"];
		}
	}
	public function Update()
	{
		$query = '
		UPDATE
		'.$this->GetTable().'
		SET
		timestamp='.$this->timestamp.',
		game='.$this->game.',
		team='.$this->team.',
		player='.$this->player.',
		duration='.$this->duration.',
		penType='.$this->penType.',
		timerid='.$this->timerid.'
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
		$query .= 'game='.intval($this->game);
		$query .='
		;
		';
		$result = $this->Database->Query($query);
	}

	public static function GetNumberOfPenalties($database, $dbPrefix="", $addCond= "")
	{
		$query = '
		SELECT * FROM
		'.self::GetTableName($dbPrefix, self::$TABLE_NAME).' 
		'.$addCond.'
		;';
		//echo $query;
		$result = $database->Query($query);
		return $database->GetNumRows($result);
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
			$selfIns = new DbObj_Penalty($database, $dbPrefix);
			$selfIns->id = 				$row['id'];
			$selfIns->timestamp = 		$row['timestamp'];
			$selfIns->game = 			$row['game'];
			$selfIns->team = 			$row['team'];
			$selfIns->player = 			$row['player'];
			$selfIns->duration = 		$row['duration'];
			$selfIns->penType = 		$row['penType'];
			$selfIns->timerid = 		$row['timerid'];

			$all[] = $selfIns;
		}
		return $all;
	}

	public function ReadSmallestPenalty()
	{
		$query = '
		SELECT * FROM
		'.$this->GetTable().'
		WHERE
		game='.intval($this->game).'
		AND team='.intval($this->team).'
		ORDER BY duration ASC
		LIMIT 1;
		';
		$result = $this->Database->Query($query);
		if ($this->Database->GetNumRows($result) > 0)
		{
			$row = $result->fetch_assoc();
			$this->id = $row['id'];
			$this->timestamp = $row['timestamp'];
			$this->game = $row['game'];
			$this->team = $row['team'];
			$this->player = $row['player'];
			$this->duration = $row['duration'];
			$this->penType = $row['penType'];
			$this->timerid = $row['timerid'];
		}
	}

	public function ReadLastPenaltyInGame()
	{
		$query = '
		SELECT * FROM
		'.$this->GetTable().'
		WHERE
		game='.intval($this->game).'
		AND team='.intval($this->team).'
		ORDER BY id DESC
		LIMIT 1;
		';
		$result = $this->Database->Query($query);
		if ($this->Database->GetNumRows($result) > 0)
		{
			$row = $result->fetch_assoc();
			$this->id = $row['id'];
			$this->timestamp = $row['timestamp'];
			$this->game = $row['game'];
			$this->team = $row['team'];
			$this->player = $row['player'];
			$this->duration = $row['duration'];
			$this->penType = $row['penType'];
			$this->timerid = $row['timerid'];
		}
	}

	/* Database columns  *******************/
	public $id=0;
	public $timestamp;
	public $game;
	public $team;
	public $player;
	public $duration;
	public $penType;
	public $timerid;

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