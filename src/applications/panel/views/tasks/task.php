<?php use Panel\Tasks\Tasks; use Panel\Tasks\TimeEntries; use WebApplication\Csrf;
/**
 * @var array $t, $columns, $checklist, $subtasks, $watcherNames, $entries, $commits; array|null $project, $parent, $timer;
 * bool $watching, $canEdit, $canTrack; int $spent
 */
$today = date('Y-m-d');
$done = count(array_filter($checklist, function ($c) { return (int)$c['done']; }));
?>
<p><a href="<?= $e($ctx->url($project ? '/projects/' . $project['id'] : '/tasks/my')) ?>">← <?= $project ? $e($project['key'] . ' ' . $project['name']) : 'Moje úlohy' ?></a>
  <?php if ($parent): ?> · nadradená: <a href="<?= $e($ctx->url('/tasks/' . $parent['id'])) ?>"><?= $e($parent['code'] . ' ' . $parent['title']) ?></a><?php endif ?></p>
<div class="toolbar">
  <?php if ($canEdit): ?><a class="button" href="<?= $e($ctx->url('/tasks/' . $t['id'] . '/edit')) ?>">Upraviť</a><?php endif ?>
  <?php if ($canEdit && $project): ?>
    <form method="post" action="<?= $e($ctx->url('/tasks/' . $t['id'] . '/move')) ?>" class="inline"><?= Csrf::field() ?>
      <select name="column_id" onchange="this.form.submit()" aria-label="Stav úlohy">
        <?php foreach ($columns as $c): ?><option value="<?= (int)$c['id'] ?>"<?= (int)$c['id'] === (int)$t['column_id'] ? ' selected' : '' ?>><?= $e($c['name']) ?></option><?php endforeach ?>
      </select><noscript><button>Zmeniť stav</button></noscript></form>
  <?php elseif ($canEdit): ?>
    <form method="post" action="<?= $e($ctx->url('/tasks/' . $t['id'] . '/done')) ?>" class="inline"><?= Csrf::field() ?>
      <button class="<?= $t['closed_at'] ? '' : 'primary' ?>"><?= $t['closed_at'] ? 'Otvoriť znova' : '✓ Hotovo' ?></button></form>
  <?php endif ?>
  <form method="post" action="<?= $e($ctx->url('/tasks/' . $t['id'] . '/watch')) ?>" class="inline"><?= Csrf::field() ?>
    <?php if (!$watching): ?><input type="hidden" name="watch" value="1"><?php endif ?><button><?= $watching ? 'Nesledovať' : '👁 Sledovať' ?></button></form>
  <?php if ($canTrack): ?>
    <form method="post" action="<?= $e($ctx->url('/time/timer')) ?>" class="inline"><?= Csrf::field() ?><input type="hidden" name="back" value="/tasks/<?= (int)$t['id'] ?>">
      <?php if ($timer && (int)$timer['task_id'] === (int)$t['id']): ?>
        <input type="hidden" name="stop" value="1"><button class="primary">⏱ Zastaviť (od <?= $e(date('H:i', strtotime($timer['started_at']))) ?>)</button>
      <?php else: ?><input type="hidden" name="task_id" value="<?= (int)$t['id'] ?>"><button>⏱ Spustiť stopky</button><?php endif ?></form>
  <?php endif ?>
  <?php if ($canEdit && $project): ?><a class="push" href="<?= $e($ctx->url('/tasks/new?project=' . $project['id'] . '&parent=' . $t['id'])) ?>">+ Podúloha</a><?php endif ?>
</div>

<div class="cards">
  <section class="card">
    <dl class="facts">
      <dt>Stav</dt><dd><?= $e($project ? (string)$t['column_name'] : ($t['closed_at'] ? 'hotová' : 'otvorená')) ?><?= $t['closed_at'] ? ' · dokončená ' . $e($f->dateTime($t['closed_at'])) : '' ?></dd>
      <dt>Riešiteľ</dt><dd><?= $e((string)($t['assignee_name'] ?? '—')) ?></dd>
      <dt>Termín</dt><dd class="<?= $t['due_date'] && $t['due_date'] < $today && !$t['closed_at'] ? 'k-over' : '' ?>"><?= $t['due_date'] ? $e($f->date($t['due_date'])) : '—' ?><?= $t['start_date'] ? ' (začiatok ' . $e($f->date($t['start_date'])) . ')' : '' ?></dd>
      <dt>Priorita</dt><dd class="prio-<?= (int)$t['priority'] ?>"><?= $e(Tasks::PRIORITIES[(int)$t['priority']]) ?></dd>
      <?php if ($t['labels'] !== ''): ?><dt>Štítky</dt><dd><?php foreach (explode(',', $t['labels']) as $l): ?><span class="badge"><?= $e($l) ?></span> <?php endforeach ?></dd><?php endif ?>
      <dt>Čas</dt><dd><?= TimeEntries::format($spent) ?> h<?= $t['estimate_minutes'] ? ' z odhadu ' . TimeEntries::format((int)$t['estimate_minutes']) . ' h' : '' ?></dd>
      <dt>Sledujú</dt><dd><?= $e(implode(', ', $watcherNames) ?: '—') ?></dd>
      <dt>Založil</dt><dd><?= $e((string)$t['author_name']) ?>, <?= $e($f->dateTime($t['created_at'])) ?></dd>
    </dl>
  </section>
  <section class="card">
    <h2>Popis</h2>
    <?= trim((string)$t['description']) !== '' ? '<p>' . nl2br($e($t['description'])) . '</p>' : '<p class="muted">Bez popisu.</p>' ?>
  </section>
