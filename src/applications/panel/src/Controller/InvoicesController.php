<?php
namespace Panel\Controller;

use Panel\Audit;
use Panel\Invoicing\Calculator;
use Panel\Invoicing\Customers;
use Panel\Invoicing\Invoices;
use Panel\Invoicing\Legislation;
use Panel\Invoicing\OmegaExport;
use Panel\PanelContext;
use Panel\Rights;
use WebApplication\Csrf;
use WebApplication\Http\Request;
use WebApplication\Http\Response;
use WebApplication\Session;

/** Invoices and credit notes: list, draft form, issue, credit note, invoice from a shop order, print view. */
class InvoicesController
{
    /** @var PanelContext */
    private $ctx;

    public function __construct(PanelContext $ctx)
    {
        $this->ctx = $ctx;
    }

    public function index(Request $req, array $params): string
    {
        $f = [
            'company' => (int)$req->query('company', 0),
            'doc_type' => (string)$req->query('doc_type', ''),
            'status' => (string)$req->query('status', ''),
            'year' => (int)$req->query('year', 0),
            'q' => trim((string)$req->query('q', '')),
            'payment' => (string)$req->query('payment', ''),
        ];
        $page = max(1, (int)$req->query('page', 1));
        $ids = $this->ctx->companyIds();
        $res = $this->ctx->invoices()->search($f, $ids, $page);
        return $this->ctx->page('invoices/list', ['filters' => $f, 'rows' => $res['rows'], 'total' => $res['total'], 'sum' => $res['sum'],
            'page' => $page, 'pages' => (int)ceil($res['total'] / 50), 'companies' => $this->ctx->companies()->all($ids),
            'years' => $this->ctx->invoices()->years($ids)], 'Faktúry', 'invoices:list');
    }

    public function create(Request $req, array $params)
    {
        $companies = $this->ctx->companies()->all($this->ctx->companyIds(), true);
        if (!$companies) {
            return $this->ctx->page('invoices/no-company', [], 'Nová faktúra', 'invoices');
        }
        $companyId = (int)$req->query('company', 0);
        if (!$this->ctx->canCompany($companyId)) {
            $companyId = (int)$companies[0]['id'];
        }
        $h = $this->ctx->invoices()->defaults($companyId, 'invoice', $this->ctx->user());
        $lines = [['name' => '', 'sku' => '', 'quantity' => '1', 'unit' => 'ks', 'unit_price' => '',
                   'vat_rate' => $this->defaultRate($h), 'vat_category' => 'S']];
        return $this->renderForm(null, $h, $lines, []);
    }

    public function edit(Request $req, array $params)
    {
        $inv = $this->load((int)$params['id']);
        if ($inv instanceof Response) {
            return $inv;
        }
        if ($inv['status'] !== 'draft') {
            Session::flash('error', 'Vystavený doklad sa nedá meniť. Opravu urobte dobropisom.');
            return Response::redirect($this->ctx->url('/invoices/' . $inv['id']));
        }
        return $this->renderForm($inv, $inv, $inv['lines'], []);
    }

    public function save(Request $req, array $params)
    {
        if (!Csrf::check($req)) {
            return Response::text('Formulár vypršal, načítajte stránku znova.', 400);
        }
        $inv = null;
        if (isset($params['id'])) {
            $inv = $this->load((int)$params['id']);
            if ($inv instanceof Response) {
                return $inv;
            }
            if ($inv['status'] !== 'draft') {
                Session::flash('error', 'Vystavený doklad sa nedá meniť.');
                return Response::redirect($this->ctx->url('/invoices/' . $inv['id']));
            }
        }
        list($h, $lines, $errors) = $this->readForm($req, $inv);
        if ($errors) {
            return Response::html($this->renderForm($inv, $h, $lines, $errors), 422);
        }
        $repo = $this->ctx->invoices();
        $user = $this->ctx->user();
        if ($req->post('save_customer')) {
            $c = [];
            foreach (Customers::FIELDS as $f) {
                $c[$f] = $h['buyer_' . $f] ?? '';
            }
            $c['type'] = $h['buyer_type'];
            $existing = $h['customer_id'] ? $this->ctx->customers()->find((int)$h['customer_id']) : null;
            if ($existing) {
                $c['due_days'] = $existing['due_days'];
                $c['note'] = $existing['note'];
            }
            $h['customer_id'] = $this->ctx->customers()->save($existing ? (int)$existing['id'] : null, (int)$h['company_id'], $c);
        }
        try {
            $id = $repo->saveDraft($inv ? (int)$inv['id'] : null, $h, $lines, $user);
        } catch (\RuntimeException $e) {
            Session::flash('error', $e->getMessage());
            return Response::redirect($this->ctx->url('/invoices'));
        }
        if ($req->post('action') === 'issue') {
            return $this->doIssue($id);
        }
        Session::flash('msg', 'Koncept uložený.');
        return Response::redirect($this->ctx->url('/invoices/' . $id));
    }

