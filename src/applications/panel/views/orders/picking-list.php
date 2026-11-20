<?php
/**
 * Vychystávací zoznam (PDF): all items of the selected orders summed per product / combination — what to take from the
 * shelves — and which orders need them. @var array $orders; string $fontBase; Panel\Format $f
 */
$font = function (string $file) use ($fontBase, $e) { return $e(rtrim($fontBase, '/') . '/' . $file); };
$items = [];
foreach ($orders as $o) {
    foreach ($o['lines'] as $l) {
        $k = ($l['product_id'] ?? 0) . '-' . ($l['product_attribute_id'] ?? 0) . '-' . $l['product_reference'] . '-' . $l['product_name'];
        if (!isset($items[$k])) {
            $items[$k] = ['name' => $l['product_name'], 'reference' => $l['product_reference'], 'qty' => 0, 'orders' => []];
        }
        $items[$k]['qty'] += (int)$l['product_quantity'];
        $items[$k]['orders'][] = $o['reference'] . ((int)$l['product_quantity'] > 1 ? ' (' . (int)$l['product_quantity'] . ')' : '');
    }
}
uasort($items, function ($a, $b) { return strcmp((string)$a['reference'] . $a['name'], (string)$b['reference'] . $b['name']); });
?>
<!doctype html>
<html lang="sk"><head><meta charset="utf-8"><title>Vychystávací zoznam</title>
<style>
  @font-face { font-family: 'Poppins'; font-weight: normal; src: url('<?= $font('Poppins-Regular.ttf') ?>') format('truetype'); }
  @font-face { font-family: 'Poppins'; font-weight: bold; src: url('<?= $font('Poppins-SemiBold.ttf') ?>') format('truetype'); }
  @page { size: A4; margin: 12mm 12mm 16mm 12mm; }
  body { font-family: 'Poppins', 'DejaVu Sans', sans-serif; font-size: 9.5pt; color: #111; }
  table { border-collapse: collapse; width: 100%; }
  th { text-align: left; border-bottom: 0.4mm solid #111; padding: 1mm; font-size: 8.5pt; }
  td { border-bottom: 0.2mm solid #bbb; padding: 1.2mm 1mm; vertical-align: top; }
  .r { text-align: right; white-space: nowrap; }
  .tick { width: 6mm; height: 6mm; border: 0.4mm solid #111; }
  .small { font-size: 7.5pt; color: #555; }
</style></head><body>
<h1 style="font-size: 16pt; margin: 0 0 1mm">Vychystávací zoznam</h1>
<p class="small"><?= $e(date('j. n. Y H:i')) ?> · <?= count($orders) ?> objednávok: <?= $e(implode(', ', array_column($orders, 'reference'))) ?></p>
<table>
  <thead><tr><th style="width: 8mm"></th><th>Kód</th><th>Produkt</th><th class="r">Ks</th><th>Objednávky</th></tr></thead>
  <tbody>
  <?php foreach ($items as $i): ?>
    <tr><td><div class="tick"></div></td><td><?= $e($i['reference']) ?></td><td><?= $e($i['name']) ?></td><td class="r"><b><?= (int)$i['qty'] ?></b></td>
      <td class="small"><?= $e(implode(', ', $i['orders'])) ?></td></tr>
  <?php endforeach ?>
  </tbody>
</table>
<p>Spolu kusov: <b><?= array_sum(array_column($items, 'qty')) ?></b></p>
</body></html>
