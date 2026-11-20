<?php /** @var array $connections; bool $isAdmin */ ?>
<?= $view->partial('stock/tabs', ['tab' => 'shops']) ?>
<p class="muted">Sklad e-shopu ostáva v e-shope — panel ho číta a mení priamo v ňom (produkty a kombinácie presne ako v
  PrestaShope). Predaj v e-shope odpisuje e-shop sám.</p>
<?php if ($isAdmin): ?><p><a class="button primary" href="<?= $e($ctx->url('/stock/shops/new')) ?>">+ Pripojiť e-shop</a></p><?php endif ?>
<?php if (!$connections): ?><p class="muted">Zatiaľ nie je pripojený žiadny e-shop.</p><?php endif ?>
<div class="cards">
<?php foreach ($connections as $c): ?>
  <section class="card">
    <h2><?= $e($c['name']) ?><?= $c['active'] ? '' : ' <span class="badge">neaktívne</span>' ?></h2>
    <p class="muted small"><?= $e($c['url']) ?> · PrestaShop Webservice · objednávky <?= !empty($c['local_db']) ? 'z databázy panela' : 'cez Webservice' ?><?php if ($isAdmin): ?> · <a href="<?= $e($ctx->url('/stock/shops/' . $c['id'] . '/edit')) ?>">nastavenie</a><?php endif ?></p>
    <?php if ($c['error']): ?><p class="flash err"><?= $e($c['error']) ?></p><?php endif ?>
    <?php foreach ($c['shops'] as $s): ?>
      <p><a href="<?= $e($ctx->url('/stock/shops/' . $c['id'] . '/' . $s['id'])) ?>"><?= $e($s['name']) ?></a>
        <span class="muted small">· skupina <?= $e($s['group_name']) ?><?= $s['share_stock'] ? ' (spoločný sklad skupiny)' : '' ?></span></p>
    <?php endforeach ?>
  </section>
<?php endforeach ?>
</div>
