<?php
namespace WebApplication;

/**
 * Loads the configuration.
 *
 * Preferred: one file config/config.php (see config/config.template.php).
 * Fallback (servers not migrated yet): the old trio ApplicationConfig.php + DatabaseConfig.php + JwtSecret.php.
 */
class Config
{
    /** @var array */
    private $data;

    public function __construct(array $data)
    {
        $this->data = self::normalize($data);
    }

    public static function load(string $dir = WA_CONFIG): self
    {
        // dev/CI: path to a config file outside the web root
        $envFile = getenv('WA_CONFIG_FILE');
        $file = is_string($envFile) && $envFile !== '' ? $envFile : $dir . '/config.php';
        if (is_file($file)) {
            $data = require $file;
            if (!is_array($data)) {
                throw new \RuntimeException("Config file $file must return an array");
            }
            return new self($data);
        }
        if (is_file($dir . '/ApplicationConfig.php')) {
            return new self(self::fromLegacyFiles($dir));
        }
        throw new \RuntimeException('No configuration found: create config/config.php from config/config.template.php');
    }

    /** Dot-path access, e.g. get('database.host'). */
    public function get(string $key, $default = null)
    {
        $value = $this->data;
        foreach (explode('.', $key) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }
        return $value;
    }

    public function all(): array
    {
        return $this->data;
    }

    /** Old split config files → new structure. */
    public static function fromLegacyFiles(string $dir): array
    {
        $apps = require $dir . '/ApplicationConfig.php';
        $db = is_file($dir . '/DatabaseConfig.php') ? require $dir . '/DatabaseConfig.php' : [];
        $jwt = is_file($dir . '/JwtSecret.php') ? require $dir . '/JwtSecret.php' : [];

        $debug = !empty($apps['debugEnabled']);
        unset($apps['debugEnabled']);
        foreach ($apps as $name => $app) {
            if (!is_array($app)) {
                unset($apps[$name]);
                continue;
            }
            if (!isset($app['auth'])) {
                $apps[$name]['auth'] = $app['clientAuth'] ?? (!empty($app['userEnable']) ? 'user' : 'none');
            }
        }
        return [
            'debug' => $debug,
            'database' => [
                'host' => $db['dbHost'] ?? 'localhost',
                'name' => $db['dbName'] ?? '',
                'user' => $db['dbUser'] ?? '',
                'password' => $db['dbPass'] ?? '',
                'port' => $db['dbPort'] ?? 3306,
            ],
            'jwtSecret' => $jwt['jwt_secret'] ?? '',
            'routes' => [],          // legacy: app chosen from the first part of the host name
            'apps' => $apps,
        ];
    }

    private static function normalize(array $data): array
    {
        $defaults = [
            'debug' => false,
            'timezone' => 'Europe/Bratislava',
            'database' => ['host' => 'localhost', 'name' => '', 'user' => '', 'password' => '', 'port' => 3306, 'charset' => 'utf8mb4'],
            'jwtSecret' => '',
            'routes' => [],
            'devPathSelect' => false,
            'logApiAuth' => false,
            'applicationsDir' => WA_APPLICATIONS,
            'apps' => [],
        ];
        $data = array_replace($defaults, $data);
        $data['database'] = array_replace($defaults['database'], (array)$data['database']);
        foreach ($data['apps'] as $name => $app) {
            $data['apps'][$name] = array_replace([
                'folder' => $name,
                'file' => 'WebApp.php',
                'class' => 'WebApp',
                'dbPrefix' => $name . '_',
                'auth' => 'none',          // none | user | api
            ], $app);
        }
        return $data;
    }
}
