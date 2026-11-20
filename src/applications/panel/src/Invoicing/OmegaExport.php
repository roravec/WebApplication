<?php
namespace Panel\Invoicing;

/**
 * Text file for "Firma → Import → Import z textového súboru" in KROS Omega (Podvojné účtovníctvo).
 *
 * Format: official KROS description "ImportExport 20.60" (ftpkros.sk/files/documents/general/omega/2021/FAQ/
 * ImportExport_20_60.xls, sheets "Vseobecne", "Fakturacia", "Partneri"); summary in docs/omega-export.md.
 * - every value separated by TAB, every record ends with CRLF, text in Windows-1250, dates DD.MM.RRRR;
 * - first line R00; block "R00 T04" = partners (R01 per partner), block "R00 T01" = invoicing: R01 = document
 *   header, R02 = its items (free items, type V) until the next R01/R00;
 * - VAT rates: 0 (zero), N (lower), V (higher), X (no VAT). The 20.60 layout has only one lower + one higher rate.
 * Codes that exist only in the accountant's Omega (record code OF/OD, number series code, decimal separator) are
 * company settings.
 *
 * FORMAT VERSIONS: KROS changes the layout from time to time (e.g. "ImportExport 28.00" for documents from 2025 with three
 * VAT rates). A new layout = a subclass (e.g. OmegaExport2800 overriding document()/partner()), registered in VERSIONS
 * and create(); the company chooses the version in its settings. The existing version stays untouched.
 */
class OmegaExport
{
    /** version => label shown in the company settings */
    const VERSIONS = ['20.60' => 'KROS ImportExport 20.60 (najviac 2 sadzby DPH na doklade)'];

    const DEFAULTS = [
        'version' => '20.60',
        'decimal' => '.',            // must match the accountant's Omega / Windows settings
        'evidence_invoice' => 'OF',  // kód evidencie — odberateľské faktúry
        'evidence_credit' => 'OD',   // kód evidencie — odoslané dobropisy
        'series_invoice' => '',      // kód číselného radu v Omege (optional)
        'series_credit' => '',
        'partners' => 1,             // add the partners block (companies only, not private persons)
    ];
    const COUNTRIES = ['SK' => 'Slovensko', 'CZ' => 'Česko', 'AT' => 'Rakúsko', 'HU' => 'Maďarsko', 'PL' => 'Poľsko',
                       'DE' => 'Nemecko', 'UA' => 'Ukrajina'];

    /** @var array */ private $s;
    /** @var string[] */ private $errors = [];
    /** @var int[] */ private $exported = [];

    /** The exporter for the company's chosen format version. */
    public static function create(array $settings): self
    {
        switch ($settings['version'] ?? '20.60') {
            // case '28.00': return new OmegaExport2800($settings);
            default:
                return new self($settings);
        }
    }

    public function __construct(array $settings)
    {
        $this->s = array_merge(self::DEFAULTS, array_intersect_key($settings, self::DEFAULTS));
    }

    /** Ids of the documents build() put into the file (the others are in errors()). */
    public function exportedIds(): array
    {
        return $this->exported;
    }

    /** Problems found during build(): documents that were left out, with the reason. */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * @param array $invoices issued invoices / credit notes as returned by Invoices::find() (with lines + supplier)
     * @param array $originals invoice id => number, for credit notes
     * @return string file content in Windows-1250
     */
    public function build(array $invoices, array $originals = []): string
    {
        $this->errors = [];
        $this->exported = [];
        $docs = [];
        $partners = [];
        foreach ($invoices as $inv) {
            try {
                $docs[] = $this->document($inv, $originals);
                $this->exported[] = (int)$inv['id'];
            } catch (\RuntimeException $e) {
                $this->errors[] = ($inv['number'] ?: '#' . $inv['id']) . ': ' . $e->getMessage();
                continue;
            }
            if ($this->s['partners'] && $inv['buyer_type'] === 'b2b') {
                $partners[$inv['buyer_ico'] !== '' ? 'ico:' . $inv['buyer_ico'] : 'name:' . $inv['buyer_name']] = $this->partner($inv);
            }
        }
        $rows = [];
        if ($partners) {
            $rows[] = ['R00', 'T04'];
            foreach ($partners as $p) {
                $rows[] = $p;
            }
        }
        $rows[] = ['R00', 'T01'];
        foreach ($docs as $d) {
            foreach ($d as $r) {
                $rows[] = $r;
            }
        }
        $out = '';
        foreach ($rows as $r) {
            $out .= implode("\t", array_map([$this, 'clean'], $r)) . "\r\n";
        }
        return self::toWindows1250($out);
    }

