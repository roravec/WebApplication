<?php
class BaseEntity
{
    protected $database;
    public function __construct($database)
    {
        $this->database = $database;
    }
    public function getTableName(): string
    {
        return $this->database->getPrefix() . static::$TABLE_NAME;
    }
}
?>