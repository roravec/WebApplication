<?php use WebApplication\Csrf;
/**
 * @var WebApplication\Entity\Client|null $client; array $values (identifier, name, status, access), $errors, $roles, $groups,
 * $companies (id => name), $shops (list of key, name, source), $shopErrors, $editorPerms, $readOnly (what he has beyond the
 * editor — read only), $effective; bool $editorAdmin; int $minPassword
 */
$err = function ($k) use ($errors, $e) { return isset($errors[$k]) ? '<span class="field-err">' . $e($errors[$k]) . '</span>' : ''; };
$action = $client ? '/employees/' . $client->getId() : '/employees';
$a = $values['access'];
$fromRoles = [];
foreach ($roles as $r) {
    if (in_array($r['id'], $a['roles'], true)) {
        $fromRoles = array_merge($fromRoles, $r['permissions']);
    }
}
$fromRoles = array_flip($fromRoles);
$effective = array_flip($effective);
$bySource = [];
foreach ($shops as $s) {
    $bySource[$s['source']][] = $s;
}
?>
<p><a href="<?= $e($ctx->url('/employees')) ?>">← Zamestnanci</a></p>
<?php if ($readOnly): ?>
  <div class="card"><p><b>Tohto zamestnanca nemôžete upravovať</b> — má viac práv ako vy (<?= $e(implode(', ', $readOnly)) ?>).
    Zmeniť ho môže správca alebo niekto, kto má aspoň toľko práv.</p></div>
