<?php
namespace Panel\Invoicing;

use WebApplication\Database\Database;

/** Supplier companies with their VAT periods, bank accounts, e-shops and number series. */
class Companies
{
    const FIELDS = ['name', 'street', 'city', 'zip', 'country', 'ico', 'dic', 'registration', 'email', 'phone', 'web',
                    'slogan', 'due_days', 'invoice_note', 'accent_color', 'active'];

    /** @var Database */ private $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    private function t(string $name): string
    {
        return $this->db->table($name);
    }

    /** Companies (without images) limited to $ids. @param int[] $ids */
    public function all(array $ids, bool $activeOnly = false): array
    {
        if (!$ids) {
            return [];
        }
        $in = implode(',', array_map('intval', $ids));
        $cols = 'id, ' . implode(', ', self::FIELDS) . ', logo_mime, stamp_mime';
        return $this->db->query("SELECT $cols FROM {$this->t('company')} WHERE id IN ($in)"
            . ($activeOnly ? ' AND active = 1' : '') . ' ORDER BY active DESC, name');
    }

    public function find(int $id): ?array
    {
        $cols = 'id, ' . implode(', ', self::FIELDS) . ', logo_mime, stamp_mime, created_at, updated_at';
        $c = $this->db->fetchOne("SELECT $cols FROM {$this->t('company')} WHERE id = ?", [$id]);
        return $c === false ? null : $c;
    }

    /** @return int new or existing id */
    public function save(?int $id, array $data): int
    {
        $now = date('Y-m-d H:i:s');
        $vals = [];
        foreach (self::FIELDS as $f) {
            $vals[] = $data[$f] ?? null;
        }
        if ($id) {
            $set = implode(', ', array_map(function ($f) { return "$f = ?"; }, self::FIELDS));
            $this->db->execute("UPDATE {$this->t('company')} SET $set, updated_at = ? WHERE id = ?", array_merge($vals, [$now, $id]));
            return $id;
        }
        $cols = implode(', ', self::FIELDS);
        $q = implode(', ', array_fill(0, count(self::FIELDS), '?'));
        $this->db->execute("INSERT INTO {$this->t('company')} ($cols, created_at, updated_at) VALUES ($q, ?, ?)", array_merge($vals, [$now, $now]));
        return $this->db->lastInsertId();
    }

    /** @param string $kind logo | stamp */
    public function setImage(int $id, string $kind, ?string $data, string $mime): void
    {
        if (!in_array($kind, ['logo', 'stamp'], true)) {
            throw new \InvalidArgumentException($kind);
        }
        $this->db->execute("UPDATE {$this->t('company')} SET $kind = ?, {$kind}_mime = ? WHERE id = ?", [$data, $data === null ? '' : $mime, $id]);
    }

    /** @return array{0: string, 1: string}|null [data, mime] */
    public function image(int $id, string $kind): ?array
    {
        if (!in_array($kind, ['logo', 'stamp'], true)) {
            return null;
        }
        $row = $this->db->fetchOne("SELECT $kind AS data, {$kind}_mime AS mime FROM {$this->t('company')} WHERE id = ?", [$id]);
        return ($row === false || $row['data'] === null || $row['data'] === '') ? null : [$row['data'], $row['mime']];
    }

    /** Settings of exports to accounting (Omega …), JSON in company.export_settings. */
    public function exportSettings(int $id): array
    {
        $json = $this->db->fetchValue("SELECT export_settings FROM {$this->t('company')} WHERE id = ?", [$id]);
        $s = $json ? json_decode((string)$json, true) : null;
        $s = is_array($s) ? $s : [];
        // new settings added later get their default value without a migration
        $s['omega'] = array_merge(OmegaExport::DEFAULTS, isset($s['omega']) && is_array($s['omega']) ? $s['omega'] : []);
        $s['accountant'] = array_merge(AccountingPackage::DEFAULTS, isset($s['accountant']) && is_array($s['accountant']) ? $s['accountant'] : []);
        return $s;
    }

