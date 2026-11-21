<?php
namespace Panel\Modules;

use Panel\Controller\AccountingController;
use Panel\Controller\BankImportController;
use Panel\Controller\InvoicesController;
use Panel\Module;
use Panel\PanelContext;
use Panel\Rights;
use WebApplication\Http\Router;

/** Faktúry: invoices and credit notes, payments (bank statement, Fio), for the accountant (package, Omega export). */
class InvoicingModule implements Module
{
    public function routes(Router $r, callable $guard, PanelContext $ctx): void
    {
        $invoices = new InvoicesController($ctx);
        $accounting = new AccountingController($ctx);
        $bank = new BankImportController($ctx);
        $VIEW = Rights::INVOICES_VIEW;
        $EDIT = Rights::INVOICES_EDIT;
        // automatic monthly package for the accountant without a logged-in employee (token in config; 404 without it)
        $r->get('/cron/accounting', [$accounting, 'cron']);

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
}
