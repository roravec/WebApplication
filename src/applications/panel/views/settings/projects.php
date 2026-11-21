<?php use WebApplication\Csrf;
/** @var string $format, $base; array $repos, $errors, $new (repo + secret after adding), $providers — Nastavenia → Projekty */
$err = function ($k) use ($errors, $e) { return isset($errors[$k]) ? '<span class="field-err">' . $e($errors[$k]) . '</span>' : ''; };
$hook = function (array $r) use ($base) { return $r['provider'] === 'api' ? $base . '/api/v1/git/push' : $base . '/git/' . $r['provider'] . '/' . (int)$r['id']; };
?>
<?php if ($new): $r = $new['repo']; ?>
<section class="card">
  <h2>Repozitár <?= $e($r['name']) ?> je pridaný — teraz ho nastavte <?= $r['provider'] === 'github' ? 'na GitHube' : ($r['provider'] === 'gitlab' ? 'v GitLabe' : 'na serveri') ?></h2>
  <p><b>Tajný kľúč sa zobrazí len teraz</b> — skopírujte ho do nastavenia webhooku. Ak ho stratíte, repozitár pridajte znova.</p>
  <?php if ($r['provider'] === 'github'): ?>
    <ol>
      <li>Na GitHube otvorte repozitár → <b>Settings → Webhooks → Add webhook</b>.</li>
      <li><b>Payload URL:</b> <code><?= $e($hook($r)) ?></code></li>
      <li><b>Content type:</b> <code>application/json</code></li>
      <li><b>Secret:</b> <input readonly value="<?= $e($new['secret']) ?>" onclick="this.select()" style="font-family: monospace; width: 100%" aria-label="Secret"></li>
      <li><b>Which events:</b> Just the push event → <b>Add webhook</b>. GitHub hneď pošle skúšobný „ping“ — pri webhooku sa ukáže zelená fajka.</li>
    </ol>
  <?php elseif ($r['provider'] === 'gitlab'): ?>
    <ol>
      <li>V GitLabe otvorte projekt → <b>Settings → Webhooks → Add new webhook</b>.</li>
      <li><b>URL:</b> <code><?= $e($hook($r)) ?></code></li>
      <li><b>Secret token:</b> <input readonly value="<?= $e($new['secret']) ?>" onclick="this.select()" style="font-family: monospace; width: 100%" aria-label="Secret token"></li>
      <li><b>Trigger:</b> Push events (všetky vetvy) → <b>Add webhook</b>. Skúška: Test → Push events.</li>
    </ol>
  <?php else: ?>
    <p>Na vlastnom git serveri skopírujte skript <code>dev/git-hook-post-receive</code> z repozitára WebApplication do <code>hooks/post-receive</code>
      holého repozitára a nastavte v ňom <code>PANEL_URL=<?= $e($base) ?></code>, <code>REPO=<?= $e($r['name']) ?></code> a API kľúč osoby s právom
      Úlohy — úpravy (Môj účet → API kľúče). Tajný kľúč nižšie sa pri tomto spôsobe nepoužíva.</p>
  <?php endif ?>
</section>
<?php endif ?>

<form class="card form" method="post" action="<?= $e($ctx->url('/settings/projects/format')) ?>">
  <h2>Číslo úlohy v správe commitu</h2>
  <?= Csrf::field() ?>
  <p class="field-hint">Commit sa priradí k úlohe, keď jeho správa <b>začína</b> číslom úlohy v tomto tvare (<code>{TASK}</code> = číslo, napr. LED-12).
    Na začiatku môže byť aj viac čísel za sebou. Commit bez čísla prejde, len sa k ničomu nepriradí. Číslo ďalej v texte sa neráta.</p>
  <label>Formát <input name="format" value="<?= $e($format) ?>" maxlength="20" required><?= $err('format') ?></label>
  <p class="field-hint">Príklady: <code>#{TASK}</code> → <code>#LED-12 oprava časovača</code> · <code>[{TASK}]</code> → <code>[LED-12] oprava</code> ·
    <code>{TASK}:</code> → <code>LED-12: oprava</code> · viac úloh: <code><?= $e(str_replace('{TASK}', 'LED-12', $format) . ' ' . str_replace('{TASK}', 'LED-14', $format)) ?> spoločná oprava</code></p>
  <button class="primary">Uložiť formát</button>
</form>

<section class="card">
  <h2>Git repozitáre</h2>
  <p class="field-hint">Repozitáre, z ktorých panel prijíma commity (panel kód neukladá — len hash, správu, autora, vetvu a odkaz).
    Autor sa spáruje so zamestnancom podľa e-mailu v gite.</p>
  <?php if ($repos): ?>
  <table class="grid compact">
    <thead><tr><th>Repozitár</th><th>Kde</th><th>Adresa webhooku</th><th>Posledný push</th><th class="num">Commity</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($repos as $r): ?>
      <tr class="<?= (int)$r['active'] ? '' : 'muted' ?>">
        <td><?= $r['web_url'] !== '' ? '<a href="' . $e($r['web_url']) . '" target="_blank" rel="noopener">' . $e($r['name']) . '</a>' : $e($r['name']) ?></td>
        <td><?= $e($providers[$r['provider']] ?? $r['provider']) ?></td>
        <td><code class="small"><?= $e($hook($r)) ?></code></td>
        <td><?= $r['last_push_at'] ? $e($f->dateTime($r['last_push_at'])) . '<br><small class="muted">' . $e($r['last_message']) . '</small>' : '<span class="muted">ešte nie</span>' ?></td>
        <td class="num"><?= (int)$r['commits'] ?></td>
        <td><form method="post" action="<?= $e($ctx->url('/settings/projects/repos/' . (int)$r['id'] . '/toggle')) ?>"><?= Csrf::field() ?>
          <button class="link"><?= (int)$r['active'] ? 'vypnúť' : 'zapnúť' ?></button></form></td>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table>
  <?php endif ?>
  <form method="post" action="<?= $e($ctx->url('/settings/projects/repos')) ?>" class="form">
    <?= Csrf::field() ?>
    <h3>Pridať repozitár</h3>
    <?= $err('repo') ?>
    <div class="form-grid">
      <label>Kde je <select name="provider"><?php foreach ($providers as $k => $l): ?><option value="<?= $e($k) ?>"><?= $e($l) ?></option><?php endforeach ?></select></label>
      <label>Názov (vlastník/repozitár) <input name="name" required placeholder="napr. erma/ledboard-fw"></label>
      <label>Adresa na webe (nepovinné) <input name="web_url" placeholder="https://github.com/erma/ledboard-fw"></label>
    </div>
    <button class="primary">Pridať a ukázať postup</button>
  </form>
</section>
