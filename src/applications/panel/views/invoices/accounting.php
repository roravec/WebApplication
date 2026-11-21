<?php use Panel\Invoicing\AccountingPackage; use Panel\Rights; use WebApplication\Csrf;
/** @var array $companies, $periods, $settings, $preview, $history; int $cid; string $period; bool $configured */
$pkg = $preview['package'];
$sent = $pkg && $pkg['status'] === 'sent';
?>
<form class="filters" method="get" action="<?= $e($ctx->url('/accounting')) ?>">
  <?php if (count($companies) > 1): ?>
  <select name="company" onchange="this.form.submit()"><?php foreach ($companies as $c): ?><option value="<?= (int)$c['id'] ?>"<?= $cid === (int)$c['id'] ? ' selected' : '' ?>><?= $e($c['name']) ?></option><?php endforeach ?></select>
  <?php else: ?><input type="hidden" name="company" value="<?= (int)$cid ?>"><?php endif ?>
  <select name="period" onchange="this.form.submit()"><?php foreach ($periods as $p => $label): ?><option value="<?= $e($p) ?>"<?= $period === $p ? ' selected' : '' ?>><?= $e($label) ?></option><?php endforeach ?></select>
  <noscript><button>Zobraziť</button></noscript>
</form>

<section class="card">
  <h2>Doklady za <?= $e(AccountingPackage::label($period)) ?></h2>
  <p>Jeden e-mail pre účtovníka: <b>súbor na import do Omegy</b>, <b>PDF všetkých dokladov</b>, prehľad dokladov a prijatých úhrad
    (do Excelu). Každý doklad ide len v jednom balíku — aj neskoro vystavené doklady z minulých mesiacov sa pridajú do najbližšieho.</p>
  <p>
    <?php if ($sent): ?>
      <span class="badge pay-paid">odoslané</span> <?= $e($f->dateTime($pkg['sent_at'])) ?> na <?= $e($pkg['recipients']) ?> ·
      <?= $e(AccountingPackage::docs(count((array)json_decode((string)$pkg['doc_ids'], true)))) ?><?php $sentExp = count((array)json_decode((string)($pkg['expense_ids'] ?? '[]'), true)); if ($sentExp): ?>, prijaté: <?= $e(AccountingPackage::docs($sentExp)) ?><?php endif ?>
    <?php else: ?>
      Pripravené: <b><?= $e(AccountingPackage::docs((int)$preview['docs'])) ?></b> (do Omegy <?= (int)$preview['omega'] ?>), úhrady: <b><?= (int)$preview['payments'] ?></b>, prijaté doklady (náklady): <b><?= (int)$preview['expenses'] ?></b>.
      <?php if ($pkg && $pkg['status'] === 'failed'): ?><br><span class="badge pay-overdue">neodoslané</span> <?= $e($pkg['error']) ?><?php endif ?>
    <?php endif ?>
  </p>
  <div class="actions">
    <a class="button" href="<?= $e($ctx->url('/accounting/download?company=' . $cid . '&period=' . $period)) ?>">Stiahnuť ZIP</a>
    <?php if ($ctx->can(Rights::INVOICES_EDIT)): ?>
    <form method="post" action="<?= $e($ctx->url('/accounting/send')) ?>" class="inline">
      <?= Csrf::field() ?><input type="hidden" name="company" value="<?= (int)$cid ?>"><input type="hidden" name="period" value="<?= $e($period) ?>">
      <?php if ($sent): ?><input type="hidden" name="again" value="1"><?php endif ?>
      <button class="primary"<?= !$configured || trim((string)$settings['email']) === '' ? ' disabled' : '' ?>
        <?= $sent ? ' onclick="return confirm(\'Balík už bol odoslaný. Poslať ho znova (rovnaký obsah)?\')"' : '' ?>>
        <?= $sent ? 'Poslať znova' : 'Poslať účtovníkovi' ?></button>
    </form>
    <?php endif ?>
  </div>
  <p class="muted small">
    E-mail účtovníka: <b><?= $settings['email'] !== '' ? $e($settings['email']) : 'nenastavený' ?></b> ·
    automaticky: <b><?= $settings['auto'] ? 'áno, od ' . (int)$settings['day'] . '. dňa nasledujúceho mesiaca' : 'nie' ?></b>
    <?php if ($ctx->can(Rights::COMPANIES_MANAGE)): ?>· <a href="<?= $e($ctx->url('/companies/' . $cid)) ?>#accountant">zmeniť</a><?php endif ?>
    <?php if (!$configured): ?><br><span class="field-err">Odosielanie e-mailov nie je nastavené na serveri (config.php → apps.panel.mail).</span><?php endif ?>
  </p>
</section>

<?php if ($history): ?>
<section class="card">
  <h2>Odoslané balíky</h2>
  <table class="grid compact">
    <thead><tr><th>Mesiac</th><th>Stav</th><th>Odoslané</th><th>Komu</th><th class="num">Dokladov</th><th>Ako</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($history as $h): ?>
      <tr>
        <td><a href="<?= $e($ctx->url('/accounting?company=' . $cid . '&period=' . $h['period'])) ?>"><?= $e(AccountingPackage::label($h['period'])) ?></a></td>
        <td><?= $h['status'] === 'sent' ? '<span class="badge pay-paid">odoslané</span>' : ($h['status'] === 'failed' ? '<span class="badge pay-overdue" title="' . $e($h['error']) . '">chyba</span>' : '<span class="badge">odosiela sa</span>') ?></td>
        <td><?= $e($h['sent_at'] ? $f->dateTime($h['sent_at']) : '') ?></td>
        <td><?= $e($h['recipients']) ?></td>
        <td class="num"><?= $h['doc_count'] === null ? '' : (int)$h['doc_count'] ?></td>
        <td><?= $h['trigger'] === 'auto' ? 'automaticky' : 'ručne' ?></td>
        <td><a href="<?= $e($ctx->url('/accounting/download?company=' . $cid . '&period=' . $h['period'])) ?>">ZIP</a></td>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table>
</section>
<?php endif ?>
