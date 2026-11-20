<?php use Panel\Audit; use Panel\Invoicing\Calculator; use Panel\Invoicing\Invoices; use Panel\Rights; use WebApplication\Csrf;
/** @var array $inv, $company, $issueErrors, $issueWarnings, $creditNotes, $audit, $payments, $emails; array|null $original, $archived;
    bool $canEdit; float $paid; string $paymentState; array $ublErrors */
$cur = $inv['currency'];
$draft = $inv['status'] === 'draft';
$num = function ($v) { $s = number_format((float)$v, 3, ',', ' '); return rtrim(rtrim($s, '0'), ','); };
?>
<p><a href="<?= $e($ctx->url('/invoices')) ?>">← Faktúry</a></p>

<div class="toolbar">
  <?= $draft ? '<span class="badge draft">koncept</span>' : '<span class="badge issued">vystavený</span>' ?>
  <?php if (!$draft): ?><span class="badge pay-<?= $e($paymentState) ?>"><?= $e(Invoices::PAYMENT_STATES[$paymentState]) ?></span><?php endif ?>
  <?php if ($canEdit && !$draft): ?><a class="button" href="<?= $e($ctx->url('/invoices/' . $inv['id'] . '/send')) ?>">Poslať e-mailom</a><?php endif ?>
  <?php if ($canEdit && !$draft && $paymentState !== 'paid' && (float)$inv['total'] > 0): ?><a class="button" href="<?= $e($ctx->url('/invoices/' . $inv['id'] . '/send?kind=reminder')) ?>">Poslať upomienku</a><?php endif ?>
  <a class="button primary" href="<?= $e($ctx->url('/invoices/' . $inv['id'] . '/pdf')) ?>" target="_blank">PDF</a>
  <a class="button" href="<?= $e($ctx->url('/invoices/' . $inv['id'] . '/pdf?download=1')) ?>">Stiahnuť PDF</a>
  <a class="button" href="<?= $e($ctx->url('/invoices/' . $inv['id'] . '/print')) ?>" target="_blank">Náhľad</a>
  <?php if (!$draft && !$ublErrors): ?>
    <a class="button" href="<?= $e($ctx->url('/invoices/' . $inv['id'] . '/ubl')) ?>" title="UBL 2.1 / Peppol BIS Billing 3.0 (EN 16931)">E-faktúra (XML)</a>
  <?php elseif (!$draft): ?>
    <span class="muted small" title="<?= $e(implode(' ', $ublErrors)) ?>">E-faktúra: nedá sa (<?= $e($ublErrors[0]) ?>)</span>
  <?php endif ?>
  <?php if ($canEdit && $draft): ?>
    <a class="button" href="<?= $e($ctx->url('/invoices/' . $inv['id'] . '/edit')) ?>">Upraviť</a>
    <form method="post" action="<?= $e($ctx->url('/invoices/' . $inv['id'] . '/issue')) ?>" class="inline">
      <?= Csrf::field() ?><button class="primary"<?= $issueErrors ? ' disabled' : '' ?> data-confirm="Vystaviť doklad? Po vystavení dostane číslo a už sa nedá meniť ani zmazať.">Vystaviť</button>
    </form>
    <form method="post" action="<?= $e($ctx->url('/invoices/' . $inv['id'] . '/delete')) ?>" class="inline">
      <?= Csrf::field() ?><button class="danger" data-confirm="Zmazať tento koncept?">Zmazať koncept</button>
    </form>
  <?php elseif ($canEdit && $inv['doc_type'] === 'invoice'): ?>
    <form method="post" action="<?= $e($ctx->url('/invoices/' . $inv['id'] . '/credit-note')) ?>" class="inline">
      <?= Csrf::field() ?><button data-confirm="Vytvoriť koncept dobropisu k tejto faktúre?">Dobropis (oprava)</button>
    </form>
  <?php endif ?>
</div>

<?php if ($issueWarnings): ?>
  <div class="flash warn"><b>Skontrolujte:</b><ul><?php foreach ($issueWarnings as $m): ?><li><?= $e($m) ?></li><?php endforeach ?></ul></div>
<?php endif ?>
<?php if ($issueErrors): ?>
  <div class="flash err"><b>Pred vystavením treba doplniť:</b><ul><?php foreach ($issueErrors as $m): ?><li><?= $e($m) ?></li><?php endforeach ?></ul></div>
<?php endif ?>

