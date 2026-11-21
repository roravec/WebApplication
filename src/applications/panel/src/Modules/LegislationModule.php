<?php
namespace Panel\Modules;

use Panel\Controller\LegislationController;
use Panel\Module;
use Panel\PanelContext;
use Panel\Rights;
use WebApplication\Http\Router;

/** Nastavenia → Legislatíva: VAT rates, texts on documents, VAT registration thresholds (docs/legislativa.md). */
class LegislationModule implements Module
{
    public function routes(Router $r, callable $guard, PanelContext $ctx): void
    {
        $legislation = new LegislationController($ctx);
        $r->get('/settings/legal', $guard(Rights::ADMIN_ONLY, [$legislation, 'form']));
        $r->post('/settings/legal', $guard(Rights::ADMIN_ONLY, [$legislation, 'save']));
        $r->post('/settings/legal/vat-registration', $guard(Rights::ADMIN_ONLY, [$legislation, 'saveVatRegistration']));
    }

    public function nav(): array
    {
        return [];
    }

    public function settingsTabs(): array
    {
        return [['key' => 'legal', 'path' => '/settings/legal', 'label' => 'Legislatíva', 'permission' => Rights::ADMIN_ONLY, 'order' => 50]];
    }
}
