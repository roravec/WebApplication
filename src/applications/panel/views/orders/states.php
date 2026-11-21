<?php /** @var array $states, $sources; string|null $error; int $src */
$color = function ($c) { return preg_match('/^#[0-9a-fA-F]{3,6}$/', (string)$c) ? $c : '#999'; };
$q = $src ? '?src=' . $src : '';
?>
<p><a href="<?= $e($ctx->url('/settings/eshops')) ?>">← Pripojené e-shopy</a></p>
<?php if (count($sources) > 1): ?>
  <form class="filters" method="get" action="<?= $e($ctx->url('/settings/eshops/states')) ?>">
    <select name="src" onchange="this.form.submit()"><?php foreach ($sources as $id => $label): ?><option value="<?= (int)$id ?>"<?= (int)$id === $src ? ' selected' : '' ?>><?= $e($label) ?></option><?php endforeach ?></select>
  </form>
<?php endif ?>
<?php if ($error): ?><p class="flash err"><?= $e($error) ?></p><?php else: ?>
<p class="muted">Stavy objednávok sa ukladajú priamo v e-shope (ako Objednávky → Stavy v jeho administrácii). E-mail pri zmene
  stavu pošle e-shop len vtedy, keď ho má stav zapnutý.</p>
<p><a class="button" href="<?= $e($ctx->url('/settings/eshops/states/0' . $q)) ?>">+ Nový stav</a></p>
<table class="grid">
  <thead><tr><th>Stav</th><th>E-mail zákazníkovi</th><th>Nastavenie</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($states as $s):
      $flags = [];
      foreach (['paid' => 'zaplatená', 'shipped' => 'odoslaná', 'logable' => 'platná', 'delivery' => 'dodací list', 'hidden' => 'skrytý'] as $k => $label) {
          if ($s[$k]) { $flags[] = $label; }
      } ?>
    <tr><td><span class="state" style="--c: <?= $e($color($s['color'])) ?>"><?= $e($s['name']) ?></span><?php if ($s['module_name'] !== ''): ?> <small class="muted">(modul <?= $e($s['module_name']) ?>)</small><?php endif ?></td>
      <td><?php if ($s['send_email']): ?>áno · <?= $e($s['template']) ?>
          <?php elseif ($s['template'] !== ''): ?><b>nie</b> <small class="muted">(šablóna <?= $e($s['template']) ?> je nastavená, ale vypnutá)</small>
          <?php else: ?><span class="muted">nie</span><?php endif ?></td>
      <td class="small"><?= $e(implode(', ', $flags)) ?></td>
      <td><a href="<?= $e($ctx->url('/settings/eshops/states/' . $s['id'] . $q)) ?>">Upraviť</a></td></tr>
  <?php endforeach ?>
  </tbody>
</table>
<?php endif ?>
