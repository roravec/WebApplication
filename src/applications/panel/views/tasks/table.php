<?php use Panel\Tasks\Tasks; /** @var array $tasks; bool|null $showProject — task rows (list views) */
$today = date('Y-m-d');
?>
<?php if (!$tasks): ?><p class="muted">Žiadne úlohy.</p><?php else: ?>
<table class="grid">
  <thead><tr><th>Číslo</th><th>Úloha</th><?php if (!empty($showProject)): ?><th>Projekt</th><?php endif ?><th>Stav</th><th>Riešiteľ</th><th>Termín</th><th>Priorita</th></tr></thead>
  <tbody>
  <?php foreach ($tasks as $t): $over = $t['due_date'] && $t['due_date'] < $today && !$t['closed_at']; ?>
    <tr class="<?= $t['closed_at'] ? 'muted' : '' ?>">
      <td><a href="<?= $e($ctx->url('/tasks/' . $t['id'])) ?>"><?= $e($t['code']) ?></a></td>
      <td><a href="<?= $e($ctx->url('/tasks/' . $t['id'])) ?>"><?= $e($t['title']) ?></a>
        <?php foreach (array_filter(explode(',', $t['labels'])) as $l): ?> <span class="badge"><?= $e($l) ?></span><?php endforeach ?></td>
      <?php if (!empty($showProject)): ?><td><?= $e($t['project_name'] ?? 'osobná') ?></td><?php endif ?>
      <td><?= $e($t['project_id'] === null ? ($t['closed_at'] ? 'hotová' : 'otvorená') : (string)$t['column_name']) ?></td>
      <td><?= $e((string)$t['assignee_name']) ?></td>
      <td class="<?= $over ? 'k-over' : '' ?>"><?= $t['due_date'] ? $e($f->date($t['due_date'])) : '' ?></td>
      <td><span class="prio-<?= (int)$t['priority'] ?>"><?= $e(Tasks::PRIORITIES[(int)$t['priority']]) ?></span></td>
    </tr>
  <?php endforeach ?>
  </tbody>
</table>
<?php endif ?>
