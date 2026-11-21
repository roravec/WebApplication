<?php use WebApplication\Csrf;
/** @var array|null $c; array $values, $errors */
$err = function ($k) use ($errors, $e) { return isset($errors[$k]) ? '<span class="field-err">' . $e($errors[$k]) . '</span>' : ''; };
?>
<p><a href="<?= $e($ctx->url('/settings/eshops')) ?>">← Pripojené e-shopy</a></p>
<?php if (isset($errors['test'])): ?><p class="flash err"><?= $e($errors['test']) ?></p><?php endif ?>
<form class="card form" method="post" action="<?= $e($ctx->url($c ? '/settings/eshops/' . $c['id'] . '/edit' : '/settings/eshops')) ?>" style="max-width: 42rem">
  <?= Csrf::field() ?>
  <label>Názov <input name="name" value="<?= $e($values['name']) ?>" required placeholder="napr. E-shopy ERMA (PrestaShop)"><?= $err('name') ?></label>
  <label>Adresa e-shopu <input name="url" value="<?= $e($values['url']) ?>" required placeholder="https://www.example.sk/store/"><?= $err('url') ?>
    <small class="field-hint">Adresa, na ktorej beží PrestaShop (aj s podpriečinkom). Pri viacerých obchodoch v jednej inštalácii stačí jedna.</small></label>
  <label>Kľúč Webservice <input type="password" name="api_key" value="" autocomplete="new-password" placeholder="<?= $c ? 'uložený — nechajte prázdne' : '32 znakov' ?>"><?= $err('api_key') ?>
    <small class="field-hint">V administrácii PrestaShopu: Rozšírené parametre → Webservice → pridať kľúč. Oprávnenia:
      <b>GET + PUT:</b> products, combinations, stock_availables, orders, order_carriers ·
      <b>GET + POST:</b> order_histories, addresses · <b>GET + PUT + POST:</b> order_states ·
      <b>GET + POST + PUT + DELETE:</b> specific_prices, order_details ·
      <b>GET:</b> product_options, product_option_values, languages, shops, shop_groups, order_payments,
      customers, countries, currencies, carriers, taxes, tax_rules, tax_rule_groups, categories.
      Kľúč sa uloží zašifrovaný.</small></label>
  <label class="check"><input type="checkbox" name="active" value="1"<?= $values['active'] ? ' checked' : '' ?>> Aktívne</label>
  <label class="check"><input type="checkbox" name="local_db" value="1"<?= !empty($values['local_db']) ? ' checked' : '' ?>>
    E-shop je v tej istej databáze ako panel (naše e-shopy na tomto hostingu — zaškrtnúť)</label>
  <p class="muted small">Zaškrtnuté: objednávky sa naďalej čítajú priamo z databázy a cez toto pripojenie sa menia (adresy, stav,
    číslo zásielky, položky…). Nezaškrtnuté len pri e-shope na inom hostingu s vlastnou databázou — jeho objednávky panel
    číta cez Webservice a v Objednávkach sa vyberá hore vľavo (pri našom e-shope by sa objednávky zobrazili dvakrát).</p>
  <button class="primary">Overiť a uložiť</button>
</form>
