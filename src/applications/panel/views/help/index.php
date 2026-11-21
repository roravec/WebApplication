<?php /** @var array $groups heading => [slug => title] */ ?>
<p class="muted">Návody k panelu krok za krokom. Na každej stránke panela je vpravo hore tlačidlo <b>? Pomocník</b> — otvorí
  návod presne k tej stránke. Ak niečo nie je jasné alebo návod nesedí s tým, čo vidíte, povedzte to — návod sa opraví.</p>
<div class="help-index">
  <?php foreach ($groups as $label => $pages): ?>
    <section>
      <h2><?= $e($label) ?></h2>
      <ul><?php foreach ($pages as $slug => $title): ?><li><a href="<?= $e($ctx->url('/help/' . $slug)) ?>"><?= $e($title) ?></a></li><?php endforeach ?></ul>
    </section>
  <?php endforeach ?>
</div>
