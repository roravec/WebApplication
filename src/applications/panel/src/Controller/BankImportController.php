<?php
namespace Panel\Controller;

use Panel\Audit;
use Panel\Invoicing\BankStatement;
use Panel\Invoicing\Invoices;
use Panel\PanelContext;
use WebApplication\Csrf;
use WebApplication\Http\Request;
use WebApplication\Http\Response;
use WebApplication\Session;

/**
 * Faktúry → Import výpisu: upload a CAMT.053 bank statement, the panel pairs incoming payments with open invoices by
 * the variable symbol, the employee checks the proposal and confirms. A bank transaction is imported only once.
 */
class BankImportController
{
    const SESSION_KEY = 'bank_import';

    /** @var PanelContext */
    private $ctx;

    public function __construct(PanelContext $ctx)
    {
        $this->ctx = $ctx;
    }

    public function form(Request $req, array $params, string $error = ''): string
    {
        // bank accounts with an API token (automatic download)
        $apis = [];
        foreach ($this->ctx->companies()->all($this->ctx->companyIds()) as $c) {
            foreach ($this->ctx->companies()->banks((int)$c['id'], true) as $b) {
                if (!empty($b['api_token'])) {
                    $from = $b['api_last_download'] ? date('Y-m-d', strtotime($b['api_last_download'] . ' -3 days')) : date('Y-m-d', strtotime('-30 days'));
                    $apis[] = ['id' => (int)$b['id'], 'company' => $c['name'], 'iban' => $b['iban'], 'provider' => $b['api_provider'],
                               'last' => $b['api_last_download'], 'from' => $from];
                }
            }
        }
        return $this->ctx->page('invoices/bank-import', ['error' => $error, 'apis' => $apis], 'Import bankového výpisu', 'invoices');
    }

    /** POST /payments/import/fio — download movements of a period through the bank's API. */
    public function download(Request $req, array $params)
    {
        if (!Csrf::check($req)) {
            return Response::text('Formulár vypršal, načítajte stránku znova.', 400);
        }
        $companies = $this->ctx->companies();
        $bank = $companies->bank((int)$req->post('bank_id', 0));
        if ($bank === null || !$this->ctx->canCompany((int)$bank['company_id']) || empty($bank['api_token'])) {
            return Response::html($this->form($req, $params, 'Účet nemá nastavené API banky.'), 422);
        }
        $from = (string)$req->post('from', '');
        $to = (string)$req->post('to', date('Y-m-d'));
        try {
            $token = (new \Panel\Secret($this->ctx->app->getJwtSecret()))->decrypt((string)$bank['api_token']);
            if ($token === null) {
                throw new \RuntimeException('Uložený token sa nedá prečítať (zmenil sa jwtSecret?) — zadajte ho pri firme znova.');
            }
            $xml = \Panel\Invoicing\Fio::movements($token, $from, $to);
            $st = BankStatement::parseCamt053($xml);
        } catch (\Throwable $e) {
            return Response::html($this->form($req, $params, $e->getMessage()), 422);
        }
        $companies->bankDownloaded((int)$bank['id']);
        Audit::log($this->ctx->db(), $this->ctx->user(), 'company', (int)$bank['company_id'], 'bank_download', $bank['iban'] . ' ' . $from . ' – ' . $to);
        if (!$st['entries']) {
            return Response::html($this->form($req, $params, 'V období ' . $from . ' – ' . $to . ' nie sú na účte žiadne pohyby.'), 200);
        }
        return $this->review($req, $params, $st);
    }

    public function upload(Request $req, array $params)
    {
        if (!Csrf::check($req)) {
            return Response::text('Formulár vypršal, načítajte stránku znova.', 400);
        }
        $f = $req->file('statement');
        if ($f === null || $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
            return Response::html($this->form($req, $params, 'Vyberte súbor s výpisom (XML).'), 422);
        }
        if ($f['size'] > 10 * 1024 * 1024) {
            return Response::html($this->form($req, $params, 'Súbor je väčší ako 10 MB.'), 422);
        }
        try {
            $st = BankStatement::parseCamt053((string)file_get_contents($f['tmp_name']));
        } catch (\InvalidArgumentException $e) {
            return Response::html($this->form($req, $params, $e->getMessage()), 422);
        }
        return $this->review($req, $params, $st);
    }

