<?php use Panel\Expenses\Expenses; use WebApplication\Csrf;
/** @var array|null $exp, $duplicate; array $values, $errors, $companies, $categories, $shops, $vat, $suppliers */
$err = function ($k) use ($errors, $e) { return isset($errors[$k]) ? '<span class="field-err">' . $e($errors[$k]) . '</span>' : ''; };
$v = function ($k) use ($values) { return $values[$k] ?? ''; };
$action = $exp ? '/expenses/' . $exp['id'] : '/expenses';
$kinds = [];
foreach ($categories as $c) {
    $kinds[Expenses::KINDS[$c['kind']] ?? $c['kind']][] = $c;
}
?>
<p><a href="<?= $e($ctx->url($exp ? '/expenses/' . $exp['id'] : '/expenses')) ?>">← <?= $exp ? 'Doklad' : 'Náklady' ?></a></p>
<?php if ($exp && $exp['accounting_package_id']): ?>
  <p class="flash warn">Doklad už bol odoslaný účtovníkovi v mesačnom balíku. Zmeny sa mu automaticky nepošlú — dajte mu vedieť.</p>
<?php endif ?>
<?php if ($duplicate): ?>
  <div class="flash warn">Doklad s rovnakým číslom od tohto dodávateľa už je zadaný:
    <a href="<?= $e($ctx->url('/expenses/' . $duplicate['id'])) ?>" target="_blank">dodanie <?= $e($f->date($duplicate['delivery_date'])) ?>, <?= $e($f->money($duplicate['total'])) ?></a>.
    Ak je to naozaj iný doklad, zaškrtnite „Je to iný doklad“ dole a uložte znova.</div>
