<?php use Panel\Expenses\Expenses; use WebApplication\Csrf;
/** @var array $categories; bool $canEdit */
?>
<?= $view->partial('expenses/tabs', ['tab' => 'categories']) ?>
<p class="muted">Druh určuje, kam náklad patrí vo výsledkoch: <b>tovar</b> (nákup na predaj), <b>náklady na objednávky</b> (doprava, platobné
  poplatky, obaly — znižujú maržu objednávok), <b>réžia</b> (všetko ostatné). Kategórie sa nemažú — nepoužívanú vypnite.</p>
<form method="post" action="<?= $e($ctx->url('/expenses/categories')) ?>">
  <?= Csrf::field() ?>
  <table class="grid">
    <thead><tr><th>Názov</th><th>Druh</th><th>Aktívna</th></tr></thead>
    <tbody>
    <?php $i = 0; foreach ($categories as $c): $i++; ?>
      <tr>
        <td><input type="hidden" name="cat[<?= $i ?>][id]" value="<?= (int)$c['id'] ?>"><input name="cat[<?= $i ?>][name]" value="<?= $e($c['name']) ?>"<?= $canEdit ? '' : ' disabled' ?>></td>
        <td><select name="cat[<?= $i ?>][kind]"<?= $canEdit ? '' : ' disabled' ?>><?php foreach (Expenses::KINDS as $k => $label): ?><option value="<?= $e($k) ?>"<?= $c['kind'] === $k ? ' selected' : '' ?>><?= $e($label) ?></option><?php endforeach ?></select></td>
        <td><input type="checkbox" name="cat[<?= $i ?>][active]" value="1"<?= $c['active'] ? ' checked' : '' ?><?= $canEdit ? '' : ' disabled' ?>></td>
      </tr>
    <?php endforeach ?>
    <?php if ($canEdit): for ($n = 1; $n <= 3; $n++): $i++; ?>
      <tr>
        <td><input type="hidden" name="cat[<?= $i ?>][id]" value=""><input name="cat[<?= $i ?>][name]" placeholder="Nová kategória"></td>
        <td><select name="cat[<?= $i ?>][kind]"><?php foreach (Expenses::KINDS as $k => $label): ?><option value="<?= $e($k) ?>"<?= $k === 'overhead' ? ' selected' : '' ?>><?= $e($label) ?></option><?php endforeach ?></select></td>
        <td></td>
      </tr>
    <?php endfor; endif ?>
    </tbody>
  </table>
  <?php if ($canEdit): ?><p><button class="primary">Uložiť kategórie</button></p><?php endif ?>
</form>
