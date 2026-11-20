<?php use WebApplication\Csrf;
/** @var array $companies, $values, $errors */
$err = function ($k) use ($errors, $e) {
    if (!isset($errors[$k])) {
        return '';
    }
    $m = (array)$errors[$k];
    return '<span class="field-err">' . implode('<br>', array_map($e, $m)) . '</span>';
};
?>
<p><a href="<?= $e($ctx->url('/invoices')) ?>">← Faktúry</a></p>
<form class="card form" method="post" action="<?= $e($ctx->url('/invoices/export')) ?>">
  <?= Csrf::field() ?>
  <p class="muted small">Textový súbor pre účtovníka: v Omege <b>Firma → Import → Import z textového súboru</b>.
    Obsahuje vystavené faktúry a dobropisy firmy podľa dátumu vystavenia. Kódy evidencie, číselných radov a desatinný
    oddeľovač sa nastavujú pri firme (Firmy → Export do Omegy).</p>
  <label>Firma
    <select name="company"><?php foreach ($companies as $c): ?><option value="<?= (int)$c['id'] ?>"<?= (int)$values['company'] === (int)$c['id'] ? ' selected' : '' ?>><?= $e($c['name']) ?></option><?php endforeach ?></select><?= $err('company') ?>
  </label>
  <label>Vystavené od <input type="date" name="from" value="<?= $e($values['from']) ?>" required><?= $err('from') ?></label>
  <label>do <input type="date" name="to" value="<?= $e($values['to']) ?>" required><?= $err('to') ?></label>
  <label class="check"><input type="checkbox" name="only_new" value="1"<?= $values['only_new'] ? ' checked' : '' ?>> Len doklady, ktoré ešte neboli exportované</label>
  <?php if (isset($errors['docs'])): ?><div class="flash err"><b>Tieto doklady sa nedajú exportovať:</b><?= $err('docs') ?></div><?php endif ?>
  <button class="primary">Stiahnuť súbor pre Omegu</button>
</form>
