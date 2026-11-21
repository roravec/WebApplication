<?php /** @var array $projects; bool $archived, $canCreate */ ?>
<div class="toolbar">
  <?php if ($archived): ?><a href="<?= $e($ctx->url('/projects')) ?>">← Aktívne projekty</a><?php else: ?><a href="<?= $e($ctx->url('/projects?archived=1')) ?>">Archivované projekty</a><?php endif ?>
  <a href="<?= $e($ctx->url('/tasks')) ?>">Všetky úlohy</a>
  <?php if ($canCreate && !$archived): ?><a class="button primary push" href="<?= $e($ctx->url('/projects/new')) ?>">+ Nový projekt</a><?php endif ?>
</div>
<?php if (!$projects): ?>
  <p class="muted"><?= $archived ? 'Žiadne archivované projekty.' : 'Zatiaľ nie ste v žiadnom projekte.' . ($canCreate ? ' Založte ho tlačidlom + Nový projekt.' : ' Do projektu vás pridá jeho vlastník.') ?></p>
<?php else: ?>
<table class="grid">
  <thead><tr><th>Kľúč</th><th>Projekt</th><th>Firma</th><th class="num">Otvorené úlohy</th><th class="num">Spolu</th></tr></thead>
  <tbody>
  <?php foreach ($projects as $p): ?>
    <tr><td><a href="<?= $e($ctx->url('/projects/' . $p['id'])) ?>"><b><?= $e($p['key']) ?></b></a></td>
      <td><a href="<?= $e($ctx->url('/projects/' . $p['id'])) ?>"><?= $e($p['name']) ?></a></td>
      <td><?= $e((string)$p['company_name']) ?></td><td class="num"><?= (int)$p['open_tasks'] ?></td><td class="num"><?= (int)$p['all_tasks'] ?></td></tr>
  <?php endforeach ?>
  </tbody>
</table>
<?php endif ?>
