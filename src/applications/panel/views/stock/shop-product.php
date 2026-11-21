<?php use Panel\Shop\ShopProducts; use WebApplication\Csrf;
/** @var array $c, $shop, $p; array|null $sales; string $salesError; bool $canEdit */
$ro = $canEdit ? '' : ' readonly';
$price = function ($v) { return $v === '' ? '' : rtrim(rtrim(number_format((float)$v, 4, ',', ''), '0'), ','); };
?>
<?= $view->partial('stock/tabs', ['tab' => 'shops']) ?>
<p><a href="<?= $e($ctx->url('/eshops/' . $c['id'] . '/' . $shop['id'] . '?q=' . rawurlencode($p['reference']))) ?>">← Sklad e-shopu <?= $e($shop['name']) ?></a></p>
<p class="muted">Kód <b><?= $e($p['reference']) ?></b><?= $p['ean'] ? ' · EAN ' . $e($p['ean']) : '' ?> · <?= $p['active'] ? 'aktívny' : 'neaktívny' ?> ·
  nastavenia sa ukladajú priamo do e-shopu <?= $e($shop['name']) ?>. Množstvá sa menia v zozname skladu.</p>
<form method="post" action="<?= $e($ctx->url('/eshops/' . $c['id'] . '/' . $shop['id'] . '/product/' . $p['id'])) ?>">
  <?= Csrf::field() ?>
  <section class="card form-grid">
    <h2>Produkt</h2>
    <?php foreach (ShopProducts::PRODUCT_FIELDS as $f => $label): $v = (string)$p[$f]; ?>
      <?php if ($f === 'active'): ?>
        <label class="check"><input type="checkbox" name="<?= $f ?>" value="1"<?= $v === '1' ? ' checked' : '' ?><?= $canEdit ? '' : ' disabled' ?>> <?= $e($label) ?></label>
      <?php elseif ($f === 'price'): ?>
        <label><?= $e($label) ?> <input name="<?= $f ?>" value="<?= $e($price($v)) ?>" inputmode="decimal" class="num"<?= $ro ?>>
          <small class="muted"><?= $p['tax_rate'] > 0 ? 's DPH ' . $e(number_format((float)$v * (1 + $p['tax_rate'] / 100), 2, ',', ' ')) . ' € (' . $e(rtrim(rtrim(number_format($p['tax_rate'], 2, ',', ''), '0'), ',')) . ' %)' : 'bez DPH = konečná cena (produkt nemá daň)' ?></small></label>
      <?php elseif ($f === 'low_stock_alert'): ?>
        <label class="check"><input type="checkbox" name="<?= $f ?>" value="1"<?= $v === '1' ? ' checked' : '' ?><?= $canEdit ? '' : ' disabled' ?>> <?= $e($label) ?></label>
      <?php elseif ($f === 'wholesale_price'): ?>
        <label><?= $e($label) ?> <input name="<?= $f ?>" value="<?= $e($price($v)) ?>" inputmode="decimal" class="num"<?= $ro ?>></label>
      <?php else: ?>
        <label class="<?= in_array($f, ShopProducts::LANG_FIELDS, true) ? 'wide' : '' ?>"><?= $e($label) ?> <input name="<?= $f ?>" value="<?= $e($v) ?>"<?= $ro ?><?= $f === 'low_stock_threshold' ? ' placeholder="nenastavené"' : '' ?>></label>
      <?php endif ?>
      <input type="hidden" name="orig_<?= $f ?>" value="<?= $e(in_array($f, ['wholesale_price', 'price'], true) ? $price($v) : $v) ?>">
    <?php endforeach ?>
  </section>
  <?php if ($p['combinations']): ?>
  <section class="card">
    <h2>Kombinácie (<?= count($p['combinations']) ?>)</h2>
    <div class="scroll-x"><table class="grid compact">
      <thead><tr><th>Kombinácia</th><th>Kód</th><th class="num">Min. množstvo</th><th class="num">Nízky stav</th><th class="num">Nákupná cena</th><th class="num" title="o koľko je kombinácia drahšia (záporné = lacnejšia), bez DPH">Príplatok k cene</th></tr></thead>
      <tbody>
      <?php foreach ($p['combinations'] as $k): $n = 'combo[' . (int)$k['id'] . ']'; ?>
        <tr><td><?= $e($k['label'] ?: '#' . $k['id']) ?></td><td><?= $e($k['reference']) ?></td>
          <td><input name="<?= $n ?>[minimal_quantity]" value="<?= $e($k['minimal_quantity']) ?>" size="4" class="num"<?= $ro ?>>
            <input type="hidden" name="<?= $n ?>[orig_minimal_quantity]" value="<?= $e($k['minimal_quantity']) ?>"></td>
          <td><input name="<?= $n ?>[low_stock_threshold]" value="<?= $e($k['low_stock_threshold']) ?>" size="4" class="num" placeholder="—"<?= $ro ?>>
            <input type="hidden" name="<?= $n ?>[orig_low_stock_threshold]" value="<?= $e($k['low_stock_threshold']) ?>"></td>
          <td><input name="<?= $n ?>[wholesale_price]" value="<?= $e($price($k['wholesale_price'])) ?>" size="8" class="num"<?= $ro ?>>
            <input type="hidden" name="<?= $n ?>[orig_wholesale_price]" value="<?= $e($price($k['wholesale_price'])) ?>"></td>
          <td><input name="<?= $n ?>[price]" value="<?= $e($price($k['price'])) ?>" size="8" class="num"<?= $ro ?>>
            <input type="hidden" name="<?= $n ?>[orig_price]" value="<?= $e($price($k['price'])) ?>"></td></tr>
      <?php endforeach ?>
      </tbody>
    </table></div>
  </section>
  <?php endif ?>
  <?php if ($canEdit): ?><p class="actions"><button class="primary">Uložiť do e-shopu</button> <span class="muted small">uložia sa len zmenené hodnoty</span></p><?php endif ?>
