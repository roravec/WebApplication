<?php /** @var array $o order with lines, address_invoice, address_delivery, payments, history, src; Panel\Shop\OrderSource $source;
    array|null $invoice; int $invoiceCompany; bool $canInvoice, $canSeeInvoice, $canChangeState, $canEdit; array $states, $notes, $messages, $returns */
$src = (int)$o['src'];
$addr = function ($a) use ($e) {
    if (!$a) {
        return '<span class="muted">—</span>';
    }
    $lines = [$a['company'], trim($a['firstname'] . ' ' . $a['lastname']), $a['address1'], $a['address2'],
              trim($a['postcode'] . ' ' . $a['city']), $a['country'], $a['phone'] ?: $a['phone_mobile'],
              $a['dni'] ? 'IČO: ' . $a['dni'] : '', $a['vat_number'] ? 'DIČ / IČ DPH: ' . $a['vat_number'] : ''];
    return implode('<br>', array_map($e, array_filter($lines, 'strlen')));
};
$color = function ($c) { return preg_match('/^#[0-9a-fA-F]{3,6}$/', (string)$c) ? $c : '#999'; };
$cur = (string)$o['currency'];
?>
<p><a href="<?= $e($ctx->url('/orders' . ($src ? '?src=' . $src : ''))) ?>">← Objednávky</a></p>
<?php if (!empty($editBlocked)): ?><p class="flash warn"><?= $e($editBlocked) ?><?php if (!empty($canManageShops)): ?>
  <a href="<?= $e($ctx->url('/settings/eshops')) ?>">Otvoriť Nastavenia → E-shopy</a><?php endif ?></p><?php endif ?>
<div class="cards">
  <section class="card">
    <h2>Objednávka</h2>
    <dl>
      <dt>Číslo</dt><dd><?= $e($o['reference']) ?> (ID <?= (int)$o['id_order'] ?>)</dd>
      <dt>E-shop</dt><dd><?= $e($o['shop']) ?><?php if ($src): ?> <small class="muted">(<?= $e($source->label()) ?>, cez Webservice)</small><?php endif ?></dd>
      <dt>Dátum</dt><dd><?= $e($f->dateTime($o['date_add'])) ?></dd>
      <dt>Stav</dt><dd><span class="state" style="--c: <?= $e($color($o['state_color'])) ?>"><?= $e($o['state']) ?></span></dd>
      <dt>Platba</dt><dd><?= $e($o['payment']) ?></dd>
      <dt>Doprava</dt><dd><?= $e($o['carrier']) ?></dd>
      <?php if (!empty($o['tracking'])): $turl = (string)($o['carrier_url'] ?? ''); ?>
        <dt>Zásielka</dt><dd><?php if (strpos($turl, '@') !== false): ?><a href="<?= $e(str_replace('@', rawurlencode($o['tracking']), $turl)) ?>" target="_blank" rel="noopener"><?= $e($o['tracking']) ?></a><?php else: ?><?= $e($o['tracking']) ?><?php endif ?></dd>
      <?php endif ?>
      <dt>Zákazník</dt><dd><?= $e(trim($o['customer_firstname'] . ' ' . $o['customer_lastname'])) ?><br><?= $e($o['customer_email']) ?></dd>
    </dl>
  </section>
  <section class="card"><h2>Fakturačná adresa</h2><p><?= $addr($o['address_invoice']) ?></p>
    <?php if ($canEdit): ?><p class="small"><a href="<?= $e($ctx->orderUrl($src, (int)$o['id_order'], '/address/invoice')) ?>">Zmeniť</a></p><?php endif ?></section>
  <section class="card"><h2>Dodacia adresa</h2><p><?= $addr($o['address_delivery']) ?></p>
    <?php if (!empty($o['pickup_point'])): ?><p><b>Výdajné miesto:</b> <?= $e($o['pickup_point']) ?></p><?php endif ?>
    <?php if ($canEdit): ?><p class="small"><a href="<?= $e($ctx->orderUrl($src, (int)$o['id_order'], '/address/delivery')) ?>">Zmeniť</a></p><?php endif ?></section>
</div>

<div class="toolbar">
  <a class="button" href="<?= $e($ctx->orderUrl($src, (int)$o['id_order'], '/delivery-note')) ?>" target="_blank">Dodací list (PDF)</a>
  <?php if ($ctx->can(\Panel\Rights::ORDERS_EDIT)): ?>
    <a class="button" href="<?= $e($ctx->orderUrl($src, (int)$o['id_order'], '/message')) ?>">Napísať zákazníkovi</a>
    <a class="button" href="<?= $e($ctx->orderUrl($src, (int)$o['id_order'], '/return')) ?>">Vrátenie tovaru</a>
  <?php endif ?>
  <a class="button" href="<?= $e($ctx->url('/shipping' . ($src ? '?src=' . $src : ''))) ?>">Expedícia</a>
