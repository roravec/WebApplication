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
        $this->client = new Client($this->rootApplication->getDatabase());
        $this->restoreLoginStateFromAccessToken();
	}

    public function restoreLoginStateFromAccessToken(): void
    {
        $accessToken = $this->getAccessTokenFromHeader();
        if ($accessToken !== null)
        {
            $decodedToken = JwtUtils::decode($accessToken, $this->rootApplication->getJwtSecret());

            if ($decodedToken !== null &&
                isset($decodedToken['sub']) &&
                isset($decodedToken['appid']) &&
                $decodedToken['appid'] === $this->rootApplication->getApplicationName() &&
                !JwtUtils::isExpired($decodedToken))
            {
                if ($this->client->read($decodedToken['sub']) && $this->client->status > 0)
                {
                    $this->client->setLoginVerified(true);
                    $this->accessToken = $accessToken;
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
                $this->saveAuthorization();
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
        // read refreshToken from request (e.g. Authorization header or body)
        // validate refreshToken
        $refreshToken = $this->getRefreshTokenFromHeader();
        $tokenFromDb = Token::validateRefreshToken($this->rootApplication->getDatabase(), $refreshToken);
        if ($tokenFromDb === null || $tokenFromDb->userid !== $this->client->id)
        {
            return false;
        }
        else
        {
            $tokenFromDb->revoked = 1;
            $tokenFromDb->update();
            $this->client = Client::readById($this->rootApplication->getDatabase(), $tokenFromDb->userid);
            $this->saveAuthorization();
            return true;
        }

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
        // revoke provided refresh token in database
        if ($this->client->getLoginVerified())
        {
            $refreshToken = $this->getRefreshTokenFromHeader();
            if ($refreshToken !== null)
            {
                Token::revokeByValue($this->rootApplication->getDatabase(), $refreshToken, $this->client->id);
            }
            $this->client->setLoginVerified(false);
        }
        return true;
    }
    public function isLoggedIn(): bool
    {
        return $this->client->getLoginVerified();
    }

    /**
     * Saves client's login state by generating JWT access and refresh tokens.
     * @return void
     */
    public function saveAuthorization(): void
    {
        // Generate new access token
        $this->accessToken = $this->client->generateAccessToken($this->rootApplication->getJwtSecret());
        // Generate new refresh token and store in database if not already set
        if ($this->getRefreshToken() === null)
        {
            $this->refreshToken = $this->client->generateRefreshToken();
        }
        $this->client->setLoginVerified(true);
    }

    private $accessToken = null;
    /** Returns an access token for the authenticated client.
     * @return string|null The access token, or null if not logged in.
     */
    public function getAccessToken(): ?string
    {
        if ($this->accessToken !== null && $this->client->getLoginVerified())
        {
            return $this->accessToken;
        }
        return null;
    }

    private $refreshToken = null;
    /** Returns a refresh token for the authenticated client.
     * @return string|null The refresh token, or null if not logged in.
     */
    public function getRefreshToken(): ?string
    {
        if ($this->refreshToken !== null && $this->client->getLoginVerified())
        {
            return $this->refreshToken;
        }
        return null;
    }

    /**
     * Returns the authenticated client if logged in.
     * @return Client|null The authenticated client, or null if not logged in.
     */
    public function getClient()
    {
        return $this->client;
    }
}

?>