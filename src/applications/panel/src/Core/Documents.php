<?php
namespace Panel\Core;

use Panel\Invoicing\Companies;
use Panel\Invoicing\Numbering;
use Panel\Invoicing\Pdf;
use Panel\PanelContext;
use WebApplication\Database\Database;

/**
 * Documents of all modules (docs/panel-architektura.md chapter 4 "Číslovanie + PDF šablóny"): one mechanism of number
 * series (the invoices' Numbering — row lock, no duplicates, no gaps on rollback) and one PDF look of the company
 * (logo, accent colour, address, IČO / DIČ, registration, bank, page numbers) for any document a module prints.
 */
class Documents
{
    /** @var array|null */
    private static $types = null;

    /** Document types registered by the modules (Core\ProvidesDocumentTypes). @return array type => [label, table, format] */
    public static function types(): array
    {
        if (self::$types === null) {
            self::$types = self::fromModules(\PanelApp::modules());
        }
        return self::$types;
    }

    /** @param \Panel\Module[] $modules */
    public static function fromModules(array $modules): array
    {
        $out = [];
        foreach ($modules as $m) {
            if ($m instanceof ProvidesDocumentTypes) {
                foreach ($m->documentTypes() as $type => $d) {
                    if (isset($out[$type]) || isset(Numbering::TYPES[$type]) || isset(Numbering::STOCK_TYPES[$type])) {
                        throw new \LogicException('Typ dokladu ' . $type . ' je registrovaný dvakrát.');
                    }
                    if (Numbering::formatError((string)$d['format']) !== null || !preg_match('/^[a-z_]+$/', (string)$d['table'])) {
                        throw new \LogicException('Typ dokladu ' . $type . ': neplatný formát čísla alebo tabuľka.');
                    }
                    $out[$type] = ['label' => (string)$d['label'], 'table' => (string)$d['table'], 'format' => (string)$d['format']];
                }
            }
        }
        return $out;
    }

    /**
     * Next number of the company's default series of the type (created with the registered default format when the
     * company has none yet). MUST run inside the transaction that stores the document.
     * @return array{number: string, vs: string, counter: int, series_id: int}
     */
    public static function allocate(Database $db, int $companyId, string $type, string $date): array
    {
        $companies = new Companies($db);
        $s = $companies->defaultSeries($companyId, $type);
        if ($s === null) {
            $t = self::types()[$type] ?? null;
            if ($t === null) {
                throw new \InvalidArgumentException('Neznámy typ dokladu: ' . $type);
            }
            $id = $companies->saveSeries(null, $companyId, ['doc_type' => $type, 'name' => $t['label'], 'format' => $t['format'], 'vs_format' => '',
                'reset_yearly' => 1, 'is_default' => 1, 'active' => 1]);
            $s = $companies->oneSeries($id);
        }
        return Numbering::allocate($db, $s, $companyId, $date) + ['series_id' => (int)$s['id']];
    }

    /** The company's look + data for a printed document (views/pdf/document.php). */
    public static function look(PanelContext $ctx, int $companyId): array
    {
        $companies = $ctx->companies();
        $c = $companies->find($companyId);
        if ($c === null) {
            throw new \InvalidArgumentException('Firma neexistuje.');
        }
        $img = function (string $kind) use ($companies, $companyId) {
            $i = $companies->image($companyId, $kind);
            return $i ? 'data:' . $i[1] . ';base64,' . base64_encode($i[0]) : '';
        };
        $bank = $companies->banks($companyId, true)[0] ?? null;
        return ['company' => $c, 'logo' => $img('logo'), 'stamp' => $img('stamp'), 'bank' => $bank,
            'accent' => preg_match('/^#[0-9a-fA-F]{6}$/', (string)($c['accent_color'] ?? '')) ? $c['accent_color'] : '#f74040'];
    }

    /**
     * HTML of a document in the company's look (browser preview: $forPdf = false). @param array $meta label => value
     * shown under the title (Dátum, Zákazník …); $bodyHtml = the module's content (already escaped!)
     */
    public static function html(PanelContext $ctx, int $companyId, string $title, string $number, string $bodyHtml, array $meta = [], bool $forPdf = true): string
    {
        return $ctx->view->render('pdf/document', self::look($ctx, $companyId) + ['docTitle' => $title, 'number' => $number, 'body' => $bodyHtml,
            'meta' => $meta, 'forPdf' => $forPdf, 'fontBase' => $forPdf ? Pdf::fontDir() : $ctx->app->asset('fonts')]);
    }

    /** PDF bytes of the document. */
    public static function pdf(PanelContext $ctx, int $companyId, string $title, string $number, string $bodyHtml, array $meta = []): string
    {
        return Pdf::fromHtml(self::html($ctx, $companyId, $title, $number, $bodyHtml, $meta, true));
    }
}