    /** Pairing proposal for a parsed statement (file or bank API) → review page. */
    private function review(Request $req, array $params, array $st)
    {
        // the company by the statement's account
        $companyId = 0;
        foreach ($this->ctx->companyIds() as $cid) {
            foreach ($this->ctx->companies()->banks($cid) as $b) {
                if (strtoupper(str_replace(' ', '', $b['iban'])) === $st['iban']) {
                    $companyId = $cid;
                }
            }
        }
        if (!$companyId) {
            return Response::html($this->form($req, $params, 'Účet ' . $st['iban'] . ' z výpisu nepatrí žiadnej firme, ku ktorej máte prístup (Firmy → bankové účty).'), 422);
        }
        $repo = $this->ctx->invoices();
        $open = $repo->openDocuments($companyId);
        $done = array_flip($repo->importedRefs(array_column($st['entries'], 'ref')));
        $rows = [];
        foreach ($st['entries'] as $e) {
            $match = Invoices::matchTransaction($e, $open);
            $e['imported'] = $e['ref'] !== '' && isset($done[$e['ref']]);
            $e['invoice_id'] = $match ? (int)$match['id'] : 0;
            $e['exact'] = $match && abs(((float)$match['total'] - (float)$match['paid']) - $e['amount']) < 0.005;
            $rows[] = $e;
        }
        $token = bin2hex(random_bytes(8));
        Session::set(self::SESSION_KEY, ['token' => $token, 'company' => $companyId, 'iban' => $st['iban'], 'rows' => $rows]);
        return $this->ctx->page('invoices/bank-review', ['token' => $token, 'iban' => $st['iban'], 'rows' => $rows, 'open' => $open,
            'company' => $this->ctx->companies()->find($companyId)], 'Import bankového výpisu — kontrola', 'invoices');
    }

    public function confirm(Request $req, array $params)
    {
        if (!Csrf::check($req)) {
            return Response::text('Formulár vypršal, načítajte stránku znova.', 400);
        }
        $data = Session::get(self::SESSION_KEY);
        if (!is_array($data) || ($data['token'] ?? '') !== (string)$req->post('token', '') || !$this->ctx->canCompany((int)$data['company'])) {
            Session::flash('error', 'Import vypršal — nahrajte výpis znova.');
            return Response::redirect($this->ctx->url('/payments/import'));
        }
        $repo = $this->ctx->invoices();
        $open = [];
        foreach ($repo->openDocuments((int)$data['company']) as $d) {
            $open[(int)$d['id']] = $d;
        }
        $use = (array)$req->post('use', []);
        $target = (array)$req->post('invoice', []);
        $imported = 0;
        $skipped = [];
        foreach ($data['rows'] as $i => $e) {
            if (empty($use[$i]) || $e['imported']) {
                continue;
            }
            $invoiceId = (int)($target[$i] ?? 0);
            if (!isset($open[$invoiceId])) {
                $skipped[] = $e['ref'] . ' (doklad nie je otvorený)';
                continue;
            }
            $note = trim('výpis ' . $e['date'] . ', ' . $e['payer'] . ($e['message'] !== '' ? ', ' . $e['message'] : ''), ', ');
            try {
                $repo->addPayment($invoiceId, $e['date'], (float)$e['amount'], 'transfer', $note, $this->ctx->user(), $e['ref']);
                $imported++;
            } catch (\PDOException $ex) {
                $skipped[] = $e['ref'] . ' (už importovaná)';   // unique bank_ref
            }
        }
        Session::remove(self::SESSION_KEY);
        Audit::log($this->ctx->db(), $this->ctx->user(), 'company', (int)$data['company'], 'bank_import',
            $data['iban'] . ': ' . $imported . ' úhrad');
        Session::flash('msg', 'Zapísaných úhrad: ' . $imported . '.' . ($skipped ? ' Preskočené: ' . implode(', ', $skipped) . '.' : ''));
        return Response::redirect($this->ctx->url('/invoices?payment=unpaid'));
    }
}
