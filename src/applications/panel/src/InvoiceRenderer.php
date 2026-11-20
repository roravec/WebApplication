<?php
namespace Panel;

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
