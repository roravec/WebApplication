<?php
// Interface definition
interface IDatabase
{
    /**
     * Closes database connection.
     * @return void
     */
	public function close() : void;

    /**
     * Gets table prefix.
     * @return string
     */
	public function getPrefix() : string;

    /**
     * Sets table prefix.
     * @return string
     */
	public function setPrefix(string $prefix='') : void;

    /**
     * Executes a SELECT query and returns all rows.
     * @param string $sql
     * @param array $params
     * @return array
     */
	public function query(string $query, array $params = []) : array;

    /**
     * Executes a SELECT query and returns a single row.
     * @param string $sql
     * @param array $params
     * @return array|false
     */
	public function fetchOne(string $query, array $params = []);

    /**
     * Executes an INSERT, UPDATE, or DELETE query.
     * @param string $sql
     * @param array $params
     * @return bool
     */
	public function execute(string $query, array $params = []) : bool;
	
    /**
     * Returns the last inserted ID.
     * @return int
     */
    public function lastInsertID() : int;

    /**
     * Returns the number of rows from a SELECT query.
     * @param string $sql
     * @param array $params
     * @return int
     */
    public function getNumRows(string $query, array $params = []) : int;
}
?>