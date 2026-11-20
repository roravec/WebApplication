<?php
namespace Panel\Invoicing;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;

/** QR code as a small SVG (no GD / Imagick needed): bacon/bacon-qr-code computes the matrix, we draw the modules. */
class QrSvg
{
    /**
     * @param string $text e.g. a PAY by square string (alphanumeric: 0-9 A-V → QR alphanumeric mode)
     * @param string $level L / M / Q / H (PAY by square uses L)
     */
    public static function svg(string $text, string $level = 'L', int $quiet = 2): string
    {
        $matrix = Encoder::encode($text, ErrorCorrectionLevel::forBits(['L' => 1, 'M' => 0, 'Q' => 3, 'H' => 2][$level] ?? 1), 'ISO-8859-1')
            ->getMatrix();
        $n = $matrix->getWidth();
        $size = $n + 2 * $quiet;
        $path = '';
        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                if ($matrix->get($x, $y) === 1) {
                    $path .= 'M' . ($x + $quiet) . ' ' . ($y + $quiet) . 'h1v1h-1z';
                }
            }
        }
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $size . ' ' . $size . '" width="' . $size . '" height="' . $size
            . '" shape-rendering="crispEdges"><rect width="100%" height="100%" fill="#fff"/><path fill="#000" d="' . $path . '"/></svg>';
    }

    public static function dataUri(string $text, string $level = 'L'): string
    {
        return 'data:image/svg+xml;base64,' . base64_encode(self::svg($text, $level));
    }
}