    public function show(Request $req, array $params)
    {
        $inv = $this->load((int)$params['id']);
        if ($inv instanceof Response) {
            return $inv;
        }
        $repo = $this->ctx->invoices();
        $original = $inv['original_invoice_id'] ? $repo->find((int)$inv['original_invoice_id']) : null;
        return $this->ctx->page('invoices/show', [
            'inv' => $inv,
            'company' => $this->ctx->companies()->find((int)$inv['company_id']),
            'issueErrors' => $inv['status'] === 'draft' ? $repo->issueErrors($inv) : [],
            'issueWarnings' => $inv['status'] === 'draft' ? $repo->issueWarnings($inv) : [],
            'archived' => $inv['status'] === 'issued' ? $repo->storedPdf((int)$inv['id']) : null,
            'payments' => $repo->payments((int)$inv['id']),
            'paid' => $paid = $repo->paidSum((int)$inv['id']),
            'paymentState' => Invoices::paymentState($inv, $paid),
            'emails' => $repo->emails((int)$inv['id']),
            'ublErrors' => $inv['status'] === 'issued'
                ? \Panel\Invoicing\Ubl::check($inv, $inv['supplier'] ?: $this->ctx->companies()->supplierSnapshot((int)$inv['company_id'], $inv['delivery_date']))
                : [],
            'original' => $original,
            'creditNotes' => $inv['doc_type'] === 'invoice' ? $repo->creditNotesOf((int)$inv['id']) : [],
            'audit' => Audit::forEntity($this->ctx->db(), 'invoice', (int)$inv['id']),
            'canEdit' => $this->ctx->can(Rights::INVOICES_EDIT),
            // administrators: an issued document issued by mistake can go back to a draft (null = not shown)
            'unissueErrors' => $inv['status'] === 'issued' && $this->ctx->can(\Panel\Rights::INVOICES_UNISSUE) ? $repo->unissueErrors($inv) : null,
        ], $this->title($inv), 'invoices');
    }

    /** POST /invoices/{id}/unissue — administrators: a document issued by mistake back to a draft (Invoices::unissue). */
    public function unissue(Request $req, array $params)
    {
        if (!Csrf::check($req)) {
            return Response::text('Formulár vypršal, načítajte stránku znova.', 400);
        }
        $inv = $this->load((int)$params['id']);
        if ($inv instanceof Response) {
            return $inv;
        }
        $back = Response::redirect($this->ctx->url('/invoices/' . $inv['id']));
        if (!$req->post('confirm')) {
            Session::flash('error', 'Potvrďte, že doklad nikto mimo firmy nedostal.');
            return $back;
        }
        try {
            $number = $this->ctx->invoices()->unissue((int)$inv['id'], (string)$req->post('reason', ''), $this->ctx->user());
            Session::flash('msg', 'Doklad ' . $number . ' je znova koncept, číslo je uvoľnené. Pred novým vystavením skontrolujte'
                . ' „Ďalšie číslo“ číselného radu (Firmy → firma → Číselné rady).');
        } catch (\RuntimeException $e) {
            Session::flash('error', 'Doklad ostáva vystavený: ' . $e->getMessage());
        } catch (\InvalidArgumentException $e) {
            Session::flash('error', $e->getMessage());
        }
        return $back;
    }

    public function issue(Request $req, array $params)
    {
        if (!Csrf::check($req)) {
            return Response::text('Formulár vypršal, načítajte stránku znova.', 400);
        }
        $inv = $this->load((int)$params['id']);
        return $inv instanceof Response ? $inv : $this->doIssue((int)$inv['id']);
    }

    private function doIssue(int $id): Response
    {
        try {
            $num = $this->ctx->invoices()->issue($id, $this->ctx->user());
            // archive the PDF exactly as issued (10-year retention); a failure doesn't undo the issue
            $pdfError = $this->ctx->renderer()->archive($id);
            Session::flash('msg', 'Doklad vystavený pod číslom ' . $num['number'] . '.'
                . ($pdfError ? ' PDF sa zatiaľ nepodarilo uložiť (' . $pdfError . ') — vytvorí sa pri prvom otvorení.' : ''));
        } catch (\RuntimeException $e) {
            Session::flash('error', 'Doklad nebol vystavený: ' . $e->getMessage());
        }
        return Response::redirect($this->ctx->url('/invoices/' . $id));
    }

    public function delete(Request $req, array $params)
    {
        if (!Csrf::check($req)) {
            return Response::text('Formulár vypršal, načítajte stránku znova.', 400);
        }
        $inv = $this->load((int)$params['id']);
        if ($inv instanceof Response) {
            return $inv;
        }
        try {
            $this->ctx->invoices()->deleteDraft((int)$inv['id'], $this->ctx->user());
            Session::flash('msg', 'Koncept zmazaný.');
            return Response::redirect($this->ctx->url('/invoices'));
        } catch (\RuntimeException $e) {
            Session::flash('error', $e->getMessage());
            return Response::redirect($this->ctx->url('/invoices/' . $inv['id']));
        }
    }

    public function creditNote(Request $req, array $params)
    {
        if (!Csrf::check($req)) {
            return Response::text('Formulár vypršal, načítajte stránku znova.', 400);
        }
        $inv = $this->load((int)$params['id']);
        if ($inv instanceof Response) {
            return $inv;
        }
        try {
            $id = $this->ctx->invoices()->createCreditNote((int)$inv['id'], $this->ctx->user());
            Session::flash('msg', 'Koncept dobropisu vytvorený — upravte položky (záporné množstvá) a doplňte dôvod opravy.');
            return Response::redirect($this->ctx->url('/invoices/' . $id . '/edit'));
        } catch (\RuntimeException $e) {
            Session::flash('error', $e->getMessage());
            return Response::redirect($this->ctx->url('/invoices/' . $inv['id']));
        }
    }

