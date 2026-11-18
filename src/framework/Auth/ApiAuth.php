<?php
namespace WebApplication\Auth;

use WebApplication\Application;
use WebApplication\Entity\Client;
use WebApplication\Entity\LogEntry;
use WebApplication\Entity\Token;

/**
 * Login for devices / API clients, no session:
 *   - short-lived access token (JWT) in "Authorization: Bearer ..."
 *   - long-lived refresh token (random, stored in <prefix>token) in "X-Refresh-Token"
 */
class ApiAuth implements IAuth
{
    /** @var Application */
    private $app;
    /** @var Client|null */
    private $client = null;
    /** @var string|null */
    private $accessToken = null;
    /** @var string|null */
    private $refreshToken = null;

    public function __construct(Application $app)
    {
        $this->app = $app;
        $this->restoreLoginStateFromAccessToken();
    }

    public function restoreLoginStateFromAccessToken(): void
    {
        $token = $this->getAccessTokenFromHeader();
        if ($token !== null) {
            $payload = Jwt::decode($token, $this->app->getJwtSecret());
            if ($payload !== null && isset($payload['sub'])
                && ($payload['appid'] ?? '') === $this->app->getApplicationName()) {
                $client = Client::readById($this->app->getDatabase(), (int)$payload['sub']);
                if ($client !== null && $client->isActive()) {
                    $client->setLoginVerified(true);
                    $this->client = $client;
                    $this->accessToken = $token;
                }
            }
        }
        $this->audit('auth', $token !== null);
    }

    public function getAccessTokenFromHeader(): ?string
    {
        return $this->app->getRequest()->bearerToken();
    }

    public function getRefreshTokenFromHeader(): ?string
    {
        $value = $this->app->getRequest()->header('x-refresh-token');
        return is_string($value) && $value !== '' ? $value : null;
    }

    /** Checks identifier + secret; on success issues an access token (and a refresh token if $storeLogin). */
    public function login(string $identifier, string $secret, bool $storeLogin = false): bool
    {
        $client = Client::readByIdentifierAndSecret($this->app->getDatabase(), $identifier, $secret);
        if ($client === null || !$client->isActive()) {
            $this->audit('login-failed', true, $identifier);
            return false;
        }
        $this->client = $client;
        $this->saveAuthorization(!$storeLogin);
        $client->touch();
        return true;
    }

    /** Exchanges a valid refresh token (X-Refresh-Token) for a new access + refresh token (rotation). */
    public function refresh(): bool
    {
        $value = $this->getRefreshTokenFromHeader();
        if ($value === null) {
            return false;
        }
        $db = $this->app->getDatabase();
        $token = Token::validateRefreshToken($db, $value);
        if ($token === null) {
            return false;
        }
        if ($this->client !== null && $this->client->getId() !== (int)$token->userid) {
            return false;
        }
        $client = Client::readById($db, (int)$token->userid);
        if ($client === null || !$client->isActive()) {
            return false;
        }
        $token->revoke();
        $this->client = $client;
        $this->saveAuthorization();
        return true;
    }

    public function logout(): bool
    {
        if ($this->client !== null) {
            $value = $this->getRefreshTokenFromHeader();
            if ($value !== null) {
                Token::revokeByValue($this->app->getDatabase(), $value, $this->client->getId());
            }
        }
        $this->client = null;
        $this->accessToken = null;
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

    public function saveAuthorization(bool $dontGenerateRefreshToken = false): void
    {
        $this->accessToken = $this->client->generateAccessToken($this->app->getJwtSecret(), $this->app->getApplicationName());
        if (!$dontGenerateRefreshToken) {
            $this->refreshToken = $this->client->generateRefreshToken();
        }
        $this->client->setLoginVerified(true);
    }

    public function getAccessToken(): ?string
    {
        return $this->client !== null ? $this->accessToken : null;
    }

    public function getRefreshToken(): ?string
    {
        return $this->client !== null ? $this->refreshToken : null;
    }

    /** Typical JSON body after login/refresh. */
    public function tokenResponseData(): array
    {
        return [
            'access_token' => $this->getAccessToken(),
            'refresh_token' => $this->getRefreshToken(),
            'expires_in' => Client::$ACCESS_TOKEN_VALID_FOR,
        ];
    }

    /** Optional audit log into <prefix>log (config 'logApiAuth'). Never stores tokens or secrets. */
    private function audit(string $action, bool $tokenSent, string $identifier = ''): void
    {
        if (!$this->app->getConfig()->get('logApiAuth')) {
            return;
        }
        $req = $this->app->getRequest();
        $log = new LogEntry($this->app->getDatabase());
        $log->action = $action;
        $log->userId = $this->client ? $this->client->getId() : 0;
        $log->clientIp = $req->ip();
        $log->status = $this->client ? 1 : 0;
        $log->message = json_encode([
            'path' => $req->path(),
            'method' => $req->method(),
            'user_agent' => $req->header('user-agent'),
            'token_sent' => $tokenSent,
            'identifier' => $identifier,
        ]);
        $log->create();
    }
}
