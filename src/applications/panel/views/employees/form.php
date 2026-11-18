<?php use Panel\Rights; use WebApplication\Csrf;
/** @var WebApplication\Entity\Client|null $client; array $values, $errors; int $minPassword */
$err = function ($k) use ($errors, $e) { return isset($errors[$k]) ? '<span class="field-err">' . $e($errors[$k]) . '</span>' : ''; };
$action = $client ? '/employees/' . $client->getId() : '/employees';
?>
<p><a href="<?= $e($ctx->url('/employees')) ?>">← Zamestnanci</a></p>
<form class="card form" method="post" action="<?= $e($ctx->url($action)) ?>">
  <?= Csrf::field() ?>
  <label>Meno a priezvisko <input name="name" value="<?= $e($values['name']) ?>" required><?= $err('name') ?></label>
  <label>Prihlasovacie meno <input name="identifier" value="<?= $e($values['identifier']) ?>" required autocomplete="off"><?= $err('identifier') ?></label>
  <label>Oprávnenie
    <select name="rights">
      <?php foreach (Rights::labels() as $level => $label): ?>
        <option value="<?= (int)$level ?>"<?= (int)$values['rights'] === $level ? ' selected' : '' ?>><?= $e($label) ?></option>
      <?php endforeach ?>
    </select><?= $err('rights') ?>
  </label>
  <label class="check"><input type="checkbox" name="status" value="1"<?= $values['status'] ? ' checked' : '' ?>> Aktívny (môže sa prihlásiť)</label>
  <label><?= $client ? 'Nové heslo (nechajte prázdne, ak sa nemení)' : 'Heslo' ?>
    <input name="password" type="password" autocomplete="new-password" minlength="<?= (int)$minPassword ?>"><?= $err('password') ?></label>
  <button class="primary">Uložiť</button>
</form>
