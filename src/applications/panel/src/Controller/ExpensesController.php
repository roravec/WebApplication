<?php
namespace Panel\Controller;

use Panel\Expenses\Expenses;
use Panel\PanelContext;
use Panel\Rights;
use WebApplication\Csrf;
use WebApplication\Http\Request;
use WebApplication\Http\Response;
use WebApplication\Session;

/**
 * Náklady → prijaté doklady (docs/sprava-firmy-specifikacia.md, module C): supplier invoices, receipts and credit notes
 * with their files (PDF / photo), category, cost centre (e-shop), due date and payment. Never deleted — cancelled
 * with a reason. They go to the accountant in the monthly package (AccountingPackage::withExpenses).
 */
class ExpensesController
{
    const PER_PAGE = 50;

    /** @var PanelContext */
    private $ctx;

    public function __construct(PanelContext $ctx)
    {
        $this->ctx = $ctx;
    }

    private function x(): Expenses
    {
        return $this->ctx->expenses();
    }

    // ------------------------------------------------------------------ list

    public function index(Request $req, array $params)
    {
        $ids = $this->ctx->companyIds();
        $companies = $this->ctx->companies()->all($ids);
        $f = [
            'companies' => $ids,
            'company' => (int)$req->query('company', 0),
            'from' => self::date((string)$req->query('from', '')) ?: '',
            'to' => self::date((string)$req->query('to', '')) ?: '',
            'category' => (int)$req->query('category', 0),
            'shop' => (int)$req->query('shop', 0),
            'status' => in_array($req->query('status'), ['open', 'paid', 'cancelled', 'overdue'], true) ? (string)$req->query('status') : '',
            'q' => trim((string)$req->query('q', '')),
        ];
        if ($req->query('csv')) {
            return $this->csv($this->x()->search($f, 1, 100000)['rows']);
        }
        $this->x()->generateRecurring();   // due recurring costs appear as soon as somebody looks
        $page = max(1, (int)$req->query('page', 1));
        $res = $this->x()->search($f, $page, self::PER_PAGE);
        return $this->ctx->page('expenses/list', ['filters' => $f, 'rows' => $res['rows'], 'total' => $res['total'], 'sums' => $res['sums'],
            'page' => $page, 'pages' => max(1, (int)ceil($res['total'] / self::PER_PAGE)), 'companies' => $companies,
            'categories' => $this->x()->categories(), 'shops' => $this->shops(), 'canEdit' => $this->ctx->can(Rights::EXPENSES_EDIT)],
            'Náklady', 'expenses');
    }

    private function csv(array $rows): Response
    {
        $out = "\xEF\xBB\xBF" . implode(';', ['Dodanie', 'Vystavený', 'Splatnosť', 'Dodávateľ', 'IČO', 'Číslo dokladu', 'VS', 'Typ', 'Kategória',
            'Základ', 'DPH', 'Spolu', 'Mena', 'Úhrada', 'Stav', 'Uhradené dňa', 'Poznámka']) . "\r\n";
        $n = function ($x) { return number_format((float)$x, 2, ',', ''); };
        foreach ($rows as $r) {
            $out .= implode(';', array_map(function ($v) {
                $v = (string)$v;
                return preg_match('/[;"\r\n]/', $v) ? '"' . str_replace('"', '""', $v) . '"' : $v;
            }, [$r['delivery_date'], $r['issue_date'], $r['due_date'], $r['supplier_name'], $r['supplier_ico'], $r['document_number'],
                $r['variable_symbol'], Expenses::DOC_TYPES[$r['doc_type']] ?? $r['doc_type'], $r['category'], $n($r['total_net']),
                $n($r['total_vat']), $n($r['total']), $r['currency'], Expenses::PAYMENT_METHODS[$r['payment_method']] ?? $r['payment_method'],
                Expenses::STATUSES[$r['status']] ?? $r['status'], $r['paid_on'], $r['note']])) . "\r\n";
        }
        return new Response($out, 200, ['Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="naklady-' . date('Y-m-d') . '.csv"', 'Cache-Control' => 'private, no-store']);
    }

    // ------------------------------------------------------------------ form

