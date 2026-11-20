<?php
/**
 * hbkpravotice.sk — web of the club HBK Pravotice + StadiumControl (scoreboard / timer / stats administration and the
 * JSON API that bnhl.sk reads). Ported 2026-11-20 from StadiumControl/WebServer/webapp/WebApp_hbkpravotice (oldest
 * core, mysqli) without changing its pages: the old page builder (class WebApp in WebApp.php) runs through
 * WebApplication\Legacy\OldCoreApplication (old core API on the framework, LegacyDatabase, users in <prefix>users).
 *
 * config: 'hbkpravotice' => ['folder' => 'hbkpravotice', 'file' => 'App.php', 'class' => 'HbkpravoticeApp',
 *                            'dbPrefix' => 'hbkpravotice_', 'auth' => 'none']   (texts: <prefix>text, appid 'hbkpravotice')
 */
\WebApplication\Legacy\OldCoreApplication::loadOldCore();   // ICRUD, User — before the app's own classes
require_once __DIR__ . '/WebApp.php';

class HbkpravoticeApp implements IWebApp
{
    public $name = '';

    /** The old core built the page the same way for every method (forms read $_POST themselves). */
    public function get($rootApplication): string
    {
        return \WebApplication\Legacy\OldCoreApplication::run($rootApplication, new WebApp());
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
