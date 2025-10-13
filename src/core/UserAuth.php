<?php
require_once __DIR__ . '/JwtHelper.php';
require_once __DIR__ . '/../interfaces/IAuth.php';
require_once __DIR__ . '/../entities/Client.php';

/**
 * Class UserAuth
 * 
 * Manages user authentication using JWTs and sessions.
 */
class UserAuth implements IAuth
{
    private $rootApplication = null;
    private $client = null;
	public function __construct($rootApplication)
	{
        $this->rootApplication = $rootApplication;

        $this->client = new Client($this->rootApplication->getDatabase());
		$this->restoreLoginState();
	}


    /* Returns
      1 - OK /  login successful
      0 - NOK / login unsuccessful
    */
	public function login($login, $password, $storeLogin=false) : bool
	{
		$this->client->login($login, $password);
    	if ($this->client->getLoginVerified()) // user verified
    	{
            session_regenerate_id(true);
			$_SESSION['User_Id'] = $this->client->id;
			$_SESSION['User_Login'] = $this->client->identifier;
			$_SESSION['User_Name'] = $this->client->name;
			$_SESSION['User_AppId'] = $this->rootApplication->getApplicationName();
			$_SESSION['User_Rights'] = $this->client->getRights();
			if ($storeLogin)
			{
				$this->saveAuthorization($this->client->id, $this->client->identifier);
			}
			return true;
    	}
    	$this->Logout();
    	return false;
  	}

    /**
     * Saves login state by generating a JWT and setting it as a secure cookie.
     * @param int $userID
     * @param string $login
     * @return void
     */
	private function saveAuthorization($userID, $login): void
	{
        $payload = [
            'userId' => $userID,
            'username' => $login,
            'appid' => $this->rootApplication->getApplicationName(),
            'exp' => time() + 86400 * 30
        ];
		// During login, generate and set a JWT as a secure cookie
		$token = JwtUtils::encode($payload, $this->getJwtSecret());
		$cookieName = 'auth_token_' . $this->rootApplication->getApplicationName();
        setcookie($cookieName, $token, time() + 86400 * 30, "/", "", true, true);
	}

    public function refresh(): bool
    {
        if (!$this->client->getLoginVerified())
        {
            return false;
        }
        $this->saveAuthorization($this->client->id, $this->client->identifier);
        return true;
    }

    /**
     * Checks if user is logged in by verifying session or JWT token.
     * If valid, restores user state.
     * @return void
     */
	private function restoreLoginState(): void
	{
		if (
			isset($_SESSION["User_Id"]) 
		&& isset($_SESSION["User_Login"]) 
		&& isset($_SESSION['User_Rights'])
		&& isset($_SESSION['User_AppId'])
		&& isset($_SESSION['User_Name'])
		)
		{
			$this->client->manualLogin(
				$_SESSION["User_Id"],
				$_SESSION["User_Login"],
				$_SESSION['User_Name'],
				$_SESSION['User_Rights']
			);
		}
		else if (isset($_COOKIE['auth_token'])) 
		{
			$token = $_COOKIE['auth_token'];
    		$userData = JwtUtils::decode($token, $this->getJwtSecret());
			if ($userData !== null) 
			{
				// User is authenticated
				// Access the user data
				$userId = $userData['userId'];
				$username = $userData['username'];
				$appId = $userData['appid'];
                $expiration = $userData['exp'];
                if ($appId !== $this->rootApplication->getApplicationName() || $expiration < time()) {
                    // Invalid app ID or token expired
                    return;
                }
				$this->client->read($userId);
                if ($this->client->id <= 0)
                {
                    // User not found
                    return;
                }
                else if ($this->client->status <= 0)
                {
                    // User inactive
                    return;
                }
				$_SESSION['User_Id'] = $this->client->id;
				$_SESSION['User_Login'] = $this->client->identifier;
				$_SESSION['User_Name'] = $this->client->name;
				$_SESSION['User_AppId'] = $this->rootApplication->getApplicationName();
				$_SESSION['User_Rights'] = $this->client->getRights();
				$this->client->manualLogin(
					$_SESSION["User_Id"],
					$_SESSION["User_Login"],
					$_SESSION['User_Name'],
					$_SESSION['User_Rights']
				);
			} 
			else 
			{
				// user is not authenticated
			}
		} 
		else 
		{
			// user is not authenticated
		}
	}
    /**
     * Destroys session and clears authentication cookies.
     * @return void
     */
    public function logout(): bool
  	{
		$this->client->logout();
    	unset($_SESSION['User_Id']);
		unset($_SESSION['User_Login']);
		unset($_SESSION['User_Name']);
		unset($_SESSION['User_AppId']);
		unset($_SESSION['User_Rights']);

		// Destroy the session
		$_SESSION = [];
        session_destroy();

		// Clear the client-side token (if stored in a cookie)
		setcookie('auth_token', '', time() - 3600, '/', '', true, true);
        return true;
  	}

    /**
     * Checks if user is logged in.
     * @return bool True if logged in, false otherwise.
     */
  	public function isLoggedIn(): bool
  	{
		return $this->client->getLoginVerified();
  	}

    /**
     * Retrieves the JWT secret from configuration.
     * @return string The JWT secret key.
     */
    private function getJwtSecret() : string
    {
        $config = require __DIR__ . '/../config/JwtSecret.php';
        return $config['jwt_secret'];
    }
}

?>