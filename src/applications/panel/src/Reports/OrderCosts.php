<?php
namespace Panel\Reports;

use Panel\Audit;
use WebApplication\Database\Database;
use WebApplication\Entity\Client;

/**
 * Order costs (docs/sprava-firmy-specifikacia.md, module D): shipping by carrier, payment fee by payment method
 * (% of the order total + fixed) and packaging per order — rules set in Výsledky → Náklady objednávok. They are an
 * ESTIMATE per order (contribution margin of an order / e-shop); the real invoices of the carriers and gateways are
 * received documents (Náklady, category kind "order") — module E compares the two.
 * Amounts as the company's cost: without VAT for a VAT payer, with VAT for a non-payer.
 * For each type the most specific valid rule wins: e-shop + carrier/payment > carrier/payment > e-shop > any.
 */
class OrderCosts
{
    const TYPES = ['shipping' => 'Doprava', 'payment' => 'Platba', 'packaging' => 'Balné'];

    /** @var Database */ private $db;
    /** @var string */ private $p;
    /** @var array|null */ private $rules = null;

    public function __construct(Database $db, string $shopPrefix = 'ps_')
    {
        if (!preg_match('/^[A-Za-z0-9_]*$/', $shopPrefix)) {
            throw new \InvalidArgumentException('Invalid shop table prefix');
        }
        $this->db = $db;
        $this->p = $shopPrefix;
    }

    private function t(string $name): string
    {
        return $this->db->table($name);
    }

