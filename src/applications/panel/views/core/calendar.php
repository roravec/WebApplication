<?php use Panel\Core\Calendar; /** @var string $month (Y-m); array $weeks, $events (date => list), $upcoming */
$today = date('Y-m-d');
$prev = date('Y-m', strtotime($month . '-01 -1 month'));
$next = date('Y-m', strtotime($month . '-01 +1 month'));
$months = ['', 'január', 'február', 'marec', 'apríl', 'máj', 'jún', 'júl', 'august', 'september', 'október', 'november', 'december'];
?>
<div class="toolbar">
  <a class="button" href="<?= $e($ctx->url('/calendar?month=' . $prev)) ?>">←</a>
  <b><?= $e($months[(int)substr($month, 5)] . ' ' . substr($month, 0, 4)) ?></b>
  <a class="button" href="<?= $e($ctx->url('/calendar?month=' . $next)) ?>">→</a>
  <a href="<?= $e($ctx->url('/calendar')) ?>">dnes</a>
  <span class="muted small push">Termíny z celého panela, ku ktorým máte prístup: splatnosti faktúr a dokladov, opakované náklady … (pribudnú úlohy, prenájmy, kalibrácie).</span>
</div>
<div class="scroll-x">
<table class="cal">
  <thead><tr><?php foreach (['Po', 'Ut', 'St', 'Št', 'Pi', 'So', 'Ne'] as $d): ?><th><?= $d ?></th><?php endforeach ?></tr></thead>
  <tbody>
  <?php foreach ($weeks as $w): ?>
    <tr>
    <?php foreach ($w as $d): ?>
      <td class="<?= substr($d, 0, 7) !== $month ? 'other' : '' ?><?= $d === $today ? ' today' : '' ?>">
        <span class="day"><?= (int)substr($d, 8) ?></span>
        <?php foreach ($events[$d] ?? [] as $ev): ?>
          <a href="<?= $e($ctx->url($ev['url'])) ?>" class="k-<?= $e($ev['kind']) ?>" title="<?= $e($ev['title'] . ' — ' . (Calendar::KINDS[$ev['kind']] ?? '')) ?>"><?= $e($ev['title']) ?></a>
        <?php endforeach ?>
      </td>
    <?php endforeach ?>
    </tr>
  <?php endforeach ?>
  </tbody>
</table>
</div>

<h2>Najbližších 14 dní</h2>
<?php if (!$upcoming): ?><p class="muted">Žiadne termíny.</p><?php endif ?>
<table class="grid compact"><tbody>
<?php foreach ($upcoming as $d => $list): foreach ($list as $ev): ?>
  <tr><td><?= $e($f->date($d)) ?></td><td><a href="<?= $e($ctx->url($ev['url'])) ?>" class="k-<?= $e($ev['kind']) ?>"><?= $e($ev['title']) ?></a></td>
    <td class="muted small"><?= $e(Calendar::KINDS[$ev['kind']] ?? '') ?></td></tr>
<?php endforeach; endforeach ?>
</tbody></table>
