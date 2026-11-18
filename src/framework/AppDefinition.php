<?php
namespace WebApplication;

/** One configured application, as selected for the current request. */
class AppDefinition
{
    /** @var string app name (key in config 'apps') */
    public $name = '';
    /** @var string folder under applications/ */
    public $folder = '';
    /** @var string file with the app class, relative to the folder */
    public $file = 'WebApp.php';
    /** @var string class implementing IWebApp or Http\RequestHandler */
    public $class = 'WebApp';
    /** @var string database table prefix */
    public $dbPrefix = '';
    /** @var string none | user | api */
    public $auth = 'none';
    /** @var string URL path the app is mounted on, e.g. '/' or '/panel' (no trailing slash except root) */
    public $basePath = '/';
    /** @var string directory containing all app folders */
    public $applicationsDir = WA_APPLICATIONS;

    public static function fromConfig(string $name, array $cfg, string $basePath = '/', string $applicationsDir = WA_APPLICATIONS): self
    {
        $d = new self();
        $d->name = $name;
        $d->folder = $cfg['folder'];
        $d->file = $cfg['file'];
        $d->class = $cfg['class'];
        $d->dbPrefix = $cfg['dbPrefix'];
        $d->auth = $cfg['auth'];
        $d->basePath = $basePath;
        $d->applicationsDir = $applicationsDir;
        return $d;
    }

    public function path(): string
    {
        return $this->applicationsDir . '/' . $this->folder;
    }

    /** Public URL of the app folder (for assets): /applications/<folder> */
    public function publicPath(): string
    {
        return '/applications/' . $this->folder;
    }
}
