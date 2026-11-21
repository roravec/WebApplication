<?php use Panel\Core\ApiKeys; use Panel\Core\Permissions; use WebApplication\Csrf;
/** @var array $keys (own keys); bool $mine — Môj účet → API kľúče */
$myPerms = $ctx->access()->permissions();
$all = Permissions::all();
?>
<section class="card" id="api">
  <h2>API kľúče</h2>
  <p class="field-hint">Kľúč pre program, ktorý pracuje s panelom za vás — testovací prípravok, CI (GitHub Actions), skript. Kľúč nikdy nemá viac
    práv ako vy; keď o právo prídete, stratí ho aj kľúč. Návod: <a href="<?= $e($ctx->url('/help/api-kluce')) ?>">API kľúče</a>.</p>
  <?php if ($keys): ?>
  <table class="grid compact">
    <thead><tr><th>Názov</th><th>Kľúč</th><th>Práva</th><th>Platí do</th><th>Naposledy</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($keys as $k): ?>
      <tr class="<?= $k['revoked_at'] ? 'muted' : '' ?>">
        <td><?= $e($k['name']) ?></td>
        <td><code>pk_<?= $e($k['prefix']) ?>_…</code></td>
        <td><small><?= $e(implode(', ', array_map(function ($p) use ($all) { return $all[$p]['label'] ?? $p; }, $k['permissions'])) ?: 'len čítanie a komentáre záznamov') ?></small></td>
        <td><?= $k['expires_at'] ? $e($f->date($k['expires_at'])) : 'bez obmedzenia' ?></td>
        <td><?= $k['last_used_at'] ? $e($f->dateTime($k['last_used_at'])) : '<span class="muted">nepoužitý</span>' ?></td>
        <td><?php if ($k['revoked_at']): ?><span class="muted">zrušený</span><?php else: ?>
          <form method="post" action="<?= $e($ctx->url('/account/api-keys/' . (int)$k['id'] . '/revoke')) ?>" onsubmit="return confirm('Zrušiť kľúč? Programy, ktoré ho používajú, prestanú fungovať.')">
            <?= Csrf::field() ?><button class="link link-danger">zrušiť</button></form><?php endif ?></td>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table>
  <?php endif ?>
  <form method="post" action="<?= $e($ctx->url('/account/api-keys')) ?>" class="form">
    <?= Csrf::field() ?>
    <h3>Nový kľúč</h3>
    <label>Názov — čo ho bude používať <input name="name" maxlength="100" required placeholder="napr. Testovací prípravok LedBoard"></label>
    <label>Platnosť <select name="days">
      <?php foreach (ApiKeys::EXPIRY_DAYS as $d): ?><option value="<?= $d ?>"<?= $d === 90 ? ' selected' : '' ?>><?= $d ? $d . ' dní' : 'bez obmedzenia' ?></option><?php endforeach ?>
    </select></label>
    <details><summary>Práva kľúča (len z vašich práv; bez zaškrtnutia = len čítať a komentovať záznamy, ktoré vidíte)</summary>
      <?php foreach ($myPerms as $p): ?>
        <label class="check"><input type="checkbox" name="permissions[]" value="<?= $e($p) ?>"> <?= $e($all[$p]['label'] ?? $p) ?><?= !empty($all[$p]['sensitive']) ? ' <span class="badge">citlivé</span>' : '' ?></label>
      <?php endforeach ?>
    </details>
    <p class="actions"><button class="primary">Vytvoriť kľúč</button></p>
  </form>
</section>
