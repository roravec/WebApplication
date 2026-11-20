<?php


#[\AllowDynamicProperties]
class DbObj_Game extends DbObj implements ICRUD
{
	public static $TABLE_NAME = "game";

	/* ICRUD interface functions */
	public function Create()
	{
		$query = '
		INSERT INTO 
		'.$this->GetTable().'
		(
			gameGroup, name, location, startTime, note, team1, team2, afterGameStatus, eventGroup, referee, refereeRating, visitors
		)
		VALUES (
            '.$this->gameGroup.',
			"'.$this->name.'", 
            "'.$this->location.'",
            '.$this->startTime.',
			"'.$this->note.'", 
			'.$this->team1.', 
			'.$this->team2.', 
			'.$this->afterGameStatus.',
			'.$this->eventGroup.',
            "'.$this->referee.'",
            '.$this->refereeRating.',
            '.$this->visitors.'
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
            $this->location = $row["location"];
            $this->startTime = $row["startTime"];
            $this->referee = $row["referee"];
            $this->refereeRating = $row["refereeRating"];
            $this->visitors = $row["visitors"];
            $this->gameGroup = $row["gameGroup"];
			$this->note = $row["note"];
			$this->team1 = $row["team1"];
			$this->team2 = $row["team2"];
			$this->afterGameStatus = $row["afterGameStatus"];
			$this->eventGroup = $row["eventGroup"];
		}
	}
	public function Update()
	{
		$query = '
		UPDATE
		'.$this->GetTable().'
		SET
		name="'.$this->name.'",
        location="'.$this->location.'",
        referee="'.$this->referee.'",
        refereeRating='.$this->refereeRating.',
        visitors='.$this->visitors.',
        startTime='.$this->startTime.',
        gameGroup='.$this->gameGroup.',
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
			$selfIns = new DbObj_Game($database, $dbPrefix);
			$selfIns->id = 				$row['id'];
			$selfIns->gameGroup = 	    $row['gameGroup'];
			$selfIns->name = 			$row['name'];
			$selfIns->referee = 		$row['referee'];
			$selfIns->refereeRating = 	$row['refereeRating'];
			$selfIns->visitors = 		$row['visitors'];
            $selfIns->location = 		$row['location'];
            $selfIns->startTime = 		$row['startTime'];
			$selfIns->note = 			$row['note'];
			$selfIns->team1 = 			$row['team1'];
			$selfIns->team2 = 			$row['team2'];
			$selfIns->afterGameStatus = $row['afterGameStatus'];
			$selfIns->eventGroup = $row['eventGroup'];

			$all[] = $selfIns;
		}
		return $all;
	}

	/* Database columns  *******************/
	public $id=0;
	public $name="";
    public $location="";
/**
 * datetime-local
 */
public $startTime = 0; 
	public $note="";
	public $referee="";
	public $refereeRating=0;
	public $visitors=0;
    public $gameGroup=0;
	public $team1=0;
	public $team2=0;
	public $afterGameStatus=0;
	public $eventGroup=0;

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

#[\AllowDynamicProperties]
class GameGoal
{
	public $goalid = 0;
	public $a1id = 0;
	public $a2id = 0;
	public $gameid = 0;
	public $teamid = 0;
	public $player_goal = 0;
	public $player_assist1 = 0;
	public $player_assist2 = 0;
	public $time = 0;
	public $goalType = 0; // 0=normal, 1=power-play, 2=short-handed, 3=empty-net, 4=penalty-shot
	public $goalPeriod = 0;
	public function __construct()
	{
	}
	public static function ReadAll($gameid, $teamid, $database, $dbPrefix="", $addCond= "")
	{
		$preaddCond = ($teamid>0)? ("AND goal.team=".$teamid) : "";
		$query = '
		SELECT 
		goal.id as goalid, goal.timestamp AS timestamp, goal.team AS team, goal.player as GOAL, goal.goalType as goalType, goal.goalPeriod as goalPeriod,
		assist1.player AS ASSIST1, assist2.player AS ASSIST2, assist1.id as a1id, assist2.id as a2id 
FROM `hbkpravotice_goal` goal
LEFT JOIN `hbkpravotice_assistance` assist1 ON goal.id=assist1.goal AND assist1.assistOrder=0
LEFT JOIN `hbkpravotice_assistance` assist2 ON goal.id=assist2.goal AND assist2.assistOrder=1
WHERE goal.game='.$gameid.' '.$preaddCond.' '.$addCond;
		//echo $query; exit;
/* Outputs: 
	goalid	
	a1id
	a2id
	timestamp	
	team	
	GOAL	
	ASSIST1	
	ASSIST2	
	goalType
	goalPeriod
*/
		$result = $database->Query($query);
		$all = array();
		while ($row = $result->fetch_assoc())
		{
			$selfIns = new GameGoal();
			$selfIns->gameid = 			$gameid;
			$selfIns->goalid = 			$row['goalid'];
			$selfIns->a1id = 			$row['a1id'];
			$selfIns->a2id = 			$row['a2id'];
			$selfIns->time = 			$row['timestamp'];
			$selfIns->teamid = 			$row['team'];
			$selfIns->player_goal = 	$row['GOAL'];
			$selfIns->player_assist1 = 	$row['ASSIST1'];
			$selfIns->player_assist2 = 	$row['ASSIST2'];
			$selfIns->goalType = 		$row['goalType'];
			$selfIns->goalPeriod = 	isset($row['goalPeriod']) ? $row['goalPeriod'] : 0;

			$all[] = $selfIns;
		}
		return $all;
	}
}

?>