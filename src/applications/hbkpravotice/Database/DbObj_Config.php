<?php


#[\AllowDynamicProperties]
class DbObj_Config extends DbObj implements ICRUD
{
	public static $TABLE_NAME = "config";

	/* ICRUD interface functions */
	public function Create()
	{
		$query = '
		INSERT INTO 
		'.$this->GetTable().'
		(
			name, location, timerActive, sockets, 
			teamsMirror, activeGame, soundVolume, musicVolume, commandExpirationTime,
			quietHourStart, quietHourEnd, musicBreak
		)
		VALUES (
			"'.$this->name.'",
			'.$this->location.',
			'.$this->timerActive.',
			'.$this->sockets.',
			'.$this->teamsMirror.',
			'.$this->activeGame.',
			'.$this->soundVolume.',
			'.$this->musicVolume.',
			'.$this->commandExpirationTime.',
			"'.$this->quietHourStart.'",
			"'.$this->quietHourEnd.'",
            '.$this->musicBreak.'
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
		id='.$this->id.'
		LIMIT 1;
		';
		$result = $this->Database->Query($query);
		if ($this->Database->GetNumRows($result) > 0)
		{
			$row = $result->fetch_assoc();
			$this->name = $row["name"];
			$this->location = $row["location"];
			$this->timerActive = $row["timerActive"];
			$this->sockets = $row["sockets"];
			$this->teamsMirror = $row["teamsMirror"];
			$this->activeGame = $row["activeGame"];
			$this->soundVolume = $row["soundVolume"];
			$this->musicVolume = $row["musicVolume"];
			$this->commandExpirationTime = $row["commandExpirationTime"];
			$this->quietHourStart = $row["quietHourStart"];
			$this->quietHourEnd = $row["quietHourEnd"];
			$this->musicBreak = $row["musicBreak"];
		}
	}
	public function Update()
	{
		$query = '
		UPDATE
		'.$this->GetTable().'
		SET
		name="'.$this->name.'",
		quietHourEnd="'.$this->quietHourEnd.'",
		quietHourStart="'.$this->quietHourStart.'",
		location='.$this->location.',
		timerActive='.$this->timerActive.',
		sockets='.$this->sockets.',
		teamsMirror='.$this->teamsMirror.',
		soundVolume='.$this->soundVolume.',
        musicVolume='.$this->musicVolume.',
		commandExpirationTime='.$this->commandExpirationTime.',
		activeGame='.$this->activeGame.',
        musicBreak='.$this->musicBreak.'
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
		id='.$this->id.'
		LIMIT 1;
		';
		$result = $this->Database->Query($query);
	}

	public static function ReadAll($database, $dbPrefix="")
	{
		$query = '
		SELECT * FROM
		'.self::GetTableName($dbPrefix, self::$TABLE_NAME).';
		';
		$result = $database->Query($query);
		$all = array();
		while ($row = $result->fetch_assoc())
		{
			$selfIns = new DbObj_Config($database, self::$TABLE_NAME, $dbPrefix);
			$selfIns->id = $row['id'];
			$selfIns->name = $row['name'];
			$selfIns->location = $row['location'];
			$selfIns->timerActive = $row['timerActive'];
			$selfIns->sockets = $row['sockets'];
			$selfIns->teamsMirror = $row['teamsMirror'];
			$selfIns->activeGame = $row['activeGame'];
			$selfIns->soundVolume = $row['soundVolume'];
            $selfIns->musicVolume = $row['musicVolume'];
			$selfIns->commandExpirationTime = $row['commandExpirationTime'];
			$selfIns->quietHourStart = $row['quietHourStart'];
			$selfIns->quietHourEnd = $row['quietHourEnd'];
            $selfIns->musicBreak = $row['musicBreak'];
			$all[] = $selfIns;
		}
		return $all;
	}

	/* Database columns  *******************/
	public $id=0;
	public $name="";
	public $location=0;
	public $timerActive=0;
	public $sockets=0;
	public $teamsMirror=0;
	public $activeGame=0;
	public $soundVolume=50;
    public $musicVolume=30;
	public $commandExpirationTime=0;
	public $quietHourStart="";
	public $quietHourEnd="";
    public $musicBreak=0;

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