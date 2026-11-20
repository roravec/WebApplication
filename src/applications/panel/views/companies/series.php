<?php use Panel\Invoicing\Numbering; use WebApplication\Csrf;
/** @var array $company, $values, $errors; array|null $series; bool $used; int $year */
$err = function ($k) use ($errors, $e) { return isset($errors[$k]) ? '<span class="field-err">' . $e($errors[$k]) . '</span>' : ''; };
$action = '/companies/' . $company['id'] . '/series' . ($series ? '/' . $series['id'] : '');
$ro = $used ? ' readonly' : '';
?>
<p><a href="<?= $e($ctx->url('/companies/' . $company['id'])) ?>#series">← <?= $e($company['name']) ?></a></p>
<form class="card form" method="post" action="<?= $e($ctx->url($action)) ?>">
  <?= Csrf::field() ?>
  <?php if ($used): ?><p class="flash ok">Rad už bol použitý — formát a typ sa nedajú meniť (číslovanie vystavených dokladov musí ostať jednotné).</p><?php endif ?>
  <label>Názov <input name="name" value="<?= $e($values['name']) ?>" required><?= $err('name') ?></label>
  <label>Typ dokladu
    <select name="doc_type"<?= $used ? ' disabled' : '' ?>>
      <?php foreach (Numbering::allTypes() as $k => $label): ?>
        <option value="<?= $e($k) ?>"<?= $values['doc_type'] === $k ? ' selected' : '' ?>><?= $e($label) ?></option>
      <?php endforeach ?>
    </select><?= $err('doc_type') ?>
  </label>
  <label>Formát čísla <input name="format" value="<?= $e($values['format']) ?>"<?= $ro ?> required><?= $err('format') ?>
    <small class="field-hint">{RRRR} rok, {RR} rok dvojmiestne, {MM} mesiac, {NNNNNN} poradové číslo (počet N = počet číslic). Napr. FA{RRRR}/{NNNNNN} → FA<?= (int)$year ?>/000001</small></label>
  <label>Variabilný symbol <input name="vs_format" value="<?= $e($values['vs_format']) ?>"<?= $ro ?> placeholder="prázdne = číslice z čísla dokladu"><?= $err('vs_format') ?>
    <small class="field-hint">Napr. {RR}{NNNNNN} → <?= $e(substr((string)$year, 2)) ?>000001. Najviac 10 číslic.</small></label>
  <label class="check"><input type="checkbox" name="reset_yearly" value="1"<?= $values['reset_yearly'] ? ' checked' : '' ?><?= $used ? ' disabled' : '' ?>> Číslovať každý rok od začiatku</label>
  <label>Ďalšie poradové číslo<?= $values['reset_yearly'] ? ' v roku ' . (int)$year : '' ?> <input name="next" type="number" min="1" value="<?= (int)$values['next'] ?>"><?= $err('next') ?>
    <small class="field-hint">Prvé číslo nového systému určí majiteľ s účtovníkom. Systém nedovolí vystaviť číslo, ktoré už existuje.</small></label>
  <label class="check"><input type="checkbox" name="is_default" value="1"<?= $values['is_default'] ? ' checked' : '' ?>> Predvolený rad pre tento typ dokladu</label>
  <label class="check"><input type="checkbox" name="active" value="1"<?= $values['active'] ? ' checked' : '' ?>> Aktívny</label>
  <button class="primary">Uložiť</button>
</form>