    public function saveExportSettings(int $id, array $settings): void
    {
        $this->db->execute("UPDATE {$this->t('company')} SET export_settings = ? WHERE id = ?",
            [json_encode($settings, JSON_UNESCAPED_UNICODE), $id]);
    }

    const MAIL_DEFAULTS = [
        'from_name' => '',      // '' = company name
        'from_email' => '',     // '' = company e-mail
        'reply_to' => '',
        'bcc' => '',            // copy of every sent invoice (e.g. the accountant)
        'subject' => '{document} {number} — {company}',
        'body' => "Dobrý deň,\n\nv prílohe Vám posielame {document_acc} č. {number} na sumu {total}.\n{payment_info}\n\nĎakujeme za spoluprácu.\n\n{company}\n{web}\n{phone}",
        'pay_transfer' => 'Prosíme o úhradu do {due_date} na účet {iban}, variabilný symbol {vs}.',
        'pay_paid' => 'Faktúra je už uhradená.',
        'pay_cod' => 'Faktúra bude uhradená dobierkou.',
        'reminder_subject' => 'Upomienka: {document} {number} — {company}',
        'reminder_body' => "Dobrý deň,\n\nevidujeme neuhradenú {document_acc} č. {number} so splatnosťou {due_date}, zostáva uhradiť {rest}.\nProsíme o úhradu na účet {iban}, variabilný symbol {vs}. Faktúru prikladáme.\n\nAk ste už platili, považujte túto správu za bezpredmetnú.\n\n{company}\n{web}\n{phone}",
    ];

    /** E-mail settings of a company (JSON company.mail_settings) with defaults for missing keys. */
    public function mailSettings(int $id): array
    {
        $json = $this->db->fetchValue("SELECT mail_settings FROM {$this->t('company')} WHERE id = ?", [$id]);
        $s = $json ? json_decode((string)$json, true) : null;
        return array_merge(self::MAIL_DEFAULTS, is_array($s) ? array_intersect_key($s, self::MAIL_DEFAULTS) : []);
    }

    public function saveMailSettings(int $id, array $s): void
    {
        $this->db->execute("UPDATE {$this->t('company')} SET mail_settings = ? WHERE id = ?",
            [json_encode(array_intersect_key($s, self::MAIL_DEFAULTS), JSON_UNESCAPED_UNICODE), $id]);
    }

    // ------------------------------------------------------------------ VAT periods

    public function vatPeriods(int $companyId): array
    {
        return $this->db->query("SELECT * FROM {$this->t('company_vat')} WHERE company_id = ? ORDER BY valid_from", [$companyId]);
    }

