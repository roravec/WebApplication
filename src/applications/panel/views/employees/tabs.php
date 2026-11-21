<?php /** @var string $tab — Nastavenia → Zamestnanci a roly: people, roles, overview */ ?>
<nav class="tabs">
  <a href="<?= $e($ctx->url('/employees')) ?>" class="<?= $tab === 'people' ? 'active' : '' ?>">Zamestnanci</a>
  <a href="<?= $e($ctx->url('/employees/roles')) ?>" class="<?= $tab === 'roles' ? 'active' : '' ?>">Roly</a>
  <a href="<?= $e($ctx->url('/employees/overview')) ?>" class="<?= $tab === 'overview' ? 'active' : '' ?>">Kto čo smie</a>
  <?php if ($tab === 'people'): ?><a class="button primary push" href="<?= $e($ctx->url('/employees/new')) ?>">+ Nový zamestnanec</a><?php endif ?>
  <?php if ($tab === 'roles'): ?><a class="button primary push" href="<?= $e($ctx->url('/employees/roles/new')) ?>">+ Nová rola</a><?php endif ?>
</nav>
