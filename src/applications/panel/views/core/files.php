<?php use Panel\Core\Files; use WebApplication\Csrf;
/** @var array $record, $files, $shares; string $error, $webUrl; int $limit — the "Súbory" part of the record box (fragment) */
$ref = $record['ref'];
$size = function ($b) { return $b >= 1048576 ? number_format($b / 1048576, 1, ',', ' ') . ' MB' : ($b >= 1024 ? number_format($b / 1024, 0, ',', ' ') . ' kB' : $b . ' B'); };
?>
<?php if ($error !== ''): ?>
  <p class="flash err">Súbory sú dočasne nedostupné — <?= $e($error) ?></p>
<?php else: ?>
  <?php if (!$files): ?><p class="muted">Zatiaľ žiadne súbory.</p><?php endif ?>
  <?php if ($files): ?>
  <table class="grid compact"><tbody>
  <?php foreach ($files as $x): ?>
    <tr>
      <td><?php if ($x['dir']): ?>📁 <?= $e($x['name']) ?> <small class="muted">(priečinok — otvoríte v Nextcloude)</small>
        <?php else: ?><a href="<?= $e($ctx->url('/files/' . $ref . '/download') . '?name=' . rawurlencode($x['name'])) ?>"><?= $e($x['name']) ?></a><?php endif ?></td>
      <td class="num"><?= $x['dir'] ? '' : $e($size($x['size'])) ?></td>
      <td class="muted small"><?= $e($x['modified'] ? $f->dateTime($x['modified']) : '') ?></td>
      <td><?php if (!$x['dir']): ?><form method="post" action="<?= $e($ctx->url('/files/' . $ref . '/delete')) ?>" onsubmit="return confirm('Zmazať súbor?')">
        <?= Csrf::field() ?><input type="hidden" name="name" value="<?= $e($x['name']) ?>"><button class="link link-danger" title="Zmazať">✕</button></form><?php endif ?></td>
    </tr>
  <?php endforeach ?>
  </tbody></table>
  <?php endif ?>
  <form method="post" action="<?= $e($ctx->url('/files/' . $ref . '/upload')) ?>" enctype="multipart/form-data" class="inline link-form">
    <?= Csrf::field() ?>
    <input type="file" name="files[]" multiple required>
    <button>Nahrať</button>
    <small class="muted">najviac <?= (int)round($limit / 1048576) ?> MB na súbor — väčšie nahrajte priamo do Nextcloudu alebo cez odkaz na nahratie</small>
  </form>

  <h3>Zdieľanie mimo firmy</h3>
  <?php foreach ($shares as $s): ?>
    <p class="small"><?= $s['kind'] === 'upload' ? '⬆ odkaz na nahratie' : '⬇ odkaz na stiahnutie' ?>:
      <a href="<?= $e($s['url']) ?>" target="_blank" rel="noopener"><?= $e($s['url']) ?></a>
      <span class="muted"><?= $s['expires'] ? ' · platí do ' . $e($f->date($s['expires'])) : ' · bez obmedzenia' ?><?= (int)$s['has_password'] ? ' · s heslom' : '' ?> · <?= $e($s['client_name']) ?></span>
      <form method="post" action="<?= $e($ctx->url('/files/shares/' . (int)$s['id'] . '/revoke')) ?>" class="inline" onsubmit="return confirm('Zrušiť odkaz? Prestane fungovať.')">
        <?= Csrf::field() ?><button class="link link-danger">zrušiť</button></form></p>
  <?php endforeach ?>
  <form method="post" action="<?= $e($ctx->url('/files/' . $ref . '/share')) ?>" class="inline link-form">
    <?= Csrf::field() ?>
    <select name="kind" aria-label="Druh odkazu">
      <option value="read">odkaz na stiahnutie (vidí súbory)</option>
      <option value="upload">odkaz na nahratie (len pošle súbory — napr. zákazník)</option>
    </select>
    <select name="days" aria-label="Platnosť">
      <?php foreach (Files::SHARE_DAYS as $d): ?><option value="<?= $d ?>"<?= $d === 14 ? ' selected' : '' ?>>platí <?= $d ?> dní</option><?php endforeach ?>
    </select>
    <input name="password" type="password" placeholder="heslo (nepovinné, aspoň 8 znakov)" autocomplete="new-password">
    <button>Vytvoriť odkaz</button>
  </form>
  <?php if ($webUrl !== ''): ?><p class="small"><a href="<?= $e($webUrl) ?>" target="_blank" rel="noopener">Otvoriť priečinok v Nextcloude</a>
    <span class="muted">(len pre účet panela v Nextcloude)</span></p><?php endif ?>
<?php endif ?>