<div class="cards">
  <section class="card">
    <h2>Doklad</h2>
    <dl>
      <dt>Typ</dt><dd><?= $e(Invoices::typeLabel($inv['doc_type'])) ?></dd>
      <dt>Číslo</dt><dd><?= $inv['number'] ? '<b>' . $e($inv['number']) . '</b>' : '<span class="muted">pridelí sa pri vystavení</span>' ?></dd>
      <?php if ($inv['variable_symbol']): ?><dt>Variabilný symbol</dt><dd><?= $e($inv['variable_symbol']) ?></dd><?php endif ?>
      <dt>Firma</dt><dd><?= $e($company['name']) ?></dd>
      <dt>Vystavená</dt><dd><?= $e($f->date($inv['issue_date'])) ?></dd>
      <dt>Dodanie</dt><dd><?= $e($f->date($inv['delivery_date'])) ?></dd>
      <dt>Splatnosť</dt><dd><?= $e($f->date($inv['due_date'])) ?></dd>
      <dt>Úhrada</dt><dd><?= $e(Invoices::PAYMENT_METHODS[$inv['payment_method']] ?? $inv['payment_method']) ?></dd>
      <dt>DPH</dt><dd><?= $inv['vat_payer'] ? 'platiteľ DPH' : 'neplatiteľ DPH' ?></dd>
      <?php if ($archived): ?>
        <dt>Archív PDF</dt><dd><?= $e($f->dateTime($archived['created_at'])) ?> · <?= (int)round($archived['size'] / 1024) ?> kB
          <br><small class="muted" title="SHA-256 kontrolný súčet uloženého PDF">SHA-256 <?= $e(substr($archived['sha256'], 0, 16)) ?>…</small></dd>
      <?php endif ?>
      <?php if ($inv['shop_order_id']): ?>
        <dt>Objednávka</dt><dd><?php if ($ctx->can(Rights::ORDERS_VIEW)): ?><a href="<?= $e($ctx->orderUrl((int)$inv['shop_connection_id'], (int)$inv['shop_order_id'])) ?>"><?= $e($inv['order_reference']) ?></a><?php else: ?><?= $e($inv['order_reference']) ?><?php endif ?></dd>
      <?php endif ?>
      <?php if ($original): ?>
        <dt>Opravuje faktúru</dt><dd><a href="<?= $e($ctx->url('/invoices/' . $original['id'])) ?>"><?= $e($original['number']) ?></a></dd>
        <dt>Dôvod</dt><dd><?= $e($inv['correction_reason']) ?></dd>
      <?php endif ?>
    </dl>
  </section>
  <section class="card">
    <h2>Odberateľ</h2>
    <p><b><?= $e($inv['buyer_name']) ?></b><br><?= $e($inv['buyer_street']) ?><br><?= $e(trim($inv['buyer_zip'] . ' ' . $inv['buyer_city'])) ?>, <?= $e($inv['buyer_country']) ?>
      <?php foreach (['buyer_ico' => 'IČO', 'buyer_dic' => 'DIČ', 'buyer_icdph' => 'IČ DPH', 'buyer_email' => 'E-mail', 'buyer_phone' => 'Telefón'] as $k => $label): ?>
        <?php if ($inv[$k] !== ''): ?><br><span class="muted"><?= $e($label) ?>:</span> <?= $e($inv[$k]) ?><?php endif ?>
      <?php endforeach ?></p>
  </section>
  <section class="card">
    <h2>Súčty</h2>
    <dl>
      <dt>Základ</dt><dd><?= $e($f->money($inv['total_net'], $cur)) ?></dd>
      <dt>DPH</dt><dd><?= $e($f->money($inv['total_vat'], $cur)) ?></dd>
      <dt><b>Spolu</b></dt><dd><b><?= $e($f->money($inv['total'], $cur)) ?></b></dd>
    </dl>
    <?php if ($creditNotes): ?>
      <h2>Dobropisy</h2>
      <?php foreach ($creditNotes as $c): ?>
        <p><a href="<?= $e($ctx->url('/invoices/' . $c['id'])) ?>"><?= $e($c['number'] ?: 'koncept') ?></a> · <?= $e($f->money($c['total'], $cur)) ?></p>
      <?php endforeach ?>
    <?php endif ?>
  </section>
</div>

<h2>Položky</h2>
<table class="grid">
  <thead><tr><th>#</th><th>Názov</th><th class="num">Množstvo</th><th class="num">Cena/MJ</th><?php if ($inv['vat_payer']): ?><th class="num">DPH</th><?php endif ?><th class="num">Základ</th><?php if ($inv['vat_payer']): ?><th class="num">S DPH</th><?php endif ?></tr></thead>
  <tbody>
  <?php foreach ($inv['lines'] as $i => $l): ?>
    <tr><td><?= $i + 1 ?></td><td><?= $e($l['name']) ?><?php if ($l['sku'] !== ''): ?> <small class="muted"><?= $e($l['sku']) ?></small><?php endif ?></td>
      <td class="num"><?= $e($num($l['quantity'])) ?> <?= $e($l['unit']) ?></td>
      <td class="num"><?= $e($f->money($l['unit_price'], $cur)) ?></td>
      <?php if ($inv['vat_payer']): ?><td class="num"><?= $l['vat_category'] === 'S' ? $e($f->number($l['vat_rate'], 0)) . ' %' : $e($l['vat_category']) ?></td><?php endif ?>
      <td class="num"><?= $e($f->money($l['net'], $cur)) ?></td>
      <?php if ($inv['vat_payer']): ?><td class="num"><?= $e($f->money($l['gross'], $cur)) ?></td><?php endif ?></tr>
  <?php endforeach ?>
  </tbody>
