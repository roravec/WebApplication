<?php
require_once __DIR__ . '/JwtHelper.php';
require_once __DIR__ . '/../interfaces/IAuth.php';
require_once __DIR__ . '/../entities/Client.php';

/**
 * Class ApiAuth
 *
 * Manages client authentication using JWTs and tokens. Doesn't use sessions.
 */
class ApiAuth implements IAuth
{
    private $rootApplication = null;
    private $client = null;
	public function __construct($rootApplication)
	{
        $this->rootApplication = $rootApplication;
        $token = $this->getAccessTokenFromHeader();
        $this->client = new Client($this->rootApplication->getDatabase());
        if ($token !== null)
        {
            $decodedToken = JwtUtils::decode($token, $this->rootApplication->getJwtSecret());

            if ($decodedToken !== null &&
                isset($decodedToken['sub']) &&
                isset($decodedToken['appid']) &&
                $decodedToken['appid'] === $this->rootApplication->getApplicationName() &&
                !JwtUtils::isExpired($decodedToken))
            {
                if ($this->client->read($decodedToken['sub']) && $this->client->status > 0)
                {
                    $this->client->setLoginVerified(true);
                }
            }
        }
	}

    function getAccessTokenFromHeader(): ?string
    {
        $headers = getallheaders();
        if (isset($headers['Authorization']))
        {
            if (preg_match('/Bearer\s(\S+)/', $headers['Authorization'], $matches))
            {
                return $matches[1];
            }
        }
        return null;
    }

    public function getRefreshTokenFromHeader(): ?string
    {
        $headers = getallheaders();
        if (isset($headers['X-Refresh-Token']))
        {
            return $headers['X-Refresh-Token'];
        }
        return null;
    }

    /**
     * Creates a new access token and refresh token for the authenticated client.
     * @param mixed $identifier
     * @param mixed $secret
     * @param mixed $storeLogin
     * @return bool
     */
    public function login($identifier, $secret, $storeLogin=false) : bool
    {
        $this->client->login($identifier, $secret);
        if ($this->client->getLoginVerified()) // user verified
        {
            if ($storeLogin)
            {
                $this->saveAuthorization($this->client->id, $this->client->identifier);
            }
            return true;
        }
        return false;

        // echo json_encode([
        //     'access_token' => $accessToken,
        //     'refresh_token' => $refreshToken,
        //     'expires_in' => 3600
        // ]);
    }

    /**
     * Refreshes the access token for the authenticated client.
     * @return bool
     */
    public function refresh(): bool
    {
        if (!$this->client->getLoginVerified())
        {
            return false;
        }
        // read refreshToken from request (e.g. Authorization header or body)
        // validate refreshToken
        $refreshToken = $this->getRefreshTokenFromHeader();

        // TODO
        if ($refreshToken === null || !JwtUtils::isValid($refreshToken, $this->rootApplication->getJwtSecret()))
        {
            return false;
        }
        $this->saveAuthorization($this->client->id, $this->client->identifier);
        return true;

        // echo json_encode([
        //     'access_token' => $newAccessToken,
        //     'refresh_token' => $newRefreshToken,
        //     'expires_in' => 3600
        // ]);
    }

    /**
     * Revokes the token for future use.
     * @return bool
     */
    public function logout(): bool
    {
        // For API auth, logout is typically handled client-side by deleting the token.
        // TODO: Implement token revocation.
        return true;
    }
    public function isLoggedIn(): bool
    {
        return $this->client->getLoginVerified();
    }

    /**
     * Saves login state by generating JWT access and refresh tokens.
     * @param int $userID
     * @param string $identifier
     * @return void
     */
    public function saveAuthorization($userID, $identifier): bool
    {
        // TODO

    }

    private $accessToken = null;
    /** Returns a JWT access token for the authenticated client.
     * @return string|null The JWT access token, or null if not logged in.
     */
    public function getAccessToken(): ?string
    {
        if ($this->accessToken === null && $this->client->getLoginVerified())
        {
            $this->accessToken = $this->client->generateAccessToken($this->rootApplication->getJwtSecret());
        }
        return $this->accessToken;
    }

    private $refreshToken = null;
    /** Returns a refresh token for the authenticated client.
     * @return string|null The refresh token, or null if not logged in.
     */
    public function getRefreshToken(): ?string
    {
        if ($this->refreshToken === null && $this->client->getLoginVerified())
        {
            // generates and also stores refresh token in database
            $this->refreshToken = $this->client->generateRefreshToken();
            return $this->refreshToken->token;
        }
        return $this->refreshToken;
    }
}

?>