<?php use WebApplication\Csrf;
/** @var array $o, $values, $errors, $sender, $sent; int $src; bool $configured */
$err = function ($k) use ($errors, $e) { return isset($errors[$k]) ? '<span class="field-err">' . $e($errors[$k]) . '</span>' : ''; };
?>
<p><a href="<?= $e($ctx->orderUrl($src, (int)$o['id_order'])) ?>">← Objednávka <?= $e($o['reference']) ?></a></p>
<?php if (!$configured): ?><p class="flash err">Odosielanie e-mailov nie je nastavené (config.php → mail).</p><?php endif ?>
<?php foreach (['from', 'send'] as $k): if (isset($errors[$k])): ?><p class="flash err"><?= $e($errors[$k]) ?></p><?php endif; endforeach ?>
<form class="card form-grid narrow" method="post" action="<?= $e($ctx->orderUrl($src, (int)$o['id_order'], '/message')) ?>">
  <?= Csrf::field() ?>
  <p class="wide muted small">Odosielateľ: <b><?= $e($sender['from_name'] ?? '') ?></b> &lt;<?= $e($sender['from'] ?? '') ?>&gt;
    <?= !empty($sender['brand']) ? ' — e-mail v štýle e-shopu (logo ' . $e($sender['brand']['name']) . ')' : '' ?>. Odpovede prídu na <?= $e($sender['reply_to'] ?? '') ?>.</p>
  <label class="wide">Komu <input name="to" value="<?= $e($values['to']) ?>" required><?= $err('to') ?></label>
  <label class="wide">Predmet <input name="subject" value="<?= $e($values['subject']) ?>" required></label>
  <label class="wide">Text <textarea name="text" rows="12" required><?= $e($values['text']) ?></textarea><?= $err('text') ?></label>
  <p class="wide"><button class="primary">Odoslať</button></p>
</form>
<?php if ($sent): ?>
<section class="card">
  <h2>Odoslané správy k objednávke</h2>
  <?php foreach ($sent as $m): ?>
    <details><summary><?= $e($f->dateTime($m['created_at'])) ?> · <?= $e($m['client_name']) ?> · <?= $e($m['subject']) ?><?= $m['status'] === 'sent' ? '' : ' — <b>neodoslané</b>' ?></summary>
      <pre style="white-space: pre-wrap"><?= $e($m['body']) ?></pre></details>
  <?php endforeach ?>
</section>
<?php endif ?>
