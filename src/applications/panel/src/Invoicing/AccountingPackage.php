<?php
namespace Panel\Invoicing;

use Panel\Audit;
use Panel\Mailer;
use Panel\Zip;
use WebApplication\Database\Database;
use WebApplication\Entity\Client;

/**
 * Monthly package for the accountant (owner 2026-11-20 — "aby som nemusel nič robiť"): ONE e-mail per company and month
 * with a ZIP:
 *   omega-RRRR-MM.txt             import file for KROS Omega (OmegaExport, company settings) — documents not exported before
 *   faktury/*.pdf, dobropisy/*.pdf the archived PDFs of all documents of the package
 *   prehlad-dokladov-RRRR-MM.csv  overview of the documents (Excel, ; and decimal comma)
 *   uhrady-RRRR-MM.csv            payments recorded in the month
 *   prijate/*                     files of the received documents (Náklady, module C) + prijate-doklady-RRRR-MM.csv
 *   NAVOD.txt                     how to import it in Omega
 * Documents: issued, issue date up to the end of the month, not in an earlier package (also late ones from previous
 * months). Each document goes into exactly one package; a sent package keeps its document list, so a re-send or a
 * download later gives the same content. Sent automatically after the month (company setting: on / day / address —
 * triggered by the dashboard or the cron URL, see sendDue()) or by the button in Faktúry → Pre účtovníčku.
 */
class AccountingPackage
{
    const DEFAULTS = [
        'email' => '',   // accountant's address(es), comma separated
        'auto' => 0,     // 1 = send automatically after the end of the month
        'day' => 1,      // from this day of the next month (1–28)
    ];
    const MAX_BYTES = 20 * 1024 * 1024;   // larger packages are not attached (most mail servers refuse ~25 MB)
    const MONTHS = [1 => 'január', 'február', 'marec', 'apríl', 'máj', 'jún', 'júl', 'august', 'september', 'október', 'november', 'december'];

    /** @var Database */ private $db;
    /** @var Invoices */ private $invoices;
    /** @var Companies */ private $companies;
    /** @var callable function (array $invoice): string PDF bytes */ private $pdf;
    /** @var \Panel\Expenses\Expenses|null */ private $expenses = null;

    public function __construct(Database $db, Invoices $invoices, Companies $companies, callable $pdf)
    {
        $this->db = $db;
        $this->invoices = $invoices;
        $this->companies = $companies;
        $this->pdf = $pdf;
    }

    /** Received documents go into the package too (docs/sprava-firmy-specifikacia.md, module C). */
    public function withExpenses(\Panel\Expenses\Expenses $expenses): self
    {
        $this->expenses = $expenses;
        return $this;
    }

    private function t(string $name): string
    {
        return $this->db->table($name);
    }

    public static function previousPeriod(?string $today = null): string
    {
        return date('Y-m', strtotime('first day of last month', strtotime($today ?? date('Y-m-d'))));
    }

    public static function label(string $period): string
    {
        return self::MONTHS[(int)substr($period, 5, 2)] . ' ' . substr($period, 0, 4);
    }

