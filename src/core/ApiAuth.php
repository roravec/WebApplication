<?php
require_once __DIR__ . '/JwtHelper.php';
require_once __DIR__ . '/../interfaces/IAuth.php';
require_once __DIR__ . '/../entities/Client.php';
require_once __DIR__ . '/../entities/LogEntry.php';
require_once __DIR__ . '/../entities/Token.php';

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

    /**
     * Restores login state from the access token in the Authorization header.
     * @return void
     */
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
                // echo json_encode([
                //     'success' => 'valid token',
                //     'description' => 'The access token is valid.',
                //     'token_data' => $decodedToken
                // ]);
                if ($this->client->read($decodedToken['sub']) && $this->client->status > 0)
                {
                    //echo "Client read successfully. User ID: " . $this->client->id . "<br>";
                    $this->client->setLoginVerified(true);
                    $this->accessToken = $accessToken;
                }
            }
            else
            {
                // echo json_encode([
                //     'error' => 'invalid_token',
                //     'error_description' => 'The access token is invalid or has expired.',
                //     'token_data' => $decodedToken
                // ]);
            }
        }
        else
        {
            //echo "Access token is invalid<br>";
            echo json_encode([
                'error' => 'invalid_token',
                'error_description' => 'The access token is non-existent.'
            ]);
        }

        // save everything to log, mainly all headers, raw message, everything
        $log = new LogEntry($this->rootApplication->getDatabase());
        $log->action = 'auth';
        $log->message = json_encode([
            'headers' => getallheaders(),
            'client_id' => $this->client->id,
            'is_logged_in' => $this->client->getLoginVerified()
        ]);
        $log->create();
    }

    /**
     * Retrieves the access token from the Authorization header.
     * @return string|null The access token, or null if not present.
     */
    function getAccessTokenFromHeader(): ?string
    {
        $headers = getallheaders();

        if (isset($headers['Authorization']) || isset($headers['authorization']))
        {
            if (preg_match('/Bearer\s(\S+)/', $headers['Authorization'] ?? $headers['authorization'], $matches))
            {
                return $matches[1];
            }
        }
        return null;
    }

    /**
     * Retrieves the refresh token from the X-Refresh-Token header.
     * @return string|null The refresh token, or null if not present.
     */
    public function getRefreshTokenFromHeader(): ?string
    {
        $headers = getallheaders();
        if (isset($headers['X-Refresh-Token']) || isset($headers['x-refresh-token']))
        {
            return $headers['X-Refresh-Token'] ?? $headers['x-refresh-token'];
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
            else
            {
                $this->saveAuthorization(true);
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
        //echo "Refresh token from header: $refreshToken<br>";
        //echo "Token from DB: " . json_encode($tokenFromDb) . "<br>";

        if ($tokenFromDb === null)
        {
            //echo "Invalid refresh token<br>";
            return false;
        }
        else
        {
            if ($this->client->getLoginVerified())
            {
                if ($this->client->id !== $tokenFromDb->userid)
                {
                    return false;
                }
            }
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
    public function saveAuthorization($dontGenerateRefreshToken = false): void
    {
        // Generate new access token
        $this->accessToken = $this->client->generateAccessToken($this->rootApplication->getJwtSecret(), $this->rootApplication->getApplicationName());
        // Generate new refresh token and store in database if not already set
        if (!$dontGenerateRefreshToken)
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