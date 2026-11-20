<?php use Panel\Invoicing\Numbering; use WebApplication\Csrf;
/** @var array|null $company; array $values, $errors, $shops, $shopMap, $allCompanies, $series */
$err = function ($k) use ($errors, $e) { return isset($errors[$k]) ? '<span class="field-err">' . $e($errors[$k]) . '</span>' : ''; };
$v = function ($k) use ($values) { return $values[$k] ?? ''; };
$action = $company ? '/companies/' . $company['id'] : '/companies';
$names = array_column($allCompanies, 'name', 'id');
$vatRow = function (array $p) use ($e) { ?>
  <tr>
    <td><input type="date" name="vat_from[]" value="<?= $e($p['valid_from']) ?>"></td>
    <td><select name="vat_payer[]"><option value="0">neplatiteľ DPH</option><option value="1"<?= $p['vat_payer'] ? ' selected' : '' ?>>platiteľ DPH</option></select></td>
    <td><input name="vat_icdph[]" value="<?= $e($p['icdph']) ?>" placeholder="SK2121232916" size="16"></td>
    <td><button type="button" class="link-danger" data-remove-row title="Odstrániť">✕</button></td>
  </tr>
<?php };
$bankRow = function (array $b) use ($e) { ?>
  <tr>
    <td><input type="hidden" name="bank_id[]" value="<?= (int)$b['id'] ?>"><input name="bank_name[]" value="<?= $e($b['account_name']) ?>" placeholder="Názov účtu"></td>
    <td><input name="bank_iban[]" value="<?= $e($b['iban']) ?>" placeholder="SK.." size="28"></td>
    <td><input name="bank_bic[]" value="<?= $e($b['bic']) ?>" size="11"></td>
    <td><select name="bank_default[]"><option value="0">nie</option><option value="1"<?= $b['is_default'] ? ' selected' : '' ?>>áno</option></select></td>
    <td><select name="bank_active[]"><option value="1">aktívny</option><option value="0"<?= $b['active'] ? '' : ' selected' ?>>neaktívny</option></select></td>
    <td><input type="password" name="bank_token[]" value="" autocomplete="new-password" size="14"
      placeholder="<?= !empty($b['api_token']) ? 'uložený (Fio)' : 'Fio API token' ?>"
      title="<?= !empty($b['api_token']) ? 'Token je uložený zašifrovaný. Nechajte prázdne = bez zmeny, napíšte „zmazať“ = odstrániť.' : 'Token „len na čítanie“ z Fio internetbankingu (Nastavenia → API) — pre automatické sťahovanie pohybov.' ?>"></td>
  </tr>
<?php };
?>
<p><a href="<?= $e($ctx->url('/companies')) ?>">← Firmy</a></p>
<form method="post" action="<?= $e($ctx->url($action)) ?>" enctype="multipart/form-data">
  <?= Csrf::field() ?>
  <div class="cards">
    <section class="card form-grid">
      <h2>Údaje firmy</h2>
      <label class="wide">Obchodné meno <input name="name" value="<?= $e($v('name')) ?>" required><?= $err('name') ?></label>
      <label class="wide">Ulica a číslo <input name="street" value="<?= $e($v('street')) ?>"></label>
      <label>PSČ <input name="zip" value="<?= $e($v('zip')) ?>" size="8"></label>
      <label>Mesto <input name="city" value="<?= $e($v('city')) ?>"></label>
      <label>Krajina <input name="country" value="<?= $e($v('country')) ?>" size="3" maxlength="2"><?= $err('country') ?></label>
      <label>IČO <input name="ico" value="<?= $e($v('ico')) ?>"><?= $err('ico') ?> <button type="button" class="link rpo" data-rpo="" data-url="<?= $e($ctx->url('/lookup/ico')) ?>">Doplniť z registra</button></label>
      <label>DIČ <input name="dic" value="<?= $e($v('dic')) ?>"></label>
      <label class="wide">Zápis v obchodnom registri <input name="registration" value="<?= $e($v('registration')) ?>" placeholder="Obchodný register Okresného súdu …, oddiel Sro, vložka č. …">
        <?php if ($v('registration') === ''): ?><small class="field-hint">Povinný údaj na faktúre (Obchodný zákonník § 3a).</small><?php endif ?></label>
    </section>
    <section class="card form-grid">
      <h2>Kontakty a faktúra</h2>
      <label>E-mail <input name="email" type="email" value="<?= $e($v('email')) ?>"><?= $err('email') ?></label>
      <label>Telefón <input name="phone" value="<?= $e($v('phone')) ?>"></label>
      <label>Web <input name="web" value="<?= $e($v('web')) ?>"></label>
      <label>Slogan (hlavička faktúry) <input name="slogan" value="<?= $e($v('slogan')) ?>"></label>
      <label>Splatnosť (dní) <input name="due_days" type="number" min="0" max="365" value="<?= (int)$v('due_days') ?>"><?= $err('due_days') ?></label>
      <label>Farba faktúry <input name="accent_color" type="color" value="<?= $e($v('accent_color') ?: '#f74040') ?>"><?= $err('accent_color') ?></label>
      <label class="wide">Poznámka na faktúre (predvolená) <textarea name="invoice_note" rows="2"><?= $e($v('invoice_note')) ?></textarea></label>
      <label class="check wide"><input type="checkbox" name="active" value="1"<?= $v('active') ? ' checked' : '' ?>> Aktívna (dá sa fakturovať)</label>
    </section>
  </div>

  <section class="card">
    <h2>Režim DPH</h2>
    <p class="muted small">Od ktorého dňa je firma platiteľ / neplatiteľ DPH. Pre faktúru platí obdobie podľa dátumu dodania. Bez obdobia = neplatiteľ.</p>
    <?= $err('vat') ?>
    <table class="grid compact">
      <thead><tr><th>Platí od</th><th>Režim</th><th>IČ DPH</th><th></th></tr></thead>
      <tbody id="vat-rows"><?php foreach ($values['vat'] as $p) { $vatRow($p); } ?></tbody>
    </table>
    <template id="vat-rows-tpl"><?php $vatRow(['valid_from' => '', 'vat_payer' => 0, 'icdph' => '']); ?></template>
    <p><button type="button" data-add-row="vat-rows">+ Obdobie DPH</button></p>
  </section>

  <section class="card">
    <h2>Bankové účty</h2>
    <?= $err('banks') ?>
    <table class="grid compact">
      <thead><tr><th>Názov účtu</th><th>IBAN</th><th>BIC</th><th>Predvolený</th><th>Stav</th><th title="Automatické sťahovanie pohybov">API banky</th></tr></thead>
      <tbody id="bank-rows"><?php foreach ($values['banks'] as $b) { $bankRow($b); } ?></tbody>
    </table>
    <template id="bank-rows-tpl"><?php $bankRow(['id' => 0, 'account_name' => '', 'iban' => '', 'bic' => '', 'is_default' => 0, 'active' => 1, 'api_token' => null]); ?></template>
    <p class="muted small">API banky: token Fio banky (internetbanking → Nastavenia → API, oprávnenie len na čítanie) — pohyby sa
      potom sťahujú tlačidlom vo Faktúry → Import výpisu. Token sa uloží zašifrovaný a už sa nezobrazí.</p>
    <p><button type="button" data-add-row="bank-rows">+ Bankový účet</button></p>
  </section>

  <div class="cards">
    <section class="card">
      <h2>Logo</h2>
      <?php if ($company && $company['logo_mime']): ?>
        <p><img class="preview" src="<?= $e($ctx->url('/companies/' . $company['id'] . '/logo')) ?>" alt="Logo"></p>
        <label class="check"><input type="checkbox" name="remove_logo" value="1"> Odstrániť logo</label>
      <?php endif ?>
      <label>Nahrať PNG/JPG (max 2 MB) <input type="file" name="logo" accept="image/png,image/jpeg"><?= $err('logo') ?></label>
    </section>
    <section class="card">
      <h2>Pečiatka a podpis</h2>
      <?php if ($company && $company['stamp_mime']): ?>
        <p><img class="preview" src="<?= $e($ctx->url('/companies/' . $company['id'] . '/stamp')) ?>" alt="Pečiatka"></p>
        <label class="check"><input type="checkbox" name="remove_stamp" value="1"> Odstrániť pečiatku</label>
      <?php endif ?>
      <label>Nahrať PNG/JPG (max 2 MB) <input type="file" name="stamp" accept="image/png,image/jpeg"><?= $err('stamp') ?></label>
    </section>
    <section class="card">
      <h2>E-shopy</h2>
      <p class="muted small">Objednávky ktorých e-shopov fakturuje táto firma.</p>
      <?php if (!$shops): ?><p class="muted">E-shop nie je dostupný.</p><?php endif ?>
      <?php foreach ($shops as $g): ?>
        <?php if (count($shops) > 1): ?><p class="small"><b><?= $e($g['label']) ?></b></p><?php endif ?>
        <?php if ($g['error'] !== null): ?>
          <p class="muted small">E-shop neodpovedá (<?= $e($g['error']) ?>) — jeho priradenie zostáva bez zmeny.</p>
        <?php else: ?>
          <input type="hidden" name="shop_sources[]" value="<?= (int)$g['src'] ?>">
          <?php foreach ($g['shops'] as $s): $key = $g['src'] . ':' . (int)$s['id_shop']; $owner = $shopMap[$key] ?? 0; ?>
            <label class="check"><input type="checkbox" name="shops[]" value="<?= $e($key) ?>"<?= in_array($key, $values['shops'], true) ? ' checked' : '' ?>>
              <?= $e($s['name']) ?><?php if ($owner && (!$company || $owner !== (int)$company['id'])): ?> <small class="muted">(teraz: <?= $e($names[$owner] ?? 'iná firma') ?>)</small><?php endif ?></label>
          <?php endforeach ?>
        <?php endif ?>
      <?php endforeach ?>
    </section>
  </div>
  <?php $om = $values['omega']; ?>
  <section class="card form-grid">
    <h2>Export do Omegy (účtovník)</h2>
    <p class="wide muted small">Hodnoty podľa nastavenia Omegy u účtovníka. Ak ich nepoznáte, nechajte predvolené (OF, OD) — kód
      číselného radu je nepovinný.</p>
    <?= $err('omega') ?>
    <label>Kód evidencie — faktúry <input name="omega_evidence_invoice" value="<?= $e($om['evidence_invoice']) ?>" maxlength="5"></label>
    <label>Kód evidencie — dobropisy <input name="omega_evidence_credit" value="<?= $e($om['evidence_credit']) ?>" maxlength="5"></label>
    <label>Kód číselného radu — faktúry <input name="omega_series_invoice" value="<?= $e($om['series_invoice']) ?>" maxlength="5"></label>
    <label>Kód číselného radu — dobropisy <input name="omega_series_credit" value="<?= $e($om['series_credit']) ?>" maxlength="5"></label>
    <label>Formát (verzia KROS)
      <select name="omega_version"><?php foreach (Panel\Invoicing\OmegaExport::VERSIONS as $k => $label): ?><option value="<?= $e($k) ?>"<?= ($om['version'] ?? '') === $k ? ' selected' : '' ?>><?= $e($label) ?></option><?php endforeach ?></select></label>
    <label>Desatinný oddeľovač
      <select name="omega_decimal"><option value=".">bodka (1234.50)</option><option value=","<?= $om['decimal'] === ',' ? ' selected' : '' ?>>čiarka (1234,50)</option></select></label>
    <label class="check"><input type="checkbox" name="omega_partners" value="1"<?= $om['partners'] ? ' checked' : '' ?>> Pridať aj odberateľov-firmy do partnerov</label>
  </section>
  <?php $ac = $values['accountant']; ?>
  <section class="card form-grid" id="accountant">
    <h2>Pre účtovníka (mesačný balík)</h2>
    <p class="wide muted small">Raz za mesiac jeden e-mail účtovníkovi: súbor na import do Omegy (nastavenie vyššie), PDF všetkých
      dokladov a prehľad dokladov a úhrad. Dá sa poslať aj ručne a stiahnuť v časti Faktúry → Pre účtovníka.</p>
    <?= $err('accountant') ?>
    <label class="wide">E-mail účtovníka <input name="acc_email" value="<?= $e($ac['email']) ?>" placeholder="uctovnicka@example.sk (viac adries oddeľte čiarkou)"></label>
    <label class="check"><input type="checkbox" name="acc_auto" value="1"<?= $ac['auto'] ? ' checked' : '' ?>> Posielať automaticky za predchádzajúci mesiac</label>
    <label>od dňa v mesiaci <input type="number" name="acc_day" min="1" max="28" value="<?= (int)$ac['day'] ?>"></label>
  </section>
  <?php $ms = $values['mail']; ?>
  <section class="card form-grid">
    <h2>E-mail s faktúrou</h2>
    <p class="wide muted small">Odosielateľ a text e-mailu, ktorým sa posiela faktúra (pred odoslaním sa dá upraviť).
      Zástupné znaky: {document} {document_lower} {document_acc} (faktúru / dobropis) {number} {total} {due_date} {vs} {iban} {order} {customer} {company}
      {web} {email} {phone} {payment_info}. Prístup k SMTP serveru je v config.php, nie tu.</p>
    <?= $err('mail') ?>
    <label>Meno odosielateľa <input name="mail_from_name" value="<?= $e($ms['from_name']) ?>" placeholder="<?= $e($v('name')) ?>"></label>
    <label>E-mail odosielateľa <input name="mail_from_email" value="<?= $e($ms['from_email']) ?>" placeholder="<?= $e($v('email')) ?>"></label>
    <label>Odpovede na <input name="mail_reply_to" value="<?= $e($ms['reply_to']) ?>"></label>
    <label>Skrytá kópia každej faktúry <input name="mail_bcc" value="<?= $e($ms['bcc']) ?>" placeholder="napr. účtovník"></label>
    <label class="wide">Predmet <input name="mail_subject" value="<?= $e($ms['subject']) ?>"></label>
    <label class="wide">Text <textarea name="mail_body" rows="7"><?= $e($ms['body']) ?></textarea></label>
    <label class="wide">{payment_info} pri prevode <input name="mail_pay_transfer" value="<?= $e($ms['pay_transfer']) ?>"></label>
    <label class="wide">{payment_info} keď je uhradená (karta / hotovosť) <input name="mail_pay_paid" value="<?= $e($ms['pay_paid']) ?>"></label>
    <label class="wide">{payment_info} pri dobierke <input name="mail_pay_cod" value="<?= $e($ms['pay_cod']) ?>"></label>
    <label class="wide">Upomienka — predmet <input name="mail_reminder_subject" value="<?= $e($ms['reminder_subject']) ?>"></label>
    <label class="wide">Upomienka — text ({rest} = zostáva uhradiť) <textarea name="mail_reminder_body" rows="6"><?= $e($ms['reminder_body']) ?></textarea></label>
  </section>
  <p><button class="primary">Uložiť firmu</button></p>
</form>

<?php if ($company): ?>
<section class="card" id="series">
  <h2>Číselné rady</h2>
  <table class="grid compact">
    <thead><tr><th>Názov</th><th>Typ dokladu</th><th>Formát</th><th>Ďalšie číslo</th><th>Nulovanie</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($series as $s): ?>
      <tr class="<?= $s['active'] ? '' : 'inactive' ?>">
        <td><a href="<?= $e($ctx->url('/companies/' . $company['id'] . '/series/' . $s['id'])) ?>"><?= $e($s['name']) ?></a><?= $s['is_default'] ? ' <span class="badge">predvolený</span>' : '' ?></td>
        <td><?= $e(Numbering::allTypes()[$s['doc_type']] ?? $s['doc_type']) ?></td>
        <td><code><?= $e($s['format']) ?></code></td>
        <td><b><?= $e($s['example']) ?></b></td>
        <td><?= $s['reset_yearly'] ? 'každý rok' : 'nie' ?></td>
        <td><?= $s['active'] ? '' : 'neaktívny' ?></td>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table>
  <p><a class="button" href="<?= $e($ctx->url('/companies/' . $company['id'] . '/series/new')) ?>">+ Číselný rad</a></p>
</section>
<?php endif ?>
