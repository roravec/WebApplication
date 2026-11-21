<?php use Panel\Core\Notifications; use WebApplication\Csrf; /** @var array $rows; string $mode */
$unread = count(array_filter($rows, function ($r) { return $r['read_at'] === null; }));
?>
<div class="toolbar">
  <span class="muted">Neprečítané: <b><?= (int)$unread ?></b> · e-mailom: <?= $e(Notifications::MODES[$mode]) ?>
    (<a href="<?= $e($ctx->url('/account#notify')) ?>">zmeniť</a>)</span>
  <?php if ($unread): ?>
  <form method="post" action="<?= $e($ctx->url('/notifications/read-all')) ?>" class="inline push"><?= Csrf::field() ?><button>Označiť všetko ako prečítané</button></form>
  <?php endif ?>
</div>
<?php if (!$rows): ?>
  <p class="muted">Zatiaľ žiadne upozornenia. Prídu, keď vás niekto spomenie v komentári (@Vaše meno) alebo odpovie v diskusii, kde ste písali.</p>
<?php else: ?>
<table class="grid">
  <tbody>
  <?php foreach ($rows as $n): ?>
    <tr class="<?= $n['read_at'] === null ? 'unread' : 'muted' ?>">
      <td><?= $e($f->dateTime($n['created_at'])) ?></td>
      <td><a href="<?= $e($ctx->url('/notifications/' . (int)$n['id'])) ?>"><?= $n['read_at'] === null ? '<b>' . $e($n['text']) . '</b>' : $e($n['text']) ?></a></td>
    </tr>
  <?php endforeach ?>
  </tbody>
</table>
<?php endif ?>
