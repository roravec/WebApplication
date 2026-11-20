<?php /** @var string $tab — Výsledky (docs/sprava-firmy-specifikacia.md) */ ?>
<nav class="tabs">
  <a href="<?= $e($ctx->url('/reports/result')) ?>" class="<?= $tab === 'result' ? 'active' : '' ?>">Výsledok firmy</a>
  <a href="<?= $e($ctx->url('/reports/money')) ?>" class="<?= $tab === 'money' ? 'active' : '' ?>">Peniaze</a>
  <a href="<?= $e($ctx->url('/reports/sales')) ?>" class="<?= $tab === 'sales' ? 'active' : '' ?>">Predaje a marža</a>
  <a href="<?= $e($ctx->url('/reports/purchase-prices')) ?>" class="<?= $tab === 'purchase' ? 'active' : '' ?>">Nákupné ceny</a>
  <a href="<?= $e($ctx->url('/reports/order-costs')) ?>" class="<?= $tab === 'order-costs' ? 'active' : '' ?>">Náklady objednávok</a>
</nav>
