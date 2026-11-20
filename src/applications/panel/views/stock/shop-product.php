<?php use Panel\Shop\ShopProducts; use WebApplication\Csrf;
/** @var array $c, $shop, $p; bool $canEdit */
$ro = $canEdit ? '' : ' readonly';
$price = function ($v) { return $v === '' ? '' : rtrim(rtrim(number_format((float)$v, 4, ',', ''), '0'), ','); };
?>
<?= $view->partial('stock/tabs', ['tab' => 'shops']) ?>
<p><a href="<?= $e($ctx->url('/stock/shops/' . $c['id'] . '/' . $shop['id'] . '?q=' . rawurlencode($p['reference']))) ?>">← Sklad e-shopu <?= $e($shop['name']) ?></a></p>
<p class="muted">Kód <b><?= $e($p['reference']) ?></b><?= $p['ean'] ? ' · EAN ' . $e($p['ean']) : '' ?> · <?= $p['active'] ? 'aktívny' : 'neaktívny' ?> ·
  nastavenia sa ukladajú priamo do e-shopu <?= $e($shop['name']) ?>. Množstvá sa menia v zozname skladu.</p>
<form method="post" action="<?= $e($ctx->url('/stock/shops/' . $c['id'] . '/' . $shop['id'] . '/product/' . $p['id'])) ?>">
  <?= Csrf::field() ?>
  <section class="card form-grid">
    <h2>Produkt</h2>
    <?php foreach (ShopProducts::PRODUCT_FIELDS as $f => $label): $v = (string)$p[$f]; ?>
      <?php if ($f === 'low_stock_alert'): ?>
        <label class="check"><input type="checkbox" name="<?= $f ?>" value="1"<?= $v === '1' ? ' checked' : '' ?><?= $canEdit ? '' : ' disabled' ?>> <?= $e($label) ?></label>
      <?php elseif ($f === 'wholesale_price'): ?>
        <label><?= $e($label) ?> <input name="<?= $f ?>" value="<?= $e($price($v)) ?>" inputmode="decimal" class="num"<?= $ro ?>></label>
      <?php else: ?>
        <label class="<?= in_array($f, ShopProducts::LANG_FIELDS, true) ? 'wide' : '' ?>"><?= $e($label) ?> <input name="<?= $f ?>" value="<?= $e($v) ?>"<?= $ro ?><?= $f === 'low_stock_threshold' ? ' placeholder="nenastavené"' : '' ?>></label>
      <?php endif ?>
      <input type="hidden" name="orig_<?= $f ?>" value="<?= $e($f === 'wholesale_price' ? $price($v) : $v) ?>">
    <?php endforeach ?>
  </section>
  <?php if ($p['combinations']): ?>
  <section class="card">
    <h2>Kombinácie (<?= count($p['combinations']) ?>)</h2>
    <div class="scroll-x"><table class="grid compact">
      <thead><tr><th>Kombinácia</th><th>Kód</th><th class="num">Min. množstvo</th><th class="num">Nízky stav</th><th class="num">Nákupná cena</th></tr></thead>
      <tbody>
      <?php foreach ($p['combinations'] as $k): $n = 'combo[' . (int)$k['id'] . ']'; ?>
        <tr><td><?= $e($k['label'] ?: '#' . $k['id']) ?></td><td><?= $e($k['reference']) ?></td>
          <td><input name="<?= $n ?>[minimal_quantity]" value="<?= $e($k['minimal_quantity']) ?>" size="4" class="num"<?= $ro ?>>
            <input type="hidden" name="<?= $n ?>[orig_minimal_quantity]" value="<?= $e($k['minimal_quantity']) ?>"></td>
          <td><input name="<?= $n ?>[low_stock_threshold]" value="<?= $e($k['low_stock_threshold']) ?>" size="4" class="num" placeholder="—"<?= $ro ?>>
            <input type="hidden" name="<?= $n ?>[orig_low_stock_threshold]" value="<?= $e($k['low_stock_threshold']) ?>"></td>
          <td><input name="<?= $n ?>[wholesale_price]" value="<?= $e($price($k['wholesale_price'])) ?>" size="8" class="num"<?= $ro ?>>
            <input type="hidden" name="<?= $n ?>[orig_wholesale_price]" value="<?= $e($price($k['wholesale_price'])) ?>"></td></tr>
      <?php endforeach ?>
      </tbody>
    </table></div>
  </section>
  <?php endif ?>
  <?php if ($canEdit): ?><p class="actions"><button class="primary">Uložiť do e-shopu</button> <span class="muted small">uložia sa len zmenené hodnoty</span></p><?php endif ?>
</form>
