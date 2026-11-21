<?php use WebApplication\Csrf;
/** @var array $jobs (key => job), $state (key => job_run row); string $cronUrl; bool $mailOk — Nastavenia → Systém: scheduler */
?>
<?= $view->partial('settings/system-tabs', ['tab' => 'jobs']) ?>
<p class="field-hint">Úlohy, ktoré panel robí sám v určený čas (odoslanie balíka účtovníčke, opakované náklady, e-maily s upozorneniami …).
  Spúšťajú sa samé, keď je panel otvorený (stačí, že v ňom niekto pracuje), a cez <b>cron</b> hostingu, ak je nastavený.</p>
<?php if (!$mailOk): ?><p class="flash err">Odosielanie e-mailov nie je nastavené (config.php → apps.panel.mail) — úlohy, ktoré posielajú e-maily, nič nepošlú.</p><?php endif ?>
<table class="grid">
  <thead><tr><th>Úloha</th><th>Ako často</th><th>Naposledy</th><th>Výsledok</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($jobs as $key => $j): $s = $state[$key] ?? null; ?>
    <tr class="<?= $s && !(int)$s['last_ok'] ? 'warn' : '' ?>">
      <td><?= $e($j['label']) ?><br><small class="muted"><?= $e($key) ?></small></td>
      <td><?= $j['every'] >= 1440 ? 'raz denne' . ($j['hour'] !== null ? ' od ' . (int)$j['hour'] . ':00' : '') : ($j['every'] >= 60 ? 'každú hodinu' : ($j['every'] === 1 ? 'každú minútu' : 'každých ' . (int)$j['every'] . ' min')) ?></td>
      <td><?= $s && $s['last_run_at'] ? $e($f->dateTime($s['last_run_at'])) : '<span class="muted">ešte nie</span>' ?></td>
      <td><?= $s ? ((int)$s['last_ok'] ? '' : '<b>CHYBA:</b> ') . $e($s['last_message']) : '' ?></td>
      <td><form method="post" action="<?= $e($ctx->url('/settings/system/run/' . $key)) ?>"><?= Csrf::field() ?><button>Spustiť teraz</button></form></td>
    </tr>
  <?php endforeach ?>
  </tbody>
</table>

<section class="card">
  <h2>Cron hostingu (odporúčané)</h2>
  <?php if ($cronUrl === ''): ?>
    <p class="muted">V config.php chýba <code>apps.panel.cron_token</code> (aspoň 20 znakov) — bez neho cron adresa nefunguje. Úlohy sa aj tak
      spúšťajú pri práci v paneli.</p>
  <?php else: ?>
    <p>Aby úlohy bežali aj vtedy, keď nikto nemá panel otvorený (napr. ranný súhrn), nastavte v správe hostingu cron, ktorý
      <b>každých 5–15 minút</b> otvorí túto adresu:</p>
    <p><code style="word-break: break-all"><?= $e($cronUrl) ?></code></p>
    <p class="field-hint">Adresa obsahuje tajný kľúč — nikomu ju neposielajte. Kto ju pozná, môže len spustiť úlohy, ktoré sú na rade.</p>
  <?php endif ?>
</section>
