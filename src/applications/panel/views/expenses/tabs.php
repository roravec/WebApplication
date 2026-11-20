<?php /** @var string $tab — Náklady (docs/sprava-firmy-specifikacia.md, module C) */ use Panel\Rights; ?>
<nav class="tabs">
  <a href="<?= $e($ctx->url('/expenses')) ?>" class="<?= $tab === 'list' ? 'active' : '' ?>">Prijaté doklady</a>
  <a href="<?= $e($ctx->url('/expenses?status=open')) ?>" class="<?= $tab === 'open' ? 'active' : '' ?>">Neuhradené</a>
  <a href="<?= $e($ctx->url('/expenses/recurring')) ?>" class="<?= $tab === 'recurring' ? 'active' : '' ?>">Opakované</a>
  <a href="<?= $e($ctx->url('/expenses/categories')) ?>" class="<?= $tab === 'categories' ? 'active' : '' ?>">Kategórie</a>
  <?php if ($ctx->can(Rights::EXPENSES_EDIT)): ?>
    <a class="button push" href="<?= $e($ctx->url('/payments/import')) ?>" title="Úhrady nákladov z výpisu banky (Fio / CAMT.053)">Import výpisu</a>
    <a class="button primary" href="<?= $e($ctx->url('/expenses/new')) ?>">+ Nový doklad</a>
  <?php endif ?>
</nav>
