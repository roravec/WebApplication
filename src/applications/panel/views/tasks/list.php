<?php use Panel\Tasks\TimeEntries;
/** @var array $project, $tasks, $f, $members, $hours; bool $canEdit, $canManage — project tasks as a table + hours */
?>
<?= $view->partial('tasks/project-tabs', ['project' => $project, 'tab' => 'list', 'canEdit' => $canEdit, 'canManage' => $canManage]) ?>
<form class="filters" method="get" action="<?= $e($ctx->url('/projects/' . $project['id'] . '/list')) ?>">
  <input name="q" value="<?= $e($f['q']) ?>" placeholder="Číslo alebo názov">
  <select name="status">
    <?php foreach (['open' => 'Otvorené', 'done' => 'Hotové', 'all' => 'Všetky'] as $k => $l): ?><option value="<?= $k ?>"<?= $f['status'] === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach ?>
  </select>
  <select name="assignee"><option value="">Všetci riešitelia</option>
    <?php foreach ($members as $m): ?><option value="<?= (int)$m['client_id'] ?>"<?= $f['assignee'] === $m['client_id'] ? ' selected' : '' ?>><?= $e($m['name']) ?></option><?php endforeach ?>
  </select>
  <input name="label" value="<?= $e($f['label']) ?>" placeholder="Štítok">
  <button>Filtrovať</button>
</form>
<?= $view->partial('tasks/table', ['tasks' => $tasks]) ?>
<?php if ($project['description']): ?><section class="card"><h2>O projekte</h2><p><?= nl2br($e($project['description'])) ?></p></section><?php endif ?>
<section class="card">
  <h2>Odpracované hodiny</h2>
  <?php if (!$hours): ?><p class="muted">Zatiaľ žiadne výkazy práce pri tomto projekte.</p><?php else: ?>
  <table class="grid compact"><thead><tr><th>Kto</th><th class="num">Hodiny</th><th class="num">z toho fakturovateľné</th></tr></thead><tbody>
  <?php foreach ($hours as $h): ?><tr><td><?= $e($h['name']) ?></td><td class="num"><?= TimeEntries::format((int)$h['minutes']) ?></td><td class="num"><?= TimeEntries::format((int)$h['billable']) ?></td></tr><?php endforeach ?>
  <tr><th>Spolu</th><th class="num"><?= TimeEntries::format((int)array_sum(array_column($hours, 'minutes'))) ?></th><th class="num"><?= TimeEntries::format((int)array_sum(array_column($hours, 'billable'))) ?></th></tr>
  </tbody></table>
  <?php endif ?>
  <p class="field-hint">Členovia projektu: <?= $e(implode(', ', array_map(function ($m) { return $m['name'] . ' (' . Panel\Tasks\Projects::ROLES[$m['role']] . ')'; }, $members))) ?></p>
</section>
<?= $view->partial('core/record', ['ref' => 'project:' . $project['id']]) ?>
