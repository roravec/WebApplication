<?php
namespace Panel;

use WebApplication\Http\Router;

/**
 * A part of the panel (orders, invoicing, stock …) that plugs itself in — routes, menu items, tabs of Nastavenia —
 * so adding a module means a new class in src/Modules/, not edits in App.php + layout.php + … (docs/panel-architektura.md,
 * rule 5). Registered in App.php (PanelApp::modules()).
 */
interface Module
{
    /**
     * Registers the module's routes.
     * @param callable $guard fn(string|array $permission, callable $handler): callable — login + permission check
     *                        ('' = any logged-in employee, array = any of the permissions, Rights::ADMIN_ONLY = administrators)
     */
    public function routes(Router $r, callable $guard, PanelContext $ctx): void;

    /**
     * Items of the left menu: list of ['key', 'group' (Navigation::GROUPS), 'path', 'label', 'permission', 'order',
     * 'tabs' (optional: list of ['key', 'path', 'label', 'permission'] shown above the pages of this item)].
     * A page is in a menu item when its section (PanelContext::page) is the item's key, or "key:tab".
     */
    public function nav(): array;

    /** Tabs of Nastavenia: list of ['key', 'path', 'label', 'permission', 'order']; pages use the section "settings:key". */
    public function settingsTabs(): array;
}