    /** @return array[] R01 + R02 rows of one document */
    private function document(array $inv, array $originals): array
    {
        if ($inv['status'] !== 'issued' || !$inv['number']) {
            throw new \RuntimeException('nie je vystavený');
        }
        $credit = $inv['doc_type'] === 'credit_note';
        $sign = $credit ? -1 : 1;   // credit notes are exported with positive amounts (document type 4 = dobropis)
        $payer = (bool)$inv['vat_payer'];
        $sup = (array)$inv['supplier'];

        // VAT rate codes: higher = the highest standard rate on the document, lower = another one; more → not possible
        $rates = [];
        foreach ($inv['lines'] as $l) {
            if ($payer && $l['vat_category'] === 'S' && (float)$l['vat_rate'] > 0) {
                $rates[Calculator::fmt((float)$l['vat_rate'])] = true;
            }
        }
        krsort($rates, SORT_NUMERIC);
        $rates = array_keys($rates);
        if (count($rates) > 2) {
            throw new \RuntimeException('má ' . count($rates) . ' sadzby DPH (' . implode(', ', $rates) . ' %), formát Omega 20.60 pozná len dve.');
        }
        $higher = $rates[0] ?? null;
        $lower = $rates[1] ?? null;
        $code = function (array $l) use ($payer, $higher, $lower) {
            if (!$payer || $l['vat_category'] === 'O') {
                return 'X';
            }
            if ($l['vat_category'] !== 'S' || (float)$l['vat_rate'] == 0) {
                return $l['vat_category'] === 'Z' ? '0' : 'X';
            }
            $r = Calculator::fmt((float)$l['vat_rate']);
            return $r === $higher ? 'V' : ($r === $lower ? 'N' : 'X');
        };
        $base = ['N' => 0.0, 'V' => 0.0, '0' => 0.0, 'X' => 0.0];
        $items = [];
        foreach ($inv['lines'] as $l) {
            $c = $code($l);
            $base[$c] += $sign * (float)$l['net'];
            $items[] = ['R02', mb_substr($l['name'], 0, 255, 'UTF-8'), $this->num($sign * (float)$l['quantity'], 3),
                mb_substr($l['unit'], 0, 5, 'UTF-8'), $this->num((float)$l['unit_price'], 4), $c, '', '', '', 'V'];
        }
        $vat = ['N' => 0.0, 'V' => 0.0];
        foreach ($inv['breakdown'] as $b) {
            if ($b['category'] === 'S' && (float)$b['rate'] > 0) {
                $vat[$b['rate'] === $higher ? 'V' : 'N'] += $sign * (float)$b['vat'];
            }
        }
        list($vatCode, $vatId) = self::splitVatId((string)$inv['buyer_icdph']);
        list($supVatCode, $supVatId) = self::splitVatId((string)($sup['icdph'] ?? ''));
        $date = function ($d) { return date('d.m.Y', strtotime($d)); };
        $r = array_fill(1, 71, '');
        $r[1] = 'R01';
        $r[2] = self::fit($inv['number'], 20, 'číslo dokladu');
        $r[3] = mb_substr($inv['buyer_name'], 0, 75, 'UTF-8');
        $r[4] = self::fit($inv['buyer_ico'], 12, 'IČO');
        $r[5] = $date($inv['issue_date']);
        $r[6] = $date($inv['due_date']);
        $r[7] = $date($inv['delivery_date']);
        $r[8] = $this->num($base['N']);
        $r[9] = $this->num($base['V']);
        $r[10] = $this->num($base['0']);
        $r[11] = $this->num($base['X']);
        $r[12] = $lower !== null ? $this->num((float)$lower, 0) : '';
        $r[13] = $higher !== null ? $this->num((float)$higher, 0) : '';
        $r[14] = $this->num($vat['N']);
        $r[15] = $this->num($vat['V']);
        $r[17] = $this->num(0);
        $r[18] = $credit ? '4' : '0';
        $r[19] = $credit ? $this->s['evidence_credit'] : $this->s['evidence_invoice'];
        $r[20] = $credit ? $this->s['series_credit'] : $this->s['series_invoice'];
        $r[25] = mb_substr($inv['buyer_street'], 0, 40, 'UTF-8');
        $r[26] = mb_substr($inv['buyer_zip'], 0, 6, 'UTF-8');
        $r[27] = mb_substr($inv['buyer_city'], 0, 40, 'UTF-8');
        $r[28] = self::fit($inv['buyer_dic'], 12, 'DIČ');
        $r[33] = $credit ? (string)($originals[(int)$inv['original_invoice_id']] ?? '') : '';
        $r[34] = mb_substr((string)$inv['order_reference'], 0, 20, 'UTF-8');
        $r[35] = mb_substr((string)$inv['issued_by_name'], 0, 30, 'UTF-8');
        $r[38] = Invoices::PAYMENT_METHODS[$inv['payment_method']] ?? $inv['payment_method'];
        $r[40] = $inv['currency'];
        $r[41] = '1';
        $r[42] = '1';
        $r[43] = $this->num($sign * (float)$inv['total']);
        $r[45] = trim(($credit ? 'Dôvod: ' . $inv['correction_reason'] . ' ' : '') . (string)$inv['note']);
        $r[47] = self::countryName($inv['buyer_country']);
        $r[48] = $vatCode;
        $r[49] = $vatId;
        $r[56] = (string)$inv['bic'];
        $r[57] = (string)$inv['iban'];
        $r[58] = $supVatCode;
        $r[59] = $supVatId;
        $r[60] = self::countryName((string)($sup['country'] ?? 'SK'));
        $r[61] = '-2';
        $r[62] = '3';
        $r[71] = self::fit($inv['variable_symbol'], 20, 'VS');
        return array_merge([array_values($r)], $items);
    }

