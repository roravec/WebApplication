<?php
namespace Panel;

use Panel\Invoicing\Calculator;
use Panel\Invoicing\Invoices;
use Panel\Invoicing\Legislation;
use Panel\Invoicing\PayBySquare;
use Panel\Invoicing\Pdf;
use Panel\Invoicing\QrSvg;
use Panel\Invoicing\Templates;

/**
 * Builds the printed document of an invoice — the same HTML template for the browser preview and for the PDF.
 * Issued documents use their frozen data (supplier, texts, template) and their archived PDF is never regenerated.
 */
class InvoiceRenderer
{
    /** @var PanelContext */ private $ctx;

    public function __construct(PanelContext $ctx)
    {
        $this->ctx = $ctx;
    }

    public function html(array $inv, bool $forPdf): string
    {
        $companies = $this->ctx->companies();
        $companyId = (int)$inv['company_id'];
        if ($inv['status'] === 'draft' && $inv['bank_account_id'] && (string)$inv['iban'] === '') {
            // a draft shows the chosen account; an issued document has its own frozen copy
            $bank = $companies->bank((int)$inv['bank_account_id']);
            if ($bank) {
                $inv['iban'] = $bank['iban'];
                $inv['bic'] = $bank['bic'];
                $inv['bank_account_name'] = $bank['account_name'];
            }
        }
        $supplier = $inv['supplier'] ?: $companies->supplierSnapshot($companyId, $inv['delivery_date']);
        $original = $inv['original_invoice_id'] ? $this->ctx->invoices()->find((int)$inv['original_invoice_id']) : null;
        $legal = $inv['legal'] ?: (new Legislation($this->ctx->db()))->textsOn($inv['issue_date']);
        $img = function (string $kind) use ($companies, $companyId) {
            $i = $companies->image($companyId, $kind);
            return $i ? 'data:' . $i[1] . ';base64,' . base64_encode($i[0]) : '';
        };
        $qr = '';
        $qrError = '';
        try {
            $qr = $this->payBySquare($inv, $supplier);
        } catch (\Throwable $e) {
            $qrError = $e->getMessage();
        }
        return $this->ctx->view->render(Templates::viewFor($inv), [
            'inv' => $inv, 'supplier' => $supplier, 'original' => $original, 'legal' => $legal,
            'logo' => $img('logo'), 'stamp' => $img('stamp'), 'qr' => $qr, 'qrError' => $qrError,
            'forPdf' => $forPdf, 'fontBase' => $forPdf ? Pdf::fontDir() : $this->ctx->app->asset('fonts'),
            'title' => Invoices::typeLabel($inv['doc_type']) . ' ' . ($inv['number'] ?: '(koncept)'),
        ]);
    }

    /**
     * Sample invoice of a company for the preview in its settings (owner 2026-11-20): today's company data, logo, stamp,
     * first active bank account, VAT status of today, the current template. Nothing is saved.
     */
    public function sample(int $companyId): array
    {
        $companies = $this->ctx->companies();
        $today = date('Y-m-d');
        $payer = (bool)$companies->vatOn($companyId, $today)['vat_payer'];
        $banks = $companies->banks($companyId, true);
        $bank = $banks ? $banks[0] : null;
        $c = Calculator::compute([
            ['position' => 1, 'name' => 'Vzorový produkt (náhľad faktúry)', 'sku' => 'VZOR-1', 'quantity' => '2', 'unit' => 'ks',
             'unit_price' => '12.50', 'vat_rate' => '23', 'vat_category' => 'S'],
            ['position' => 2, 'name' => 'Doprava — Slovenská pošta', 'sku' => '', 'quantity' => '1', 'unit' => 'ks',
             'unit_price' => '3.90', 'vat_rate' => '23', 'vat_category' => 'S'],
        ], $payer);
        $user = $this->ctx->user();
        return [
            'id' => 0, 'company_id' => $companyId, 'doc_type' => 'invoice', 'status' => 'draft', 'series_id' => null,
            'number' => 'FA' . date('Y') . '/000000', 'variable_symbol' => date('Y') . '000000', 'issue_date' => $today,
            'delivery_date' => $today, 'due_date' => date('Y-m-d', strtotime('+14 days')), 'currency' => 'EUR',
            'payment_method' => $bank ? 'transfer' : 'paid', 'bank_account_id' => $bank ? (int)$bank['id'] : null,
            'bank_account_name' => '', 'iban' => '', 'bic' => '', 'vat_payer' => $payer ? 1 : 0, 'supplier_json' => null, 'supplier' => null,
            'legal_json' => null, 'legal' => null, 'template' => '', 'customer_id' => null, 'buyer_type' => 'person',
            'buyer_name' => 'Ján Vzorový', 'buyer_street' => 'Hlavná 1', 'buyer_city' => 'Bratislava', 'buyer_zip' => '811 01',
            'buyer_country' => 'SK', 'buyer_ico' => '', 'buyer_dic' => '', 'buyer_icdph' => '', 'buyer_email' => 'zakaznik@example.sk',
            'buyer_phone' => '', 'shop_id' => null, 'shop_connection_id' => 0, 'shop_order_id' => null, 'order_reference' => 'ABCDEFGHI',
            'original_invoice_id' => null, 'correction_reason' => '', 'note' => '', 'internal_note' => '',
            'lines' => $c['lines'], 'breakdown' => $c['breakdown'], 'total_net' => $c['net'], 'total_vat' => $c['vat'], 'total' => $c['total'],
            'issued_by_name' => $user ? $user->getName() : '', 'issued_by' => null, 'issued_at' => null,
            'created_by' => null, 'created_at' => $today, 'updated_at' => $today,
        ];
    }

