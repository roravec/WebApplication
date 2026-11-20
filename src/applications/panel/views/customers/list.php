<?php use Panel\Rights; /** @var string $q; array $rows, $companyNames; int $total, $page, $pages */ ?>
<form class="filters" method="get" action="<?= $e($ctx->url('/customers')) ?>">
  <input name="q" value="<?= $e($q) ?>" placeholder="Meno, IČO, e-mail, mesto">
  <button class="primary">Hľadať</button>
  <?php if ($ctx->can(Rights::INVOICES_EDIT)): ?><a class="button" href="<?= $e($ctx->url('/customers/new')) ?>">+ Nový odberateľ</a><?php endif ?>
</form>
<p class="muted"><?= (int)$total ?> odberateľov</p>
<table class="grid">
  <thead><tr><th>Meno / firma</th><th>Typ</th><th>IČO</th><th>Adresa</th><th>E-mail</th><?php if ($companyNames): ?><th>Firma</th><?php endif ?></tr></thead>
  <tbody>
  <?php foreach ($rows as $c): ?>
    <tr>
      <td><?php if ($ctx->can(Rights::INVOICES_EDIT)): ?><a href="<?= $e($ctx->url('/customers/' . $c['id'])) ?>"><?= $e($c['name']) ?></a><?php else: ?><?= $e($c['name']) ?><?php endif ?></td>
      <td><?= $c['type'] === 'b2b' ? 'firma' : 'osoba' ?></td>
      <td><?= $e($c['ico']) ?></td>
      <td><?= $e(trim($c['street'] . ', ' . $c['zip'] . ' ' . $c['city'], ', ')) ?></td>
      <td><?= $e($c['email']) ?></td>
      <?php if ($companyNames): ?><td><?= $e($companyNames[$c['company_id']] ?? '') ?></td><?php endif ?>
    </tr>
  <?php endforeach ?>
  </tbody>
</table>
<?php if ($pages > 1): ?>
  <nav class="pager">
    <?php if ($page > 1): ?><a href="?<?= $e(http_build_query(['q' => $q, 'page' => $page - 1])) ?>">← Predchádzajúca</a><?php endif ?>
    <span>Strana <?= (int)$page ?> z <?= (int)$pages ?></span>
    <?php if ($page < $pages): ?><a href="?<?= $e(http_build_query(['q' => $q, 'page' => $page + 1])) ?>">Ďalšia →</a><?php endif ?>
  </nav>
<?php endif ?>
