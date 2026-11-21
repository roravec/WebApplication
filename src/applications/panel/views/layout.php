<?php
/** @var string $title, $section, $content; Client|null $user; string|null $flash, $flashError; Panel\PanelContext $ctx */
use WebApplication\Csrf;

$menu = $ctx->navigation()->menu($ctx);            // groups of the left menu (src/Navigation.php, from the modules)
$active = Panel\Navigation::menuKey($section);
$tabs = $ctx->navigation()->tabs($section, $ctx);  // tabs above the page (Nastavenia, Faktúry)
$helpUrl = $ctx->url('/help/for') . '?' . http_build_query(['page' => (string)($helpPage ?? ''), 'section' => $section]);
?>
<!doctype html>
<html lang="sk" data-theme="<?= $e($ctx->theme()) ?>" data-theme-path="<?= $e($ctx->themeCookiePath()) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title><?= $e($title) ?> · ERMA panel</title>
  <link rel="stylesheet" href="<?= $e($app->asset('css/panel.css') . '?v=' . @filemtime(dirname(__DIR__) . '/css/panel.css')) ?>">
  <link rel="icon" type="image/png" sizes="32x32" href="<?= $e($app->asset('img/favicon-32.png')) ?>">
  <link rel="apple-touch-icon" href="<?= $e($app->asset('img/apple-touch-icon.png')) ?>">
  <script src="<?= $e($app->asset('js/theme.js') . '?v=' . @filemtime(dirname(__DIR__) . '/js/theme.js')) ?>" defer></script>
  <script src="<?= $e($app->asset('js/panel.js') . '?v=' . @filemtime(dirname(__DIR__) . '/js/panel.js')) ?>" defer></script>
</head>
<body data-tick="<?= $e($ctx->url('/cron/tick')) ?>">
<div class="shell">
  <aside class="side">
    <a class="brand" href="<?= $e($ctx->url('/')) ?>"><img src="<?= $e($app->asset('img/mark.png')) ?>" alt="" width="32" height="32"><span>ERMA <b>panel</b></span></a>
    <nav>
      <?php foreach ($menu as $group): ?>
        <?php if ($group['label'] !== ''): ?><span class="nav-group"><?= $e($group['label']) ?></span><?php endif ?>
        <?php foreach ($group['items'] as $i): ?>
          <a href="<?= $e($ctx->url($i['path'])) ?>" class="<?= $active === $i['key'] ? 'active' : '' ?>"><?= $e($i['label']) ?></a>
        <?php endforeach ?>
      <?php endforeach ?>
    </nav>
    <div class="me">
      <a href="<?= $e($ctx->url('/account')) ?>" class="<?= $section === 'account' ? 'active' : '' ?>"><?= $e($user->getName()) ?></a>
      <small><?= $e($ctx->access()->roleLabel()) ?></small>
      <?php $unread = $ctx->unreadNotifications(); ?>
      <a href="<?= $e($ctx->url('/notifications')) ?>" class="bell<?= $section === 'notifications' ? ' active' : '' ?>" title="Upozornenia"
         data-bell="<?= $e($ctx->url('/notifications/count')) ?>">🔔 Upozornenia <span class="badge bell-count"<?= $unread ? '' : ' hidden' ?>><?= (int)$unread ?></span></a>
      <a href="<?= $e($ctx->url('/help')) ?>" class="<?= $section === 'help' ? 'active' : '' ?>">? Pomocník — návody</a>
      <button type="button" class="link theme-toggle" data-theme-toggle title="Prepnúť farebný režim"><span class="to-light">☀ Denný režim</span><span class="to-dark">☾ Tmavý režim</span></button>
      <form method="post" action="<?= $e($ctx->url('/logout')) ?>"><?= Csrf::field() ?><button class="link">Odhlásiť</button></form>
    </div>
  </aside>
  <main class="content">
    <form class="search" method="get" action="<?= $e($ctx->url('/search')) ?>" role="search">
      <input type="search" name="q" placeholder="Hľadať — číslo faktúry, objednávky, dokladu, názov…" aria-label="Hľadať v paneli"
        value="<?= $e($section === 'search' ? (string)$ctx->request->query('q', '') : '') ?>">
    </form>
    <div class="page-head"><h1><?= $e($title) ?></h1>
      <a class="help-link" href="<?= $e($helpUrl) ?>" target="_blank" rel="noopener" title="Návod k tejto stránke (otvorí sa na novej karte)">? Pomocník</a></div>
    <?php if ($tabs): ?><nav class="tabs"><?php foreach ($tabs as $t): ?><a href="<?= $e($ctx->url($t['path'])) ?>" class="<?= $t['active'] ? 'active' : '' ?>"><?= $e($t['label']) ?></a><?php endforeach ?></nav><?php endif ?>
    <?php if ($flash): ?><p class="flash ok"><?= $e($flash) ?></p><?php endif ?>
    <?php if ($flashError): ?><p class="flash err"><?= $e($flashError) ?></p><?php endif ?>
    <?= $content ?>
  </main>
</div>
</body>
</html>
