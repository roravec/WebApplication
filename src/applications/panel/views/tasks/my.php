<?php use WebApplication\Csrf; /** @var array $groups, $watched; array|null $timer; bool $canTrack — Moje úlohy */
$titles = ['over' => 'Po termíne', 'today' => 'Dnes', 'week' => 'Do 7 dní', 'later' => 'Neskôr', 'none' => 'Bez termínu'];
$count = array_sum(array_map('count', $groups));
?>
<div class="toolbar">
  <a href="<?= $e($ctx->url('/projects')) ?>">Projekty</a> · <a href="<?= $e($ctx->url('/tasks')) ?>">Všetky úlohy</a> · <a href="<?= $e($ctx->url('/calendar')) ?>">Kalendár</a>
  <?php if ($ctx->can(\Panel\Rights::TASKS_EDIT)): ?><a class="button primary push" href="<?= $e($ctx->url('/tasks/new')) ?>">+ Osobná úloha</a><?php endif ?>
</div>
<?php if ($timer): ?>
  <form class="flash ok" method="post" action="<?= $e($ctx->url('/time/timer')) ?>"><?= Csrf::field() ?>
    ⏱ Stopky bežia od <?= $e(date('H:i', strtotime($timer['started_at']))) ?><?= $timer['task_id'] ? ' — <a href="' . $e($ctx->url('/tasks/' . (int)$timer['task_id'])) . '">úloha</a>' : '' ?>
    <input type="hidden" name="stop" value="1"><input type="hidden" name="back" value="/tasks/my"> <button>Zastaviť a zapísať</button></form>
<?php endif ?>
<?php if (!$count): ?><p class="muted">Nemáte pridelenú žiadnu otvorenú úlohu. 🎉</p><?php endif ?>
<?php foreach ($groups as $k => $list): if (!$list) { continue; } ?>
  <h2 class="<?= $k === 'over' ? 'k-over' : '' ?>"><?= $e($titles[$k]) ?> (<?= count($list) ?>)</h2>
  <?= $view->partial('tasks/table', ['tasks' => $list, 'showProject' => true]) ?>
<?php endforeach ?>
<?php if ($watched): ?>
  <h2>Sledujem (riešia iní)</h2>
  <?= $view->partial('tasks/table', ['tasks' => $watched, 'showProject' => true]) ?>
<?php endif ?>
