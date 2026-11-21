<?php use WebApplication\Csrf;
/** @var array|null $customer; array $values, $errors */
$err = function ($k) use ($errors, $e) { return isset($errors[$k]) ? '<span class="field-err">' . $e($errors[$k]) . '</span>' : ''; };
$v = function ($k) use ($values) { return $values[$k] ?? ''; };
$action = $customer ? '/customers/' . $customer['id'] : '/customers';
?>
<p><a href="<?= $e($ctx->url('/customers')) ?>">← Odberatelia</a></p>
<form class="card form-grid narrow" method="post" action="<?= $e($ctx->url($action)) ?>">
  <?= Csrf::field() ?>
  <?php if (!$customer && count($companies) > 1): ?>
    <label class="wide">Odberateľ firmy
      <select name="company_id"><?php foreach ($companies as $c): ?><option value="<?= (int)$c['id'] ?>"<?= (int)$v('company_id') === (int)$c['id'] ? ' selected' : '' ?>><?= $e($c['name']) ?></option><?php endforeach ?></select><?= $err('company_id') ?>
    </label>
  <?php elseif (!$customer): ?>
    <input type="hidden" name="company_id" value="<?= (int)$v('company_id') ?>">
  <?php endif ?>
  <label>Typ
    <select name="type"><option value="b2c">fyzická osoba (B2C)</option><option value="b2b"<?= $v('type') === 'b2b' ? ' selected' : '' ?>>firma (B2B)</option></select>
  </label>
  <label class="wide">Meno / obchodné meno <input name="name" value="<?= $e($v('name')) ?>" required><?= $err('name') ?></label>
  <label class="wide">Ulica a číslo <input name="street" value="<?= $e($v('street')) ?>"></label>
  <label>PSČ <input name="zip" value="<?= $e($v('zip')) ?>"></label>
  <label>Mesto <input name="city" value="<?= $e($v('city')) ?>"></label>
  <label>Krajina <input name="country" value="<?= $e($v('country')) ?>" maxlength="2" size="3"><?= $err('country') ?></label>
  <label>IČO <input name="ico" value="<?= $e($v('ico')) ?>"><?= $err('ico') ?> <button type="button" class="link rpo" data-rpo="" data-url="<?= $e($ctx->url('/lookup/ico')) ?>">Doplniť z registra</button></label>
  <label>DIČ <input name="dic" value="<?= $e($v('dic')) ?>"></label>
  <label>IČ DPH <input name="icdph" value="<?= $e($v('icdph')) ?>"></label>
  <label>E-mail <input name="email" type="email" value="<?= $e($v('email')) ?>"><?= $err('email') ?></label>
  <label>Telefón <input name="phone" value="<?= $e($v('phone')) ?>"></label>
  <label>Splatnosť (dní) <input name="due_days" value="<?= $e($v('due_days')) ?>" placeholder="podľa firmy"><?= $err('due_days') ?></label>
  <label class="wide">Poznámka <textarea name="note" rows="2"><?= $e($v('note')) ?></textarea></label>
  <p class="wide"><button class="primary">Uložiť</button></p>
</form>
<?php if ($customer): ?><?= $view->partial('core/record', ['ref' => 'customer:' . (int)$customer['id']]) ?><?php endif ?>
