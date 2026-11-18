<?php use WebApplication\Csrf; /** @var array $errors; int $minPassword */
$err = function ($k) use ($errors, $e) { return isset($errors[$k]) ? '<span class="field-err">' . $e($errors[$k]) . '</span>' : ''; };
?>
<form class="card form" method="post" action="<?= $e($ctx->url('/account')) ?>">
  <h2>Zmena hesla</h2>
  <?= Csrf::field() ?>
  <label>Súčasné heslo <input name="current" type="password" autocomplete="current-password" required><?= $err('current') ?></label>
  <label>Nové heslo (aspoň <?= (int)$minPassword ?> znakov)
    <input name="password" type="password" autocomplete="new-password" required minlength="<?= (int)$minPassword ?>"><?= $err('password') ?></label>
  <label>Nové heslo znova <input name="password2" type="password" autocomplete="new-password" required><?= $err('password2') ?></label>
  <button class="primary">Zmeniť heslo</button>
</form>
