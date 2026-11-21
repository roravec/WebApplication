<?php
/**
 * Shared look of printed documents of all modules (Core\Documents::html / pdf): company header (logo, address, IČO /
 * DIČ), title + number in the company's accent colour, meta rows, the module's body, footer (registration, bank).
 * dompdf: tables and simple CSS only (no flex / grid).
 * @var array $company, $meta; array|null $bank; string $logo, $stamp, $accent, $docTitle, $number, $body (HTML), $fontBase; bool $forPdf
 */
$font = function (string $file) use ($fontBase, $e) { return $e(rtrim($fontBase, '/') . '/' . $file); };
$c = $company;
?>
<!doctype html>
<html lang="sk">
<head>
<meta charset="utf-8">
<title><?= $e($docTitle . ' ' . $number) ?></title>
<style>
  @font-face { font-family: 'Poppins'; font-style: normal; font-weight: normal; src: url('<?= $font('Poppins-Regular.ttf') ?>') format('truetype'); }
  @font-face { font-family: 'Poppins'; font-style: normal; font-weight: bold; src: url('<?= $font('Poppins-SemiBold.ttf') ?>') format('truetype'); }
  @page { margin: 14mm 14mm 18mm; }
  body { margin: 0; color: #222; font-family: 'Poppins', 'DejaVu Sans', sans-serif; font-size: 9pt; line-height: 1.35; }
  .head { width: 100%; border-collapse: collapse; }
  .head td { vertical-align: top; }
  .logo img { max-height: 22mm; max-width: 70mm; }
  .supplier { text-align: right; font-size: 8.5pt; }
  .supplier b { font-size: 10pt; }
  h1 { color: <?= $e($accent) ?>; font-size: 17pt; margin: 8mm 0 1mm; }
  h1 small { color: #222; font-size: 12pt; font-weight: normal; }
  .meta { border-collapse: collapse; margin: 2mm 0 5mm; }
  .meta td { padding: .4mm 6mm .4mm 0; }
  .meta td:first-child { color: #666; }
  .body table { border-collapse: collapse; width: 100%; }
  .body th { background: <?= $e($accent) ?>; color: #fff; text-align: left; padding: 1.2mm 2mm; font-weight: bold; }
  .body td { border-bottom: 0.3mm solid #ddd; padding: 1.2mm 2mm; }
  .body .num { text-align: right; }
  .foot { position: fixed; bottom: -12mm; left: 0; right: 0; font-size: 7pt; color: #777; border-top: 0.3mm solid <?= $e($accent) ?>; padding-top: 1mm; }
  <?php if (!$forPdf): ?>body { max-width: 190mm; margin: 10mm auto; } .foot { position: static; margin-top: 10mm; }<?php endif ?>
</style>
</head>
<body>
<table class="head"><tr>
  <td class="logo"><?php if ($logo !== ''): ?><img src="<?= $e($logo) ?>" alt=""><?php endif ?></td>
  <td class="supplier"><b><?= $e($c['name']) ?></b><br><?= $e($c['street']) ?><br><?= $e(trim($c['zip'] . ' ' . $c['city'])) ?>
    <?php if ($c['ico'] !== ''): ?><br>IČO: <?= $e($c['ico']) ?><?php endif ?><?php if ($c['dic'] !== ''): ?> · DIČ: <?= $e($c['dic']) ?><?php endif ?>
    <?php if ($c['email'] !== '' || $c['phone'] !== ''): ?><br><?= $e(trim($c['email'] . '  ' . $c['phone'])) ?><?php endif ?></td>
</tr></table>
<h1><?= $e($docTitle) ?> <?php if ($number !== ''): ?><small><?= $e($number) ?></small><?php endif ?></h1>
<?php if ($meta): ?>
<table class="meta"><?php foreach ($meta as $k => $v): ?><tr><td><?= $e($k) ?></td><td><?= $e($v) ?></td></tr><?php endforeach ?></table>
<?php endif ?>
<div class="body"><?= $body ?></div>
<div class="foot"><?= $e($c['name']) ?><?= $c['registration'] !== '' ? ' · ' . $e($c['registration']) : '' ?>
  <?php if ($bank): ?> · IBAN <?= $e(trim(chunk_split($bank['iban'], 4, ' '))) ?><?php endif ?><?= $c['web'] !== '' ? ' · ' . $e($c['web']) : '' ?></div>
</body>
</html>
