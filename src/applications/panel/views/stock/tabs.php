<?php use Panel\Rights; /** @var string $tab */ ?>
<nav class="tabs">
  <a href="<?= $e($ctx->url('/stock')) ?>" class="<?= $tab === 'index' ? 'active' : '' ?>">Stav skladu</a>
  <a href="<?= $e($ctx->url('/stock/docs')) ?>" class="<?= $tab === 'docs' ? 'active' : '' ?>">Doklady</a>
  <a href="<?= $e($ctx->url('/stock/warehouses')) ?>" class="<?= $tab === 'warehouses' ? 'active' : '' ?>">Sklady</a>
  <?php if ($ctx->can(Rights::STOCK_EDIT)): ?>
    <span class="push"></span>
    <a class="button" href="<?= $e($ctx->url('/stock/docs/new?type=receipt')) ?>">+ Príjemka</a>
    <a class="button" href="<?= $e($ctx->url('/stock/docs/new?type=issue')) ?>">+ Výdajka</a>
    <a class="button" href="<?= $e($ctx->url('/stock/docs/new?type=transfer')) ?>">+ Prevodka</a>
    <a class="button" href="<?= $e($ctx->url('/stock/docs/new?type=adjust')) ?>">+ Inventúra</a>
    <a class="button primary" href="<?= $e($ctx->url('/stock/items/new')) ?>">+ Karta</a>
  <?php endif ?>
</nav>
