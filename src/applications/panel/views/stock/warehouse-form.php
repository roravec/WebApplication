<?php use WebApplication\Csrf;
/** @var array|null $w; array $values, $errors, $companies */
$err = function ($k) use ($errors, $e) { return isset($errors[$k]) ? '<span class="field-err">' . $e($errors[$k]) . '</span>' : ''; };
?>
<p><a href="<?= $e($ctx->url('/stock/warehouses')) ?>">← Sklady</a></p>
<form class="card form-grid narrow" method="post" action="<?= $e($ctx->url($w ? '/stock/warehouses/' . $w['id'] : '/stock/warehouses')) ?>">
  <?= Csrf::field() ?>
  <?php if (!$w && count($companies) > 1): ?>
    <label class="wide">Firma <select name="company_id"><?php foreach ($companies as $c): ?><option value="<?= (int)$c['id'] ?>"<?= (int)$values['company_id'] === (int)$c['id'] ? ' selected' : '' ?>><?= $e($c['name']) ?></option><?php endforeach ?></select><?= $err('company_id') ?></label>
  <?php elseif (!$w): ?><input type="hidden" name="company_id" value="<?= (int)$values['company_id'] ?>"><?php endif ?>
  <label>Kód <input name="code" value="<?= $e($values['code']) ?>" maxlength="20" required placeholder="napr. HOWIES"><?= $err('code') ?></label>
  <label>Názov <input name="name" value="<?= $e($values['name']) ?>" required><?= $err('name') ?></label>
  <input type="hidden" name="kind" value="other">
  <p class="wide muted small">Vlastný sklad v paneli (dávky s nákupnou cenou, FIFO) — pre veci mimo e-shopov. Sklad e-shopu sa spravuje v e-shope (menu E-shopy).</p>
  <label class="wide">Umiestnenie <input name="location" value="<?= $e($values['location']) ?>" placeholder="adresa, miestnosť, regál …"></label>
  <label class="check wide"><input type="checkbox" name="active" value="1"<?= $values['active'] ? ' checked' : '' ?>> Aktívny</label>
  <p class="wide"><button class="primary">Uložiť</button></p>
</form>
