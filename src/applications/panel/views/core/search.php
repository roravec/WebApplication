<?php /** @var string $q; array $groups (label => list of resolved records) */ ?>
<?php if ($q === ''): ?>
  <p class="muted">Napíšte do poľa hore číslo faktúry, kód objednávky, číslo dokladu, názov odberateľa, skladovej karty … alebo kód záznamu (napr. <code>invoice:15</code>).</p>
<?php elseif (!$groups): ?>
  <p class="muted">Pre „<?= $e($q) ?>“ sa nič nenašlo (alebo k tomu nemáte prístup). Skúste kratší text alebo iba časť čísla.</p>
<?php else: ?>
  <?php foreach ($groups as $label => $list): ?>
    <section class="search-group">
      <h2><?= $e($label) ?> (<?= count($list) ?>)</h2>
      <ul><?php foreach ($list as $r): ?><li><a href="<?= $e($ctx->url($r['url'])) ?>"><?= $e($r['title']) ?></a></li><?php endforeach ?></ul>
    </section>
  <?php endforeach ?>
<?php endif ?>
