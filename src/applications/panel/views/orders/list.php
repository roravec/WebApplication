<?php /** @var array $filters, $shops, $states, $rows; Panel\Shop\OrderSource[] $sources; string|null $error; int $total, $page, $pages */
$qs = function (array $change) use ($filters) {
    $all = array_merge($filters, $change);
    return '?' . http_build_query(array_filter($all, function ($v) { return $v !== '' && $v !== 0 && $v !== null; }));
};
?>
<form class="filters" method="get" action="<?= $e($ctx->url('/orders')) ?>">
  <?php if (count($sources) > 1): ?>
    <select name="src" onchange="this.form.shop.value = ''; this.form.state.value = ''; this.form.submit()" title="Odkiaľ sa objednávky čítajú">
      <?php foreach ($sources as $sid => $s): ?>
        <option value="<?= (int)$sid ?>"<?= $filters['src'] === (int)$sid ? ' selected' : '' ?>><?= $e($s->label()) ?></option>
      <?php endforeach ?>
    </select>
  <?php else: ?><input type="hidden" name="src" value="<?= (int)$filters['src'] ?>"><?php endif ?>
  <input name="q" value="<?= $e($filters['q']) ?>" placeholder="Číslo, zákazník, e-mail, firma">
  <select name="shop"><option value="">Všetky e-shopy</option>
    <?php foreach ($shops as $s): ?>
      <option value="<?= (int)$s['id_shop'] ?>"<?= $filters['shop'] === (int)$s['id_shop'] ? ' selected' : '' ?>><?= $e($s['name']) ?></option>
    <?php endforeach ?>
  </select>
  <select name="state"><option value="">Všetky stavy</option>
    <?php foreach ($states as $s): ?>
      <option value="<?= (int)$s['id_order_state'] ?>"<?= $filters['state'] === (int)$s['id_order_state'] ? ' selected' : '' ?>><?= $e($s['name']) ?></option>
    <?php endforeach ?>
  </select>
  <label>od <input type="date" name="from" value="<?= $e($filters['from']) ?>"></label>
  <label>do <input type="date" name="to" value="<?= $e($filters['to']) ?>"></label>
  <button class="primary">Hľadať</button>
  <a href="<?= $e($ctx->url('/orders' . ($filters['src'] ? '?src=' . $filters['src'] : ''))) ?>">Zrušiť filter</a>
  <a class="button push" href="<?= $e($ctx->url('/shipping' . ($filters['src'] ? '?src=' . $filters['src'] : ''))) ?>">Expedícia</a>
</form>
<?php if ($error !== null): ?><p class="flash err">E-shop neodpovedá: <?= $e($error) ?></p><?php endif ?>
<p class="muted"><?= (int)$total ?> objednávok<?= $filters['src'] ? ' · číta sa cez Webservice e-shopu' : '' ?></p>
<?= $view->partial('orders/table', ['rows' => $rows]) ?>
<?php if ($pages > 1): ?>
  <nav class="pager">
    <?php if ($page > 1): ?><a href="<?= $e($qs(['page' => $page - 1])) ?>">← Novšie</a><?php endif ?>
    <span>Strana <?= (int)$page ?> z <?= (int)$pages ?></span>
    <?php if ($page < $pages): ?><a href="<?= $e($qs(['page' => $page + 1])) ?>">Staršie →</a><?php endif ?>
  </nav>
<?php endif ?>
