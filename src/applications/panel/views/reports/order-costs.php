<?php use Panel\Reports\OrderCosts; use WebApplication\Csrf;
/** @var array $rules, $shops, $carriers, $payments, $errors; bool $global, $canEdit — module D */
$num = function ($v, $dec) { return $v === '' || $v === null ? '' : rtrim(rtrim(number_format((float)$v, $dec, ',', ''), '0'), ','); };
$rows = $rules;
if ($canEdit) {
    for ($i = 0; $i < 4; $i++) {
        $rows[] = ['id' => '', 'shop_id' => null, 'type' => '', 'match_key' => '', 'fixed' => '', 'percent' => '', 'valid_from' => '', 'valid_to' => '', 'note' => '', 'new' => true];
    }
}
$dis = $canEdit ? '' : ' disabled';
?>
<?= $view->partial('reports/tabs', ['tab' => 'order-costs']) ?>
<p class="muted">Čo vás stojí objednávka okrem tovaru: <b>doprava</b> podľa dopravcu (cena Packety, pošty…), <b>poplatok za platbu</b>
  (GoPay, dobierka: % zo sumy objednávky + pevná suma) a <b>balné</b> (krabica, výplň). Predaje a marža z nich počítajú
  <b>príspevok na krytie</b> = hrubá marža + doprava zaplatená zákazníkom − tieto náklady. Sumy ako váš náklad: bez DPH, ak
  ste platiteľ DPH, inak s DPH. Pri zmene ceny neprepisujte starú — dajte jej „do“ a pridajte novú „od“, staré objednávky
  ostanú so starou cenou. Platí najkonkrétnejšie pravidlo (e-shop + dopravca &gt; dopravca &gt; e-shop &gt; všetky).</p>
<?php if ($errors): ?><div class="flash err"><ul><?php foreach ($errors as $m): ?><li><?= $e($m) ?></li><?php endforeach ?></ul></div><?php endif ?>
<form method="post" action="<?= $e($ctx->url('/reports/order-costs')) ?>">
  <?= Csrf::field() ?>
  <div class="scroll-x">
  <table class="grid compact">
    <thead><tr><th>Druh</th><th>E-shop</th><th>Dopravca / platba</th><th class="num">€ za objednávku</th><th class="num">% zo sumy</th>
      <th>Platí od</th><th>do</th><th>Poznámka</th><?php if ($canEdit): ?><th>Zmazať</th><?php endif ?></tr></thead>
    <tbody>
    <?php foreach ($rows as $i => $r): $sid = $r['shop_id'] === null || $r['shop_id'] === '' ? '' : (int)$r['shop_id']; ?>
      <tr data-oc-row>
        <td><input type="hidden" name="rule[<?= $i ?>][id]" value="<?= $e((string)$r['id']) ?>">
          <select name="rule[<?= $i ?>][type]" data-oc-type<?= $dis ?>><?= !empty($r['new']) ? '<option value="">— nové —</option>' : '' ?>
            <?php foreach (OrderCosts::TYPES as $k => $label): ?><option value="<?= $e($k) ?>"<?= $r['type'] === $k ? ' selected' : '' ?>><?= $e($label) ?></option><?php endforeach ?>
          </select></td>
        <td><select name="rule[<?= $i ?>][shop_id]"<?= $dis ?>>
            <?php if ($global || $sid === ''): ?><option value="">všetky e-shopy</option><?php endif ?>
            <?php foreach ($shops as $id => $name): ?><option value="<?= (int)$id ?>"<?= $sid === (int)$id ? ' selected' : '' ?>><?= $e($name) ?></option><?php endforeach ?>
          </select></td>
        <td>
          <select name="rule[<?= $i ?>][carrier]" data-oc-for="shipping"<?= $dis ?>><option value="">každý dopravca</option>
            <?php $known = isset($carriers[$r['match_key']]); foreach ($carriers as $k => $name): ?><option value="<?= $e((string)$k) ?>"<?= $r['type'] === 'shipping' && (string)$r['match_key'] === (string)$k ? ' selected' : '' ?>><?= $e($name) ?></option><?php endforeach ?>
            <?php if ($r['type'] === 'shipping' && $r['match_key'] !== '' && !$known): ?><option value="<?= $e($r['match_key']) ?>" selected><?= $e($r['label'] ?? $r['match_key']) ?></option><?php endif ?>
          </select>
          <select name="rule[<?= $i ?>][payment]" data-oc-for="payment"<?= $dis ?>><option value="">každá platba</option>
            <?php $known = isset($payments[$r['match_key']]); foreach ($payments as $k => $name): ?><option value="<?= $e($k) ?>"<?= $r['type'] === 'payment' && $r['match_key'] === $k ? ' selected' : '' ?>><?= $e($name . ' (' . $k . ')') ?></option><?php endforeach ?>
            <?php if ($r['type'] === 'payment' && $r['match_key'] !== '' && !$known): ?><option value="<?= $e($r['match_key']) ?>" selected><?= $e($r['label'] ?? $r['match_key']) ?></option><?php endif ?>
          </select>
          <span class="muted small" data-oc-for="packaging">každá objednávka</span>
        </td>
        <td class="num"><input name="rule[<?= $i ?>][fixed]" class="num" size="7" value="<?= $e($num($r['fixed'], 2)) ?>" inputmode="decimal"<?= $dis ?>></td>
        <td class="num"><input name="rule[<?= $i ?>][percent]" class="num" size="5" value="<?= $e($num($r['percent'], 3)) ?>" inputmode="decimal"<?= $dis ?>></td>
        <td><input type="date" name="rule[<?= $i ?>][valid_from]" value="<?= $e((string)$r['valid_from']) ?>"<?= $dis ?>></td>
        <td><input type="date" name="rule[<?= $i ?>][valid_to]" value="<?= $e((string)$r['valid_to']) ?>"<?= $dis ?>></td>
        <td><input name="rule[<?= $i ?>][note]" value="<?= $e((string)$r['note']) ?>" size="18"<?= $dis ?>></td>
        <?php if ($canEdit): ?><td><?php if (empty($r['new'])): ?><input type="checkbox" name="rule[<?= $i ?>][delete]" value="1"><?php endif ?></td><?php endif ?>
      </tr>
    <?php endforeach ?>
    <?php if (!$rows): ?><tr><td colspan="8" class="muted">Žiadne pravidlá.</td></tr><?php endif ?>
    </tbody>
  </table>
  </div>
  <?php if ($canEdit): ?><p class="actions"><button class="primary">Uložiť</button> <span class="muted small">Viac riadkov pribudne po uložení.</span></p><?php endif ?>
</form>
<p class="muted small">Skutočné faktúry dopravcov a platobných brán zadávajte do Nákladov (kategórie druhu „náklady na objednávky“) —
  prehľad výsledku ich porovná s týmto odhadom.</p>
