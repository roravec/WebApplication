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

        // $accessToken = $client->generateAccessToken($jwtSecret);
        // $refreshToken = $client->generateRefreshToken()->token;

        // echo json_encode([
        //     'access_token' => $accessToken,
        //     'refresh_token' => $refreshToken,
        //     'expires_in' => 3600
        // ]);
    }
    public function refresh(): bool
    {
        if (!$this->client->getLoginVerified()) {
            return false;
        }
        $this->saveAuthorization($this->client->id, $this->client->identifier);
        return true;

        // $accessToken = $client->generateAccessToken($jwtSecret);
        // echo json_encode([
        //     'access_token' => $newAccessToken,
        //     'refresh_token' => $newRefreshToken,
        //     'expires_in' => 3600
        // ]);
    }
    public function logout(): bool
    {
        // For API auth, logout is typically handled client-side by deleting the token.
        // Optionally, you could implement token revocation here.
        return true;
    }
    public function isLoggedIn(): bool
    {
        $cookieName = 'ApiAuth_' . $this->rootApplication->getApplicationName();
        if (isset($_COOKIE[$cookieName]))
        {
            $token = $_COOKIE[$cookieName];
            $payload = JwtHelper::validateToken($token, $this->rootApplication->getJwtSecret());
            if ($payload !== null &&
                isset($payload['userId']) &&
                isset($payload['username']) &&
                isset($payload['appid']) &&
                $payload['appid'] === $this->rootApplication->getApplicationName())
            {
                // Token is valid, restore client state
                $this->client->manualLogin(
                    $payload['userId'],
                    $payload['username'],
                    '', // Name not stored in token
                    0,  // Rights not stored in token
                    0,  // Type not stored in token
                    0   // Status not stored in token
                );
                return true;
            }
        }
        return false;
    }

    public function saveAuthorization($userID, $login): void
    {
    }
}

?>