    public function rules(): array
    {
        if ($this->rules === null) {
            $this->rules = $this->db->query("SELECT * FROM {$this->t('order_cost_rule')} ORDER BY FIELD(type, 'shipping', 'payment', 'packaging'),
                shop_id IS NOT NULL, shop_id, label, valid_from");
        }
        return $this->rules;
    }

    /**
     * Replace the rules of the given e-shops (+ the "all e-shops" rules when $global). Rows: id ('' = new), shop_id ('' = all),
     * type, match_key, label, fixed, percent, valid_from, valid_to, note, delete. @return string[] errors (nothing saved)
     */
    public function save(array $rows, array $allowedShops, bool $global, ?Client $user): array
    {
        $clean = [];
        $errors = [];
        foreach ($rows as $n => $r) {
            if (!empty($r['delete'])) {
                if (!empty($r['id'])) {
                    $clean[] = ['delete' => (int)$r['id']];
                }
                continue;
            }
            $type = (string)($r['type'] ?? '');
            $fixed = self::num((string)($r['fixed'] ?? ''));
            $percent = self::num((string)($r['percent'] ?? ''));
            if (empty($r['id']) && ($r['fixed'] ?? '') === '' && ($r['percent'] ?? '') === '') {
                continue;   // empty new row
            }
            $line = 'Riadok ' . ($n + 1) . ': ';
            if (!isset(self::TYPES[$type])) {
                $errors[] = $line . 'zvoľte druh nákladu.';
                continue;
            }
            $shop = (string)($r['shop_id'] ?? '') === '' ? null : (int)$r['shop_id'];
            if ($shop === null ? !$global : !in_array($shop, $allowedShops, true)) {
                $errors[] = $line . 'k tomuto e-shopu nemáte prístup.';
                continue;
            }
            if ($fixed === null || $percent === null || $fixed < 0 || $percent < 0 || $percent > 100) {
                $errors[] = $line . 'suma a percento musia byť čísla (percento 0–100).';
                continue;
            }
            $from = self::date((string)($r['valid_from'] ?? ''));
            $to = self::date((string)($r['valid_to'] ?? ''));
            if ($from === false || $to === false || ($from && $to && $to < $from)) {
                $errors[] = $line . 'neplatné obdobie platnosti.';
                continue;
            }
            $clean[] = ['id' => (int)($r['id'] ?? 0), 'shop_id' => $shop, 'type' => $type,
                'match_key' => $type === 'packaging' ? '' : mb_substr(trim((string)($r['match_key'] ?? '')), 0, 64, 'UTF-8'),
                'label' => mb_substr(trim((string)($r['label'] ?? '')), 0, 128, 'UTF-8'), 'fixed' => round($fixed, 2), 'percent' => round($percent, 3),
                'valid_from' => $from, 'valid_to' => $to, 'note' => mb_substr(trim((string)($r['note'] ?? '')), 0, 255, 'UTF-8')];
        }
        if ($errors) {
            return $errors;
        }
        $mine = function (?int $shop) use ($allowedShops, $global) { return $shop === null ? $global : in_array($shop, $allowedShops, true); };
        $existing = [];
        foreach ($this->rules() as $r) {
            $existing[(int)$r['id']] = $r;
        }
        $this->db->transaction(function () use ($clean, $existing, $mine) {
            $now = date('Y-m-d H:i:s');
            foreach ($clean as $c) {
                if (isset($c['delete'])) {
                    if (isset($existing[$c['delete']]) && $mine($existing[$c['delete']]['shop_id'] === null ? null : (int)$existing[$c['delete']]['shop_id'])) {
                        $this->db->execute("DELETE FROM {$this->t('order_cost_rule')} WHERE id = ?", [$c['delete']]);
                    }
                    continue;
                }
                $vals = [$c['shop_id'], $c['type'], $c['match_key'], $c['label'], $c['fixed'], $c['percent'], $c['valid_from'], $c['valid_to'], $c['note'], $now];
                if ($c['id'] && isset($existing[$c['id']])) {
                    $old = $existing[$c['id']];
                    if (!$mine($old['shop_id'] === null ? null : (int)$old['shop_id'])) {
                        continue;
                    }
                    $this->db->execute("UPDATE {$this->t('order_cost_rule')} SET shop_id = ?, type = ?, match_key = ?, label = ?, fixed = ?, percent = ?,
                        valid_from = ?, valid_to = ?, note = ?, updated_at = ? WHERE id = ?", array_merge($vals, [$c['id']]));
                } else {
                    $this->db->execute("INSERT INTO {$this->t('order_cost_rule')} (shop_id, type, match_key, label, fixed, percent, valid_from, valid_to, note, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", $vals);
                }
            }
        });
        $this->rules = null;
        Audit::log($this->db, $user, 'order_cost_rule', 0, 'save', count($clean) . ' pravidiel');
        return [];
    }

    /**
     * Costs of one order. @param array $o id_shop, date (Y-m-d), carrier (id_reference), module, total (paid by the customer,
     * in the default currency) @return array shipping, payment, packaging, total, rules (type => rule|null), no_shipping (bool)
     */
    public function forOrder(array $o): array
    {
        $out = ['shipping' => 0.0, 'payment' => 0.0, 'packaging' => 0.0, 'total' => 0.0, 'rules' => []];
        foreach (array_keys(self::TYPES) as $type) {
            $key = $type === 'shipping' ? (string)$o['carrier'] : ($type === 'payment' ? (string)$o['module'] : '');
            $rule = self::pick($this->rules(), $type, (int)$o['id_shop'], $key, (string)$o['date']);
            $amount = $rule ? (float)$rule['fixed'] + (float)$rule['percent'] / 100 * (float)$o['total'] : 0.0;
            $out[$type] = round($amount, 4);
            $out['total'] += $amount;
            $out['rules'][$type] = $rule;
        }
        $out['no_shipping'] = $out['rules']['shipping'] === null;
        return $out;
    }

    /** The most specific valid rule of a type. */
    public static function pick(array $rules, string $type, int $shop, string $key, string $date): ?array
    {
        $best = null;
        $bestScore = -1;
        foreach ($rules as $r) {
            if ($r['type'] !== $type || ($r['shop_id'] !== null && (int)$r['shop_id'] !== $shop)
                || ($r['match_key'] !== '' && $r['match_key'] !== $key)
                || ($r['valid_from'] !== null && $r['valid_from'] > $date) || ($r['valid_to'] !== null && $r['valid_to'] < $date)) {
                continue;
            }
            $score = ($r['match_key'] !== '' ? 2 : 0) + ($r['shop_id'] !== null ? 1 : 0);
            if ($score > $bestScore || ($score === $bestScore && (string)$r['valid_from'] > (string)$best['valid_from'])) {
                $best = $r;
                $bestScore = $score;
            }
        }
        return $best;
    }

    /** Callback for SalesReport::compute: order data from the shop tables → costs. */
    public function loader(): callable
    {
        return function (array $orderIds): array {
            $ids = array_values(array_filter(array_map('intval', $orderIds)));
            $out = [];
            foreach (array_chunk($ids, 500) as $chunk) {
                foreach ($this->db->query("SELECT o.id_order, o.id_shop, o.date_add, o.module, o.total_paid_tax_incl, o.conversion_rate, c.id_reference
                        FROM {$this->p}orders o LEFT JOIN {$this->p}carrier c ON c.id_carrier = o.id_carrier
                        WHERE o.id_order IN (" . implode(',', $chunk) . ')') as $o) {
                    $rate = (float)$o['conversion_rate'] > 0 ? (float)$o['conversion_rate'] : 1.0;
                    $out[(int)$o['id_order']] = $this->forOrder(['id_shop' => (int)$o['id_shop'], 'date' => substr((string)$o['date_add'], 0, 10),
                        'carrier' => (string)$o['id_reference'], 'module' => (string)$o['module'], 'total' => (float)$o['total_paid_tax_incl'] / $rate]);
                }
            }
            return $out;
        };
    }

    /** Carriers of the shops (by id_reference — stays the same when a carrier is edited): reference => name */
    public function carriers(): array
    {
        $out = [];
        foreach ($this->db->query("SELECT id_reference, name, deleted FROM {$this->p}carrier ORDER BY deleted, id_carrier DESC") as $c) {
            $ref = (string)$c['id_reference'];
            if (!isset($out[$ref])) {
                $out[$ref] = (string)$c['name'] . ((int)$c['deleted'] ? ' (zrušený)' : '');
            }
        }
        // active carriers first (by name), then the deleted ones (older orders still use them)
        uksort($out, function ($a, $b) use ($out) {
            $da = substr($out[$a], -11) === '(zrušený)';
            $db = substr($out[$b], -11) === '(zrušený)';
            return $da === $db ? strcoll($out[$a], $out[$b]) : ($da ? 1 : -1);
        });
        return $out;
    }

    /** Payment methods used in orders of the last 2 years: module => name */
    public function paymentModules(): array
    {
        $out = [];
        foreach ($this->db->query("SELECT module, MAX(payment) AS payment FROM {$this->p}orders WHERE date_add >= ? AND module <> ''
                GROUP BY module ORDER BY COUNT(*) DESC", [date('Y-m-d', strtotime('-2 years'))]) as $m) {
            $out[(string)$m['module']] = (string)$m['payment'];
        }
        return $out;
    }

    private static function num(string $s): ?float
    {
        $s = str_replace([' ', "\xC2\xA0", '€', '%'], '', trim($s));
        if ($s === '') {
            return 0.0;
        }
        $s = str_replace(',', '.', $s);
        return is_numeric($s) ? (float)$s : null;
    }

    /** @return string|null|false Y-m-d, null for empty, false when invalid */
    private static function date(string $s)
    {
        $s = trim($s);
        if ($s === '') {
            return null;
        }
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? $s : false;
    }
}
