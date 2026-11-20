<?php use Panel\Invoicing\Legislation; use WebApplication\Csrf;
/** @var array $values (rates, texts), $errors, $textsNow, $vatReg */
$err = function ($k) use ($errors, $e) { return isset($errors[$k]) ? '<p class="field-err">' . $e($errors[$k]) . '</p>' : ''; };
$rateRow = function (array $r) use ($e) { ?>
  <tr>
    <td><input name="rate_country[]" value="<?= $e($r['country']) ?>" size="3" maxlength="2"></td>
    <td><input name="rate_rate[]" value="<?= $e($r['rate'] === '' ? '' : (string)(float)$r['rate']) ?>" size="5" class="num"></td>
    <td><select name="rate_kind[]"><option value="standard">základná</option><option value="reduced"<?= $r['kind'] === 'reduced' ? ' selected' : '' ?>>znížená</option></select></td>
    <td><input type="date" name="rate_from[]" value="<?= $e($r['valid_from']) ?>"></td>
    <td><input type="date" name="rate_to[]" value="<?= $e((string)$r['valid_to']) ?>"></td>
    <td><input name="rate_note[]" value="<?= $e($r['note']) ?>"></td>
    <td><button type="button" class="link-danger" data-remove-row title="Odstrániť">✕</button></td>
  </tr>
<?php };
$textRow = function (array $t) use ($e) { ?>
  <tr>
    <td><select name="text_code[]"><?php foreach (Legislation::TEXT_CODES as $code => $label): ?><option value="<?= $e($code) ?>"<?= $t['code'] === $code ? ' selected' : '' ?>><?= $e($label) ?></option><?php endforeach ?></select></td>
    <td><input name="text_text[]" value="<?= $e($t['text']) ?>" class="w-name"></td>
    <td><input type="date" name="text_from[]" value="<?= $e($t['valid_from']) ?>"></td>
    <td><input name="text_note[]" value="<?= $e($t['note']) ?>"></td>
    <td><button type="button" class="link-danger" data-remove-row title="Odstrániť">✕</button></td>
  </tr>
<?php };
?>
<p class="muted">Zákonné údaje, ktoré sa menia, sú tu — nie v programe. Zmena platí pre doklady vystavené odteraz (podľa dátumu
  „platí od“); <b>vystavené doklady si nesú kópiu</b> sadzieb a textov z dňa vystavenia a nezmenia sa. Pri zmene zákona
  nepremazávajte starý riadok — pridajte nový s novým dátumom „platí od“ (a starému dajte „platí do“).</p>
<form method="post" action="<?= $e($ctx->url('/settings/legal')) ?>">
  <?= Csrf::field() ?>
  <section class="card">
    <h2>Sadzby DPH</h2>
    <p class="muted small">Ponúkajú sa vo faktúre a kontrolujú pri vystavení (platiteľ DPH) podľa dátumu dodania. Základná sadzba je predvolená pre novú položku.</p>
    <?= $err('rates') ?>
    <div class="scroll-x"><table class="grid compact">
      <thead><tr><th>Krajina</th><th class="num">Sadzba %</th><th>Druh</th><th>Platí od</th><th>Platí do</th><th>Poznámka</th><th></th></tr></thead>
      <tbody id="rate-rows"><?php foreach ($values['rates'] as $r) { $rateRow($r); } ?></tbody>
    </table></div>
    <template id="rate-rows-tpl"><?php $rateRow(['country' => 'SK', 'rate' => '', 'kind' => 'standard', 'valid_from' => '', 'valid_to' => '', 'note' => '']); ?></template>
    <p><button type="button" data-add-row="rate-rows">+ Sadzba</button></p>
  </section>
  <section class="card">
    <h2>Texty na dokladoch</h2>
    <p class="muted small">Pre každý typ platí riadok s najneskorším dátumom „platí od“, ktorý nie je po dátume vystavenia.
      Zástupné znaky: <code>{currency}</code>, <code>{number}</code>, <code>{date}</code>, <code>{reason}</code>. Prázdny text sa netlačí.</p>
    <?= $err('texts') ?>
    <div class="scroll-x"><table class="grid compact">
      <thead><tr><th>Typ</th><th>Text</th><th>Platí od</th><th>Poznámka</th><th></th></tr></thead>
      <tbody id="text-rows"><?php foreach ($values['texts'] as $t) { $textRow($t); } ?></tbody>
    </table></div>
    <template id="text-rows-tpl"><?php $textRow(['code' => 'non_vat_payer', 'text' => '', 'valid_from' => date('Y-m-d'), 'note' => '']); ?></template>
    <p><button type="button" data-add-row="text-rows">+ Text</button></p>
  </section>
  <p><button class="primary">Uložiť</button></p>
</form>

<form method="post" action="<?= $e($ctx->url('/settings/legal/vat-registration')) ?>">
  <?= Csrf::field() ?>
  <section class="card">
    <h2>Registrácia k DPH — hranica obratu</h2>
    <p class="muted small">Pre firmy, ktoré nie sú platiteľmi DPH: Výsledky → Výsledok firmy a nástenka sledujú obrat a upozornia pri 80 %
      hranice. Obdobie „kalendárny rok“: nad hranicou platiteľ od 1. 1. ďalšieho roka, nad okamžitou hranicou odo dňa dodania.
      Pri zmene zákona pridajte nový riadok s dátumom „platí od“ a starému dajte „platí do“.</p>
    <?= $err('vat_registration') ?>
    <div class="scroll-x"><table class="grid compact">
      <thead><tr><th>Krajina</th><th>Obdobie</th><th class="num">Hranica €</th><th class="num">Okamžitá hranica €</th><th>Platí od</th><th>Platí do</th><th>Poznámka</th></tr></thead>
      <tbody>
      <?php foreach (array_merge(array_values($vatReg), [['country' => 'SK', 'period' => 'year', 'threshold' => '', 'threshold_immediate' => '', 'valid_from' => '', 'valid_to' => '', 'note' => '']]) as $i => $r): ?>
        <tr>
          <td><input name="reg[<?= $i ?>][country]" value="<?= $e($r['country']) ?>" size="3" maxlength="2"></td>
          <td><select name="reg[<?= $i ?>][period]"><?php foreach (\Panel\Reports\VatTurnover::PERIODS as $k => $label): ?><option value="<?= $e($k) ?>"<?= $r['period'] === $k ? ' selected' : '' ?>><?= $e($label) ?></option><?php endforeach ?></select></td>
          <td><input name="reg[<?= $i ?>][threshold]" value="<?= $e($r['threshold'] === '' ? '' : (string)(float)$r['threshold']) ?>" size="9" class="num"></td>
          <td><input name="reg[<?= $i ?>][threshold_immediate]" value="<?= $e($r['threshold_immediate'] === null || $r['threshold_immediate'] === '' ? '' : (string)(float)$r['threshold_immediate']) ?>" size="9" class="num"></td>
          <td><input type="date" name="reg[<?= $i ?>][valid_from]" value="<?= $e($r['valid_from']) ?>"></td>
          <td><input type="date" name="reg[<?= $i ?>][valid_to]" value="<?= $e((string)$r['valid_to']) ?>"></td>
          <td><input name="reg[<?= $i ?>][note]" value="<?= $e($r['note']) ?>" size="40"></td>
        </tr>
      <?php endforeach ?>
      </tbody>
    </table></div>
    <p class="muted small">Riadok s prázdnou hranicou a dátumom sa neuloží (tak sa pravidlo odstráni).</p>
    <p><button class="primary">Uložiť hranice</button></p>
  </section>
</form>
