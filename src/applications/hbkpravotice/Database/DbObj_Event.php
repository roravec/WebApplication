<?php


#[\AllowDynamicProperties]
class DbObj_Event extends DbObj implements ICRUD
{
	public static $TABLE_NAME = "event";

	/* ICRUD interface functions */
	public function Create()
	{
		$query = '
		INSERT INTO 
		'.$this->GetTable().'
		(
			active, image, year, date, name, description, url
		)
		VALUES (
			'.$this->active.', 
			"'.$this->image.'", 
			"'.$this->year.'", 
			"'.$this->date.'", 
			"'.$this->name.'", 
			"'.$this->description.'", 
			"'.$this->url.'"
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
			$this->active = $row["active"];
			$this->image = $row["image"];
			$this->year = $row["year"];
			$this->name = $row["name"];
			$this->date = $row["date"];
			$this->description = $row["description"];
			$this->url = $row["url"];
		}
	}
	public function Update()
	{
		$query = '
		UPDATE
		'.$this->GetTable().'
		SET
		active='.$this->active.',
		image="'.$this->image.'",
		year="'.$this->year.'",
		name="'.$this->name.'",
		date="'.$this->date.'",
		description="'.$this->description.'",
		url="'.$this->url.'"
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
			$selfIns = new DbObj_Event($database, $dbPrefix);
			$selfIns->id = 				$row['id'];
			$selfIns->active = 			$row['active'];
			$selfIns->image = 			$row['image'];
			$selfIns->year = 			$row['year'];
			$selfIns->date = 			$row['date'];
			$selfIns->name = 			$row['name'];
			$selfIns->description = 	$row['description'];
			$selfIns->url = 			$row['url'];

			$all[] = $selfIns;
		}
		return $all;
	}

	/* Database columns  *******************/
	public $id=0;
	public $active;
	public $image="";
	public $year="";
	public $date="";
	public $name="";
	public $description="";
	public $url="";

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