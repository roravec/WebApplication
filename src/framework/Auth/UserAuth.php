<?php
namespace WebApplication\Auth;

use WebApplication\Application;
use WebApplication\Entity\Client;
use WebApplication\Session;

/**
 * Login for people in a browser: PHP session (per app) + optional "remember me" cookie (signed JWT).
 * The user is re-read from the database on every request, so a deactivated user is logged out at once.
 */
class UserAuth implements IAuth
{
    const REMEMBER_DAYS = 30;

    /** @var Application */
    private $app;
    /** @var Client|null */
    private $client = null;

    public function __construct(Application $app)
    {
        $this->app = $app;
        $this->restore();
    }

    public function login(string $identifier, string $secret, bool $storeLogin = false): bool
    {
        $client = Client::readByIdentifierAndSecret($this->app->getDatabase(), $identifier, $secret);
        if ($client === null || !$client->isActive()) {
            // only forget the user — keep the session (CSRF token) so the form can be submitted again
            $this->client = null;
            Session::remove('wa_user');
            return false;
        }
        Session::regenerate();
        $this->setSessionUser($client);
        if ($storeLogin) {
            $this->setRememberCookie($client);
        }
        $client->touch();
        return true;
    }

    public function refresh(): bool
    {
        if ($this->client === null) {
            return false;
        }
        $this->setRememberCookie($this->client);
        return true;
    }

    public function logout(): bool
    {
        $this->client = null;
        Session::remove('wa_user');
        Session::destroy();
        $this->setCookie('', time() - 3600);
        return true;
    }

    public function isLoggedIn(): bool
    {
        return $this->client !== null;
    }

    public function getClient(): ?Client
    {
        return $this->client;
    }

    /** True if logged in with at least $level rights. */
    public function hasRights(int $level): bool
    {
        return $this->client !== null && $this->client->getRights() >= $level;
    }

    private function restore(): void
    {
        $sess = Session::get('wa_user');
        if (is_array($sess) && ($sess['app'] ?? '') === $this->app->getApplicationName()) {
            $client = Client::readById($this->app->getDatabase(), (int)$sess['id']);
            if ($client !== null && $client->isActive()) {
                $client->setLoginVerified(true);
                $this->client = $client;
                return;
            }
            Session::remove('wa_user');
        }

        $token = $this->app->getRequest()->cookie($this->cookieName());
        if (!is_string($token) || $token === '') {
            return;
        }
        $payload = Jwt::decode($token, $this->app->getJwtSecret());
        if ($payload === null || ($payload['typ'] ?? '') !== 'remember'
            || ($payload['appid'] ?? '') !== $this->app->getApplicationName()) {
            $this->setCookie('', time() - 3600);
            return;
        }
        $client = Client::readById($this->app->getDatabase(), (int)($payload['sub'] ?? 0));
        if ($client === null || !$client->isActive()) {
            $this->setCookie('', time() - 3600);
            return;
        }
        Session::regenerate();
        $this->setSessionUser($client);
    }

    private function setSessionUser(Client $client): void
    {
        $client->setLoginVerified(true);
        $this->client = $client;
        Session::set('wa_user', ['id' => $client->getId(), 'app' => $this->app->getApplicationName()]);
    }

    private function setRememberCookie(Client $client): void
    {
        $exp = time() + 86400 * self::REMEMBER_DAYS;
        $token = Jwt::encode([
            'typ' => 'remember',
            'sub' => $client->getId(),
            'appid' => $this->app->getApplicationName(),
            'exp' => $exp,
        ], $this->app->getJwtSecret());
        $this->setCookie($token, $exp);
    }

    private function cookieName(): string
    {
        return 'wa_remember_' . preg_replace('/[^A-Za-z0-9_]/', '', $this->app->getApplicationName());
    }

    private function setCookie(string $value, int $expires): void
    {
        if (headers_sent() || PHP_SAPI === 'cli') {
            return;
        }
        setcookie($this->cookieName(), $value, [
            'expires' => $expires,
            'path' => '/',
            'secure' => $this->app->getRequest()->isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
