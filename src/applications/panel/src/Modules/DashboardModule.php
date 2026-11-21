<?php
namespace Panel\Modules;

use Panel\Controller\DashboardController;
use Panel\Module;
use Panel\PanelContext;
use WebApplication\Http\Router;

/** Prehľad (overview). */
class DashboardModule implements Module
{
    public function routes(Router $r, callable $guard, PanelContext $ctx): void
    {
        $dashboard = new DashboardController($ctx);
        $r->get('/', $guard('', [$dashboard, 'index']));
    }

    public function nav(): array
    {
        return [['key' => 'dashboard', 'group' => '', 'path' => '/', 'label' => 'Prehľad', 'order' => 0]];
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
