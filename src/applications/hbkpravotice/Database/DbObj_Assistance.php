<?php


#[\AllowDynamicProperties]
class DbObj_Assistance extends DbObj implements ICRUD
{
	public static $TABLE_NAME = "assistance";

	/* ICRUD interface functions */
	public function Create()
	{
		$query = '
		INSERT INTO 
		'.$this->GetTable().'
		(
			goal, player, assistOrder, game
		)
		VALUES (
			'.$this->goal.', 
			'.$this->player.', 
			'.$this->assistOrder.',
			'.$this->game.'
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
			$this->goal = $row["goal"];
			$this->player = $row["player"];
			$this->assistOrder = $row["assistOrder"];
			$this->game = $row["game"];
		}
	}
	public function Update()
	{
		$query = '
		UPDATE
		'.$this->GetTable().'
		SET
		goal='.$this->goal.',
		player='.$this->player.',
		assistOrder='.$this->assistOrder.',
		game='.$this->game.'
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
		$query .= 'game='.intval($this->game);
		$query .='
		;
		';
		$result = $this->Database->Query($query);
	}
	public function DeleteGoalOccurences()
	{
		$query = '
		DELETE FROM
		'.$this->GetTable().'
		WHERE ';
		$query .= 'goal='.intval($this->goal);
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
			$selfIns = new DbObj_Assistance($database, $dbPrefix);
			$selfIns->id = 				$row['id'];
			$selfIns->goal = 			$row['goal'];
			$selfIns->player = 			$row['player'];
			$selfIns->assistOrder = 	$row['assistOrder'];
			$selfIns->game = 			$row['game'];

			$all[] = $selfIns;
		}
		return $all;
	}

	/* Database columns  *******************/
	public $id=0;
	public $goal;
	public $player;
	public $assistOrder;
	public $game;

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