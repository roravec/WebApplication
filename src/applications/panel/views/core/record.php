<?php use Panel\Audit; use Panel\Core\Comments; use Panel\Core\Links; use WebApplication\Csrf;
/**
 * Box of every record page (docs/panel-architektura.md chapter 4): Komentáre · Prepojenia · História + short link and
 * QR label. Usage in a page: <?= $view->partial('core/record', ['ref' => 'invoice:' . $inv['id']]) ?>
 * @var string $ref; bool|null $noHistory (the page shows its own history)
 */
$p = $ctx->recordPanel($ref);
if ($p === null) {
    return;
}
$me = $ctx->user();
$link = function ($r) use ($ctx) { return $ctx->refs()->resolve($r); };
$url = function ($path) use ($ctx) { return $ctx->url($path); };
$live = array_filter($p['comments'], function ($c) { return $c['deleted_at'] === null; });
?>
<section class="card record" id="comments">
  <h2>Komentáre (<?= count($live) ?>)</h2>
  <?php foreach ($p['comments'] as $c): $mine = (int)$c['client_id'] === $me->getId() || $ctx->isAdmin(); ?>
    <div class="comment" id="comment-<?= (int)$c['id'] ?>">
      <small class="muted"><?= $e($f->dateTime($c['created_at'])) ?> · <?= $e($c['client_name']) ?><?= $c['edited_at'] ? ' · upravený' : '' ?></small>
      <?php if ($c['deleted_at'] !== null): ?>
        <p class="muted"><i>komentár zmazaný</i></p>
      <?php else: ?>
        <p><?= Comments::render($c['body'], $c['mentions'], $link, $url) ?></p>
        <?php if ($mine): ?>
          <details class="comment-edit"><summary>upraviť</summary>
            <form method="post" action="<?= $e($ctx->url('/comments/' . (int)$c['id'] . '/edit')) ?>">
              <?= Csrf::field() ?>
              <textarea name="body" rows="3" data-mentions maxlength="<?= Comments::MAX ?>"><?= $e($c['body']) ?></textarea>
              <p class="actions"><button>Uložiť</button></p>
            </form>
            <form method="post" action="<?= $e($ctx->url('/comments/' . (int)$c['id'] . '/delete')) ?>" onsubmit="return confirm('Zmazať komentár?')">
              <?= Csrf::field() ?><button class="link link-danger">zmazať komentár</button>
            </form>
          </details>
        <?php endif ?>
      <?php endif ?>
    </div>
  <?php endforeach ?>
  <?php if (!$p['comments']): ?><p class="muted">Zatiaľ žiadne. Komentáre vidia len zamestnanci, zákazník nie.</p><?php endif ?>
  <form method="post" action="<?= $e($ctx->url('/comments')) ?>">
    <?= Csrf::field() ?>
    <input type="hidden" name="ref" value="<?= $e($ref) ?>">
    <textarea name="body" rows="2" data-mentions maxlength="<?= Comments::MAX ?>" required
      placeholder="Napíšte komentár. @Meno = upozorniť kolegu, invoice:15 alebo order:0:2481 = odkaz na záznam"></textarea>
    <div class="mention-list" hidden></div>
    <p class="actions"><button>Pridať komentár</button></p>
  </form>
  <datalist id="people-<?= $e(str_replace(':', '-', $ref)) ?>" class="people">
    <?php foreach ($p['people'] as $name): ?><option value="<?= $e($name) ?>"><?php endforeach ?>
  </datalist>
</section>

<section class="card record" id="links">
  <h2>Prepojenia (<?= count($p['links']) ?>)</h2>
  <?php if ($p['links']): ?>
    <table class="grid compact"><tbody>
    <?php foreach ($p['links'] as $l): ?>
      <tr>
        <td><?= $e($l['label']) ?></td>
        <td><a href="<?= $e($ctx->url($l['record']['url'])) ?>"><?= $e($l['record']['label']) ?> <?= $e($l['record']['title']) ?></a></td>
        <td class="muted small"><?= $e($l['client_name']) ?>, <?= $e($f->dateTime($l['created_at'])) ?></td>
        <td><form method="post" action="<?= $e($ctx->url('/links/' . (int)$l['id'] . '/delete')) ?>" onsubmit="return confirm('Zrušiť prepojenie?')">
          <?= Csrf::field() ?><input type="hidden" name="ref" value="<?= $e($ref) ?>"><button class="link link-danger" title="Zrušiť prepojenie">✕</button></form></td>
      </tr>
    <?php endforeach ?>
    </tbody></table>
  <?php else: ?>
    <p class="muted">Zatiaľ žiadne. Prepojte súvisiace záznamy — napr. objednávku s prijatým dokladom alebo faktúru s odberateľom.</p>
  <?php endif ?>
  <form method="post" action="<?= $e($ctx->url('/links')) ?>" class="inline link-form">
    <?= Csrf::field() ?>
    <input type="hidden" name="ref" value="<?= $e($ref) ?>">
    <input type="hidden" name="target_ref" value="">
    <select name="kind" aria-label="Druh prepojenia">
      <?php foreach (Links::KINDS as $k => $labels): ?><option value="<?= $e($k) ?>"><?= $e($labels[0]) ?></option><?php endforeach ?>
    </select>
    <input name="target" placeholder="číslo faktúry, objednávky, dokladu, názov…" autocomplete="off" required data-lookup="<?= $e($ctx->url('/links/lookup')) ?>">
    <button>Prepojiť</button>
    <div class="lookup-list" hidden></div>
  </form>
</section>

<?php if ((new Panel\Core\Files($ctx))->configured()): ?>
<section class="card record" id="files">
  <h2>Súbory</h2>
  <div data-files="<?= $e($ctx->url('/files/' . $ref)) ?>"><p class="muted">Načítavam súbory z Nextcloudu…
    <a href="<?= $e($ctx->url('/files/' . $ref)) ?>">zobraziť</a></p></div>
</section>
<?php endif ?>

<?php if (empty($noHistory)): ?>
<section class="card record" id="history">
  <h2>História</h2>
  <?php if (!$p['history']): ?><p class="muted">Zatiaľ bez zmien.</p><?php endif ?>
  <table class="grid compact"><tbody>
  <?php foreach ($p['history'] as $h): ?>
    <tr><td><?= $e($f->dateTime($h['created_at'])) ?></td><td><?= $e($h['client_name']) ?></td><td><?= $e(Audit::label($h['action'])) ?> <?= $e($h['details']) ?></td></tr>
  <?php endforeach ?>
  </tbody></table>
</section>
<?php endif ?>

<p class="muted small record-link">Krátky odkaz: <a href="<?= $e($ctx->url('/r/' . $ref)) ?>"><?= $e($ctx->refs()->shortUrl($ref)) ?></a>
  · <a href="<?= $e($ctx->url('/r/' . $ref . '/label')) ?>" target="_blank" rel="noopener">QR štítok na vytlačenie</a>
  · v komentároch píšte <code><?= $e($ref) ?></code></p>
