<?php


#[\AllowDynamicProperties]
class DbObj_Team extends DbObj implements ICRUD
{
	public static $TABLE_NAME = "team";

	/* ICRUD interface functions */
	public function Create()
	{
		$query = '
		INSERT INTO 
		'.$this->GetTable().'
		(
			name, shortName, nameSoundFile, songFile, goalHorn, imagePath
		)
		VALUES (
			"'.$this->name.'", 
			"'.$this->shortName.'", 
			'.$this->nameSoundFile.',
			'.$this->songFile.',
			'.$this->goalHorn.',
			"'.$this->imagePath.'" 
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
			$this->shortName = $row["shortName"];
			$this->nameSoundFile = $row["nameSoundFile"];
			$this->songFile = $row["songFile"];
			$this->goalHorn = $row["goalHorn"];
			$this->imagePath = $row["imagePath"];
		}
	}
	public function Update()
	{
		$query = '
		UPDATE
		'.$this->GetTable().'
		SET
		name="'.$this->name.'",
		shortName="'.$this->shortName.'",
		nameSoundFile='.$this->nameSoundFile.',
		songFile='.$this->songFile.',
		goalHorn='.$this->goalHorn.',
		imagePath="'.$this->imagePath.'"
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
			$selfIns = new DbObj_Team($database, $dbPrefix);
			$selfIns->id = 				$row['id'];
			$selfIns->name = 			$row['name'];
			$selfIns->shortName = 		$row['shortName'];
			$selfIns->nameSoundFile = 	$row['nameSoundFile'];
			$selfIns->songFile = 	    $row['songFile'];
			$selfIns->goalHorn = 		$row['goalHorn'];
			$selfIns->imagePath = 		$row['imagePath'];

			$all[] = $selfIns;
		}
		return $all;
	}

	/* Database columns  *******************/
	public $id=0;
	public $name="";
	public $shortName="";
	public $nameSoundFile=0;
	public $songFile=0;
	public $goalHorn=0;
	public $imagePath="";

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