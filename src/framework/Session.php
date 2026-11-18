<?php
namespace WebApplication;

/**
 * PHP session with safe cookie settings, started only for apps that need it (auth 'user').
 * Each app gets its own session cookie, so apps on the same host don't share logins.
 */
class Session
{
    public static function start(string $appName, bool $https): void
    {
        if (session_status() === PHP_SESSION_ACTIVE || PHP_SAPI === 'cli') {
            return;
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_name('WASID_' . preg_replace('/[^A-Za-z0-9]/', '', $appName));
        $params = ['lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true];
        if (PHP_VERSION_ID >= 70300) {
            $params['samesite'] = 'Lax';
            session_set_cookie_params($params);
        } else {
            session_set_cookie_params(0, '/', '', $https, true);
        }
        session_start();
    }

    public static function get(string $key, $default = null)
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function set(string $key, $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /** One-time message for the next page ("Invoice saved"). */
    public static function flash(string $key, ?string $message = null): ?string
    {
        if ($message !== null) {
            $_SESSION['_flash'][$key] = $message;
            return null;
        }
        $value = $_SESSION['_flash'][$key] ?? null;
        unset($_SESSION['_flash'][$key]);
        return $value;
    }

    public static function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public static function destroy(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        $_SESSION = [];
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        session_destroy();
    }
}
