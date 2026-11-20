<?php /** @var array $o order with lines, address_invoice, address_delivery, payments, history, src; Panel\Shop\OrderSource $source;
    array|null $invoice; int $invoiceCompany; bool $canInvoice, $canSeeInvoice, $canChangeState; array $states */
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
      <dt>Zákazník</dt><dd><?= $e(trim($o['customer_firstname'] . ' ' . $o['customer_lastname'])) ?><br><?= $e($o['customer_email']) ?></dd>
    </dl>
  </section>
  <section class="card"><h2>Fakturačná adresa</h2><p><?= $addr($o['address_invoice']) ?></p></section>
  <section class="card"><h2>Dodacia adresa</h2><p><?= $addr($o['address_delivery']) ?></p></section>
</div>

<h2>Položky</h2>
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
        <select name="state"><?php foreach ($states as $s): ?><option value="<?= (int)$s['id_order_state'] ?>"<?= (int)$s['id_order_state'] === (int)$o['current_state'] ? ' selected' : '' ?>><?= $e($s['name']) ?></option><?php endforeach ?></select>
        <label class="check"><input type="checkbox" name="sendemail" value="1" checked> poslať zákazníkovi e-mail</label>
        <button class="primary" data-confirm="Zmeniť stav objednávky v e-shope?">Zmeniť stav</button>
      </form>
    <?php endif ?>
    <?php foreach ($o['history'] as $h): ?>
      <p><?= $e($f->dateTime($h['date_add'])) ?> · <?= $e($h['state']) ?></p>
    <?php endforeach ?>
  </section>
  <section class="card">
    <h2>Faktúra</h2>
    <?php if ($invoice): ?>
      <p><?php if ($canSeeInvoice): ?><a href="<?= $e($ctx->url('/invoices/' . $invoice['id'])) ?>"><?= $e($invoice['number'] ?: 'Koncept faktúry') ?></a><?php else: ?><?= $e($invoice['number'] ?: 'Koncept faktúry') ?><?php endif ?>
        <?= $invoice['status'] === 'draft' ? '<span class="badge draft">koncept</span>' : '<span class="badge issued">vystavená</span>' ?></p>
    <?php elseif (!$invoiceCompany): ?>
      <p class="muted">E-shop nie je priradený k firme, ktorá zaň fakturuje (Firmy → E-shopy).</p>
    <?php elseif ($canInvoice): ?>
      <form method="post" action="<?= $e($ctx->orderUrl($src, (int)$o['id_order'], '/invoice')) ?>">
        <?= WebApplication\Csrf::field() ?>
        <button class="primary">Pripraviť faktúru</button>
      </form>
      <p class="muted small">Vytvorí koncept z položiek objednávky; pred vystavením ho skontrolujete.</p>
    <?php else: ?>
      <p class="muted">Bez faktúry.</p>
    <?php endif ?>
  </section>
</div>
