<?php use Panel\Tasks\TimeEntries; use WebApplication\Csrf;
/** @var array $days (date => entries), $people (id => name, only with time.all), $projects; string $monday, $week; int $person; bool $mine; array|null $timer */
$prev = date('o-\WW', strtotime($monday . ' -7 days'));
$next = date('o-\WW', strtotime($monday . ' +7 days'));
$names = ['Po', 'Ut', 'St', 'Št', 'Pi', 'So', 'Ne'];
$total = 0;
foreach ($days as $list) { foreach ($list as $x) { $total += (int)$x['minutes']; } }
?>
<div class="toolbar">
  <a class="button" href="<?= $e($ctx->url('/time?week=' . $prev . ($people ? '&person=' . $person : ''))) ?>">←</a>
  <b>Týždeň <?= $e(date('j. n.', strtotime($monday))) ?> – <?= $e(date('j. n. Y', strtotime($monday . ' +6 days'))) ?></b>
  <a class="button" href="<?= $e($ctx->url('/time?week=' . $next . ($people ? '&person=' . $person : ''))) ?>">→</a>
  <a href="<?= $e($ctx->url('/time')) ?>">tento týždeň</a>
  <?php if ($people): ?>
    <form method="get" action="<?= $e($ctx->url('/time')) ?>" class="inline push"><input type="hidden" name="week" value="<?= $e($week) ?>">
      <select name="person" onchange="this.form.submit()" aria-label="Koho výkaz"><?php foreach ($people as $id => $n): ?><option value="<?= (int)$id ?>"<?= (int)$id === $person ? ' selected' : '' ?>><?= $e($n) ?></option><?php endforeach ?></select></form>
  <?php endif ?>
</div>
<?php if ($timer): ?>
  <form class="flash ok" method="post" action="<?= $e($ctx->url('/time/timer')) ?>"><?= Csrf::field() ?>
    ⏱ Stopky bežia od <?= $e(date('H:i', strtotime($timer['started_at']))) ?>
    <input type="hidden" name="stop" value="1"> <input name="note" placeholder="čo ste robili (nepovinné)"> <button>Zastaviť a zapísať</button></form>
<?php endif ?>
<table class="grid">
  <thead><tr><th>Deň</th><th>Úloha / projekt</th><th>Poznámka</th><th class="num">Hodiny</th><th></th></tr></thead>
  <tbody>
  <?php $i = 0; foreach ($days as $date => $list): $sum = array_sum(array_column($list, 'minutes')); ?>
    <tr class="day-row"><th colspan="3"><?= $names[$i++] ?> <?= $e(date('j. n.', strtotime($date))) ?></th><th class="num"><?= $sum ? TimeEntries::format((int)$sum) : '' ?></th><th></th></tr>
    <?php foreach ($list as $x): ?>
      <tr><td></td>
        <td><?php if ($x['task_id']): ?><a href="<?= $e($ctx->url('/tasks/' . $x['task_id'])) ?>"><?= $e($x['task_code'] . ' ' . $x['task_title']) ?></a>
          <?php elseif ($x['project_id']): ?><?= $e($x['project_key'] . ' ' . $x['project_name']) ?><?php else: ?><span class="muted">všeobecná práca</span><?php endif ?></td>
        <td class="muted small"><?= $e($x['note']) ?><?= $x['source'] === 'timer' ? ' ⏱' : '' ?><?= (int)$x['billable'] ? ' · fakturovateľné' : '' ?></td>
        <td class="num"><?= TimeEntries::format((int)$x['minutes']) ?></td>
        <td><?php if ($mine): ?><form method="post" action="<?= $e($ctx->url('/time/' . (int)$x['id'] . '/delete')) ?>" onsubmit="return confirm('Zmazať záznam?')"><?= Csrf::field() ?><button class="link link-danger" title="Zmazať">✕</button></form><?php endif ?></td></tr>
    <?php endforeach ?>
  <?php endforeach ?>
  <tr><th colspan="3">Spolu za týždeň</th><th class="num"><?= TimeEntries::format($total) ?></th><th></th></tr>
  </tbody>
</table>
<?php if ($mine): ?>
<form class="card form" method="post" action="<?= $e($ctx->url('/time')) ?>">
  <h2>Zapísať čas</h2>
  <?= Csrf::field() ?><input type="hidden" name="week" value="<?= $e($week) ?>">
  <div class="form-grid">
    <label>Dátum <input type="date" name="date" value="<?= $e(min(date('Y-m-d'), date('Y-m-d', strtotime($monday . ' +6 days')))) ?>" required></label>
    <label>Hodiny <input name="hours" placeholder="napr. 2, 1,5 alebo 1:30" required></label>
    <label>Úloha (číslo) <input name="task" placeholder="napr. LED-12 (nepovinné)"></label>
    <label>alebo projekt <select name="project_id"><option value="">— žiadny —</option>
      <?php foreach ($projects as $p): ?><option value="<?= (int)$p['id'] ?>"><?= $e($p['key'] . ' ' . $p['name']) ?></option><?php endforeach ?></select></label>
  </div>
  <label>Poznámka <input name="note" maxlength="500" placeholder="čo ste robili"></label>
  <label class="check"><input type="checkbox" name="billable" value="1"> fakturovateľné (zákazníkovi)</label>
  <button class="primary">Zapísať</button>
</form>
<?php endif ?>