<?php endif ?>
<form class="card form-grid" method="post" action="<?= $e($ctx->url($action)) ?>" enctype="multipart/form-data" data-expense-form>
  <?= Csrf::field() ?>
  <h2>Doklad</h2>
  <?php if (!$exp && count($companies) > 1): ?>
    <label>Firma (odberateľ)
      <select name="company_id" data-vat-company><?php foreach ($companies as $c): ?><option value="<?= (int)$c['id'] ?>" data-vat="<?= !empty($vat[(int)$c['id']]) ? 1 : 0 ?>"<?= (int)$v('company_id') === (int)$c['id'] ? ' selected' : '' ?>><?= $e($c['name']) ?></option><?php endforeach ?></select><?= $err('company_id') ?>
    </label>
  <?php else: ?>
    <input type="hidden" name="company_id" value="<?= (int)$v('company_id') ?>">
  <?php endif ?>
  <label>Typ dokladu
    <select name="doc_type"><?php foreach (Expenses::DOC_TYPES as $k => $label): ?><option value="<?= $e($k) ?>"<?= $v('doc_type') === $k ? ' selected' : '' ?>><?= $e($label) ?></option><?php endforeach ?></select>
  </label>
  <label>Číslo dokladu <input name="document_number" value="<?= $e($v('document_number')) ?>" maxlength="64"><?= $err('document_number') ?></label>
  <label>Variabilný symbol <input name="variable_symbol" value="<?= $e($v('variable_symbol')) ?>" maxlength="20"></label>
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

  <h2>Dodávateľ</h2>
  <label class="wide">Názov / meno <input name="supplier_name" value="<?= $e($v('supplier_name')) ?>" list="expense-suppliers" autocomplete="off" required><?= $err('supplier_name') ?>
    <small class="muted">Dodávateľa, od ktorého už doklad máte, stačí vybrať — ostatné údaje sa doplnia.</small></label>
  <datalist id="expense-suppliers"><?php foreach ($suppliers as $s): ?><option value="<?= $e($s['supplier_name']) ?>"><?php endforeach ?></datalist>
  <script type="application/json" id="expense-supplier-data"><?= json_encode(array_column($suppliers, null, 'supplier_name'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
  <label>IČO <input name="supplier_ico" value="<?= $e($v('supplier_ico')) ?>"> <button type="button" class="link rpo" data-rpo="supplier_" data-url="<?= $e($ctx->url('/lookup/ico')) ?>">Doplniť z registra</button></label>
  <label>DIČ <input name="supplier_dic" value="<?= $e($v('supplier_dic')) ?>"></label>
  <label>IČ DPH <input name="supplier_icdph" value="<?= $e($v('supplier_icdph')) ?>"></label>
  <label class="wide">Ulica a číslo <input name="supplier_street" value="<?= $e($v('supplier_street')) ?>"></label>
  <label>PSČ <input name="supplier_zip" value="<?= $e($v('supplier_zip')) ?>"></label>
  <label>Mesto <input name="supplier_city" value="<?= $e($v('supplier_city')) ?>"></label>
  <label>Krajina <input name="supplier_country" value="<?= $e($v('supplier_country')) ?>" maxlength="2" size="3"><?= $err('supplier_country') ?></label>
  <label>IBAN dodávateľa <input name="supplier_iban" value="<?= $e($v('supplier_iban')) ?>" placeholder="na úhradu"><?= $err('supplier_iban') ?></label>

  <h2>Dátumy a suma</h2>
  <label>Vystavený <input type="date" name="issue_date" value="<?= $e($v('issue_date')) ?>"><?= $err('issue_date') ?></label>
  <label>Dodanie <input type="date" name="delivery_date" value="<?= $e($v('delivery_date')) ?>" required><?= $err('delivery_date') ?>
    <small class="muted">Mesiac, do ktorého náklad patrí.</small></label>
  <label>Splatnosť <input type="date" name="due_date" value="<?= $e($v('due_date')) ?>"><?= $err('due_date') ?></label>
  <label>Spolu (s DPH) <input name="total" class="num" value="<?= $e($v('total')) ?>" inputmode="decimal" required><?= $err('total') ?>
    <?php if ($v('doc_type') === 'credit_note'): ?><small class="muted">Dobropis: suma ako na doklade, náklady zníži.</small><?php endif ?></label>
  <label data-vat-field>Z toho DPH <input name="total_vat" class="num" value="<?= $e($v('total_vat')) ?>" inputmode="decimal" placeholder="0"><?= $err('total_vat') ?>
    <small class="muted" data-vat-note>Len odpočítateľná DPH (platiteľ DPH). Neplatiteľ: 0 — celá suma je náklad.</small></label>
  <label>Mena <input name="currency" value="<?= $e($v('currency')) ?>" maxlength="3" size="4"><?= $err('currency') ?></label>
  <label>Úhrada
    <select name="payment_method"><?php foreach (Expenses::PAYMENT_METHODS as $k => $label): ?><option value="<?= $e($k) ?>"<?= $v('payment_method') === $k ? ' selected' : '' ?>><?= $e($label) ?></option><?php endforeach ?></select>
    <small class="muted">Kartou a v hotovosti = uhradené dňom vystavenia.</small>
  </label>
  <?php if (!$exp): ?>
  <label>Už uhradené dňa <input type="date" name="paid_on" value="<?= $e($v('paid_on')) ?>"><?= $err('paid_on') ?></label>
  <?php endif ?>

  <h2>Súbor a poznámka</h2>
  <label class="wide">PDF alebo fotka dokladu <input type="file" name="file" accept="application/pdf,image/*,.xml"><?= $err('file') ?>
    <small class="muted">Najviac 10 MB. Na mobile sa dá doklad rovno odfotiť. Ďalšie súbory pridáte v detaile dokladu.</small></label>
  <label class="wide">Poznámka <textarea name="note" rows="2"><?= $e($v('note')) ?></textarea></label>
  <?php if ($duplicate): ?><label class="wide"><span><input type="checkbox" name="dup_ok" value="1"> Je to iný doklad (uložiť aj tak)</span></label><?php endif ?>
  <p class="wide actions">
    <button class="primary">Uložiť</button>
    <?php if (!$exp): ?><button name="next" value="new">Uložiť a zadať ďalší</button><?php endif ?>
  </p>
</form>