</div>

<h2>Položky<?php if ($canEdit): ?> <a class="button" href="<?= $e($ctx->orderUrl($src, (int)$o['id_order'], '/lines')) ?>">Upraviť položky, dopravu a zľavu</a><?php endif ?></h2>
<table class="grid">
  <thead><tr><th>Produkt</th><th>Kód</th><th class="num">Množstvo</th><th class="num">Cena/ks s DPH</th><th class="num">Spolu s DPH</th></tr></thead>
  <tbody>
  <?php foreach ($o['lines'] as $l): ?>
    <tr><td><?= $e($l['product_name']) ?></td><td><?= $e($l['product_reference']) ?></td>
        <td class="num"><?= (int)$l['product_quantity'] ?></td>
        <td class="num"><?= $e($f->money($l['unit_price_tax_incl'], $cur)) ?></td>
        <td class="num"><?= $e($f->money($l['total_price_tax_incl'], $cur)) ?></td></tr>
  <?php endforeach ?>
  </tbody>
  <tfoot>
    <tr><td colspan="4">Produkty</td><td class="num"><?= $e($f->money($o['total_products_wt'], $cur)) ?></td></tr>
    <tr><td colspan="4">Doprava</td><td class="num"><?= $e($f->money($o['total_shipping_tax_incl'], $cur)) ?></td></tr>
    <?php if ((float)$o['total_discounts_tax_incl'] > 0): ?>
      <tr><td colspan="4">Zľavy</td><td class="num">−<?= $e($f->money($o['total_discounts_tax_incl'], $cur)) ?></td></tr>
    <?php endif ?>
    <tr class="total"><td colspan="4">Spolu</td><td class="num"><?= $e($f->money($o['total_paid_tax_incl'], $cur)) ?></td></tr>
  </tfoot>
</table>

