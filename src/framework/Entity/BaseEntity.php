<?php
namespace WebApplication\Entity;

use WebApplication\Database\Database;

abstract class BaseEntity
{
    /** @var string table name without prefix — set in the child class */
    protected static $TABLE_NAME = '';

    /** @var Database */
    protected $database;

    public function __construct(Database $database)
    {
        $this->database = $database;
    }

    public function getTableName(): string
    {
        return $this->database->getPrefix() . static::$TABLE_NAME;
    }

    protected static function tableOf(Database $db): string
    {
        return $db->table(static::$TABLE_NAME);
    }
}
