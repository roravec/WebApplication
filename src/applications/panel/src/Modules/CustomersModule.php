<?php
namespace Panel\Modules;

use Panel\Controller\CustomersController;
use Panel\Module;
use Panel\PanelContext;
use Panel\Rights;
use WebApplication\Http\Router;

/** Odberatelia (customers of the invoicing companies) + IČO lookup in the register. */
class CustomersModule implements Module
{
    public function routes(Router $r, callable $guard, PanelContext $ctx): void
    {
        $customers = new CustomersController($ctx);
        $VIEW = Rights::INVOICES_VIEW;
        $EDIT = Rights::INVOICES_EDIT;
        $r->get('/customers', $guard($VIEW, [$customers, 'index']));
        $r->get('/customers/lookup', $guard($EDIT, [$customers, 'lookup']));
        $r->get('/lookup/ico', $guard('', [$customers, 'rpo']));
        $r->get('/customers/new', $guard($EDIT, [$customers, 'form']));
        $r->get('/customers/{id:\d+}', $guard($EDIT, [$customers, 'form']));
        $r->post('/customers', $guard($EDIT, [$customers, 'save']));
        $r->post('/customers/{id:\d+}', $guard($EDIT, [$customers, 'save']));
    }

    public function nav(): array
    {
        return [['key' => 'customers', 'group' => 'sales', 'path' => '/customers', 'label' => 'Odberatelia',
            'permission' => Rights::INVOICES_VIEW, 'order' => 30]];
    }

    public function settingsTabs(): array
    {
        return [];
    }

    public function permissions(): array
    {
        return [];   // uses the permissions of other modules
    }

    public function recordTypes(): array
    {
        return [['type' => 'customer', 'label' => 'Odberateľ',
            'resolve' => function (PanelContext $ctx, array $ids): ?array {
                if (!$ctx->can(Rights::INVOICES_VIEW)) {
                    return null;
                }
                $r = $ctx->db()->fetchOne('SELECT id, company_id, name FROM ' . $ctx->db()->table('customer') . ' WHERE id = ?', [$ids[0]]);
                return $r && $ctx->canCompany((int)$r['company_id']) ? ['title' => $r['name'], 'url' => '/customers/' . $r['id'], 'company_id' => (int)$r['company_id']] : null;
            },
            'lookup' => function (PanelContext $ctx, string $q): array {
                $ids = $ctx->companyIds();
                if (!$ids || !$ctx->can(Rights::INVOICES_VIEW)) {
                    return [];
                }
                $like = '%' . addcslashes($q, '%_\\') . '%';
                return array_map(function ($id) { return 'customer:' . $id; }, array_column($ctx->db()->query('SELECT id FROM ' . $ctx->db()->table('customer')
                    . ' WHERE (name LIKE ? OR ico = ?) AND company_id IN (' . implode(',', array_map('intval', $ids)) . ') ORDER BY name LIMIT 5',
                    [$like, $q]), 'id'));
            }]];
    }
}
