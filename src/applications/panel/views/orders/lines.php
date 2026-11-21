<?php use WebApplication\Csrf;
/** @var array $o, $carriers, $products, $errors, $posted; array|null $invoice; int $src; string|null $loadError; bool $canStock */
$cur = (string)$o['currency'];
$sent = !empty($posted);
$p = function ($k, $i = null, $d = '') use ($posted) {
    $v = $posted[$k] ?? null;
    if ($i !== null) {
        $v = is_array($v) ? ($v[$i] ?? null) : null;
    }
    return $v === null ? $d : (string)$v;
};
$dec = function ($v) { return number_format((float)$v, 2, ',', ''); };
$raw = function ($v) { return number_format((float)$v, 2, '.', ''); };
$paid = array_sum(array_map(function ($x) { return (float)$x['amount']; }, $o['payments']));
$carrierId = (int)$o['id_carrier'];
?>
<p><a href="<?= $e($ctx->orderUrl($src, (int)$o['id_order'])) ?>">← Objednávka <?= $e($o['reference']) ?></a></p>
<?php if ($loadError): ?><p class="flash err"><?= $e($loadError) ?></p><?php endif ?>
<?php if ($errors): ?><div class="flash err"><ul><?php foreach ($errors as $m): ?><li><?= $e($m) ?></li><?php endforeach ?></ul></div><?php endif ?>
<p class="muted">Zmeny sa uložia priamo do e-shopu (cez Webservice) — rovnako ako úprava objednávky v administrácii PrestaShopu:
  zmenia sa položky, doprava, zľava a súčty objednávky; zaplatená suma ostáva. Počet <b>0</b> = položku odstrániť.</p>
<?php if ($invoice): ?><p class="flash err">K objednávke už existuje <?= $invoice['status'] === 'issued' ? 'vystavená faktúra ' . $e($invoice['number'])
    . ' — úprava ju nezmení, opravte ju potom dobropisom a novou faktúrou' : 'koncept faktúry — úprava ho nezmení, upravte ho potom' ?>.</p><?php endif ?>
<?php if ($o['payments']): ?><p class="muted">Zaplatené: <b><?= $e($f->money($paid, $cur)) ?></b> — ak sa súčet zmení, panel upozorní na rozdiel.</p><?php endif ?>

