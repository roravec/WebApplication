<?php /** @var WebApplication\Entity\Client[] $employees; array $roleNames client id => role names */ use Panel\Rights; ?>
<?= $view->partial('employees/tabs', ['tab' => 'people']) ?>
<table class="grid">
  <thead><tr><th>Meno</th><th>E-mail</th><th>Rola</th><th>Stav</th><th>Naposledy</th></tr></thead>
  <tbody>
  <?php foreach ($employees as $c): ?>
    <tr class="<?= $c->isActive() ? '' : 'inactive' ?>">
      <td><a href="<?= $e($ctx->url('/employees/' . $c->getId())) ?>"><?= $e($c->getName()) ?></a></td>
      <td><?= $e($c->getIdentifier()) ?></td>
      <td><?= $c->getRights() >= Rights::ADMIN ? '<b>Správca</b>' : $e(implode(', ', $roleNames[$c->getId()] ?? []) ?: 'bez roly (len výnimky)') ?></td>
      <td><?= $c->isActive() ? 'aktívny' : 'neaktívny' ?></td>
      <td><?= $e($f->dateTime($c->last_seen)) ?></td>
    </tr>
  <?php endforeach ?>
  </tbody>
</table>
