<?php /** @var array $connections; bool $canSettings — Nastavenia → E-shopy: connections (Webservice key) and order states */ ?>
<p class="muted">E-shop (PrestaShop) je s panelom prepojený kľúčom Webservice. Cez pripojenie panel mení objednávky (stav,
  adresy, číslo zásielky …), produkty, ceny a sklad priamo v e-shope. <b>Stavy objednávok</b> patria inštalácii
  PrestaShopu — sú spoločné pre všetky jej obchody.</p>
<p><a class="button primary" href="<?= $e($ctx->url('/settings/eshops/new')) ?>">+ Pripojiť e-shop</a></p>
<?php if (!$connections): ?><p class="muted">Zatiaľ nie je pripojený žiadny e-shop.</p><?php endif ?>
<div class="cards">
<?php foreach ($connections as $c): $src = !empty($c['local_db']) ? 0 : (int)$c['id']; ?>
  <section class="card">
    <h2><?= $e($c['name']) ?><?= $c['active'] ? '' : ' <span class="badge">neaktívne</span>' ?></h2>
    <p class="muted small"><?= $e($c['url']) ?> · objednávky <?= !empty($c['local_db']) ? 'z databázy panela (tá istá databáza)' : 'cez Webservice' ?></p>
    <?php if ($c['error']): ?><p class="flash err"><?= $e($c['error']) ?></p><?php endif ?>
    <p><a class="button" href="<?= $e($ctx->url('/settings/eshops/' . $c['id'] . '/edit')) ?>">Upraviť pripojenie</a>
      <?php if ($c['active']): ?><a class="button" href="<?= $e($ctx->url('/settings/eshops/states' . ($src ? '?src=' . $src : ''))) ?>">Stavy objednávok</a><?php endif ?></p>
    <?php if ($c['shops']): ?><p class="small">Obchody: <?= $e(implode(', ', array_column($c['shops'], 'name'))) ?></p><?php endif ?>
  </section>
<?php endforeach ?>
</div>
