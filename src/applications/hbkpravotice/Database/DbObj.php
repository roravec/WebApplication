<?php

#[\AllowDynamicProperties]
class DbObj
{
	/* Private  ****************************/
	public $Database;
	private $DbPrefix;
	private $TableName;
	public $ReadableIdentifier = "";

	public function __construct($database, $tableName, $dbPrefix="")
	{
		$this->Database = $database;
		$this->TableName = $tableName;
		$this->DbPrefix = $dbPrefix;
	}

	public function GetTable()
	{
		return $this->DbPrefix.$this->TableName;
	}

	public static function GetTableName($dbPrefix="", $tableName= "")
	{
		return $dbPrefix.$tableName;
	}

	public function GetName()
	{
		return $this->ReadableIdentifier;
	}
	
}

?>