    /** POST /orders/[{src}/]{id}/invoice — draft invoice from a shop order, for the company that invoices that e-shop. */
    public function fromOrder(Request $req, array $params)
    {
        if (!Csrf::check($req)) {
            return Response::text('Formulár vypršal, načítajte stránku znova.', 400);
        }
        $src = (int)($params['src'] ?? 0);
        $orderId = (int)$params['id'];
        $existing = $this->ctx->invoices()->findByOrder($src, $orderId);
        if ($existing !== null) {
            return Response::redirect($this->ctx->url('/invoices/' . $existing['id']));
        }
        $id = $this->draftFromOrder($src, $orderId);
        if ($id instanceof Response) {
            return $id;
        }
        Session::flash('msg', 'Koncept faktúry pripravený z objednávky. Skontrolujte ho a vystavte.');
        return Response::redirect($this->ctx->url('/invoices/' . $id));
    }

    /**
     * Order page, one click (owner 2026-11-20: "a button to generate the invoice when I am sure the order is OK"):
     * the draft from the order (when there is none yet) issued right away; back on the order page.
     */
    public function issueFromOrder(Request $req, array $params)
    {
        if (!Csrf::check($req)) {
            return Response::text('Formulár vypršal, načítajte stránku znova.', 400);
        }
        $src = (int)($params['src'] ?? 0);
        $orderId = (int)$params['id'];
        $back = Response::redirect($this->ctx->orderUrl($src, $orderId));
        $existing = $this->ctx->invoices()->findByOrder($src, $orderId);
        if ($existing !== null && $existing['status'] !== 'draft') {
            Session::flash('msg', 'Faktúra k objednávke už je vystavená: ' . $existing['number'] . '.');
            return $back;
        }
        $id = $existing !== null ? (int)$existing['id'] : $this->draftFromOrder($src, $orderId);
        if ($id instanceof Response) {
            return $id;
        }
        try {
            $num = $this->ctx->invoices()->issue($id, $this->ctx->user());
            $pdfError = $this->ctx->renderer()->archive($id);
            Session::flash('msg', 'Faktúra vystavená: ' . $num['number'] . '.' . ($pdfError ? ' PDF sa uloží pri prvom otvorení (' . $pdfError . ').' : '')
                . ' Zákazníkovi ju pošlete tlačidlom „Poslať faktúru zákazníkovi“.');
        } catch (\RuntimeException $e) {
            Session::flash('error', 'Faktúra nebola vystavená: ' . $e->getMessage() . ' Koncept nájdete v Faktúrach.');
        }
        return $back;
    }

    /**
     * Order page: the issued invoice to the customer with the company's e-mail text. Sent before → only after the
     * explicit second confirmation (field again=1), so the customer does not get it twice by mistake.
     */
    public function sendFromOrder(Request $req, array $params)
    {
        if (!Csrf::check($req)) {
            return Response::text('Formulár vypršal, načítajte stránku znova.', 400);
        }
        $src = (int)($params['src'] ?? 0);
        $orderId = (int)$params['id'];
        $back = Response::redirect($this->ctx->orderUrl($src, $orderId));
        $found = $this->ctx->invoices()->findByOrder($src, $orderId);
        $inv = $found ? $this->load((int)$found['id']) : null;
        if (!is_array($inv) || $inv['status'] !== 'issued') {
            Session::flash('error', 'Najprv vystavte faktúru.');
            return $back;
        }
        $sent = array_values(array_filter($this->ctx->invoices()->emails((int)$inv['id']),
            function ($m) { return $m['status'] === 'sent' && ($m['kind'] ?? 'invoice') === 'invoice'; }));
        if ($sent && !$req->post('again')) {
            Session::flash('error', 'Faktúra už bola zákazníkovi poslaná (' . $sent[0]['created_at'] . ', ' . $sent[0]['recipients']
                . '). Ak ju chcete poslať znova, potvrďte tlačidlom „Poslať znova“.');
            return $back;
        }
        $d = $this->emailDefaults($inv, 'invoice');
        $to = \Panel\Mailer::parseList($d['to']);
        if (!$to) {
            Session::flash('error', 'Objednávka nemá platný e-mail zákazníka — pošlite faktúru cez Faktúry → Poslať e-mailom.');
            return $back;
        }
        if (!filter_var($d['from'], FILTER_VALIDATE_EMAIL)) {
            Session::flash('error', 'Firma nemá platný e-mail odosielateľa (Firmy → E-mail s faktúrou).');
            return $back;
        }
        $error = $this->deliver($inv, $d, $to, [], \Panel\Mailer::parseList($d['bcc']) ?: [], $d['subject'], $d['text'], 'invoice');
        Session::flash($error === '' ? 'msg' : 'error', $error === '' ? 'Faktúra ' . $inv['number'] . ' poslaná zákazníkovi: ' . implode(', ', $to) . '.'
            : 'E-mail sa nepodarilo odoslať: ' . $error);
        return $back;
    }

