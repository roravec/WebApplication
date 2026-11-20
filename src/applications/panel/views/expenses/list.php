<?php use Panel\Expenses\Expenses;
/** @var array $filters, $rows, $sums, $companies, $categories, $shops; int $total, $page, $pages; bool $canEdit */
$fl = $filters;
unset($filters['companies']);
$qs = function (array $change) use ($filters) {
    return '?' . http_build_query(array_filter(array_merge($filters, $change), function ($v) { return $v !== '' && $v !== 0 && $v !== null; }));
};
$today = date('Y-m-d');
$multi = count($companies) > 1;
?>
<?= $view->partial('expenses/tabs', ['tab' => $fl['status'] === 'open' ? 'open' : 'list']) ?>
<form class="filters" method="get" action="<?= $e($ctx->url('/expenses')) ?>">
  <input name="q" value="<?= $e($fl['q']) ?>" placeholder="Dodávateľ, číslo dokladu, VS, IČO, poznámka">
  <?php if ($multi): ?>
  <select name="company"><option value="">Všetky firmy</option>
    <?php foreach ($companies as $c): ?><option value="<?= (int)$c['id'] ?>"<?= $fl['company'] === (int)$c['id'] ? ' selected' : '' ?>><?= $e($c['name']) ?></option><?php endforeach ?>
  </select>
  <?php endif ?>
  <select name="category"><option value="">Všetky kategórie</option>
    <?php foreach ($categories as $c): ?><option value="<?= (int)$c['id'] ?>"<?= $fl['category'] === (int)$c['id'] ? ' selected' : '' ?>><?= $e($c['name']) ?></option><?php endforeach ?>
  </select>
  <?php if ($shops): ?>
  <select name="shop"><option value="">Všetky strediská</option>
    <?php foreach ($shops as $id => $name): ?><option value="<?= (int)$id ?>"<?= $fl['shop'] === (int)$id ? ' selected' : '' ?>><?= $e($name) ?></option><?php endforeach ?>
  </select>
  <?php endif ?>
  <select name="status"><option value="">Platné (bez storna)</option>
    <?php foreach (['open' => 'Neuhradené', 'overdue' => 'Po splatnosti', 'paid' => 'Uhradené', 'cancelled' => 'Stornované'] as $k => $label): ?><option value="<?= $e($k) ?>"<?= $fl['status'] === $k ? ' selected' : '' ?>><?= $e($label) ?></option><?php endforeach ?>
  </select>
  <label class="small">Dodanie od <input type="date" name="from" value="<?= $e($fl['from']) ?>"></label>
  <label class="small">do <input type="date" name="to" value="<?= $e($fl['to']) ?>"></label>
  <button class="primary">Hľadať</button>
  <a href="<?= $e($ctx->url('/expenses')) ?>">Zrušiť filter</a>
  <a class="button push" href="<?= $e($ctx->url('/expenses') . $qs(['csv' => 1, 'page' => null])) ?>">CSV</a>
</form>

<p class="muted"><?= (int)$total ?> dokladov · spolu <?= $e($f->money($sums['total'])) ?>
  <?php if ((float)$sums['vat'] != 0): ?> · základ <?= $e($f->money($sums['net'])) ?> · DPH <?= $e($f->money($sums['vat'])) ?><?php endif ?>
  <?php if ((float)$sums['open'] != 0): ?> · <b>neuhradené <?= $e($f->money($sums['open'])) ?></b><?php endif ?></p>

<table class="grid">
  <thead><tr><th>Dodanie</th><th>Dodávateľ</th><th>Doklad</th><th>Kategória</th><?php if ($multi): ?><th>Firma</th><?php endif ?><th>Splatnosť</th><th>Stav</th><th class="num">Suma</th></tr></thead>
  <tbody>
  <?php $names = array_column($companies, 'name', 'id'); foreach ($rows as $r):
      $overdue = $r['status'] === 'open' && $r['due_date'] && $r['due_date'] < $today; ?>
    <tr>
      <td><?= $e($f->date($r['delivery_date'])) ?></td>
      <td><a href="<?= $e($ctx->url('/expenses/' . $r['id'])) ?>"><?= $e($r['supplier_name']) ?></a></td>
      <td><?= $e($r['document_number'] ?: '—') ?> <?php if ($r['doc_type'] !== 'invoice'): ?><span class="muted small"><?= $e(Expenses::DOC_TYPES[$r['doc_type']] ?? '') ?></span><?php endif ?>
        <?php if (!(int)$r['files']): ?><span class="badge pay-unpaid" title="Chýba súbor dokladu (PDF / fotka)">bez súboru</span><?php endif ?></td>
      <td><?= $e((string)$r['category']) ?><?php if ($r['shop_id'] && isset($shops[(int)$r['shop_id']])): ?> <span class="muted small">· <?= $e($shops[(int)$r['shop_id']]) ?></span><?php endif ?></td>
      <?php if ($multi): ?><td><?= $e($names[$r['company_id']] ?? '') ?></td><?php endif ?>
      <td><?= $e($f->date($r['due_date'])) ?></td>
      <td><?php if ($r['status'] === 'cancelled'): ?><span class="badge">stornovaný</span>
        <?php elseif ($r['status'] === 'paid'): ?><span class="badge pay-paid">uhradený <?= $e($f->date($r['paid_on'])) ?></span>
        <?php else: ?><span class="badge <?= $overdue ? 'pay-overdue' : 'pay-unpaid' ?>"><?= $overdue ? 'po splatnosti' : 'neuhradený' ?></span><?php endif ?></td>
      <td class="num"><?= $e($f->money($r['total'], $r['currency'])) ?></td>
    </tr>
  <?php endforeach ?>
  <?php if (!$rows): ?><tr><td colspan="8" class="muted">Žiadne doklady.<?php if ($canEdit): ?> <a href="<?= $e($ctx->url('/expenses/new')) ?>">Pridať prvý doklad</a><?php endif ?></td></tr><?php endif ?>
  </tbody>
</table>
<?php if ($pages > 1): ?>
  <nav class="pager">
    <?php if ($page > 1): ?><a href="<?= $e($qs(['page' => $page - 1])) ?>">← Predchádzajúca</a><?php endif ?>
    <span>Strana <?= (int)$page ?> z <?= (int)$pages ?></span>
    <?php if ($page < $pages): ?><a href="<?= $e($qs(['page' => $page + 1])) ?>">Ďalšia →</a><?php endif ?>
  </nav>
<?php endif ?>
