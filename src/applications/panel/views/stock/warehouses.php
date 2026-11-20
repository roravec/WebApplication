<?php /** @var array $warehouses; bool $canEdit */ ?>
<?= $view->partial('stock/tabs', ['tab' => 'warehouses']) ?>
<?php if ($canEdit): ?><p><a class="button primary" href="<?= $e($ctx->url('/stock/warehouses/new')) ?>">+ Nový sklad</a></p><?php endif ?>
<table class="grid">
  <thead><tr><th>Kód</th><th>Názov</th><th>Firma</th><th>Umiestnenie</th><th>Stav</th></tr></thead>
  <tbody>
  <?php foreach ($warehouses as $w): ?>
    <tr class="<?= $w['active'] ? '' : 'inactive' ?>">
      <td><?= $canEdit ? '<a href="' . $e($ctx->url('/stock/warehouses/' . $w['id'])) . '">' . $e($w['code']) . '</a>' : $e($w['code']) ?></td>
      <td><a href="<?= $e($ctx->url('/stock?warehouse=' . $w['id'])) ?>"><?= $e($w['name']) ?></a></td>
      <td><?= $e($w['company']) ?></td>
      <td><?= $e($w['location']) ?></td>
      <td><?= $w['active'] ? 'aktívny' : 'neaktívny' ?></td>
    </tr>
  <?php endforeach ?>
  <?php if (!$warehouses): ?><tr><td colspan="5" class="muted">Zatiaľ žiadny sklad.</td></tr><?php endif ?>
  </tbody>
</table>
