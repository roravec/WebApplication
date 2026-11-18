<?php /** @var array $rows */
$color = function ($c) { return preg_match('/^#[0-9a-fA-F]{3,6}$/', (string)$c) ? $c : '#999'; };
?>
<table class="grid">
  <thead><tr><th>Číslo</th><th>Dátum</th><th>E-shop</th><th>Zákazník</th><th>Platba</th><th>Stav</th><th class="num">Suma</th></tr></thead>
  <tbody>
  <?php if (!$rows): ?><tr><td colspan="7" class="muted">Žiadne objednávky.</td></tr><?php endif ?>
  <?php foreach ($rows as $o): ?>
    <tr>
      <td><a href="<?= $e($ctx->url('/orders/' . (int)$o['id_order'])) ?>"><?= $e($o['reference']) ?></a></td>
      <td><?= $e($f->dateTime($o['date_add'])) ?></td>
      <td><?= $e($o['shop']) ?></td>
      <td><?= $e(trim($o['firstname'] . ' ' . $o['lastname'])) ?><?php if ($o['company']): ?><br><small class="muted"><?= $e($o['company']) ?></small><?php endif ?></td>
      <td><?= $e($o['payment']) ?></td>
      <td><span class="state" style="--c: <?= $e($color($o['state_color'])) ?>"><?= $e($o['state']) ?></span></td>
      <td class="num"><?= $e($f->money($o['total_paid_tax_incl'], (string)$o['currency'])) ?></td>
    </tr>
  <?php endforeach ?>
  </tbody>
</table>
