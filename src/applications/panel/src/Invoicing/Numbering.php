<?php
namespace Panel\Invoicing;

use WebApplication\Database\Database;

/**
 * Document numbers from number series.
 * Format tokens: {RRRR} year, {RR} 2-digit year, {MM} month, {NNNN…} counter padded to the count of N.
 * Example: "FA{RRRR}/{NNNNNN}" + 198 in 2026 → "FA2026/000198". Variabilný symbol: vs_format with the same tokens
 * (digits only, max 10), or empty = the digits of the number (last 10).
 * Numbers are allocated inside the issuing transaction with a row lock, so two people issuing at the same moment
 * never get the same number, and a failed issue (rollback) leaves no gap.
 */
class Numbering
{
    const TYPES = ['invoice' => 'Faktúra', 'credit_note' => 'Dobropis (opravná faktúra)'];
    /** stock documents (table stock_doc); default formats used when a company has no series yet */
    const STOCK_TYPES = ['receipt' => 'Príjemka', 'issue' => 'Výdajka', 'transfer' => 'Prevodka', 'adjust' => 'Inventúrny rozdiel'];
    const STOCK_DEFAULT_FORMATS = ['receipt' => 'PR{RRRR}/{NNNN}', 'issue' => 'VY{RRRR}/{NNNN}', 'transfer' => 'PV{RRRR}/{NNNN}',
                                   'adjust' => 'IN{RRRR}/{NNNN}'];

    /** All document types that have number series (invoices + stock). */
    public static function allTypes(): array
    {
        return self::TYPES + self::STOCK_TYPES + array_map(function ($t) { return $t['label']; }, \Panel\Core\Documents::types());
    }

    /** Table whose documents use numbers of this type. */
    public static function tableFor(string $docType): string
    {
        $module = \Panel\Core\Documents::types()[$docType] ?? null;   // documents of other modules (Core\ProvidesDocumentTypes)
        return $module ? $module['table'] : (isset(self::STOCK_TYPES[$docType]) ? 'stock_doc' : 'invoice');
    }

    public static function format(string $format, int $counter, string $date): string
    {
        $t = strtotime($date) ?: time();
        return preg_replace_callback('/\{(RRRR|RR|MM|N+)\}/', function ($m) use ($counter, $t) {
            switch ($m[1]) {
                case 'RRRR': return date('Y', $t);
                case 'RR': return date('y', $t);
                case 'MM': return date('m', $t);
                default: return str_pad((string)$counter, strlen($m[1]), '0', STR_PAD_LEFT);
            }
        }, $format);
    }

    public static function variableSymbol(string $vsFormat, string $number, int $counter, string $date): string
    {
        $vs = $vsFormat === '' ? preg_replace('/\D/', '', $number) : self::format($vsFormat, $counter, $date);
        return substr((string)$vs, -10);
    }

    /** Error message for an invalid number format, or null. */
    public static function formatError(string $format): ?string
    {
        if ($format === '' || strlen($format) > 40) {
            return 'Formát: 1–40 znakov.';
        }
        if (preg_match_all('/\{N+\}/', $format) !== 1) {
            return 'Formát musí obsahovať práve jedno počítadlo, napr. {NNNNNN}.';
        }
        $rest = preg_replace('/\{(RRRR|RR|MM|N+)\}/', '', $format);
        if (strpos($rest, '{') !== false || strpos($rest, '}') !== false) {
            return 'Neznámy token — povolené sú {RRRR}, {RR}, {MM}, {NNN…}.';
        }
        if (!preg_match('/^[A-Za-z0-9\/\-_.]*$/', $rest)) {
            return 'Okrem tokenov sú povolené len písmená, čísla a / - _ .';
        }
        return null;
    }

    public static function vsFormatError(string $vsFormat): ?string
    {
        if ($vsFormat === '') {
            return null;
        }
        if (preg_match_all('/\{N+\}/', $vsFormat) !== 1 || preg_replace('/\{(RRRR|RR|MM|N+)\}|\d/', '', $vsFormat) !== '') {
            return 'Variabilný symbol: len tokeny {RRRR} {RR} {MM} {NNN…} a číslice, práve jedno počítadlo.';
        }
        if (strlen(self::format($vsFormat, 1, '2026-01-01')) > 10) {
            return 'Variabilný symbol môže mať najviac 10 číslic.';
        }
        return null;
    }

    public static function counterYear(array $series, string $date): int
    {
        return (int)$series['reset_yearly'] ? (int)date('Y', strtotime($date) ?: time()) : 0;
    }

    /** Next counter value without allocating it (for display). */
    public static function peek(Database $db, array $series, string $date): int
    {
        $n = $db->fetchValue('SELECT next_number FROM ' . $db->table('series_counter') . ' WHERE series_id = ? AND year = ?',
            [(int)$series['id'], self::counterYear($series, $date)]);
        return $n === null ? 1 : (int)$n;
    }

    public static function setNext(Database $db, array $series, int $year, int $next): void
    {
        $db->execute('INSERT INTO ' . $db->table('series_counter') . ' (series_id, year, next_number) VALUES (?, ?, ?)
                      ON DUPLICATE KEY UPDATE next_number = VALUES(next_number)', [(int)$series['id'], $year, max(1, $next)]);
    }

    /**
     * Takes the next number of the series. MUST run inside a transaction (the lock lasts until commit/rollback).
     * @return array{number: string, vs: string, counter: int}
     */
    public static function allocate(Database $db, array $series, int $companyId, string $date): array
    {
        if (!$db->pdo()->inTransaction()) {
            throw new \LogicException('Numbering::allocate() must run inside a transaction');
        }
        $year = self::counterYear($series, $date);
        $t = $db->table('series_counter');
        $db->execute("INSERT IGNORE INTO $t (series_id, year, next_number) VALUES (?, ?, 1)", [(int)$series['id'], $year]);
        $n = (int)$db->fetchValue("SELECT next_number FROM $t WHERE series_id = ? AND year = ? FOR UPDATE", [(int)$series['id'], $year]);
        $number = self::format((string)$series['format'], $n, $date);
        $exists = $db->fetchValue('SELECT id FROM ' . $db->table(self::tableFor((string)($series['doc_type'] ?? 'invoice')))
            . ' WHERE company_id = ? AND number = ?', [$companyId, $number]);
        if ($exists !== null) {
            throw new \RuntimeException("Číslo $number už existuje. Skontrolujte „ďalšie číslo“ v číselnom rade.");
        }
        $db->execute("UPDATE $t SET next_number = ? WHERE series_id = ? AND year = ?", [$n + 1, (int)$series['id'], $year]);
        return ['number' => $number, 'vs' => self::variableSymbol((string)$series['vs_format'], $number, $n, $date), 'counter' => $n];
    }
}
