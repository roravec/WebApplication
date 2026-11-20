<?php /** @var array $f, $shops, $groups; array|null $data */
$m = function ($v) use ($f) { return (new Panel\Format())->money((float)$v); };
$pct = function ($v) { return $v === null ? '–' : number_format((float)$v, 1, ',', ' ') . ' %'; };
$qs = function (array $change) use ($f) {
    $q = ['from' => $f['from'], 'to' => $f['to'], 'group' => $f['group']];
    if ($f['picked']) {
        $q['shop'] = $f['picked'];
    }
    return '?' . http_build_query(array_merge($q, $change));
};
?>
<?= $view->partial('reports/tabs', ['tab' => 'sales']) ?>
<form class="filters" method="get" action="<?= $e($ctx->url('/reports/sales')) ?>">
  <label>Od <input type="date" name="from" value="<?= $e($f['from']) ?>"></label>
  <label>Do <input type="date" name="to" value="<?= $e($f['to']) ?>"></label>
  <select name="shop[]" multiple size="<?= min(4, max(2, count($shops))) ?>" title="Bez výberu = všetky e-shopy">
    <?php foreach ($shops as $id => $name): ?>
      <option value="<?= (int)$id ?>"<?= in_array($id, $f['picked'], true) ? ' selected' : '' ?>><?= $e($name) ?></option>
    <?php endforeach ?>
  </select>
  <select name="group">
    <?php foreach ($groups as $k => $label): ?>
      <option value="<?= $e($k) ?>"<?= $f['group'] === $k ? ' selected' : '' ?>>Podľa: <?= $e(mb_strtolower($label, 'UTF-8')) ?></option>
    <?php endforeach ?>
  </select>
  <button class="primary">Zobraziť</button>
  <a class="button" href="<?= $e($ctx->url('/reports/sales') . $qs(['from' => date('Y-m-01'), 'to' => date('Y-m-t')])) ?>">Tento mesiac</a>
  <a class="button" href="<?= $e($ctx->url('/reports/sales') . $qs(['from' => date('Y-01-01'), 'to' => date('Y-12-31')])) ?>">Tento rok</a>
  <?php if ($data): ?><a class="button" href="<?= $e($ctx->url('/reports/sales') . $qs(['csv' => 1])) ?>">CSV pre Excel</a><?php endif ?>
</form>

<?php if (!$data): ?>
  <p class="muted">Nemáte prístup k žiadnemu e-shopu (e-shopy priraďte firmám vo Firmy → E-shopy).</p>
