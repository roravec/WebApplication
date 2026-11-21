<?php
namespace Panel\Modules;

use Panel\Module;
use Panel\PanelContext;
use WebApplication\Http\Response;
use WebApplication\Http\Router;

/** /settings → the first tab of Nastavenia this person may open (the tabs come from the other modules). */
class SettingsModule implements Module
{
    public function routes(Router $r, callable $guard, PanelContext $ctx): void
    {
        $r->get('/settings', $guard('', function () use ($ctx) {
            $tabs = $ctx->navigation()->settingsTabs($ctx);
            if (!$tabs) {
                return Response::html($ctx->page('forbidden', [], 'Prístup zamietnutý'), 403);
            }
            return Response::redirect($ctx->url($tabs[0]['path']));
        }));
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
