<?php use Panel\Shop\ShopStock; use WebApplication\Csrf;
/** @var array $c, $rows, $filters; array|null $shop; int $total, $page, $pages; string $error; bool $canEdit */
$qs = function (array $change) use ($filters) {
    return '?' . http_build_query(array_filter(array_merge($filters, $change), function ($v) { return $v !== '' && $v !== false && $v !== 0; }));
};
?>
<?= $view->partial('stock/tabs', ['tab' => 'shops']) ?>
<p><a href="<?= $e($ctx->url('/stock/shops')) ?>">← E-shopy</a></p>
<?php if ($error): ?><p class="flash err"><?= $e($error) ?></p><?php endif ?>
<?php if ($shop): ?>
<p class="muted"><?= $e($c['name']) ?> · <?= $e($shop['name']) ?> · skupina <?= $e($shop['group_name']) ?><?= $shop['share_stock'] ? ' — <b>spoločný sklad skupiny</b> (zmena platí pre všetky jej e-shopy)' : '' ?></p>
<form class="filters" method="get" action="">
  <input name="q" value="<?= $e($filters['q']) ?>" placeholder="Názov, kód, EAN">
  <label class="check"><input type="checkbox" name="inactive" value="1"<?= $filters['inactive'] ? ' checked' : '' ?>> aj neaktívne produkty</label>
  <button class="primary">Hľadať</button>
</form>
<p class="muted"><?= (int)$total ?> riadkov (produkty bez kombinácií a jednotlivé kombinácie)</p>
<form method="post" action="<?= $e($ctx->url('/stock/shops/' . $c['id'] . '/' . $shop['id'])) ?>" data-only-changed>
  <?= Csrf::field() ?>
  <input type="hidden" name="q" value="<?= $e($filters['q']) ?>"><input type="hidden" name="inactive" value="<?= $filters['inactive'] ? '1' : '' ?>">
  <input type="hidden" name="page" value="<?= (int)$page ?>">
  <div class="scroll-x"><table class="grid compact">
    <thead><tr><th>Produkt / kombinácia</th><th>Kód</th><th>EAN</th><th class="num">Množstvo</th><th>Pri vypredaní</th><th>Umiestnenie</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $n = 'row[' . (int)$r['stock_id'] . ']'; ?>
      <tr class="<?= $r['active'] ? '' : 'inactive' ?>" data-row>
        <td><a href="<?= $e($ctx->url('/stock/shops/' . $c['id'] . '/' . $shop['id'] . '/product/' . $r['product_id'])) ?>" title="Nastavenia skladu produktu"><?= $e($r['name'] !== '' ? $r['name'] : '(bez názvu #' . $r['product_id'] . ')') ?></a><?= $r['combination_id'] ? ' <span class="badge">kombinácia</span>' : '' ?>
          <input type="hidden" name="<?= $n ?>[name]" value="<?= $e($r['name']) ?>"></td>
        <td><?= $e($r['reference']) ?></td><td><?= $e($r['ean']) ?></td>
        <td><input name="<?= $n ?>[quantity]" value="<?= (int)$r['quantity'] ?>" size="5" class="num" inputmode="numeric"<?= $canEdit ? '' : ' readonly' ?>>
          <input type="hidden" name="<?= $n ?>[orig_quantity]" value="<?= (int)$r['quantity'] ?>"></td>
        <td><select name="<?= $n ?>[out_of_stock]"<?= $canEdit ? '' : ' disabled' ?>>
            <?php foreach (ShopStock::OUT_OF_STOCK as $k => $label): ?><option value="<?= (int)$k ?>"<?= (int)$r['out_of_stock'] === $k ? ' selected' : '' ?>><?= $e($label) ?></option><?php endforeach ?>
          </select><input type="hidden" name="<?= $n ?>[orig_out_of_stock]" value="<?= (int)$r['out_of_stock'] ?>"></td>
        <td><input name="<?= $n ?>[location]" value="<?= $e($r['location']) ?>" size="10"<?= $canEdit ? '' : ' readonly' ?>>
          <input type="hidden" name="<?= $n ?>[orig_location]" value="<?= $e($r['location']) ?>"></td>
      </tr>
    <?php endforeach ?>
    <?php if (!$rows): ?><tr><td colspan="6" class="muted">Nič sa nenašlo.</td></tr><?php endif ?>
    </tbody>
  </table></div>
  <?php if ($pages > 1): ?>
    <nav class="pager">
      <?php if ($page > 1): ?><a href="<?= $e($qs(['page' => $page - 1])) ?>">← Predchádzajúca</a><?php endif ?>
      <span>Strana <?= (int)$page ?> z <?= (int)$pages ?></span>
      <?php if ($page < $pages): ?><a href="<?= $e($qs(['page' => $page + 1])) ?>">Ďalšia →</a><?php endif ?>
    </nav>
  <?php endif ?>
  <?php if ($canEdit && $rows): ?><p class="actions"><button class="primary">Uložiť zmeny do e-shopu</button> <span class="muted small">uložia sa len zmenené riadky</span></p><?php endif ?>
</form>
<?php endif ?>
