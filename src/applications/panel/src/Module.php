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

    /**
     * Permissions of the module (Core\Permissions): list of ['key' ("module.action"), 'label' (what it allows, in Slovak,
     * shown on the employee and role forms), 'group' (heading on those forms), 'sensitive' (money, settings,
     * irreversible steps)]. Every guard / check of the module uses only these keys (test "permissions …").
     */
    public function permissions(): array;

    /**
     * Record types of the module (Core\Refs — references "type:id", short links /r/<ref>, links, comments, activity):
     * list of ['type' (= the audit entity name for simple types), 'label' (Slovak, "Faktúra"), 'parts' (ids in the
     * reference, default 1), 'resolve' => fn(PanelContext $ctx, int[] $ids): ?array ['title', 'url', 'company_id'] — null when the
     * record does not exist OR the person may not see it (check permission + company / e-shop here!),
     * optional 'lookup' => fn(PanelContext $ctx, string $q): string[] references matching a number / name (max ~5)].
     */
    public function recordTypes(): array;
}
