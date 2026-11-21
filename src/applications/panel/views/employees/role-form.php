<?php use WebApplication\Csrf;
/**
 * @var array|null $role; array $values (name, description, permissions), $errors, $groups, $editorPerms, $readOnly (permissions
 * of the role the editor lacks — read only), $holders (Client[]); bool $editorAdmin
 */
$err = function ($k) use ($errors, $e) { return isset($errors[$k]) ? '<span class="field-err">' . $e($errors[$k]) . '</span>' : ''; };
?>
<p><a href="<?= $e($ctx->url('/employees/roles')) ?>">← Roly</a></p>
<?php if ($readOnly): ?>
  <div class="card"><p><b>Túto rolu nemôžete upravovať</b> — dáva práva, ktoré sami nemáte. Zmeniť ju môže správca.</p></div>
<?php endif ?>
<form class="card form" method="post" action="<?= $e($ctx->url($role ? '/employees/roles/' . $role['id'] : '/employees/roles')) ?>">
  <?= Csrf::field() ?>
  <fieldset class="plain"<?= $readOnly ? ' disabled' : '' ?>>
  <label>Názov roly <input name="name" value="<?= $e($values['name']) ?>" required maxlength="100" placeholder="napr. Expedícia"><?= $err('name') ?></label>
  <label>Popis (pre koho je, čo robí) <input name="description" value="<?= $e($values['description']) ?>" maxlength="255"></label>
  <fieldset>
    <legend>Práva roly</legend>
    <?= $err('permissions') ?>
    <p class="field-hint"><span class="badge">citlivé</span> = peniaze, nastavenia alebo nevratné kroky — dávajte ich len tým, ktorí ich naozaj potrebujú.</p>
    <?php foreach ($groups as $group => $perms): ?>
      <p class="muted small"><b><?= $e($group) ?></b></p>
      <?php foreach ($perms as $key => $p): $mine = $editorAdmin || in_array($key, $editorPerms, true); ?>
        <label class="check"><input type="checkbox" name="permissions[]" value="<?= $e($key) ?>"<?= in_array($key, $values['permissions'], true) ? ' checked' : '' ?>>
          <span><?= $e($p['label']) ?><?= $p['sensitive'] ? ' <span class="badge">citlivé</span>' : '' ?><?= $mine ? '' : ' <small class="muted">(nemáte ho — nemôžete ho dať)</small>' ?></span></label>
      <?php endforeach ?>
    <?php endforeach ?>
  </fieldset>
  <?php if (!$readOnly): ?><button class="primary">Uložiť</button><?php endif ?>
  </fieldset>
</form>

<?php if ($role): ?>
  <h2>Kto má túto rolu (<?= count($holders) ?>)</h2>
  <?php if (!$holders): ?><p class="muted">Zatiaľ nikto — rolu priradíte pri zamestnancovi.</p><?php endif ?>
  <ul>
    <?php foreach ($holders as $h): ?><li><a href="<?= $e($ctx->url('/employees/' . $h->getId())) ?>"><?= $e($h->getName()) ?></a><?= $h->isActive() ? '' : ' <small class="muted">(neaktívny)</small>' ?></li><?php endforeach ?>
  </ul>
  <?php if (!$readOnly): ?>
  <form method="post" action="<?= $e($ctx->url('/employees/roles/' . $role['id'] . '/delete')) ?>" class="inline"
        onsubmit="return confirm(<?= $e(json_encode('Zmazať rolu „' . $role['name'] . '“?' . ($holders ? ' Odoberie sa ' . count($holders) . ' ľuďom a prídu o práva, ktoré im dávala.' : ''), JSON_UNESCAPED_UNICODE)) ?>)">
    <?= Csrf::field() ?><button class="danger">Zmazať rolu</button>
  </form>
  <?php endif ?>
  <?= $view->partial('core/record', ['ref' => 'role:' . $role['id']]) ?>
<?php endif ?>
