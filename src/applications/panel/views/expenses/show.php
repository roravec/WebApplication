<?php use Panel\Expenses\Expenses; use WebApplication\Csrf;
/** @var array $exp, $company, $shops; array|null $package, $recurring; bool $canEdit */
$cur = $exp['currency'];
$overdue = $exp['status'] === 'open' && $exp['due_date'] && $exp['due_date'] < date('Y-m-d');
$preview = null;
foreach ($exp['files'] as $file) {
    if (in_array($file['mime'], ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'], true)) {
        $preview = $file;
        break;
    }
}
$fileUrl = function ($file, $download = false) use ($ctx, $exp) {
    return $ctx->url('/expenses/' . $exp['id'] . '/files/' . $file['id']) . ($download ? '?download=1' : '');
};
?>
<p><a href="<?= $e($ctx->url('/expenses')) ?>">← Náklady</a></p>

<div class="toolbar">
  <?php if ($exp['status'] === 'cancelled'): ?><span class="badge">stornovaný</span>
  <?php elseif ($exp['status'] === 'paid'): ?><span class="badge pay-paid">uhradený <?= $e($f->date($exp['paid_on'])) ?></span>
  <?php else: ?><span class="badge <?= $overdue ? 'pay-overdue' : 'pay-unpaid' ?>"><?= $overdue ? 'po splatnosti' : 'neuhradený' ?></span><?php endif ?>
  <?php if ($package): ?><span class="badge issued" title="Mesačný balík pre účtovníka">u účtovníka (<?= $e($package['period']) ?>)</span><?php endif ?>
  <?php if ($canEdit && $exp['status'] !== 'cancelled'): ?>
    <a class="button" href="<?= $e($ctx->url('/expenses/' . $exp['id'] . '/edit')) ?>">Upraviť</a>
  <?php endif ?>
  <?php if ($canEdit): ?>
    <a class="button" href="<?= $e($ctx->url('/expenses/new?copy=' . $exp['id'])) ?>" title="Nový doklad s rovnakým dodávateľom, kategóriou a úhradou">Ďalší od tohto dodávateľa</a>
    <?php if (!$recurring): ?><a class="button" href="<?= $e($ctx->url('/expenses/recurring/new?from=' . $exp['id'])) ?>" title="Panel bude tento náklad vytvárať sám každý mesiac / štvrťrok / rok">Nastaviť opakovanie</a><?php endif ?>
  <?php endif ?>
  <?php if ($canEdit && $exp['status'] === 'paid'): ?>
    <form method="post" action="<?= $e($ctx->url('/expenses/' . $exp['id'] . '/unpaid')) ?>" class="inline">
      <?= Csrf::field() ?><button data-confirm="Zrušiť úhradu? Doklad bude znova neuhradený.">Zrušiť úhradu</button>
    </form>
  <?php endif ?>
</div>

<?php if ($exp['status'] === 'cancelled'): ?>
  <p class="flash err">Stornovaný: <?= $e($exp['cancel_reason']) ?></p>
<?php endif ?>
<?php if (!$exp['files'] && $exp['status'] !== 'cancelled'): ?>
  <p class="flash warn">Chýba súbor dokladu (PDF alebo fotka) — účtovník ho potrebuje. Pridajte ho nižšie.</p>
<?php endif ?>

<div class="cards">
  <section class="card">
    <h2>Doklad</h2>
    <dl>
      <dt>Typ</dt><dd><?= $e(Expenses::DOC_TYPES[$exp['doc_type']] ?? $exp['doc_type']) ?></dd>
      <dt>Číslo</dt><dd><b><?= $e($exp['document_number'] ?: '—') ?></b></dd>
      <?php if ($exp['variable_symbol']): ?><dt>Variabilný symbol</dt><dd><?= $e($exp['variable_symbol']) ?></dd><?php endif ?>
      <dt>Firma</dt><dd><?= $e($company['name'] ?? '') ?></dd>
      <dt>Kategória</dt><dd><?= $e((string)$exp['category']) ?></dd>
      <?php if ($recurring): ?><dt>Opakovaný</dt><dd><a href="<?= $e($ctx->url('/expenses/recurring/' . $recurring['id'])) ?>"><?= $e($recurring['name']) ?></a></dd><?php endif ?>
      <?php if ($exp['shop_id']): ?><dt>Stredisko</dt><dd><?= $e($shops[(int)$exp['shop_id']] ?? 'e-shop #' . $exp['shop_id']) ?></dd><?php endif ?>
      <dt>Vystavený</dt><dd><?= $e($f->date($exp['issue_date'])) ?></dd>
      <dt>Dodanie</dt><dd><?= $e($f->date($exp['delivery_date'])) ?></dd>
      <dt>Splatnosť</dt><dd><?= $e($f->date($exp['due_date'])) ?></dd>
      <dt>Úhrada</dt><dd><?= $e(Expenses::PAYMENT_METHODS[$exp['payment_method']] ?? $exp['payment_method']) ?>
        <?php if ($exp['paid_ref']): ?><br><small class="muted"><?= $e($exp['paid_ref']) ?></small><?php endif ?>
        <?php if (!empty($exp['bank_ref'])): ?><br><small class="muted">pohyb v banke <?= $e($exp['bank_ref']) ?></small><?php endif ?></dd>
    </dl>
  </section>
  <section class="card">
    <h2>Dodávateľ</h2>
    <dl>
      <dt>Názov</dt><dd><?= $e($exp['supplier_name']) ?></dd>
      <?php foreach (['supplier_ico' => 'IČO', 'supplier_dic' => 'DIČ', 'supplier_icdph' => 'IČ DPH'] as $k => $label): if ($exp[$k] !== ''): ?>
        <dt><?= $e($label) ?></dt><dd><?= $e($exp[$k]) ?></dd>
      <?php endif; endforeach ?>
      <?php $addr = trim($exp['supplier_street'] . ', ' . $exp['supplier_zip'] . ' ' . $exp['supplier_city'], ', '); if ($addr !== ''): ?>
        <dt>Adresa</dt><dd><?= $e($addr) ?><?= $exp['supplier_country'] !== 'SK' ? ', ' . $e($exp['supplier_country']) : '' ?></dd>
      <?php endif ?>
      <?php if ($exp['supplier_iban']): ?><dt>IBAN</dt><dd><?= $e(trim(chunk_split($exp['supplier_iban'], 4, ' '))) ?></dd><?php endif ?>
    </dl>
  </section>
  <section class="card">
    <h2>Suma</h2>
    <p class="big"><?= $e($f->money($exp['total'], $cur)) ?></p>
    <?php if ((float)$exp['total_vat'] != 0): ?>
      <dl><dt>Základ</dt><dd><?= $e($f->money($exp['total_net'], $cur)) ?></dd><dt>DPH</dt><dd><?= $e($f->money($exp['total_vat'], $cur)) ?></dd></dl>
    <?php endif ?>
    <?php if ($canEdit && $exp['status'] === 'open'): ?>
      <form method="post" action="<?= $e($ctx->url('/expenses/' . $exp['id'] . '/paid')) ?>" class="form-grid" style="margin-top:1rem">
        <?= Csrf::field() ?>
        <label>Uhradené dňa <input type="date" name="paid_on" value="<?= $e(date('Y-m-d')) ?>" required></label>
        <label>Poznámka k úhrade <input name="paid_ref" placeholder="napr. výpis 11/2026"></label>
        <p class="wide"><button class="primary">Označiť ako uhradené</button></p>
      </form>
    <?php endif ?>
  </section>
</div>

<?php if ($exp['note']): ?><section class="card"><h2>Poznámka</h2><p><?= nl2br($e($exp['note'])) ?></p></section><?php endif ?>

<section class="card">
  <h2>Súbory</h2>
  <?php if ($exp['files']): ?>
    <ul>
      <?php foreach ($exp['files'] as $file): ?>
        <li><a href="<?= $e($fileUrl($file)) ?>" target="_blank"><?= $e($file['filename']) ?></a>
          <span class="muted small"><?= (int)round($file['size'] / 1024) ?> kB · <?= $e($f->dateTime($file['created_at'])) ?></span>
          · <a href="<?= $e($fileUrl($file, true)) ?>">stiahnuť</a></li>
      <?php endforeach ?>
    </ul>
  <?php else: ?><p class="muted">Bez súboru.</p><?php endif ?>
  <?php if ($canEdit && $exp['status'] !== 'cancelled'): ?>
    <form method="post" action="<?= $e($ctx->url('/expenses/' . $exp['id'] . '/files')) ?>" enctype="multipart/form-data" class="actions">
      <?= Csrf::field() ?>
      <input type="file" name="file" accept="application/pdf,image/*,.xml" required>
      <button>Priložiť súbor</button>
    </form>
  <?php endif ?>
  <?php if ($preview): ?>
    <div class="doc-preview">
      <?php if ($preview['mime'] === 'application/pdf'): ?>
        <iframe src="<?= $e($fileUrl($preview)) ?>" title="Náhľad dokladu"></iframe>
      <?php else: ?>
        <img src="<?= $e($fileUrl($preview)) ?>" alt="Náhľad dokladu">
      <?php endif ?>
    </div>
  <?php endif ?>
</section>

<?php if ($canEdit && $exp['status'] !== 'cancelled'): ?>
<section class="card">
  <h2>Storno</h2>
  <p class="muted small">Prijaté doklady sa nemažú (archivácia 10 rokov) — chybne zadaný doklad stornujte s dôvodom.</p>
  <form method="post" action="<?= $e($ctx->url('/expenses/' . $exp['id'] . '/cancel')) ?>" class="actions">
    <?= Csrf::field() ?>
    <input name="reason" placeholder="Dôvod, napr. zadaný dvakrát" required size="40">
    <button class="danger" data-confirm="Stornovať tento doklad?">Stornovať</button>
  </form>
</section>
<?php endif ?>

<?= $view->partial('core/record', ['ref' => 'expense:' . (int)$exp['id']]) ?>
