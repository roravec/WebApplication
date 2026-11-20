<?php


#[\AllowDynamicProperties]
class DbObj_Gameevent extends DbObj implements ICRUD
{
	public static $TABLE_NAME = "gameevent";

	/* ICRUD interface functions */
	public function Create()
	{
		$query = '
		INSERT INTO 
		'.$this->GetTable().'
		(
			gameid, event, paralelEvent, lastRecordedTime, eventTimeStart, 
			eventTimeStop, startSoundPlayed, almostEndSoundPlayed, eventTimeOffset
		)
		VALUES (
			'.$this->gameid.', 
			'.$this->event.', 
			'.$this->paralelEvent.', 
			'.$this->lastRecordedTime.', 
			'.$this->eventTimeStart.', 
			'.$this->eventTimeStop.', 
			'.$this->startSoundPlayed.', 
			'.$this->almostEndSoundPlayed.', 
			'.$this->eventTimeOffset.'
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
			$this->event = 					$row['event'];
			$this->gameid = 				$row['gameid'];
			$this->paralelEvent = 			$row['paralelEvent'];
			$this->lastRecordedTime = 		$row['lastRecordedTime'];
			$this->eventTimeStart = 		$row['eventTimeStart'];
			$this->eventTimeStop = 			$row['eventTimeStop'];
			$this->eventTimeOffset = 		$row['eventTimeOffset'];
			$this->startSoundPlayed = 		$row['startSoundPlayed'];
			$this->almostEndSoundPlayed = 	$row['almostEndSoundPlayed'];
		}
	}
	public function Find($addCond='')
	{
		$query = '
		SELECT * FROM
		'.$this->GetTable().'
		WHERE
		gameid='.intval($this->gameid).
		' '.$addCond. '
		LIMIT 1;
		';
		$result = $this->Database->Query($query);
		if ($this->Database->GetNumRows($result) > 0)
		{
			$row = $result->fetch_assoc();
			$this->id = 					$row['id'];
			$this->event = 					$row['event'];
			$this->gameid = 				$row['gameid'];
			$this->paralelEvent = 			$row['paralelEvent'];
			$this->lastRecordedTime = 		$row['lastRecordedTime'];
			$this->eventTimeStart = 		$row['eventTimeStart'];
			$this->eventTimeStop = 			$row['eventTimeStop'];
			$this->eventTimeOffset = 		$row['eventTimeOffset'];
			$this->startSoundPlayed = 		$row['startSoundPlayed'];
			$this->almostEndSoundPlayed = 	$row['almostEndSoundPlayed'];
		}
	}
	public function Update()
	{
		$query = '
		UPDATE
		'.$this->GetTable().'
		SET
		event='.$this->event.',
		paralelEvent='.$this->paralelEvent.',
		lastRecordedTime='.$this->lastRecordedTime.',
		eventTimeStart='.$this->eventTimeStart.',
		eventTimeStop='.$this->eventTimeStop.',
		startSoundPlayed='.$this->startSoundPlayed.',
		almostEndSoundPlayed='.$this->almostEndSoundPlayed.',
		eventTimeOffset='.$this->eventTimeOffset.'
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
		$query .= ' AND paralelEvent=1';
		$query .='
		;
		';
		$result = $this->Database->Query($query);
	}
	public function DeleteEventOccurences($event)
	{
		$query = '
		DELETE FROM
		'.$this->GetTable().'
		WHERE ';
		$query .= 'event='.intval($event);
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
			$selfIns = new DbObj_Gameevent($database, $dbPrefix);
			$selfIns->id = 					$row['id'];
			$selfIns->gameid = 				$row['gameid'];
			$selfIns->event = 				$row['event'];
			$selfIns->paralelEvent = 		$row['paralelEvent'];
			$selfIns->lastRecordedTime = 	$row['lastRecordedTime'];
			$selfIns->eventTimeStart = 		$row['eventTimeStart'];
			$selfIns->eventTimeStop = 		$row['eventTimeStop'];
			$selfIns->eventTimeOffset = 	$row['eventTimeOffset'];
			$selfIns->startSoundPlayed = 	$row['startSoundPlayed'];
			$selfIns->almostEndSoundPlayed =$row['almostEndSoundPlayed'];


			$all[] = $selfIns;
		}
		return $all;
	}

	/* Database columns  *******************/
	public $id=0;
	public $gameid=0;
	public $event=0;
	public $paralelEvent=0;
	public $lastRecordedTime=0;
	public $eventTimeStart=0;
	public $eventTimeStop=0;
	public $eventTimeOffset=0;
	public $startSoundPlayed=0;
	public $almostEndSoundPlayed=0;

	/* Public  *****************************/
	public function __construct($database, $dbPrefix="")
	{
		parent::__construct($database, self::$TABLE_NAME, $dbPrefix);
	}
	public function GetName()
	{
		return $this->event;
	}
}

?>