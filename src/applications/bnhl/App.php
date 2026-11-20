<?php
/**
 * bnhl.sk — league web (matches, tables, stats). Ported 2026-11-20 from StadiumControl/WebServer/webapp/WebApp_bnhl
 * (oldest core) without changing its pages: the old page builder (class WebApp in WebApp.php) runs through
 * WebApplication\Legacy\OldCoreApplication. Shares the tables of hbkpravotice (dbPrefix hbkpravotice_) and reads
 * games / players / teams from the StadiumControl JSON API of hbkpravotice.sk (StadiumControlApi).
 *
 * config: 'bnhl' => ['folder' => 'bnhl', 'file' => 'App.php', 'class' => 'BnhlApp', 'dbPrefix' => 'hbkpravotice_',
 *                    'auth' => 'none',
 *                    'stadiumControlApi' => 'https://hbkpravotice.sk/StadiumControl']   // optional; dev: the dev copy
 */
\WebApplication\Legacy\OldCoreApplication::loadOldCore();
require_once __DIR__ . '/WebApp.php';

class BnhlApp implements IWebApp
{
    public $name = '';

    public function get($rootApplication): string
    {
        $api = (string)$rootApplication->getConfig()->get('apps.' . $rootApplication->getApplicationName() . '.stadiumControlApi', '');
        if ($api !== '') {
            StadiumControlApi::$ApiUrl = $api;
        }
        // bnhl writes "/" . $AssetsPath itself → the folder without the leading slash (like the old "WebApp_bnhl")
        $folder = ltrim($rootApplication->getDefinition()->publicPath(), '/');
        return \WebApplication\Legacy\OldCoreApplication::run($rootApplication, new WebApp(), 'en', $folder);
    }

    public function post($rootApplication): string { return $this->get($rootApplication); }
    public function put($rootApplication): string { return $this->get($rootApplication); }
    public function delete($rootApplication): string { return $this->get($rootApplication); }

    public function methodNotAllowed(string $method, $rootApplication): string
    {
        $rootApplication->response()->setStatus(405);
        return '';
    }
}
