<?php use Panel\Tasks\Tasks; use Panel\Tasks\TimeEntries; use WebApplication\Csrf;
/** @var array|null $t, $project; array $values, $errors, $assignees (id => name), $columns, $editable (projects the person may add to) */
$err = function ($k) use ($errors, $e) { return isset($errors[$k]) ? '<span class="field-err">' . $e($errors[$k]) . '</span>' : ''; };
$v = function ($k) use ($values) { return (string)($values[$k] ?? ''); };
$est = $values['estimate_minutes'] ?? '';
?>
<p><a href="<?= $e($ctx->url($t ? '/tasks/' . $t['id'] : ($project ? '/projects/' . $project['id'] : '/tasks/my'))) ?>">← späť</a></p>
<form class="card form" method="post" action="<?= $e($ctx->url($t ? '/tasks/' . $t['id'] : '/tasks')) ?>">
  <?= Csrf::field() ?>
  <?php if (!$t): ?>
    <label>Projekt <select name="project_id" onchange="location.href='<?= $e($ctx->url('/tasks/new')) ?>?project=' + this.value">
      <option value="0">— osobná úloha (bez projektu, vidíte ju len vy) —</option>
      <?php foreach ($editable as $p): ?><option value="<?= (int)$p['id'] ?>"<?= $project && (int)$project['id'] === (int)$p['id'] ? ' selected' : '' ?>><?= $e($p['key'] . ' ' . $p['name']) ?></option><?php endforeach ?>
    </select></label>
  <?php endif ?>
  <label>Názov — čo treba urobiť <input name="title" value="<?= $e($v('title')) ?>" required maxlength="255" autofocus><?= $err('title') ?></label>
  <label>Popis <textarea name="description" rows="6" placeholder="Podrobnosti, postup, odkazy. Kód záznamu (napr. order:0:2481) sa v komentároch stane odkazom."><?= $e($v('description')) ?></textarea></label>
  <div class="form-grid">
    <label>Riešiteľ <select name="assignee_id"><option value="">— nikto —</option>
      <?php foreach ($assignees as $id => $name): ?><option value="<?= (int)$id ?>"<?= (string)$id === $v('assignee_id') ? ' selected' : '' ?>><?= $e($name) ?></option><?php endforeach ?>
    </select><?= $err('assignee_id') ?></label>
    <label>Priorita <select name="priority">
      <?php foreach (Tasks::PRIORITIES as $k => $l): ?><option value="<?= $k ?>"<?= (int)$v('priority') === $k ? ' selected' : '' ?>><?= $e($l) ?></option><?php endforeach ?>
    </select></label>
    <label>Začiatok <input type="date" name="start_date" value="<?= $e($v('start_date')) ?>"></label>
    <label>Termín <input type="date" name="due_date" value="<?= $e($v('due_date')) ?>"><?= $err('due_date') ?></label>
    <label>Odhad (hodiny) <input name="estimate" value="<?= $e($est === '' || $est === null || $est === -1 ? '' : TimeEntries::format((int)$est)) ?>" placeholder="napr. 4 alebo 1:30"><?= $err('estimate') ?></label>
    <label>Štítky (oddelené čiarkou) <input name="labels" value="<?= $e($v('labels')) ?>" placeholder="napr. firmvér, chyba"></label>
    <?php if ($project && $columns): ?>
      <label>Stav <select name="column_id">
        <?php foreach ($columns as $c): ?><option value="<?= (int)$c['id'] ?>"<?= (string)$c['id'] === $v('column_id') ? ' selected' : '' ?>><?= $e($c['name']) ?></option><?php endforeach ?>
      </select></label>
    <?php endif ?>
  </div>
  <input type="hidden" name="parent_id" value="<?= $e($v('parent_id')) ?>"><?= $err('parent_id') ?>
  <?php if ($v('parent_id') !== ''): ?><p class="field-hint">Ukladá sa ako podúloha úlohy #<?= $e($v('parent_id')) ?>.</p><?php endif ?>
  <button class="primary"><?= $t ? 'Uložiť' : 'Založiť úlohu' ?></button>
</form>
