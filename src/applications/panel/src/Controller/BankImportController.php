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
 * Faktúry → Úhrady (výpis, Fio): upload a CAMT.053 bank statement (or download it from Fio), the panel pairs incoming payments
 * with open invoices by the variable symbol and outgoing payments with unpaid received documents (Náklady) by VS or the
 * supplier's IBAN + amount; an outgoing payment without a document can become a new cost (bank fee …). The employee
 * checks the proposal and confirms. A bank transaction is imported only once.
 * Incoming payments need invoices.edit, outgoing ones expenses.edit.
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

    private function allowed(): bool
    {
        return $this->ctx->can(\Panel\Rights::INVOICES_EDIT) || $this->ctx->can(\Panel\Rights::EXPENSES_EDIT);
    }

    private function forbidden(): Response
    {
        return Response::html($this->ctx->page('forbidden', [], 'Prístup zamietnutý'), 403);
    }

    public function form(Request $req, array $params, string $error = '')
    {
        if (!$this->allowed()) {
            return $this->forbidden();
        }
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
        return $this->ctx->page('invoices/bank-import', ['error' => $error, 'apis' => $apis], 'Import bankového výpisu', 'invoices:payments');
    }

    /** POST /payments/import/fio — download movements of a period through the bank's API. */
    public function download(Request $req, array $params)
    {
        if (!$this->allowed()) {
            return $this->forbidden();
        }
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
        if (!$this->allowed()) {
            return $this->forbidden();
        }
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
        $in = $this->ctx->can(\Panel\Rights::INVOICES_EDIT);
        $out = $this->ctx->can(\Panel\Rights::EXPENSES_EDIT);
        $open = $in ? $repo->openDocuments($companyId) : [];
        $openExp = $out ? $this->ctx->expenses()->openDocuments($companyId) : [];
        $refs = array_column($st['entries'], 'ref');
        $done = array_flip(array_merge($repo->importedRefs($refs), $this->ctx->expenses()->importedRefs($refs)));
        $rows = [];
        foreach ($st['entries'] as $e) {
            $e['imported'] = $e['ref'] !== '' && isset($done[$e['ref']]);
            $e['invoice_id'] = $e['expense_id'] = 0;
            $e['exact'] = false;
            $e['by'] = '';
            if ($e['amount'] > 0) {
                $match = $in ? Invoices::matchTransaction($e, $open) : null;
                $e['invoice_id'] = $match ? (int)$match['id'] : 0;
                $e['exact'] = $match && abs(((float)$match['total'] - (float)$match['paid']) - $e['amount']) < 0.005;
            } elseif ($e['amount'] < 0 && $out) {
                $match = \Panel\Expenses\Expenses::matchPayment($e, $openExp);
                $e['expense_id'] = $match ? (int)$match['id'] : 0;
                $e['exact'] = $match && abs((float)$match['total'] + $e['amount']) < 0.005;
                $e['by'] = $match ? $match['by'] : '';
            }
            $rows[] = $e;
        }
        $token = bin2hex(random_bytes(8));
        Session::set(self::SESSION_KEY, ['token' => $token, 'company' => $companyId, 'iban' => $st['iban'], 'rows' => $rows]);
        return $this->ctx->page('invoices/bank-review', ['token' => $token, 'iban' => $st['iban'], 'rows' => $rows, 'open' => $open,
            'openExp' => $openExp, 'in' => $in, 'out' => $out, 'categories' => $out ? $this->ctx->expenses()->categories(true) : [],
            'company' => $this->ctx->companies()->find($companyId)], 'Import bankového výpisu — kontrola', $in ? 'invoices:payments' : 'expenses');
    }

    public function confirm(Request $req, array $params)
    {
        if (!$this->allowed()) {
            return $this->forbidden();
        }
        if (!Csrf::check($req)) {
            return Response::text('Formulár vypršal, načítajte stránku znova.', 400);
        }
        $data = Session::get(self::SESSION_KEY);
        if (!is_array($data) || ($data['token'] ?? '') !== (string)$req->post('token', '') || !$this->ctx->canCompany((int)$data['company'])) {
            Session::flash('error', 'Import vypršal — nahrajte výpis znova.');
            return Response::redirect($this->ctx->url('/payments/import'));
        }
        $repo = $this->ctx->invoices();
        $in = $this->ctx->can(\Panel\Rights::INVOICES_EDIT);
        $out = $this->ctx->can(\Panel\Rights::EXPENSES_EDIT);
        $cid = (int)$data['company'];
        $open = [];
        foreach ($in ? $repo->openDocuments($cid) : [] as $d) {
            $open[(int)$d['id']] = $d;
        }
        $openExp = [];
        foreach ($out ? $this->ctx->expenses()->openDocuments($cid) : [] as $d) {
            $openExp[(int)$d['id']] = $d;
        }
        $cats = array_map('intval', array_column($out ? $this->ctx->expenses()->categories() : [], 'id'));
        $use = (array)$req->post('use', []);
        $target = (array)$req->post('invoice', []);
        $expTarget = (array)$req->post('expense', []);
        $expCat = (array)$req->post('category', []);
        $imported = 0;
        $paidExp = 0;
        $newExp = 0;
        $skipped = [];
        foreach ($data['rows'] as $i => $e) {
            if (empty($use[$i]) || $e['imported']) {
                continue;
            }
            if ($e['amount'] < 0) {
                if (!$out) {
                    continue;
                }
                $t = (string)($expTarget[$i] ?? '0');
                $note = trim('výpis ' . $e['date'] . ', ' . $e['payer'] . ($e['message'] !== '' ? ', ' . $e['message'] : ''), ', ');
                try {
                    if ($t === 'new') {
                        $cat = (int)($expCat[$i] ?? 0);
                        if (!in_array($cat, $cats, true)) {
                            $skipped[] = $e['ref'] . ' (zvoľte kategóriu nového nákladu)';
                            continue;
                        }
                        $this->ctx->expenses()->createFromBank($cid, $e, $cat, $this->ctx->user());
                        $newExp++;
                    } elseif (isset($openExp[(int)$t]) && $this->ctx->expenses()->payFromBank((int)$t, $e['date'], $e['ref'], $note, $this->ctx->user())) {
                        unset($openExp[(int)$t]);
                        $paidExp++;
                    } else {
                        $skipped[] = $e['ref'] . ' (náklad nie je neuhradený)';
                    }
                } catch (\PDOException $ex) {
                    $skipped[] = $e['ref'] . ' (už importovaná)';   // unique bank_ref
                }
                continue;
            }
            if (!$in) {
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
        Audit::log($this->ctx->db(), $this->ctx->user(), 'company', $cid, 'bank_import',
            $data['iban'] . ': ' . $imported . ' úhrad faktúr, ' . $paidExp . ' uhradených nákladov, ' . $newExp . ' nových nákladov');
        Session::flash('msg', 'Zapísaných úhrad faktúr: ' . $imported . ', uhradených nákladov: ' . $paidExp . ($newExp ? ', nových nákladov: ' . $newExp : '') . '.'
            . ($skipped ? ' Preskočené: ' . implode(', ', $skipped) . '.' : ''));
        return Response::redirect($this->ctx->url($in && ($imported || !$out) ? '/invoices?payment=unpaid' : '/expenses?status=open'));
    }
}
