<?php
/** @var string $title, $section, $content; Client|null $user; string|null $flash, $flashError; Panel\PanelContext $ctx */
use Panel\Rights;
use WebApplication\Csrf;

$nav = [
    ['dashboard', '/', 'Prehľad', Rights::VIEW],
    ['orders', '/orders', 'Objednávky', Rights::VIEW],
    ['invoices', null, 'Faktúry', Rights::VIEW],          // pripravuje sa
    ['companies', null, 'Firmy', Rights::ADMIN],          // pripravuje sa
    ['employees', '/employees', 'Zamestnanci', Rights::ADMIN],
];
?>
<!doctype html>
<html lang="sk">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title><?= $e($title) ?> · ERMA panel</title>
  <link rel="stylesheet" href="<?= $e($app->asset('css/panel.css')) ?>">
  <link rel="icon" type="image/png" sizes="32x32" href="<?= $e($app->asset('img/favicon-32.png')) ?>">
  <link rel="apple-touch-icon" href="<?= $e($app->asset('img/apple-touch-icon.png')) ?>">
</head>
<body>
<div class="shell">
  <aside class="side">
    <a class="brand" href="<?= $e($ctx->url('/')) ?>"><img src="<?= $e($app->asset('img/mark.png')) ?>" alt="" width="32" height="32"><span>ERMA <b>panel</b></span></a>
    <nav>
      <?php foreach ($nav as list($key, $path, $label, $rights)): if (!$ctx->can($rights)) continue; ?>
        <?php if ($path === null): ?>
          <span class="soon" title="Pripravuje sa"><?= $e($label) ?></span>
        <?php else: ?>
          <a href="<?= $e($ctx->url($path)) ?>" class="<?= $section === $key ? 'active' : '' ?>"><?= $e($label) ?></a>
        <?php endif ?>
      <?php endforeach ?>
    </nav>
    <div class="me">
      <a href="<?= $e($ctx->url('/account')) ?>" class="<?= $section === 'account' ? 'active' : '' ?>"><?= $e($user->getName()) ?></a>
      <small><?= $e(Rights::label($user->getRights())) ?></small>
      <form method="post" action="<?= $e($ctx->url('/logout')) ?>"><?= Csrf::field() ?><button class="link">Odhlásiť</button></form>
    </div>
  </aside>
  <main class="content">
    <h1><?= $e($title) ?></h1>
    <?php if ($flash): ?><p class="flash ok"><?= $e($flash) ?></p><?php endif ?>
    <?php if ($flashError): ?><p class="flash err"><?= $e($flashError) ?></p><?php endif ?>
    <?= $content ?>
  </main>
</div>
</body>
</html>
