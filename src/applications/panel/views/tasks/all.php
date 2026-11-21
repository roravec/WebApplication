<?php /** @var array $tasks, $f, $people, $projects — all tasks the person may see */ ?>
<form class="filters" method="get" action="<?= $e($ctx->url('/tasks')) ?>">
  <input name="q" value="<?= $e($f['q']) ?>" placeholder="Číslo (LED-12) alebo názov">
  <select name="project"><option value="">Všetky projekty</option><option value="0"<?= $f['project'] === 0 ? ' selected' : '' ?>>Osobné úlohy</option>
    <?php foreach ($projects as $p): ?><option value="<?= (int)$p['id'] ?>"<?= $f['project'] === (int)$p['id'] ? ' selected' : '' ?>><?= $e($p['key'] . ' ' . $p['name']) ?></option><?php endforeach ?>
  </select>
  <select name="assignee"><option value="">Všetci riešitelia</option>
    <?php foreach ($people as $id => $name): ?><option value="<?= (int)$id ?>"<?= $f['assignee'] === (int)$id ? ' selected' : '' ?>><?= $e($name) ?></option><?php endforeach ?>
  </select>
  <select name="status">
    <?php foreach (['open' => 'Otvorené', 'done' => 'Hotové', 'all' => 'Všetky'] as $k => $l): ?><option value="<?= $k ?>"<?= $f['status'] === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach ?>
  </select>
  <select name="due"><option value="">Akýkoľvek termín</option><option value="overdue"<?= $f['due'] === 'overdue' ? ' selected' : '' ?>>Po termíne</option>
    <option value="week"<?= $f['due'] === 'week' ? ' selected' : '' ?>>Do 7 dní</option></select>
  <input name="label" value="<?= $e($f['label']) ?>" placeholder="Štítok">
  <button class="primary">Filtrovať</button>
  <a href="<?= $e($ctx->url('/tasks')) ?>">Zrušiť filter</a>
  <?php if ($ctx->can(\Panel\Rights::TASKS_EDIT)): ?><a class="button push" href="<?= $e($ctx->url('/tasks/new')) ?>">+ Osobná úloha</a><?php endif ?>
</form>
<?= $view->partial('tasks/table', ['tasks' => $tasks, 'showProject' => true]) ?>
