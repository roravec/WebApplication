<?php use Panel\Invoicing\Calculator; use Panel\Invoicing\Invoices; use WebApplication\Csrf;
/** @var array|null $inv, $original; array $h, $lines, $errors, $company, $companies, $series, $banks, $rates; bool $vatPayer, $showVat; string $defaultRate */
$err = function ($k) use ($errors, $e) { return isset($errors[$k]) ? '<span class="field-err">' . $e($errors[$k]) . '</span>' : ''; };
$action = $inv ? '/invoices/' . $inv['id'] : '/invoices';
$num = function ($v, int $dec) { return is_numeric($v) ? rtrim(rtrim(number_format((float)$v, $dec, ',', ''), '0'), ',') : (string)$v; };
$lineRow = function (array $l) use ($e, $showVat, $num) { ?>
  <tr>
    <td><input name="line_name[]" value="<?= $e($l['name']) ?>" class="w-name" placeholder="Názov položky"></td>
    <td><input name="line_sku[]" value="<?= $e($l['sku'] ?? '') ?>" size="8" placeholder="kód"></td>
    <td><input name="line_qty[]" value="<?= $e($num($l['quantity'], 3)) ?>" size="6" inputmode="decimal" class="num"></td>
    <td><input name="line_unit[]" value="<?= $e($l['unit'] ?? 'ks') ?>" size="4"></td>
    <td><input name="line_price[]" value="<?= $e($num($l['unit_price'], 4)) ?>" size="9" inputmode="decimal" class="num"></td>
    <?php if ($showVat): ?>
    <td><input name="line_vat[]" value="<?= $e($num($l['vat_rate'], 2)) ?>" size="4" inputmode="decimal" class="num" list="vat-rates"></td>
    <td><select name="line_cat[]">
      <?php foreach (Calculator::CATEGORIES as $k => $label): if ($k === 'O') continue; ?>
        <option value="<?= $e($k) ?>" title="<?= $e($label) ?>"<?= ($l['vat_category'] ?? 'S') === $k ? ' selected' : '' ?>><?= $e($k) ?></option>
      <?php endforeach ?>
    </select></td>
    <?php endif ?>
    <td class="num line-total"></td>
    <td><button type="button" class="link-danger" data-remove-row title="Odstrániť položku">✕</button></td>
  </tr>
<?php };
?>
<p><a href="<?= $e($ctx->url($inv ? '/invoices/' . $inv['id'] : '/invoices')) ?>">← <?= $inv ? 'Späť na doklad' : 'Faktúry' ?></a></p>
<?php if ($errors): ?><p class="flash err">Formulár obsahuje chyby — skontrolujte označené polia.</p><?php endif ?>

