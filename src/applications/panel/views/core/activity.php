<?php use Panel\Audit; /** @var array $rows (audit rows with 'record'); int $next; bool $first — feed "Čo sa deje" */ ?>
<p class="field-hint">Posledné zmeny v paneli — len pri záznamoch, ku ktorým máte prístup. Kliknutím otvoríte záznam.</p>
<?php if (!$rows): ?>
  <p class="muted"><?= $first ? 'Zatiaľ sa nič nedeje.' : 'Staršie zmeny už nie sú.' ?></p>
<?php else: ?>
<table class="grid">
  <thead><tr><th>Kedy</th><th>Kto</th><th>Záznam</th><th>Čo urobil</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $a): ?>
    <tr>
      <td><?= $e($f->dateTime($a['created_at'])) ?></td>
      <td><?= $e($a['client_name']) ?></td>
      <td><a href="<?= $e($ctx->url($a['record']['url'])) ?>"><?= $e($a['record']['label']) ?> <?= $e($a['record']['title']) ?></a></td>
      <td><?= $e(Audit::label($a['action'])) ?> <span class="muted"><?= $e($a['details']) ?></span></td>
    </tr>
  <?php endforeach ?>
  </tbody>
</table>
<?php endif ?>
<p><?php if (!$first): ?><a href="<?= $e($ctx->url('/activity')) ?>">← Najnovšie</a> · <?php endif ?>
  <?php if ($next && $rows): ?><a href="<?= $e($ctx->url('/activity?before=' . $next)) ?>">Staršie →</a><?php endif ?></p>
