<?php
namespace WebApplication\Auth;

use WebApplication\Entity\Client;

interface IAuth
{
    public function login(string $identifier, string $secret, bool $storeLogin = false): bool;
    public function refresh(): bool;
    public function logout(): bool;
    public function isLoggedIn(): bool;
    /** The logged-in client (user or device), or null. */
    public function getClient(): ?Client;
}
