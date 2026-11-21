<?php use Panel\Core\Permissions; use WebApplication\Csrf; /** @var array $keys (all, with owner_name, calls) */
$all = Permissions::all();
?>
<?= $view->partial('settings/system-tabs', ['tab' => 'api']) ?>
<p class="field-hint">Všetky API kľúče v paneli. Kľúče si vytvára každý sám v <b>Môj účet</b> (treba právo „API — vlastné API kľúče“); tu ich vidíte
  a môžete zrušiť. Kľúč pracuje s právami svojho autora (najviac s tými, ktoré mu autor dal).</p>
<?php if (!$keys): ?><p class="muted">Zatiaľ žiadny kľúč.</p><?php else: ?>
<table class="grid">
  <thead><tr><th>Autor</th><th>Názov</th><th>Kľúč</th><th>Práva</th><th>Platí do</th><th>Posledné volania</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($keys as $k): ?>
    <tr class="<?= $k['revoked_at'] ? 'muted' : '' ?>">
      <td><?= $e($k['owner_name'] ?? '#' . $k['client_id']) ?></td>
      <td><?= $e($k['name']) ?></td>
      <td><code>pk_<?= $e($k['prefix']) ?>_…</code></td>
      <td><small><?= $e(implode(', ', array_map(function ($p) use ($all) { return $all[$p]['label'] ?? $p; }, $k['permissions'])) ?: 'čítanie a komentáre') ?></small></td>
      <td><?= $k['expires_at'] ? $e($f->date($k['expires_at'])) : 'bez obmedzenia' ?></td>
      <td><small><?php foreach ($k['calls'] as $c): ?><?= $e($f->dateTime($c['created_at'])) ?> <?= $e($c['method'] . ' ' . $c['path']) ?> → <?= (int)$c['status'] ?><br><?php endforeach ?>
        <?= $k['calls'] ? '' : '<span class="muted">žiadne</span>' ?></small></td>
      <td><?php if ($k['revoked_at']): ?><span class="muted">zrušený (<?= $e($k['revoked_by']) ?>)</span><?php else: ?>
        <form method="post" action="<?= $e($ctx->url('/settings/api-keys/' . (int)$k['id'] . '/revoke')) ?>" onsubmit="return confirm('Zrušiť kľúč?')">
          <?= Csrf::field() ?><button class="link link-danger">zrušiť</button></form><?php endif ?></td>
    </tr>
  <?php endforeach ?>
  </tbody>
</table>
<?php endif ?>
