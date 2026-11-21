<?php use Panel\Shop\ShopOrderStates; use WebApplication\Csrf;
/** @var array $values, $errors, $templates; array|null $state; int $sid, $src */
$err = function ($k) use ($errors, $e) { return isset($errors[$k]) ? '<span class="field-err">' . $e($errors[$k]) . '</span>' : ''; };
$q = $src ? '?src=' . $src : '';
?>
<p><a href="<?= $e($ctx->url('/orders/states' . $q)) ?>">← Stavy objednávok</a></p>
<?php if (isset($errors['form'])): ?><p class="flash err"><?= $e($errors['form']) ?></p><?php endif ?>
<?php if ($state && $state['module_name'] !== ''): ?><p class="muted">Tento stav používa modul <b><?= $e($state['module_name']) ?></b> (napr. platobná brána) — meňte opatrne.</p><?php endif ?>
<form class="card form-grid narrow" method="post" action="<?= $e($ctx->url('/orders/states/' . $sid . $q)) ?>">
  <?= Csrf::field() ?>
  <label class="wide">Názov <input name="name" value="<?= $e($values['name']) ?>" maxlength="64" required><?= $err('name') ?></label>
  <label>Farba <input type="color" name="color" value="<?= $e($values['color'] ?: '#4169e1') ?>"><?= $err('color') ?></label>
  <label>Šablóna e-mailu <input name="template" value="<?= $e($values['template']) ?>" list="mail-templates" maxlength="64" placeholder="napr. shipped"><?= $err('template') ?>
    <small class="field-hint">Názov e-mailu v e-shope (súbor v mails/sk/). shipped = odoslanie s odkazom na sledovanie, in_transit = na ceste / na vyzdvihnutie, preparation = pripravuje sa, payment = platba prijatá.</small></label>
  <datalist id="mail-templates"><?php foreach ($templates as $t): ?><option value="<?= $e($t) ?>"><?php endforeach ?></datalist>
  <?php foreach (ShopOrderStates::FLAGS as $f => $label): ?>
    <label class="check wide"><input type="checkbox" name="<?= $e($f) ?>" value="1"<?= !empty($values[$f]) ? ' checked' : '' ?>> <?= $e($label) ?></label>
  <?php endforeach ?>
  <p class="wide muted small">Mení sa len slovenský názov a šablóna (ostatné jazyky e-shopu ostávajú; nový stav ich dostane rovnaké).
    Nastavenia faktúr PrestaShopu sa nemenia — faktúry vystavuje panel.</p>
  <p class="wide"><button class="primary">Uložiť do e-shopu</button></p>
</form>
