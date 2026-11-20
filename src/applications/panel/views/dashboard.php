<?php /** @var bool $canOrders, $shopAvailable; array|null $stats, $drafts, $overdue; array $latest, $shopErrors */ ?>
<?php foreach ($shopErrors as $err): ?><p class="flash err">E-shop neodpovedá — <?= $e($err) ?></p><?php endforeach ?>
<?php if ($overdue && $overdue['total'] > 0): ?>
  <section class="card">
    <h2>Faktúry po splatnosti (<?= (int)$overdue['total'] ?>)</h2>
    <?php foreach ($overdue['rows'] as $d): ?>
      <p><a href="<?= $e($ctx->url('/invoices/' . $d['id'])) ?>"><?= $e($d['number']) ?></a> · <?= $e($d['buyer_name']) ?>
        · <?= $e($f->money((float)$d['total'] - (float)$d['paid'], $d['currency'])) ?> · splatná <?= $e($f->date($d['due_date'])) ?></p>
    <?php endforeach ?>
    <p><a href="<?= $e($ctx->url('/invoices?payment=overdue')) ?>">Všetky po splatnosti →</a></p>
  </section>
<?php endif ?>
<?php if ($drafts && $drafts['total'] > 0): ?>
  <section class="card">
    <h2>Rozpracované faktúry (<?= (int)$drafts['total'] ?>)</h2>
    <?php foreach ($drafts['rows'] as $d): ?>
      <p><a href="<?= $e($ctx->url('/invoices/' . $d['id'])) ?>"><?= $e($d['buyer_name'] ?: 'bez odberateľa') ?></a>
        · <?= $e($f->money($d['total'], $d['currency'])) ?><?= $d['order_reference'] ? ' · objednávka ' . $e($d['order_reference']) : '' ?></p>
    <?php endforeach ?>
  </section>
<?php endif ?>
<?php if (!$canOrders): ?>
  <p class="muted">Vitajte v sekcii pre zamestnancov.</p>
<?php elseif (!$shopAvailable): ?>
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
