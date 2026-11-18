<?php /** @var bool $shopAvailable; array|null $stats; array $latest */ ?>
<?php if (!$shopAvailable): ?>
  <?= $view->partial('orders/unavailable') ?>
<?php else: ?>
  <div class="tiles">
    <div class="tile"><span>Dnes</span><b><?= (int)$stats['today'] ?></b><small>objednávok</small></div>
    <div class="tile"><span>Posledných 7 dní</span><b><?= (int)$stats['week'] ?></b><small>objednávok</small></div>
    <div class="tile"><span>Posledných 30 dní</span><b><?= (int)$stats['month'] ?></b><small><?= $e($f->money($stats['month_total'])) ?></small></div>
  </div>

  <h2>E-shopy — posledných 30 dní</h2>
  <table class="grid">
    <thead><tr><th>E-shop</th><th class="num">Objednávky</th><th class="num">Spolu</th></tr></thead>
    <tbody>
    <?php foreach ($stats['per_shop'] as $s): ?>
      <tr><td><?= $e($s['name']) ?></td><td class="num"><?= (int)$s['orders'] ?></td><td class="num"><?= $e($f->money($s['total'])) ?></td></tr>
    <?php endforeach ?>
    </tbody>
  </table>

  <h2>Najnovšie objednávky</h2>
  <?= $view->partial('orders/table', ['rows' => $latest]) ?>
  <p><a href="<?= $e($ctx->url('/orders')) ?>">Všetky objednávky →</a></p>
<?php endif ?>
