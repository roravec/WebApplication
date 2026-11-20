<?php use WebApplication\Csrf; /** @var array $errors, $values; string $key; int $minPassword */ ?>
<!doctype html>
<html lang="sk" data-theme="<?= $e($ctx->theme()) ?>" data-theme-path="<?= $e($ctx->themeCookiePath()) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>Prvý správca · ERMA panel</title>
  <link rel="stylesheet" href="<?= $e($app->asset('css/panel.css')) ?>">
  <link rel="icon" type="image/png" sizes="32x32" href="<?= $e($app->asset('img/favicon-32.png')) ?>">
  <script src="<?= $e($app->asset('js/theme.js')) ?>" defer></script>
</head>
<body class="login-page">
  <form class="login card" method="post" action="<?= $e($ctx->url('/setup')) ?>?key=<?= $e(rawurlencode($key)) ?>">
    <img class="login-logo" src="<?= $e($app->asset('img/logo-vertical.png')) ?>" alt="ERMA Group" width="220" height="163">
    <h1>Prvý správca panela</h1>
    <p class="muted small">Táto stránka funguje len raz — kým v paneli nie je žiadny účet. Heslo si zvoľte sami, nikto iný ho neuvidí.</p>
    <?= Csrf::field() ?>
    <label>E-mail (prihlasovacie meno) <input name="identifier" type="email" value="<?= $e($values['identifier']) ?>" autocomplete="username" required autofocus></label>
    <?php if (isset($errors['identifier'])): ?><p class="flash err"><?= $e($errors['identifier']) ?></p><?php endif ?>
    <p class="muted small">Ďalší zamestnanci sa tiež prihlasujú svojím e-mailom.</p>
    <label>Meno a priezvisko <input name="name" value="<?= $e($values['name']) ?>" required></label>
    <?php if (isset($errors['name'])): ?><p class="flash err"><?= $e($errors['name']) ?></p><?php endif ?>
    <label>Heslo (aspoň <?= (int)$minPassword ?> znakov) <input name="password" type="password" autocomplete="new-password" required></label>
    <?php if (isset($errors['password'])): ?><p class="flash err"><?= $e($errors['password']) ?></p><?php endif ?>
    <label>Heslo znova <input name="password2" type="password" autocomplete="new-password" required></label>
    <?php if (isset($errors['password2'])): ?><p class="flash err"><?= $e($errors['password2']) ?></p><?php endif ?>
    <button class="primary">Vytvoriť účet správcu</button>
  </form>
</body>
</html>