    /** VAT status on a date: ['vat_payer' => bool, 'icdph' => string]. No period = not a VAT payer. */
    public function vatOn(int $companyId, string $date): array
    {
        $row = $this->db->fetchOne("SELECT vat_payer, icdph FROM {$this->t('company_vat')}
            WHERE company_id = ? AND valid_from <= ? ORDER BY valid_from DESC LIMIT 1", [$companyId, $date]);
        return $row === false ? ['vat_payer' => false, 'icdph' => ''] : ['vat_payer' => (bool)$row['vat_payer'], 'icdph' => (string)$row['icdph']];
    }

    /** @param array $periods list of [valid_from, vat_payer, icdph] */
    public function saveVatPeriods(int $companyId, array $periods): void
    {
        $this->db->execute("DELETE FROM {$this->t('company_vat')} WHERE company_id = ?", [$companyId]);
        foreach ($periods as $p) {
            $this->db->execute("INSERT INTO {$this->t('company_vat')} (company_id, valid_from, vat_payer, icdph) VALUES (?, ?, ?, ?)",
                [$companyId, $p['valid_from'], $p['vat_payer'] ? 1 : 0, $p['icdph']]);
        }
    }

    // ------------------------------------------------------------------ bank accounts

    public function banks(int $companyId, bool $activeOnly = false): array
    {
        return $this->db->query("SELECT * FROM {$this->t('company_bank')} WHERE company_id = ?"
            . ($activeOnly ? ' AND active = 1' : '') . ' ORDER BY is_default DESC, id', [$companyId]);
    }

    public function bank(int $id): ?array
    {
        $row = $this->db->fetchOne("SELECT * FROM {$this->t('company_bank')} WHERE id = ?", [$id]);
        return $row === false ? null : $row;
    }

    /**
     * @param array $banks list of [id (0 = new), account_name, iban, bic, is_default, active]; missing ids are deactivated
     * @return int[] ids of the given accounts in the same order
     */
    public function saveBanks(int $companyId, array $banks): array
    {
        $keep = [];
        foreach ($banks as $b) {
            $vals = [$b['account_name'], $b['iban'], $b['bic'], $b['is_default'] ? 1 : 0, $b['active'] ? 1 : 0];
            if ((int)$b['id'] > 0) {
                $this->db->execute("UPDATE {$this->t('company_bank')} SET account_name = ?, iban = ?, bic = ?, is_default = ?, active = ?
                    WHERE id = ? AND company_id = ?", array_merge($vals, [(int)$b['id'], $companyId]));
                $keep[] = (int)$b['id'];
            } else {
                $this->db->execute("INSERT INTO {$this->t('company_bank')} (account_name, iban, bic, is_default, active, company_id)
                    VALUES (?, ?, ?, ?, ?, ?)", array_merge($vals, [$companyId]));
                $keep[] = $this->db->lastInsertId();
            }
        }
        // accounts are referenced by invoices → never deleted, only deactivated
        $not = $keep ? ' AND id NOT IN (' . implode(',', $keep) . ')' : '';
        $this->db->execute("UPDATE {$this->t('company_bank')} SET active = 0, is_default = 0 WHERE company_id = ?$not", [$companyId]);
        return $keep;
    }

    /** Bank API (automatic download of movements): provider + ENCRYPTED token (Panel\Secret); null = remove. */
    public function setBankApi(int $bankId, int $companyId, string $provider, ?string $encryptedToken): void
    {
        $this->db->execute("UPDATE {$this->t('company_bank')} SET api_provider = ?, api_token = ? WHERE id = ? AND company_id = ?",
            [$encryptedToken === null ? '' : $provider, $encryptedToken, $bankId, $companyId]);
    }

    public function bankDownloaded(int $bankId): void
    {
        $this->db->execute("UPDATE {$this->t('company_bank')} SET api_last_download = ? WHERE id = ?", [date('Y-m-d H:i:s'), $bankId]);
    }

    // ------------------------------------------------------------------ e-shops

    /** Key of an e-shop: "source:id_shop" (source 0 = shop tables in this database, N = shop connection N). */
    public static function shopKey(int $source, int $shopId): string
    {
        return $source . ':' . $shopId;
    }

    /** @return array "source:id_shop" => company_id */
    public function shopMap(): array
    {
        $map = [];
        foreach ($this->db->query("SELECT connection_id, shop_id, company_id FROM {$this->t('company_shop')}") as $r) {
            $map[self::shopKey((int)$r['connection_id'], (int)$r['shop_id'])] = (int)$r['company_id'];
        }
        return $map;
    }

    /** Company that invoices the orders of this e-shop (0 = none). */
    public function companyForShop(int $source, int $shopId): int
    {
        return $this->shopMap()[self::shopKey($source, $shopId)] ?? 0;
    }

    /**
     * @param string[] $keys "source:id_shop" e-shops invoiced by this company (taken away from any other company)
     * @param int[] $sources sources whose e-shops were offered in the form — assignments of other sources (e.g. a shop
     *              that was unreachable at that moment) stay as they are
     */
    public function saveShops(int $companyId, array $keys, array $sources): void
    {
        $sources = array_values(array_unique(array_map('intval', $sources)));
        if ($sources) {
            $in = implode(', ', array_fill(0, count($sources), '?'));
            $this->db->execute("DELETE FROM {$this->t('company_shop')} WHERE company_id = ? AND connection_id IN ($in)",
                array_merge([$companyId], $sources));
        }
        foreach (array_unique($keys) as $k) {
            if (!preg_match('/^(\d+):(\d+)$/', (string)$k, $m) || !in_array((int)$m[1], $sources, true)) {
                continue;
            }
            $this->db->execute("REPLACE INTO {$this->t('company_shop')} (connection_id, shop_id, company_id) VALUES (?, ?, ?)",
                [(int)$m[1], (int)$m[2], $companyId]);
        }
    }

    // ------------------------------------------------------------------ number series

    public function series(int $companyId, ?string $docType = null, bool $activeOnly = false): array
    {
        $sql = "SELECT * FROM {$this->t('series')} WHERE company_id = ?";
        $params = [$companyId];
        if ($docType !== null) {
            $sql .= ' AND doc_type = ?';
            $params[] = $docType;
        }
        if ($activeOnly) {
            $sql .= ' AND active = 1';
        }
        return $this->db->query($sql . ' ORDER BY doc_type, is_default DESC, name', $params);
    }

    public function oneSeries(int $id): ?array
    {
        $row = $this->db->fetchOne("SELECT * FROM {$this->t('series')} WHERE id = ?", [$id]);
        return $row === false ? null : $row;
    }

    public function defaultSeries(int $companyId, string $docType): ?array
    {
        $row = $this->db->fetchOne("SELECT * FROM {$this->t('series')} WHERE company_id = ? AND doc_type = ? AND active = 1
            ORDER BY is_default DESC, id LIMIT 1", [$companyId, $docType]);
        return $row === false ? null : $row;
    }

    public function saveSeries(?int $id, int $companyId, array $d): int
    {
        $vals = [$d['doc_type'], $d['name'], $d['format'], $d['vs_format'], $d['reset_yearly'] ? 1 : 0, $d['is_default'] ? 1 : 0, $d['active'] ? 1 : 0];
        if ($id) {
            $this->db->execute("UPDATE {$this->t('series')} SET doc_type = ?, name = ?, format = ?, vs_format = ?, reset_yearly = ?,
                is_default = ?, active = ? WHERE id = ? AND company_id = ?", array_merge($vals, [$id, $companyId]));
        } else {
            $this->db->execute("INSERT INTO {$this->t('series')} (doc_type, name, format, vs_format, reset_yearly, is_default, active, company_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)", array_merge($vals, [$companyId]));
            $id = $this->db->lastInsertId();
        }
        if ($d['is_default']) {
            $this->db->execute("UPDATE {$this->t('series')} SET is_default = 0 WHERE company_id = ? AND doc_type = ? AND id <> ?",
                [$companyId, $d['doc_type'], $id]);
        }
        return $id;
    }

    /** Has the series been used by an issued document? (then its format must not change) */
    public function seriesUsed(int $seriesId): bool
    {
        return $this->db->fetchValue("SELECT 1 FROM {$this->t('invoice')} WHERE series_id = ? AND number IS NOT NULL LIMIT 1", [$seriesId]) !== null
            || $this->db->fetchValue("SELECT 1 FROM {$this->t('stock_doc')} WHERE series_id = ? LIMIT 1", [$seriesId]) !== null;
    }

    /** Snapshot of the supplier printed on a document issued on $date (stored in invoice.supplier_json). */
    public function supplierSnapshot(int $companyId, string $deliveryDate): array
    {
        $c = $this->find($companyId);
        if ($c === null) {
            throw new \RuntimeException('Firma neexistuje.');
        }
        $vat = $this->vatOn($companyId, $deliveryDate);
        return [
            'name' => $c['name'], 'street' => $c['street'], 'city' => $c['city'], 'zip' => $c['zip'], 'country' => $c['country'],
            'ico' => $c['ico'], 'dic' => $c['dic'], 'icdph' => $vat['vat_payer'] ? $vat['icdph'] : '', 'vat_payer' => $vat['vat_payer'],
            'registration' => $c['registration'], 'email' => $c['email'], 'phone' => $c['phone'], 'web' => $c['web'],
            'slogan' => $c['slogan'], 'accent_color' => $c['accent_color'],
        ];
    }
}
