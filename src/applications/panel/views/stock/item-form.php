<?php use WebApplication\Csrf;
/** @var array|null $item; array $values, $errors, $companies */
$err = function ($k) use ($errors, $e) { return isset($errors[$k]) ? '<span class="field-err">' . $e($errors[$k]) . '</span>' : ''; };
?>
<p><a href="<?= $e($ctx->url($item ? '/stock/items/' . $item['id'] : '/stock')) ?>">← <?= $item ? 'Karta' : 'Sklad' ?></a></p>
<form class="card form-grid narrow" method="post" action="<?= $e($ctx->url($item ? '/stock/items/' . $item['id'] : '/stock/items')) ?>">
  <?= Csrf::field() ?>
  <?php if (!$item && count($companies) > 1): ?>
    <label class="wide">Firma <select name="company_id"><?php foreach ($companies as $c): ?><option value="<?= (int)$c['id'] ?>"<?= (int)$values['company_id'] === (int)$c['id'] ? ' selected' : '' ?>><?= $e($c['name']) ?></option><?php endforeach ?></select><?= $err('company_id') ?></label>
  <?php elseif (!$item): ?><input type="hidden" name="company_id" value="<?= (int)$values['company_id'] ?>"><?php endif ?>
  <label>Kód (SKU) <input name="sku" value="<?= $e($values['sku']) ?>" required><?= $err('sku') ?></label>
  <label class="wide">Názov <input name="name" value="<?= $e($values['name']) ?>" required><?= $err('name') ?></label>
  <label>Merná jednotka <input name="unit" value="<?= $e($values['unit']) ?>" maxlength="10" size="6"></label>
  <label>EAN <input name="ean" value="<?= $e($values['ean']) ?>" maxlength="20"></label>
  <label>Minimálne množstvo <input name="min_qty" value="<?= $e($values['min_qty'] === null ? '' : rtrim(rtrim((string)$values['min_qty'], '0'), '.')) ?>" placeholder="upozorniť pod"><?= $err('min_qty') ?></label>
  <label class="wide">Poznámka <textarea name="note" rows="2"><?= $e((string)$values['note']) ?></textarea></label>
  <label class="check wide"><input type="checkbox" name="active" value="1"<?= $values['active'] ? ' checked' : '' ?>> Aktívna</label>
  <p class="wide"><button class="primary">Uložiť</button></p>
</form>
