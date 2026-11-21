<?php /** @var string|null $error */ ?>
<?php if (!empty($error)): ?>
  <p class="flash err">E-shop neodpovedá: <?= $e($error) ?></p>
  <p><a href="<?= $e($ctx->url('/orders')) ?>">← Objednávky</a></p>
<?php else: ?>
  <p class="flash err">Objednávky nie sú dostupné: v databáze nie sú tabuľky e-shopu (nastavenie <code>apps.panel.shop.prefix</code>)
    a nie je pripojený žiadny e-shop cez Webservice (Nastavenia → E-shopy).</p>
<?php endif ?>
