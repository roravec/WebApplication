<?php /** @var string $tab — Nastavenia → Systém: scheduler, integrations, API keys */ ?>
<nav class="tabs">
  <a href="<?= $e($ctx->url('/settings/system')) ?>" class="<?= $tab === 'jobs' ? 'active' : '' ?>">Plánovač</a>
  <a href="<?= $e($ctx->url('/settings/integrations')) ?>" class="<?= $tab === 'integrations' ? 'active' : '' ?>">Integrácie</a>
  <a href="<?= $e($ctx->url('/settings/api-keys')) ?>" class="<?= $tab === 'api' ? 'active' : '' ?>">API kľúče</a>
</nav>
