<?php use WebApplication\Csrf;
/** @var string $token, $iban; array $rows, $open, $company */
$matched = count(array_filter($rows, function ($r) { return $r['invoice_id'] && !$r['imported']; }));
?>
<p><a href="<?= $e($ctx->url('/payments/import')) ?>">← Iný výpis</a></p>
<p class="muted">Účet <b><?= $e(trim(chunk_split($iban, 4, ' '))) ?></b> · <?= $e($company['name']) ?> · pohybov <?= count($rows) ?>,
  spárovaných <?= (int)$matched ?>. Zaškrtnuté riadky sa zapíšu ako úhrady; doklad môžete zmeniť.</p>
<form method="post" action="<?= $e($ctx->url('/payments/import/confirm')) ?>">
  <?= Csrf::field() ?>
  <input type="hidden" name="token" value="<?= $e($token) ?>">
  <div class="scroll-x">
  <table class="grid compact">
    <thead><tr><th></th><th>Dátum</th><th class="num">Suma</th><th>Platiteľ</th><th>VS</th><th>Správa</th><th>Doklad</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $i => $r): ?>
      <tr class="<?= $r['imported'] || $r['amount'] < 0 ? 'inactive' : '' ?>">
        <td><input type="checkbox" name="use[<?= (int)$i ?>]" value="1"<?= $r['invoice_id'] && !$r['imported'] && $r['amount'] > 0 ? ' checked' : '' ?><?= $r['imported'] ? ' disabled' : '' ?>></td>
        <td><?= $e($f->date($r['date'])) ?></td>
        <td class="num"><?= $e($f->money($r['amount'], $r['currency'] ?: 'EUR')) ?></td>
        <td><?= $e($r['payer']) ?><?php if ($r['payer_iban']): ?><br><small class="muted"><?= $e($r['payer_iban']) ?></small><?php endif ?></td>
        <td><?= $e($r['vs']) ?></td>
        <td><small><?= $e($r['message']) ?></small></td>
        <td>
          <?php if ($r['imported']): ?>
            <span class="badge issued">už zapísaná</span>
          <?php else: ?>
            <select name="invoice[<?= (int)$i ?>]">
              <option value="0">— nepárovať —</option>
              <?php foreach ($open as $d): ?>
                <option value="<?= (int)$d['id'] ?>"<?= (int)$d['id'] === $r['invoice_id'] ? ' selected' : '' ?>><?= $e($d['number'] . ' · ' . $d['buyer_name'] . ' · zostáva ' . $f->money((float)$d['total'] - (float)$d['paid'])) ?></option>
              <?php endforeach ?>
            </select>
            <?php if ($r['invoice_id'] && !$r['exact']): ?><br><small class="field-err">Suma sa nezhoduje so zostatkom — zapíše sa ako čiastočná úhrada.</small><?php endif ?>
          <?php endif ?>
        </td>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table>
  </div>
  <p class="actions"><button class="primary">Zapísať zaškrtnuté úhrady</button></p>
</form>
