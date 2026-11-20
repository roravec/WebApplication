<?php /** @var array $companies, $company, $cur, $prev; int $year; bool $payer, $hasShops; array|null $vat — module E */
$m = function ($v) use ($f) { return $f->money((float)$v); };
$t = $cur['total'];
$p = $prev['total'];
$diff = function ($a, $b) { return abs($b) < 0.01 ? null : ($a - $b) / abs($b) * 100; };
$pctTxt = function ($x) { return $x === null ? '' : ($x >= 0 ? '+' : '') . number_format($x, 0, ',', ' ') . ' %'; };
$srcLabel = ['actual' => 'skutočné (doklady)', 'estimate' => 'odhad (pravidlá)', 'mixed' => 'skutočné / odhad', '' => ''];
$monthNames = [1 => 'jan', 'feb', 'mar', 'apr', 'máj', 'jún', 'júl', 'aug', 'sep', 'okt', 'nov', 'dec'];
$levelClass = ['ok' => 'pay-paid', 'warn' => 'pay-unpaid', 'over' => 'pay-overdue', 'over_immediate' => 'pay-overdue'];
?>
<?= $view->partial('reports/tabs', ['tab' => 'result']) ?>
<form class="filters" method="get" action="<?= $e($ctx->url('/reports/result')) ?>">
  <?php if (count($companies) > 1): ?>
  <select name="company"><?php foreach ($companies as $c): ?><option value="<?= (int)$c['id'] ?>"<?= (int)$c['id'] === (int)$company['id'] ? ' selected' : '' ?>><?= $e($c['name']) ?></option><?php endforeach ?></select>
  <?php endif ?>
  <select name="year"><?php for ($y = (int)date('Y'); $y >= (int)date('Y') - 5; $y--): ?><option value="<?= $y ?>"<?= $y === $year ? ' selected' : '' ?>><?= $y ?></option><?php endfor ?></select>
  <button class="primary">Zobraziť</button>
</form>

<div class="cards">
  <section class="card"><h2>Zisk <?= $year ?></h2><p class="big"><?= $e($m($t['profit'])) ?></p>
    <p class="muted small">minulý rok za rovnaké obdobie <?= $e($m($p['profit'])) ?> <?= $e($pctTxt($diff($t['profit'], $p['profit']))) ?></p></section>
  <section class="card"><h2>Tržby</h2><p class="big"><?= $e($m($t['revenue'])) ?></p>
    <p class="muted small">e-shopy <?= $e($m($t['shop_revenue'])) ?> · ostatné faktúry <?= $e($m($t['other_revenue'])) ?>
      · minulý rok <?= $e($m($p['revenue'])) ?> <?= $e($pctTxt($diff($t['revenue'], $p['revenue']))) ?></p></section>
  <section class="card"><h2>Náklady</h2><p class="big"><?= $e($m($t['cogs'] + $t['order_costs'] + $t['overhead'])) ?></p>
    <p class="muted small">predaný tovar <?= $e($m($t['cogs'])) ?> · objednávky <?= $e($m($t['order_costs'])) ?> <?= $e($srcLabel[$t['order_costs_source']] ?? '') ?>
      · réžia <?= $e($m($t['overhead'])) ?></p></section>
  <?php if ($vat): ?>
  <section class="card"><h2>Obrat pre registráciu k DPH</h2>
    <p class="big"><?= $e($m($vat['turnover'])) ?> <span class="badge <?= $e($levelClass[$vat['level']]) ?>"><?= number_format($vat['pct'], 0) ?> %</span></p>
    <p class="small"><?= $e($vat['text']) ?></p>
    <p class="muted small">Od <?= $e($f->date($vat['from'])) ?>; hranica podľa Legislatívy (<?= $e($vat['rule']['note']) ?>). Oslobodené plnenia nie sú odpočítané.</p></section>
  <?php elseif ($payer): ?>
  <section class="card"><h2>DPH</h2><p class="muted">Firma je platiteľ DPH — sumy sú bez DPH.</p></section>
  <?php endif ?>
</div>

<?php if ($cur['foreign']): ?><p class="flash warn"><?= (int)$cur['foreign'] ?> dokladov v inej mene ako EUR nie je započítaných.</p><?php endif ?>
<?php if ($t['missing_cost']): ?><p class="flash warn"><?= (int)$t['missing_cost'] ?> predaných položiek nemá nákupnú cenu — zisk je vyšší, ako v skutočnosti.
  <a href="<?= $e($ctx->url('/reports/purchase-prices')) ?>">Doplniť nákupné ceny</a></p><?php endif ?>

