<?php


#[\AllowDynamicProperties]
class DbObj_Goal extends DbObj implements ICRUD
{
	public static $TABLE_NAME = "goal";

	/* ICRUD interface functions */
	public function Create()
	{
		$query = '
		INSERT INTO 
		'.$this->GetTable().'
		(
			timestamp, game, team, player, goalType, goalPeriod
		)
		VALUES (
			'.$this->timestamp.', 
			'.$this->game.', 
			'.$this->team.', 
			'.$this->player.', 
			'.$this->goalType.',
			'.$this->goalPeriod.'
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
			$this->goalType = isset($row["goalType"]) ? $row["goalType"] : 0;
			$this->goalPeriod = isset($row["goalPeriod"]) ? $row["goalPeriod"] : 0;
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
		goalType='.$this->goalType.',
		goalPeriod='.$this->goalPeriod.'
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
			$selfIns = new DbObj_Goal($database, $dbPrefix);
			$selfIns->id = 				$row['id'];
			$selfIns->timestamp = 		$row['timestamp'];
			$selfIns->game = 			$row['game'];
			$selfIns->team = 			$row['team'];
			$selfIns->player = 			$row['player'];
			$selfIns->goalType = 		isset($row['goalType']) ? $row['goalType'] : 0;
			$selfIns->goalPeriod = 	isset($row['goalPeriod']) ? $row['goalPeriod'] : 0;

			$all[] = $selfIns;
		}
		return $all;
	}
    /* reads the last added teams goal in the game. By ID */
    public function ReadLastGoalInGame()
    {
        $query = '
        SELECT * FROM
        '.$this->GetTable().'
        WHERE
        game='.intval($this->game).' AND
        team='.intval($this->team).'
        ORDER BY id DESC
        LIMIT 1;
        ';
        $result = $this->Database->Query($query);
        if ($this->Database->GetNumRows($result) > 0)
        {
            $row = $result->fetch_assoc();
            $this->id = $row["id"];
            $this->timestamp = $row["timestamp"];
            $this->game = $row["game"];
            $this->team = $row["team"];
            $this->player = $row["player"];
            $this->goalType = isset($row["goalType"]) ? $row["goalType"] : 0;
			$this->goalPeriod = isset($row["goalPeriod"]) ? $row["goalPeriod"] : 0;
        }
    }

	public static function GetNumberOfGoals($database, $dbPrefix="", $addCond= "")
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

	/* Database columns  *******************/
	public $id=0;
	public $timestamp;
	public $game;
	public $team;
	public $player;
	public $goalType = 0; // 0=normal, 1=power-play, 2=short-handed, 3=empty-net, 4=penalty-shot
	public $goalPeriod = 0;

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