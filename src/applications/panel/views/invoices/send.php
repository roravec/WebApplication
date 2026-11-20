<?php use WebApplication\Csrf;
/** @var array $inv, $values, $defaults, $errors; bool $configured */
$err = function ($k) use ($errors, $e) { return isset($errors[$k]) ? '<span class="field-err">' . $e($errors[$k]) . '</span>' : ''; };
?>
<p><a href="<?= $e($ctx->url('/invoices/' . $inv['id'])) ?>">← Späť na doklad</a></p>
<?php if (!$configured): ?>
  <p class="flash err">Odosielanie e-mailov nie je na serveri nastavené (config.php → apps.panel.mail) — správca ho musí doplniť.</p>
<?php endif ?>
<?php if (isset($errors['send'])): ?><p class="flash err"><?= $e($errors['send']) ?></p><?php endif ?>
<form class="card form" method="post" action="<?= $e($ctx->url('/invoices/' . $inv['id'] . '/send')) ?>" style="max-width: 46rem">
  <?= Csrf::field() ?>
  <input type="hidden" name="kind" value="<?= $e($values['kind'] ?? 'invoice') ?>">
  <p class="muted small">Od: <?= $e($defaults['from_name']) ?> &lt;<?= $e($defaults['from']) ?>&gt;<?= $defaults['reply_to'] ? ' · odpovede na ' . $e($defaults['reply_to']) : '' ?>
    · príloha <b><?= $e(preg_replace('/[^A-Za-z0-9_-]+/', '-', (string)$inv['number'])) ?>.pdf</b> (archivované PDF) <?= $err('from') ?></p>
  <label>Komu <input name="to" value="<?= $e($values['to']) ?>" placeholder="zakaznik@example.sk" required><?= $err('to') ?>
    <small class="field-hint">Viac adries oddeľte čiarkou.</small></label>
  <label>Kópia <input name="cc" value="<?= $e($values['cc']) ?>"><?= $err('cc') ?></label>
  <label>Skrytá kópia <input name="bcc" value="<?= $e($values['bcc']) ?>"></label>
  <label>Predmet <input name="subject" value="<?= $e($values['subject']) ?>" required></label>
  <label>Text <textarea name="text" rows="10" required><?= $e($values['text']) ?></textarea><?= $err('text') ?></label>
  <button class="primary"<?= $configured ? '' : ' disabled' ?>>Odoslať</button>
</form>
