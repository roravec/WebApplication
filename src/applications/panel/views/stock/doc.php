<?php use Panel\Invoicing\Numbering; /** @var array $d */
$q = function ($v) use ($f) { return rtrim(rtrim($f->number($v, 3), '0'), ','); };
?>
<?= $view->partial('stock/tabs', ['tab' => 'docs']) ?>
<div class="cards">
  <section class="card">
    <h2><?= $e(Numbering::STOCK_TYPES[$d['doc_type']]) ?></h2>
    <dl>
      <dt>Číslo</dt><dd><b><?= $e($d['number']) ?></b></dd>
      <dt>Dátum</dt><dd><?= $e($f->date($d['doc_date'])) ?></dd>
      <dt>Sklad</dt><dd><?= $e($d['warehouse']) ?><?= $d['target_warehouse'] ? ' → ' . $e($d['target_warehouse']) : '' ?></dd>
      <?php if ($d['partner']): ?><dt>Partner</dt><dd><?= $e($d['partner']) ?></dd><?php endif ?>
      <?php if ($d['reference']): ?><dt>Odkaz</dt><dd><?= $e($d['reference']) ?></dd><?php endif ?>
      <dt>Hodnota</dt><dd><b><?= $e($f->money($d['total_cost'])) ?></b> <span class="muted small">(nákupné ceny)</span></dd>
    </dl>
    <?php if ($d['note']): ?><p><?= nl2br($e($d['note'])) ?></p><?php endif ?>
    <p class="muted small">Zaúčtovaný doklad sa nedá meniť — opravu urobte opačným dokladom.</p>
  </section>
</div>
<h2>Pohyby</h2>
<table class="grid compact">
  <thead><tr><th>Kód</th><th>Názov</th><th>Sklad</th><th>Dávka (prijatá)</th><th class="num">Množstvo</th><th class="num">Cena / MJ</th><th class="num">Hodnota</th></tr></thead>
  <tbody>
  <?php foreach ($d['moves'] as $m): ?>
    <tr><td><a href="<?= $e($ctx->url('/stock/items/' . $m['item_id'])) ?>"><?= $e($m['sku']) ?></a></td><td><?= $e($m['name']) ?></td>
      <td><?= $e($m['warehouse']) ?></td><td><?= $e($f->date($m['received_on'])) ?><?= $m['lot_code'] ? ' · ' . $e($m['lot_code']) : '' ?></td>
      <td class="num" style="color: <?= (float)$m['qty'] < 0 ? 'var(--red)' : 'var(--ok)' ?>"><?= (float)$m['qty'] > 0 ? '+' : '' ?><?= $e($q($m['qty'])) ?> <?= $e($m['unit']) ?></td>
      <td class="num"><?= $e(preg_replace('/(,\d\d\d*?)0+$/', '$1', $f->number($m['unit_cost'], 4))) ?> €</td>
      <td class="num"><?= $e($f->money((float)$m['qty'] * (float)$m['unit_cost'])) ?></td></tr>
  <?php endforeach ?>
  </tbody>
</table>

<?= $view->partial('core/record', ['ref' => 'stock_doc:' . (int)$d['id']]) ?>
