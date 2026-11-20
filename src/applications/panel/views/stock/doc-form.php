<?php use Panel\Invoicing\Numbering; use WebApplication\Csrf;
/** @var string $type; array $values, $errors, $companies, $warehouses, $items, $lines */
$err = function ($k) use ($errors, $e) { return isset($errors[$k]) ? '<p class="flash err">' . $e($errors[$k]) . '</p>' : ''; };
$receipt = $type === 'receipt';
$adjust = $type === 'adjust';
$row = function (array $l) use ($e, $items, $receipt, $adjust) { ?>
  <tr>
    <td><select name="line_item[]" class="w-name"><option value="0">— vyberte kartu —</option>
      <?php foreach ($items as $i): ?><option value="<?= (int)$i['id'] ?>"<?= (int)$l['item_id'] === (int)$i['id'] ? ' selected' : '' ?>><?= $e($i['sku'] . ' — ' . $i['name']) ?></option><?php endforeach ?>
    </select></td>
    <td><input name="line_qty[]" value="<?= $e($l['qty']) ?>" size="8" class="num" inputmode="decimal" placeholder="<?= $adjust ? 'zistené' : '' ?>"></td>
    <?php if ($receipt || $adjust): ?><td><input name="line_cost[]" value="<?= $e($l['cost']) ?>" size="9" class="num" inputmode="decimal" placeholder="<?= $adjust ? 'pri prebytku' : '' ?>"></td><?php endif ?>
    <?php if ($receipt): ?>
      <td><input name="line_lot[]" value="<?= $e($l['lot']) ?>" size="10"></td>
      <td><input type="date" name="line_exp[]" value="<?= $e($l['exp']) ?>"></td>
    <?php endif ?>
    <td><button type="button" class="link-danger" data-remove-row title="Odstrániť">✕</button></td>
  </tr>
<?php };
?>
<?= $view->partial('stock/tabs', ['tab' => 'docs']) ?>
<?= $err('doc') ?><?= $err('lines') ?>
<?php if (count($companies) > 1): ?>
  <p class="muted small">Firma: <?php foreach ($companies as $c): ?>
    <?php if ((int)$c['id'] === (int)$values['company_id']): ?><b><?= $e($c['name']) ?></b><?php else: ?><a href="<?= $e($ctx->url('/stock/docs/new?type=' . $type . '&company=' . $c['id'])) ?>"><?= $e($c['name']) ?></a><?php endif ?> ·
  <?php endforeach ?></p>
<?php endif ?>
<?php if (!$warehouses): ?><p class="flash err">Firma nemá žiadny aktívny sklad — <a href="<?= $e($ctx->url('/stock/warehouses/new')) ?>">založte ho</a>.</p><?php endif ?>
<?php if (!$items): ?><p class="flash err">Firma nemá žiadne skladové karty — <a href="<?= $e($ctx->url('/stock/items/new')) ?>">založte kartu</a>.</p><?php endif ?>
<form method="post" action="<?= $e($ctx->url('/stock/docs')) ?>">
  <?= Csrf::field() ?>
  <input type="hidden" name="doc_type" value="<?= $e($type) ?>">
  <input type="hidden" name="company_id" value="<?= (int)$values['company_id'] ?>">
  <section class="card form-grid">
    <h2><?= $e(Numbering::STOCK_TYPES[$type]) ?></h2>
    <label>Dátum <input type="date" name="doc_date" value="<?= $e($values['doc_date']) ?>" required></label>
    <label><?= $type === 'transfer' ? 'Zo skladu' : 'Sklad' ?>
      <select name="warehouse_id"><?php foreach ($warehouses as $w): ?><option value="<?= (int)$w['id'] ?>"<?= (int)$values['warehouse_id'] === (int)$w['id'] ? ' selected' : '' ?>><?= $e($w['name']) ?></option><?php endforeach ?></select></label>
    <?php if ($type === 'transfer'): ?>
      <label>Do skladu
        <select name="target_warehouse_id"><?php foreach ($warehouses as $w): ?><option value="<?= (int)$w['id'] ?>"<?= (int)$values['target_warehouse_id'] === (int)$w['id'] ? ' selected' : '' ?>><?= $e($w['name']) ?></option><?php endforeach ?></select></label>
    <?php endif ?>
    <?php if (!$adjust): ?>
      <label><?= $receipt ? 'Dodávateľ' : 'Príjemca / účel' ?> <input name="partner" value="<?= $e($values['partner']) ?>"></label>
      <label><?= $receipt ? 'Číslo faktúry dodávateľa' : 'Odkaz (objednávka …)' ?> <input name="reference" value="<?= $e($values['reference']) ?>"></label>
    <?php endif ?>
    <label class="wide">Poznámka <input name="note" value="<?= $e($values['note']) ?>"></label>
    <p class="wide muted small"><?php
      echo $receipt ? 'Každý riadok vytvorí novú dávku s nákupnou cenou (bez DPH).' : '';
      echo $type === 'issue' ? 'Vydá sa od najstarších dávok (FIFO); hodnota výdaja = ich nákupné ceny. Viac, ako je na sklade, sa vydať nedá.' : '';
      echo $type === 'transfer' ? 'Dávky sa presunú s pôvodnou nákupnou cenou a dátumom príjmu.' : '';
      echo $adjust ? 'Zadajte zistené množstvo. Manko sa odpíše od najstarších dávok, prebytok sa prijme za zadanú cenu (prázdna = posledná nákupná cena).' : '';
    ?></p>
  </section>
  <section class="card">
    <div class="scroll-x"><table class="grid compact">
      <thead><tr><th>Karta</th><th class="num"><?= $adjust ? 'Zistené množstvo' : 'Množstvo' ?></th>
        <?php if ($receipt || $adjust): ?><th class="num">Nákupná cena / MJ</th><?php endif ?>
        <?php if ($receipt): ?><th>Šarža</th><th>Spotreba do</th><?php endif ?><th></th></tr></thead>
      <tbody id="stock-rows"><?php foreach ($lines as $l) { $row($l); } ?></tbody>
    </table></div>
    <template id="stock-rows-tpl"><?php $row(['item_id' => 0, 'qty' => '', 'cost' => '', 'lot' => '', 'exp' => '']); ?></template>
    <p><button type="button" data-add-row="stock-rows">+ Položka</button></p>
  </section>
  <p class="actions"><button class="primary" data-confirm="Zaúčtovať doklad? Po zaúčtovaní sa nedá meniť.">Zaúčtovať</button></p>
</form>
