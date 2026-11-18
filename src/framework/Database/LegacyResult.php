<?php
namespace WebApplication\Database;

/** Behaves like a mysqli_result for the methods the old apps use. */
class LegacyResult
{
    /** @var int */
    public $num_rows;
    /** @var array */
    private $rows;
    /** @var int */
    private $pos = 0;

    public function __construct(array $rows)
    {
        $this->rows = array_values($rows);
        $this->num_rows = count($this->rows);
    }

    /** @return array|null */
    public function fetch_assoc()
    {
        return $this->pos < $this->num_rows ? $this->rows[$this->pos++] : null;
    }

    /** @return array|null */
    public function fetch_row()
    {
        $row = $this->fetch_assoc();
        return $row === null ? null : array_values($row);
    }

    /** @return array|null both numeric and named keys, like MYSQLI_BOTH */
    public function fetch_array()
    {
        $row = $this->fetch_assoc();
        return $row === null ? null : array_merge(array_values($row), $row);
    }

    /** @return object|null */
    public function fetch_object()
    {
        $row = $this->fetch_assoc();
        return $row === null ? null : (object)$row;
    }

    public function fetch_all()
    {
        return array_map('array_values', $this->rows);
    }

    public function data_seek($offset)
    {
        if ($offset < 0 || $offset >= $this->num_rows) {
            return false;
        }
        $this->pos = (int)$offset;
        return true;
    }

    public function free() {}
    public function close() {}
}