<div class="cards">
  <section class="card">
    <h2>Platby</h2>
    <?php if ($o['payments_unavailable']): ?><p class="muted">Platby sa nedajú zistiť — kľúč Webservice nemá oprávnenie order_payments (GET).</p>
    <?php elseif (!$o['payments']): ?><p class="muted">Žiadna zaznamenaná platba.</p><?php endif ?>
    <?php foreach ($o['payments'] as $p): ?>
      <p><?= $e($f->dateTime($p['date_add'])) ?> · <?= $e($p['payment_method']) ?> · <b><?= $e($f->money($p['amount'], $cur)) ?></b>
      <?php if ($p['transaction_id']): ?><br><small class="muted"><?= $e($p['transaction_id']) ?></small><?php endif ?></p>
    <?php endforeach ?>
  </section>
  <section class="card">
    <h2>História stavov</h2>
    <?php if ($canChangeState): ?>
      <form method="post" action="<?= $e($ctx->orderUrl($src, (int)$o['id_order'], '/state')) ?>" class="filters">
        <?= WebApplication\Csrf::field() ?>
        <select name="state"><?php foreach ($states as $s): ?><option value="<?= (int)$s['id_order_state'] ?>"<?= (int)$s['id_order_state'] === (int)$o['current_state'] ? ' selected' : '' ?>><?= $e($s['name'] . (isset($s['send_email']) && !(int)$s['send_email'] ? ' (bez e-mailu)' : '')) ?></option><?php endforeach ?></select>
        <label class="check"><input type="checkbox" name="sendemail" value="1" checked> poslať zákazníkovi e-mail (ak ho stav posiela)</label>
        <button class="primary" data-confirm="Zmeniť stav objednávky v e-shope?">Zmeniť stav</button>
      </form>
    <?php endif ?>
    <?php foreach ($o['history'] as $h): ?>
      <p><?= $e($f->dateTime($h['date_add'])) ?> · <?= $e($h['state']) ?></p>
    <?php endforeach ?>
  </section>
  <section class="card">
    <h2>Faktúra</h2>
    <?php
      $invoiceSent = array_values(array_filter($panelEmails, function ($m) { return $m['status'] === 'sent' && ($m['kind'] ?? 'invoice') === 'invoice'; }));
      $orderAction = function ($path) use ($ctx, $src, $o) { return $ctx->orderUrl($src, (int)$o['id_order'], $path); };
    ?>
    <?php if ($invoice): ?>
      <p><?php if ($canSeeInvoice): ?><a href="<?= $e($ctx->url('/invoices/' . $invoice['id'])) ?>"><?= $e($invoice['number'] ?: 'Koncept faktúry') ?></a><?php else: ?><?= $e($invoice['number'] ?: 'Koncept faktúry') ?><?php endif ?>
        <?= $invoice['status'] === 'draft' ? '<span class="badge draft">koncept</span>' : '<span class="badge issued">vystavená</span>' ?>
        <?php if ($invoice['status'] !== 'draft' && $canSeeInvoice): ?> · <a href="<?= $e($ctx->url('/invoices/' . $invoice['id'] . '/pdf')) ?>">PDF</a><?php endif ?></p>
      <?php if ($canInvoice && $invoice['status'] === 'draft'): ?>
        <form method="post" action="<?= $e($orderAction('/invoice/issue')) ?>" class="inline">
          <?= WebApplication\Csrf::field() ?>
          <button class="primary" onclick="return confirm('Vystaviť faktúru? Po vystavení sa už nedá meniť (len dobropisom).')">Vystaviť faktúru</button>
        </form>
      <?php elseif ($canInvoice && $invoice['status'] === 'issued'): ?>
        <form method="post" action="<?= $e($orderAction('/invoice/send')) ?>" class="inline">
          <?= WebApplication\Csrf::field() ?>
          <?php if ($invoiceSent): ?>
            <input type="hidden" name="again" value="1">
            <p class="muted small">Poslaná <?= $e($f->dateTime($invoiceSent[0]['created_at'])) ?> na <?= $e($invoiceSent[0]['recipients']) ?><?= count($invoiceSent) > 1 ? ' (spolu ' . count($invoiceSent) . '×)' : '' ?>.</p>
            <button onclick="return confirm('Faktúra už bola zákazníkovi poslaná. Poslať ju ZNOVA?')">Poslať znova</button>
          <?php else: ?>
            <button class="primary" onclick="return confirm('Poslať faktúru zákazníkovi na <?= $e($o['customer_email']) ?>?')">Poslať faktúru zákazníkovi</button>
          <?php endif ?>
        </form>
        <?php if ($canSeeInvoice): ?><p class="muted small"><a href="<?= $e($ctx->url('/invoices/' . $invoice['id'] . '/send')) ?>">Upraviť text e-mailu pred odoslaním</a></p><?php endif ?>
      <?php endif ?>
    <?php elseif (!$invoiceCompany): ?>
      <p class="muted">E-shop nie je priradený k firme, ktorá zaň fakturuje (Firmy → E-shopy).</p>
    <?php elseif ($canInvoice): ?>
      <form method="post" action="<?= $e($orderAction('/invoice/issue')) ?>" class="inline">
        <?= WebApplication\Csrf::field() ?>
        <button class="primary" onclick="return confirm('Vystaviť faktúru z položiek objednávky? Po vystavení sa už nedá meniť (len dobropisom).')">Vystaviť faktúru</button>
      </form>
      <form method="post" action="<?= $e($orderAction('/invoice')) ?>" class="inline">
        <?= WebApplication\Csrf::field() ?>
        <button>Pripraviť koncept na úpravu</button>
      </form>
      <p class="muted small">„Vystaviť faktúru“ = hneď s číslom z položiek objednávky. Koncept = najprv upraviť, potom vystaviť.</p>
    <?php else: ?>
      <p class="muted">Bez faktúry.</p>
    <?php endif ?>
  </section>
  <section class="card">
    <h2>E-maily zákazníkovi</h2>
    <?php
      $mails = [];
      foreach ($panelEmails as $m) {
          $mails[] = ['at' => $m['created_at'], 'who' => 'panel', 'what' => $m['subject'], 'full' => $m['subject'],
              'kind' => ($m['kind'] ?? '') === 'reminder' ? 'upomienka' : 'faktúra', 'to' => $m['recipients'], 'ok' => $m['status'] === 'sent'];
      }
      foreach ($messages as $m) {
          $mails[] = ['at' => $m['created_at'], 'who' => 'panel · ' . $m['client_name'], 'what' => $m['subject'], 'full' => $m['subject'],
              'kind' => 'správa', 'to' => $m['recipients'], 'ok' => $m['status'] === 'sent'];
      }
      foreach ($shopEmails ?: [] as $m) {
          // the shop puts its long name in front ("Howies Hockey Slovensko - … - Potvrdenie objednávky") → the last part
          $parts = explode(' - ', (string)$m['subject']);
          $mails[] = ['at' => $m['date_add'], 'who' => 'e-shop', 'what' => trim((string)end($parts)), 'full' => $m['subject'] . ' (' . $m['template'] . ')',
              'kind' => '', 'to' => $o['customer_email'], 'ok' => true];
      }
      usort($mails, function ($a, $b) { return strcmp($b['at'], $a['at']); });
    ?>
    <?php if (!$mails): ?>
      <p class="muted">Zatiaľ žiadny<?= $shopEmails === null ? ' z panela (e-maily e-shopu sa pri tomto pripojení nedajú zobraziť)' : '' ?>.</p>
    <?php endif ?>
    <?php foreach ($mails as $m): ?>
      <p<?= $m['ok'] ? '' : ' class="inactive"' ?>><small class="muted"><?= $e($f->dateTime($m['at'])) ?> · <?= $e($m['who']) ?><?= $m['kind'] !== '' ? ' · ' . $e($m['kind']) : '' ?></small><br>
        <span title="<?= $e($m['full']) ?>"><?= $e($m['what']) ?></span><?= $m['ok'] ? '' : ' — <b>neodoslané</b>' ?><br>
        <small class="muted"><?= $e($m['to']) ?></small></p>
    <?php endforeach ?>
  </section>
