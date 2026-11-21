<?php /** @var array $key; string $token, $apiUrl — shown ONCE after creating a key */ ?>
<section class="card">
  <h2>Kľúč „<?= $e($key['name']) ?>“ je vytvorený</h2>
  <p><b>Skopírujte si ho teraz</b> — panel ho uložil len zašifrovaný a už ho nikdy neukáže. Ak ho stratíte, zrušte ho a vytvorte nový.</p>
  <p><input readonly value="<?= $e($token) ?>" onclick="this.select()" style="width: 100%; font-family: monospace" aria-label="API kľúč"></p>
  <p class="field-hint">Program ho posiela v hlavičke <code>Authorization: Bearer <?= $e(substr($token, 0, 12)) ?>…</code>. Vyskúšanie:
    <code>curl -H "Authorization: Bearer KĽÚČ" <?= $e($apiUrl) ?>/me</code></p>
  <p><a class="button primary" href="<?= $e($ctx->url('/account#api')) ?>">Hotovo — späť na Môj účet</a></p>
</section>
