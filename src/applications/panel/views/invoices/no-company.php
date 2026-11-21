<section class="card">
  <p>Na vystavenie faktúry treba najprv založiť firmu (dodávateľa) — údaje, bankový účet a číselný rad.</p>
  <?php if ($ctx->can(\Panel\Rights::COMPANIES_CREATE)): ?>
    <p><a class="button primary" href="<?= $e($ctx->url('/companies/new')) ?>">+ Založiť firmu</a></p>
  <?php else: ?>
    <p class="muted">Nemáte prístup k žiadnej firme — požiadajte správcu.</p>
  <?php endif ?>
</section>
