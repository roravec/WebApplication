<?php


#[\AllowDynamicProperties]
class DbObj_Gameroster extends DbObj implements ICRUD
{
	public static $TABLE_NAME = "gameroster";

	/* ICRUD interface functions */
	public function Create()
	{
		$query = '
		INSERT INTO 
		'.$this->GetTable().'
		(
			game, team, number, player
		)
		VALUES (
			'.$this->game.', 
			'.$this->team.', 
			'.$this->number.', 
			'.$this->player.'
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
			$this->game = $row["game"];
			$this->team = $row["team"];
			$this->number = $row["number"];
			$this->player = $row["player"];
		}
	}
	public function Find($playerid, $gameid)
	{
		$this->player = $playerid;
		$this->game = $gameid;

		$query = '
		SELECT * FROM
		'.$this->GetTable().'
		WHERE
		player='.intval($this->player).' 
		AND team='.intval($this->game).' 
		LIMIT 1;
		';
		$result = $this->Database->Query($query);
		if ($this->Database->GetNumRows($result) > 0)
		{
			$row = $result->fetch_assoc();
			$this->game = $row["game"];
			$this->team = $row["team"];
			$this->number = $row["number"];
			$this->player = $row["player"];
		}
	}
	public function Update()
	{
		$query = '
		UPDATE
		'.$this->GetTable().'
		SET
		game='.$this->game.',
		team='.$this->team.',
		number='.$this->number.',
		player='.$this->player.'
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
	public function DeleteGameOccurences()
	{
		$query = '
		DELETE FROM
		'.$this->GetTable().'
		WHERE ';
		if ($this->game > 0) 		$query .= 'game='.intval($this->game);
		//else if ($this->player > 0) $query .= 'player='.intval($this->player);
		$query .='
		;
		';
		$result = $this->Database->Query($query);
	}
    public function DeleteGameTeamOccurences()
	{
		$query = '
		DELETE FROM
		'.$this->GetTable().'
		WHERE ';
		$query .= 'game='.intval($this->game);
		$query .= ' AND team='.intval($this->team);
		$query .='
		;
		';
		$result = $this->Database->Query($query);
	}

    public function DeletePlayerOccurences()
	{
		$query = '
		DELETE FROM
		'.$this->GetTable().'
		WHERE ';
		//if ($this->game > 0) 		$query .= 'game='.intval($this->game);
		if ($this->player > 0) $query .= 'player='.intval($this->player);
		$query .='
		;
		';
		$result = $this->Database->Query($query);
	}
    public function DeleteTeamOccurences()
	{
		$query = '
		DELETE FROM
		'.$this->GetTable().'
		WHERE ';
		//if ($this->game > 0) 		$query .= 'game='.intval($this->game);
		if ($this->team > 0) $query .= 'team='.intval($this->team);
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
			$selfIns->game = 			$row['game'];
			$selfIns->team = 			$row['team'];
			$selfIns->number = 			$row['number'];
			$selfIns->player = 			$row['player'];

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
		game='.intval($this->game).' AND player='.intval($this->player).'
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
	public $game=0;
	public $team=0;
	public $number=0;
	public $player=0;

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