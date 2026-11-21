<?php
namespace Panel\Controller;

use Panel\Audit;
use Panel\Invoicing\Companies;
use Panel\Invoicing\Numbering;
use Panel\Invoicing\OmegaExport;
use Panel\PanelContext;
use Panel\Rights;
use WebApplication\Csrf;
use WebApplication\Http\Request;
use WebApplication\Http\Response;
use WebApplication\Session;

/** Supplier companies: details, VAT periods, bank accounts, logo + stamp, e-shops, number series. */
class CompaniesController
{
    const MAX_IMAGE = 2 * 1024 * 1024;

    /** @var PanelContext */
    private $ctx;

    public function __construct(PanelContext $ctx)
    {
        $this->ctx = $ctx;
    }

    public function index(Request $req, array $params): string
    {
        $repo = $this->ctx->companies();
        $list = $repo->all($this->ctx->companyIds());
        foreach ($list as &$c) {
            $c['vat'] = $repo->vatOn((int)$c['id'], date('Y-m-d'));
            $c['series'] = $repo->series((int)$c['id'], null, true);
        }
        return $this->ctx->page('companies/list', ['companies' => $list], 'Firmy', 'settings:companies');
    }

    public function form(Request $req, array $params, array $errors = [], ?array $values = null)
    {
        $repo = $this->ctx->companies();
        $id = isset($params['id']) ? (int)$params['id'] : 0;
        $company = null;
        if ($id) {
            if (!$this->ctx->canCompany($id) || ($company = $repo->find($id)) === null) {
                return $this->notFound();
            }
        }
        if ($values === null) {
            $values = $company ?: ['name' => '', 'street' => '', 'city' => '', 'zip' => '', 'country' => 'SK', 'ico' => '', 'dic' => '',
                'registration' => '', 'email' => '', 'phone' => '', 'web' => '', 'slogan' => '', 'due_days' => 14,
                'invoice_note' => '', 'accent_color' => '#f74040', 'active' => 1];
            $values['vat'] = $id ? $repo->vatPeriods($id) : [];
            $values['banks'] = $id ? $repo->banks($id) : [];
            $values['shops'] = $id ? array_map('strval', array_keys(array_filter($repo->shopMap(), function ($c) use ($id) { return $c === $id; }))) : [];
            $values['omega'] = $id ? $repo->exportSettings($id)['omega'] : OmegaExport::DEFAULTS;
            $values['accountant'] = $id ? $repo->exportSettings($id)['accountant'] : \Panel\Invoicing\AccountingPackage::DEFAULTS;
            $values['mail'] = $id ? $repo->mailSettings($id) : Companies::MAIL_DEFAULTS;
            $values['costs'] = $id ? $repo->exportSettings($id)['costs'] : Companies::COSTS_DEFAULTS;
            $values['summary'] = $id ? $repo->exportSettings($id)['summary'] : Companies::SUMMARY_DEFAULTS;
        }
        $shops = $this->shopGroups();
        $series = [];
        if ($id) {
            foreach ($repo->series($id) as $s) {
                $s['next'] = Numbering::peek($this->ctx->db(), $s, date('Y-m-d'));
                $s['example'] = Numbering::format($s['format'], $s['next'], date('Y-m-d'));
                $series[] = $s;
            }
        }
        return $this->ctx->page('companies/form', ['company' => $company, 'values' => $values, 'errors' => $errors,
            'shops' => $shops, 'shopMap' => $repo->shopMap(), 'allCompanies' => $repo->all($this->ctx->companyIds()),
            'series' => $series], $company ? $company['name'] : 'Nová firma', 'settings:companies');
    }

