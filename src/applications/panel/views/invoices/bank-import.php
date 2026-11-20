<?php use WebApplication\Csrf; /** @var string $error; array $apis */ ?>
<p><a href="<?= $e($ctx->url('/invoices')) ?>">← Faktúry</a></p>
<?php if ($error): ?><p class="flash err"><?= $e($error) ?></p><?php endif ?>
<?php foreach ($apis as $a): ?>
<form class="card form" method="post" action="<?= $e($ctx->url('/payments/import/fio')) ?>" style="max-width: 40rem">
  <?= Csrf::field() ?>
  <input type="hidden" name="bank_id" value="<?= (int)$a['id'] ?>">
  <h2>Stiahnuť z banky — <?= $e($a['company']) ?></h2>
  <p class="muted small"><?= $e(trim(chunk_split($a['iban'], 4, ' '))) ?> · Fio API<?= $a['last'] ? ' · naposledy ' . $e($f->dateTime($a['last'])) : '' ?></p>
  <div class="filters">
    <label>od <input type="date" name="from" value="<?= $e($a['from']) ?>" required></label>
    <label>do <input type="date" name="to" value="<?= $e(date('Y-m-d')) ?>" required></label>
    <button class="primary">Stiahnuť pohyby</button>
  </div>
</form>
<?php endforeach ?>
<form class="card form" method="post" action="<?= $e($ctx->url('/payments/import')) ?>" enctype="multipart/form-data" style="max-width: 40rem">
  <?= Csrf::field() ?>
  <p>Nahrajte výpis z banky vo formáte <b>CAMT.053 (XML)</b> — štandard SEPA, ktorý ponúka každá slovenská banka
    (vo Fio banke „SBA XML“, inde „XML výpis CAMT.053“). Panel spáruje prijaté platby s neuhradenými faktúrami podľa
    <b>variabilného symbolu</b>; pred zápisom návrh skontrolujete. Ten istý pohyb sa nedá zapísať dvakrát.</p>
  <label>Výpis (XML) <input type="file" name="statement" accept=".xml,application/xml,text/xml" required></label>
  <button class="primary">Načítať výpis</button>
</form>
