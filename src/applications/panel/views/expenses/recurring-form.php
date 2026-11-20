<?php use Panel\Expenses\Expenses; use Panel\Rights; use WebApplication\Csrf;
/** @var array|null $rec; array $values, $errors, $companies, $categories, $shops, $vat, $suppliers, $generated */
$err = function ($k) use ($errors, $e) { return isset($errors[$k]) ? '<span class="field-err">' . $e($errors[$k]) . '</span>' : ''; };
$v = function ($k) use ($values) { return $values[$k] ?? ''; };
$kinds = [];
foreach ($categories as $c) {
    $kinds[Expenses::KINDS[$c['kind']] ?? $c['kind']][] = $c;
}
$canEdit = $ctx->can(Rights::EXPENSES_EDIT);
?>
<p><a href="<?= $e($ctx->url('/expenses/recurring')) ?>">← Opakované náklady</a></p>
<form class="card form-grid" method="post" action="<?= $e($ctx->url($rec ? '/expenses/recurring/' . $rec['id'] : '/expenses/recurring')) ?>" data-expense-form>
  <?= Csrf::field() ?>
  <h2>Čo a kedy</h2>
  <label class="wide">Názov <input name="name" value="<?= $e($v('name')) ?>" placeholder="napr. Nájom skladu" required><?= $err('name') ?></label>
  <?php if (!$rec && count($companies) > 1): ?>
    <label>Firma (odberateľ)
      <select name="company_id" data-vat-company><?php foreach ($companies as $c): ?><option value="<?= (int)$c['id'] ?>" data-vat="<?= !empty($vat[(int)$c['id']]) ? 1 : 0 ?>"<?= (int)$v('company_id') === (int)$c['id'] ? ' selected' : '' ?>><?= $e($c['name']) ?></option><?php endforeach ?></select><?= $err('company_id') ?>
    </label>
  <?php else: ?>
    <input type="hidden" name="company_id" value="<?= (int)$v('company_id') ?>">
  <?php endif ?>
  <label>Ako často
    <select name="interval_months"><?php foreach (Expenses::INTERVALS as $k => $label): ?><option value="<?= (int)$k ?>"<?= (int)$v('interval_months') === $k ? ' selected' : '' ?>><?= $e($label) ?></option><?php endforeach ?></select><?= $err('interval_months') ?>
  </label>
  <label>Ďalší doklad dňa <input type="date" name="next_date" value="<?= $e($v('next_date')) ?>" required><?= $err('next_date') ?>
    <small class="muted">Ďalšie v rovnaký deň v mesiaci (posledný deň ostáva posledným). Dátum v minulosti vytvorí doklady hneď.</small></label>
  <label>Posledný doklad najneskôr <input type="date" name="end_date" value="<?= $e($v('end_date')) ?>"><?= $err('end_date') ?>
    <small class="muted">Prázdne = kým to nevypnete.</small></label>
  <label>Splatnosť (dní) <input name="due_days" value="<?= $e($v('due_days')) ?>" inputmode="numeric"><?= $err('due_days') ?></label>
  <label>Kategória
    <select name="category_id" required><option value="">— zvoľte —</option>
      <?php foreach ($kinds as $kind => $cats): ?><optgroup label="<?= $e($kind) ?>">
        <?php foreach ($cats as $c): ?><option value="<?= (int)$c['id'] ?>"<?= (int)$v('category_id') === (int)$c['id'] ? ' selected' : '' ?>><?= $e($c['name']) ?></option><?php endforeach ?>
      </optgroup><?php endforeach ?>
    </select><?= $err('category_id') ?>
  </label>
  <?php if ($shops): ?>
  <label>Stredisko (e-shop)
    <select name="shop_id"><option value="">celá firma</option>
      <?php foreach ($shops as $id => $name): ?><option value="<?= (int)$id ?>"<?= (int)$v('shop_id') === (int)$id ? ' selected' : '' ?>><?= $e($name) ?></option><?php endforeach ?>
    </select>
  </label>
  <?php endif ?>
  <label><span><input type="checkbox" name="active" value="1"<?= $v('active') ? ' checked' : '' ?>> Aktívny</span></label>

  <h2>Dodávateľ</h2>
  <label class="wide">Názov / meno <input name="supplier_name" value="<?= $e($v('supplier_name')) ?>" list="expense-suppliers" autocomplete="off" required><?= $err('supplier_name') ?></label>
  <datalist id="expense-suppliers"><?php foreach ($suppliers as $s): ?><option value="<?= $e($s['supplier_name']) ?>"><?php endforeach ?></datalist>
  <script type="application/json" id="expense-supplier-data"><?= json_encode(array_column($suppliers, null, 'supplier_name'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
  <label>IČO <input name="supplier_ico" value="<?= $e($v('supplier_ico')) ?>"> <button type="button" class="link rpo" data-rpo="supplier_" data-url="<?= $e($ctx->url('/lookup/ico')) ?>">Doplniť z registra</button></label>
  <label>DIČ <input name="supplier_dic" value="<?= $e($v('supplier_dic')) ?>"></label>
  <label>IČ DPH <input name="supplier_icdph" value="<?= $e($v('supplier_icdph')) ?>"></label>
  <label class="wide">Ulica a číslo <input name="supplier_street" value="<?= $e($v('supplier_street')) ?>"></label>
  <label>PSČ <input name="supplier_zip" value="<?= $e($v('supplier_zip')) ?>"></label>
  <label>Mesto <input name="supplier_city" value="<?= $e($v('supplier_city')) ?>"></label>
  <label>Krajina <input name="supplier_country" value="<?= $e($v('supplier_country')) ?>" maxlength="2" size="3"><?= $err('supplier_country') ?></label>
  <label>IBAN dodávateľa <input name="supplier_iban" value="<?= $e($v('supplier_iban')) ?>"><?= $err('supplier_iban') ?>
    <small class="muted">Podľa neho sa spáruje platba z výpisu.</small></label>
  <label>Variabilný symbol <input name="variable_symbol" value="<?= $e($v('variable_symbol')) ?>" maxlength="20"></label>

  <h2>Suma</h2>
  <label>Spolu (s DPH) <input name="total" class="num" value="<?= $e($v('total')) ?>" inputmode="decimal" required><?= $err('total') ?></label>
  <label>Z toho DPH <input name="total_vat" class="num" value="<?= $e($v('total_vat')) ?>" inputmode="decimal" placeholder="0"><?= $err('total_vat') ?>
    <small class="muted" data-vat-note>Len odpočítateľná DPH (platiteľ DPH). Neplatiteľ: 0.</small></label>
  <label>Mena <input name="currency" value="<?= $e($v('currency')) ?>" maxlength="3" size="4"><?= $err('currency') ?></label>
  <label>Úhrada
    <select name="payment_method"><?php foreach (Expenses::PAYMENT_METHODS as $k => $label): ?><option value="<?= $e($k) ?>"<?= $v('payment_method') === $k ? ' selected' : '' ?>><?= $e($label) ?></option><?php endforeach ?></select>
  </label>
  <label class="wide">Poznámka (pripíše sa ku každému dokladu) <input name="note" value="<?= $e($v('note')) ?>" maxlength="255"></label>
  <?php if ($canEdit): ?><p class="wide"><button class="primary">Uložiť</button></p><?php endif ?>
</form>

<?php if ($rec): ?>
<section class="card">
  <h2>Vytvorené doklady</h2>
  <?php if ($generated): ?>
  <table class="grid">
    <?php foreach ($generated as $g): ?>
      <tr><td><a href="<?= $e($ctx->url('/expenses/' . $g['id'])) ?>"><?= $e($f->date($g['delivery_date'])) ?></a></td>
        <td><?= $e($g['document_number'] ?: 'bez čísla') ?></td><td><?= $e(Expenses::STATUSES[$g['status']] ?? $g['status']) ?></td>
        <td class="num"><?= $e($f->money($g['total'], $g['currency'])) ?></td></tr>
    <?php endforeach ?>
  </table>
  <?php else: ?><p class="muted">Zatiaľ žiadne — prvý bude <?= $e($f->date($rec['next_date'])) ?>.</p><?php endif ?>
</section>
<?php endif ?>
