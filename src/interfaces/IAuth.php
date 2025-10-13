<?php
// Interface definition
interface IAuth
{
    public function login(string $identifier, string $secret): bool;
    public function refresh(): bool;
    public function logout(): bool;
    public function isLoggedIn(): bool;
}
?>