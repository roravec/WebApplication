<?php
/**
 * Dodací list (PDF, dompdf: tables only) — one page per order: shop, order number, addresses, pickup point, carrier,
 * payment, items with codes and a tick column for packing.
 * @var array $orders (from OrderSource::find); string $fontBase; Panel\Format $f
 */
$font = function (string $file) use ($fontBase, $e) { return $e(rtrim($fontBase, '/') . '/' . $file); };
$addr = function ($a) use ($e) {
    if (!$a) {
        return '—';
    }
    $lines = [$a['company'] ?? '', trim(($a['firstname'] ?? '') . ' ' . ($a['lastname'] ?? '')), $a['address1'] ?? '', $a['address2'] ?? '',
        trim(($a['postcode'] ?? '') . ' ' . ($a['city'] ?? '')), $a['country'] ?? '', ($a['phone'] ?? '') ?: ($a['phone_mobile'] ?? '')];
    return implode('<br>', array_map($e, array_filter($lines, 'strlen')));
};
?>
<!doctype html>
<html lang="sk"><head><meta charset="utf-8"><title>Dodacie listy</title>
<style>
  @font-face { font-family: 'Poppins'; font-weight: normal; src: url('<?= $font('Poppins-Regular.ttf') ?>') format('truetype'); }
  @font-face { font-family: 'Poppins'; font-weight: bold; src: url('<?= $font('Poppins-SemiBold.ttf') ?>') format('truetype'); }
  @page { size: A4; margin: 12mm 12mm 16mm 12mm; }
  body { font-family: 'Poppins', 'DejaVu Sans', sans-serif; font-size: 9.5pt; color: #111; line-height: 1.35; }
  table { border-collapse: collapse; width: 100%; }
  td, th { vertical-align: top; padding: 0; }
  .page { page-break-after: always; }
  .page:last-child { page-break-after: auto; }
  h1 { font-size: 16pt; margin: 0; }
  .head td { padding-bottom: 3mm; border-bottom: 0.6mm solid #111; }
  .box td { padding: 3mm 3mm 3mm 0; width: 33%; }
  .lbl { font-size: 7.5pt; color: #555; text-transform: uppercase; letter-spacing: .3pt; }
  .items th { text-align: left; border-bottom: 0.4mm solid #111; padding: 1mm; font-size: 8.5pt; }
  .items td { border-bottom: 0.2mm solid #bbb; padding: 1.2mm 1mm; }
  .items .r { text-align: right; white-space: nowrap; }
  .tick { width: 6mm; height: 6mm; border: 0.4mm solid #111; }
  .note { margin-top: 4mm; padding: 2mm; border: 0.3mm solid #999; }
</style></head><body>
<?php foreach ($orders as $o): ?>
<div class="page">
  <table class="head"><tr>
    <td><h1>Dodací list</h1><?= $e($o['shop']) ?></td>
    <td style="text-align: right"><b style="font-size: 14pt">Objednávka <?= $e($o['reference']) ?></b><br><?= $e($f->dateTime($o['date_add'])) ?></td>
  </tr></table>
  <table class="box"><tr>
    <td><div class="lbl">Dodacia adresa</div><?= $addr($o['address_delivery'] ?? null) ?>
      <?php if (!empty($o['pickup_point'])): ?><br><b>Výdajné miesto:</b> <?= $e($o['pickup_point']) ?><?php endif ?></td>
    <td><div class="lbl">Fakturačná adresa</div><?= $addr($o['address_invoice'] ?? null) ?><br><?= $e($o['customer_email'] ?? '') ?></td>
    <td><div class="lbl">Doprava</div><b><?= $e($o['carrier'] ?? '') ?></b><?= !empty($o['tracking']) ? '<br>Zásielka: ' . $e($o['tracking']) : '' ?>
      <div class="lbl" style="margin-top: 2mm">Platba</div><?= $e($o['payment'] ?? '') ?><br><b><?= $e($f->money($o['total_paid_tax_incl'], (string)($o['currency'] ?: 'EUR'))) ?></b></td>
  </tr></table>
  <table class="items">
    <thead><tr><th style="width: 8mm"></th><th>Produkt</th><th>Kód</th><th class="r">Ks</th></tr></thead>
    <tbody>
    <?php foreach ($o['lines'] as $l): ?>
      <tr><td><div class="tick"></div></td><td><?= $e($l['product_name']) ?></td><td><?= $e($l['product_reference']) ?></td><td class="r"><b><?= (int)$l['product_quantity'] ?></b></td></tr>
    <?php endforeach ?>
    </tbody>
  </table>
  <p>Spolu kusov: <b><?= array_sum(array_map('intval', array_column($o['lines'], 'product_quantity'))) ?></b></p>
</div>
<?php endforeach ?>
</body></html>
