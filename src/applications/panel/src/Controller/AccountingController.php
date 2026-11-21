<?php
namespace Panel\Controller;

use Panel\Invoicing\AccountingPackage;
use Panel\PanelContext;
use WebApplication\Csrf;
use WebApplication\Http\Request;
use WebApplication\Http\Response;
use WebApplication\Session;

/**
 * Faktúry → Pre účtovníka: monthly packages (Omega file + PDFs + overviews) — send by e-mail, download, history.
 * Automatic sending: dashboard (AccountingPackage::sendDue) and the cron URL /cron/accounting?token=… (config
 * apps.<panel>.cron_token; the hosting has no shell, an external cron service or the hosting's web cron can call it).
 */
class AccountingController
{
    /** @var PanelContext */
    private $ctx;

    public function __construct(PanelContext $ctx)
    {
        $this->ctx = $ctx;
    }

    public function index(Request $req, array $params)
    {
        $companies = $this->ctx->companies()->all($this->ctx->companyIds());
        if (!$companies) {
            return $this->ctx->page('invoices/no-company', [], 'Pre účtovníka', 'invoices:accounting');
        }
        $cid = (int)$req->query('company', 0);
        if (!$this->ctx->canCompany($cid)) {
            $cid = (int)$companies[0]['id'];
        }
        $acc = $this->ctx->accounting();
        $period = (string)$req->query('period', AccountingPackage::previousPeriod());
        if (!AccountingPackage::validPeriod($period)) {
            $period = AccountingPackage::previousPeriod();
        }
        $c = $acc->collect($cid, $period);
        $periods = [];
        for ($i = 0; $i < 13; $i++) {   // this month and 12 back
            $p = date('Y-m', strtotime(date('Y-m-01') . " -$i month"));
            $periods[$p] = AccountingPackage::label($p);
        }
        return $this->ctx->page('invoices/accounting', [
            'companies' => $companies, 'cid' => $cid, 'period' => $period, 'periods' => $periods,
            'settings' => $this->ctx->companies()->exportSettings($cid)['accountant'],
            'preview' => ['docs' => count($c['docs']), 'omega' => count($c['omega']), 'payments' => count($c['payments']),
                          'expenses' => count($c['expenses']),
                          'package' => $c['package']],
            'history' => $acc->history($cid), 'configured' => $this->ctx->mailer()->configured(),
        ], 'Pre účtovníka', 'invoices:accounting');
    }

    /** POST /accounting/send — send the package of a month now (again = once more, same content). */
    public function send(Request $req, array $params)
    {
        if (!Csrf::check($req)) {
            return Response::text('Formulár vypršal, načítajte stránku znova.', 400);
        }
        $cid = (int)$req->post('company', 0);
        $period = (string)$req->post('period', '');
        $back = Response::redirect($this->ctx->url('/accounting?company=' . $cid . '&period=' . rawurlencode($period)));
        if (!$this->ctx->canCompany($cid) || !AccountingPackage::validPeriod($period)) {
            Session::flash('error', 'Zvoľte firmu a mesiac.');
            return $back;
        }
        $this->ctx->expenses()->generateRecurring();   // recurring costs of the month belong into the package
        $r = $this->ctx->accounting()->send($cid, $period, $this->ctx->mailer(), $this->ctx->user(), 'manual', (bool)$req->post('again'));
        Session::flash($r['ok'] ? 'msg' : 'error', $r['message']);
        return $back;
    }

    /** GET /accounting/download?company=&period= — the ZIP (nothing is marked; sending does that). */
    public function download(Request $req, array $params)
    {
        $cid = (int)$req->query('company', 0);
        $period = (string)$req->query('period', '');
        if (!$this->ctx->canCompany($cid) || !AccountingPackage::validPeriod($period)) {
            return Response::html($this->ctx->page('notfound', [], 'Stránka neexistuje', 'invoices'), 404);
        }
        $b = $this->ctx->accounting()->build($cid, $period);
        if ($b['zip'] === '') {
            Session::flash('error', 'Za ' . AccountingPackage::label($period) . ' nie sú žiadne doklady ani úhrady.');
            return Response::redirect($this->ctx->url('/accounting?company=' . $cid . '&period=' . $period));
        }
        return new Response($b['zip'], 200, ['Content-Type' => 'application/zip', 'Content-Disposition' => 'attachment; filename="' . $b['name'] . '"',
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    /** GET /cron/accounting?token=… — automatic sending without a logged-in employee (404 unless a token is configured). */
    public function cron(Request $req, array $params)
    {
        $app = $this->ctx->app;
        $token = (string)$app->getConfig()->get('apps.' . $app->getApplicationName() . '.cron_token', '');
        if (strlen($token) < 20 || !hash_equals($token, (string)$req->query('token', ''))) {
            return Response::text('Not found', 404);
        }
        $out = [];
        foreach ($this->ctx->accounting()->sendDue($this->ctx->mailer()) as $r) {
            $out[] = ($r['ok'] ? 'OK ' : 'CHYBA ') . $r['message'];
        }
        foreach ((new \Panel\Reports\MonthlySummary($this->ctx))->sendDue($this->ctx->mailer()) as $r) {
            $out[] = ($r['ok'] ? 'OK ' : 'CHYBA ') . $r['message'];
        }
        return Response::text($out ? implode("\n", $out) : 'Nič na odoslanie.');
    }
}
