<?php
namespace Panel\Invoicing;

/**
 * Invoice amounts (EN 16931 way): line net = round(quantity × unit price without VAT, 2); VAT is calculated per VAT
 * rate from the sum of line nets (the legal amount); the VAT shown on a line is for information only.
 * A company that is not a VAT payer on the delivery date charges no VAT: every line gets rate 0, category "O".
 */
class Calculator
{
    const CATEGORIES = [
        'S' => 'Základná/znížená sadzba',
        'Z' => 'Nulová sadzba',
        'E' => 'Oslobodené od dane',
        'AE' => 'Prenesenie daňovej povinnosti',
        'K' => 'Dodanie do iného členského štátu EÚ',
        'G' => 'Vývoz mimo EÚ',
        'O' => 'Mimo predmetu DPH (neplatiteľ)',
    ];

    /**
     * @param array $lines each with quantity, unit_price, vat_rate, vat_category (+ any other keys, kept)
     * @return array{lines: array, breakdown: array, net: string, vat: string, total: string}
     *         amounts as strings with 2 decimals ("12.30"), breakdown keyed "S|23.00" => [category, rate, net, vat]
     */
    public static function compute(array $lines, bool $vatPayer): array
    {
        $out = [];
        $breakdown = [];
        $net = 0.0;
        foreach ($lines as $l) {
            $qty = (float)$l['quantity'];
            $price = (float)$l['unit_price'];
            $category = $vatPayer ? strtoupper((string)($l['vat_category'] ?? 'S')) : 'O';
            if (!isset(self::CATEGORIES[$category])) {
                $category = 'S';
            }
            $rate = ($vatPayer && $category === 'S') ? (float)$l['vat_rate'] : 0.0;
            $lineNet = self::round($qty * $price);
            $lineVat = self::round($lineNet * $rate / 100);
            $l['vat_category'] = $category;
            $l['vat_rate'] = self::fmt($rate);
            $l['net'] = self::fmt($lineNet);
            $l['vat'] = self::fmt($lineVat);
            $l['gross'] = self::fmt($lineNet + $lineVat);
            $out[] = $l;

            $key = $category . '|' . self::fmt($rate);
            if (!isset($breakdown[$key])) {
                $breakdown[$key] = ['category' => $category, 'rate' => self::fmt($rate), 'net' => 0.0, 'vat' => 0.0];
            }
            $breakdown[$key]['net'] += $lineNet;
            $net += $lineNet;
        }
        $vat = 0.0;
        foreach ($breakdown as $k => $b) {
            $bNet = self::round($b['net']);
            $bVat = self::round($bNet * (float)$b['rate'] / 100);
            $vat += $bVat;
            $breakdown[$k]['net'] = self::fmt($bNet);
            $breakdown[$k]['vat'] = self::fmt($bVat);
        }
        ksort($breakdown);
        $net = self::round($net);
        $vat = self::round($vat);
        return ['lines' => $out, 'breakdown' => $breakdown, 'net' => self::fmt($net), 'vat' => self::fmt($vat),
                'total' => self::fmt($net + $vat)];
    }

    public static function round(float $x): float
    {
        return round($x, 2);
    }

    /** Decimal string for the database (no float artefacts, "-0.00" → "0.00"). */
    public static function fmt(float $x, int $decimals = 2): string
    {
        $s = number_format($x, $decimals, '.', '');
        return preg_match('/^-0\.?0*$/', $s) ? substr($s, 1) : $s;
    }

    /** Parses a number typed by a person: "1 234,50" / "1234.5" → 1234.5; null when not a number. */
    public static function parse($value): ?float
    {
        $s = str_replace([' ', "\u{00A0}", ','], ['', '', '.'], trim((string)$value));
        return is_numeric($s) ? (float)$s : null;
    }
}