    public static function validPeriod(string $period): bool
    {
        return (bool)preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period);
    }

    /** Validated settings from the company form (fields acc_email, acc_auto, acc_day). @return array{0: array, 1: array} */
    public static function readSettings(array $in): array
    {
        $s = ['email' => trim((string)($in['acc_email'] ?? '')), 'auto' => !empty($in['acc_auto']) ? 1 : 0,
              'day' => max(1, min(28, (int)($in['acc_day'] ?? 1)))];
        $err = [];
        if ($s['email'] !== '' && Mailer::parseList($s['email']) === null) {
            $err['accountant'] = 'Neplatná e-mailová adresa účtovníka.';
        }
        if ($s['auto'] && $s['email'] === '') {
            $err['accountant'] = 'Na automatické posielanie zadajte e-mail účtovníka.';
        }
        return [$s, $err];
    }

    public function find(int $companyId, string $period): ?array
    {
        $row = $this->db->fetchOne("SELECT * FROM {$this->t('accounting_package')} WHERE company_id = ? AND period = ?", [$companyId, $period]);
        return $row === false ? null : $row;
    }

    /** Packages of a company, newest first, with the number of documents. */
    public function history(int $companyId, int $limit = 24): array
    {
        $rows = $this->db->query("SELECT * FROM {$this->t('accounting_package')} WHERE company_id = ? ORDER BY period DESC LIMIT " . (int)$limit, [$companyId]);
        foreach ($rows as &$r) {
            $r['doc_count'] = $r['doc_ids'] !== null ? count((array)json_decode($r['doc_ids'], true)) : null;
        }
        return $rows;
    }

    /**
     * What a package contains: documents (full, as from Invoices::find), the ones for the Omega file, payments of the month.
     * A package that was already sent keeps its content; otherwise the documents not yet in any package.
     */
    public function collect(int $companyId, string $period): array
    {
        $from = $period . '-01';
        $to = date('Y-m-t', strtotime($from));
        $pkg = $this->find($companyId, $period);
        if ($pkg && $pkg['doc_ids'] !== null) {
            $ids = array_map('intval', (array)json_decode($pkg['doc_ids'], true));
            $omegaIds = array_map('intval', (array)json_decode((string)$pkg['omega_ids'], true));
            $expenseIds = array_map('intval', (array)json_decode((string)($pkg['expense_ids'] ?? '[]'), true));
            $expenses = $this->expenses ? $this->expenses->byIds($expenseIds) : [];
        } else {
            $expenses = $this->expenses ? $this->expenses->forAccounting($companyId, $from, $to) : [];
            $ids = array_map('intval', array_column($this->db->query("SELECT id FROM {$this->t('invoice')}
                WHERE company_id = ? AND status = 'issued' AND issue_date BETWEEN ? AND ? AND accounting_package_id IS NULL
                ORDER BY doc_type, issue_date, number", [$companyId, date('Y-m-d', strtotime($from . ' -1 year')), $to]), 'id'));
            $omegaIds = null;
        }
        $docs = [];
        foreach ($ids as $id) {
            $d = $this->invoices->find($id);
            if ($d !== null) {
                $docs[] = $d;
            }
        }
        $omega = array_values(array_filter($docs, function ($d) use ($omegaIds) {
            return $omegaIds === null ? $d['omega_exported_at'] === null : in_array((int)$d['id'], $omegaIds, true);
        }));
        $payments = $this->db->query("SELECT p.paid_on, p.amount, p.method, p.note, p.bank_ref, i.number, i.variable_symbol, i.buyer_name, i.currency
            FROM {$this->t('invoice_payment')} p JOIN {$this->t('invoice')} i ON i.id = p.invoice_id
            WHERE i.company_id = ? AND p.paid_on BETWEEN ? AND ? ORDER BY p.paid_on, p.id", [$companyId, $from, $to]);
        return ['package' => $pkg, 'docs' => $docs, 'omega' => $omega, 'payments' => $payments, 'expenses' => $expenses, 'from' => $from, 'to' => $to];
    }

    /**
     * The ZIP and what it contains.
     * @return array zip (bytes, '' when there is nothing), name, doc_ids, omega_ids, problems (string[]), summary (array)
     */
    public function build(int $companyId, string $period): array
    {
        $c = $this->collect($companyId, $period);
        $company = $this->companies->find($companyId);
        $settings = $this->companies->exportSettings($companyId);
        $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower(self::ascii((string)$company['name']))), '-') ?: 'firma';
        $base = $slug . '-' . $period;
        $problems = [];
        $zip = new Zip(strtotime($c['to'] . ' 23:59:58'));   // files dated the last day of the month: a re-sent package is byte-identical
        $originals = [];
        foreach ($c['docs'] as $d) {
            if ($d['original_invoice_id']) {
                $o = $this->invoices->find((int)$d['original_invoice_id']);
                $originals[(int)$d['original_invoice_id']] = $o ? (string)$o['number'] : '';
            }
        }
        // Omega import file
        $omegaIds = [];
        if ($c['omega']) {
            $export = OmegaExport::create($settings['omega']);
            $txt = $export->build($c['omega'], $originals);
            $omegaIds = $export->exportedIds();
            foreach ($export->errors() as $e) {
                $problems[] = 'Nie je v súbore pre Omegu: ' . $e;
            }
            if ($omegaIds) {
                $zip->add("$base/omega-$period.txt", $txt);
            }
        }
        // PDFs
        foreach ($c['docs'] as $d) {
            try {
                $pdf = ($this->pdf)($d);
                $dir = $d['doc_type'] === 'credit_note' ? 'dobropisy' : 'faktury';
                $zip->add("$base/$dir/" . preg_replace('/[^A-Za-z0-9_-]+/', '-', (string)$d['number']) . '.pdf', $pdf);
            } catch (\Throwable $e) {
                $problems[] = 'PDF chýba: ' . $d['number'] . ' (' . $e->getMessage() . ')';
            }
        }
        // overviews
        $paidTo = [];
        foreach ($c['docs'] as $d) {
            $paidTo[(int)$d['id']] = $this->invoices->paidSum((int)$d['id']);
        }
        $rows = [['Číslo', 'Typ', 'K dokladu', 'Vystavený', 'Dodanie', 'Splatnosť', 'Odberateľ', 'IČO', 'DIČ', 'IČ DPH', 'Základ', 'DPH', 'Spolu',
                  'Mena', 'Spôsob úhrady', 'VS', 'Objednávka', 'Uhradené', 'Stav úhrady', 'V súbore pre Omegu']];
        $sum = ['invoice' => [0, 0.0], 'credit_note' => [0, 0.0]];
        foreach ($c['docs'] as $d) {
            $type = $d['doc_type'] === 'credit_note' ? 'credit_note' : 'invoice';
            $sum[$type][0]++;
            $sum[$type][1] += (float)$d['total'];
            $inOmega = in_array((int)$d['id'], $omegaIds, true);
            $rows[] = [$d['number'], Invoices::typeLabel($d['doc_type']), $originals[(int)$d['original_invoice_id']] ?? '',
                self::d($d['issue_date']), self::d($d['delivery_date']), self::d($d['due_date']), $d['buyer_name'], $d['buyer_ico'], $d['buyer_dic'],
                $d['buyer_icdph'], self::n($d['total_net']), self::n($d['total_vat']), self::n($d['total']), $d['currency'],
                Invoices::PAYMENT_METHODS[$d['payment_method']] ?? $d['payment_method'], $d['variable_symbol'], $d['order_reference'],
                self::n($paidTo[(int)$d['id']]), Invoices::PAYMENT_STATES[Invoices::paymentState($d, $paidTo[(int)$d['id']])] ?? '',
                $inOmega ? 'áno' : ($d['omega_exported_at'] ? 'nie — exportovaný už ' . self::d(substr((string)$d['omega_exported_at'], 0, 10)) : 'nie — pozri problémy')];
        }
        if ($c['docs']) {
            $zip->add("$base/prehlad-dokladov-$period.csv", self::csv($rows));
        }
        $paySum = 0.0;
        if ($c['payments']) {
            $prow = [['Dátum', 'Doklad', 'VS', 'Odberateľ', 'Suma', 'Mena', 'Spôsob', 'Poznámka', 'Bankový pohyb']];
            foreach ($c['payments'] as $p) {
                $paySum += (float)$p['amount'];
                $prow[] = [self::d($p['paid_on']), $p['number'], $p['variable_symbol'], $p['buyer_name'], self::n($p['amount']), $p['currency'],
                    Invoices::PAYMENT_METHODS[$p['method']] ?? $p['method'], $p['note'], (string)$p['bank_ref']];
            }
            $zip->add("$base/uhrady-$period.csv", self::csv($prow));
        }
        // received documents (costs): their files + overview
        $expSum = 0.0;
        if ($c['expenses']) {
            $files = [];
            $used = [];
            foreach ($this->expenses->filesOf(array_column($c['expenses'], 'id')) as $f) {
                $files[(int)$f['expense_id']][] = $f;
            }
            $erow = [['Dodávateľ', 'IČO', 'DIČ', 'IČ DPH', 'Číslo dokladu', 'Typ', 'VS', 'Vystavený', 'Dodanie', 'Splatnosť', 'Základ', 'DPH', 'Spolu',
                      'Mena', 'Úhrada', 'Stav', 'Uhradené dňa', 'Kategória', 'Poznámka', 'Súbory']];
            foreach ($c['expenses'] as $e) {
                $expSum += (float)$e['total'];
                $names = [];
                foreach ($files[(int)$e['id']] ?? [] as $i => $f) {
                    $ext = strtolower(pathinfo((string)$f['filename'], PATHINFO_EXTENSION)) ?: (\Panel\Expenses\Expenses::FILE_TYPES[$f['mime']] ?? 'bin');
                    $name = substr($e['delivery_date'], 0, 10) . '_' . trim(preg_replace('/[^A-Za-z0-9_-]+/', '-', self::ascii($e['supplier_name'] . ' ' . $e['document_number'])), '-')
                        . ($i ? '_' . ($i + 1) : '') . '.' . preg_replace('/[^a-z0-9]/', '', $ext);
                    if (isset($used[$name])) {   // same supplier + number + date twice
                        $name = pathinfo($name, PATHINFO_FILENAME) . '_' . $f['id'] . '.' . pathinfo($name, PATHINFO_EXTENSION);
                    }
                    $used[$name] = true;
                    $zip->add("$base/prijate/$name", (string)$f['data']);
                    $names[] = $name;
                }
                if (!$names) {
                    $problems[] = 'Prijatý doklad bez súboru: ' . $e['supplier_name'] . ' ' . $e['document_number'] . ' (' . self::d($e['delivery_date']) . ')';
                }
                $erow[] = [$e['supplier_name'], $e['supplier_ico'], $e['supplier_dic'], $e['supplier_icdph'], $e['document_number'],
                    \Panel\Expenses\Expenses::DOC_TYPES[$e['doc_type']] ?? $e['doc_type'], $e['variable_symbol'], self::d($e['issue_date']),
                    self::d($e['delivery_date']), self::d($e['due_date']), self::n($e['total_net']), self::n($e['total_vat']), self::n($e['total']),
                    $e['currency'], \Panel\Expenses\Expenses::PAYMENT_METHODS[$e['payment_method']] ?? $e['payment_method'],
                    \Panel\Expenses\Expenses::STATUSES[$e['status']] ?? $e['status'], self::d($e['paid_on']), (string)$e['category'], (string)$e['note'],
                    implode(', ', $names)];
            }
            $zip->add("$base/prijate-doklady-$period.csv", self::csv($erow));
        }
        if ($zip->count()) {
            $zip->add("$base/NAVOD.txt", self::guide($period, (bool)$omegaIds, $problems));
        }
        return ['zip' => $zip->count() ? $zip->bytes() : '', 'name' => "$base.zip", 'doc_ids' => array_map(function ($d) { return (int)$d['id']; }, $c['docs']),
            'omega_ids' => $omegaIds, 'problems' => $problems,
            'expense_ids' => array_map('intval', array_column($c['expenses'], 'id')),
            'summary' => ['invoices' => $sum['invoice'][0], 'invoices_total' => $sum['invoice'][1], 'credit_notes' => $sum['credit_note'][0],
                'credit_total' => $sum['credit_note'][1], 'payments' => count($c['payments']), 'payments_total' => $paySum,
                'omega' => count($omegaIds), 'expenses' => count($c['expenses']), 'expenses_total' => $expSum, 'currency' => $c['docs'] ? $c['docs'][0]['currency'] : 'EUR']];
    }

    /**
     * Send the package of a month by e-mail. Safe against two simultaneous runs (the row is claimed first).
     * @param bool $again send a package that was already sent once more (same content)
     * @return array ['ok' => bool, 'message' => string]
     */
    public function send(int $companyId, string $period, Mailer $mailer, ?Client $user, string $trigger = 'manual', bool $again = false): array
    {
        $company = $this->companies->find($companyId);
        $label = self::label($period) . ' (' . $company['name'] . ')';
        $s = $this->companies->exportSettings($companyId)['accountant'];
        $to = Mailer::parseList((string)$s['email']);
        if (!$to) {
            return ['ok' => false, 'message' => 'Firma nemá nastavený e-mail účtovníka (Firmy → Pre účtovníka).'];
        }
        if (!$this->claim($companyId, $period, $trigger, $user, $again)) {
            $pkg = $this->find($companyId, $period);
            return ['ok' => false, 'message' => $pkg && $pkg['status'] === 'sent'
                ? 'Balík za ' . $label . ' už bol odoslaný ' . date('j. n. Y H:i', strtotime((string)$pkg['sent_at'])) . '.'
                : 'Balík za ' . $label . ' sa práve odosiela, skúste o chvíľu.'];
        }
        $pkg = $this->find($companyId, $period);
        try {
            $b = $this->build($companyId, $period);
            if (strlen($b['zip']) > self::MAX_BYTES) {
                throw new \RuntimeException('balík má ' . round(strlen($b['zip']) / 1048576, 1) . ' MB, e-mailom sa nedá poslať — stiahnite ho v paneli (Faktúry → Pre účtovníka).');
            }
            $ms = $this->companies->mailSettings($companyId);
            $from = $ms['from_email'] ?: (string)$company['email'];
            if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
                throw new \RuntimeException('firma nemá platný e-mail odosielateľa (Firmy → E-mail s faktúrou).');
            }
            $mailer->send(['from' => $from, 'from_name' => $ms['from_name'] ?: (string)$company['name'], 'reply_to' => $ms['reply_to'],
                'to' => $to, 'subject' => 'Doklady za ' . self::label($period) . ' — ' . $company['name'],
                'text' => $this->mailText($company, $period, $b),
                'attachments' => $b['zip'] !== '' ? [[$b['name'], $b['zip'], 'application/zip']] : []]);
        } catch (\Throwable $e) {
            $this->db->execute("UPDATE {$this->t('accounting_package')} SET status = 'failed', error = ? WHERE id = ?",
                [mb_substr($e->getMessage(), 0, 500, 'UTF-8'), (int)$pkg['id']]);
            Audit::log($this->db, $user, 'company', $companyId, 'accounting_failed', self::label($period) . ': ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Balík za ' . $label . ' sa nepodarilo odoslať: ' . $e->getMessage()];
        }
        $this->db->transaction(function () use ($pkg, $b, $to) {
            $id = (int)$pkg['id'];
            if ($b['doc_ids']) {
                $this->db->execute("UPDATE {$this->t('invoice')} SET accounting_package_id = ? WHERE accounting_package_id IS NULL AND id IN ("
                    . implode(',', array_map('intval', $b['doc_ids'])) . ')', [$id]);
            }
            if ($b['omega_ids']) {
                $this->db->execute("UPDATE {$this->t('invoice')} SET omega_exported_at = ? WHERE omega_exported_at IS NULL AND id IN ("
                    . implode(',', array_map('intval', $b['omega_ids'])) . ')', [date('Y-m-d H:i:s')]);
            }
            if ($this->expenses && $b['expense_ids']) {
                $this->expenses->markSent($b['expense_ids'], $id);
            }
            $this->db->execute("UPDATE {$this->t('accounting_package')} SET status = 'sent', error = '', sent_at = ?, recipients = ?, doc_ids = ?, omega_ids = ?,
                expense_ids = ? WHERE id = ?", [date('Y-m-d H:i:s'), mb_substr(implode(', ', $to), 0, 500, 'UTF-8'), json_encode($b['doc_ids']),
                json_encode($b['omega_ids']), json_encode($b['expense_ids']), $id]);
        });
        Audit::log($this->db, $user, 'company', $companyId, 'accounting', self::label($period) . ': ' . self::docs(count($b['doc_ids']))
            . ($b['expense_ids'] ? ' + prijaté: ' . count($b['expense_ids']) : '') . ' → '
            . implode(', ', $to) . ($trigger === 'auto' ? ' (automaticky)' : ''));
        return ['ok' => true, 'message' => 'Účtovníkovi odoslané doklady za ' . $label . ': ' . self::docs(count($b['doc_ids']))
            . ($b['expense_ids'] ? ', prijaté: ' . self::docs(count($b['expense_ids'])) : '')
            . ($b['problems'] ? ' — pozor: ' . implode('; ', $b['problems']) : '') . '.'];
    }

    /**
     * Automatic sending (companies with "auto"): the package of the previous month, from the set day on. Called by the
     * dashboard (any logged-in employee) and by the cron URL — the hosting has no shell. A failed attempt is retried
     * at most once an hour. @return array results of send() for the companies where something happened
     */
    public function sendDue(Mailer $mailer, ?string $today = null): array
    {
        $today = $today ?? date('Y-m-d');
        $period = self::previousPeriod($today);
        if ($this->expenses) {
            $this->expenses->generateRecurring($today);   // recurring costs first — they belong into the package
        }
        $out = [];
        foreach ($this->db->query("SELECT id FROM {$this->t('company')} WHERE active = 1") as $r) {
            $id = (int)$r['id'];
            $s = $this->companies->exportSettings($id)['accountant'];
            if (!$s['auto'] || trim((string)$s['email']) === '' || (int)substr($today, 8, 2) < (int)$s['day']) {
                continue;
            }
            $pkg = $this->find($id, $period);
            if ($pkg && ($pkg['status'] === 'sent'
                    || ($pkg['status'] === 'failed' && strtotime((string)$pkg['attempted_at']) > time() - 3600))) {
                continue;
            }
            $out[] = $this->send($id, $period, $mailer, null, 'auto');
        }
        return $out;
    }

    /** Take the package row for this run; false when another run has it or (without $again) it was sent already. */
    private function claim(int $companyId, string $period, string $trigger, ?Client $user, bool $again): bool
    {
        $now = date('Y-m-d H:i:s');
        $pkg = $this->find($companyId, $period);
        if ($pkg === null) {
            return $this->db->affected("INSERT IGNORE INTO {$this->t('accounting_package')} (company_id, period, status, `trigger`, created_by, attempted_at)
                VALUES (?, ?, 'sending', ?, ?, ?)", [$companyId, $period, $trigger, $user ? $user->getId() : 0, $now]) === 1;
        }
        if ($pkg['status'] === 'sent' && !$again) {
            return false;
        }
        if ($pkg['status'] === 'sending' && strtotime((string)$pkg['attempted_at']) > time() - 1800) {
            return false;   // another run is sending it (a crashed run is taken over after 30 minutes)
        }
        return $this->db->affected("UPDATE {$this->t('accounting_package')} SET status = 'sending', attempted_at = ?, `trigger` = ?
            WHERE id = ? AND status = ? AND attempted_at = ?", [$now, $trigger, (int)$pkg['id'], $pkg['status'], $pkg['attempted_at']]) === 1;
    }

    private function mailText(array $company, string $period, array $b): string
    {
        $s = $b['summary'];
        $m = function ($x) use ($s) { return number_format((float)$x, 2, ',', ' ') . ' ' . $s['currency']; };
        $t = "Dobrý deň,\n\nposielame doklady firmy " . $company['name'] . ' za ' . self::label($period) . ".\n\n";
        if (!$b['doc_ids'] && !$s['payments'] && !$s['expenses']) {
            return $t . "Za tento mesiac nie sú žiadne vystavené ani prijaté doklady ani úhrady.\n\nE-mail poslal automaticky ERMA panel.";
        }
        $t .= '- faktúry: ' . $s['invoices'] . ($s['invoices'] ? ' (spolu ' . $m($s['invoices_total']) . ')' : '') . "\n"
            . '- dobropisy: ' . $s['credit_notes'] . ($s['credit_notes'] ? ' (spolu ' . $m($s['credit_total']) . ')' : '') . "\n"
            . '- úhrady: ' . $s['payments'] . ($s['payments'] ? ' (spolu ' . $m($s['payments_total']) . ')' : '') . "\n"
            . '- prijaté doklady (náklady): ' . $s['expenses'] . ($s['expenses'] ? ' (spolu ' . $m($s['expenses_total']) . ')' : '') . "\n\n"
            . 'V prílohe (' . $b['name'] . "):\n"
            . ($s['omega'] ? '- omega-' . $period . '.txt — ' . self::docs($s['omega']) . " na import do Omegy (Firma → Import → Import z textového súboru)\n" : '')
            . ($b['doc_ids'] ? "- PDF vystavených dokladov (priečinky faktury, dobropisy)\n" : '')
            . ($b['doc_ids'] ? "- prehlad-dokladov-$period.csv — zoznam dokladov (Excel)\n" : '')
            . ($s['payments'] ? "- uhrady-$period.csv — prijaté úhrady\n" : '')
            . ($s['expenses'] ? "- prijate/ — súbory prijatých dokladov, prijate-doklady-$period.csv — ich zoznam\n" : '');
        if ($b['problems']) {
            $t .= "\nPozor:\n- " . implode("\n- ", $b['problems']) . "\n";
        }
        return $t . "\nE-mail poslal automaticky ERMA panel. Otázky k dokladom: " . ((string)$company['email'] ?: 'odpoveďou na tento e-mail') . '.';
    }

    private static function guide(string $period, bool $omega, array $problems): string
    {
        $t = "Doklady za " . self::label($period) . "\r\n\r\n";
        if ($omega) {
            $t .= "Import do Omegy: Firma -> Import -> Import z textového súboru -> súbor omega-$period.txt\r\n"
                . "(kódy evidencie a číselných radov sú nastavené v ERMA paneli pri firme; ak nesedia s Omegou, dajte vedieť).\r\n"
                . "Doklady, ktoré už boli do Omegy exportované skôr, v súbore nie sú (v prehľade sú označené).\r\n\r\n";
        }
        $t .= "PDF vystavených dokladov: priečinky faktury a dobropisy.\r\nPrijaté doklady (náklady): priečinok prijate, zoznam prijate-doklady-$period.csv.\r\n"
            . "Prehľad dokladov a úhrad: súbory .csv (otvoria sa v Exceli).\r\n";
        if ($problems) {
            $t .= "\r\nPozor:\r\n- " . implode("\r\n- ", $problems) . "\r\n";
        }
        return $t;
    }

    /** "1 doklad", "3 doklady", "5 dokladov" */
    public static function docs(int $n): string
    {
        return $n . ($n === 1 ? ' doklad' : ($n >= 2 && $n <= 4 ? ' doklady' : ' dokladov'));
    }

    /** CSV for Excel in Slovak settings: UTF-8 with BOM, ";" and CRLF. */
    private static function csv(array $rows): string
    {
        $out = "\xEF\xBB\xBF";
        foreach ($rows as $r) {
            $out .= implode(';', array_map(function ($v) {
                $v = (string)$v;
                return preg_match('/[;"\r\n]/', $v) ? '"' . str_replace('"', '""', $v) . '"' : $v;
            }, $r)) . "\r\n";
        }
        return $out;
    }

    private static function n($x): string
    {
        return number_format((float)$x, 2, ',', '');
    }

    private static function d(?string $date): string
    {
        return $date ? date('j.n.Y', strtotime($date)) : '';
    }

    private static function ascii(string $s): string
    {
        $t = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT', $s) : false;
        return $t !== false ? $t : $s;
    }
}
