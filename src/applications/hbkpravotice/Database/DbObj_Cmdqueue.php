<?php


#[\AllowDynamicProperties]
class DbObj_Cmdqueue extends DbObj implements ICRUD
{
	public static $TABLE_NAME = "cmdqueue";

	/* ICRUD interface functions */
	public function Create()
	{
		$query = '
		INSERT INTO 
		'.$this->GetTable().'
		(
			location, cmd, parameter0, parameter1, parameter2, handled, added, expiration
		)
		VALUES (
			'.$this->location.', 
			"'.$this->cmd.'",
			"'.$this->parameter0.'", 
			"'.$this->parameter1.'", 
			"'.$this->parameter2.'", 
			'.$this->handled.',
			'.$this->added.',
			'.$this->expiration.'
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
			$this->location = $row["location"];
			$this->cmd = $row["cmd"];
			$this->parameter0 = $row["parameter0"];
			$this->parameter1 = $row["parameter1"];
			$this->parameter2 = $row["parameter2"];
			$this->added = $row["added"];
			$this->expiration = $row["expiration"];
			$this->handled = $row["handled"];
		}
	}
	public function Update()
	{
		$query = '
		UPDATE
		'.$this->GetTable().'
		SET
		location="'.$this->location.'",
		cmd="'.$this->cmd.'",
		parameter0="'.$this->parameter0.'",
		parameter1="'.$this->parameter1.'",
		parameter2="'.$this->parameter2.'",
		handled='.$this->handled.',
		added='.$this->added.',
		expiration='.$this->expiration.'
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
		//echo $query;
		$result = $database->Query($query);
		$all = array();
		while ($row = $result->fetch_assoc())
		{
			$selfIns = new DbObj_Cmdqueue($database, $dbPrefix);
			$selfIns->id = 			$row['id'];
			$selfIns->location = 	$row['location'];
			$selfIns->cmd = 		$row['cmd'];
			$selfIns->parameter0 = 	$row['parameter0'];
			$selfIns->parameter1 = 	$row['parameter1'];
			$selfIns->parameter2 = 	$row['parameter2'];
			$selfIns->handled = 	$row['handled'];
			$selfIns->added = 		$row['added'];
			$selfIns->expiration = 	$row['expiration'];
			$all[] = $selfIns;
		}
		return $all;
	}

	/* Database columns  *******************/
	public $id=0;
	public $location=0;
	public $cmd="";
	public $parameter0="";
	public $parameter1="";
	public $parameter2="";
	public $handled=0;
	public $added=0;
	public $expiration=0;

	/* Public  *****************************/
	public function __construct($database, $dbPrefix="")
	{
		parent::__construct($database, self::$TABLE_NAME, $dbPrefix);
	}
	public function GetName()
	{
		return $this->cmd;
	}
}

?>