<?php /** @var array $people list of [client, access, effective (key => i)], $groups, $roleNames, $companyNames, $shopNames */ ?>
<?= $view->partial('employees/tabs', ['tab' => 'overview']) ?>
<p class="field-hint">Čo smú aktívni zamestnanci — súčet ich rolí a výnimiek. Zmeniť to môžete kliknutím na meno.</p>
<?php if (!$people): ?><p class="muted">Žiadny aktívny zamestnanec.</p><?php else: ?>
<div class="scroll-x">
<table class="grid compact">
  <thead><tr><th>Právo</th>
    <?php foreach ($people as $p): ?><th><a href="<?= $e($ctx->url('/employees/' . $p['client']->getId())) ?>"><?= $e($p['client']->getName()) ?></a></th><?php endforeach ?>
  </tr></thead>
  <tbody>
    <tr><th>Rola</th>
      <?php foreach ($people as $p): ?><td><?= $p['access']['admin'] ? '<b>Správca</b>' : $e(implode(', ', $roleNames[$p['client']->getId()] ?? []) ?: '—') ?></td><?php endforeach ?></tr>
    <tr><th>Firmy</th>
      <?php foreach ($people as $p): $a = $p['access']; ?>
        <td><?= $a['admin'] || $a['all_companies'] ? 'všetky' : $e(implode(', ', array_map(function ($id) use ($companyNames) { return $companyNames[$id] ?? '#' . $id; }, $a['companies'])) ?: 'žiadna') ?></td>
      <?php endforeach ?></tr>
    <tr><th>E-shopy</th>
      <?php foreach ($people as $p): $a = $p['access']; ?>
        <td><?= $a['admin'] || $a['all_shops'] ? 'všetky' : $e(implode(', ', array_map(function ($k) use ($shopNames) { return $shopNames[$k] ?? $k; }, $a['shops'])) ?: 'žiadny') ?></td>
      <?php endforeach ?></tr>
    <?php foreach ($groups as $group => $perms): ?>
      <tr><th colspan="<?= count($people) + 1 ?>"><?= $e($group) ?></th></tr>
      <?php foreach ($perms as $key => $def): ?>
        <tr><td><?= $e($def['label']) ?><?= $def['sensitive'] ? ' <span class="badge">citlivé</span>' : '' ?></td>
          <?php foreach ($people as $p): $on = isset($p['effective'][$key]); ?><td class="result <?= $on ? 'yes' : 'no' ?>"><?= $on ? '✓' : '–' ?></td><?php endforeach ?>
        </tr>
      <?php endforeach ?>
    <?php endforeach ?>
  </tbody>
</table>
</div>
<?php endif ?>
