<?php
namespace WebApplication\Legacy;

use WebApplication\Application;
use WebApplication\Session;

/**
 * The API of the OLDEST core (StadiumControl/WebServer/webapp/core/Application.php) on top of the framework, for apps
 * ported from WebApp_<name> (hbkpravotice, bnhl). Those apps don't implement get()/post(): the old core called
 *   $webApp->SetInitVariables($application, $appId, $appPath, $database, $languageCode); echo $webApp->GetHTML($page);
 * and the app used $application->GetTextFromDatabase / GetDatabase / GetDbPrefix / GetContentFilename / Login / Logout /
 * IsLoggedIn / GetUser. This class is that $application:
 *   - database = the framework's LegacyDatabase (same mysqli-like API, values as strings, shared connection);
 *   - texts = Application::getTextFromDatabase (<prefix>text, appid = app name);
 *   - page = path segments exactly as the old DetectPage() / GetContentFilename();
 *   - users = the app's own table <prefix>users with the old global class User (oldcore/User.php), login kept in the
 *     framework session (own cookie per app) + "remember me" cookie auth_token, signed with the framework's jwtSecret
 *     (the old core had the key in the source code and never checked the expiry — old cookies are no longer valid,
 *     users log in once more).
 * The app path given to the app is the public folder /applications/<folder> (assets), no longer a relative path.
 */
class OldCoreApplication
{
    const REMEMBER_COOKIE = 'auth_token';
    const REMEMBER_DAYS = 30;

    /** @var string app id as in the old core = app name in the config */
    public $ApplicationId = '';
    /** @var bool the old core printed debug output when true — never on */
    public $Debug = false;

    /** @var Application */ private $app;
    /** @var \User */ private $user;
    /** @var string old "AppEntryPath": the host name (GetContentFilename compares the last segment with it) */
    private $entryPath;

    /** Interfaces / classes of the old core the ported app files refer to (load BEFORE the app's own files). */
    public static function loadOldCore(): void
    {
        require_once __DIR__ . '/oldcore/ICRUD.php';
        require_once __DIR__ . '/oldcore/User.php';
    }

    /**
     * Run an old page builder the way the old core did; returns the page.
     * @param string|null $appPath path given to the app for its assets: default the public folder "/applications/<folder>";
     *        an app that puts "/" in front itself gets it without the leading slash (as the old relative "WebApp_x")
     */
    public static function run(Application $app, $webApp, string $language = 'en', ?string $appPath = null): string
    {
        $core = new self($app);
        $definition = $app->getDefinition();
        if ($appPath === null) {
            $appPath = $definition ? $definition->publicPath() : '';
        }
        $webApp->SetInitVariables($core, $core->ApplicationId, $appPath, $core->GetDatabase(), $language);
        return (string)$webApp->GetHTML($core->DetectPage());
    }

    public function __construct(Application $app)
    {
        self::loadOldCore();
        $this->app = $app;
        $this->ApplicationId = $app->getApplicationName();
        $this->entryPath = $app->getRequest()->host();
        Session::start($this->ApplicationId, $app->getRequest()->isHttps());
        $this->user = new \User($this->GetDatabase(), $this->GetDbPrefix());
        $this->restoreLogin();
    }

    // ------------------------------------------------------------------ the old API

    /** Path segments as the old core: "/a/b/" → ['a', 'b'], "/" → [''] */
    public function DetectPage()
    {
        $path = ltrim($this->app->getRequest()->appPath(), '/');
        return explode('/', rtrim($path, "/\\"));
    }

    /** Same rules as the old core: last segment = page; a segment with a dot (file) or the host name → default page. */
    public function GetContentFilename($path, $defaultPage)
    {
        if (!is_array($path) || count($path) === 0) {
            return $defaultPage;
        }
        $last = $path[count($path) - 1];
        return (strpos((string)$last, '.') !== false || $this->entryPath == $last) ? $defaultPage : $last;
    }

    public function GetTextFromDatabase($language, $textIdentifier)
    {
        return $this->app->getTextFromDatabase((string)$language, (string)$textIdentifier);
    }

    public function GetDatabase()
    {
        return $this->app->getLegacyDatabase();
    }

    public function GetDbPrefix()
    {
        return $this->app->GetDbPrefix();
    }

