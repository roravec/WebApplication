<?php /** @var array $project; string $tab; bool $canEdit, $canManage */ ?>
<nav class="tabs">
  <a href="<?= $e($ctx->url('/projects/' . $project['id'])) ?>" class="<?= $tab === 'board' ? 'active' : '' ?>">Kanban</a>
  <a href="<?= $e($ctx->url('/projects/' . $project['id'] . '/list')) ?>" class="<?= $tab === 'list' ? 'active' : '' ?>">Zoznam a hodiny</a>
  <?php if ($canManage): ?><a href="<?= $e($ctx->url('/projects/' . $project['id'] . '/settings')) ?>">Nastavenie projektu</a><?php endif ?>
  <?php if ($canEdit): ?><a class="button primary push" href="<?= $e($ctx->url('/tasks/new?project=' . $project['id'])) ?>">+ Nová úloha</a><?php endif ?>
</nav>
<?php if ($project['status'] === 'archived'): ?><p class="flash err">Projekt je archivovaný — úlohy sa nedajú meniť. Znova ho otvorí vlastník v nastavení projektu.</p><?php endif ?>