    public function form(Request $req, array $params, array $errors = [], ?array $values = null, ?array $duplicate = null)
    {
        $exp = isset($params['id']) ? $this->load((int)$params['id']) : null;
        if ($exp instanceof Response) {
            return $exp;
        }
        $companies = $this->ctx->companies()->all($this->ctx->companyIds(), true);
        if (!$exp && !$companies) {
            return $this->ctx->page('invoices/no-company', [], 'Nový doklad', 'expenses');
        }
        if ($values === null) {
            if ($exp) {
                $values = $exp;
                if ($exp['doc_type'] === 'credit_note') {   // stored negative, shown as on the document
                    foreach (['total', 'total_vat', 'total_net'] as $k) {
                        $values[$k] = -(float)$exp[$k];
                    }
                }
            } else {
                $values = array_fill_keys(Expenses::FIELDS, '') + ['paid_on' => ''];
                $values = array_merge($values, ['company_id' => (int)$companies[0]['id'], 'doc_type' => 'invoice', 'supplier_country' => 'SK',
                    'currency' => 'EUR', 'payment_method' => 'transfer', 'delivery_date' => date('Y-m-d'), 'issue_date' => date('Y-m-d')]);
                // "Ďalší doklad od tohto dodávateľa": copy supplier, category, cost centre, payment
                $copy = (int)$req->query('copy', 0) ? $this->load((int)$req->query('copy')) : null;
                if (is_array($copy)) {
                    foreach (['company_id', 'shop_id', 'category_id', 'doc_type', 'supplier_name', 'supplier_ico', 'supplier_dic', 'supplier_icdph',
                                 'supplier_street', 'supplier_zip', 'supplier_city', 'supplier_country', 'supplier_iban', 'currency', 'payment_method'] as $k) {
                        $values[$k] = $copy[$k];
                    }
                }
            }
            foreach (['total', 'total_vat'] as $k) {
                if ($values[$k] !== '' && $values[$k] !== null) {
                    $values[$k] = number_format((float)$values[$k], 2, ',', '');
                }
            }
        }
        $vat = [];
        foreach ($companies as $c) {
            $vat[(int)$c['id']] = $this->ctx->companies()->vatOn((int)$c['id'], date('Y-m-d'))['vat_payer'];
        }
        return $this->ctx->page('expenses/form', ['exp' => $exp, 'values' => $values, 'errors' => $errors, 'companies' => $companies,
            'categories' => $this->x()->categories(true), 'shops' => $this->shops(), 'vat' => $vat, 'duplicate' => $duplicate,
            'suppliers' => $this->suppliers()],
            $exp ? 'Doklad ' . ($exp['document_number'] ?: '#' . $exp['id']) : 'Nový prijatý doklad', 'expenses');
    }

