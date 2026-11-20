<?php use Panel\Invoicing\Numbering; /** @var array $filters, $docs, $warehouses; bool $canEdit */ ?>
<?= $view->partial('stock/tabs', ['tab' => 'docs']) ?>
<form class="filters" method="get" action="<?= $e($ctx->url('/stock/docs')) ?>">
  <select name="type"><option value="">Všetky doklady</option>
    <?php foreach (Numbering::STOCK_TYPES as $k => $label): ?><option value="<?= $e($k) ?>"<?= $filters['type'] === $k ? ' selected' : '' ?>><?= $e($label) ?></option><?php endforeach ?>
  </select>
  <select name="warehouse"><option value="">Všetky sklady</option>
    <?php foreach ($warehouses as $w): ?><option value="<?= (int)$w['id'] ?>"<?= $filters['warehouse'] === (int)$w['id'] ? ' selected' : '' ?>><?= $e($w['name']) ?></option><?php endforeach ?>
  </select>
  <button class="primary">Filtrovať</button>
</form>
<table class="grid">
  <thead><tr><th>Číslo</th><th>Doklad</th><th>Dátum</th><th>Sklad</th><th>Partner / odkaz</th><th class="num">Hodnota</th></tr></thead>
  <tbody>
  <?php foreach ($docs as $d): ?>
    <tr><td><a href="<?= $e($ctx->url('/stock/docs/' . $d['id'])) ?>"><?= $e($d['number']) ?></a></td>
      <td><?= $e(Numbering::STOCK_TYPES[$d['doc_type']]) ?></td><td><?= $e($f->date($d['doc_date'])) ?></td>
      <td><?= $e($d['warehouse']) ?><?= $d['target_warehouse'] ? ' → ' . $e($d['target_warehouse']) : '' ?></td>
      <td><?= $e(trim($d['partner'] . ($d['reference'] ? ' · ' . $d['reference'] : ''), ' ·')) ?></td>
      <td class="num"><?= $e($f->money($d['total_cost'])) ?></td></tr>
  <?php endforeach ?>
  <?php if (!$docs): ?><tr><td colspan="6" class="muted">Žiadne doklady.</td></tr><?php endif ?>
  </tbody>
</table>
