<?php /** @var array $shops, $picked, $rows, $errors, $posted; string $filter, $q; int $total, $page, $pages, $queue; bool $canEdit, $canStock; array|null $connection */
use WebApplication\Csrf;
$f = new Panel\Format();
$qs = function (array $change = []) use ($picked, $filter, $q, $page) {
    $all = array_merge(['filter' => $filter, 'q' => $q, 'page' => $page], $picked ? ['shop' => $picked] : [], $change);
    return '?' . http_build_query(array_filter($all, function ($v) { return $v !== '' && $v !== null && $v !== []; }));
};
$filters = ['missing_sold' => 'Predané za 12 mesiacov bez nákupnej ceny', 'missing_sold_all' => 'Kedykoľvek predané bez nákupnej ceny',
    'missing' => 'Všetky bez nákupnej ceny', 'all' => 'Všetky produkty'];
?>
<?= $view->partial('reports/tabs', ['tab' => 'purchase']) ?>
<p class="muted">Nákupná cena bez DPH tak, ako ju eviduje e-shop (Katalóg → Produkty → Ceny). Uloží sa priamo do e-shopu (cez Webservice) vo všetkých
  obchodoch, kde produkt je. Prehľady predajov ju hneď použijú — pri starších objednávkach, ktoré ju v čase predaja nemali, ako <i>odhad</i>.</p>

<form class="filters" method="get" action="<?= $e($ctx->url('/reports/purchase-prices')) ?>">
  <select name="filter">
    <?php foreach ($filters as $k => $label): ?><option value="<?= $e($k) ?>"<?= $filter === $k ? ' selected' : '' ?>><?= $e($label) ?></option><?php endforeach ?>
  </select>
  <select name="shop[]" multiple size="<?= min(4, max(2, count($shops))) ?>" title="Bez výberu = všetky e-shopy">
    <?php foreach ($shops as $id => $name): ?><option value="<?= (int)$id ?>"<?= in_array($id, $picked, true) ? ' selected' : '' ?>><?= $e($name) ?></option><?php endforeach ?>
  </select>
  <input name="q" value="<?= $e($q) ?>" placeholder="Názov alebo kód">
  <button class="primary">Zobraziť</button>
  <span class="muted"><?= (int)$total ?> položiek</span>
</form>

<?php if (!$canEdit): ?>
  <p class="flash err"><?= !$canStock ? 'Na zmenu nákupných cien potrebujete právo „Sklad — príjemky, výdajky…“.'
    : 'Na zápis do e-shopu treba pripojenie s Webservice kľúčom označené „táto databáza“ (Nastavenia → E-shopy → Pripojiť e-shop).' ?></p>
<?php endif ?>

<?php if ($errors): ?><p class="flash err"><?= implode('<br>', array_map($e, $errors)) ?></p><?php endif ?>

<form method="post" action="<?= $e($ctx->url('/reports/purchase-prices') . $qs()) ?>">
  <?= Csrf::field() ?>
  <?php if ($rows): ?>
  <table class="grid">
    <thead><tr><th>Produkt</th><th>Kód</th><th class="num">Predané ks</th><th>Naposledy</th><th class="num">Nákupná cena bez DPH</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $key = $r['product_id'] . '-' . $r['attribute_id']; ?>
      <tr><td><?= $e($r['name']) ?><?= $r['combination'] !== '' ? ' <span class="muted">' . $e($r['combination']) . '</span>' : '' ?>
            <span class="muted small">#<?= (int)$r['product_id'] ?></span></td>
        <td><?= $e($r['reference']) ?></td><td class="num"><?= (int)$r['sold_qty'] ?: '' ?></td><td><?= $e($f->date($r['last_sold'] ?: null)) ?></td>
        <td class="num">
          <input type="hidden" name="old[<?= $e($key) ?>]" value="<?= $e($r['price'] > 0 ? number_format($r['price'], 6, '.', '') : '') ?>">
          <input class="num pp" name="prices[<?= $e($key) ?>]" inputmode="decimal" size="8" <?= $canEdit ? '' : 'disabled' ?>
            value="<?= $e($posted[$key] ?? ($r['price'] > 0 ? rtrim(rtrim(number_format($r['price'], 6, ',', ''), '0'), ',') : '')) ?>"
            placeholder="<?= $r['price'] > 0 ? '' : 'chýba' ?>"> €</td></tr>
    <?php endforeach ?>
    </tbody>
  </table>
  <?php if ($pages > 1): ?>
    <p class="pager"><?php for ($i = 1; $i <= $pages; $i++): ?>
      <?= $i === $page ? '<b>' . $i . '</b>' : '<a href="' . $e($ctx->url('/reports/purchase-prices') . $qs(['page' => $i])) . '">' . $i . '</a>' ?>
    <?php endfor ?></p>
  <?php endif ?>
  <?php else: ?>
    <p class="muted"><?= $filter === 'all' ? 'Žiadne produkty.' : 'Všetky produkty majú nákupnú cenu.' ?></p>
  <?php endif ?>

  <?php if ($canEdit): ?>
  <div class="cards">
    <section class="card">
      <h2>Rovnaká cena pre viac položiek</h2>
      <p class="muted small">Doplní cenu do všetkých <b>prázdnych</b> polí na tejto stránke (napr. všetky ŠPZ rovnako). Uloží sa až tlačidlom nižšie.</p>
      <p><input id="pp-bulk" inputmode="decimal" size="8" placeholder="napr. 6,50"> € <button type="button" onclick="
        var v = document.getElementById('pp-bulk').value.trim(); if (!v) return;
        document.querySelectorAll('input.pp').forEach(function (i) { if (!i.value.trim()) { i.value = v; } });">Doplniť do prázdnych</button></p>
    </section>
    <section class="card">
      <h2>Import z tabuľky</h2>
      <p class="muted small">Riadok = <code>kód;cena</code> (kód produktu alebo kombinácie z e-shopu), napr. skopírované z Excelu (stĺpce kód a cena).</p>
      <textarea name="csv" rows="4" style="width:100%" placeholder="TX-1DFLT;6,50&#10;AZ-38A17B;7"></textarea>
    </section>
  </div>
  <p><button class="primary">Uložiť do e-shopu</button></p>
  <?php endif ?>
</form>