<form id="invoice-form" method="post" action="<?= $e($ctx->url($action)) ?>">
  <?= Csrf::field() ?>
  <input type="hidden" name="company_id" value="<?= (int)$h['company_id'] ?>">
  <input type="hidden" name="customer_id" value="<?= $e($h['customer_id']) ?>">

  <div class="cards">
    <section class="card form-grid">
      <h2>Dodávateľ</h2>
      <div class="wide">
        <b><?= $e($company['name']) ?></b>
        <?php if (count($companies) > 1): ?>
          · <small>zmeniť: <?php foreach ($companies as $c): if ((int)$c['id'] === (int)$h['company_id']) continue; ?>
            <a href="<?= $e($ctx->url('/invoices/new?company=' . (int)$c['id'])) ?>"><?= $e($c['name']) ?></a> <?php endforeach ?></small>
        <?php endif ?>
        <br><small class="muted"><?= $vatPayer ? 'Platiteľ DPH' : 'Neplatiteľ DPH — faktúra bude bez DPH' ?> (k dátumu dodania)</small>
        <?php if ($h['doc_type'] === 'credit_note'): ?>
          <br>Dobropis k faktúre <?php if ($original): ?><a href="<?= $e($ctx->url('/invoices/' . $original['id'])) ?>"><?= $e($original['number']) ?></a><?php endif ?>
        <?php endif ?>
      </div>
      <label>Číselný rad
        <select name="series_id">
          <?php foreach ($series as $s): ?><option value="<?= (int)$s['id'] ?>"<?= (int)$h['series_id'] === (int)$s['id'] ? ' selected' : '' ?>><?= $e($s['name'] . ' (' . $s['format'] . ')') ?></option><?php endforeach ?>
          <?php if (!$series): ?><option value="">— žiadny rad, nastavte vo Firmách —</option><?php endif ?>
        </select><?= $err('series_id') ?></label>
      <label>Vystavil <input name="issued_by_name" value="<?= $e($h['issued_by_name']) ?>"></label>
      <label>Dátum vystavenia <input type="date" name="issue_date" value="<?= $e($h['issue_date']) ?>" required><?= $err('issue_date') ?></label>
      <label>Dátum dodania <input type="date" name="delivery_date" value="<?= $e($h['delivery_date']) ?>" required><?= $err('delivery_date') ?></label>
      <label>Dátum splatnosti <input type="date" name="due_date" value="<?= $e($h['due_date']) ?>" required><?= $err('due_date') ?></label>
      <label>Forma úhrady
        <select name="payment_method">
          <?php foreach (Invoices::PAYMENT_METHODS as $k => $label): ?><option value="<?= $e($k) ?>"<?= $h['payment_method'] === $k ? ' selected' : '' ?>><?= $e($label) ?></option><?php endforeach ?>
        </select><?= $err('payment_method') ?></label>
      <label>Bankový účet
        <select name="bank_account_id"><option value="">—</option>
          <?php foreach ($banks as $b): ?><option value="<?= (int)$b['id'] ?>"<?= (int)$h['bank_account_id'] === (int)$b['id'] ? ' selected' : '' ?>><?= $e($b['iban']) ?></option><?php endforeach ?>
        </select><?= $err('bank_account_id') ?></label>
      <label>Mena <input name="currency" value="<?= $e($h['currency']) ?>" size="4" maxlength="3"><?= $err('currency') ?></label>
    </section>

    <section class="card form-grid">
      <h2>Odberateľ</h2>
      <div class="wide lookup">
        <input id="customer-search" data-url="<?= $e($ctx->url('/customers/lookup?company=' . (int)$h['company_id'])) ?>" placeholder="Hľadať v adresári odberateľov (meno, IČO, e-mail)…" autocomplete="off">
        <div id="customer-results" class="results"></div>
        <small id="customer-linked" class="muted"><?= $h['customer_id'] ? 'Prepojené s adresárom odberateľov.' : '' ?></small>
      </div>
      <label>Typ
        <select name="buyer_type"><option value="b2c">fyzická osoba</option><option value="b2b"<?= $h['buyer_type'] === 'b2b' ? ' selected' : '' ?>>firma</option></select></label>
      <label>Meno / obchodné meno <input name="buyer_name" value="<?= $e($h['buyer_name']) ?>"><?= $err('buyer_name') ?></label>
      <label class="wide">Ulica a číslo <input name="buyer_street" value="<?= $e($h['buyer_street']) ?>"></label>
      <label>PSČ <input name="buyer_zip" value="<?= $e($h['buyer_zip']) ?>"></label>
      <label>Mesto <input name="buyer_city" value="<?= $e($h['buyer_city']) ?>"></label>
      <label>Krajina <input name="buyer_country" value="<?= $e($h['buyer_country']) ?>" maxlength="2" size="3"><?= $err('buyer_country') ?></label>
      <label>IČO <input name="buyer_ico" value="<?= $e($h['buyer_ico']) ?>"><?= $err('buyer_ico') ?> <button type="button" class="link rpo" data-rpo="buyer_" data-url="<?= $e($ctx->url('/lookup/ico')) ?>">Doplniť z registra</button></label>
      <label>DIČ <input name="buyer_dic" value="<?= $e($h['buyer_dic']) ?>"></label>
      <label>IČ DPH <input name="buyer_icdph" value="<?= $e($h['buyer_icdph']) ?>"></label>
      <label>E-mail <input name="buyer_email" type="email" value="<?= $e($h['buyer_email']) ?>"><?= $err('buyer_email') ?></label>
      <label>Telefón <input name="buyer_phone" value="<?= $e($h['buyer_phone']) ?>"></label>
      <label class="check wide"><input type="checkbox" name="save_customer" value="1"<?= $h['customer_id'] ? '' : ' checked' ?>> Uložiť / aktualizovať v adresári odberateľov</label>
    </section>
  </div>

  <section class="card">
    <h2>Položky</h2>
    <?= $err('lines') ?>
    <div class="scroll-x">
    <table class="grid compact" id="lines-table" data-vat-payer="<?= $vatPayer ? '1' : '0' ?>">
      <thead><tr><th>Názov</th><th>Kód</th><th class="num">Množstvo</th><th>MJ</th><th class="num">Cena/MJ bez DPH</th>
        <?php if ($showVat): ?><th class="num">DPH %</th><th title="Kategória DPH (EN 16931)">Kat.</th><?php endif ?>
        <th class="num">Spolu</th><th></th></tr></thead>
      <tbody id="line-rows"><?php foreach ($lines as $l) { $lineRow($l); } ?></tbody>
      <tfoot>
        <tr><td colspan="<?= $showVat ? 7 : 5 ?>" class="num">Základ</td><td class="num" id="sum-net"></td><td></td></tr>
        <?php if ($showVat): ?><tr><td colspan="7" class="num">DPH</td><td class="num" id="sum-vat"></td><td></td></tr><?php endif ?>
        <tr class="total"><td colspan="<?= $showVat ? 7 : 5 ?>" class="num">Spolu (náhľad)</td><td class="num" id="sum-total"></td><td></td></tr>
      </tfoot>
    </table>
    </div>
    <template id="line-rows-tpl"><?php $lineRow(['name' => '', 'quantity' => '1', 'unit' => 'ks', 'unit_price' => '', 'vat_rate' => $defaultRate, 'vat_category' => 'S']); ?></template>
    <datalist id="vat-rates"><?php foreach ($rates as $r): ?><option value="<?= $e($num($r, 2)) ?>"><?php endforeach ?></datalist>
    <?php if ($showVat && $rates): ?><small class="muted">Platné sadzby DPH k dátumu dodania: <?= $e(implode(' %, ', array_map(function ($r) use ($num) { return $num($r, 2); }, $rates))) ?> % (Nastavenia → Legislatíva).</small><?php endif ?>
    <p><button type="button" data-add-row="line-rows">+ Položka</button>
      <?php if ($h['doc_type'] === 'credit_note'): ?><small class="muted">Dobropis: množstvá sú záporné (vrátené / opravené položky).</small><?php endif ?></p>
  </section>

  <section class="card form-grid">
    <?php if ($h['doc_type'] === 'credit_note'): ?>
      <label class="wide">Dôvod opravy (povinné) <input name="correction_reason" value="<?= $e($h['correction_reason']) ?>" maxlength="255"><?= $err('correction_reason') ?></label>
    <?php endif ?>
    <label class="wide">Poznámka na doklade <textarea name="note" rows="2"><?= $e($h['note']) ?></textarea></label>
    <label class="wide">Interná poznámka (netlačí sa) <textarea name="internal_note" rows="2"><?= $e($h['internal_note']) ?></textarea></label>
  </section>

  <p class="actions">
    <button class="primary" name="action" value="save">Uložiť koncept</button>
    <button name="action" value="issue" data-confirm="Vystaviť doklad? Po vystavení dostane číslo a už sa nedá meniť ani zmazať.">Uložiť a vystaviť</button>
  </p>
</form>
