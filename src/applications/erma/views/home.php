<?php /** @var array $c company data, string $panelUrl, bool|null $notFound */ $notFound = $notFound ?? false; ?>
<!doctype html>
<html lang="sk">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $e($c['name']) ?> – vývoj elektroniky</title>
  <meta name="description" content="Vývoj elektroniky, firmvéru a IoT riešení na mieru.">
  <link rel="stylesheet" href="<?= $e($app->asset('css/erma.css')) ?>">
  <link rel="icon" type="image/png" sizes="32x32" href="<?= $e($app->asset('img/favicon-32.png')) ?>">
  <link rel="apple-touch-icon" href="<?= $e($app->asset('img/apple-touch-icon.png')) ?>">
</head>
<body>
  <header class="top">
    <a class="brand" href="/"><img class="logo" src="<?= $e($app->asset('img/logo-slogan.png')) ?>" alt="ERMA Group – Crafting Electronics" width="142" height="48"></a>
    <nav><a href="#sluzby">Služby</a><a href="#kontakt">Kontakt</a><a class="staff" href="<?= $e($panelUrl) ?>">Pre zamestnancov</a></nav>
  </header>

  <main>
    <?php if ($notFound): ?>
      <section class="notice">Stránka, ktorú hľadáte, neexistuje. <a href="/">Späť na úvod</a></section>
    <?php endif ?>

    <section class="hero">
      <p class="slogan"><?= $e($c['slogan']) ?></p>
      <h1>Vyvíjame elektroniku<br>od nápadu po výrobok</h1>
      <p class="lead">Návrh hardvéru, firmvér pre mikrokontroléry a IoT riešenia na mieru — vrátane prototypov a malých sérií.</p>
      <a class="button" href="#kontakt">Napíšte nám</a>
    </section>

    <section id="sluzby" class="services">
      <article><h2>Návrh hardvéru</h2><p>Schémy, návrh dosiek plošných spojov, výber súčiastok s ohľadom na cenu a dostupnosť.</p></article>
      <article><h2>Firmvér</h2><p>Programovanie mikrokontrolérov (STM32, ESP32), komunikačné protokoly, bootloadery a aktualizácie.</p></article>
      <article><h2>IoT a softvér</h2><p>Prepojenie zariadení so serverom, webové aplikácie na správu a vzdialené ovládanie.</p></article>
      <article><h2>Výroba</h2><p>Prototypy, osadenie dosiek, káble a krabičky — od jedného kusu po malé série.</p></article>
    </section>

    <section id="kontakt" class="contact">
      <h2>Kontakt</h2>
      <p><b><?= $e($c['name']) ?></b><br><?= $e($c['street']) ?>, <?= $e($c['city']) ?><br>IČO: <?= $e($c['ico']) ?></p>
      <p><a href="mailto:<?= $e($c['email']) ?>"><?= $e($c['email']) ?></a><br>
         <a href="tel:<?= $e(preg_replace('/\s+/', '', $c['phone'])) ?>"><?= $e($c['phone']) ?></a></p>
    </section>
  </main>

  <footer><img class="mark" src="<?= $e($app->asset('img/mark.png')) ?>" alt="" width="32" height="32"><span>© <?= date('Y') ?> <?= $e($c['name']) ?> · Stránka sa pripravuje</span></footer>
</body>
</html>
