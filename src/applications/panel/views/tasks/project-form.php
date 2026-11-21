<?php use Panel\Tasks\Projects; use WebApplication\Csrf;
/** @var array|null $project; array $values, $errors, $people (id => name), $companies (id => name) */
$err = function ($k) use ($errors, $e) { return isset($errors[$k]) ? '<span class="field-err">' . $e($errors[$k]) . '</span>' : ''; };
$p = $project;
?>
<p><a href="<?= $e($ctx->url($p ? '/projects/' . $p['id'] : '/projects')) ?>">← <?= $p ? $e($p['key'] . ' ' . $p['name']) : 'Projekty' ?></a></p>
<form class="card form" method="post" action="<?= $e($ctx->url($p ? '/projects/' . $p['id'] . '/settings' : '/projects')) ?>">
  <?= Csrf::field() ?>
  <label>Názov projektu <input name="name" value="<?= $e($values['name']) ?>" required maxlength="190" placeholder="napr. LedBoard — firmvér 2.0"><?= $err('name') ?></label>
  <?php if (!$p): ?>
    <label>Kľúč (v číslach úloh, nedá sa neskôr zmeniť) <input name="key" value="<?= $e($values['key']) ?>" required maxlength="10" pattern="[A-Za-z][A-Za-z0-9]{1,9}"
      placeholder="LED" style="text-transform: uppercase"><?= $err('key') ?>
      <small class="field-hint">2–10 písmen alebo číslic. Úlohy budú LED-1, LED-2 … — toto číslo sa píše aj na začiatok commitov.</small></label>
    <label>Firma <select name="company_id">
      <?php foreach ($companies as $id => $name): ?><option value="<?= (int)$id ?>"<?= (int)$id === (int)$values['company_id'] ? ' selected' : '' ?>><?= $e($name) ?></option><?php endforeach ?>
    </select><?= $err('company_id') ?></label>
  <?php else: ?>
    <p>Kľúč: <b><?= $e($p['key']) ?></b></p>
  <?php endif ?>
  <label>Popis (cieľ, zákazník, dôležité odkazy) <textarea name="description" rows="3"><?= $e($values['description']) ?></textarea></label>
  <?php if ($p): ?>
    <label>Stav <select name="status"><option value="active">aktívny</option><option value="archived"<?= $values['status'] === 'archived' ? ' selected' : '' ?>>archivovaný (úlohy sa už nemenia)</option></select></label>
  <?php endif ?>

  <fieldset>
    <legend>Členovia projektu</legend>
    <?= $err('members') ?>
    <p class="field-hint"><b>vlastník</b> spravuje projekt (členovia, stĺpce) · <b>člen</b> pracuje na úlohách · <b>len čítanie</b> nič nemení ·
      <b>externý</b> = človek zvonka: vidí len projekty, kam ho pozvete. Riešiteľom úlohy môže byť len člen.</p>
    <div class="scroll-x"><table class="grid compact"><tbody>
    <?php foreach ($people as $id => $name): $role = $values['members'][(int)$id] ?? ''; ?>
      <tr><td><?= $e($name) ?></td><td><select name="member[<?= (int)$id ?>]" aria-label="Rola v projekte: <?= $e($name) ?>">
        <option value="">— nie je v projekte —</option>
        <?php foreach (Projects::ROLES as $k => $label): ?><option value="<?= $e($k) ?>"<?= $role === $k ? ' selected' : '' ?>><?= $e($label) ?></option><?php endforeach ?>
      </select></td></tr>
    <?php endforeach ?>
    </tbody></table></div>
  </fieldset>

  <fieldset>
    <legend>Stĺpce kanbanu (stavy úloh)</legend>
    <?= $err('columns') ?>
    <p class="field-hint">Poradie zľava doprava. <b>hotovo</b> = úloha v tomto stĺpci je dokončená. Zmazaný stĺpec: jeho úlohy sa presunú do prvého.</p>
    <table class="grid compact" id="columns"><tbody>
    <?php $cols = $values['columns']; $cols[] = ['id' => 0, 'name' => '', 'done' => false]; $cols[] = ['id' => 0, 'name' => '', 'done' => false];
    foreach ($cols as $i => $c): ?>
      <tr><td><input type="hidden" name="col[<?= $i ?>][id]" value="<?= (int)$c['id'] ?>">
        <input name="col[<?= $i ?>][name]" value="<?= $e($c['name']) ?>" maxlength="60" placeholder="<?= $c['id'] ? '' : 'nový stĺpec' ?>" aria-label="Názov stĺpca"></td>
        <td><label class="check"><input type="checkbox" name="col[<?= $i ?>][done]" value="1"<?= $c['done'] ? ' checked' : '' ?>> hotovo</label></td>
        <td><?php if ($c['id']): ?><label class="check"><input type="checkbox" name="col[<?= $i ?>][remove]" value="1"> zmazať</label><?php endif ?></td></tr>
    <?php endforeach ?>
    </tbody></table>
  </fieldset>
  <button class="primary">Uložiť</button>
</form>
<?php if ($p): ?><?= $view->partial('core/record', ['ref' => 'project:' . $p['id']]) ?><?php endif ?>
