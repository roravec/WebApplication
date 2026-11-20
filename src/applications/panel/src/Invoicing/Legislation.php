<?php
namespace Panel\Invoicing;

use WebApplication\Database\Database;

/**
 * Legal rules that change over time, kept as DATA with validity dates (tables vat_rate, legal_text — migration 003).
 * Edited by an administrator in the panel (Nastavenia → Legislatíva). Code asks "what applies on this date";
 * an issued invoice stores the texts it was issued with (invoice.legal_json), so later changes never alter it.
 * Overview of where every legal rule lives: docs/legislativa.md.
 */
class Legislation
{
    const TEXT_CODES = [
        'title_invoice' => 'Nadpis faktúry',
        'title_credit_note' => 'Nadpis dobropisu',
        'credit_note_reference' => 'Dobropis — odkaz na pôvodnú faktúru ({number}, {date}, {reason})',
        'non_vat_payer' => 'Dodávateľ nie je platiteľ DPH',
        'vat_AE' => 'Prenesenie daňovej povinnosti (kategória AE)',
        'vat_E' => 'Oslobodenie od dane (kategória E)',
        'vat_K' => 'Dodanie tovaru do iného štátu EÚ (kategória K)',
        'vat_G' => 'Vývoz mimo EÚ (kategória G)',
        'vat_Z' => 'Nulová sadzba (kategória Z)',
        'paid' => 'Uhradené (karta / hotovosť)',
        'prices_currency' => 'Mena cien ({currency})',
    ];

    /** @var Database */ private $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    // ------------------------------------------------------------------ VAT rates

    /** All rate rows (for the settings page). */
    public function allRates(): array
    {
        return $this->db->query('SELECT * FROM ' . $this->db->table('vat_rate') . ' ORDER BY country, valid_from DESC, rate DESC');
    }

    /** Rates valid in a country on a date, highest first. @return array[] rows with rate, kind */
    public function ratesOn(string $country, string $date): array
    {
        return $this->db->query('SELECT rate, kind, note FROM ' . $this->db->table('vat_rate') . '
            WHERE country = ? AND valid_from <= ? AND (valid_to IS NULL OR valid_to >= ?) ORDER BY rate DESC',
            [strtoupper($country), $date, $date]);
    }

    /** The standard rate on a date (default for a new line), or null when none is defined. */
    public function standardRate(string $country, string $date): ?float
    {
        foreach ($this->ratesOn($country, $date) as $r) {
            if ($r['kind'] === 'standard') {
                return (float)$r['rate'];
            }
        }
        return null;
    }

    public function isValidRate(string $country, string $date, float $rate): bool
    {
        foreach ($this->ratesOn($country, $date) as $r) {
            if (abs((float)$r['rate'] - $rate) < 0.001) {
                return true;
            }
        }
        return false;
    }

    /** @param array $rows list of [country, rate, kind, valid_from, valid_to|null, note] — replaces all rows */
    public function saveRates(array $rows): void
    {
        $t = $this->db->table('vat_rate');
        $this->db->execute("DELETE FROM $t");
        foreach ($rows as $r) {
            $this->db->execute("INSERT INTO $t (country, rate, kind, valid_from, valid_to, note) VALUES (?, ?, ?, ?, ?, ?)",
                [$r['country'], $r['rate'], $r['kind'], $r['valid_from'], $r['valid_to'] ?: null, $r['note']]);
        }
    }

    // ------------------------------------------------------------------ texts

    public function allTexts(): array
    {
        return $this->db->query('SELECT * FROM ' . $this->db->table('legal_text') . ' ORDER BY code, valid_from DESC');
    }

    /** code => text valid on a date (every known code present, '' when not defined). */
    public function textsOn(string $date): array
    {
        $out = array_fill_keys(array_keys(self::TEXT_CODES), '');
        $rows = $this->db->query('SELECT code, text FROM ' . $this->db->table('legal_text') . ' WHERE valid_from <= ? ORDER BY valid_from', [$date]);
        foreach ($rows as $r) {
            $out[$r['code']] = (string)$r['text'];   // later valid_from overwrites earlier
        }
        return $out;
    }

    /** @param array $rows list of [code, text, valid_from, note] — replaces all rows */
    public function saveTexts(array $rows): void
    {
        $t = $this->db->table('legal_text');
        $this->db->execute("DELETE FROM $t");
        foreach ($rows as $r) {
            $this->db->execute("INSERT INTO $t (code, text, valid_from, note) VALUES (?, ?, ?, ?)",
                [$r['code'], $r['text'], $r['valid_from'], $r['note']]);
        }
    }

    /** Fills {placeholders} in a text: fill('Ceny sú v {currency}.', ['currency' => 'EUR']). */
    public static function fill(string $text, array $vars): string
    {
        return preg_replace_callback('/\{([a-z_]+)\}/', function ($m) use ($vars) {
            return array_key_exists($m[1], $vars) ? (string)$vars[$m[1]] : $m[0];
        }, $text);
    }
}