    private function partner(array $inv): array
    {
        list($vatCode, $vatId) = self::splitVatId((string)$inv['buyer_icdph']);
        $r = array_fill(1, 32, '');
        $r[1] = 'R01';
        $r[2] = mb_substr($inv['buyer_name'], 0, 75, 'UTF-8');
        $r[3] = $inv['buyer_ico'];
        $r[4] = mb_substr($inv['buyer_street'], 0, 40, 'UTF-8');
        $r[5] = mb_substr($inv['buyer_zip'], 0, 6, 'UTF-8');
        $r[6] = mb_substr($inv['buyer_city'], 0, 40, 'UTF-8');
        $r[8] = self::countryName($inv['buyer_country']);
        $r[13] = $inv['buyer_icdph'] !== '' ? 'T' : 'F';
        $r[14] = $inv['buyer_email'];
        $r[25] = $vatCode;
        $r[26] = $vatId;
        $r[27] = $inv['buyer_dic'];
        $r[32] = 'F';
        return array_values($r);
    }

    private function num(float $x, int $decimals = 2): string
    {
        $s = Calculator::fmt($x, $decimals);
        if ($decimals > 2 && strpos($s, '.') !== false) {
            $s = rtrim(rtrim($s, '0'), '.');
        }
        return $this->s['decimal'] === ',' ? str_replace('.', ',', $s) : $s;
    }

    /** No TAB / line breaks inside a value (they would break the record). */
    private function clean($v): string
    {
        return trim(preg_replace('/[\t\r\n]+/', ' ', (string)$v));
    }

    /** Identifiers are never shortened — a document whose identifier doesn't fit is left out. */
    private static function fit(string $v, int $max, string $what): string
    {
        if (mb_strlen($v, 'UTF-8') > $max) {
            throw new \RuntimeException("$what je dlhšie ako $max znakov");
        }
        return $v;
    }

    /** "SK2121232916" → ["SK", "2121232916"] */
    public static function splitVatId(string $id): array
    {
        $id = strtoupper(str_replace(' ', '', $id));
        return preg_match('/^([A-Z]{2})(.+)$/', $id, $m) ? [$m[1], $m[2]] : ['', $id];
    }

    public static function countryName(string $code): string
    {
        return self::COUNTRIES[strtoupper($code)] ?? strtoupper($code);
    }

    public static function toWindows1250(string $utf8): string
    {
        if (function_exists('iconv')) {
            $out = @iconv('UTF-8', 'Windows-1250//TRANSLIT', $utf8);
            if ($out !== false) {
                return $out;
            }
        }
        throw new \RuntimeException('Na serveri chýba iconv (prevod do Windows-1250).');
    }

    /** Validated settings from a form. @return array{0: array, 1: array} [settings, errors] */
    public static function readSettings(array $in): array
    {
        $s = [
            'version' => isset(self::VERSIONS[$in['omega_version'] ?? '']) ? $in['omega_version'] : self::DEFAULTS['version'],
            'decimal' => ($in['omega_decimal'] ?? '.') === ',' ? ',' : '.',
            'evidence_invoice' => strtoupper(trim((string)($in['omega_evidence_invoice'] ?? ''))),
            'evidence_credit' => strtoupper(trim((string)($in['omega_evidence_credit'] ?? ''))),
            'series_invoice' => trim((string)($in['omega_series_invoice'] ?? '')),
            'series_credit' => trim((string)($in['omega_series_credit'] ?? '')),
            'partners' => !empty($in['omega_partners']) ? 1 : 0,
        ];
        $err = [];
        foreach (['evidence_invoice', 'evidence_credit', 'series_invoice', 'series_credit'] as $k) {
            if (mb_strlen($s[$k], 'UTF-8') > 5) {
                $err['omega'] = 'Kódy evidencie a číselných radov v Omege majú najviac 5 znakov.';
            }
        }
        if ($s['evidence_invoice'] === '' || $s['evidence_credit'] === '') {
            $err['omega'] = 'Zadajte kódy evidencie (štandardne OF a OD).';
        }
        return [$s, $err];
    }
}
