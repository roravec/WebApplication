<?php use Panel\Shop\ShopOrderEdit; use WebApplication\Csrf;
/** @var array $o, $values, $errors; array|null $invoice; int $src; string $type, $country */
$err = function ($k) use ($errors, $e) { return isset($errors[$k]) ? '<span class="field-err">' . $e($errors[$k]) . '</span>' : ''; };
?>
<p><a href="<?= $e($ctx->orderUrl($src, (int)$o['id_order'])) ?>">← Objednávka <?= $e($o['reference']) ?></a></p>
<?php if (isset($errors['form'])): ?><p class="flash err"><?= $e($errors['form']) ?></p><?php endif ?>
<p class="muted">Zmena sa uloží do e-shopu: vznikne nová adresa zákazníka a objednávka sa na ňu prepne (pôvodná ostane pre históriu
  a iné objednávky). Krajina ostáva: <b><?= $e($country) ?></b>.
  <?php if ($invoice && $type === 'invoice'): ?><br><b>Faktúra k objednávke už existuje</b> — zmena adresy ju neprepíše.<?php endif ?></p>
<form class="card form-grid narrow" method="post" action="<?= $e($ctx->orderUrl($src, (int)$o['id_order'], '/address/' . $type)) ?>">
  <?= Csrf::field() ?>
  <?php foreach (ShopOrderEdit::ADDRESS_FIELDS as $k => $label): ?>
    <label class="<?= in_array($k, ['company', 'address1', 'address2', 'other'], true) ? 'wide' : '' ?>"><?= $e($label) ?>
      <input name="<?= $e($k) ?>" value="<?= $e($values[$k] ?? '') ?>"<?= in_array($k, ['lastname', 'address1', 'city'], true) ? ' required' : '' ?>><?= $err($k) ?></label>
  <?php endforeach ?>
  <p class="wide"><button class="primary">Uložiť do e-shopu</button></p>
</form>
