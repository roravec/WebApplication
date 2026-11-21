<?php
namespace Panel\Modules;

use Panel\Controller\AccountingController;
use Panel\Controller\BankImportController;
use Panel\Controller\InvoicesController;
use Panel\Core\ProvidesJobs;
use Panel\Core\ProvidesCalendar;
use Panel\Module;
use Panel\PanelContext;
use Panel\Rights;
use WebApplication\Http\Router;

/** Faktúry: invoices and credit notes, payments (bank statement, Fio), for the accountant (package, Omega export). */
class InvoicingModule implements Module, ProvidesJobs, ProvidesCalendar
{
    public function routes(Router $r, callable $guard, PanelContext $ctx): void
    {
        $invoices = new InvoicesController($ctx);
        $accounting = new AccountingController($ctx);
        $bank = new BankImportController($ctx);
        $VIEW = Rights::INVOICES_VIEW;
        $EDIT = Rights::INVOICES_EDIT;

        $r->get('/invoices', $guard($VIEW, [$invoices, 'index']));
        $r->get('/invoices/new', $guard($EDIT, [$invoices, 'create']));
        $r->post('/invoices', $guard($EDIT, [$invoices, 'save']));
        $r->get('/invoices/export', $guard($VIEW, [$invoices, 'exportForm']));
        $r->post('/invoices/export', $guard($VIEW, [$invoices, 'export']));
        $r->get('/accounting', $guard($VIEW, [$accounting, 'index']));
        $r->get('/accounting/download', $guard($VIEW, [$accounting, 'download']));
        $r->post('/accounting/send', $guard($EDIT, [$accounting, 'send']));
        $r->get('/invoices/{id:\d+}', $guard($VIEW, [$invoices, 'show']));
        $r->get('/invoices/{id:\d+}/edit', $guard($EDIT, [$invoices, 'edit']));
        $r->post('/invoices/{id:\d+}', $guard($EDIT, [$invoices, 'save']));
        $r->post('/invoices/{id:\d+}/issue', $guard($EDIT, [$invoices, 'issue']));
        $r->post('/invoices/{id:\d+}/delete', $guard($EDIT, [$invoices, 'delete']));
        $r->post('/invoices/{id:\d+}/unissue', $guard(Rights::INVOICES_UNISSUE, [$invoices, 'unissue']));
        $r->post('/invoices/{id:\d+}/credit-note', $guard($EDIT, [$invoices, 'creditNote']));
        $r->get('/invoices/{id:\d+}/print', $guard($VIEW, [$invoices, 'printView']));
        $r->get('/invoices/{id:\d+}/pdf', $guard($VIEW, [$invoices, 'pdf']));
        $r->get('/invoices/{id:\d+}/ubl', $guard($VIEW, [$invoices, 'ubl']));
        $r->post('/invoices/{id:\d+}/payments', $guard($EDIT, [$invoices, 'addPayment']));
        $r->post('/invoices/{id:\d+}/payments/{pid:\d+}/delete', $guard($EDIT, [$invoices, 'deletePayment']));
        $r->get('/invoices/{id:\d+}/send', $guard($EDIT, [$invoices, 'sendForm']));
        $r->post('/invoices/{id:\d+}/send', $guard($EDIT, [$invoices, 'send']));

        // invoice from a shop order (the order pages are OrdersModule)
        foreach (OrdersModule::orderPaths() as $o) {
            $r->post($o . '/invoice', $guard($EDIT, [$invoices, 'fromOrder']));
            $r->post($o . '/invoice/issue', $guard($EDIT, [$invoices, 'issueFromOrder']));
            $r->post($o . '/invoice/send', $guard($EDIT, [$invoices, 'sendFromOrder']));
        }

        $r->get('/payments/import', $guard('', [$bank, 'form']));
        // statement import: invoices.edit (incoming) or expenses.edit (outgoing) — checked in BankImportController
        $r->post('/payments/import', $guard('', [$bank, 'upload']));
        $r->post('/payments/import/confirm', $guard('', [$bank, 'confirm']));
        $r->post('/payments/import/fio', $guard('', [$bank, 'download']));
    }

    public function nav(): array
    {
        return [['key' => 'invoices', 'group' => 'finance', 'path' => '/invoices', 'label' => 'Faktúry', 'permission' => Rights::INVOICES_VIEW,
            'order' => 10, 'tabs' => [
                ['key' => 'list', 'path' => '/invoices', 'label' => 'Faktúry a dobropisy', 'permission' => Rights::INVOICES_VIEW],
                ['key' => 'payments', 'path' => '/payments/import', 'label' => 'Úhrady (výpis, Fio)',
                    'permission' => [Rights::INVOICES_EDIT, Rights::EXPENSES_EDIT]],
                ['key' => 'accounting', 'path' => '/accounting', 'label' => 'Pre účtovníka', 'permission' => Rights::INVOICES_VIEW],
                ['key' => 'export', 'path' => '/invoices/export', 'label' => 'Export do Omegy', 'permission' => Rights::INVOICES_VIEW],
            ]]];
    }

