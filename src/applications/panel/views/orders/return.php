<?php use WebApplication\Csrf;
/** @var array $o, $returned, $states, $errors, $posted; array|null $invoice; int $src; bool $canCredit, $canRestock, $hasApi */
$cur = (string)$o['currency'];
$p = function ($k, $d = '') use ($posted) { return $posted[$k] ?? $d; };
$sent = !empty($posted);
?>
<p><a href="<?= $e($ctx->orderUrl($src, (int)$o['id_order'])) ?>">← Objednávka <?= $e($o['reference']) ?></a></p>
<?php if ($errors): ?><div class="flash err"><ul><?php foreach ($errors as $m): ?><li><?= $e($m) ?></li><?php endforeach ?></ul></div><?php endif ?>
<form method="post" action="<?= $e($ctx->orderUrl($src, (int)$o['id_order'], '/return')) ?>">
  <?= Csrf::field() ?>
  <table class="grid">
    <thead><tr><th>Produkt</th><th>Kód</th><th class="num">Objednané</th><th class="num">Už vrátené</th><th class="num">Cena/ks</th><th class="num">Vracia sa (ks)</th></tr></thead>
    <tbody>
    <?php foreach ($o['lines'] as $l): $id = (int)($l['id_order_detail'] ?? 0); $max = (int)$l['product_quantity'] - ($returned[$id] ?? 0); ?>
      <tr class="<?= $max > 0 ? '' : 'inactive' ?>"><td><?= $e($l['product_name']) ?></td><td><?= $e($l['product_reference']) ?></td>
        <td class="num"><?= (int)$l['product_quantity'] ?></td><td class="num"><?= (int)($returned[$id] ?? 0) ?: '' ?></td>
        <td class="num"><?= $e($f->money($l['unit_price_tax_incl'], $cur)) ?></td>
        <td class="num"><?php if ($max > 0): ?><input type="number" name="qty[<?= $id ?>]" min="0" max="<?= $max ?>" value="<?= (int)($p('qty', [])[$id] ?? 0) ?>" style="width: 5rem"><?php else: ?>—<?php endif ?></td></tr>
    <?php endforeach ?>
    </tbody>
  </table>
  <div class="card form-grid" style="margin-top: 1rem">
    <label class="check wide"><input type="checkbox" name="shipping" value="1"<?= $p('shipping') ? ' checked' : '' ?>> Vrátiť aj poštovné (<?= $e($f->money($o['total_shipping_tax_incl'], $cur)) ?>)</label>
    <label class="check wide"><input type="checkbox" name="restock" value="1"<?= !$sent || $p('restock') ? ' checked' : '' ?><?= $canRestock && $hasApi ? '' : ' disabled' ?>>
      Naskladniť vrátené kusy späť do e-shopu<?= $canRestock ? ($hasApi ? '' : ' (chýba pripojenie e-shopu)') : ' (treba právo Sklad — úpravy)' ?></label>
    <label class="check wide"><input type="checkbox" name="credit_note" value="1"<?= (!$sent || $p('credit_note')) && $canCredit ? ' checked' : '' ?><?= $canCredit ? '' : ' disabled' ?>>
      Pripraviť dobropis k faktúre<?= $invoice ? ' ' . $e($invoice['number'] ?: '(koncept)') : '' ?> — len vrátené položky (vystavíte ho po kontrole)
      <?= $canCredit ? '' : ($invoice ? ($invoice['status'] !== 'issued' ? ' — faktúra ešte nie je vystavená' : ' — treba právo Faktúry — vystavovanie') : ' — objednávka nemá faktúru') ?></label>
    <label class="wide">Dôvod (na dobropise) <input name="reason" value="<?= $e($p('reason', 'Vrátenie tovaru')) ?>"></label>
    <label>Zmeniť stav objednávky
      <select name="state"<?= $hasApi ? '' : ' disabled' ?>><option value="0">— nemeniť —</option>
        <?php foreach ($states as $s): ?><option value="<?= (int)$s['id_order_state'] ?>"<?= (int)$p('state', 0) === (int)$s['id_order_state'] ? ' selected' : '' ?>><?= $e($s['name']) ?></option><?php endforeach ?>
      </select></label>
    <label class="check"><input type="checkbox" name="sendemail" value="1"<?= $p('sendemail') ? ' checked' : '' ?>> poslať zákazníkovi e-mail o stave</label>
    <label class="wide">Poznámka <input name="note" value="<?= $e($p('note')) ?>" placeholder="napr. vrátené poštou, tovar v poriadku"></label>
    <p class="wide muted small">Peniaze zákazníkovi: platbu kartou cez GoPay vráťte v administrácii GoPay, prevodom z internetbankingu —
      panel to zatiaľ nerobí sám. Úhradu dobropisu zapíšete pri dobropise (alebo ju spáruje import výpisu).</p>
    <p class="wide"><button class="primary" data-confirm="Zapísať vrátenie? Naskladnenie a zmena stavu sa vykonajú v e-shope hneď.">Zapísať vrátenie</button></p>
  </div>
</form>
