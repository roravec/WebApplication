<?php /** @var array $connections; bool $canSettings — menu E-shopy: the shops, their products, prices, sales and stock */ ?>
<p class="muted">Produkty, ceny, akcie a sklad e-shopov — panel ich číta a mení priamo v e-shope (produkty a kombinácie
  presne ako v PrestaShope). Sklad e-shopu ostáva v e-shope a predaj v e-shope ho odpisuje sám.
  <?php if ($canSettings): ?>Pripojenia e-shopov a stavy objednávok sú v <a href="<?= $e($ctx->url('/settings/eshops')) ?>">Nastaveniach → E-shopy</a>.<?php endif ?></p>
<?php if (!$connections): ?>
  <p class="muted">Zatiaľ nie je pripojený žiadny e-shop.<?php if ($canSettings): ?> Pripojíte ho v <a href="<?= $e($ctx->url('/settings/eshops')) ?>">Nastaveniach → E-shopy</a>.<?php endif ?></p>
<?php endif ?>
<div class="cards">
<?php foreach ($connections as $c): if (!$c['active']) continue; ?>
  <section class="card">
    <h2><?= $e($c['name']) ?></h2>
    <?php if ($c['error']): ?><p class="flash err"><?= $e($c['error']) ?></p><?php endif ?>
    <?php foreach ($c['shops'] as $s): ?>
      <p><a href="<?= $e($ctx->url('/eshops/' . $c['id'] . '/' . $s['id'])) ?>"><?= $e($s['name']) ?></a>
        <span class="muted small">· skupina <?= $e($s['group_name']) ?><?= $s['share_stock'] ? ' (spoločný sklad skupiny)' : '' ?></span></p>
    <?php endforeach ?>
  </section>
<?php endforeach ?>
</div>
