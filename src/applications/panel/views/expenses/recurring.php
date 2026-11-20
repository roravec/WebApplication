<?php use Panel\Expenses\Expenses;
/** @var array $rows, $companyNames, $shops; bool $canEdit */
?>
<?= $view->partial('expenses/tabs', ['tab' => 'recurring']) ?>
<p class="muted">Nájom, predplatné, telefón… Panel v deň ďalšieho dokladu sám vytvorí neuhradený náklad (pri platbe kartou uhradený);
  vy doplníte číslo dokladu a súbor, keď faktúra príde, a úhrada sa spáruje z výpisu.
  <?php if ($canEdit): ?><a class="button primary" href="<?= $e($ctx->url('/expenses/recurring/new')) ?>">+ Nový opakovaný náklad</a><?php endif ?></p>
<table class="grid">
  <thead><tr><th>Názov</th><th>Dodávateľ</th><th>Kategória</th><?php if ($companyNames): ?><th>Firma</th><?php endif ?><th>Ako často</th><th>Ďalší doklad</th><th>Do</th><th class="num">Suma</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr class="<?= $r['active'] ? '' : 'inactive' ?>">
      <td><a href="<?= $e($ctx->url('/expenses/recurring/' . $r['id'])) ?>"><?= $e($r['name']) ?></a><?= $r['active'] ? '' : ' <span class="badge">ukončený</span>' ?></td>
      <td><?= $e($r['supplier_name']) ?></td>
      <td><?= $e((string)$r['category']) ?><?php if ($r['shop_id'] && isset($shops[(int)$r['shop_id']])): ?> <span class="muted small">· <?= $e($shops[(int)$r['shop_id']]) ?></span><?php endif ?></td>
      <?php if ($companyNames): ?><td><?= $e($companyNames[$r['company_id']] ?? '') ?></td><?php endif ?>
      <td><?= $e(Expenses::INTERVALS[(int)$r['interval_months']] ?? '') ?></td>
      <td><?= $r['active'] ? $e($f->date($r['next_date'])) : '—' ?></td>
      <td><?= $e($f->date($r['end_date'])) ?></td>
      <td class="num"><?= $e($f->money($r['total'], $r['currency'])) ?></td>
    </tr>
  <?php endforeach ?>
  <?php if (!$rows): ?><tr><td colspan="8" class="muted">Žiadne opakované náklady. Nastavíte ich tu alebo tlačidlom „Nastaviť opakovanie“ na doklade.</td></tr><?php endif ?>
  </tbody>
</table>
