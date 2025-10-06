<?php
require_once __DIR__ . '/../entities/User.php';

class UserAuth
{
    private $rootApplication = null;
    private $user = null;
	public function __construct($rootApplication)
	{
        $this->rootApplication = $rootApplication;

        $this->user = new User($this->rootApplication->getDatabase());
		$this->checkIfUserIsLoggedIn();
	}


    /* Returns
      1 - OK /  login successful
      0 - NOK / login unsuccessful
    */
	public function login($login, $password, $storeLogin=false) : bool
	{
		$this->user->login($login, $password);
    	if ($this->user->getLoginVerified()) // user verified
    	{
            session_regenerate_id(true);
			$_SESSION['User_Id'] = $this->user->id;
			$_SESSION['User_Login'] = $this->user->login;
			$_SESSION['User_Name'] = $this->user->name;
			$_SESSION['User_AppId'] = $this->rootApplication->getApplicationName();
			$_SESSION['User_Rights'] = $this->user->getRights();
			if ($storeLogin)
			{
				$this->saveLogin($this->user->id, $this->user->login);
			}
			return true;
    	}
    	$this->Logout();
    	return false;
  	}
	private function saveLogin($userID, $login)
	{
		// During login, generate and set a JWT as a secure cookie
		$token = $this->generateJWT($userID, $login); // Implement your token generation logic
		$cookieName = 'auth_token_' . $this->rootApplication->getApplicationName();
        setcookie($cookieName, $token, time() + 86400 * 30, "/", "", true, true);
	}
	private function checkIfUserIsLoggedIn()
	{
		if (
			isset($_SESSION["User_Id"]) 
		&& isset($_SESSION["User_Login"]) 
		&& isset($_SESSION['User_Rights'])
		&& isset($_SESSION['User_AppId'])
		&& isset($_SESSION['User_Name'])
		)
		{
			$this->user->manualLogin(
				$_SESSION["User_Id"],
				$_SESSION["User_Login"],
				$_SESSION['User_Name'],
				$_SESSION['User_Rights']
			);
		}
		else if (isset($_COOKIE['auth_token'])) 
		{
			$token = $_COOKIE['auth_token'];
    		$userData = $this->validateJWT($token);
			if ($userData !== null) 
			{
				// User is authenticated
				// Access the user data
				$userId = $userData['userId'];
				$username = $userData['username'];
				$this->user->read($userId);

				$_SESSION['User_Id'] = $this->user->id;
				$_SESSION['User_Login'] = $this->user->login;
				$_SESSION['User_Name'] = $this->user->name;
				$_SESSION['User_AppId'] = $this->rootApplication->getApplicationName();
				$_SESSION['User_Rights'] = $this->user->getRights();
				$this->user->manualLogin(
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
  	public function logout()
  	{
		$this->user->logout();
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
  	}

  	public function isLoggedIn()
  	{
		return $this->user->getLoginVerified();
  	}

    private function getJwtSecret() : string
    {
        $config = require __DIR__ . '/../config/JwtSecret.php';
        return $config['jwt_secret'];
    }

    // Function to encode user data as a JWT
	private function generateJWT($userId, $username) 
	{
		$header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
		$payload = json_encode([
            'userId' => $userId,
            'username' => $username,
            'appid' => $this->rootApplication->getApplicationName(),
            'exp' => time() + 60 * 60 * 24 * 30
        ]);

		// Base64 URL encode the header and payload
		$base64UrlHeader = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
		$base64UrlPayload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($payload));

		// Create the signature using HMAC and SHA-256
		$signature = hash_hmac('sha256', $base64UrlHeader . '.' . $base64UrlPayload, $this->getJwtSecret(), true);
		$base64UrlSignature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));

		// Combine the base64 URL encoded components to form the JWT
		$jwt = $base64UrlHeader . '.' . $base64UrlPayload . '.' . $base64UrlSignature;

		return $jwt;
	}

// Function to decode user data from a JWT
	private function validateJWT($token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null; // Malformed token
        }

        list($base64UrlHeader, $base64UrlPayload, $base64UrlSignature) = $parts;

        // Decode components
        $payload = base64_decode(str_pad(strtr($base64UrlPayload, '-_', '+/'), strlen($base64UrlPayload) % 4, '=', STR_PAD_RIGHT));
        $signature = base64_decode(str_pad(strtr($base64UrlSignature, '-_', '+/'), strlen($base64UrlSignature) % 4, '=', STR_PAD_RIGHT));
        $expectedSignature = hash_hmac('sha256', $base64UrlHeader . '.' . $base64UrlPayload, $this->getJwtSecret(), true);

        if (!hash_equals($signature, $expectedSignature)) {
            return null; // Invalid signature
        }

        // Decode payload
        $decodedData = json_decode($payload, true);
        if (!is_array($decodedData)) {
            return null; // Invalid payload
        }
        if ($decodedData['appid'] !== $this->rootApplication->getApplicationName())
        {
            return null; // Token is for a different app
        }

        // Check expiration
        if (isset($decodedData['exp']) && time() > $decodedData['exp']) {
            return null; // Token expired
        }

        return $decodedData;
    }
}

?>