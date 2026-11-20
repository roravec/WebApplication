<?php use Panel\Rights; use WebApplication\Csrf;
/** @var WebApplication\Entity\Client|null $client; array $values, $errors, $companies; int $minPassword */
$err = function ($k) use ($errors, $e) { return isset($errors[$k]) ? '<span class="field-err">' . $e($errors[$k]) . '</span>' : ''; };
$action = $client ? '/employees/' . $client->getId() : '/employees';
?>
<p><a href="<?= $e($ctx->url('/employees')) ?>">← Zamestnanci</a></p>
<form class="card form" method="post" action="<?= $e($ctx->url($action)) ?>">
  <?= Csrf::field() ?>
  <label>Meno a priezvisko <input name="name" value="<?= $e($values['name']) ?>" required><?= $err('name') ?></label>
  <label>E-mail (prihlasovacie meno) <input name="identifier" type="email" value="<?= $e($values['identifier']) ?>" required autocomplete="off"><?= $err('identifier') ?></label>
  <fieldset>
    <legend>Oprávnenia</legend>
    <label class="check"><input type="checkbox" name="admin" value="1" data-toggles="#perms"<?= $values['admin'] ? ' checked' : '' ?>>
      <span><b>Správca</b> — všetko vrátane zamestnancov, všetkých firiem a zakladania firiem</span></label><?= $err('admin') ?>
    <div id="perms" class="perms"<?= $values['admin'] ? ' hidden' : '' ?>>
      <?php foreach (Rights::permissionLabels() as $perm => $label): ?>
        <label class="check"><input type="checkbox" name="permissions[]" value="<?= $e($perm) ?>"<?= in_array($perm, $values['permissions'], true) ? ' checked' : '' ?>> <?= $e($label) ?></label>
      <?php endforeach ?>
      <p class="muted small">Prístup k firmám (faktúry, odberatelia a nastavenia len týchto firiem):</p>
      <?php if (!$companies): ?><p class="muted small">Zatiaľ nie je založená žiadna firma.</p><?php endif ?>
      <?php foreach ($companies as $c): ?>
        <label class="check"><input type="checkbox" name="companies[]" value="<?= (int)$c['id'] ?>"<?= in_array((int)$c['id'], $values['companies'], true) ? ' checked' : '' ?>> <?= $e($c['name']) ?></label>
      <?php endforeach ?>
    </div>
  </fieldset>
  <label class="check"><input type="checkbox" name="status" value="1"<?= $values['status'] ? ' checked' : '' ?>> Aktívny (môže sa prihlásiť)</label>
  <label><?= $client ? 'Nové heslo (nechajte prázdne, ak sa nemení)' : 'Heslo' ?>
    <input name="password" type="password" autocomplete="new-password" minlength="<?= (int)$minPassword ?>"><?= $err('password') ?></label>
  <button class="primary">Uložiť</button>
</form>
