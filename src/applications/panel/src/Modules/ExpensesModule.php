<?php
namespace Panel\Modules;

use Panel\Controller\ExpensesController;
use Panel\Core\ProvidesJobs;
use Panel\Core\ProvidesCalendar;
use Panel\Module;
use Panel\PanelContext;
use Panel\Rights;
use WebApplication\Http\Router;

/** Náklady: received documents, recurring costs, categories. */
class ExpensesModule implements Module, ProvidesJobs, ProvidesCalendar
{
    public function routes(Router $r, callable $guard, PanelContext $ctx): void
    {
        $expenses = new ExpensesController($ctx);
        $XV = Rights::EXPENSES_VIEW;
        $XE = Rights::EXPENSES_EDIT;
        $r->get('/expenses', $guard($XV, [$expenses, 'index']));
        $r->get('/expenses/new', $guard($XE, [$expenses, 'form']));
        $r->post('/expenses', $guard($XE, [$expenses, 'save']));
        $r->get('/expenses/categories', $guard($XV, [$expenses, 'categories']));
        $r->post('/expenses/categories', $guard($XE, [$expenses, 'categoriesSave']));
        $r->get('/expenses/recurring', $guard($XV, [$expenses, 'recurringIndex']));
        $r->get('/expenses/recurring/new', $guard($XE, [$expenses, 'recurringForm']));
        $r->post('/expenses/recurring', $guard($XE, [$expenses, 'recurringSave']));
        $r->get('/expenses/recurring/{id:\d+}', $guard($XV, [$expenses, 'recurringForm']));
        $r->post('/expenses/recurring/{id:\d+}', $guard($XE, [$expenses, 'recurringSave']));
        $r->get('/expenses/{id:\d+}', $guard($XV, [$expenses, 'show']));
        $r->get('/expenses/{id:\d+}/edit', $guard($XE, [$expenses, 'form']));
        $r->post('/expenses/{id:\d+}', $guard($XE, [$expenses, 'save']));
        $r->get('/expenses/{id:\d+}/files/{fid:\d+}', $guard($XV, [$expenses, 'file']));
        $r->post('/expenses/{id:\d+}/files', $guard($XE, [$expenses, 'addFile']));
        $r->post('/expenses/{id:\d+}/paid', $guard($XE, [$expenses, 'paid']));
        $r->post('/expenses/{id:\d+}/unpaid', $guard($XE, [$expenses, 'unpaid']));
        $r->post('/expenses/{id:\d+}/cancel', $guard($XE, [$expenses, 'cancel']));
    }

    public function nav(): array
    {
        return [['key' => 'expenses', 'group' => 'finance', 'path' => '/expenses', 'label' => 'Náklady', 'permission' => Rights::EXPENSES_VIEW,
            'order' => 20]];
    }

    public function settingsTabs(): array
    {
        return [];
    }

    public function permissions(): array
    {
        return [
            ['key' => Rights::EXPENSES_VIEW, 'group' => 'Náklady', 'sensitive' => false,
                'label' => 'Náklady — prijaté doklady (prezeranie)'],
            ['key' => Rights::EXPENSES_EDIT, 'group' => 'Náklady', 'sensitive' => false,
                'label' => 'Náklady — zadávanie dokladov, úhrady, storno, kategórie, náklady objednávok'],
        ];
    }

    public function recordTypes(): array
    {
        return [['type' => 'expense', 'label' => 'Prijatý doklad',
            'resolve' => function (PanelContext $ctx, array $ids): ?array {
                if (!$ctx->can(Rights::EXPENSES_VIEW)) {
                    return null;
                }
                $r = $ctx->db()->fetchOne('SELECT id, company_id, document_number, supplier_name FROM ' . $ctx->db()->table('expense') . ' WHERE id = ?', [$ids[0]]);
                if (!$r || !$ctx->canCompany((int)$r['company_id'])) {
                    return null;
                }
                return ['title' => 'Doklad ' . trim($r['document_number'] . ' ' . $r['supplier_name']), 'url' => '/expenses/' . $r['id'],
                    'company_id' => (int)$r['company_id']];
            },
            'lookup' => function (PanelContext $ctx, string $q): array {
                $ids = $ctx->companyIds();
                if (!$ids || !$ctx->can(Rights::EXPENSES_VIEW)) {
                    return [];
                }
                $like = '%' . addcslashes($q, '%_\\') . '%';
                return array_map(function ($id) { return 'expense:' . $id; }, array_column($ctx->db()->query('SELECT id FROM ' . $ctx->db()->table('expense')
                    . ' WHERE (document_number LIKE ? OR supplier_name LIKE ?) AND company_id IN (' . implode(',', array_map('intval', $ids)) . ') ORDER BY id DESC LIMIT 5',
                    [$like, $like]), 'id'));
            }]];
    }

    public function jobs(): array
    {
        return [['key' => 'expenses.recurring', 'label' => 'Opakované náklady — vytvoriť doklady, ktoré sú na rade', 'every' => 1440, 'hour' => 5,
            'run' => function (PanelContext $sys): string {
                return 'vytvorených dokladov: ' . count($sys->expenses()->generateRecurring());
            }]];
    }

    /** Due dates of unpaid received documents + the next dates of recurring costs. */
    public function calendar(PanelContext $ctx, string $from, string $to): array
    {
        $ids = $ctx->companyIds();
        if (!$ids || !$ctx->can(Rights::EXPENSES_VIEW)) {
            return [];
        }
        $in = implode(',', array_map('intval', $ids));
        $out = [];
        foreach ($ctx->db()->query('SELECT id, document_number, supplier_name, due_date, total, currency FROM ' . $ctx->db()->table('expense')
                . " WHERE status = 'open' AND due_date BETWEEN ? AND ? AND company_id IN ($in)", [$from, $to]) as $r) {
            $out[] = ['date' => $r['due_date'], 'title' => 'Zaplatiť ' . trim($r['supplier_name'] . ' ' . $r['document_number']) . ' (' . number_format((float)$r['total'], 2, ',', ' ')
                . ' ' . $r['currency'] . ')', 'url' => '/expenses/' . $r['id'], 'kind' => $r['due_date'] < date('Y-m-d') ? 'over' : 'due'];
        }
        foreach ($ctx->db()->query('SELECT id, name, supplier_name, next_date FROM ' . $ctx->db()->table('expense_recurring')
                . " WHERE active = 1 AND next_date BETWEEN ? AND ? AND company_id IN ($in)", [$from, $to]) as $r) {
            $out[] = ['date' => $r['next_date'], 'title' => 'Opakovaný náklad: ' . ($r['name'] ?: $r['supplier_name']), 'url' => '/expenses/recurring/' . $r['id'], 'kind' => 'plan'];
        }
        return $out;
    }
}
