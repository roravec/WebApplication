<?php use Panel\Tasks\Tasks; use WebApplication\Csrf;
/** @var array $project, $columns, $byColumn (column id => tasks), $f, $members; bool $canEdit, $canManage — kanban */
$today = date('Y-m-d');
?>
<?= $view->partial('tasks/project-tabs', ['project' => $project, 'tab' => 'board', 'canEdit' => $canEdit, 'canManage' => $canManage]) ?>
<form class="filters" method="get" action="<?= $e($ctx->url('/projects/' . $project['id'])) ?>">
  <input name="q" value="<?= $e($f['q']) ?>" placeholder="Číslo alebo názov">
  <select name="assignee"><option value="">Všetci riešitelia</option>
    <?php foreach ($members as $m): ?><option value="<?= (int)$m['client_id'] ?>"<?= $f['assignee'] === $m['client_id'] ? ' selected' : '' ?>><?= $e($m['name']) ?></option><?php endforeach ?>
  </select>
  <input name="label" value="<?= $e($f['label']) ?>" placeholder="Štítok">
  <button>Filtrovať</button>
  <?php if ($f['q'] !== '' || $f['assignee'] || $f['label'] !== ''): ?><a href="<?= $e($ctx->url('/projects/' . $project['id'])) ?>">Zrušiť filter</a><?php endif ?>
</form>
<?php if ($canEdit): ?><p class="field-hint">Úlohu presuniete chytením myšou do iného stĺpca (na mobile zmeníte stav v detaile úlohy). Hotové úlohy staršie ako 14 dní sú v záložke Zoznam.</p><?php endif ?>
<div class="board" data-move-url="<?= $e($ctx->url('/tasks/')) ?>" data-csrf="<?= $e(Csrf::token()) ?>"<?= $canEdit ? ' data-editable="1"' : '' ?>>
  <?php foreach ($columns as $col): $list = $byColumn[(int)$col['id']] ?? []; ?>
    <section class="board-col<?= (int)$col['done'] ? ' done' : '' ?>" data-column="<?= (int)$col['id'] ?>">
      <h2><?= $e($col['name']) ?> <span class="muted">(<?= count($list) ?>)</span></h2>
      <div class="board-cards">
      <?php foreach ($list as $t): $over = $t['due_date'] && $t['due_date'] < $today && !$t['closed_at']; ?>
        <a class="board-card prio-<?= (int)$t['priority'] ?>" href="<?= $e($ctx->url('/tasks/' . $t['id'])) ?>" data-task="<?= (int)$t['id'] ?>"<?= $canEdit ? ' draggable="true"' : '' ?>>
          <small class="muted"><?= $e($t['code']) ?></small> <?= $e($t['title']) ?>
          <span class="board-meta">
            <?php foreach (array_filter(explode(',', $t['labels'])) as $l): ?><span class="badge"><?= $e($l) ?></span> <?php endforeach ?>
            <?php if ($t['due_date']): ?><span class="<?= $over ? 'k-over' : 'muted' ?>">⏰ <?= $e(date('j. n.', strtotime($t['due_date']))) ?></span><?php endif ?>
            <?php if ($t['assignee_name']): ?><span class="muted">👤 <?= $e($t['assignee_name']) ?></span><?php endif ?>
            <?php if ((int)$t['priority'] >= 3): ?><span class="prio-<?= (int)$t['priority'] ?>"><?= $e(Tasks::PRIORITIES[(int)$t['priority']]) ?></span><?php endif ?>
          </span>
        </a>
      <?php endforeach ?>
      </div>
    </section>
  <?php endforeach ?>
</div>
