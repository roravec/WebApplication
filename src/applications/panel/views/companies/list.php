<?php /** @var array $companies with vat, series */ ?>
<?php if ($ctx->can(\Panel\Rights::COMPANIES_CREATE)): ?>
  <p><a class="button primary" href="<?= $e($ctx->url('/companies/new')) ?>">+ Nová firma</a></p>
<?php endif ?>
<?php if (!$companies): ?>
  <p class="muted">Zatiaľ nie je založená žiadna firma<?= $ctx->allCompanies() ? '' : ', ku ktorej máte prístup' ?>.</p>
<?php else: ?>
<table class="grid">
  <thead><tr><th>Firma</th><th>IČO</th><th>DPH dnes</th><th>Číselné rady</th><th>Stav</th></tr></thead>
  <tbody>
  <?php foreach ($companies as $c): ?>
    <tr class="<?= $c['active'] ? '' : 'inactive' ?>">
      <td><a href="<?= $e($ctx->url('/companies/' . $c['id'])) ?>"><?= $e($c['name']) ?></a><br><small class="muted"><?= $e(trim($c['street'] . ', ' . $c['zip'] . ' ' . $c['city'], ', ')) ?></small></td>
      <td><?= $e($c['ico']) ?></td>
      <td><?= $c['vat']['vat_payer'] ? 'platiteľ · ' . $e($c['vat']['icdph']) : 'neplatiteľ' ?></td>
      <td><?php foreach ($c['series'] as $s): ?><code><?= $e($s['format']) ?></code> <?php endforeach ?></td>
      <td><?= $c['active'] ? 'aktívna' : 'neaktívna' ?></td>
    </tr>
  <?php endforeach ?>
  </tbody>
</table>
<?php endif ?>
