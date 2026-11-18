<!doctype html>
<html lang="sk">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $e($title) ?></title>
  <link rel="stylesheet" href="<?= $e($app->asset('css/demo.css')) ?>">
</head>
<body>
  <header>
    <a href="<?= $e($app->url('/')) ?>">Demo</a>
    <?php if ($user): ?>
      <span><?= $e($user->getName()) ?></span>
      <form method="post" action="<?= $e($app->url('/logout')) ?>"><?= WebApplication\Csrf::field() ?><button>Log out</button></form>
    <?php else: ?>
      <a href="<?= $e($app->url('/login')) ?>">Log in</a>
    <?php endif ?>
  </header>
  <main><?= $content ?></main>
</body>
</html>
