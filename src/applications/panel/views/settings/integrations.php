<?php use WebApplication\Csrf;
/** @var array $types, $rows (name => integration row|null), $values (name => form values), $errors, $shops; int $fio; bool $mailOk */
$err = function ($k) use ($errors, $e) { return isset($errors[$k]) ? '<span class="field-err">' . $e($errors[$k]) . '</span>' : ''; };
$badge = function (?array $r) use ($e, $f) {
    if ($r === null) { return '<span class="badge">nenastavené</span>'; }
    if (!(int)$r['enabled']) { return '<span class="badge">vypnuté</span>'; }
    if ($r['status'] === 'ok') { return '<span class="badge pay-paid">funguje</span> <small class="muted">' . $e($r['status_message']) . ', ' . $e($f->dateTime($r['checked_at'])) . '</small>'; }
    if ($r['status'] === 'error') { return '<span class="badge pay-overdue">chyba</span> <small>' . $e($r['status_message']) . '</small>'; }
    return '<span class="badge">neoverené</span>';
};
?>
<?= $view->partial('settings/system-tabs', ['tab' => 'integrations']) ?>
<p class="field-hint">Napojenia na služby mimo panela. Heslá a kľúče sú uložené zašifrované a už sa nikde nezobrazia — pri zmene ich zadajte znova.</p>

<?php foreach ($types as $name => list($label, $fields)): $v = $values[$name]; ?>
<form class="card form" method="post" action="<?= $e($ctx->url('/settings/integrations/' . $name)) ?>">
  <h2><?= $e($label) ?> <?= $badge($rows[$name]) ?></h2>
  <?= Csrf::field() ?>
  <?php if ($name === 'nextcloud'): ?>
    <p class="field-hint">Súbory záznamov (zákazky, objednávky, doklady …) budú vo firemnom Nextcloude, nie na hostingu. Postup: v Nextcloude vytvorte
      používateľa pre panel (napr. <code>erma-panel</code>), prihláste sa ním, v <b>Nastavenia → Zabezpečenie</b> vytvorte <b>heslo aplikácie</b>
      a vyplňte ho sem. Podrobne v Pomocníkovi.</p>
  <?php endif ?>
  <?php foreach ($fields as $k => list($flabel, $secret, $hint)): ?>
    <label><?= $e($flabel) ?>
      <input name="<?= $e($k) ?>" type="<?= $secret ? 'password' : 'text' ?>" value="<?= $e($v[$k]) ?>" autocomplete="<?= $secret ? 'new-password' : 'off' ?>"
        placeholder="<?= $e($secret && $v[$k . '_stored'] ? 'uložené — prázdne = bez zmeny' : $hint) ?>"><?= $err($k) ?>
      <small class="field-hint"><?= $e($hint) ?></small></label>
  <?php endforeach ?>
  <label class="check"><input type="checkbox" name="enabled" value="1"<?= $v['enabled'] ? ' checked' : '' ?>> Zapnuté</label>
  <p class="actions"><button class="primary">Uložiť a overiť pripojenie</button></p>
</form>
<?php if ($rows[$name]): ?>
  <form method="post" action="<?= $e($ctx->url('/settings/integrations/' . $name . '/check')) ?>" class="inline"><?= Csrf::field() ?><button>Overiť pripojenie teraz</button></form>
<?php endif ?>
<?php endforeach ?>

<section class="card">
  <h2>Ďalšie napojenia</h2>
  <table class="grid compact"><tbody>
    <tr><td>E-shopy (PrestaShop Webservice)</td><td><?= count($shops) ?> pripojení</td>
      <td><a href="<?= $e($ctx->url('/settings/eshops')) ?>">Nastavenia → E-shopy</a></td></tr>
    <tr><td>Fio banka (sťahovanie pohybov)</td><td><?= $fio ?> účtov s tokenom</td>
      <td><a href="<?= $e($ctx->url('/companies')) ?>">Nastavenia → Firmy → bankové účty</a></td></tr>
    <tr><td>E-mail (SMTP)</td><td><?= $mailOk ? '<span class="badge pay-paid">nastavený</span>' : '<span class="badge pay-overdue">nenastavený</span>' ?></td>
      <td><span class="muted">v config.php na serveri (apps.panel.mail)</span></td></tr>
    <tr><td>API pre prípravky, CI, zariadenia</td><td></td><td><a href="<?= $e($ctx->url('/settings/api-keys')) ?>">API kľúče</a></td></tr>
  </tbody></table>
</section>
