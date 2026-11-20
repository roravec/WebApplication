<?php
namespace Panel\Controller;

use Panel\Audit;
use Panel\Invoicing\Legislation;
use Panel\PanelContext;
use WebApplication\Csrf;
use WebApplication\Http\Request;
use WebApplication\Http\Response;
use WebApplication\Session;

/**
 * Nastavenia → Legislatíva (administrator): VAT rates and legal texts with validity dates.
 * Changes apply to documents issued from then on; issued documents keep their frozen copy.
 */
class LegislationController
{
    /** @var PanelContext */
    private $ctx;

    public function __construct(PanelContext $ctx)
    {
        $this->ctx = $ctx;
    }

    public function form(Request $req, array $params, array $errors = [], ?array $values = null): string
    {
        $legal = new Legislation($this->ctx->db());
        $values = $values ?? ['rates' => $legal->allRates(), 'texts' => $legal->allTexts()];
        return $this->ctx->page('settings/legislation', ['values' => $values, 'errors' => $errors,
            'textsNow' => $legal->textsOn(date('Y-m-d'))], 'Legislatíva', 'legislation');
    }

    public function save(Request $req, array $params)
    {
        if (!Csrf::check($req)) {
            return Response::text('Formulár vypršal, načítajte stránku znova.', 400);
        }
        $col = function (string $k) use ($req) { return array_values((array)$req->post($k, [])); };
        $date = function ($d) { return preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$d) && strtotime($d); };
        $errors = [];

        $rates = [];
        $rCountry = $col('rate_country');
        $rRate = $col('rate_rate');
        $rKind = $col('rate_kind');
        $rFrom = $col('rate_from');
        $rTo = $col('rate_to');
        $rNote = $col('rate_note');
        foreach ($rRate as $i => $rate) {
            $row = ['country' => strtoupper(trim((string)($rCountry[$i] ?? ''))), 'rate' => str_replace(',', '.', trim((string)$rate)),
                'kind' => ($rKind[$i] ?? '') === 'reduced' ? 'reduced' : 'standard', 'valid_from' => trim((string)($rFrom[$i] ?? '')),
                'valid_to' => trim((string)($rTo[$i] ?? '')), 'note' => mb_substr(trim((string)($rNote[$i] ?? '')), 0, 190, 'UTF-8')];
            if ($row['rate'] === '' && $row['valid_from'] === '') {
                continue;
            }
            if (!preg_match('/^[A-Z]{2}$/', $row['country']) || !is_numeric($row['rate']) || $row['rate'] < 0 || $row['rate'] > 100
                || !$date($row['valid_from']) || ($row['valid_to'] !== '' && (!$date($row['valid_to']) || $row['valid_to'] < $row['valid_from']))) {
                $errors['rates'] = 'Skontrolujte sadzby: krajina (SK), sadzba 0–100, platí od (dátum), platí do (prázdne alebo dátum po „od“).';
            }
            $rates[] = $row;
        }

        $texts = [];
        $tCode = $col('text_code');
        $tText = $col('text_text');
        $tFrom = $col('text_from');
        $tNote = $col('text_note');
        foreach ($tCode as $i => $code) {
            $row = ['code' => (string)$code, 'text' => trim((string)($tText[$i] ?? '')), 'valid_from' => trim((string)($tFrom[$i] ?? '')),
                'note' => mb_substr(trim((string)($tNote[$i] ?? '')), 0, 190, 'UTF-8')];
            if ($row['valid_from'] === '' && $row['text'] === '') {
                continue;
            }
            if (!isset(Legislation::TEXT_CODES[$row['code']]) || !$date($row['valid_from'])) {
                $errors['texts'] = 'Skontrolujte texty: každý potrebuje typ a dátum „platí od“.';
            }
            $texts[] = $row;
        }
        // safety net: a broken / partial form must never wipe the legislation
        if (!in_array('standard', array_column($rates, 'kind'), true)) {
            $errors['rates'] = 'Musí ostať aspoň jedna základná sadzba DPH. Staré sadzby nemažte — ukončite ich dátumom „platí do“.';
        }
        $missing = array_diff(array_keys(Legislation::TEXT_CODES), array_column($texts, 'code'));
        if ($missing) {
            $errors['texts'] = 'Chýbajú texty: ' . implode(', ', array_map(function ($c) { return Legislation::TEXT_CODES[$c]; }, $missing))
                . '. Ak sa text nemá tlačiť, nechajte ho prázdny, ale riadok nemažte.';
        }
        if ($errors) {
            return Response::html($this->form($req, $params, $errors, ['rates' => $rates, 'texts' => $texts]), 422);
        }
        $db = $this->ctx->db();
        $db->transaction(function () use ($db, $rates, $texts) {
            $legal = new Legislation($db);
            $legal->saveRates($rates);
            $legal->saveTexts($texts);
        });
        Audit::log($db, $this->ctx->user(), 'legislation', 0, 'update', count($rates) . ' sadzieb, ' . count($texts) . ' textov');
        Session::flash('msg', 'Legislatíva uložená. Platí pre doklady vystavené odteraz; vystavené doklady sa nemenia.');
        return Response::redirect($this->ctx->url('/settings/legal'));
    }
}