    public function save(Request $req, array $params)
    {
        if (!Csrf::check($req)) {
            return Response::text('Formulár vypršal, načítajte stránku znova.', 400);
        }
        $exp = isset($params['id']) ? $this->load((int)$params['id']) : null;
        if ($exp instanceof Response) {
            return $exp;
        }
        if ($exp && $exp['status'] === 'cancelled') {
            Session::flash('error', 'Stornovaný doklad sa nedá upraviť.');
            return Response::redirect($this->ctx->url('/expenses/' . $exp['id']));
        }
        $v = [];
        foreach (Expenses::FIELDS as $k) {
            $v[$k] = trim((string)$req->post($k, ''));
        }
        $v['paid_on'] = trim((string)$req->post('paid_on', ''));
        $errors = [];
        $v['company_id'] = $exp ? (int)$exp['company_id'] : (int)$v['company_id'];
        if (!$this->ctx->canCompany($v['company_id'])) {
            $errors['company_id'] = 'Zvoľte firmu.';
        }
        if (!isset(Expenses::DOC_TYPES[$v['doc_type']])) {
            $v['doc_type'] = 'invoice';
        }
        if (!isset(Expenses::PAYMENT_METHODS[$v['payment_method']])) {
            $v['payment_method'] = 'transfer';
        }
        if ($v['supplier_name'] === '') {
            $errors['supplier_name'] = 'Zadajte dodávateľa.';
        }
        $v['supplier_ico'] = str_replace(' ', '', $v['supplier_ico']);
        $v['supplier_icdph'] = strtoupper(str_replace(' ', '', $v['supplier_icdph']));
        $v['supplier_country'] = strtoupper($v['supplier_country']) ?: 'SK';
        if (!preg_match('/^[A-Z]{2}$/', $v['supplier_country'])) {
            $errors['supplier_country'] = 'Kód krajiny, napr. SK.';
        }
        $v['supplier_iban'] = strtoupper(str_replace(' ', '', $v['supplier_iban']));
        if ($v['supplier_iban'] !== '' && !preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{8,30}$/', $v['supplier_iban'])) {
            $errors['supplier_iban'] = 'Neplatný IBAN.';
        }
        $v['currency'] = strtoupper($v['currency']) ?: 'EUR';
        if (!preg_match('/^[A-Z]{3}$/', $v['currency'])) {
            $errors['currency'] = 'Mena, napr. EUR.';
        }
        foreach (['delivery_date' => true, 'issue_date' => false, 'due_date' => false, 'paid_on' => false] as $k => $required) {
            if ($v[$k] === '') {
                $v[$k] = null;
                if ($required) {
                    $errors[$k] = 'Zadajte dátum.';
                }
            } elseif (($d = self::date($v[$k])) === null) {
                $errors[$k] = 'Neplatný dátum.';
            } else {
                $v[$k] = $d;
            }
        }
        if (empty($errors['due_date']) && $v['due_date'] && $v['issue_date'] && $v['due_date'] < $v['issue_date']) {
            $errors['due_date'] = 'Splatnosť je pred dátumom vystavenia.';
        }
        $total = self::amount($v['total']);
        $vatAmount = $v['total_vat'] === '' ? 0.0 : self::amount($v['total_vat']);
        if ($total === null || $total <= 0) {
            $errors['total'] = 'Suma spolu (kladné číslo, napr. 123,45).';
        }
        if ($vatAmount === null || $vatAmount < 0 || ($total !== null && $vatAmount > $total)) {
            $errors['total_vat'] = 'DPH: 0 až suma spolu.';
        }
        // VAT is deductible only for a VAT payer (on the delivery date); a non-payer's cost is the whole amount (docs/legislativa.md)
        if (empty($errors['company_id']) && $v['delivery_date'] && !$this->ctx->companies()->vatOn($v['company_id'], $v['delivery_date'])['vat_payer']) {
            $vatAmount = 0.0;
            unset($errors['total_vat']);
        }
        // a credit note lowers the costs → stored negative; the form shows the amounts as on the document
        $sign = $v['doc_type'] === 'credit_note' ? -1 : 1;
        $v['total'] = $total !== null ? round($sign * $total, 2) : 0;
        $v['total_vat'] = $vatAmount !== null ? round($sign * $vatAmount, 2) : 0;
        $v['total_net'] = round($v['total'] - $v['total_vat'], 2);
        $v['category_id'] = (int)$v['category_id'] ?: null;
        $cats = array_column($this->x()->categories(), 'id');
        if ($v['category_id'] === null || !in_array((string)$v['category_id'], array_map('strval', $cats), true)) {
            $errors['category_id'] = 'Zvoľte kategóriu.';
        }
        $v['shop_id'] = (int)$v['shop_id'] ?: null;
        if ($v['shop_id'] !== null && !isset($this->shops()[$v['shop_id']])) {
            $v['shop_id'] = null;
        }
        $file = $req->file('file');
        $upload = null;
        if ($file !== null) {
            $upload = self::readFile($file);
            if (is_string($upload)) {
                $errors['file'] = $upload;
            }
        }
        $dup = null;
        if (!$errors && !$req->post('dup_ok')) {
            $dup = $this->x()->duplicate($v['company_id'], $v['supplier_ico'], $v['supplier_name'], $v['document_number'], $exp ? (int)$exp['id'] : null);
            if ($dup) {
                $errors['document_number'] = 'Tento doklad už je zadaný — skontrolujte, alebo potvrďte, že je to iný doklad.';
            }
        }
        if ($errors) {
            $shown = $v;
            foreach (['total', 'total_vat'] as $k) {
                $shown[$k] = trim((string)$req->post($k, ''));
            }
            if (!empty($errors['file'])) {
                $errors['file'] .= ' Ostatné údaje sú vyplnené — vyberte súbor znova.';
            } elseif ($upload) {
                $errors['file'] = 'Pre chybu vo formulári sa súbor neuložil — vyberte ho znova.';
            }
            return Response::html($this->form($req, $params, $errors, $shown, $dup), 422);
        }
        $paidOn = $v['paid_on'];
        unset($v['paid_on']);
        $user = $this->ctx->user();
        $id = $this->x()->save($exp ? (int)$exp['id'] : null, $v, $user);
        if (is_array($upload)) {
            $this->x()->addFile($id, $upload[0], $upload[1], $upload[2], $user);
        }
        if (!$exp && $paidOn !== null) {
            $saved = $this->x()->find($id);
            if ($saved['status'] === 'open') {
                $this->x()->markPaid($id, $paidOn, '', $user);
            }
        }
        Session::flash('msg', $exp ? 'Doklad uložený.' : 'Doklad pridaný.');
        if (!$exp && $req->post('next') === 'new') {
            return Response::redirect($this->ctx->url('/expenses/new'));
        }
        return Response::redirect($this->ctx->url('/expenses/' . $id));
    }

