<?php use Panel\Invoicing\Invoices; use Panel\Invoicing\Numbering; use Panel\Rights;
/** @var array $filters, $rows, $companies, $years; int $total, $page, $pages; string $sum */
$qs = function (array $change) use ($filters) {
    return '?' . http_build_query(array_filter(array_merge($filters, $change), function ($v) { return $v !== '' && $v !== 0 && $v !== null; }));
};
?>
<form class="filters" method="get" action="<?= $e($ctx->url('/invoices')) ?>">
  <input name="q" value="<?= $e($filters['q']) ?>" placeholder="Číslo, odberateľ, IČO, objednávka, VS">
  <?php if (count($companies) > 1): ?>
  <select name="company"><option value="">Všetky firmy</option>
    <?php foreach ($companies as $c): ?><option value="<?= (int)$c['id'] ?>"<?= $filters['company'] === (int)$c['id'] ? ' selected' : '' ?>><?= $e($c['name']) ?></option><?php endforeach ?>
  </select>
  <?php endif ?>
  <select name="doc_type"><option value="">Všetky doklady</option>
    <?php foreach (Numbering::TYPES as $k => $label): ?><option value="<?= $e($k) ?>"<?= $filters['doc_type'] === $k ? ' selected' : '' ?>><?= $e($label) ?></option><?php endforeach ?>
  </select>
  <select name="status"><option value="">Všetky stavy</option>
    <option value="draft"<?= $filters['status'] === 'draft' ? ' selected' : '' ?>>Koncepty</option>
    <option value="issued"<?= $filters['status'] === 'issued' ? ' selected' : '' ?>>Vystavené</option>
  </select>
  <select name="payment"><option value="">Úhrada: všetko</option>
    <?php foreach (['unpaid' => 'neuhradené', 'overdue' => 'po splatnosti', 'paid' => 'uhradené'] as $k => $label): ?><option value="<?= $e($k) ?>"<?= $filters['payment'] === $k ? ' selected' : '' ?>><?= $e($label) ?></option><?php endforeach ?>
  </select>
  <select name="year"><option value="">Všetky roky</option>
    <?php foreach ($years as $y): ?><option value="<?= (int)$y ?>"<?= $filters['year'] === $y ? ' selected' : '' ?>><?= (int)$y ?></option><?php endforeach ?>
  </select>
  <button class="primary">Hľadať</button>
  <a href="<?= $e($ctx->url('/invoices')) ?>">Zrušiť filter</a>
  <?php if ($ctx->can(Rights::INVOICES_EDIT)): ?><a class="button primary push" href="<?= $e($ctx->url('/invoices/new')) ?>">+ Nová faktúra</a><?php endif ?>
</form>
<p class="muted"><?= (int)$total ?> dokladov · vystavené spolu <?= $e($f->money($sum)) ?></p>
<table class="grid">
  <thead><tr><th>Číslo</th><th>Doklad</th><th>Odberateľ</th><?php if (count($companies) > 1): ?><th>Firma</th><?php endif ?><th>Vystavená</th><th>Splatná</th><th>Objednávka</th><th>Úhrada</th><th class="num">Suma</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td><a href="<?= $e($ctx->url('/invoices/' . $r['id'])) ?>"><?= $r['number'] ? $e($r['number']) : '<span class="badge draft">koncept</span>' ?></a></td>
      <td><?= $e(Invoices::typeLabel($r['doc_type'])) ?></td>
      <td><?= $e($r['buyer_name']) ?></td>
      <?php if (count($companies) > 1): ?><td><?= $e($r['company']) ?></td><?php endif ?>
      <td><?= $e($f->date($r['issue_date'])) ?></td>
      <td><?= $e($f->date($r['due_date'])) ?></td>
      <td><?php if ($r['shop_order_id'] && $ctx->can(Rights::ORDERS_VIEW)): ?><a href="<?= $e($ctx->orderUrl((int)$r['shop_connection_id'], (int)$r['shop_order_id'])) ?>"><?= $e($r['order_reference']) ?></a><?php else: ?><?= $e($r['order_reference']) ?><?php endif ?></td>
      <td><?php if ($r['status'] === 'issued'): $st = Invoices::paymentState($r, (float)$r['paid']); ?><span class="badge pay-<?= $e($st) ?>"><?= $e(Invoices::PAYMENT_STATES[$st]) ?></span><?php endif ?></td>
      <td class="num"><?= $e($f->money($r['total'], $r['currency'])) ?></td>
    </tr>
  <?php endforeach ?>
  <?php if (!$rows): ?><tr><td colspan="9" class="muted">Žiadne doklady.</td></tr><?php endif ?>
  </tbody>
</table>
<?php if ($pages > 1): ?>
  <nav class="pager">
    <?php if ($page > 1): ?><a href="<?= $e($qs(['page' => $page - 1])) ?>">← Novšie</a><?php endif ?>
    <span>Strana <?= (int)$page ?> z <?= (int)$pages ?></span>
    <?php if ($page < $pages): ?><a href="<?= $e($qs(['page' => $page + 1])) ?>">Staršie →</a><?php endif ?>
  </nav>
<?php endif ?>
