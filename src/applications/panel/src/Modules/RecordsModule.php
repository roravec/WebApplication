<?php
namespace Panel\Modules;

use Panel\Controller\RecordsController;
use Panel\Module;
use Panel\PanelContext;
use WebApplication\Http\Router;

/**
 * Core services of all records (docs/panel-architektura.md chapter 4): short links /r/<ref> + QR + label, comments,
 * links, feed "Čo sa deje". Open to every employee; RecordsController lets through only records he may see.
 */
class RecordsModule implements Module
{
    const REF = '{ref:[a-z][a-z_]+(?::\d+)+}';   // exact check: Core\Refs::parse

    public function routes(Router $r, callable $guard, PanelContext $ctx): void
    {
        $c = new RecordsController($ctx);
        $r->get('/r/' . self::REF, $guard('', [$c, 'open']));
        $r->get('/r/' . self::REF . '/qr', $guard('', [$c, 'qr']));
        $r->get('/r/' . self::REF . '/label', $guard('', [$c, 'label']));
        $r->post('/comments', $guard('', [$c, 'commentAdd']));
        $r->post('/comments/{id:\d+}/edit', $guard('', [$c, 'commentEdit']));
        $r->post('/comments/{id:\d+}/delete', $guard('', [$c, 'commentDelete']));
        $r->get('/links/lookup', $guard('', [$c, 'lookup']));
        $r->post('/links', $guard('', [$c, 'linkAdd']));
        $r->post('/links/{id:\d+}/delete', $guard('', [$c, 'linkDelete']));
        $r->get('/activity', $guard('', [$c, 'feed']));
        $r->get('/search', $guard('', [$c, 'search']));
        $r->get('/files/' . self::REF, $guard('', [$c, 'files']));
        $r->post('/files/' . self::REF . '/upload', $guard('', [$c, 'filesUpload']));
        $r->get('/files/' . self::REF . '/download', $guard('', [$c, 'filesDownload']));
        $r->post('/files/' . self::REF . '/delete', $guard('', [$c, 'filesDelete']));
        $r->post('/files/' . self::REF . '/share', $guard('', [$c, 'filesShare']));
        $r->post('/files/shares/{id:\d+}/revoke', $guard('', [$c, 'filesRevoke']));
    }

    public function nav(): array
    {
        return [];
    }

    public function settingsTabs(): array
    {
        return [];
    }

    public function permissions(): array
    {
        return [];
    }

    public function recordTypes(): array
    {
        return [];
    }
}
