<?php use WebApplication\Csrf;
/** @var array $sources, $states, $picked, $shops, $rows, $details, $notes; Panel\Shop\OrderSource $source; int $src, $shop; string|null $error; bool $canEdit */
$color = function ($c) { return preg_match('/^#[0-9a-fA-F]{3,6}$/', (string)$c) ? $c : '#999'; };
?>
<p class="muted">Objednávky na vybavenie: označte ich a vytlačte dodacie listy / vychystávací zoznam, zapíšte čísla zásielok
  alebo zmeňte stav naraz (v e-shope, s e-mailom zákazníkovi). Zmeny idú cez Webservice e-shopu.</p>
<form class="filters" method="get" action="<?= $e($ctx->url('/shipping')) ?>">
  <?php if (count($sources) > 1): ?>
    <select name="src"><?php foreach ($sources as $id => $s): ?><option value="<?= (int)$id ?>"<?= (int)$id === $src ? ' selected' : '' ?>><?= $e($s->label()) ?></option><?php endforeach ?></select>
  <?php else: ?><input type="hidden" name="src" value="<?= (int)$src ?>"><?php endif ?>
  <?php if (count($shops) > 1): ?>
    <select name="shop"><option value="0">Všetky e-shopy</option><?php foreach ($shops as $s): ?><option value="<?= (int)$s['id_shop'] ?>"<?= (int)$s['id_shop'] === $shop ? ' selected' : '' ?>><?= $e($s['name'] . (isset($s['send_email']) && !(int)$s['send_email'] ? ' (bez e-mailu)' : '')) ?></option><?php endforeach ?></select>
  <?php endif ?>
  <details style="display: inline-block"><summary>Stavy (<?= count($picked) ?>)</summary>
    <div class="card" style="position: absolute; z-index: 5; max-height: 22rem; overflow: auto">
      <?php foreach ($states as $s): ?><label class="check" style="display: block"><input type="checkbox" name="states[]" value="<?= (int)$s['id_order_state'] ?>"<?= in_array((int)$s['id_order_state'], $picked, true) ? ' checked' : '' ?>> <?= $e($s['name']) ?></label><?php endforeach ?>
    </div>
  </details>
  <button class="primary">Zobraziť</button>
</form>
<?php if ($canEdit): ?>
  <form method="post" action="<?= $e($ctx->url('/shipping')) ?>" class="inline">
    <?= Csrf::field() ?><input type="hidden" name="src" value="<?= (int)$src ?>"><input type="hidden" name="action" value="save_states">
    <?php foreach ($picked as $id): ?><input type="hidden" name="states[]" value="<?= (int)$id ?>"><?php endforeach ?>
    <button class="link" title="Tieto stavy sa budú v Expedícii zobrazovať predvolene">Zapamätať vybrané stavy</button>
  </form>
<?php endif ?>
<?php if ($error): ?><p class="flash err"><?= $e($error) ?></p><?php endif ?>

<form method="post" action="<?= $e($ctx->url('/shipping')) ?>" id="ship-form">
  <?= Csrf::field() ?>
  <input type="hidden" name="src" value="<?= (int)$src ?>">
  <div class="toolbar">
    <button name="action" value="delivery" formtarget="_blank">Dodacie listy (PDF)</button>
    <button name="action" value="picking" formtarget="_blank">Vychystávací zoznam (PDF)</button>
    <?php if ($canEdit): ?>
      <button name="action" value="tracking">Uložiť čísla zásielok</button>
      <span>
        <select name="state"><option value="0">Zmeniť stav vybraných na…</option>
          <?php foreach ($states as $s): ?><option value="<?= (int)$s['id_order_state'] ?>"><?= $e($s['name'] . (isset($s['send_email']) && !(int)$s['send_email'] ? ' (bez e-mailu)' : '')) ?></option><?php endforeach ?>
        </select>
        <label class="check"><input type="checkbox" name="sendemail" value="1" checked> s e-mailom</label>
        <button name="action" value="state" data-confirm="Zmeniť stav vybraných objednávok v e-shope?">Zmeniť</button>
      </span>
    <?php endif ?>
  </div>
  <table class="grid">
    <thead><tr><th><input type="checkbox" data-check-all="ids[]" title="Označiť všetky"></th><th>Objednávka</th><th>Dátum</th><th>Zákazník</th><th>E-shop</th>
      <th>Doprava</th><th>Platba</th><th>Stav</th><th class="num">Ks</th><th class="num">Spolu</th><th>Číslo zásielky</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $dt = $details[(int)$r['id_order']] ?? ['carrier' => '', 'tracking' => '', 'items' => 0]; ?>
      <tr>
        <td><input type="checkbox" name="ids[]" value="<?= (int)$r['id_order'] ?>"></td>
        <td><a href="<?= $e($ctx->orderUrl($src, (int)$r['id_order'])) ?>"><?= $e($r['reference']) ?></a><?= !empty($notes[(int)$r['id_order']]) ? ' <span title="má interné poznámky">📝</span>' : '' ?></td>
        <td><?= $e($f->dateTime($r['date_add'])) ?></td>
        <td><?= $e(trim(($r['firstname'] ?? '') . ' ' . ($r['lastname'] ?? '')) ?: ($r['company'] ?? '')) ?></td>
        <td><?= $e($r['shop']) ?></td>
        <td><?= $e($dt['carrier']) ?></td>
        <td><?= $e($r['payment']) ?></td>
        <td><span class="state" style="--c: <?= $e($color($r['state_color'] ?? '')) ?>"><?= $e($r['state']) ?></span></td>
        <td class="num"><?= (int)$dt['items'] ?></td>
        <td class="num"><?= $e($f->money($r['total_paid_tax_incl'], $r['currency'] ?? 'EUR')) ?></td>
        <td><?php if ($canEdit): ?><input type="hidden" name="tracking_old[<?= (int)$r['id_order'] ?>]" value="<?= $e($dt['tracking']) ?>"><input name="tracking[<?= (int)$r['id_order'] ?>]" value="<?= $e($dt['tracking']) ?>" size="16"><?php else: ?><?= $e($dt['tracking']) ?><?php endif ?></td>
      </tr>
    <?php endforeach ?>
    <?php if (!$rows): ?><tr><td colspan="11" class="muted"><?= $picked ? 'Nič na vybavenie.' : 'Vyberte stavy objednávok na vybavenie.' ?></td></tr><?php endif ?>
    </tbody>
  </table>
  <p class="muted small"><?= count($rows) ?> objednávok<?= count($rows) >= 200 ? ' (zobrazených prvých 200)' : '' ?>. Naraz sa spracuje najviac 100 označených.</p>
</form>
