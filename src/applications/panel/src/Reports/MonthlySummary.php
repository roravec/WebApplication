<?php
namespace Panel\Reports;

use Panel\Audit;
use Panel\Invoicing\AccountingPackage;
use Panel\Mailer;
use Panel\PanelContext;

/**
 * Monthly summary for the owner (module F): after the end of a month one e-mail per company (company setting
 * Firmy → Výsledky: e-mail + on) — revenue, profit, comparison with the same month last year and the year so far,
 * receivables / payables, VAT turnover of a non-payer, what needs doing. Triggered like the accountant's package:
 * the dashboard and the cron URL (the hosting has no shell); safe against parallel runs, a failure is retried after an hour.
 */
class MonthlySummary
{
    /** @var PanelContext */ private $ctx;

    public function __construct(PanelContext $ctx)
    {
        $this->ctx = $ctx;
    }

    private function t(string $name): string
    {
        return $this->ctx->db()->table($name);
    }

    /** @return array results ['ok' => bool, 'message' => string] for the companies where something happened */
    public function sendDue(Mailer $mailer, ?string $today = null): array
    {
        $today = $today ?? date('Y-m-d');
        $period = AccountingPackage::previousPeriod($today);
        $out = [];
        foreach ($this->ctx->db()->query("SELECT id FROM {$this->t('company')} WHERE active = 1") as $c) {
            $cid = (int)$c['id'];
            $s = $this->ctx->companies()->exportSettings($cid)['summary'];
            $to = Mailer::parseList((string)$s['email']);
            if (!$s['auto'] || !$to || !$this->claim($cid, $period)) {
                continue;
            }
            try {
                $mail = $this->build($cid, $period, $today);
                $company = $this->ctx->companies()->find($cid);
                $ms = $this->ctx->companies()->mailSettings($cid);
                $from = $ms['from_email'] ?: (string)$company['email'];
                if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
                    throw new \RuntimeException('firma nemá platný e-mail odosielateľa (Firmy → E-mail s faktúrou).');
                }
                $mailer->send(['from' => $from, 'from_name' => 'ERMA panel', 'to' => $to, 'subject' => $mail['subject'], 'text' => $mail['text']]);
                $this->ctx->db()->execute("UPDATE {$this->t('monthly_summary')} SET status = 'sent', sent_at = ?, error = '' WHERE company_id = ? AND period = ?",
                    [date('Y-m-d H:i:s'), $cid, $period]);
                Audit::log($this->ctx->db(), null, 'company', $cid, 'summary', AccountingPackage::label($period) . ' → ' . implode(', ', $to));
                $out[] = ['ok' => true, 'message' => 'Mesačný súhrn za ' . AccountingPackage::label($period) . ' (' . $company['name'] . ') odoslaný.'];
            } catch (\Throwable $e) {
                $this->ctx->db()->execute("UPDATE {$this->t('monthly_summary')} SET status = 'failed', error = ? WHERE company_id = ? AND period = ?",
                    [mb_substr($e->getMessage(), 0, 500, 'UTF-8'), $cid, $period]);
                $out[] = ['ok' => false, 'message' => 'Mesačný súhrn sa nepodarilo odoslať: ' . $e->getMessage()];
            }
        }
        return $out;
    }

    /** Take the row of this company + month: new, or failed more than an hour ago. */
    private function claim(int $companyId, string $period): bool
    {
        $now = date('Y-m-d H:i:s');
        $db = $this->ctx->db();
        if ($db->affected("INSERT IGNORE INTO {$this->t('monthly_summary')} (company_id, period, status, attempted_at) VALUES (?, ?, 'sending', ?)",
                [$companyId, $period, $now]) === 1) {
            return true;
        }
        return $db->affected("UPDATE {$this->t('monthly_summary')} SET status = 'sending', attempted_at = ? WHERE company_id = ? AND period = ?
            AND ((status = 'failed' AND attempted_at < ?) OR (status = 'sending' AND attempted_at < ?))",
            [$now, $companyId, $period, date('Y-m-d H:i:s', time() - 3600), date('Y-m-d H:i:s', time() - 1800)]) === 1;
    }

    /** @return array subject, text */
    public function build(int $companyId, string $period, string $today): array
    {
        $company = $this->ctx->companies()->find($companyId);
        $shops = $this->ctx->companyShops($companyId);
        $sales = $this->ctx->shopOrders()->available() ? $this->ctx->salesReport() : null;
        $oc = $sales ? $this->ctx->orderCosts() : null;
        $res = new BusinessResult($this->ctx->db(), $sales, $oc && $oc->rules() ? $oc->loader() : null);
        $net = $this->ctx->vatBasis();
        $from = $period . '-01';
        $to = date('Y-m-t', strtotime($from));
        $y = (int)substr($period, 0, 4);
        $month = $res->compute($companyId, $shops, $from, $to, $net)['total'];
        $monthPrev = $res->compute($companyId, $shops, ($y - 1) . substr($from, 4), date('Y-m-t', strtotime(($y - 1) . substr($from, 4))), $net)['total'];
        $ytd = $res->compute($companyId, $shops, $y . '-01-01', $to, $net)['total'];
        $ytdPrev = $res->compute($companyId, $shops, ($y - 1) . '-01-01', date('Y-m-t', strtotime(($y - 1) . substr($from, 4))), $net)['total'];
        $money = new Money($this->ctx->db());
        $vat = $this->ctx->companies()->vatOn($companyId, $to)['vat_payer'] ? null
            : (new VatTurnover($this->ctx->db()))->status($companyId, $sales, $shops, $to);
        $todo = new Todo($this->ctx->db(), (string)($this->ctx->app->getConfig()->get('apps.' . $this->ctx->app->getApplicationName() . '.shop.prefix', 'ps_')));
        $tasks = $todo->expenses([$companyId], $today);
        $tasks['orders'] = $sales ? $todo->ordersWithoutInvoice($shops, $today)['count'] : 0;
        return ['subject' => 'Súhrn za ' . AccountingPackage::label($period) . ' — ' . $company['name'],
            'text' => self::text((string)$company['name'], $period, $month, $monthPrev, $ytd, $ytdPrev, $vat,
                $money->receivables($companyId, $today), $money->payables($companyId, $today), $tasks,
                ($this->ctx->request->isHttps() ? 'https://' : 'http://') . (string)$this->ctx->request->server('HTTP_HOST', $this->ctx->request->host()) . $this->ctx->url('/reports/result'))];
    }

    public static function text(string $company, string $period, array $m, array $mp, array $y, array $yp, ?array $vat, array $rec, array $pay,
                                array $tasks, string $url): string
    {
        $eur = function ($x) { return number_format((float)$x, 2, ',', ' ') . ' €'; };
        $cmp = function ($a, $b) use ($eur) {
            $d = $a - $b;
            return 'minulý rok ' . $eur($b) . (abs($b) > 0.01 ? ', ' . ($d >= 0 ? '+' : '') . number_format($d / abs($b) * 100, 0, ',', ' ') . ' %' : '');
        };
        $label = AccountingPackage::label($period);
        $t = "Súhrn firmy $company za $label\n" . str_repeat('=', 40) . "\n\n"
            . "Tržby:          " . $eur($m['revenue']) . '  (' . $cmp($m['revenue'], $mp['revenue']) . ")\n"
            . "  e-shopy " . $eur($m['shop_revenue']) . ', ' . (int)$m['orders'] . ' objednávok; ostatné faktúry ' . $eur($m['other_revenue']) . "\n"
            . "Predaný tovar:  " . $eur($m['cogs']) . "\n"
            . "Náklady objednávok: " . $eur($m['order_costs']) . ($m['order_costs_source'] === 'estimate' ? ' (odhad — chýbajú doklady dopravcov)' : '') . "\n"
            . "Réžia:          " . $eur($m['overhead']) . "\n"
            . "ZISK:           " . $eur($m['profit']) . '  (' . $cmp($m['profit'], $mp['profit']) . ")\n\n"
            . 'Od začiatku roka: tržby ' . $eur($y['revenue']) . ', zisk ' . $eur($y['profit']) . ' (' . $cmp($y['profit'], $yp['profit']) . ")\n\n"
            . 'Pohľadávky: ' . $eur($rec['total']) . ', z toho po splatnosti ' . $eur($rec['overdue']) . "\n"
            . 'Záväzky:    ' . $eur($pay['total']) . ', z toho po splatnosti ' . $eur($pay['overdue']) . "\n";
        if ($vat) {
            $t .= "\nRegistrácia k DPH: " . $vat['text'] . "\n";
        }
        $todo = [];
        if ($m['missing_cost']) {
            $todo[] = $m['missing_cost'] . ' predaných položiek bez nákupnej ceny (zisk je preto vyšší)';
        }
        if ($tasks['orders']) {
            $todo[] = $tasks['orders'] . ' objednávok za 60 dní bez faktúry';
        }
        if ($tasks['no_file']) {
            $todo[] = $tasks['no_file'] . ' prijatých dokladov bez súboru';
        }
        if ($tasks['no_number']) {
            $todo[] = $tasks['no_number'] . ' prijatých faktúr bez čísla (opakované náklady)';
        }
        if ($tasks['overdue']) {
            $todo[] = $tasks['overdue'] . ' neuhradených prijatých dokladov po splatnosti (' . $eur($tasks['overdue_sum']) . ')';
        }
        $t .= "\nTreba urobiť:\n" . ($todo ? '- ' . implode("\n- ", $todo) : '- nič') . "\n";
        return $t . "\nPodrobnosti: $url\n\nRiadiaci prehľad z ERMA panela, nie účtovný výsledok. E-mail sa posiela automaticky (Firmy → Výsledky).";
    }
}
