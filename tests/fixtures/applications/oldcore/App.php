<?php
/** Test fixture: entry of an app ported from the oldest core (same shape as applications/hbkpravotice/App.php). */
\WebApplication\Legacy\OldCoreApplication::loadOldCore();
require_once __DIR__ . '/WebApp.php';

class OldCoreFixtureApp implements IWebApp
{
    public $name = '';
    public function get($rootApplication): string { return \WebApplication\Legacy\OldCoreApplication::run($rootApplication, new OldCoreWebApp()); }
    public function post($rootApplication): string { return $this->get($rootApplication); }
    public function put($rootApplication): string { return $this->get($rootApplication); }
    public function delete($rootApplication): string { return $this->get($rootApplication); }
    public function methodNotAllowed(string $method, $rootApplication): string { return ''; }
}