    /** PAY by square QR (data URI) for payment by bank transfer; '' when not applicable. */
    public function payBySquare(array $inv, array $supplier): string
    {
        if ($inv['payment_method'] !== 'transfer' || (float)$inv['total'] <= 0 || !class_exists(\BaconQrCode\Encoder\Encoder::class)) {
            return '';
        }
        $iban = (string)$inv['iban'];
        $bic = (string)$inv['bic'];
        $holder = (string)$inv['bank_account_name'];
        if ($iban === '' && $inv['bank_account_id']) {   // draft: the chosen account (issued: frozen copy)
            $bank = $this->ctx->companies()->bank((int)$inv['bank_account_id']);
            if ($bank) {
                list($iban, $bic, $holder) = [$bank['iban'], $bank['bic'], $bank['account_name']];
            }
        }
        if ($iban === '') {
            return '';
        }
        $text = PayBySquare::encode([
            'amount' => (float)$inv['total'], 'currency' => $inv['currency'], 'due_date' => $inv['due_date'],
            'vs' => $inv['variable_symbol'], 'iban' => $iban, 'bic' => $bic,
            'note' => trim($supplier['name'] . ': ' . mb_strtolower(Invoices::typeLabel($inv['doc_type']), 'UTF-8') . ' ' . $inv['number']),
            'beneficiary_name' => $holder ?: $supplier['name'],
            'beneficiary_address1' => $supplier['street'],
            'beneficiary_address2' => trim($supplier['zip'] . ' ' . $supplier['city']),
        ]);
        return QrSvg::dataUri($text, 'L');
    }

    /**
     * PDF of a document. Issued: the archived file (created now if it doesn't exist yet). Draft: rendered on the fly.
     * @return array{0: string, 1: string} [pdf bytes, sha256 or '']
     */
    public function pdf(array $inv): array
    {
        $repo = $this->ctx->invoices();
        if ($inv['status'] === 'issued') {
            $stored = $repo->storedPdf((int)$inv['id']);
            if ($stored === null) {
                $repo->storePdf((int)$inv['id'], Pdf::fromHtml($this->html($inv, true)));
                $stored = $repo->storedPdf((int)$inv['id']);
            }
            return [$stored['pdf'], $stored['sha256']];
        }
        return [Pdf::fromHtml($this->html($inv, true)), ''];
    }

    /** Right after issuing: archive the PDF. A failure doesn't undo the issue — the PDF is created on first download. */
    public function archive(int $invoiceId): ?string
    {
        try {
            $inv = $this->ctx->invoices()->find($invoiceId);
            if ($inv && $inv['status'] === 'issued') {
                $this->pdf($inv);
            }
            return null;
        } catch (\Throwable $e) {
            $this->ctx->app->getLogger()->error('Invoice PDF archive failed', ['invoice' => $invoiceId, 'error' => $e->getMessage()]);
            return $e->getMessage();
        }
    }
}
