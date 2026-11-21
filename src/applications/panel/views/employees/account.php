<?php use Panel\Core\Notifications; use WebApplication\Csrf;
/** @var array $errors; int $minPassword; string $notifyMode; array $apiKeys; bool $canApiKeys */
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

<form class="card form" method="post" action="<?= $e($ctx->url('/account/notifications')) ?>" id="notify">
  <h2>Upozornenia e-mailom</h2>
  <?= Csrf::field() ?>
  <p class="field-hint">Upozornenie dostanete, keď vás niekto spomenie v komentári (@Vaše meno) alebo keď niekto pridá komentár k záznamu,
    kde ste už písali. V paneli ich vždy uvidíte pri zvončeku 🔔 vľavo dole.</p>
  <?php foreach (Notifications::MODES as $k => $label): ?>
    <label class="check"><input type="radio" name="email_mode" value="<?= $e($k) ?>"<?= $notifyMode === $k ? ' checked' : '' ?>> <?= $e($label) ?></label>
  <?php endforeach ?>
  <button class="primary">Uložiť</button>
</form>

<?php if ($canApiKeys): ?>
<?= $view->partial('core/api-keys', ['keys' => $apiKeys, 'mine' => true]) ?>
<?php endif ?>
