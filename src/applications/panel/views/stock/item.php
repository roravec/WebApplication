<?php use Panel\Invoicing\Numbering;
/** @var array $item, $levels, $warehouses, $lots, $moves; bool $canEdit */
$q = function ($v) use ($f) { return rtrim(rtrim($f->number($v, 3), '0'), ','); };
$total = array_sum(array_column($levels, 'qty'));
?>
<?= $view->partial('stock/tabs', ['tab' => '']) ?>
<div class="toolbar">
  <span class="muted">Kód <b><?= $e($item['sku']) ?></b><?= $item['ean'] ? ' · EAN ' . $e($item['ean']) : '' ?> · MJ <?= $e($item['unit']) ?></span>
  <?php if ($canEdit): ?>
    <a class="button" href="<?= $e($ctx->url('/stock/items/' . $item['id'] . '/edit')) ?>">Upraviť kartu</a>
    <a class="button" href="<?= $e($ctx->url('/stock/docs/new?type=receipt&company=' . $item['company_id'] . '&item=' . $item['id'])) ?>">+ Príjem</a>
    <a class="button" href="<?= $e($ctx->url('/stock/docs/new?type=issue&company=' . $item['company_id'] . '&item=' . $item['id'])) ?>">+ Výdaj</a>
  <?php endif ?>
</div>
<div class="cards">
  <section class="card">
    <h2>Stav podľa skladov</h2>
    <?php if (!$levels): ?><p class="muted">Na sklade nie je nič.</p><?php endif ?>
    <dl>
      <?php foreach ($levels as $wid => $l): ?>
        <dt><?= $e($warehouses[$wid]['name'] ?? '?') ?></dt><dd><b><?= $e($q($l['qty'])) ?> <?= $e($item['unit']) ?></b> · <?= $e($f->money($l['value'])) ?></dd>
      <?php endforeach ?>
      <?php if (count($levels) > 1): ?><dt>Spolu</dt><dd><b><?= $e($q($total)) ?> <?= $e($item['unit']) ?></b></dd><?php endif ?>
    </dl>
    <?php if ($item['min_qty'] !== null): ?><p class="muted small">Minimum: <?= $e($q($item['min_qty'])) ?> <?= $e($item['unit']) ?></p><?php endif ?>
  </section>
  <?php if ($item['note']): ?><section class="card"><h2>Poznámka</h2><p><?= nl2br($e($item['note'])) ?></p></section><?php endif ?>
</div>

<h2>Dávky na sklade (FIFO — vydávajú sa od najstaršej)</h2>
<table class="grid compact">
  <thead><tr><th>Sklad</th><th>Prijaté</th><th>Doklad</th><th>Šarža</th><th>Spotreba do</th><th class="num">Prijaté množstvo</th><th class="num">Zostatok</th><th class="num">Nákupná cena / MJ</th><th class="num">Hodnota</th></tr></thead>
  <tbody>
  <?php foreach ($lots as $l): ?>
    <tr><td><?= $e($l['warehouse']) ?></td><td><?= $e($f->date($l['received_on'])) ?></td>
      <td><a href="<?= $e($ctx->url('/stock/docs/' . $l['doc_id'])) ?>"><?= $e($l['doc_number']) ?></a></td>
      <td><?= $e($l['lot_code']) ?></td><td><?= $e($f->date($l['expires_on'])) ?></td>
      <td class="num"><?= $e($q($l['qty_received'])) ?></td><td class="num"><b><?= $e($q($l['qty_left'])) ?></b></td>
      <td class="num"><?= $e(preg_replace('/(,\d\d\d*?)0+$/', '$1', $f->number($l['unit_cost'], 4))) ?> €</td>
      <td class="num"><?= $e($f->money((float)$l['qty_left'] * (float)$l['unit_cost'])) ?></td></tr>
  <?php endforeach ?>
  <?php if (!$lots): ?><tr><td colspan="9" class="muted">Žiadne dávky.</td></tr><?php endif ?>
  </tbody>
</table>

<h2>Posledné pohyby</h2>
<table class="grid compact">
  <thead><tr><th>Dátum</th><th>Doklad</th><th>Sklad</th><th class="num">Množstvo</th><th class="num">Cena / MJ</th></tr></thead>
  <tbody>
  <?php foreach ($moves as $m): ?>
    <tr><td><?= $e($f->date($m['doc_date'])) ?></td>
      <td><a href="<?= $e($ctx->url('/stock/docs/' . $m['doc_id'])) ?>"><?= $e($m['number']) ?></a> <span class="muted small"><?= $e(Numbering::STOCK_TYPES[$m['doc_type']] ?? '') ?></span></td>
      <td><?= $e($m['warehouse']) ?></td>
      <td class="num" style="color: <?= (float)$m['qty'] < 0 ? 'var(--red)' : 'var(--ok)' ?>"><?= (float)$m['qty'] > 0 ? '+' : '' ?><?= $e($q($m['qty'])) ?></td>
      <td class="num"><?= $e(preg_replace('/(,\d\d\d*?)0+$/', '$1', $f->number($m['unit_cost'], 4))) ?> €</td></tr>
  <?php endforeach ?>
  <?php if (!$moves): ?><tr><td colspan="5" class="muted">Žiadne pohyby.</td></tr><?php endif ?>
  </tbody>
</table>

<?= $view->partial('core/record', ['ref' => 'stock_item:' . (int)$item['id']]) ?>