    /** @return int|Response id of the new draft, or the answer (error message) */
    private function draftFromOrder(int $src, int $orderId)
    {
        $back = $this->ctx->orderUrl($src, $orderId);
        $source = $this->ctx->orderSource($src);
        try {
            $order = $source ? $source->find($orderId) : null;
        } catch (\Throwable $e) {
            Session::flash('error', 'E-shop neodpovedá: ' . $e->getMessage());
            return Response::redirect($back);
        }
        if ($order === null) {
            return Response::html($this->ctx->page('notfound', [], 'Objednávka neexistuje', 'orders'), 404);
        }
        $companyId = $this->ctx->companies()->companyForShop($src, (int)$order['id_shop']);
        if (!$companyId) {
            Session::flash('error', 'E-shop „' . $order['shop'] . '“ nie je priradený k žiadnej firme. Priraďte ho vo Firmy → firma → E-shopy.');
            return Response::redirect($back);
        }
        if (!$this->ctx->canCompany($companyId)) {
            Session::flash('error', 'Nemáte prístup k firme, ktorá fakturuje tento e-shop.');
            return Response::redirect($back);
        }
        try {
            return (int)$this->ctx->invoices()->createFromOrder($order, $companyId, $this->ctx->user());
        } catch (\RuntimeException $e) {
            Session::flash('error', $e->getMessage());
            return Response::redirect($back);
        }
    }

    /** A4 document for printing / saving as PDF (later also the source of the PDF file). */
    public function printView(Request $req, array $params)
    {
        $inv = $this->load((int)$params['id']);
        if ($inv instanceof Response) {
            return $inv;
        }
        return $this->ctx->renderer()->html($inv, false);
    }