    public function EscapeString($inString)
    {
        return $this->GetDatabase()->EscapeString($inString);
    }

    /** @return int 1 = logged in, 0 = wrong login / password (as the old core) */
    public function Login($login, $password, $storeLogin = false)
    {
        $this->user->Login($login, $password);
        if ($this->user->GetLoginVerified()) {
            Session::regenerate();   // new session id after login (session fixation)
            $this->remember();
            if ($storeLogin) {
                $this->setRememberCookie((int)$this->user->id);
            }
            return 1;
        }
        $this->Logout();
        return 0;
    }

    public function Logout()
    {
        $this->user->Logout();
        foreach (['User_Id', 'User_Login', 'User_Name', 'User_Rights'] as $k) {
            Session::remove($k);
        }
        Session::destroy();
        $this->cookie('', time() - 3600);
    }

    public function IsLoggedIn()
    {
        return $this->user->GetLoginVerified();
    }

    public function GetUser()
    {
        return $this->user;
    }

    // ------------------------------------------------------------------ login state

    /**
     * Session or "remember me" cookie → logged-in user. Unlike the old core the user is read from the database on
     * every request, so a deleted user / changed rights take effect at once.
     */
    private function restoreLogin(): void
    {
        $id = (int)Session::get('User_Id', 0);
        if (!$id) {
            $id = $this->rememberedUserId();
        }
        if (!$id) {
            return;
        }
        $this->user->id = $id;
        $this->user->Read();
        if ((string)$this->user->login === '') {   // user no longer exists
            $this->Logout();
            return;
        }
        $this->user->ManualLogin($this->user->id, $this->user->login, $this->user->name, $this->user->rights);
        $this->remember();
    }

    private function remember(): void
    {
        Session::set('User_Id', $this->user->id);
        Session::set('User_Login', $this->user->login);
        Session::set('User_Name', $this->user->name);
        Session::set('User_Rights', $this->user->GetRightsLevel());
    }

    private function secret(): string
    {
        return $this->app->getJwtSecret();
    }

    private function setRememberCookie(int $userId): void
    {
        if (strlen($this->secret()) < 16) {
            $this->app->getLogger()->warning('Remember-me login not stored: jwtSecret is not configured');
            return;
        }
        $exp = time() + 86400 * self::REMEMBER_DAYS;
        $this->cookie(self::rememberToken($userId, $this->ApplicationId, $this->secret(), $exp), $exp);
    }

    private function rememberedUserId(): int
    {
        return self::rememberedId((string)$this->app->getRequest()->cookie(self::REMEMBER_COOKIE, ''), $this->ApplicationId, $this->secret());
    }

    /** Signed "remember me" token: base64url(JSON user, app, expiry) . "." . base64url(HMAC-SHA256). */
    public static function rememberToken(int $userId, string $appId, string $secret, int $expires): string
    {
        $payload = self::b64((string)json_encode(['u' => $userId, 'a' => $appId, 'exp' => $expires]));
        return $payload . '.' . self::b64(hash_hmac('sha256', $payload, $secret, true));
    }

    /** User id from a token — 0 when the signature, the app or the expiry don't fit (also every old-core token). */
    public static function rememberedId(string $token, string $appId, string $secret): int
    {
        if ($token === '' || strlen($secret) < 16 || substr_count($token, '.') !== 1) {
            return 0;
        }
        list($payload, $sig) = explode('.', $token);
        if (!hash_equals(self::b64(hash_hmac('sha256', $payload, $secret, true)), $sig)) {
            return 0;
        }
        $data = json_decode((string)base64_decode(strtr($payload, '-_', '+/')), true);
        if (!is_array($data) || ($data['a'] ?? '') !== $appId || (int)($data['exp'] ?? 0) < time()) {
            return 0;
        }
        return (int)($data['u'] ?? 0);
    }

    private function cookie(string $value, int $expires): void
    {
        if (headers_sent() || PHP_SAPI === 'cli') {
            return;
        }
        $secure = $this->app->getRequest()->isHttps();
        if (PHP_VERSION_ID >= 70300) {
            setcookie(self::REMEMBER_COOKIE, $value, ['expires' => $expires, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
        } else {
            setcookie(self::REMEMBER_COOKIE, $value, $expires, '/', '', $secure, true);
        }
    }

    private static function b64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