</form>

<section class="card" id="sales">
  <h2>Akcie a zľavy</h2>
  <?php if ($sales === null): ?>
    <p class="muted">Akcie sa nedajú načítať: <?= $e($salesError) ?> (kľúč Webservice potrebuje specific_prices GET, POST, DELETE).</p>
  <?php else: ?>
    <?php if (!$sales): ?><p class="muted">Produkt nemá akciu.</p><?php else: ?>
    <table class="grid compact">
      <thead><tr><th>Zľava</th><th>Kombinácia</th><th>Od</th><th>Do</th><th class="num">Od ks</th><th></th></tr></thead>
      <tbody>
      <?php $combos = array_column($p['combinations'], 'label', 'id'); foreach ($sales as $sp):
          $d = function ($x) { return $x === '' || strpos($x, '0000') === 0 ? '' : date('j. n. Y', strtotime($x)); }; ?>
        <tr><td><b><?= $sp['type'] === 'percentage' ? '−' . $e(rtrim(rtrim(number_format($sp['reduction'] * 100, 2, ',', ''), '0'), ',')) . ' %'
              : '−' . $e(number_format($sp['reduction'], 2, ',', ' ')) . ' € ' . ($sp['tax'] ? 's DPH' : 'bez DPH') ?></b>
            <?= $sp['price'] >= 0 ? ' <small class="muted">(pevná cena ' . $e(number_format($sp['price'], 2, ',', ' ')) . ')</small>' : '' ?>
            <?= $sp['limited'] ? ' <small class="muted">(len pre skupinu / zákazníka / krajinu — nastavené v e-shope)</small>' : '' ?>
            <?= $sp['all_shops'] ? ' <small class="muted">(všetky e-shopy)</small>' : '' ?></td>
          <td><?= $sp['combination'] ? $e($combos[$sp['combination']] ?? '#' . $sp['combination']) : 'všetky' ?></td>
          <td><?= $e($d($sp['from'])) ?: '—' ?></td><td><?= $e($d($sp['to'])) ?: 'bez konca' ?></td><td class="num"><?= (int)$sp['from_quantity'] ?></td>
          <td><?php if ($canEdit): ?><form method="post" action="<?= $e($ctx->url('/eshops/' . $c['id'] . '/' . $shop['id'] . '/product/' . $p['id'] . '/sale/' . $sp['id'] . '/delete')) ?>" class="inline">
            <?= Csrf::field() ?><button class="link" data-confirm="Zrušiť túto akciu v e-shope?">zrušiť</button></form><?php endif ?></td></tr>
      <?php endforeach ?>
      </tbody>
    </table>
    <?php endif ?>
    <?php if ($canEdit): ?>
    <form method="post" action="<?= $e($ctx->url('/eshops/' . $c['id'] . '/' . $shop['id'] . '/product/' . $p['id'] . '/sale')) ?>" class="form-grid" style="margin-top: 1rem">
      <?= Csrf::field() ?>
      <label>Zľava <input name="value" inputmode="decimal" class="num" required placeholder="napr. 10"></label>
      <label>Druh <select name="type"><option value="percentage">% z ceny</option><option value="amount">€ z ceny</option></select></label>
      <label>Suma v € je <select name="tax"><option value="1">s DPH</option><option value="0">bez DPH</option></select></label>
      <?php if ($p['combinations']): ?>
      <label>Kombinácia <select name="combination"><option value="0">všetky</option>
        <?php foreach ($p['combinations'] as $k): ?><option value="<?= (int)$k['id'] ?>"><?= $e($k['label'] ?: '#' . $k['id']) ?></option><?php endforeach ?></select></label>
      <?php endif ?>
      <label>Od <input type="date" name="from"></label>
      <label>Do <input type="date" name="to"></label>
      <label>Od počtu kusov <input name="from_quantity" value="1" class="num" inputmode="numeric"></label>
      <p class="wide"><button class="primary">Pridať akciu do e-shopu</button> <span class="muted small">platí pre všetkých zákazníkov tohto e-shopu; prázdne dátumy = hneď a bez konca</span></p>
    </form>
    <?php endif ?>
  <?php endif ?>
</section>