</div>

<section class="card" id="checklist">
  <h2>Kontrolný zoznam <?= $checklist ? '(' . $done . ' / ' . count($checklist) . ')' : '' ?></h2>
  <?php foreach ($checklist as $c): ?>
    <form method="post" action="<?= $e($ctx->url('/tasks/' . $t['id'] . '/check/' . $c['id'])) ?>" class="check-row"><?= Csrf::field() ?>
      <button class="link" title="Zaškrtnúť / odškrtnúť"<?= $canEdit ? '' : ' disabled' ?>><?= (int)$c['done'] ? '☑' : '☐' ?></button>
      <span class="<?= (int)$c['done'] ? 'muted' : '' ?>"><?= (int)$c['done'] ? '<s>' . $e($c['text']) . '</s>' : $e($c['text']) ?></span>
      <?php if ($canEdit): ?><button class="link link-danger" name="delete" value="1" title="Zmazať">✕</button><?php endif ?></form>
  <?php endforeach ?>
  <?php if ($canEdit): ?>
    <form method="post" action="<?= $e($ctx->url('/tasks/' . $t['id'] . '/check')) ?>" class="inline link-form"><?= Csrf::field() ?>
      <input name="text" maxlength="255" placeholder="napr. otestovať na prípravku" required> <button>Pridať</button></form>
  <?php elseif (!$checklist): ?><p class="muted">Prázdny.</p><?php endif ?>
</section>

<?php if ($subtasks): ?>
<section class="card"><h2>Podúlohy</h2><?= $view->partial('tasks/table', ['tasks' => $subtasks]) ?></section>
<?php endif ?>

<section class="card" id="time">
  <h2>Odpracovaný čas (<?= TimeEntries::format($spent) ?> h)</h2>
  <?php if ($entries): ?>
  <table class="grid compact"><tbody>
  <?php foreach ($entries as $x): ?><tr><td><?= $e($f->date($x['work_date'])) ?></td><td><?= $e($x['person']) ?></td><td class="num"><?= TimeEntries::format((int)$x['minutes']) ?></td>
    <td class="muted small"><?= $e($x['note']) ?><?= $x['source'] === 'timer' ? ' ⏱' : '' ?><?= (int)$x['billable'] ? ' · fakturovateľné' : '' ?></td></tr><?php endforeach ?>
  </tbody></table>
  <?php endif ?>
  <?php if ($canTrack): ?>
    <form method="post" action="<?= $e($ctx->url('/time')) ?>" class="inline link-form"><?= Csrf::field() ?>
      <input type="hidden" name="task" value="<?= $e($t['code']) ?>"><input type="hidden" name="back" value="/tasks/<?= (int)$t['id'] ?>">
      <input type="date" name="date" value="<?= $e($today) ?>" aria-label="Dátum"><input name="hours" placeholder="hodiny, napr. 1,5" size="10" required aria-label="Hodiny">
      <input name="note" placeholder="čo ste robili (nepovinné)" maxlength="500"><label class="check"><input type="checkbox" name="billable" value="1"> fakturovateľné</label>
      <button>Zapísať čas</button></form>
  <?php endif ?>
</section>

<section class="card" id="commits">
  <h2>Commity (<?= count($commits) ?>)</h2>
  <?php if (!$commits): ?>
    <p class="muted">Zatiaľ žiadne. Commit sa sem priradí sám, keď jeho správa začína číslom úlohy, napr.
      <code><?= $e(str_replace('{TASK}', $t['code'], (new Panel\Tasks\Git($ctx->db()))->format())) ?> oprava …</code></p>
  <?php else: ?>
  <table class="grid compact"><tbody>
  <?php foreach ($commits as $c): ?>
    <tr><td><?= $c['url'] !== '' ? '<a href="' . $e($c['url']) . '" target="_blank" rel="noopener"><code>' . $e(substr($c['sha'], 0, 8)) . '</code></a>' : '<code>' . $e(substr($c['sha'], 0, 8)) . '</code>' ?></td>
      <td><?= $e(strtok((string)$c['message'], "\n")) ?></td>
      <td class="muted small"><?= $e($c['employee'] ?: $c['author_name']) ?> · <?= $e($c['repo_name']) ?><?= $c['branch'] !== '' ? ' (' . $e($c['branch']) . ')' : '' ?> · <?= $e($f->dateTime($c['committed_at'])) ?></td></tr>
  <?php endforeach ?>
  </tbody></table>
  <?php endif ?>
</section>

<?= $view->partial('core/record', ['ref' => 'task:' . $t['id']]) ?>
