<?php use WebApplication\Csrf;
/** @var array|null $c; array $values, $errors */
$err = function ($k) use ($errors, $e) { return isset($errors[$k]) ? '<span class="field-err">' . $e($errors[$k]) . '</span>' : ''; };
?>
<p><a href="<?= $e($ctx->url('/stock/shops')) ?>">← E-shopy</a></p>
<?php if (isset($errors['test'])): ?><p class="flash err"><?= $e($errors['test']) ?></p><?php endif ?>
<form class="card form" method="post" action="<?= $e($ctx->url($c ? '/stock/shops/' . $c['id'] . '/edit' : '/stock/shops')) ?>" style="max-width: 42rem">
  <?= Csrf::field() ?>
  <label>Názov <input name="name" value="<?= $e($values['name']) ?>" required placeholder="napr. E-shopy ERMA (PrestaShop)"><?= $err('name') ?></label>
  <label>Adresa e-shopu <input name="url" value="<?= $e($values['url']) ?>" required placeholder="https://www.example.sk/store/"><?= $err('url') ?>
    <small class="field-hint">Adresa, na ktorej beží PrestaShop (aj s podpriečinkom). Pri viacerých obchodoch v jednej inštalácii stačí jedna.</small></label>
  <label>Kľúč Webservice <input type="password" name="api_key" value="" autocomplete="new-password" placeholder="<?= $c ? 'uložený — nechajte prázdne' : '32 znakov' ?>"><?= $err('api_key') ?>
    <small class="field-hint">V administrácii PrestaShopu: Rozšírené parametre → Webservice → pridať kľúč. Oprávnenia: products, combinations,
      stock_availables (GET, PUT), product_options, product_option_values, languages, shops, shop_groups (GET), orders, order_details,
      order_states, order_payments, customers, addresses, countries, currencies, carriers (GET), order_histories (GET, POST).
      Kľúč sa uloží zašifrovaný.</small></label>
  <label class="check"><input type="checkbox" name="active" value="1"<?= $values['active'] ? ' checked' : '' ?>> Aktívne</label>
  <label class="check"><input type="checkbox" name="local_db" value="1"<?= !empty($values['local_db']) ? ' checked' : '' ?>>
    Toto je e-shop, ktorého tabuľky sú v databáze panela — objednávky sa čítajú priamo z databázy, cez toto pripojenie sa mení ich stav</label>
  <p class="muted small">Bez tejto voľby panel číta objednávky e-shopu cez Webservice (ľubovoľný PrestaShop, aj na inom hostingu)
    — v Objednávkach sa e-shop vyberá hore vľavo.</p>
  <button class="primary">Overiť a uložiť</button>
</form>