</div>

<div class="cards">
  <?php if ($canEdit): ?>
  <section class="card">
    <h2>Expedícia</h2>
    <form method="post" action="<?= $e($ctx->orderUrl($src, (int)$o['id_order'], '/tracking')) ?>" class="form">
      <?= WebApplication\Csrf::field() ?>
      <label>Číslo zásielky <input name="tracking" value="<?= $e((string)($o['tracking'] ?? '')) ?>" maxlength="64"></label>
      <label>Zároveň zmeniť stav
        <select name="state"><option value="0">— nemeniť —</option>
          <?php foreach ($states as $s): ?><option value="<?= (int)$s['id_order_state'] ?>"><?= $e($s['name'] . (isset($s['send_email']) && !(int)$s['send_email'] ? ' (bez e-mailu)' : '')) ?></option><?php endforeach ?>
        </select></label>
      <label class="check"><input type="checkbox" name="sendemail" value="1" checked> poslať zákazníkovi e-mail o stave, ak ho stav posiela (s odkazom na sledovanie)</label>
      <p style="margin: 0"><button class="primary">Uložiť do e-shopu</button></p>
    </form>
  </section>
  <?php endif ?>
  <section class="card" id="notes">
    <h2>Interné poznámky</h2>
    <?php foreach ($notes as $n): ?>
      <p><small class="muted"><?= $e($f->dateTime($n['created_at'])) ?> · <?= $e($n['client_name']) ?></small><br><?= nl2br($e($n['text'])) ?></p>
    <?php endforeach ?>
    <?php if (!$notes): ?><p class="muted">Zatiaľ žiadne. Vidia ich len zamestnanci, zákazník nie.</p><?php endif ?>
    <form method="post" action="<?= $e($ctx->orderUrl($src, (int)$o['id_order'], '/note')) ?>">
      <?= WebApplication\Csrf::field() ?>
      <textarea name="text" rows="2" style="width: 100%" placeholder="napr. zákazník volal, pošleme v piatok" required></textarea>
      <p><button>Pridať poznámku</button></p>
    </form>
  </section>
  <?php if ($returns): ?>
  <section class="card">
    <h2>Vrátenia</h2>
    <?php foreach ($returns as $r): ?>
      <p><small class="muted"><?= $e($f->dateTime($r['created_at'])) ?> · <?= $e($r['client_name']) ?></small><br>
        <?php foreach ($r['lines'] as $l): ?><?= (int)$l['quantity'] ?>× <?= $e($l['name']) ?><br><?php endforeach ?>
        <?= $r['shipping'] ? 'doprava<br>' : '' ?>
        <small><?= $r['restocked'] ? 'naskladnené v e-shope' : 'nenaskladnené' ?><?php if ($r['credit_note_id']): ?> · <a href="<?= $e($ctx->url('/invoices/' . $r['credit_note_id'])) ?>">dobropis</a><?php endif ?>
          <?= $r['state'] !== '' ? ' · stav ' . $e($r['state']) : '' ?><?= $r['note'] !== '' ? ' · ' . $e($r['note']) : '' ?></small>
        <?php if ($r['problems']): ?><br><small class="field-err"><?= $e(implode(' ', $r['problems'])) ?></small><?php endif ?></p>
    <?php endforeach ?>
  </section>
  <?php endif ?>
</div>
