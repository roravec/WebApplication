<?php
/** Interface of the oldest core (core/ICRUD.php) — database objects of ported apps implement it. */
interface ICRUD
{
    public function Create();
    public function Read();
    public function Update();
    public function Delete();
}
