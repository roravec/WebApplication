<?php
namespace Panel\Invoicing;

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * HTML → PDF with dompdf (Composer, src/vendor; pure PHP, works on shared hosting without shell).
 * dompdf understands tables, floats and position:fixed, not CSS grid/flex — PDF templates are written with tables.
 * Fonts: local TTF files referenced by @font-face (panel/fonts, chroot); font cache in var/dompdf (writable).
 */
class Pdf
{
    public static function available(): bool
    {
        return class_exists(Dompdf::class);
    }

    /**
     * @param string $html complete HTML document
     * @param string $pageLabel footer text with {PAGE_NUM} and {PAGE_COUNT}, '' = none
     */
    public static function fromHtml(string $html, string $pageLabel = 'Strana {PAGE_NUM} z {PAGE_COUNT}'): string
    {
        if (!self::available()) {
            throw new \RuntimeException('Chýba knižnica dompdf (src/vendor) — spustite composer install.');
        }
        $cache = (defined('WA_VAR') ? WA_VAR : sys_get_temp_dir()) . '/dompdf';
        if (!is_dir($cache)) {
            @mkdir($cache, 0775, true);
        }
        $options = new Options();
        $options->setChroot([self::fontDir(), $cache]);
        $options->setFontDir($cache);
        $options->setFontCache($cache);
        $options->setTempDir(sys_get_temp_dir());
        $options->setIsRemoteEnabled(false);          // only local files and data: URIs
        $options->setDefaultMediaType('print');
        $options->setDefaultFont('DejaVu Sans');
        $options->setDpi(96);
        // dompdf line height = (font ascender − descender) × ratio; Poppins has a tall box (1.4 em) → 0.72 ≈ browser spacing
        $options->setFontHeightRatio(0.72);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        if ($pageLabel !== '') {
            $canvas = $dompdf->getCanvas();
            $font = $dompdf->getFontMetrics()->getFont('Poppins') ?: $dompdf->getFontMetrics()->getFont('DejaVu Sans');
            // bottom right, inside the footer area (A4 = 595 × 842 pt)
            $canvas->page_text(505, 801, $pageLabel, $font, 7.5, [0.2, 0.2, 0.2]);
        }
        return (string)$dompdf->output();
    }

    /** Absolute path of the font folder (for @font-face in PDF templates). */
    public static function fontDir(): string
    {
        return dirname(__DIR__, 2) . '/fonts';
    }
}
