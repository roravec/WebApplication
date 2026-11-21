<?php use Panel\Reports\Money;
/** @var array $companies, $company, $rec, $pay, $soon, $flows; bool $canInvoices, $canExpenses — module E */
$m = function ($v) use ($f) { return $f->money((float)$v); };
$link = function ($dir, $r) use ($ctx, $canInvoices, $canExpenses) {
    if ($dir === 'in') {
        return $canInvoices ? $ctx->url('/invoices/' . $r['id']) : null;
    }
    return $canExpenses ? $ctx->url('/expenses/' . $r['id']) : null;
};
$max = 1.0;
foreach ($flows as $x) {
    $max = max($max, $x['in'], $x['out']);
}
?>
<?= $view->partial('reports/tabs', ['tab' => 'money']) ?>
<?php if (count($companies) > 1): ?>
<form class="filters" method="get" action="<?= $e($ctx->url('/reports/money')) ?>">
  <select name="company"><?php foreach ($companies as $c): ?><option value="<?= (int)$c['id'] ?>"<?= (int)$c['id'] === (int)$company['id'] ? ' selected' : '' ?>><?= $e($c['name']) ?></option><?php endforeach ?></select>
  <button class="primary">Zobraziť</button>
</form>
<?php endif ?>

<div class="cards">
  <section class="card"><h2>Pohľadávky</h2><p class="big"><?= $e($m($rec['total'])) ?></p>
    <p class="muted small">neuhradené vystavené faktúry · po splatnosti <b><?= $e($m($rec['overdue'])) ?></b></p></section>
  <section class="card"><h2>Záväzky</h2><p class="big"><?= $e($m($pay['total'])) ?></p>
    <p class="muted small">neuhradené prijaté doklady · po splatnosti <b><?= $e($m($pay['overdue'])) ?></b></p></section>
  <section class="card"><h2>Rozdiel</h2><p class="big"><?= $e($m($rec['total'] - $pay['total'])) ?></p>
    <p class="muted small">čo vám dlžia − čo dlžíte vy</p></section>
</div>

<section class="card">
  <h2>Podľa splatnosti</h2>
  <table class="grid">
    <thead><tr><th></th><th class="num">Pohľadávky</th><th class="num">Záväzky</th></tr></thead>
    <tbody>
    <?php foreach (Money::BUCKETS as $k => $label): ?>
      <tr class="<?= strpos($k, 'over') === 0 && ($rec['buckets'][$k] || $pay['buckets'][$k]) ? 'warn' : '' ?>"><td><?= $e($label) ?></td>
        <td class="num"><?= $rec['buckets'][$k] ? $e($m($rec['buckets'][$k])) : '' ?></td><td class="num"><?= $pay['buckets'][$k] ? $e($m($pay['buckets'][$k])) : '' ?></td></tr>
    <?php endforeach ?>
    </tbody>
  </table>
</section>

<section class="card">
  <h2>Po splatnosti a splatné do 30 dní</h2>
  <?php if (!$soon): ?><p class="muted">Nič.</p><?php else: ?>
  <table class="grid small">
    <thead><tr><th>Splatnosť</th><th></th><th>Doklad</th><th>Kto</th><th class="num">Suma</th></tr></thead>
    <tbody>
    <?php foreach ($soon as $r): $u = $link($r['dir'], $r); $late = $r['due_date'] < date('Y-m-d'); ?>
      <tr><td><?= $e($f->date($r['due_date'])) ?><?= $late ? ' <span class="badge pay-overdue">po splatnosti</span>' : '' ?></td>
        <td><?= $r['dir'] === 'in' ? 'príjem' : 'platba' ?></td>
        <td><?= $u ? '<a href="' . $e($u) . '">' . $e($r['number'] ?: 'bez čísla') . '</a>' : $e($r['number']) ?></td>
        <td><?= $e($r['party']) ?></td><td class="num"><?= $e($m($r['dir'] === 'in' ? $r['rest'] : -$r['rest'])) ?></td></tr>
    <?php endforeach ?>
    </tbody>
  </table>
  <?php endif ?>
</section>

<section class="card">
  <h2>Príjmy a výdavky po mesiacoch</h2>
  <svg viewBox="0 0 100 42" preserveAspectRatio="none" style="width:100%;height:12rem" role="img" aria-label="Príjmy a výdavky po mesiacoch">
    <?php $w = 100 / max(1, count($flows)); $i = 0; foreach ($flows as $x): $h1 = $x['in'] / $max * 36; $h2 = $x['out'] / $max * 36; ?>
      <rect x="<?= $i * $w + $w * .12 ?>" y="<?= 38 - $h1 ?>" width="<?= $w * .36 ?>" height="<?= $h1 ?>" fill="var(--ok)"><title><?= $e($x['month'] . ': príjmy ' . $m($x['in'])) ?></title></rect>
      <rect x="<?= $i * $w + $w * .52 ?>" y="<?= 38 - $h2 ?>" width="<?= $w * .36 ?>" height="<?= $h2 ?>" fill="var(--red)" opacity=".75"><title><?= $e($x['month'] . ': výdavky ' . $m($x['out'])) ?></title></rect>
      <text x="<?= $i * $w + $w / 2 ?>" y="41.5" font-size="2" text-anchor="middle" fill="var(--muted)"><?= $e(substr($x['month'], 5) . '/' . substr($x['month'], 2, 2)) ?></text>
    <?php $i++; endforeach ?>
  </svg>
  <table class="grid small">
    <thead><tr><th>Mesiac</th><th class="num">Príjmy</th><th class="num">Výdavky</th><th class="num">Rozdiel</th></tr></thead>
    <tbody><?php foreach (array_reverse($flows) as $x): ?>
      <tr><td><?= $e($x['month']) ?></td><td class="num"><?= $e($m($x['in'])) ?></td><td class="num"><?= $e($m(-$x['out'])) ?></td><td class="num"><b><?= $e($m($x['net'])) ?></b></td></tr>
    <?php endforeach ?></tbody>
  </table>
  <p class="muted small">Príjmy = zapísané úhrady faktúr (z výpisu, pri vystavení), výdavky = uhradené prijaté doklady. Platby, ktoré panel nepozná
    (napr. objednávky bez faktúry), tu nie sú — importujte výpisy pravidelne (Faktúry → Úhrady).</p>
</section>