    public function save(Request $req, array $params)
    {
        if (!Csrf::check($req)) {
            return Response::text('Formulár vypršal, načítajte stránku znova.', 400);
        }
        $repo = $this->ctx->companies();
        $id = isset($params['id']) ? (int)$params['id'] : 0;
        if ($id && (!$this->ctx->canCompany($id) || $repo->find($id) === null)) {
            return $this->notFound();
        }
        $p = function ($k) use ($req) { return trim((string)$req->post($k, '')); };
        $v = [];
        foreach (['name', 'street', 'city', 'zip', 'ico', 'dic', 'registration', 'email', 'phone', 'web', 'slogan', 'invoice_note'] as $k) {
            $v[$k] = $p($k);
        }
        $v['country'] = strtoupper($p('country'));
        $v['ico'] = str_replace(' ', '', $v['ico']);
        $v['due_days'] = (int)$p('due_days');
        $v['accent_color'] = strtolower($p('accent_color'));
        $v['active'] = $req->post('active') ? 1 : 0;

        $errors = [];
        if ($v['name'] === '') {
            $errors['name'] = 'Zadajte obchodné meno.';
        }
        if (!preg_match('/^[A-Z]{2}$/', $v['country'])) {
            $errors['country'] = 'Krajina: dvojpísmenový kód (SK).';
        }
        if ($v['ico'] !== '' && !preg_match('/^\d{6,12}$/', $v['ico'])) {
            $errors['ico'] = 'IČO: 6–12 číslic.';
        }
        if ($v['email'] !== '' && !filter_var($v['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Neplatný e-mail.';
        }
        if ($v['due_days'] < 0 || $v['due_days'] > 365) {
            $errors['due_days'] = 'Splatnosť 0–365 dní.';
        }
        if (!preg_match('/^#[0-9a-f]{6}$/', $v['accent_color'])) {
            $errors['accent_color'] = 'Farba v tvare #rrggbb.';
        }

        // VAT periods
        $v['vat'] = [];
        $from = (array)$req->post('vat_from', []);
        $payer = (array)$req->post('vat_payer', []);
        $icdph = (array)$req->post('vat_icdph', []);
        $dates = [];
        foreach ($from as $i => $d) {
            $row = ['valid_from' => trim((string)$d), 'vat_payer' => (int)($payer[$i] ?? 0), 'icdph' => strtoupper(str_replace(' ', '', (string)($icdph[$i] ?? '')))];
            if ($row['valid_from'] === '' && $row['icdph'] === '') {
                continue;
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $row['valid_from']) || isset($dates[$row['valid_from']])) {
                $errors['vat'] = 'Každé obdobie DPH musí mať iný, platný dátum „od“.';
            }
            if ($row['vat_payer'] && !preg_match('/^[A-Z]{2}[0-9A-Z]{2,13}$/', $row['icdph'])) {
                $errors['vat'] = 'Platiteľ DPH musí mať IČ DPH (napr. SK2121232916).';
            }
            $dates[$row['valid_from']] = true;
            $v['vat'][] = $row;
        }

        // bank accounts
        $v['banks'] = [];
        $bankIds = (array)$req->post('bank_id', []);
        $bankNames = (array)$req->post('bank_name', []);
        $bankIbans = (array)$req->post('bank_iban', []);
        $bankBics = (array)$req->post('bank_bic', []);
        $bankBanks = (array)$req->post('bank_bank', []);
        $bankActive = (array)$req->post('bank_active', []);
        $bankDefault = (array)$req->post('bank_default', []);
        $haveDefault = false;
        foreach ($bankIbans as $i => $iban) {
            $iban = strtoupper(str_replace(' ', '', (string)$iban));
            if ($iban === '' && (int)($bankIds[$i] ?? 0) === 0) {
                continue;
            }
            if (!self::validIban($iban)) {
                $errors['banks'] = 'Neplatný IBAN: ' . $iban;
            }
            $v['banks'][] = ['id' => (int)($bankIds[$i] ?? 0), 'account_name' => trim((string)($bankNames[$i] ?? '')) ?: $v['name'],
                'iban' => $iban, 'bic' => strtoupper(trim((string)($bankBics[$i] ?? ''))),
                'bank' => mb_substr(trim((string)($bankBanks[$i] ?? '')), 0, 100, 'UTF-8'),
                'is_default' => false, 'active' => (int)($bankActive[$i] ?? 1) === 1];
            $last = count($v['banks']) - 1;
            if (!$haveDefault && (int)($bankDefault[$i] ?? 0) === 1 && $v['banks'][$last]['active']) {
                $v['banks'][$last]['is_default'] = $haveDefault = true;
            }
        }
        if ($v['banks'] && !array_filter(array_column($v['banks'], 'is_default'))) {
            foreach ($v['banks'] as &$b) {
                if ($b['active']) {
                    $b['is_default'] = true;
                    break;
                }
            }
            unset($b);
        }
        $v['shops'] = array_values(array_filter(array_map('strval', (array)$req->post('shops', [])), function ($k) { return (bool)preg_match('/^\d+:\d+$/', $k); }));
        $v['shop_sources'] = array_map('intval', (array)$req->post('shop_sources', []));
        $tokens = [];
        $bankTokens = (array)$req->post('bank_token', []);
        foreach ($bankIbans as $i => $iban) {
            if (strtoupper(str_replace(' ', '', (string)$iban)) === '' && (int)($bankIds[$i] ?? 0) === 0) {
                continue;   // the same rows that were skipped above
            }
            $t = trim((string)($bankTokens[$i] ?? ''));
            if ($t !== '' && $t !== 'zmazať') {
                $t = preg_replace('/\s+/', '', $t);   // pasted with a line break / spaces
                if (!preg_match('/^[A-Za-z0-9]{64}$/', $t)) {
                    $errors['banks'] = 'Fio token má presne 64 znakov (písmená a číslice) — tento má ' . strlen($t)
                        . '. Skopírujte ho celý z internetbankingu (Nastavenia → API).';
                }
            }
            $tokens[] = $t;
        }
        $secret = null;
        if (array_filter($tokens, function ($t) { return $t !== '' && $t !== 'zmazať'; })) {
            try {
                $secret = new \Panel\Secret($this->ctx->app->getJwtSecret());
            } catch (\RuntimeException $e) {
                $errors['banks'] = $e->getMessage();
            }
        }
        list($v['omega'], $omegaErrors) = OmegaExport::readSettings($req->post());
        $errors += $omegaErrors;
        list($v['accountant'], $accErrors) = \Panel\Invoicing\AccountingPackage::readSettings($req->post());
        $errors += $accErrors;
        $v['costs'] = ['purchase_vat' => $req->post('cost_purchase_vat') === 'add_vat' ? 'add_vat' : 'as_entered'];
        $v['summary'] = ['email' => trim((string)$req->post('sum_email', '')), 'auto' => $req->post('sum_auto') ? 1 : 0];
        if ($v['summary']['email'] !== '' && \Panel\Mailer::parseList($v['summary']['email']) === null) {
            $errors['summary'] = 'Neplatná e-mailová adresa pre mesačný súhrn.';
        } elseif ($v['summary']['auto'] && $v['summary']['email'] === '') {
            $errors['summary'] = 'Na posielanie mesačného súhrnu zadajte e-mail.';
        }
        $v['mail'] = [];
        foreach (array_keys(Companies::MAIL_DEFAULTS) as $k) {
            $v['mail'][$k] = trim((string)$req->post('mail_' . $k, ''));
        }
        foreach (['from_email', 'reply_to'] as $k) {
            if ($v['mail'][$k] !== '' && !filter_var($v['mail'][$k], FILTER_VALIDATE_EMAIL)) {
                $errors['mail'] = 'Neplatná e-mailová adresa v nastavení e-mailu.';
            }
        }
        if ($v['mail']['bcc'] !== '' && \Panel\Mailer::parseList($v['mail']['bcc']) === null) {
            $errors['mail'] = 'Neplatná adresa v skrytej kópii.';
        }
        foreach (['subject', 'body', 'pay_transfer', 'pay_paid', 'pay_cod', 'reminder_subject', 'reminder_body'] as $k) {
            if ($v['mail'][$k] === '') {
                $v['mail'][$k] = Companies::MAIL_DEFAULTS[$k];   // empty = default text
            }
        }

        // images
        $images = [];
        foreach (['logo', 'stamp'] as $kind) {
            $f = $req->file($kind);
            if ($f !== null) {
                $img = self::readImage($f);
                if (is_string($img)) {
                    $errors[$kind] = $img;
                } else {
                    $images[$kind] = $img;
                }
            } elseif ($req->post('remove_' . $kind)) {
                $images[$kind] = null;
            }
        }

        if ($errors) {
            return Response::html($this->form($req, $params, $errors, $v), 422);
        }
        $db = $this->ctx->db();
        $user = $this->ctx->user();
        $isNew = !$id;
        $id = $db->transaction(function () use ($repo, $id, $v, $images, $db, $user, $tokens, $secret) {
            $id = $repo->save($id ?: null, $v);
            $repo->saveVatPeriods($id, $v['vat']);
            foreach ($repo->saveBanks($id, $v['banks']) as $i => $bankId) {
                $t = $tokens[$i] ?? '';
                if ($t === 'zmazať') {
                    $repo->setBankApi($bankId, $id, '', null);
                } elseif ($t !== '' && $secret) {
                    $repo->setBankApi($bankId, $id, 'fio', $secret->encrypt($t));
                }
            }
            $repo->saveShops($id, $v['shops'], $v['shop_sources']);
            $repo->saveExportSettings($id, array_merge($repo->exportSettings($id), ['omega' => $v['omega'], 'accountant' => $v['accountant'],
                'costs' => $v['costs'], 'summary' => $v['summary']]));
            $repo->saveMailSettings($id, $v['mail']);
            foreach ($images as $kind => $img) {
                $repo->setImage($id, $kind, $img === null ? null : $img[0], $img === null ? '' : $img[1]);
            }
            return $id;
        });
        if ($isNew) {
            (new \Panel\Core\Roles($db))->grantCompany($user->getId(), $id);
            // ready-to-use number series; the first number of the new system can be set on the series
            $repo->saveSeries(null, $id, ['doc_type' => 'invoice', 'name' => 'Faktúry', 'format' => 'FA{RRRR}/{NNNNNN}',
                'vs_format' => '', 'reset_yearly' => 1, 'is_default' => 1, 'active' => 1]);
            $repo->saveSeries(null, $id, ['doc_type' => 'credit_note', 'name' => 'Dobropisy', 'format' => 'DB{RRRR}/{NNNN}',
                'vs_format' => '', 'reset_yearly' => 1, 'is_default' => 1, 'active' => 1]);
        }
        Audit::log($db, $user, 'company', $id, $isNew ? 'create' : 'update');
        Session::flash('msg', 'Firma uložená.');
        return Response::redirect($this->ctx->url('/companies/' . $id));
    }

    public function image(Request $req, array $params): Response
    {
        $id = (int)$params['id'];
        $img = $this->ctx->canCompany($id) ? $this->ctx->companies()->image($id, $params['kind']) : null;
        if ($img === null) {
            return Response::text('Not Found', 404);
        }
        return (new Response($img[0], 200, ['Content-Type' => $img[1], 'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff']));
    }

    /** GET /companies/{id}/preview[?pdf=1] — sample invoice with the company's current data, logo, stamp and account. */
    public function preview(Request $req, array $params): Response
    {
        $id = (int)$params['id'];
        if (!$this->ctx->canCompany($id) || $this->ctx->companies()->find($id) === null) {
            return Response::text('Not Found', 404);
        }
        $renderer = $this->ctx->renderer();
        $inv = $renderer->sample($id);
        try {
            if ($req->query('pdf')) {
                return new Response(\Panel\Invoicing\Pdf::fromHtml($renderer->html($inv, true)), 200, ['Content-Type' => 'application/pdf',
                    'Content-Disposition' => 'inline; filename="nahlad-faktury.pdf"', 'Cache-Control' => 'private, no-store']);
            }
            return Response::html($renderer->html($inv, false));
        } catch (\Throwable $e) {
            return Response::text('Náhľad sa nepodarilo vytvoriť: ' . $e->getMessage(), 500);
        }
    }

    // ------------------------------------------------------------------ number series

    public function seriesForm(Request $req, array $params, array $errors = [], ?array $values = null)
    {
        $repo = $this->ctx->companies();
        $companyId = (int)$params['id'];
        $company = $this->ctx->canCompany($companyId) ? $repo->find($companyId) : null;
        $series = isset($params['sid']) ? $repo->oneSeries((int)$params['sid']) : null;
        if ($company === null || (isset($params['sid']) && ($series === null || (int)$series['company_id'] !== $companyId))) {
            return $this->notFound();
        }
        $year = (int)date('Y');
        if ($values === null) {
            $values = $series ?: ['doc_type' => 'invoice', 'name' => '', 'format' => 'FA{RRRR}/{NNNNNN}', 'vs_format' => '',
                'reset_yearly' => 1, 'is_default' => 0, 'active' => 1];
            $values['next'] = $series ? Numbering::peek($this->ctx->db(), $series, date('Y-m-d')) : 1;
        }
        return $this->ctx->page('companies/series', ['company' => $company, 'series' => $series, 'values' => $values,
            'errors' => $errors, 'used' => $series ? $repo->seriesUsed((int)$series['id']) : false, 'year' => $year],
            $series ? 'Číselný rad ' . $series['name'] : 'Nový číselný rad', 'settings:companies');
    }

    public function seriesSave(Request $req, array $params)
    {
        if (!Csrf::check($req)) {
            return Response::text('Formulár vypršal, načítajte stránku znova.', 400);
        }
        $repo = $this->ctx->companies();
        $companyId = (int)$params['id'];
        $company = $this->ctx->canCompany($companyId) ? $repo->find($companyId) : null;
        $series = isset($params['sid']) ? $repo->oneSeries((int)$params['sid']) : null;
        if ($company === null || (isset($params['sid']) && ($series === null || (int)$series['company_id'] !== $companyId))) {
            return $this->notFound();
        }
        $used = $series ? $repo->seriesUsed((int)$series['id']) : false;
        $v = [
            'doc_type' => (string)$req->post('doc_type', 'invoice'),
            'name' => trim((string)$req->post('name', '')),
            'format' => trim((string)$req->post('format', '')),
            'vs_format' => trim((string)$req->post('vs_format', '')),
            'reset_yearly' => $req->post('reset_yearly') ? 1 : 0,
            'is_default' => $req->post('is_default') ? 1 : 0,
            'active' => $req->post('active') ? 1 : 0,
            'next' => (int)$req->post('next', 1),
        ];
        if ($used) {   // the numbering of issued documents must stay consistent
            foreach (['doc_type', 'format', 'vs_format', 'reset_yearly'] as $k) {
                $v[$k] = $series[$k];
            }
        }
        $errors = [];
        if (!isset(Numbering::allTypes()[$v['doc_type']])) {
            $errors['doc_type'] = 'Neplatný typ dokladu.';
        }
        if ($v['name'] === '') {
            $errors['name'] = 'Zadajte názov radu.';
        }
        if (($e = Numbering::formatError($v['format'])) !== null) {
            $errors['format'] = $e;
        }
        if (($e = Numbering::vsFormatError($v['vs_format'])) !== null) {
            $errors['vs_format'] = $e;
        }
        if ($v['next'] < 1) {
            $errors['next'] = 'Ďalšie číslo musí byť aspoň 1.';
        }
        if ($errors) {
            return Response::html($this->seriesForm($req, $params, $errors, $v), 422);
        }
        $db = $this->ctx->db();
        $id = $repo->saveSeries($series ? (int)$series['id'] : null, $companyId, $v);
        $saved = $repo->oneSeries($id);
        $year = Numbering::counterYear($saved, date('Y-m-d'));
        if (!$series || Numbering::peek($db, $saved, date('Y-m-d')) !== $v['next']) {
            Numbering::setNext($db, $saved, $year, $v['next']);
        }
        Audit::log($db, $this->ctx->user(), 'series', $id, $series ? 'update' : 'create',
            $v['format'] . ', ďalšie číslo ' . $v['next'] . ($year ? " ($year)" : ''));
        Session::flash('msg', 'Číselný rad uložený.');
        return Response::redirect($this->ctx->url('/companies/' . $companyId) . '#series');
    }

    // ------------------------------------------------------------------ helpers

    /**
     * E-shops of every order source for the "E-shopy" part of the form.
     * @return array list of [src, label, shops (id_shop, name), error|null]
     */
    private function shopGroups(): array
    {
        $groups = [];
        foreach ($this->ctx->orderSources() as $src => $source) {
            try {
                $groups[] = ['src' => $src, 'label' => $source->label(), 'shops' => $source->shops(), 'error' => null];
            } catch (\Throwable $e) {
                $groups[] = ['src' => $src, 'label' => $source->label(), 'shops' => [], 'error' => $e->getMessage()];
            }
        }
        return $groups;
    }

    private function notFound(): Response
    {
        return Response::html($this->ctx->page('notfound', [], 'Firma neexistuje', 'settings:companies'), 404);
    }

    public static function validIban(string $iban): bool
    {
        if (!preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{10,30}$/', $iban)) {
            return false;
        }
        $s = substr($iban, 4) . substr($iban, 0, 4);
        $num = '';
        foreach (str_split($s) as $ch) {
            $num .= ctype_alpha($ch) ? (string)(ord($ch) - 55) : $ch;
        }
        $mod = 0;
        foreach (str_split($num, 7) as $chunk) {
            $mod = (int)(($mod . $chunk) % 97);
        }
        return $mod === 1;
    }

    /** @return array{0: string, 1: string}|string [data, mime] or an error message */
    private static function readImage(array $f)
    {
        if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
            return 'Nahranie súboru zlyhalo.';
        }
        if ($f['size'] > self::MAX_IMAGE) {
            return 'Obrázok môže mať najviac 2 MB.';
        }
        $info = @getimagesize($f['tmp_name']);
        if ($info === false || !in_array($info['mime'], ['image/png', 'image/jpeg'], true)) {
            return 'Len obrázok PNG alebo JPG.';
        }
        return [(string)file_get_contents($f['tmp_name']), $info['mime']];
    }
}