<form method="post" action="<?= $e($ctx->orderUrl($src, (int)$o['id_order'], '/lines')) ?>">
  <?= Csrf::field() ?>
  <input type="hidden" name="orig_total" value="<?= $e($raw($o['total_paid_tax_incl'])) ?>">
  <input type="hidden" name="orig_carrier" value="<?= $carrierId ?>">
  <input type="hidden" name="orig_shipping" value="<?= $e($raw($o['total_shipping_tax_incl'])) ?>">
  <input type="hidden" name="orig_discount" value="<?= $e($raw($o['total_discounts_tax_incl'])) ?>">
  <h2>Položky</h2>
  <table class="grid">
    <thead><tr><th>Produkt</th><th>Kód</th><th class="num">Teraz</th><th class="num">Počet ks</th><th class="num">Cena/ks s DPH</th></tr></thead>
    <tbody>
    <?php foreach ($o['lines'] as $l): $id = (int)$l['id_order_detail']; ?>
      <tr><td><?= $e($l['product_name']) ?></td><td><?= $e($l['product_reference']) ?></td>
        <td class="num"><?= (int)$l['product_quantity'] ?> × <?= $e($f->money($l['unit_price_tax_incl'], $cur)) ?></td>
        <td class="num"><input type="hidden" name="orig_qty[<?= $id ?>]" value="<?= (int)$l['product_quantity'] ?>">
          <input type="hidden" name="orig_price[<?= $id ?>]" value="<?= $e($raw($l['unit_price_tax_incl'])) ?>">
          <input type="number" name="qty[<?= $id ?>]" min="0" max="99999" required value="<?= $e($p('qty', $id, (string)(int)$l['product_quantity'])) ?>" style="width: 5rem"></td>
        <td class="num"><input name="price[<?= $id ?>]" inputmode="decimal" required value="<?= $e($p('price', $id, $dec($l['unit_price_tax_incl']))) ?>" style="width: 7rem"></td></tr>
    <?php endforeach ?>
    </tbody>
  </table>

  <h2>Pridať produkt</h2>
  <datalist id="shop-products">
    <?php foreach ($products as $r): ?>
      <option value="<?= $e(($r['reference'] !== '' ? $r['reference'] . ' — ' : '') . $r['name'] . ' (sklad ' . $r['quantity'] . ') [' . $r['product_id'] . ':' . $r['combination_id'] . ']') ?>"></option>
    <?php endforeach ?>
  </datalist>
  <table class="grid">
    <thead><tr><th>Produkt (začnite písať názov alebo kód)</th><th class="num">Počet ks</th><th class="num">Cena/ks s DPH</th></tr></thead>
    <tbody>
    <?php for ($i = 0; $i < 3; $i++): ?>
      <tr><td><input name="add_product[<?= $i ?>]" list="shop-products" value="<?= $e($p('add_product', $i)) ?>" style="width: 100%" autocomplete="off"></td>
        <td class="num"><input type="number" name="add_qty[<?= $i ?>]" min="1" max="99999" value="<?= $e($p('add_qty', $i, '1')) ?>" style="width: 5rem"></td>
        <td class="num"><input name="add_price[<?= $i ?>]" inputmode="decimal" value="<?= $e($p('add_price', $i)) ?>" placeholder="cena v e-shope" style="width: 7rem"></td></tr>
    <?php endfor ?>
    </tbody>
  </table>
  <p class="muted small">Prázdna cena = aktuálna cena produktu v e-shope (aj s akciou) pre krajinu doručenia.</p>

  <div class="card form-grid" style="margin-top: 1rem">
    <label>Dopravca
      <select name="carrier">
        <?php if (!isset($carriers[$carrierId])): ?><option value="<?= $carrierId ?>" selected><?= $e($o['carrier'] ?: 'dopravca ' . $carrierId) ?></option><?php endif ?>
        <?php foreach ($carriers as $cid => $c): ?>
          <option value="<?= (int)$cid ?>"<?= (int)$p('carrier', null, (string)$carrierId) === (int)$cid ? ' selected' : '' ?>><?= $e($c['name'] . ($c['active'] ? '' : ' (neaktívny)')) ?></option>
        <?php endforeach ?>
      </select></label>
    <label>Cena dopravy s DPH <input name="shipping_price" inputmode="decimal" required value="<?= $e($p('shipping_price', null, $dec($o['total_shipping_tax_incl']))) ?>"></label>
    <label>Zľava objednávky <input name="discount_value" inputmode="decimal" required value="<?= $e($p('discount_value', null, $dec($o['total_discounts_tax_incl']))) ?>"></label>
    <label>Zľava v
      <select name="discount_mode">
        <option value="amount"<?= $p('discount_mode') === 'percent' ? '' : ' selected' ?>>€ s DPH</option>
        <option value="percent"<?= $p('discount_mode') === 'percent' ? ' selected' : '' ?>>% z ceny tovaru</option>
      </select></label>
    <p class="wide muted small">Zľava = celková zľava objednávky, <b>nahrádza</b> súčasnú (teraz −<?= $e($f->money($o['total_discounts_tax_incl'], $cur)) ?>);
      0 = bez zľavy. Percento sa počíta z ceny tovaru s DPH po úpravách vyššie (bez dopravy). Pri zmene položiek sa zľava
      sama neprepočíta — ak má byť percentuálna, zadajte ju tu znova. V administrácii e-shopu bude v súčtoch objednávky
      (nie ako pomenovaný zľavový kupón). Faktúra z objednávky ju preberie.</p>
    <label class="check wide"><input type="checkbox" name="stock" value="1"<?= (!$sent || $p('stock') !== '') && $canStock ? ' checked' : '' ?><?= $canStock ? '' : ' disabled' ?>>
      Upraviť aj sklad v e-shope o rozdiel (odobraté kusy vrátiť na sklad, pridané odpísať)<?= $canStock ? '' : ' — treba právo Sklad — úpravy' ?></label>
    <p class="wide"><button class="primary"<?= $loadError ? ' disabled' : '' ?> data-confirm="Uložiť zmeny objednávky do e-shopu?">Uložiť do e-shopu</button></p>
  </div>
</form>
