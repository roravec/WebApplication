<?php
namespace Panel\Controller;

use Panel\Audit;
use Panel\Invoicing\Customers;
use Panel\PanelContext;
use WebApplication\Csrf;
use WebApplication\Http\Request;
use WebApplication\Http\Response;
use WebApplication\Session;

/** Customer directory (odberatelia) — each customer belongs to one company; employees see their companies' customers. */
class CustomersController
{
    /** @var PanelContext */
    private $ctx;

    public function __construct(PanelContext $ctx)
    {
        $this->ctx = $ctx;
    }

    public function index(Request $req, array $params): string
    {
        $q = trim((string)$req->query('q', ''));
        $page = max(1, (int)$req->query('page', 1));
        $ids = $this->ctx->companyIds();
        $res = $this->ctx->customers()->search($q, $ids, $page);
        $companies = $this->ctx->companies()->all($ids);
        return $this->ctx->page('customers/list', ['q' => $q, 'rows' => $res['rows'], 'total' => $res['total'], 'page' => $page,
            'pages' => (int)ceil($res['total'] / 50), 'companyNames' => count($companies) > 1 ? array_column($companies, 'name', 'id') : []],
            'Odberatelia', 'customers');
    }

    /** JSON for the invoice form: customers of ?company= matching ?q= (max 15). */
    public function lookup(Request $req, array $params): Response
    {
        $q = trim((string)$req->query('q', ''));
        $companyId = (int)$req->query('company', 0);
        if (strlen($q) < 2 || !$this->ctx->canCompany($companyId)) {
            return Response::json([]);
        }
        $out = [];
        foreach ($this->ctx->customers()->search($q, [$companyId], 1, 15)['rows'] as $r) {
            $out[] = array_intersect_key($r, array_flip(array_merge(['id'], Customers::FIELDS)));
        }
        return Response::json($out);
    }

    /** JSON: company data by IČO from the register of legal entities (RPO). */
    public function rpo(Request $req, array $params): Response
    {
        if (!$this->ctx->can(\Panel\Rights::INVOICES_EDIT) && !$this->ctx->can(\Panel\Rights::COMPANIES_MANAGE)
            && !$this->ctx->can(\Panel\Rights::EXPENSES_EDIT)) {
            return Response::json(['error' => 'Prístup zamietnutý.'], 403);
        }
        try {
            $r = \Panel\Invoicing\Rpo::lookup((string)$req->query('ico', ''));
        } catch (\Throwable $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
        return $r === null ? Response::json(['error' => 'IČO sa v registri nenašlo.'], 404)
            : Response::json($r + ['source' => \Panel\Invoicing\Rpo::SOURCE]);
    }

    public function form(Request $req, array $params, array $errors = [], ?array $values = null)
    {
        $customer = isset($params['id']) ? $this->load((int)$params['id']) : null;
        if ($customer instanceof Response) {
            return $customer;
        }
        $companies = $this->ctx->companies()->all($this->ctx->companyIds(), true);
        if (!$customer && !$companies) {
            return $this->ctx->page('invoices/no-company', [], 'Nový odberateľ', 'customers');
        }
        $values = $values ?? ($customer ?: array_merge(array_fill_keys(Customers::FIELDS, ''),
            ['type' => 'b2c', 'country' => 'SK', 'company_id' => (int)$companies[0]['id']]));
        return $this->ctx->page('customers/form', ['customer' => $customer, 'values' => $values, 'errors' => $errors,
            'companies' => $companies], $customer ? $customer['name'] : 'Nový odberateľ', 'customers');
    }

    public function save(Request $req, array $params)
    {
        if (!Csrf::check($req)) {
            return Response::text('Formulár vypršal, načítajte stránku znova.', 400);
        }
        $customer = isset($params['id']) ? $this->load((int)$params['id']) : null;
        if ($customer instanceof Response) {
            return $customer;
        }
        $v = [];
        foreach (Customers::FIELDS as $f) {
            $v[$f] = trim((string)$req->post($f, ''));
        }
        $v['type'] = $v['type'] === 'b2b' ? 'b2b' : 'b2c';
        $v['country'] = strtoupper($v['country']);
        $v['icdph'] = strtoupper(str_replace(' ', '', $v['icdph']));
        $v['company_id'] = $customer ? (int)$customer['company_id'] : (int)$req->post('company_id', 0);
        $errors = Customers::validate($v);
        if (!$this->ctx->canCompany($v['company_id'])) {
            $errors['company_id'] = 'Zvoľte firmu.';
        }
        if ($v['due_days'] !== '' && (!ctype_digit($v['due_days']) || (int)$v['due_days'] > 365)) {
            $errors['due_days'] = 'Splatnosť 0–365 dní alebo prázdne (podľa firmy).';
        }
        if ($errors) {
            return Response::html($this->form($req, $params, $errors, $v), 422);
        }
        $id = $this->ctx->customers()->save($customer ? (int)$customer['id'] : null, $v['company_id'], $v);
        Audit::log($this->ctx->db(), $this->ctx->user(), 'customer', $id, $customer ? 'update' : 'create');
        Session::flash('msg', 'Odberateľ uložený.');
        return Response::redirect($this->ctx->url('/customers'));
    }

    /** @return array|Response customer of a company the employee may access */
    private function load(int $id)
    {
        $c = $this->ctx->customers()->find($id);
        if ($c === null || !$this->ctx->canCompany((int)$c['company_id'])) {
            return Response::html($this->ctx->page('notfound', [], 'Odberateľ neexistuje', 'customers'), 404);
        }
        return $c;
    }
}
