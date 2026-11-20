<?php use WebApplication\Csrf;
/** @var string $token, $iban; array $rows, $open, $openExp, $categories, $company; bool $in, $out */
$matched = count(array_filter($rows, function ($r) { return ($r['invoice_id'] || $r['expense_id']) && !$r['imported']; }));
$byLabel = ['vs' => 'podľa VS', 'iban' => 'podľa účtu dodávateľa a sumy'];
?>
<p><a href="<?= $e($ctx->url('/payments/import')) ?>">← Iný výpis</a></p>
<p class="muted">Účet <b><?= $e(trim(chunk_split($iban, 4, ' '))) ?></b> · <?= $e($company['name']) ?> · pohybov <?= count($rows) ?>,
  spárovaných <?= (int)$matched ?>. Zaškrtnuté riadky sa zapíšu:
  <?= $in ? 'prijaté platby ako úhrady faktúr' : '' ?><?= $in && $out ? ', ' : '' ?><?= $out ? 'odchádzajúce platby ako úhrady nákladov (alebo nový náklad, napr. bankový poplatok)' : '' ?>.
  Doklad môžete zmeniť.</p>
<form method="post" action="<?= $e($ctx->url('/payments/import/confirm')) ?>">
  <?= Csrf::field() ?>
  <input type="hidden" name="token" value="<?= $e($token) ?>">
  <div class="scroll-x">
  <table class="grid compact">
    <thead><tr><th></th><th>Dátum</th><th class="num">Suma</th><th>Platiteľ / príjemca</th><th>VS</th><th>Správa</th><th>Doklad</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $i => $r):
        $outgoing = $r['amount'] < 0;
        $usable = !$r['imported'] && ($outgoing ? $out : ($in && $r['amount'] > 0)); ?>
      <tr class="<?= $usable ? '' : 'inactive' ?>">
        <td><?php if ($usable): ?><input type="checkbox" name="use[<?= (int)$i ?>]" value="1"<?= $r['invoice_id'] || $r['expense_id'] ? ' checked' : '' ?>><?php endif ?></td>
        <td><?= $e($f->date($r['date'])) ?></td>
        <td class="num"><?= $e($f->money($r['amount'], $r['currency'] ?: 'EUR')) ?></td>
        <td><?= $e($r['payer']) ?><?php if ($r['payer_iban']): ?><br><small class="muted"><?= $e($r['payer_iban']) ?></small><?php endif ?></td>
        <td><?= $e($r['vs']) ?></td>
        <td><small><?= $e($r['message']) ?></small></td>
        <td>
          <?php if ($r['imported']): ?>
            <span class="badge issued">už zapísaná</span>
          <?php elseif (!$usable): ?>
            <span class="muted small"><?= $outgoing ? 'odchádzajúca platba' : 'prijatá platba' ?></span>
          <?php elseif ($outgoing): ?>
            <select name="expense[<?= (int)$i ?>]">
              <option value="0">— nepárovať —</option>
              <option value="new">+ nový náklad (bez dokladu, napr. poplatok)</option>
              <?php foreach ($openExp as $d): ?>
                <option value="<?= (int)$d['id'] ?>"<?= (int)$d['id'] === $r['expense_id'] ? ' selected' : '' ?>><?= $e($d['supplier_name'] . ' · ' . ($d['document_number'] ?: 'bez čísla') . ' · ' . $f->money($d['total'], $d['currency'])) ?></option>
              <?php endforeach ?>
            </select>
            <select name="category[<?= (int)$i ?>]" title="Kategória nového nákladu">
              <option value="">kategória nového nákladu…</option>
              <?php foreach ($categories as $c): ?><option value="<?= (int)$c['id'] ?>"><?= $e($c['name']) ?></option><?php endforeach ?>
            </select>
            <?php if ($r['expense_id']): ?><br><small class="muted">Spárované <?= $e($byLabel[$r['by']] ?? '') ?>.</small><?php endif ?>
            <?php if ($r['expense_id'] && !$r['exact']): ?><br><small class="field-err">Suma sa nezhoduje s dokladom — skontrolujte.</small><?php endif ?>
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
  <p class="actions"><button class="primary">Zapísať zaškrtnuté</button></p>
</form>
