<?php


#[\AllowDynamicProperties]
class DbObj_Timerevent extends DbObj implements ICRUD
{
	public static $TABLE_NAME = "timerevent";
	public static $PenaltyTypeID = 2;

	/* ICRUD interface functions */
	public function Create()
	{
		$query = '
		INSERT INTO 
		'.$this->GetTable().'
		(
			gameid, orderingroup, duration, type, name, note, periodNumber, soundStart, soundEnd, almostEndSound, stopTimerAfter, disableMusic
		)
		VALUES (
			'.$this->gameid.', 
			'.$this->orderingroup.', 
			'.$this->duration.', 
			'.$this->type.', 
			"'.$this->name.'", 
			"'.$this->note.'", 
			'.$this->periodNumber.', 
			'.$this->soundStart.', 
			'.$this->soundEnd.',
			'.$this->almostEndSound.',
			'.$this->stopTimerAfter.',
            '.$this->disableMusic.'
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
			$this->gameid = 		$row['gameid'];
			$this->orderingroup = 		$row['orderingroup'];
			$this->duration = 			$row['duration'];
			$this->type = 				$row['type'];
			$this->name = 				$row['name'];
			$this->note = 				$row['note'];
			$this->periodNumber = 		$row['periodNumber'];
			$this->soundStart = 		$row['soundStart'];
			$this->soundEnd = 			$row['soundEnd'];
			$this->almostEndSound = 			$row['almostEndSound'];
			$this->stopTimerAfter = 	$row['stopTimerAfter'];
            $this->disableMusic = 	$row['disableMusic'];
		}
	}
	public function Update()
	{
		$query = '
		UPDATE
		'.$this->GetTable().'
		SET
		gameid='.$this->gameid.',
		orderingroup='.$this->orderingroup.',
		duration='.$this->duration.',
		type='.$this->type.',
		name="'.$this->name.'",
		note="'.$this->note.'",
		soundStart='.$this->soundStart.',
		periodNumber='.$this->periodNumber.',
		soundEnd='.$this->soundEnd.',
		almostEndSound='.$this->almostEndSound.',
		stopTimerAfter='.$this->stopTimerAfter.',
        disableMusic='.$this->disableMusic.'
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
	public function DeleteGameOccurences($justPenalties = false)
	{
		$query = '
		DELETE FROM
		'.$this->GetTable().'
		WHERE ';
		$query .= 'gameid='.intval($this->gameid);
		if ($justPenalties)
		$query .= ' AND type='.DbObj_Timerevent::$PenaltyTypeID;
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
		$result = $database->Query($query);
		$all = array();
		while ($row = $result->fetch_assoc())
		{
			$selfIns = new DbObj_Timerevent($database, $dbPrefix);
			$selfIns->id = 				$row['id'];
			$selfIns->gameid = 		$row['gameid'];
			$selfIns->orderingroup = 	$row['orderingroup'];
			$selfIns->duration = 		$row['duration'];
			$selfIns->type = 			$row['type'];
			$selfIns->name = 			$row['name'];
			$selfIns->periodNumber = 			$row['periodNumber'];
			$selfIns->note = 			$row['note'];
			$selfIns->soundStart = 		$row['soundStart'];
			$selfIns->soundEnd = 		$row['soundEnd'];
			$selfIns->almostEndSound = 		$row['almostEndSound'];
			$selfIns->stopTimerAfter = 	$row['stopTimerAfter'];
            $selfIns->disableMusic = 	$row['disableMusic'];

			$all[] = $selfIns;
		}
		return $all;
	}

	/* Database columns  *******************/
	public $id=0;
	public $gameid=0;
	public $orderingroup=0;
	public $duration=0;
	public $type=0;
	public $name="";
	public $note=" ";
	public $soundStart=0;
	public $almostEndSound=0;
	public $soundEnd=0;
	public $periodNumber=0;
	public $stopTimerAfter=0;
    public $disableMusic = 0;

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