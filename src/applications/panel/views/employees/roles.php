<?php /** @var array $roles list of id, name, description, permissions, holders */ use Panel\Core\Permissions; ?>
<?= $view->partial('employees/tabs', ['tab' => 'roles']) ?>
<p class="field-hint">Rola = sada práv pre jednu prácu (napr. Fakturácia, Expedícia). Zamestnancovi zaškrtnete jednu alebo viac rolí;
  zmena roly platí hneď pre všetkých, ktorí ju majú. <b>Správca</b> nie je rola v tomto zozname — zaškrtáva sa priamo pri zamestnancovi.</p>
<?php if (!$roles): ?>
  <p class="muted">Zatiaľ nie je žiadna rola. Vytvorte ju tlačidlom <b>+ Nová rola</b>.</p>
<?php else: ?>
<table class="grid">
  <thead><tr><th>Rola</th><th>Čo dáva</th><th class="num">Ľudia</th></tr></thead>
  <tbody>
  <?php foreach ($roles as $r): ?>
    <tr>
      <td><a href="<?= $e($ctx->url('/employees/roles/' . $r['id'])) ?>"><?= $e($r['name']) ?></a>
        <?php if ($r['description'] !== ''): ?><br><small class="muted"><?= $e($r['description']) ?></small><?php endif ?></td>
      <td><small><?= $e(implode(' · ', array_map([Permissions::class, 'label'], $r['permissions'])) ?: '— nič —') ?></small></td>
      <td class="num"><?= (int)$r['holders'] ?></td>
    </tr>
  <?php endforeach ?>
  </tbody>
</table>
<?php endif ?>
