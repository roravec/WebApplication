<?php use Panel\Rights; /** @var WebApplication\Entity\Client[] $employees */ ?>
<p><a class="button primary" href="<?= $e($ctx->url('/employees/new')) ?>">+ Nový zamestnanec</a></p>
<table class="grid">
  <thead><tr><th>Meno</th><th>Prihlasovacie meno</th><th>Oprávnenie</th><th>Stav</th><th>Naposledy</th></tr></thead>
  <tbody>
  <?php foreach ($employees as $c): ?>
    <tr class="<?= $c->isActive() ? '' : 'inactive' ?>">
      <td><a href="<?= $e($ctx->url('/employees/' . $c->getId())) ?>"><?= $e($c->getName()) ?></a></td>
      <td><?= $e($c->getIdentifier()) ?></td>
      <td><?= $e(Rights::label($c->getRights())) ?></td>
      <td><?= $c->isActive() ? 'aktívny' : 'neaktívny' ?></td>
      <td><?= $e($f->dateTime($c->last_seen)) ?></td>
    </tr>
  <?php endforeach ?>
  </tbody>
</table>