    public function settingsTabs(): array
    {
        return [];
    }

    public function permissions(): array
    {
        return [
            ['key' => Rights::INVOICES_VIEW, 'group' => 'Faktúry', 'sensitive' => false,
                'label' => 'Faktúry — prezeranie, PDF, export pre účtovníka'],
            ['key' => Rights::INVOICES_EDIT, 'group' => 'Faktúry', 'sensitive' => false,
                'label' => 'Faktúry — vystavovanie (koncepty, vystavenie, dobropisy, úhrady, odberatelia, e-maily)'],
            ['key' => Rights::INVOICES_UNISSUE, 'group' => 'Faktúry', 'sensitive' => true,
                'label' => 'Faktúry — vrátiť omylom vystavenú faktúru do konceptu'],
        ];
    }

    public function recordTypes(): array
    {
        return [['type' => 'invoice', 'label' => 'Faktúra',
            'resolve' => function (PanelContext $ctx, array $ids): ?array {
                if (!$ctx->can(Rights::INVOICES_VIEW)) {
                    return null;
                }
                $r = $ctx->db()->fetchOne('SELECT id, company_id, doc_type, status, number FROM ' . $ctx->db()->table('invoice') . ' WHERE id = ?', [$ids[0]]);
                if (!$r || !$ctx->canCompany((int)$r['company_id'])) {
                    return null;
                }
                $kind = $r['doc_type'] === 'credit_note' ? 'Dobropis' : 'Faktúra';
                return ['title' => $r['status'] === 'draft' ? $kind . ' — koncept #' . $r['id'] : $kind . ' ' . $r['number'], 'url' => '/invoices/' . $r['id'],
                    'company_id' => (int)$r['company_id']];
            },
            'lookup' => function (PanelContext $ctx, string $q): array {
                $ids = $ctx->companyIds();
                if (!$ids || !$ctx->can(Rights::INVOICES_VIEW)) {
                    return [];
                }
                return array_map(function ($id) { return 'invoice:' . $id; }, array_column($ctx->db()->query('SELECT id FROM ' . $ctx->db()->table('invoice')
                    . ' WHERE number LIKE ? AND company_id IN (' . implode(',', array_map('intval', $ids)) . ') ORDER BY id DESC LIMIT 5',
                    ['%' . addcslashes($q, '%_\\') . '%']), 'id'));
            }]];
    }

    public function jobs(): array
    {
        return [['key' => 'accounting.package', 'label' => 'Mesačný balík pre účtovníka (firmy s automatickým odosielaním)', 'every' => 60,
            'run' => function (PanelContext $sys): string {
                $out = [];
                foreach ($sys->accounting()->sendDue($sys->mailer()) as $r) {
                    $out[] = ($r['ok'] ? '' : 'CHYBA ') . $r['message'];
                }
                return $out ? implode('; ', $out) : 'nič na odoslanie';
            }]];
    }

    /** Due dates of issued, not fully paid invoices (overdue ones in red). */
    public function calendar(PanelContext $ctx, string $from, string $to): array
    {
        $ids = $ctx->companyIds();
        if (!$ids || !$ctx->can(Rights::INVOICES_VIEW)) {
            return [];
        }
        $t = $ctx->db()->table('invoice');
        $out = [];
        foreach ($ctx->db()->query("SELECT i.id, i.number, i.due_date, i.total, i.currency, i.buyer_name,
                (SELECT COALESCE(SUM(p.amount), 0) FROM " . $ctx->db()->table('invoice_payment') . " p WHERE p.invoice_id = i.id) AS paid
                FROM $t i WHERE i.status = 'issued' AND i.doc_type = 'invoice' AND i.due_date BETWEEN ? AND ?
                AND i.company_id IN (" . implode(',', array_map('intval', $ids)) . ')', [$from, $to]) as $r) {
            $rest = (float)$r['total'] - (float)$r['paid'];
            if ($rest > 0.005) {
                $out[] = ['date' => $r['due_date'], 'title' => 'Splatnosť ' . $r['number'] . ' ' . $r['buyer_name'] . ' (' . number_format($rest, 2, ',', ' ') . ' ' . $r['currency'] . ')',
                    'url' => '/invoices/' . $r['id'], 'kind' => $r['due_date'] < date('Y-m-d') ? 'over' : 'due'];
            }
        }
        return $out;
    }
}
