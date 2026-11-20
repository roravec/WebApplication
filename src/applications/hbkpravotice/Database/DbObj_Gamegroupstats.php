<?php


#[\AllowDynamicProperties]
class DbObj_Gamegroupstats extends DbObj implements ICRUD
{
	public static $TABLE_NAME = "gamegroupstats";

	/* ICRUD interface functions */
	public function Create()
	{
		$query = '
		INSERT INTO 
		'.$this->GetTable().'
		(
			name, displayName, seasonId, webpageId, pointsWin, pointsLost, pointsWinPp, pointsLostPp, pointsTie, penaltySmall, penaltyMedium, penaltyLarge
		)
		VALUES (
			"'.$this->name.'",
			"'.$this->displayName.'",
			"'.$this->seasonId.'",
			'.$this->webpageId.',
			'.$this->pointsWin.',
			'.$this->pointsLost.',
			'.$this->pointsWinPp.',
			'.$this->pointsLostPp.',
			'.$this->pointsTie.',
			'.$this->penaltySmall.',
			'.$this->penaltyMedium.',
			'.$this->penaltyLarge.'
		);
		';
		$result = $this->Database->Query($query);
		return $result;
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
			$this->displayName = $row["displayName"];
			$this->seasonId = $row["seasonId"];
			$this->webpageId = isset($row["webpageId"]) ? intval($row["webpageId"]) : 0;
			$this->pointsWin = 		$row["pointsWin"];
			$this->pointsLost = 	$row["pointsLost"];
			$this->pointsWinPp = 	$row["pointsWinPp"];
			$this->pointsLostPp = 	$row["pointsLostPp"];
			$this->pointsTie = 		$row["pointsTie"];
			$this->penaltySmall = 	isset($row["penaltySmall"]) ? intval($row["penaltySmall"]) : 0;
			$this->penaltyMedium = 	isset($row["penaltyMedium"]) ? intval($row["penaltyMedium"]) : 0;
			$this->penaltyLarge = 	isset($row["penaltyLarge"]) ? intval($row["penaltyLarge"]) : 0;
		}
	}
	public function Update()
	{
		$query = '
		UPDATE
		'.$this->GetTable().'
		SET
		name="'.$this->name.'",
		displayName="'.$this->displayName.'",
		seasonId="'.$this->seasonId.'",
		webpageId='.$this->webpageId.',
		pointsWin='.$this->pointsWin.',
		pointsLost='.$this->pointsLost.',
		pointsWinPp='.$this->pointsWinPp.',
		pointsLostPp='.$this->pointsLostPp.',
		pointsTie='.$this->pointsTie.',
		penaltySmall='.$this->penaltySmall.',
		penaltyMedium='.$this->penaltyMedium.',
		penaltyLarge='.$this->penaltyLarge.'
		WHERE
		id='.$this->id.'
		LIMIT 1;
		';
		$result = $this->Database->Query($query);
		return $result;
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

	public static function ReadAll($database, $dbPrefix="", $addCond="")
	{
		$query = '
		SELECT * FROM
		'.self::GetTableName($dbPrefix, self::$TABLE_NAME).'
		'.$addCond.'
		;
		';
		$result = $database->Query($query);
		$all = array();
		while ($row = $result->fetch_assoc())
		{
			$selfIns = new DbObj_Gamegroupstats($database, $dbPrefix);
			$selfIns->id = $row['id'];
			$selfIns->name = $row['name'];
			$selfIns->displayName = $row['displayName'];
			$selfIns->seasonId = $row['seasonId'];
			$selfIns->webpageId = isset($row['webpageId']) ? intval($row['webpageId']) : 0;
			$selfIns->pointsWin = 		$row["pointsWin"];
			$selfIns->pointsLost = 		$row["pointsLost"];
			$selfIns->pointsWinPp = 	$row["pointsWinPp"];
			$selfIns->pointsLostPp = 	$row["pointsLostPp"];
			$selfIns->pointsTie = 		$row["pointsTie"];
			$selfIns->penaltySmall = 	isset($row["penaltySmall"]) ? intval($row["penaltySmall"]) : 0;
			$selfIns->penaltyMedium = 	isset($row["penaltyMedium"]) ? intval($row["penaltyMedium"]) : 0;
			$selfIns->penaltyLarge = 	isset($row["penaltyLarge"]) ? intval($row["penaltyLarge"]) : 0;
			$all[] = $selfIns;
		}
		return $all;
	}

	/* Database columns  *******************/
	public $id=0;
	public $name="";
	public $displayName="";
	/** @var string */
	public $seasonId = "";
	public $webpageId = 0;
	public $pointsWin = 0;
	public $pointsLost = 0;
	public $pointsWinPp = 0;
	public $pointsLostPp = 0;
	public $pointsTie = 0;
	public $penaltySmall = 0;
	public $penaltyMedium = 0;
	public $penaltyLarge = 0;

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