<?php endif ?>
<form class="card form" method="post" action="<?= $e($ctx->url($action)) ?>" id="employee-access">
  <?= Csrf::field() ?>
  <fieldset class="plain"<?= $readOnly ? ' disabled' : '' ?>>
  <label>Meno a priezvisko <input name="name" value="<?= $e($values['name']) ?>" required><?= $err('name') ?></label>
  <label>E-mail (prihlasovacie meno) <input name="identifier" type="email" value="<?= $e($values['identifier']) ?>" required autocomplete="off"><?= $err('identifier') ?></label>

  <fieldset>
    <legend>Čo smie robiť</legend>
    <?= $err('access') ?>
    <label class="check"><input type="checkbox" name="admin" value="1" data-toggles="#by-roles"<?= $a['admin'] ? ' checked' : '' ?><?= $editorAdmin ? '' : ' disabled' ?>>
      <span><b>Správca</b> — smie všetko, aj v moduloch, ktoré pribudnú, vo všetkých firmách a e-shopoch<?= $editorAdmin ? '' : ' (správcu môže určiť len správca)' ?></span></label>
    <div id="by-roles" class="perms"<?= $a['admin'] ? ' hidden' : '' ?>>
      <p class="field-hint">Zaškrtnite <b>roly</b> — každá rola je sada práv pre jednu prácu (upravujú sa v záložke <a href="<?= $e($ctx->url('/employees/roles')) ?>">Roly</a>).
        Kto potrebuje niečo navyše alebo menej, dostane <b>výnimku</b> nižšie.</p>
      <?php if (!$roles): ?><p class="muted small">Zatiaľ nie je žiadna rola — vytvorte ju v záložke Roly, alebo nastavte práva výnimkami.</p><?php endif ?>
      <?php foreach ($roles as $r): $over = $editorAdmin ? [] : array_diff($r['permissions'], $editorPerms); ?>
        <label class="check"><input type="checkbox" name="roles[]" value="<?= (int)$r['id'] ?>" data-perms="<?= $e(implode(',', $r['permissions'])) ?>"<?= in_array($r['id'], $a['roles'], true) ? ' checked' : '' ?>>
          <span><b><?= $e($r['name']) ?></b><?= $r['description'] !== '' ? ' — ' . $e($r['description']) : '' ?>
            <?php if ($over): ?><br><small class="field-err">dáva aj práva, ktoré sami nemáte — priradiť ju môže len ten, kto ich má</small><?php endif ?></span></label>
      <?php endforeach ?>

      <details<?= $a['allow'] || $a['deny'] || isset($errors['access']) ? ' open' : '' ?>>
        <summary>Výnimky — jednotlivé práva navyše alebo zakázané (<?= count($a['allow']) + count($a['deny']) ?>)</summary>
        <p class="field-hint">„Podľa rolí“ = platí, čo dávajú roly. „Povoliť navyše“ = má to právo, aj keď ho žiadna rola nedáva.
          „Zakázať“ = nemá ho, aj keď ho rola dáva. <span class="badge">citlivé</span> = peniaze, nastavenia alebo nevratné kroky.</p>
        <div class="scroll-x"><table class="grid compact">
          <thead><tr><th>Právo</th><th>Z roly</th><th>Výnimka</th><th>Výsledok</th></tr></thead>
          <tbody>
          <?php foreach ($groups as $group => $perms): ?>
            <tr><th colspan="4"><?= $e($group) ?></th></tr>
            <?php foreach ($perms as $key => $p): $mode = in_array($key, $a['allow'], true) ? 'allow' : (in_array($key, $a['deny'], true) ? 'deny' : '');
                  $mine = $editorAdmin || in_array($key, $editorPerms, true); ?>
              <tr data-perm="<?= $e($key) ?>">
                <td><?= $e($p['label']) ?><?= $p['sensitive'] ? ' <span class="badge">citlivé</span>' : '' ?><?= $mine ? '' : ' <small class="muted">(nemáte ho — nemôžete ho dať)</small>' ?></td>
                <td class="from-role"><?= isset($fromRoles[$key]) ? '✓' : '–' ?></td>
                <td><select name="perm[<?= $e($key) ?>]" aria-label="Výnimka: <?= $e($p['label']) ?>">
                  <option value="">podľa rolí</option>
                  <option value="allow"<?= $mode === 'allow' ? ' selected' : '' ?>>povoliť navyše</option>
                  <option value="deny"<?= $mode === 'deny' ? ' selected' : '' ?>>zakázať</option>
                </select></td>
                <td class="result <?= isset($effective[$key]) ? 'yes' : 'no' ?>"><?= isset($effective[$key]) ? 'áno' : 'nie' ?></td>
              </tr>
            <?php endforeach ?>
          <?php endforeach ?>
          </tbody>
        </table></div>
      </details>

      <h3>Firmy</h3>
      <p class="field-hint">Faktúry, náklady, sklad, výsledky a nastavenia len týchto firiem.</p>
      <label class="check"><input type="checkbox" name="all_companies" value="1" data-toggles="#company-list"<?= $a['all_companies'] ? ' checked' : '' ?>>
        <span><b>Všetky firmy</b> — aj tie, ktoré sa založia neskôr; vidí aj údaje bez firmy (napr. e-shop nepriradený žiadnej firme)</span></label>
      <div id="company-list" class="perms"<?= $a['all_companies'] ? ' hidden' : '' ?>>
        <?php if (!$companies): ?><p class="muted small">Zatiaľ nie je založená žiadna firma.</p><?php endif ?>
        <?php foreach ($companies as $id => $name): ?>
          <label class="check"><input type="checkbox" name="companies[]" value="<?= (int)$id ?>"<?= in_array((int)$id, $a['companies'], true) ? ' checked' : '' ?>> <?= $e($name) ?></label>
        <?php endforeach ?>
      </div>

      <h3>E-shopy</h3>
      <p class="field-hint">Objednávky, expedícia, produkty a sklad v e-shope len týchto e-shopov (výsledky a náklady navyše len e-shopov jeho firiem).</p>
      <?= $err('shops') ?>
      <label class="check"><input type="checkbox" name="all_shops" value="1" data-toggles="#shop-list"<?= $a['all_shops'] ? ' checked' : '' ?>>
        <span><b>Všetky e-shopy</b> — aj tie, ktoré sa pripoja neskôr</span></label>
      <div id="shop-list" class="perms"<?= $a['all_shops'] ? ' hidden' : '' ?>>
        <?php foreach ($shopErrors as $se): ?><p class="field-err">E-shopy sa nedajú načítať — <?= $e($se) ?></p><?php endforeach ?>
        <?php if (!$shops && !$shopErrors): ?><p class="muted small">Zatiaľ nie je pripojený žiadny e-shop.</p><?php endif ?>
        <?php foreach ($bySource as $source => $list): ?>
          <?php if ($source !== ''): ?><p class="muted small"><?= $e($source) ?></p><?php endif ?>
          <?php foreach ($list as $s): ?>
            <label class="check"><input type="checkbox" name="shops[]" value="<?= $e($s['key']) ?>"<?= in_array($s['key'], $a['shops'], true) ? ' checked' : '' ?>> <?= $e($s['name']) ?></label>
          <?php endforeach ?>
        <?php endforeach ?>
      </div>
    </div>
  </fieldset>

  <label class="check"><input type="checkbox" name="status" value="1"<?= $values['status'] ? ' checked' : '' ?>> Aktívny (môže sa prihlásiť)</label>
  <label><?= $client ? 'Nové heslo (nechajte prázdne, ak sa nemení)' : 'Heslo' ?>
    <input name="password" type="password" autocomplete="new-password" minlength="<?= (int)$minPassword ?>"><?= $err('password') ?></label>
  <?php if (!$readOnly): ?><button class="primary">Uložiť</button><?php endif ?>
  </fieldset>
</form>

<?php if ($client): ?><?= $view->partial('core/record', ['ref' => 'employee:' . $client->getId()]) ?><?php endif ?>