<?php else: $s = $data['summary']; $oc = $data['costs'] && in_array($data['group'], ['month', 'shop'], true); ?>
  <div class="cards">
    <section class="card"><h2>Tržby z tovaru</h2><p class="big"><?= $e($m($s['revenue'] - $s['discounts'])) ?></p>
      <p class="muted small">po zľavách <?= $e($m($s['discounts'])) ?> · <?= (int)$s['orders'] ?> objednávok · <?= (int)$s['qty'] ?> ks</p></section>
    <section class="card"><h2>Hrubá marža</h2><p class="big"><?= $e($m($s['margin'])) ?></p>
      <p class="muted small"><?= $e($pct($s['margin_pct'])) ?> z tržieb · nákup tovaru <?= $e($m($s['cogs'])) ?></p></section>
    <section class="card"><h2>Doprava od zákazníkov</h2><p class="big"><?= $e($m($s['shipping'])) ?></p>
      <p class="muted small">priemerná objednávka <?= $e($m($s['aov'])) ?></p></section>
    <?php if ($data['costs']): ?>
    <section class="card"><h2>Príspevok na krytie</h2><p class="big"><?= $e($m($s['contribution'])) ?></p>
      <p class="muted small"><?= $e($pct($s['contribution_pct'])) ?> z tržieb · marža + doprava − doprava <?= $e($m($s['ship_cost'])) ?>
        − platby <?= $e($m($s['pay_cost'])) ?> − balné <?= $e($m($s['pack_cost'])) ?>
        <?php if ($s['no_ship_rule']): ?><br><b><?= (int)$s['no_ship_rule'] ?></b> objednávok bez ceny dopravy — <a href="<?= $e($ctx->url('/reports/order-costs')) ?>">doplniť</a><?php endif ?></p></section>
    <?php else: ?>
    <section class="card"><h2>Príspevok na krytie</h2><p class="muted">Nastavte <a href="<?= $e($ctx->url('/reports/order-costs')) ?>">náklady objednávok</a>
      (doprava, poplatky za platby, balné) — uvidíte, koľko vám objednávky naozaj zarobia.</p></section>
    <?php endif ?>
    <section class="card"><h2>Presnosť</h2>
      <p><?= (int)$s['missing'] ? '<b>' . (int)$s['missing'] . '</b> položiek <b>bez nákupnej ceny</b>' : 'Všetky položky majú nákupnú cenu.' ?></p>
      <p class="muted small"><?= (int)$s['estimated'] ?> položiek s odhadom (aktuálna nákupná cena, v čase objednávky chýbala).
        Sumy bez DPH pri platiteľovi DPH, s DPH pri neplatiteľovi.</p></section>
  </div>

  <?php $months = $data['months']; if (count($months) > 1):
      $max = max(array_map(function ($x) { return max($x['revenue'] - $x['discounts'], 1); }, $months));
      $w = 100 / count($months); ?>
    <section class="card">
      <h2>Tržby a marža po mesiacoch</h2>
      <svg viewBox="0 0 100 42" preserveAspectRatio="none" style="width:100%;height:14rem" role="img" aria-label="Tržby a marža po mesiacoch">
        <?php foreach ($months as $i => $x): $rev = max(0, $x['revenue'] - $x['discounts']); $mar = max(0, $x['margin']);
          $h1 = $rev / $max * 36; $h2 = $mar / $max * 36; ?>
          <rect x="<?= $i * $w + $w * .12 ?>" y="<?= 38 - $h1 ?>" width="<?= $w * .36 ?>" height="<?= $h1 ?>" fill="var(--link)" opacity=".45"><title><?= $e($x['label'] . ': tržby ' . $m($rev)) ?></title></rect>
          <rect x="<?= $i * $w + $w * .52 ?>" y="<?= 38 - $h2 ?>" width="<?= $w * .36 ?>" height="<?= $h2 ?>" fill="var(--ok)"><title><?= $e($x['label'] . ': marža ' . $m($x['margin'])) ?></title></rect>
          <text x="<?= $i * $w + $w / 2 ?>" y="41.5" font-size="2" text-anchor="middle" fill="var(--muted)"><?= $e(substr($x['label'], 5) . '/' . substr($x['label'], 2, 2)) ?></text>
        <?php endforeach ?>
      </svg>
      <p class="muted small"><span style="color:var(--link)">■</span> tržby z tovaru po zľavách · <span style="color:var(--ok)">■</span> hrubá marža</p>
    </section>
  <?php endif ?>

  <table class="grid">
    <thead><tr><th><?= $e($groups[$data['group']]) ?></th><th class="num">Obj.</th><th class="num">Ks</th><th class="num">Tržby</th>
      <th class="num">Zľavy</th><th class="num">Doprava</th><th class="num">Nákup</th><th class="num">Marža</th><th class="num">%</th>
      <?php if ($oc): ?><th class="num" title="doprava + platby + balné">Náklady obj.</th><th class="num">Príspevok</th><?php endif ?><th class="num">Bez NC</th></tr></thead>
    <tbody>
    <?php foreach ($data['rows'] as $r): ?>
      <tr><td><?= $e($r['label']) ?></td><td class="num"><?= (int)$r['orders'] ?></td><td class="num"><?= (int)$r['qty'] ?></td>
        <td class="num"><?= $e($m($r['revenue'])) ?></td><td class="num"><?= $r['discounts'] ? $e($m(-$r['discounts'])) : '' ?></td>
        <td class="num"><?= $r['shipping'] ? $e($m($r['shipping'])) : '' ?></td><td class="num"><?= $e($m($r['cogs'])) ?></td>
        <td class="num"><b><?= $e($m($r['margin'])) ?></b></td><td class="num"><?= $e($pct($r['margin_pct'])) ?></td>
        <?php if ($oc): ?><td class="num"><?= $e($m(-$r['order_costs'])) ?></td><td class="num"><b><?= $e($m($r['contribution'])) ?></b></td><?php endif ?>
        <td class="num"><?= $r['missing'] ? '<span class="badge pay-overdue">' . (int)$r['missing'] . '</span>' : '' ?></td></tr>
    <?php endforeach ?>
    <tr><th>Spolu</th><th class="num"><?= (int)$s['orders'] ?></th><th class="num"><?= (int)$s['qty'] ?></th><th class="num"><?= $e($m($s['revenue'])) ?></th>
      <th class="num"><?= $e($m(-$s['discounts'])) ?></th><th class="num"><?= $e($m($s['shipping'])) ?></th><th class="num"><?= $e($m($s['cogs'])) ?></th>
      <th class="num"><?= $e($m($s['margin'])) ?></th><th class="num"><?= $e($pct($s['margin_pct'])) ?></th>
      <?php if ($oc): ?><th class="num"><?= $e($m(-$s['order_costs'])) ?></th><th class="num"><?= $e($m($s['contribution'])) ?></th><?php endif ?><th class="num"><?= (int)$s['missing'] ?: '' ?></th></tr>
    </tbody>
  </table>
  <?php if ($data['group'] === 'product' || $data['group'] === 'category'): ?>
    <p class="muted small">Zľavy, doprava a náklady objednávok sú pri objednávke ako celku, preto sú len v súhrne hore.</p>
  <?php endif ?>

  <div class="cards">
    <section class="card">
      <h2>Najziskovejšie produkty</h2>
      <?php if (!$data['top']): ?><p class="muted">Žiadne s nákupnou cenou.</p><?php else: ?>
      <table class="grid small"><thead><tr><th>Produkt</th><th class="num">Ks</th><th class="num">Tržby</th><th class="num">Marža</th></tr></thead><tbody>
        <?php foreach (array_slice($data['top'], 0, 10) as $p): ?>
          <tr><td><?= $e($p['name']) ?> <span class="muted"><?= $e($p['shop']) ?></span></td><td class="num"><?= (int)$p['qty'] ?></td>
            <td class="num"><?= $e($m($p['revenue'])) ?></td><td class="num"><?= $e($m($p['margin'])) ?></td></tr>
        <?php endforeach ?>
      </tbody></table><?php endif ?>
    </section>
    <section class="card">
      <h2>Predané bez nákupnej ceny</h2>
      <?php if (!$data['missing']): ?><p class="muted">Žiadne — marža je kompletná.</p><?php else: ?>
      <p class="muted small"><a href="<?= $e($ctx->url('/reports/purchase-prices')) ?>">Doplniť nákupné ceny</a> — uložia sa do e-shopu a marža sa hneď prepočíta.</p>
      <table class="grid small"><thead><tr><th>Produkt</th><th>Kód</th><th class="num">Ks</th><th class="num">Tržby</th></tr></thead><tbody>
        <?php foreach (array_slice($data['missing'], 0, 25) as $p): ?>
          <tr><td><?= $e($p['name']) ?> <span class="muted"><?= $e($p['shop']) ?></span></td><td><?= $e($p['reference']) ?></td>
            <td class="num"><?= (int)$p['qty'] ?></td><td class="num"><?= $e($m($p['revenue'])) ?></td></tr>
        <?php endforeach ?>
      </tbody></table>
      <?php if (count($data['missing']) > 25): ?><p class="muted small">… a ďalších <?= count($data['missing']) - 25 ?> (všetky v CSV podľa produktu).</p><?php endif ?>
      <?php endif ?>
    </section>
  </div>
<?php endif ?>
