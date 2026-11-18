<?php use WebApplication\Csrf; /** @var string|null $error, $flash; string $next */ ?>
<!doctype html>
<html lang="sk">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>Prihlásenie · ERMA panel</title>
  <link rel="stylesheet" href="<?= $e($app->asset('css/panel.css')) ?>">
  <link rel="icon" type="image/png" sizes="32x32" href="<?= $e($app->asset('img/favicon-32.png')) ?>">
  <link rel="apple-touch-icon" href="<?= $e($app->asset('img/apple-touch-icon.png')) ?>">
</head>
<body class="login-page">
  <form class="login card" method="post" action="<?= $e($ctx->url('/login')) ?>">
    <img class="login-logo" src="<?= $e($app->asset('img/logo-vertical.png')) ?>" alt="ERMA Group" width="220" height="163">
    <h1>Sekcia pre zamestnancov</h1>
    <?php if ($flash): ?><p class="flash ok"><?= $e($flash) ?></p><?php endif ?>
    <?php if ($error): ?><p class="flash err"><?= $e($error) ?></p><?php endif ?>
    <?= Csrf::field() ?>
    <input type="hidden" name="next" value="<?= $e($next) ?>">
    <label>Prihlasovacie meno <input name="login" autocomplete="username" required autofocus></label>
    <label>Heslo <input name="password" type="password" autocomplete="current-password" required></label>
    <label class="check"><input type="checkbox" name="remember" value="1"> Zapamätať si ma na 30 dní</label>
    <button class="primary">Prihlásiť</button>
    <p class="muted small"><a href="/">← späť na erma.sk</a></p>
  </form>
</body>
</html>