    // ------------------------------------------------------------------ detail and actions

    public function show(Request $req, array $params)
    {
        $exp = $this->load((int)$params['id']);
        if ($exp instanceof Response) {
            return $exp;
        }
        $company = $this->ctx->companies()->find((int)$exp['company_id']);
        $package = null;
        if ($exp['accounting_package_id']) {
            $package = $this->ctx->db()->fetchOne('SELECT * FROM ' . $this->ctx->db()->table('accounting_package') . ' WHERE id = ?',
                [(int)$exp['accounting_package_id']]) ?: null;
        }
        $recurring = $exp['recurring_id'] ? $this->x()->recurring((int)$exp['recurring_id']) : null;
        return $this->ctx->page('expenses/show', ['exp' => $exp, 'company' => $company,
            'recurring' => $recurring,
            'shops' => $this->shops(), 'package' => $package, 'canEdit' => $this->ctx->can(Rights::EXPENSES_EDIT)],
            'Doklad ' . ($exp['document_number'] ?: '#' . $exp['id']) . ' — ' . $exp['supplier_name'], 'expenses');
    }

    /** GET /expenses/{id}/files/{fid} — the file inline (preview) or ?download=1 */
    public function file(Request $req, array $params)
    {
        $exp = $this->load((int)$params['id']);
        if ($exp instanceof Response) {
            return $exp;
        }
        $f = $this->x()->file((int)$params['fid']);
        if ($f === null || (int)$f['expense_id'] !== (int)$exp['id']) {
            return Response::html($this->ctx->page('notfound', [], 'Súbor neexistuje', 'expenses'), 404);
        }
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string)$f['filename']) ?: 'doklad';
        $inline = !$req->query('download') && in_array($f['mime'], ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'], true);
        $headers = ['Content-Type' => $f['mime'], 'Content-Disposition' => ($inline ? 'inline' : 'attachment') . '; filename="' . $name . '"',
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store', 'X-Frame-Options' => 'SAMEORIGIN'];
        if ($f['mime'] !== 'application/pdf') {   // (a sandbox would stop the browser's PDF viewer)
            $headers['Content-Security-Policy'] = "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox";
        }
        return new Response((string)$f['data'], 200, $headers);
    }

    public function addFile(Request $req, array $params)
    {
        $exp = $this->action($req, $params);
        if ($exp instanceof Response) {
            return $exp;
        }
        $f = $req->file('file');
        $r = $f === null ? 'Vyberte súbor.' : self::readFile($f);
        if (is_string($r)) {
            Session::flash('error', $r);
        } else {
            $this->x()->addFile((int)$exp['id'], $r[0], $r[1], $r[2], $this->ctx->user());
            Session::flash('msg', 'Súbor priložený.');
        }
        return Response::redirect($this->ctx->url('/expenses/' . $exp['id']));
    }

    public function paid(Request $req, array $params)
    {
        $exp = $this->action($req, $params);
        if ($exp instanceof Response) {
            return $exp;
        }
        $date = self::date((string)$req->post('paid_on', ''));
        if ($date === null || $exp['status'] !== 'open') {
            Session::flash('error', $date === null ? 'Zadajte dátum úhrady.' : 'Doklad nie je neuhradený.');
        } else {
            $this->x()->markPaid((int)$exp['id'], $date, trim((string)$req->post('paid_ref', '')), $this->ctx->user());
            Session::flash('msg', 'Označené ako uhradené.');
        }
        return Response::redirect($this->ctx->url('/expenses/' . $exp['id']));
    }

    public function unpaid(Request $req, array $params)
    {
        $exp = $this->action($req, $params);
        if ($exp instanceof Response) {
            return $exp;
        }
        $this->x()->markOpen((int)$exp['id'], $this->ctx->user());
        Session::flash('msg', 'Úhrada zrušená — doklad je znova neuhradený.');
        return Response::redirect($this->ctx->url('/expenses/' . $exp['id']));
    }

    public function cancel(Request $req, array $params)
    {
        $exp = $this->action($req, $params);
        if ($exp instanceof Response) {
            return $exp;
        }
        $reason = trim((string)$req->post('reason', ''));
        if ($reason === '') {
            Session::flash('error', 'Na storno zadajte dôvod (napr. „zadaný dvakrát“).');
        } else {
            $this->x()->cancel((int)$exp['id'], $reason, $this->ctx->user());
            Session::flash('msg', 'Doklad stornovaný' . ($exp['accounting_package_id'] ? ' — už bol odoslaný účtovníkovi, dajte mu vedieť.' : '.'));
        }
        return Response::redirect($this->ctx->url('/expenses/' . $exp['id']));
    }

    // ------------------------------------------------------------------ recurring costs (C2)

    public function recurringIndex(Request $req, array $params)
    {
        $companies = $this->ctx->companies()->all($this->ctx->companyIds());
        return $this->ctx->page('expenses/recurring', ['rows' => $this->x()->recurringList($this->ctx->companyIds()),
            'companyNames' => count($companies) > 1 ? array_column($companies, 'name', 'id') : [], 'shops' => $this->shops(),
            'canEdit' => $this->ctx->can(Rights::EXPENSES_EDIT)], 'Opakované náklady', 'expenses');
    }

    public function recurringForm(Request $req, array $params, array $errors = [], ?array $values = null)
    {
        $rec = isset($params['id']) ? $this->loadRecurring((int)$params['id']) : null;
        if ($rec instanceof Response) {
            return $rec;
        }
        $companies = $this->ctx->companies()->all($this->ctx->companyIds(), true);
        if (!$rec && !$companies) {
            return $this->ctx->page('invoices/no-company', [], 'Opakovaný náklad', 'expenses');
        }
        if ($values === null) {
            if ($rec) {
                $values = $rec;
            } else {
                $values = array_fill_keys(Expenses::RECURRING_FIELDS, '');
                $values = array_merge($values, ['company_id' => (int)$companies[0]['id'], 'supplier_country' => 'SK', 'currency' => 'EUR',
                    'payment_method' => 'transfer', 'interval_months' => 1, 'due_days' => 14, 'active' => 1,
                    'next_date' => date('Y-m-d', strtotime('first day of next month'))]);
                // "Nastaviť opakovanie" on a document: the same supplier, amount and category, next date one month later
                $from = (int)$req->query('from', 0) ? $this->load((int)$req->query('from')) : null;
                if (is_array($from)) {
                    foreach (['company_id', 'shop_id', 'category_id', 'supplier_name', 'supplier_ico', 'supplier_dic', 'supplier_icdph', 'supplier_street',
                                 'supplier_zip', 'supplier_city', 'supplier_country', 'supplier_iban', 'variable_symbol', 'currency', 'total', 'total_vat',
                                 'payment_method'] as $k) {
                        $values[$k] = $from[$k];
                    }
                    $values['name'] = $from['supplier_name'] . ($from['category'] ? ' — ' . $from['category'] : '');
                    $day = (int)substr($from['delivery_date'], 8, 2);
                    $values['next_date'] = Expenses::addMonths($from['delivery_date'], 1, $day);
                    if ($from['due_date'] && $from['due_date'] >= $from['delivery_date']) {
                        $values['due_days'] = (int)round((strtotime($from['due_date']) - strtotime($from['delivery_date'])) / 86400);
                    }
                }
            }
            foreach (['total', 'total_vat'] as $k) {
                if ($values[$k] !== '' && $values[$k] !== null) {
                    $values[$k] = number_format(abs((float)$values[$k]), 2, ',', '');
                }
            }
        }
        $vat = [];
        foreach ($companies as $c) {
            $vat[(int)$c['id']] = $this->ctx->companies()->vatOn((int)$c['id'], date('Y-m-d'))['vat_payer'];
        }
        return $this->ctx->page('expenses/recurring-form', ['rec' => $rec, 'values' => $values, 'errors' => $errors, 'companies' => $companies,
            'categories' => $this->x()->categories(true), 'shops' => $this->shops(), 'vat' => $vat, 'suppliers' => $this->suppliers(),
            'generated' => $rec ? $this->ctx->db()->query('SELECT id, delivery_date, total, currency, status, document_number FROM '
                . $this->ctx->db()->table('expense') . ' WHERE recurring_id = ? ORDER BY delivery_date DESC LIMIT 24', [(int)$rec['id']]) : []],
            $rec ? 'Opakovaný náklad — ' . $rec['name'] : 'Nový opakovaný náklad', 'expenses');
    }

    public function recurringSave(Request $req, array $params)
    {
        if (!Csrf::check($req)) {
            return Response::text('Formulár vypršal, načítajte stránku znova.', 400);
        }
        $rec = isset($params['id']) ? $this->loadRecurring((int)$params['id']) : null;
        if ($rec instanceof Response) {
            return $rec;
        }
        $v = [];
        foreach (Expenses::RECURRING_FIELDS as $k) {
            $v[$k] = trim((string)$req->post($k, ''));
        }
        $errors = [];
        $v['company_id'] = $rec ? (int)$rec['company_id'] : (int)$v['company_id'];
        if (!$this->ctx->canCompany($v['company_id'])) {
            $errors['company_id'] = 'Zvoľte firmu.';
        }
        if ($v['name'] === '') {
            $errors['name'] = 'Zadajte názov, napr. „Nájom skladu“.';
        }
        if ($v['supplier_name'] === '') {
            $errors['supplier_name'] = 'Zadajte dodávateľa.';
        }
        $v['supplier_ico'] = str_replace(' ', '', $v['supplier_ico']);
        $v['supplier_icdph'] = strtoupper(str_replace(' ', '', $v['supplier_icdph']));
        $v['supplier_country'] = strtoupper($v['supplier_country']) ?: 'SK';
        if (!preg_match('/^[A-Z]{2}$/', $v['supplier_country'])) {
            $errors['supplier_country'] = 'Kód krajiny, napr. SK.';
        }
        $v['supplier_iban'] = strtoupper(str_replace(' ', '', $v['supplier_iban']));
        if ($v['supplier_iban'] !== '' && !preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{8,30}$/', $v['supplier_iban'])) {
            $errors['supplier_iban'] = 'Neplatný IBAN.';
        }
        $v['currency'] = strtoupper($v['currency']) ?: 'EUR';
        if (!preg_match('/^[A-Z]{3}$/', $v['currency'])) {
            $errors['currency'] = 'Mena, napr. EUR.';
        }
        if (!isset(Expenses::PAYMENT_METHODS[$v['payment_method']])) {
            $v['payment_method'] = 'transfer';
        }
        $v['interval_months'] = (int)$v['interval_months'];
        if (!isset(Expenses::INTERVALS[$v['interval_months']])) {
            $errors['interval_months'] = 'Zvoľte, ako často.';
        }
        if (!ctype_digit($v['due_days']) || (int)$v['due_days'] > 365) {
            $errors['due_days'] = 'Splatnosť 0–365 dní.';
        }
        $v['due_days'] = (int)$v['due_days'];
        $next = self::date($v['next_date']);
        if ($next === null) {
            $errors['next_date'] = 'Zadajte dátum ďalšieho dokladu.';
        } else {
            $v['next_date'] = $next;
            // day of the month: the last day stays the last day (31 → 30. 4., 28. 2.)
            $v['day'] = $next === date('Y-m-t', strtotime($next)) ? 31 : (int)substr($next, 8, 2);
        }
        if ($v['end_date'] === '') {
            $v['end_date'] = null;
        } elseif (($end = self::date($v['end_date'])) === null || ($next !== null && $end < $next)) {
            $errors['end_date'] = 'Koniec je neplatný alebo pred ďalším dokladom.';
        } else {
            $v['end_date'] = $end;
        }
        $total = self::amount($v['total']);
        $vatAmount = $v['total_vat'] === '' ? 0.0 : self::amount($v['total_vat']);
        if ($total === null || $total <= 0) {
            $errors['total'] = 'Suma spolu (kladné číslo, napr. 123,45).';
        }
        if ($vatAmount === null || $vatAmount < 0 || ($total !== null && $vatAmount > $total)) {
            $errors['total_vat'] = 'DPH: 0 až suma spolu.';
        }
        if (empty($errors['company_id']) && $next !== null && !$this->ctx->companies()->vatOn($v['company_id'], $next)['vat_payer']) {
            $vatAmount = 0.0;   // non-payer: no deductible VAT (docs/legislativa.md)
            unset($errors['total_vat']);
        }
        $v['total'] = $total !== null ? round($total, 2) : 0;
        $v['total_vat'] = $vatAmount !== null ? round($vatAmount, 2) : 0;
        $v['category_id'] = (int)$v['category_id'] ?: null;
        if ($v['category_id'] === null || !in_array($v['category_id'], array_map('intval', array_column($this->x()->categories(), 'id')), true)) {
            $errors['category_id'] = 'Zvoľte kategóriu.';
        }
        $v['shop_id'] = (int)$v['shop_id'] ?: null;
        if ($v['shop_id'] !== null && !isset($this->shops()[$v['shop_id']])) {
            $v['shop_id'] = null;
        }
        $v['active'] = $req->post('active') ? 1 : 0;
        $v['note'] = mb_substr($v['note'], 0, 255, 'UTF-8');
        if ($errors) {
            foreach (['total', 'total_vat'] as $k) {
                $v[$k] = trim((string)$req->post($k, ''));
            }
            return Response::html($this->recurringForm($req, $params, $errors, $v), 422);
        }
        $id = $this->x()->saveRecurring($rec ? (int)$rec['id'] : null, $v, $this->ctx->user());
        $made = $this->x()->generateRecurring(null, $this->ctx->user());   // a start date in the past creates the documents now
        Session::flash('msg', 'Opakovaný náklad uložený.' . ($made ? ' Vytvorené doklady: ' . count($made) . '.' : ''));
        return Response::redirect($this->ctx->url('/expenses/recurring/' . $id));
    }

    /** @return array|Response recurring cost of a company the employee may access */
    private function loadRecurring(int $id)
    {
        $r = $this->x()->recurring($id);
        if ($r === null || !$this->ctx->canCompany((int)$r['company_id'])) {
            return Response::html($this->ctx->page('notfound', [], 'Opakovaný náklad neexistuje', 'expenses'), 404);
        }
        return $r;
    }

    // ------------------------------------------------------------------ categories

    public function categories(Request $req, array $params)
    {
        return $this->ctx->page('expenses/categories', ['categories' => $this->x()->categories(),
            'canEdit' => $this->ctx->can(Rights::EXPENSES_EDIT)], 'Kategórie nákladov', 'expenses');
    }

    public function categoriesSave(Request $req, array $params)
    {
        if (!Csrf::check($req)) {
            return Response::text('Formulár vypršal, načítajte stránku znova.', 400);
        }
        $rows = [];
        foreach ((array)$req->post('cat', []) as $r) {
            if (is_array($r)) {
                $rows[] = $r;
            }
        }
        $this->x()->saveCategories($rows, $this->ctx->user());
        Session::flash('msg', 'Kategórie uložené.');
        return Response::redirect($this->ctx->url('/expenses/categories'));
    }

    // ------------------------------------------------------------------ helpers

    /** @return array|Response document of a company the employee may access */
    private function load(int $id)
    {
        $e = $this->x()->find($id);
        if ($e === null || !$this->ctx->canCompany((int)$e['company_id'])) {
            return Response::html($this->ctx->page('notfound', [], 'Doklad neexistuje', 'expenses'), 404);
        }
        return $e;
    }

    /** POST action on a document: CSRF + access. */
    private function action(Request $req, array $params)
    {
        if (!Csrf::check($req)) {
            return Response::text('Formulár vypršal, načítajte stránku znova.', 400);
        }
        return $this->load((int)$params['id']);
    }

    /** E-shops of this database the employee may see (cost centres): id => name */
    private function shops(): array
    {
        if (!$this->ctx->shopOrders()->available()) {
            return [];
        }
        $out = [];
        foreach ($this->ctx->salesReport()->shops() as $id => $name) {
            if ($this->ctx->shopVisible(0, (int)$id)) {   // its company + the person's e-shops
                $out[(int)$id] = $name;
            }
        }
        return $out;
    }

    /** Suppliers used before (for the form: name → data, newest document wins). */
    private function suppliers(): array
    {
        $ids = $this->ctx->companyIds();
        if (!$ids) {
            return [];
        }
        $t = $this->ctx->db()->table('expense');
        $rows = $this->ctx->db()->query("SELECT supplier_name, supplier_ico, supplier_dic, supplier_icdph, supplier_street, supplier_zip, supplier_city,
                supplier_country, supplier_iban, category_id, shop_id, payment_method, currency
            FROM $t WHERE id IN (SELECT MAX(id) FROM $t WHERE company_id IN (" . implode(',', array_map('intval', $ids)) . ") AND supplier_name <> ''
                GROUP BY supplier_name) ORDER BY supplier_name LIMIT 500");
        return $rows;
    }

    /** "12,50" / "1 234.5" → 1234.5; null when not a number */
    public static function amount(string $s): ?float
    {
        $s = str_replace([' ', "\xC2\xA0", '€'], '', trim($s));
        if (preg_match('/^\d+,\d+$/', $s) || preg_match('/^\d{1,3}(\.\d{3})+,\d+$/', $s)) {
            $s = str_replace(['.', ','], ['', '.'], $s);
        }
        return preg_match('/^-?\d+(\.\d{1,2})?$/', $s) ? (float)$s : null;
    }

    /** Y-m-d or d.m.Y → Y-m-d; null when not a valid date */
    public static function date(string $s): ?string
    {
        $s = trim($s);
        if (preg_match('/^(\d{1,2})\.\s*(\d{1,2})\.\s*(\d{4})$/', $s, $m)) {
            $s = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        }
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
            return null;
        }
        return $s;
    }

    /** @return array{0: string, 1: string, 2: string}|string [name, mime, bytes] or an error message */
    private static function readFile(array $f)
    {
        if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE) {
            return 'Súbor je príliš veľký (najviac ' . min(10, self::iniMb()) . ' MB).';
        }
        if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
            return 'Nahranie súboru zlyhalo.';
        }
        if ($f['size'] > Expenses::MAX_FILE) {
            return 'Súbor môže mať najviac 10 MB.';
        }
        $bytes = (string)file_get_contents($f['tmp_name']);
        $mime = self::mime($bytes);
        if (!isset(Expenses::FILE_TYPES[$mime])) {
            return 'Len PDF, fotka (JPG, PNG, WEBP, HEIC) alebo XML (e-faktúra).';
        }
        $name = trim(preg_replace('/[\x00-\x1F\/\\\\]+/', '', (string)$f['name'])) ?: 'doklad.' . Expenses::FILE_TYPES[$mime];
        return [$name, $mime, $bytes];
    }

    /** File type by content (not by the name the browser sent). */
    public static function mime(string $b): string
    {
        if (strncmp($b, '%PDF', 4) === 0) {
            return 'application/pdf';
        }
        if (strncmp($b, "\xFF\xD8\xFF", 3) === 0) {
            return 'image/jpeg';
        }
        if (strncmp($b, "\x89PNG", 4) === 0) {
            return 'image/png';
        }
        if (strncmp($b, 'RIFF', 4) === 0 && substr($b, 8, 4) === 'WEBP') {
            return 'image/webp';
        }
        if (substr($b, 4, 4) === 'ftyp' && in_array(substr($b, 8, 4), ['heic', 'heix', 'mif1', 'msf1', 'heim', 'heis'], true)) {
            return 'image/heic';
        }
        $head = ltrim(substr($b, 0, 200), "\xEF\xBB\xBF \t\r\n");
        if (strncmp($head, '<?xml', 5) === 0 || preg_match('/^<[A-Za-z][\w:.-]*[\s>]/', $head)) {
            return 'application/xml';
        }
        return 'application/octet-stream';
    }

    private static function iniMb(): int
    {
        $v = (string)ini_get('upload_max_filesize');
        $n = (int)$v;
        $u = strtoupper(substr($v, -1));
        return $u === 'G' ? $n * 1024 : ($u === 'M' ? $n : max(1, (int)($n / 1048576)));
    }
}
