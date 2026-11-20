<?php /** @var array $filters, $warehouses, $items; bool $canEdit */
$sumQty = 0; $sumValue = 0.0;
foreach ($items as $i) { $sumValue += (float)$i['value']; }
$multi = count(array_unique(array_column($warehouses, 'company_id'))) > 1;
?>
<?= $view->partial('stock/tabs', ['tab' => 'index']) ?>
<form class="filters" method="get" action="<?= $e($ctx->url('/stock')) ?>">
  <input name="q" value="<?= $e($filters['q']) ?>" placeholder="Kód, názov, EAN">
  <select name="warehouse"><option value="">Všetky sklady</option>
    <?php foreach ($warehouses as $w): ?><option value="<?= (int)$w['id'] ?>"<?= $filters['warehouse'] === (int)$w['id'] ? ' selected' : '' ?>><?= $e(($multi ? $w['company'] . ' · ' : '') . $w['name']) ?></option><?php endforeach ?>
  </select>
  <label class="check"><input type="checkbox" name="all" value="1"<?= $filters['all'] ? ' checked' : '' ?>> aj karty bez zásoby</label>
  <button class="primary">Hľadať</button>
</form>
<?php if (!$warehouses): ?>
  <p class="muted">Zatiaľ nie je založený žiadny sklad. <?php if ($canEdit): ?><a href="<?= $e($ctx->url('/stock/warehouses/new')) ?>">Založiť sklad</a><?php endif ?></p>
<?php endif ?>
<p class="muted"><?= count($items) ?> kariet · hodnota zásob v nákupných cenách <b><?= $e($f->money($sumValue)) ?></b></p>
<table class="grid">
  <thead><tr><th>Kód</th><th>Názov</th><?php if ($multi): ?><th>Firma</th><?php endif ?><th class="num">Množstvo</th><th class="num">Priem. cena</th><th class="num">Hodnota</th></tr></thead>
  <tbody>
  <?php foreach ($items as $i): $low = $i['min_qty'] !== null && (float)$i['qty'] < (float)$i['min_qty']; ?>
    <tr class="<?= $i['active'] ? '' : 'inactive' ?>">
      <td><a href="<?= $e($ctx->url('/stock/items/' . $i['id'])) ?>"><?= $e($i['sku']) ?></a></td>
      <td><?= $e($i['name']) ?><?= $low ? ' <span class="badge pay-overdue" title="Pod minimálnym množstvom ' . $e($f->number($i['min_qty'], 0)) . '">málo</span>' : '' ?></td>
      <?php if ($multi): ?><td><?= $e($i['company']) ?></td><?php endif ?>
      <td class="num"><?= $e(rtrim(rtrim($f->number($i['qty'], 3), '0'), ',')) ?> <?= $e($i['unit']) ?></td>
      <td class="num"><?= (float)$i['qty'] > 0 ? $e($f->money((float)$i['value'] / (float)$i['qty'])) : '' ?></td>
      <td class="num"><?= $e($f->money($i['value'])) ?></td>
    </tr>
  <?php endforeach ?>
  <?php if (!$items): ?><tr><td colspan="6" class="muted">Žiadne karty.</td></tr><?php endif ?>
  </tbody>
</table>