<div class="scroll-x">
<table class="grid">
  <thead><tr><th>Mesiac</th><th class="num">Tržby e-shopy</th><th class="num">Ostatné tržby</th><th class="num">Predaný tovar</th>
    <th class="num" title="skutočné = doklady druhu „náklady na objednávky“, inak odhad z pravidiel">Náklady objednávok</th><th class="num">Réžia</th>
    <th class="num">Zisk</th><th class="num">Zisk <?= $year - 1 ?></th><th class="num">Nákup tovaru (doklady)</th></tr></thead>
  <tbody>
  <?php foreach ($cur['months'] as $k => $r): $pr = $prev['months'][($year - 1) . substr($k, 4)] ?? null; ?>
    <tr><td><?= $e($monthNames[(int)substr($k, 5, 2)] . ' ' . substr($k, 0, 4)) ?></td>
      <td class="num"><?= $e($m($r['shop_revenue'])) ?></td><td class="num"><?= $r['other_revenue'] ? $e($m($r['other_revenue'])) : '' ?></td>
      <td class="num"><?= $e($m(-$r['cogs'])) ?></td>
      <td class="num"><?= $r['order_costs'] ? $e($m(-$r['order_costs'])) : '' ?><?php if ($r['order_costs_source'] === 'estimate'): ?> <span class="muted small" title="odhad z pravidiel Náklady objednávok — doklady dopravcov za mesiac chýbajú">odhad</span><?php endif ?></td>
      <td class="num"><?= $r['overhead'] ? $e($m(-$r['overhead'])) : '' ?></td>
      <td class="num"><b><?= $e($m($r['profit'])) ?></b></td>
      <td class="num muted"><?= $pr ? $e($m($pr['profit'])) : '' ?></td>
      <td class="num muted"><?= $r['goods_purchases'] ? $e($m($r['goods_purchases'])) : '' ?></td></tr>
  <?php endforeach ?>
  <tr><th>Spolu</th><th class="num"><?= $e($m($t['shop_revenue'])) ?></th><th class="num"><?= $e($m($t['other_revenue'])) ?></th>
    <th class="num"><?= $e($m(-$t['cogs'])) ?></th><th class="num"><?= $e($m(-$t['order_costs'])) ?></th><th class="num"><?= $e($m(-$t['overhead'])) ?></th>
    <th class="num"><?= $e($m($t['profit'])) ?></th><th class="num"><?= $e($m($p['profit'])) ?></th><th class="num"><?= $e($m($t['goods_purchases'])) ?></th></tr>
  </tbody>
</table>
</div>
<p class="muted small">Riadiaci prehľad, nie účtovný výsledok: predaný tovar = nákupné ceny predaných kusov (doklady za nákup tovaru sú zásoba,
  sú len pre informáciu v poslednom stĺpci). Sumy bez DPH pri platiteľovi, s DPH pri neplatiteľovi. Prijaté doklady podľa dátumu dodania.</p>

<?php if ($cur['shops']): ?>
<section class="card">
  <h2>E-shopy <?= $year ?></h2>
  <table class="grid">
    <thead><tr><th>E-shop</th><th class="num">Obj.</th><th class="num">Tržby</th><th class="num">Hrubá marža</th><th class="num">Príspevok na krytie</th>
      <th class="num" title="prijaté doklady priradené e-shopu (stredisko)">Náklady e-shopu</th><th class="num">Výsledok e-shopu</th></tr></thead>
    <tbody>
    <?php foreach ($cur['shops'] as $s): ?>
      <tr><td><?= $e($s['name']) ?></td><td class="num"><?= (int)$s['orders'] ?></td><td class="num"><?= $e($m($s['revenue'])) ?></td>
        <td class="num"><?= $e($m($s['margin'])) ?></td><td class="num"><?= $s['contribution'] === null ? '—' : $e($m($s['contribution'])) ?></td>
        <td class="num"><?= $s['direct'] ? $e($m(-$s['direct'])) : '' ?></td><td class="num"><b><?= $s['result'] === null ? '—' : $e($m($s['result'])) ?></b></td></tr>
    <?php endforeach ?>
    </tbody>
  </table>
  <p class="muted small">Výsledok e-shopu = príspevok na krytie − prijaté doklady so strediskom tohto e-shopu. Réžia celej firmy sa nerozpočítava.
    <?php if (!$cur['costs']): ?>Príspevok sa počíta po nastavení <a href="<?= $e($ctx->url('/reports/order-costs')) ?>">nákladov objednávok</a>.<?php endif ?></p>
</section>
<?php elseif (!$hasShops): ?>
  <p class="muted">Firma nemá priradené e-shopy tejto databázy (Firmy → E-shopy) — tržby sú len z faktúr.</p>
<?php endif ?>

<?php $cmp = array_filter($cur['months'], function ($r) { return $r['order_costs_actual'] || $r['order_costs_est']; }); if ($cmp): ?>
<section class="card">
  <h2>Náklady objednávok — odhad a skutočnosť</h2>
  <table class="grid small">
    <thead><tr><th>Mesiac</th><th class="num">Odhad (pravidlá)</th><th class="num">Skutočnosť (doklady)</th><th class="num">Rozdiel</th></tr></thead>
    <tbody>
    <?php foreach ($cmp as $k => $r): ?>
      <tr><td><?= $e($monthNames[(int)substr($k, 5, 2)] . ' ' . substr($k, 0, 4)) ?></td><td class="num"><?= $e($m($r['order_costs_est'])) ?></td>
        <td class="num"><?= $r['order_costs_actual'] ? $e($m($r['order_costs_actual'])) : '<span class="muted">chýbajú</span>' ?></td>
        <td class="num"><?= $r['order_costs_actual'] && $r['order_costs_est'] ? $e($m($r['order_costs_actual'] - $r['order_costs_est'])) : '' ?></td></tr>
    <?php endforeach ?>
    </tbody>
  </table>
  <p class="muted small">Veľký rozdiel = upravte ceny v Náklady objednávok, alebo chýbajú / prebývajú doklady dopravcov a platobných brán.</p>
</section>
<?php endif ?>