    /** GET /invoices/{id}/pdf — issued: the archived PDF (exactly as issued); draft: generated now with "KONCEPT". */
    public function pdf(Request $req, array $params)
    {
        $inv = $this->load((int)$params['id']);
        if ($inv instanceof Response) {
            return $inv;
        }
        try {
            list($pdf, $sha) = $this->ctx->renderer()->pdf($inv);
        } catch (\Throwable $e) {
            $this->ctx->app->getLogger()->error('Invoice PDF failed', ['invoice' => $inv['id'], 'error' => $e->getMessage()]);
            Session::flash('error', 'PDF sa nepodarilo vytvoriť: ' . $e->getMessage());
            return Response::redirect($this->ctx->url('/invoices/' . $inv['id']));
        }
        $name = preg_replace('/[^A-Za-z0-9_-]+/', '-', $inv['number'] ?: 'koncept-' . $inv['id']) . '.pdf';
        $headers = ['Content-Type' => 'application/pdf', 'Content-Disposition' => ($req->query('download') ? 'attachment' : 'inline')
            . '; filename="' . $name . '"', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store'];
        if ($sha !== '') {
            $headers['X-Document-SHA256'] = $sha;
        }
        return new Response($pdf, 200, $headers);
    }

    /** GET /invoices/{id}/ubl — e-invoice UBL 2.1 / Peppol BIS Billing 3.0 (from the frozen data of the issued document). */
    public function ubl(Request $req, array $params)
    {
        $inv = $this->load((int)$params['id']);
        if ($inv instanceof Response) {
            return $inv;
        }
        $supplier = $inv['supplier'] ?: $this->ctx->companies()->supplierSnapshot((int)$inv['company_id'], $inv['delivery_date']);
        $errors = \Panel\Invoicing\Ubl::check($inv, $supplier);
        if ($errors) {
            Session::flash('error', 'E-faktúru nemožno vytvoriť: ' . implode(' ', $errors));
            return Response::redirect($this->ctx->url('/invoices/' . $inv['id']));
        }
        $legal = $inv['legal'] ?: (new Legislation($this->ctx->db()))->textsOn($inv['issue_date']);
        $original = $inv['original_invoice_id'] ? $this->ctx->invoices()->find((int)$inv['original_invoice_id']) : null;
        $xml = \Panel\Invoicing\Ubl::build($inv, $supplier, $legal, $original);
        $name = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string)$inv['number']) . '.xml';
        return new Response($xml, 200, ['Content-Type' => 'application/xml; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $name . '"', 'X-Content-Type-Options' => 'nosniff']);
    }

    // ------------------------------------------------------------------ payments

    /** POST /invoices/{id}/payments — record a payment (or a refund of a credit note). */
    public function addPayment(Request $req, array $params)
    {
        if (!Csrf::check($req)) {
            return Response::text('Formulár vypršal, načítajte stránku znova.', 400);
        }
        $inv = $this->load((int)$params['id']);
        if ($inv instanceof Response) {
            return $inv;
        }
        $back = Response::redirect($this->ctx->url('/invoices/' . $inv['id']) . '#payments');
        $date = (string)$req->post('paid_on', '');
        $amount = Calculator::parse($req->post('amount', ''));
        $method = (string)$req->post('method', 'transfer');
        if ($inv['status'] !== 'issued') {
            Session::flash('error', 'Úhradu možno zapísať len k vystavenému dokladu.');
        } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date) || $amount === null || abs($amount) < 0.005
            || !isset(Invoices::PAYMENT_METHODS[$method])) {
            Session::flash('error', 'Úhrada: zadajte dátum, sumu (nie 0) a spôsob.');
        } else {
            // a credit note is "paid" by a refund → the amount has the sign of the document
            $amount = (float)$inv['total'] < 0 ? -abs($amount) : abs($amount);
            $this->ctx->invoices()->addPayment((int)$inv['id'], $date, $amount, $method, trim((string)$req->post('note', '')), $this->ctx->user());
            Session::flash('msg', 'Úhrada zapísaná.');
        }
        return $back;
    }

    public function deletePayment(Request $req, array $params)
    {
        if (!Csrf::check($req)) {
            return Response::text('Formulár vypršal, načítajte stránku znova.', 400);
        }
        $inv = $this->load((int)$params['id']);
        if ($inv instanceof Response) {
            return $inv;
        }
        $ok = $this->ctx->invoices()->deletePayment((int)$inv['id'], (int)$params['pid'], $this->ctx->user());
        Session::flash($ok ? 'msg' : 'error', $ok ? 'Úhrada zmazaná.' : 'Úhrada neexistuje.');
        return Response::redirect($this->ctx->url('/invoices/' . $inv['id']) . '#payments');
    }

    // ------------------------------------------------------------------ e-mail

    /** Values of an e-mail with the document, from the company's templates (Firmy → E-mail s faktúrou). */
    private function emailDefaults(array $inv, string $kind = 'invoice'): array
    {
        $companies = $this->ctx->companies();
        $company = $companies->find((int)$inv['company_id']);
        $s = $companies->mailSettings((int)$inv['company_id']);
        $f = new \Panel\Format();
        $vars = [
            'document' => Invoices::typeLabel($inv['doc_type']), 'document_lower' => mb_strtolower(Invoices::typeLabel($inv['doc_type']), 'UTF-8'),
            'document_acc' => $inv['doc_type'] === 'credit_note' ? 'dobropis' : 'faktúru',   // "posielame faktúru"
            'number' => $inv['number'], 'total' => $f->money($inv['total'], $inv['currency']), 'due_date' => $f->date($inv['due_date']),
            'vs' => $inv['variable_symbol'], 'iban' => trim(chunk_split((string)$inv['iban'], 4, ' ')), 'order' => $inv['order_reference'],
            'company' => $company['name'], 'web' => $company['web'], 'email' => $company['email'], 'phone' => $company['phone'],
            'customer' => $inv['buyer_name'],
            'rest' => $f->money((float)$inv['total'] - $this->ctx->invoices()->paidSum((int)$inv['id']), $inv['currency']),
        ];
        $pay = $inv['payment_method'] === 'transfer' ? $s['pay_transfer'] : ($inv['payment_method'] === 'cod' ? $s['pay_cod'] : $s['pay_paid']);
        $vars['payment_info'] = Legislation::fill($pay, $vars);
        // document from an e-shop order: the shop's name, web and logo (owner 2026-11-20: the customer must see where he bought)
        $brand = $this->shopBrand($inv);
        $vars['shop'] = $brand ? $brand['name'] : (string)$company['name'];
        $vars['shop_web'] = $brand ? $brand['web'] : (string)$company['web'];
        $subject = $kind === 'reminder' ? $s['reminder_subject'] : $s['subject'];
        $body = $kind === 'reminder' ? $s['reminder_body'] : $s['body'];
        if ($brand) {   // texts the company has not changed → the e-shop version
            $key = $kind === 'reminder' ? 'reminder_' : '';
            $subject = $subject === \Panel\Invoicing\Companies::MAIL_DEFAULTS[$key . 'subject'] ? \Panel\Invoicing\Companies::SHOP_MAIL[$key . 'subject'] : $subject;
            $body = $body === \Panel\Invoicing\Companies::MAIL_DEFAULTS[$key . 'body'] ? \Panel\Invoicing\Companies::SHOP_MAIL[$key . 'body'] : $body;
        }
        return [
            'to' => (string)$inv['buyer_email'],
            'cc' => '',
            'bcc' => $s['bcc'],
            'subject' => Legislation::fill($subject, $vars),
            'text' => trim(preg_replace("/\n{3,}/", "\n\n", Legislation::fill($body, $vars))),
            'kind' => $kind,
            'from' => $s['from_email'] ?: (string)$company['email'],
            'from_name' => $s['from_name'] ?: ($brand ? $brand['name'] : (string)$company['name']),
            'reply_to' => $s['reply_to'],
            'brand' => $brand,
            'footer' => $brand ? Legislation::fill(($inv['doc_type'] === 'credit_note' ? 'Dobropis' : 'Faktúru')
                . ' vystavuje {company}, prevádzkovateľ e-shopu {shop}.', $vars) : '',
        ];
    }

    /** Name / web / logo of the e-shop the document's order came from (shop tables of this database); null otherwise. */
    private function shopBrand(array $inv): ?array
    {
        if (empty($inv['shop_id']) || (int)($inv['shop_connection_id'] ?? 0) !== 0 || !$this->ctx->shopOrders()->available()) {
            return null;
        }
        $b = $this->ctx->shopOrders()->brand((int)$inv['shop_id']);
        return $b['name'] !== '' ? $b : null;
    }

    /** HTML e-mail in the e-shop's look (Panel\ShopMail). */
    private function brandedHtml(array $brand, string $text, string $footer, bool $logo): string
    {
        return \Panel\ShopMail::html($brand, $text, $footer, $logo);
    }

    public function sendForm(Request $req, array $params, array $errors = [], ?array $values = null)
    {
        $inv = $this->load((int)$params['id']);
        if ($inv instanceof Response) {
            return $inv;
        }
        if ($inv['status'] !== 'issued') {
            Session::flash('error', 'E-mailom sa dá poslať len vystavený doklad.');
            return Response::redirect($this->ctx->url('/invoices/' . $inv['id']));
        }
        $kind = ($values['kind'] ?? $req->query('kind', 'invoice')) === 'reminder' ? 'reminder' : 'invoice';
        $d = $this->emailDefaults($inv, $kind);
        return $this->ctx->page('invoices/send', ['inv' => $inv, 'values' => $values ?? $d, 'defaults' => $d, 'errors' => $errors,
            'configured' => $this->mailer()->configured()],
            ($kind === 'reminder' ? 'Upomienka — ' : 'Poslať ') . mb_strtolower($this->title($inv), 'UTF-8'), 'invoices');
    }

    public function send(Request $req, array $params)
    {
        if (!Csrf::check($req)) {
            return Response::text('Formulár vypršal, načítajte stránku znova.', 400);
        }
        $inv = $this->load((int)$params['id']);
        if ($inv instanceof Response) {
            return $inv;
        }
        if ($inv['status'] !== 'issued') {
            return Response::redirect($this->ctx->url('/invoices/' . $inv['id']));
        }
        $kind = $req->post('kind') === 'reminder' ? 'reminder' : 'invoice';
        $d = $this->emailDefaults($inv, $kind);
        $v = ['to' => trim((string)$req->post('to', '')), 'cc' => trim((string)$req->post('cc', '')), 'bcc' => trim((string)$req->post('bcc', '')),
              'subject' => trim((string)$req->post('subject', '')), 'text' => trim((string)$req->post('text', ''))] + $d;
        $errors = [];
        $to = \Panel\Mailer::parseList($v['to']);
        $cc = \Panel\Mailer::parseList($v['cc']);
        $bcc = \Panel\Mailer::parseList($v['bcc']);
        if (!$to) {
            $errors['to'] = 'Zadajte aspoň jednu platnú e-mailovú adresu.';
        }
        if ($cc === null || $bcc === null) {
            $errors['cc'] = 'Neplatná adresa v kópii.';
        }
        if ($v['subject'] === '' || $v['text'] === '') {
            $errors['text'] = 'Vyplňte predmet aj text.';
        }
        if (!filter_var($d['from'], FILTER_VALIDATE_EMAIL)) {
            $errors['from'] = 'Firma nemá platný e-mail odosielateľa (Firmy → E-mail s faktúrou).';
        }
        if ($errors) {
            return Response::html($this->sendForm($req, $params, $errors, $v), 422);
        }
        $error = $this->deliver($inv, $d, $to, $cc ?: [], $bcc ?: [], $v['subject'], $v['text'], $kind);
        if ($error !== '') {
            return Response::html($this->sendForm($req, $params, ['send' => 'E-mail sa nepodarilo odoslať: ' . $error], $v), 502);
        }
        Session::flash('msg', ($kind === 'reminder' ? 'Upomienka odoslaná: ' : 'E-mail odoslaný: ') . implode(', ', $to) . '.');
        return Response::redirect($this->ctx->url('/invoices/' . $inv['id']));
    }

    /** E-mail with the PDF of the document; logged (sent / failed). @return string '' or the error */
    private function deliver(array $inv, array $d, array $to, array $cc, array $bcc, string $subject, string $text, string $kind): string
    {
        $recipients = array_merge($to, $cc, $bcc);
        try {
            list($pdf) = $this->ctx->renderer()->pdf($inv);
            $name = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string)$inv['number']) . '.pdf';
            $m = ['from' => $d['from'], 'from_name' => $d['from_name'], 'reply_to' => $d['reply_to'],
                'to' => $to, 'cc' => $cc, 'bcc' => $bcc, 'subject' => $subject, 'text' => $text,
                'attachments' => [[$name, $pdf, 'application/pdf']]];
            if (!empty($d['brand'])) {   // e-shop look: logo + text + who issues the document
                $logo = $d['brand']['logo'] !== '';
                $m['html'] = $this->brandedHtml($d['brand'], $text, (string)$d['footer'], $logo);
                $m['text'] = $text . ($d['footer'] !== '' ? "\n\n-- \n" . $d['footer'] : '');
                $m['embedded'] = $logo ? [['shoplogo', $d['brand']['logo'], $d['brand']['logo_mime'], 'logo']] : [];
            }
            $this->mailer()->send($m);
        } catch (\Throwable $e) {
            $this->ctx->invoices()->logEmail((int)$inv['id'], $recipients, $subject, 'failed', $e->getMessage(), $this->ctx->user(), $kind);
            return $e->getMessage();
        }
        $this->ctx->invoices()->logEmail((int)$inv['id'], $recipients, $subject, 'sent', '', $this->ctx->user(), $kind);
        return '';
    }

    private function mailer(): \Panel\Mailer
    {
        $app = $this->ctx->app;
        return new \Panel\Mailer((array)$app->getConfig()->get('apps.' . $app->getApplicationName() . '.mail', []));
    }

    // ------------------------------------------------------------------ export for the accountant (Omega)

    public function exportForm(Request $req, array $params, array $errors = [], ?array $values = null)
    {
        $companies = $this->ctx->companies()->all($this->ctx->companyIds());
        if (!$companies) {
            return $this->ctx->page('invoices/no-company', [], 'Export do Omegy', 'invoices:export');
        }
        $values = $values ?? ['company' => (int)$companies[0]['id'], 'from' => date('Y-m-01', strtotime('first day of last month')),
            'to' => date('Y-m-t', strtotime('last day of last month')), 'only_new' => 1, 'trial' => 0];
        return $this->ctx->page('invoices/export', ['companies' => $companies, 'values' => $values, 'errors' => $errors],
            'Export do Omegy', 'invoices:export');
    }

    /** POST: download the TXT file for Omega (Firma → Import → Import z textového súboru). */
    public function export(Request $req, array $params)
    {
        if (!Csrf::check($req)) {
            return Response::text('Formulár vypršal, načítajte stránku znova.', 400);
        }
        $v = ['company' => (int)$req->post('company', 0), 'from' => (string)$req->post('from', ''), 'to' => (string)$req->post('to', ''),
              'only_new' => $req->post('only_new') ? 1 : 0, 'trial' => $req->post('trial') ? 1 : 0];
        $errors = [];
        if (!$this->ctx->canCompany($v['company'])) {
            $errors['company'] = 'Zvoľte firmu.';
        }
        foreach (['from', 'to'] as $d) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v[$d])) {
                $errors[$d] = 'Zadajte dátum.';
            }
        }
        if ($errors) {
            return Response::html($this->exportForm($req, $params, $errors, $v), 422);
        }
        $repo = $this->ctx->invoices();
        $docs = $repo->forExport($v['company'], $v['from'], $v['to'], (bool)$v['only_new']);
        if (!$docs) {
            return Response::html($this->exportForm($req, $params, ['from' => 'V tomto období nie sú žiadne vystavené doklady'
                . ($v['only_new'] ? ', ktoré ešte neboli exportované' : '') . '.'], $v), 422);
        }
        $originals = [];
        foreach ($docs as $d) {
            if ($d['original_invoice_id']) {
                $o = $repo->find((int)$d['original_invoice_id']);
                $originals[(int)$d['original_invoice_id']] = $o ? (string)$o['number'] : '';
            }
        }
        $export = OmegaExport::create($this->ctx->companies()->exportSettings($v['company'])['omega']);
        try {
            $txt = $export->build($docs, $originals);
        } catch (\RuntimeException $e) {
            return Response::html($this->exportForm($req, $params, ['company' => $e->getMessage()], $v), 500);
        }
        if ($export->errors()) {
            return Response::html($this->exportForm($req, $params, ['docs' => $export->errors()], $v), 422);
        }
        // a trial file (e.g. for the accountant to test the import) does not mark the documents as exported — they still
        // go into the next real export / the monthly accountant package
        if (!$v['trial']) {
            $repo->markExported(array_map(function ($d) { return (int)$d['id']; }, $docs));
        }
        Audit::log($this->ctx->db(), $this->ctx->user(), 'company', $v['company'], 'export',
            'Omega' . ($v['trial'] ? ' (na skúšku)' : '') . ': ' . count($docs) . ' dokladov ' . $v['from'] . ' – ' . $v['to']);
        $name = 'omega-' . ($v['trial'] ? 'SKUSKA-' : '') . $v['from'] . '-' . $v['to'] . '.txt';
        return new Response($txt, 200, ['Content-Type' => 'text/plain; charset=windows-1250',
            'Content-Disposition' => 'attachment; filename="' . $name . '"', 'X-Content-Type-Options' => 'nosniff']);
    }

    // ------------------------------------------------------------------ helpers

    /** @return array|Response invoice the employee may access, or an error response */
    private function load(int $id)
    {
        $inv = $this->ctx->invoices()->find($id);
        if ($inv === null || !$this->ctx->canCompany((int)$inv['company_id'])) {
            return Response::html($this->ctx->page('notfound', [], 'Doklad neexistuje', 'invoices'), 404);
        }
        return $inv;
    }

    private function title(array $inv): string
    {
        return Invoices::typeLabel($inv['doc_type']) . ' ' . ($inv['number'] ?: '(koncept)');
    }

    private function renderForm(?array $inv, array $h, array $lines, array $errors): string
    {
        $companies = $this->ctx->companies();
        $companyId = (int)$h['company_id'];
        $company = $companies->find($companyId);
        $periods = $companies->vatPeriods($companyId);
        $vatPayer = $companies->vatOn($companyId, (string)$h['delivery_date'])['vat_payer'];
        $original = !empty($inv['original_invoice_id']) ? $this->ctx->invoices()->find((int)$inv['original_invoice_id']) : null;
        return $this->ctx->page('invoices/form', [
            'inv' => $inv, 'h' => $h, 'lines' => $lines, 'errors' => $errors, 'company' => $company,
            'companies' => $inv ? [] : $companies->all($this->ctx->companyIds(), true),
            'series' => $companies->series($companyId, (string)$h['doc_type'], true),
            'banks' => $companies->banks($companyId, true),
            'vatPayer' => $vatPayer,
            'showVat' => $vatPayer || (bool)array_filter(array_column($periods, 'vat_payer')),
            'original' => $original,
            'defaultRate' => $this->defaultRate($h),
            'rates' => array_map(function ($r) { return (float)$r['rate']; },
                (new Legislation($this->ctx->db()))->ratesOn($company ? (string)$company['country'] : 'SK', (string)$h['delivery_date'])),
        ], $inv ? 'Upraviť ' . mb_strtolower($this->title($inv), 'UTF-8') : 'Nová faktúra', 'invoices');
    }

    /** Standard VAT rate valid for the company's country on the delivery date (Nastavenia → Legislatíva). */
    private function defaultRate(array $h): string
    {
        $company = $this->ctx->companies()->find((int)$h['company_id']);
        $rate = (new Legislation($this->ctx->db()))->standardRate($company ? (string)$company['country'] : 'SK', (string)$h['delivery_date']);
        return $rate === null ? '0' : Calculator::fmt($rate);
    }

    /** @return array{0: array, 1: array, 2: array} [header, lines, errors] */
    private function readForm(Request $req, ?array $inv): array
    {
        $p = function ($k) use ($req) { return trim((string)$req->post($k, '')); };
        $h = [];
        foreach (Invoices::HEADER_FIELDS as $f) {
            $h[$f] = $p($f);
        }
        $errors = [];
        if ($inv) {   // fixed after creation
            $h['company_id'] = (int)$inv['company_id'];
            $h['doc_type'] = $inv['doc_type'];
        } else {
            $h['company_id'] = (int)$h['company_id'];
            $h['doc_type'] = 'invoice';
        }
        if (!$this->ctx->canCompany((int)$h['company_id'])) {
            $errors['company_id'] = 'Nemáte prístup k tejto firme.';
        }
        $companies = $this->ctx->companies();
        $series = $h['series_id'] ? $companies->oneSeries((int)$h['series_id']) : null;
        if ($h['series_id'] && ($series === null || (int)$series['company_id'] !== (int)$h['company_id'] || $series['doc_type'] !== $h['doc_type'])) {
            $errors['series_id'] = 'Neplatný číselný rad.';
        }
        $bank = $h['bank_account_id'] ? $companies->bank((int)$h['bank_account_id']) : null;
        if ($h['bank_account_id'] && ($bank === null || (int)$bank['company_id'] !== (int)$h['company_id'])) {
            $errors['bank_account_id'] = 'Neplatný bankový účet.';
        }
        foreach (['issue_date', 'delivery_date', 'due_date'] as $d) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $h[$d]) || !strtotime($h[$d])) {
                $errors[$d] = 'Zadajte dátum.';
            }
        }
        if (!isset(Invoices::PAYMENT_METHODS[$h['payment_method']])) {
            $errors['payment_method'] = 'Zvoľte formu úhrady.';
        }
        if (!preg_match('/^[A-Z]{3}$/', $h['currency'] = strtoupper($h['currency']))) {
            $errors['currency'] = 'Mena: trojpísmenový kód (EUR).';
        }
        $h['buyer_type'] = $h['buyer_type'] === 'b2b' ? 'b2b' : 'b2c';
        $h['buyer_country'] = strtoupper($h['buyer_country']);
        $h['buyer_ico'] = str_replace(' ', '', $h['buyer_ico']);
        $h['buyer_icdph'] = strtoupper(str_replace(' ', '', $h['buyer_icdph']));
        $buyer = [];
        foreach (['type', 'name', 'country', 'ico', 'email'] as $k) {
            $buyer[$k] = $h['buyer_' . $k];
        }
        $errors += Customers::validate($buyer, 'buyer_');
        if ($h['customer_id'] !== '') {   // only a customer of the same company
            $cust = $this->ctx->customers()->find((int)$h['customer_id']);
            if ($cust === null || (int)$cust['company_id'] !== (int)$h['company_id']) {
                $h['customer_id'] = '';
            }
        }

        $lines = [];
        $names = (array)$req->post('line_name', []);
        foreach ($names as $i => $name) {
            $l = [
                'name' => trim((string)$name),
                'sku' => trim((string)($req->post('line_sku')[$i] ?? '')),
                'quantity' => trim((string)($req->post('line_qty')[$i] ?? '')),
                'unit' => trim((string)($req->post('line_unit')[$i] ?? '')) ?: 'ks',
                'unit_price' => trim((string)($req->post('line_price')[$i] ?? '')),
                'vat_rate' => trim((string)($req->post('line_vat')[$i] ?? '0')),
                'vat_category' => strtoupper(trim((string)($req->post('line_cat')[$i] ?? 'S'))) ?: 'S',
            ];
            if ($l['name'] === '' && $l['unit_price'] === '' && $l['sku'] === '') {
                continue;   // empty row
            }
            $qty = Calculator::parse($l['quantity']);
            $price = Calculator::parse($l['unit_price']);
            $rate = Calculator::parse($l['vat_rate'] === '' ? '0' : $l['vat_rate']);
            if ($l['name'] === '' || $qty === null || $price === null || $rate === null || $qty == 0 || $rate < 0 || $rate > 100
                || !isset(Calculator::CATEGORIES[$l['vat_category']])) {
                $errors['lines'] = 'Skontrolujte položky: každá potrebuje názov, množstvo (nie 0), cenu a sadzbu DPH.';
            } else {
                $l['quantity'] = $qty;
                $l['unit_price'] = $price;
                $l['vat_rate'] = $rate;
            }
            $lines[] = $l;
        }
        if (mb_strlen($h['correction_reason'], 'UTF-8') > 255) {
            $errors['correction_reason'] = 'Najviac 255 znakov.';
        }
        return [$h, $lines, $errors];
    }
}