</table>

<?php if ($inv['note'] || $inv['internal_note']): ?>
<div class="cards">
  <?php if ($inv['note']): ?><section class="card"><h2>Poznámka na doklade</h2><p><?= nl2br($e($inv['note'])) ?></p></section><?php endif ?>
  <?php if ($inv['internal_note']): ?><section class="card"><h2>Interná poznámka</h2><p><?= nl2br($e($inv['internal_note'])) ?></p></section><?php endif ?>
</div>
<?php endif ?>

<?php if (!$draft): $rest = (float)$inv['total'] - $paid; ?>
<div class="cards" id="payments">
  <section class="card">
    <h2>Úhrady</h2>
    <?php if (!$payments): ?><p class="muted">Zatiaľ žiadna úhrada.</p><?php endif ?>
    <?php foreach ($payments as $p): ?>
      <p><?= $e($f->date($p['paid_on'])) ?> · <b><?= $e($f->money($p['amount'], $cur)) ?></b> · <?= $e(Invoices::PAYMENT_METHODS[$p['method']] ?? $p['method']) ?>
        <?= $p['note'] !== '' ? '<span class="muted">· ' . $e($p['note']) . '</span>' : '' ?>
        <?php if ($canEdit): ?>
          <form method="post" action="<?= $e($ctx->url('/invoices/' . $inv['id'] . '/payments/' . $p['id'] . '/delete')) ?>" class="inline">
            <?= Csrf::field() ?><button class="link-danger" title="Zmazať úhradu" data-confirm="Zmazať túto úhradu?">✕</button></form>
        <?php endif ?></p>
    <?php endforeach ?>
    <p>Uhradené <b><?= $e($f->money($paid, $cur)) ?></b> z <?= $e($f->money($inv['total'], $cur)) ?><?= abs($rest) >= 0.005 ? ' · zostáva <b>' . $e($f->money($rest, $cur)) . '</b>' : '' ?></p>
    <?php if ($canEdit && abs($rest) >= 0.005): ?>
      <form method="post" action="<?= $e($ctx->url('/invoices/' . $inv['id'] . '/payments')) ?>" class="filters">
        <?= Csrf::field() ?>
        <input type="date" name="paid_on" value="<?= $e(date('Y-m-d')) ?>" required>
        <input name="amount" value="<?= $e(number_format(abs($rest), 2, ',', '')) ?>" size="9" class="num" required>
        <select name="method"><?php foreach (Invoices::PAYMENT_METHODS as $k => $label): ?><option value="<?= $e($k) ?>"<?= $inv['payment_method'] === $k ? ' selected' : '' ?>><?= $e($label) ?></option><?php endforeach ?></select>
        <input name="note" placeholder="poznámka" size="14">
        <button class="primary"><?= (float)$inv['total'] < 0 ? 'Zapísať vrátenie' : 'Zapísať úhradu' ?></button>
      </form>
    <?php endif ?>
  </section>
  <section class="card">
    <h2>E-maily</h2>
    <?php if (!$emails): ?><p class="muted">Doklad ešte nebol poslaný e-mailom.</p><?php endif ?>
    <?php foreach ($emails as $m): ?>
      <p><?= $e($f->dateTime($m['created_at'])) ?> · <?= ($m['kind'] ?? '') === 'reminder' ? '<b>upomienka</b> · ' : '' ?><?= $e($m['recipients']) ?>
        <?= $m['status'] === 'sent' ? '<span class="badge issued">odoslaný</span>' : '<span class="badge draft">chyba</span> <small class="muted">' . $e($m['error']) . '</small>' ?></p>
    <?php endforeach ?>
  </section>
</div>
<?php endif ?>

<h2>História</h2>
<table class="grid compact">
  <tbody>
  <?php foreach ($audit as $a): ?>
    <tr><td><?= $e($f->dateTime($a['created_at'])) ?></td><td><?= $e($a['client_name']) ?></td><td><?= $e(Audit::label($a['action'])) ?> <?= $e($a['details']) ?></td></tr>
  <?php endforeach ?>
  </tbody>
</table>
