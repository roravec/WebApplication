<?php
namespace Panel\Reports;

use Panel\Audit;
use WebApplication\Database\Database;
use WebApplication\Entity\Client;

/**
 * Turnover of a non-payer against the VAT registration threshold (module E). The thresholds are LEGISLATION AS DATA
 * (table vat_registration_rule, Nastavenia → Legislatíva, docs/legislativa.md): period 'year' (calendar year, from
 * 2025 in SK) or 'rolling12' (12 consecutive calendar months, SK until 2024), threshold and an optional immediate one.
 * Turnover = e-shop sales of this database (goods after discounts + shipping, valid orders, returns subtracted) +
 * issued documents not made from those orders, by delivery date, EUR. Exempt supplies are not left out (rare for an
 * e-shop) — the page says so.
 */
class VatTurnover
{
    const PERIODS = ['year' => 'kalendárny rok', 'rolling12' => '12 po sebe idúcich mesiacov'];
    const WARN_PCT = 80;

    /** @var Database */ private $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    private function t(string $name): string
    {
        return $this->db->table($name);
    }

    public function rules(): array
    {
        return $this->db->query("SELECT * FROM {$this->t('vat_registration_rule')} ORDER BY country, valid_from");
    }

    public function ruleOn(string $country, string $date): ?array
    {
        $r = $this->db->fetchOne("SELECT * FROM {$this->t('vat_registration_rule')} WHERE country = ? AND valid_from <= ?
            AND (valid_to IS NULL OR valid_to >= ?) ORDER BY valid_from DESC LIMIT 1", [$country, $date, $date]);
        return $r === false ? null : $r;
    }

    /** @param array $rows country, period, threshold, threshold_immediate, valid_from, valid_to, note @return string|null error */
    public function saveRules(array $rows, ?Client $user): ?string
    {
        $clean = [];
        foreach ($rows as $r) {
            $threshold = str_replace([' ', ','], ['', '.'], trim((string)($r['threshold'] ?? '')));
            $immediate = str_replace([' ', ','], ['', '.'], trim((string)($r['threshold_immediate'] ?? '')));
            $from = trim((string)($r['valid_from'] ?? ''));
            $to = trim((string)($r['valid_to'] ?? ''));
            if ($threshold === '' && $from === '') {
                continue;
            }
            $country = strtoupper(trim((string)($r['country'] ?? '')));
            $okDate = function ($d) { return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false; };
            if (!preg_match('/^[A-Z]{2}$/', $country) || !isset(self::PERIODS[$r['period'] ?? '']) || !is_numeric($threshold) || $threshold <= 0
                || ($immediate !== '' && (!is_numeric($immediate) || $immediate < $threshold)) || !$okDate($from)
                || ($to !== '' && (!$okDate($to) || $to < $from))) {
                return 'Skontrolujte registráciu k DPH: krajina, obdobie, hranica (číslo), okamžitá hranica (prázdna alebo ≥ hranica), platí od, platí do.';
            }
            $clean[] = [$country, $r['period'], (float)$threshold, $immediate === '' ? null : (float)$immediate, $from, $to === '' ? null : $to,
                mb_substr(trim((string)($r['note'] ?? '')), 0, 255, 'UTF-8')];
        }
        if (!$clean) {
            return 'Musí ostať aspoň jedno pravidlo registrácie k DPH (staré ukončite dátumom „platí do“).';
        }
        $this->db->transaction(function () use ($clean) {
            $this->db->execute("DELETE FROM {$this->t('vat_registration_rule')}");
            foreach ($clean as $c) {
                $this->db->execute("INSERT INTO {$this->t('vat_registration_rule')} (country, period, threshold, threshold_immediate, valid_from, valid_to, note)
                    VALUES (?, ?, ?, ?, ?, ?, ?)", $c);
            }
        });
        Audit::log($this->db, $user, 'legislation', 0, 'update', 'registrácia k DPH: ' . count($clean) . ' pravidiel');
        return null;
    }

    /**
     * Turnover in a period. @param SalesReport|null $sales shop tables of this database @param int[] $shops the company's e-shops
     */
    public function turnover(int $companyId, ?SalesReport $sales, array $shops, string $from, string $to): float
    {
        $sum = 0.0;
        if ($sales && $shops) {
            $s = $sales->compute(['from' => $from, 'to' => $to, 'shops' => $shops, 'group' => 'month'], function () { return false; })['summary'];
            $sum += $s['revenue'] - $s['discounts'] + $s['shipping'];
        }
        $sum += (float)$this->db->fetchValue("SELECT COALESCE(SUM(total), 0) FROM {$this->t('invoice')} WHERE company_id = ? AND status = 'issued'
            AND currency = 'EUR' AND delivery_date BETWEEN ? AND ?
            AND NOT (shop_order_id IS NOT NULL AND shop_connection_id = 0" . ($shops ? ' AND shop_id IN (' . implode(',', array_map('intval', $shops)) . ')' : ' AND 1 = 0') . ')',
            [$companyId, $from, $to]);
        return round($sum, 2);
    }

    /**
     * Where a non-payer stands on a date. @return array|null null = no rule; else rule, from, to, turnover, threshold,
     * immediate, pct, projection (year: extrapolated to the end of the year), level ok | warn | over | over_immediate, text
     */
    public function status(int $companyId, ?SalesReport $sales, array $shops, string $date, string $country = 'SK'): ?array
    {
        $rule = $this->ruleOn($country, $date);
        if ($rule === null) {
            return null;
        }
        $from = $rule['period'] === 'year' ? substr($date, 0, 4) . '-01-01' : date('Y-m-01', strtotime(substr($date, 0, 7) . '-01 -11 months'));
        $turnover = $this->turnover($companyId, $sales, $shops, $from, $date);
        $threshold = (float)$rule['threshold'];
        $immediate = $rule['threshold_immediate'] !== null ? (float)$rule['threshold_immediate'] : null;
        $projection = null;
        if ($rule['period'] === 'year') {
            $day = (int)date('z', strtotime($date)) + 1;
            $projection = round($turnover / $day * (365 + (int)date('L', strtotime($date))), 2);
        }
        $pct = $turnover / $threshold * 100;
        $money = function ($x) { return number_format($x, 0, ',', ' ') . ' €'; };
        if ($immediate !== null && $turnover > $immediate) {
            $level = 'over_immediate';
            $text = 'Obrat ' . $money($turnover) . ' prekročil ' . $money($immediate) . ' — firma je platiteľom DPH odo dňa dodania, ktorým hranicu prekročila; žiadosť o registráciu treba podať do 5 pracovných dní. Kontaktujte účtovníka.';
        } elseif ($turnover >= $threshold) {
            $level = 'over';
            $text = 'Obrat ' . $money($turnover) . ' dosiahol hranicu ' . $money($threshold) . ' (' . self::PERIODS[$rule['period']] . ') — povinná registrácia k DPH'
                . ($rule['period'] === 'year' ? ' od 1. 1. nasledujúceho roka' : '') . '. Kontaktujte účtovníka.';
        } elseif ($pct >= self::WARN_PCT || ($projection !== null && $projection >= $threshold)) {
            $level = 'warn';
            $text = 'Obrat ' . $money($turnover) . ' je ' . number_format($pct, 0) . ' % hranice ' . $money($threshold)
                . ($projection !== null ? '; pri tomto tempe bude za rok ' . $money($projection) : '') . '. Sledujte ho a poraďte sa s účtovníkom.';
        } else {
            $level = 'ok';
            $text = 'Obrat ' . $money($turnover) . ' je ' . number_format($pct, 0) . ' % hranice ' . $money($threshold) . '.';
        }
        return ['rule' => $rule, 'from' => $from, 'to' => $date, 'turnover' => $turnover, 'threshold' => $threshold, 'immediate' => $immediate,
            'pct' => $pct, 'projection' => $projection, 'level' => $level, 'text' => $text];
    }
}
