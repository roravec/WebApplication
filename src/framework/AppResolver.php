<?php
namespace WebApplication;

use WebApplication\Http\Request;

/**
 * Chooses the application for a request.
 *
 * 1. config 'routes': "host" or "host/path" => app name. The most specific path wins, then an exact host beats '*'.
 *    'erma.sk' => 'erma', 'www.erma.sk' => 'erma', 'erma.sk/panel' => 'panel',
 *    host '*' with a path = any host, e.g. key "*" . "/panel" => 'panel'; key '*' alone => default app.
 * 2. 'devPathSelect' (development only): http://localhost/<app>/... — first path segment names the app.
 * 3. Legacy fallback: first label of the host name without "www." (erma.sk → erma).
 */
class AppResolver
{
    /** @var Config */
    private $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    public function resolve(Request $request): ?AppDefinition
    {
        $apps = $this->config->get('apps', []);
        $dir = (string)$this->config->get('applicationsDir', WA_APPLICATIONS);
        $host = strtolower($request->host());
        $path = $request->path();

        // 1. explicit routes
        $best = null;
        $bestLen = -1;
        foreach ($this->config->get('routes', []) as $pattern => $appName) {
            $pattern = strtolower(trim($pattern));
            if ($pattern === '*') {
                if ($best === null) {
                    $best = [$appName, '/'];
                }
                continue;
            }
            $slash = strpos($pattern, '/');
            $pHost = $slash === false ? $pattern : substr($pattern, 0, $slash);
            $pPath = $slash === false ? '/' : rtrim(substr($pattern, $slash), '/');
            if ($pPath === '') {
                $pPath = '/';
            }
            $anyHost = $pHost === '*';
            if ((!$anyHost && $pHost !== $host) || !self::pathStartsWith($path, $pPath)) {
                continue;
            }
            // longer (more specific) path wins; with equal paths an exact host beats '*'
            $score = strlen($pPath) * 2 + ($anyHost ? 0 : 1);
            if ($score > $bestLen) {
                $best = [$appName, $pPath];
                $bestLen = $score;
            }
        }
        if ($best !== null && isset($apps[$best[0]])) {
            return AppDefinition::fromConfig($best[0], $apps[$best[0]], $best[1], $dir);
        }

        // 2. development: first path segment selects the app
        if ($this->config->get('devPathSelect')) {
            $segments = $request->segments();
            if (isset($segments[0]) && isset($apps[$segments[0]])) {
                return AppDefinition::fromConfig($segments[0], $apps[$segments[0]], '/' . $segments[0], $dir);
            }
        }

        // 3. legacy: first label of the host name
        $label = explode('.', preg_replace('/^www\./', '', $host))[0];
        if ($label !== '' && isset($apps[$label])) {
            return AppDefinition::fromConfig($label, $apps[$label], '/', $dir);
        }
        return null;
    }

    public static function pathStartsWith(string $path, string $prefix): bool
    {
        if ($prefix === '/') {
            return true;
        }
        return $path === $prefix || strncmp($path, $prefix . '/', strlen($prefix) + 1) === 0;
    }
}
