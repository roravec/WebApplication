<?php
// Interface definition
interface ICrud
{
	public function create() : bool;
	public function read(int $id=0) : bool;
	public function update() : bool;
	public function delete(int $id=0) : bool;
}
?>