<?php


#[\AllowDynamicProperties]
class DbObj_Ggsgame extends DbObj implements ICRUD
{
	public static $TABLE_NAME = "ggsgame";

	/* ICRUD interface functions */
	public function Create()
	{
		$query = '
		INSERT INTO 
		'.$this->GetTable().'
		(
			ggs, game
		)
		VALUES (
			'.$this->ggs.', 
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
			$this->ggs = $row["ggs"];
			$this->game = $row["game"];
		}
	}
	public function Find($ggs, $game)
	{
		$this->ggs = $ggs;
		$this->game = $game;

		$query = '
		SELECT * FROM
		'.$this->GetTable().'
		WHERE
		ggs='.intval($this->ggs).' 
		AND game='.intval($this->game).' 
		LIMIT 1;
		';
		$result = $this->Database->Query($query);
		if ($this->Database->GetNumRows($result) > 0)
		{
			$row = $result->fetch_assoc();
			$this->ggs = $row["ggs"];
			$this->game = $row["game"];
		}
	}
	public function Update()
	{
		$query = '
		UPDATE
		'.$this->GetTable().'
		SET
		ggs='.$this->ggs.',
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
	public function DeleteOccurences()
	{
		$query = '
		DELETE FROM
		'.$this->GetTable().'
		WHERE ';
		if ($this->ggs > 0) 		$query .= 'ggs='.intval($this->ggs);
		else if ($this->game > 0) $query .= 'game='.intval($this->game);
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
			$selfIns = new DbObj_Ggsgame($database, $dbPrefix);
			$selfIns->id = 				$row['id'];
			$selfIns->ggs = 			$row['ggs'];
			$selfIns->game = 			$row['game'];

			$all[] = $selfIns;
		}
		return $all;
	}

	public function DuplicateExists()
	{
		$query = '
		SELECT * FROM
		'.$this->GetTable().'
		WHERE
		ggs='.intval($this->ggs).' AND game='.intval($this->game).'
		;
		';
		$result = $this->Database->Query($query);
		if ($this->Database->GetNumRows($result) > 0)
		{
			return true;
		}
		return false;
	}

	/* Database columns  *******************/
	public $id=0;
	public $ggs=0;
	public $game=0;

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