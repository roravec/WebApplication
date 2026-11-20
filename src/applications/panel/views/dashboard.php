<?php /** @var bool $canOrders, $shopAvailable; array|null $stats, $drafts, $overdue; array $latest, $shopErrors, $business, $todo */
$levelClass = ['ok' => 'pay-paid', 'warn' => 'pay-unpaid', 'over' => 'pay-overdue', 'over_immediate' => 'pay-overdue'];
?>
<?php foreach ($shopErrors as $err): ?><p class="flash err">E-shop neodpovedá — <?= $e($err) ?></p><?php endforeach ?>
<?php if ($todo): ?>
  <section class="card todo">
    <h2>Treba urobiť</h2>
    <ul>
      <?php foreach ($todo as list($level, $text, $url)): ?>
        <li class="todo-<?= $e($level) ?>"><a href="<?= $e($url) ?>"><?= $e($text) ?></a></li>
      <?php endforeach ?>
    </ul>
  </section>
<?php endif ?>
<?php if ($business): ?>
  <div class="cards">
  <?php foreach ($business as $b): $c = $b['cur']; $p = $b['prev']; ?>
    <section class="card">
      <h2><?= $e($b['company']['name']) ?> — tento mesiac</h2>
      <p class="big"><?= $e($f->money($c['profit'])) ?></p>
      <p class="muted small">zisk zatiaľ (minulý rok k rovnakému dňu <?= $e($f->money($p['profit'])) ?>) · tržby <?= $e($f->money($c['revenue'])) ?>
        (<?= $e($f->money($p['revenue'])) ?>) · <?= (int)$c['orders'] ?> objednávok<?= $c['order_costs_source'] === 'estimate' ? ' · náklady objednávok odhadom' : '' ?></p>
      <?php if ($b['vat']): $v = $b['vat']; ?>
        <p class="small">Obrat pre DPH: <b><?= $e($f->money($v['turnover'])) ?></b> <span class="badge <?= $e($levelClass[$v['level']]) ?>"><?= number_format($v['pct'], 0) ?> % hranice</span></p>
      <?php endif ?>
      <p class="small"><a href="<?= $e($ctx->url('/reports/result?company=' . $b['company']['id'])) ?>">Výsledok firmy →</a> · <a href="<?= $e($ctx->url('/reports/money?company=' . $b['company']['id'])) ?>">Peniaze →</a></p>
    </section>
  <?php endforeach ?>
  </div>
<?php endif ?>
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
