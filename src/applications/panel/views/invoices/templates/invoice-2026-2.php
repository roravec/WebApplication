<?php use Panel\Invoicing\Invoices; use Panel\Invoicing\Legislation;
/**
 * Print template "invoice-2026-2" (owner 2026-11-21: closer to his design ermavzor FA2026/000198 + everything the old
 * shop invoices had — FA2026/000214): separate date bars, IČO / DIČ under each other, all item columns incl. VAT, three
 * total boxes, "Vystavil" + stamp bottom right; domestic account number + bank, buyer phone / e-mail, product codes;
 * for a document from an e-shop order the shop's invoice logo, web, e-mail and phone in the header + "also a delivery
 * note" and the thank-you text (both editable in Legislatíva).
 * The same HTML is shown in the browser and rendered to PDF by dompdf → only tables, floats and position:fixed (no grid/flex).
 * DO NOT EDIT once invoices were issued with it in production: copy to a new file and switch Templates::CURRENT.
 * @var array $inv, $supplier, $legal; array|null $original, $shop; string $title, $logo, $stamp, $qr (data URIs or ''),
 *      $qrError, $fontBase, $bankName; bool $forPdf
 */
$cur = $inv['currency'];
$payer = (bool)$inv['vat_payer'];
$accent = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($supplier['accent_color'] ?? '')) ? $supplier['accent_color'] : '#f74040';
$m = function ($v) use ($f, $cur) { return $f->money($v, $cur); };
$n = function ($v, int $dec = 2) use ($f) { return $f->number($v, $dec); };
$qty = function ($v) { $s = number_format((float)$v, 3, ',', ' '); return rtrim(rtrim($s, '0'), ','); };
// unit price: all its decimals (up to 4), at least 2 — so quantity × price matches the line total
$price = function ($v) use ($n) { $s = $n($v, 4); return preg_replace('/(,\d\d\d*?)0+$/', '$1', $s); };
$d = function ($date) { return $date ? date('j.n.Y', strtotime((string)$date)) : ''; };
$isCredit = $inv['doc_type'] === 'credit_note';
$draft = $inv['status'] === 'draft';
$t = function (string $code, array $vars = []) use ($legal) { return Legislation::fill((string)($legal[$code] ?? ''), $vars); };
$catTexts = [];
foreach ($inv['lines'] as $l) {
    if ($payer && ($l['vat_category'] ?? 'S') !== 'S' && $t('vat_' . $l['vat_category']) !== '') {
        $catTexts[$l['vat_category']] = $t('vat_' . $l['vat_category']);
    }
}
$countries = ['SK' => 'Slovensko', 'CZ' => 'Česko', 'AT' => 'Rakúsko', 'HU' => 'Maďarsko', 'PL' => 'Poľsko', 'DE' => 'Nemecko', 'US' => 'USA',
    'GB' => 'Spojené kráľovstvo', 'IT' => 'Taliansko', 'FR' => 'Francúzsko', 'NL' => 'Holandsko', 'BE' => 'Belgicko', 'CH' => 'Švajčiarsko'];
$addr = function (array $p, string $pre = '') use ($e, $countries) {
    $c = strtoupper((string)($p[$pre . 'country'] ?? 'SK')) ?: 'SK';
    return $e($p[$pre . 'street']) . '<br>' . $e(trim($p[$pre . 'zip'] . ' ' . $p[$pre . 'city'])) . '<br>' . $e($countries[$c] ?? $c);
};
$idRows = function (array $pairs) use ($e) {
    $out = '';
    foreach ($pairs as $label => $v) {
        if ((string)$v !== '') {
            $out .= '<tr><td class="lbl">' . $e($label) . ':</td><td>' . $e($v) . '</td></tr>';
        }
    }
    return $out !== '' ? '<table class="ids">' . $out . '</table>' : '';
};
// domestic account number from a Slovak / Czech IBAN: [prefix-]number/bank code (as the old invoices printed it)
$domestic = function (string $iban) {
    $iban = strtoupper(str_replace(' ', '', $iban));
    if (!preg_match('/^(SK|CZ)\d{2}(\d{4})(\d{6})(\d{10})$/', $iban, $x)) {
        return '';
    }
    $prefix = ltrim($x[3], '0');
    return ($prefix !== '' ? $prefix . '-' : '') . (ltrim($x[4], '0') ?: '0') . '/' . $x[2];
};
// header: the e-shop of the order (its invoice logo and contacts), otherwise the company
$headLogo = $shop && $shop['logo'] !== '' ? $shop['logo'] : $logo;
// the shop's web as a plain domain (https://www.howieshockey.sk/store/ → www.howieshockey.sk)
$domain = function ($url) { return (string)parse_url((string)$url, PHP_URL_HOST); };
$contacts = $shop ? array_filter([$domain($shop['web']), $shop['email'] ?: ($supplier['email'] ?? ''),
    $shop['phone'] ?: ($supplier['phone'] ?? '')]) : array_filter([$supplier['web'] ?? '', $supplier['email'] ?? '', $supplier['phone'] ?? '']);
$footContacts = array_filter([$supplier['web'] ?? '', $supplier['email'] ?? '', $supplier['phone'] ?? '']);
$font = function (string $file) use ($fontBase, $e) { return $e(rtrim($fontBase, '/') . '/' . $file); };
$account = $domestic((string)$inv['iban']);
?>
<!doctype html>
<html lang="sk">
<head>
<meta charset="utf-8">
<title><?= $e($title) ?></title>
<meta name="robots" content="noindex, nofollow">
<style>
  @font-face { font-family: 'Poppins'; font-style: normal; font-weight: 300; src: url('<?= $font('Poppins-Light.ttf') ?>') format('truetype'); }
  @font-face { font-family: 'Poppins'; font-style: normal; font-weight: normal; src: url('<?= $font('Poppins-Regular.ttf') ?>') format('truetype'); }
  @font-face { font-family: 'Poppins'; font-style: italic; font-weight: normal; src: url('<?= $font('Poppins-Italic.ttf') ?>') format('truetype'); }
  @font-face { font-family: 'Poppins'; font-style: normal; font-weight: bold; src: url('<?= $font('Poppins-SemiBold.ttf') ?>') format('truetype'); }
  @page { size: A4; margin: 10mm 12mm 19mm 12mm; }
  html { color-scheme: light; }
  body { margin: 0; color: #222; font-family: 'Poppins', 'DejaVu Sans', sans-serif; font-size: 8.8pt; line-height: 1.32; }
  table { border-collapse: collapse; }
  td, th { vertical-align: top; padding: 0; }
  b, th, .name { font-weight: bold; }
  .bar { background: <?= $e($accent) ?>; color: #fff; font-weight: bold; text-align: center; padding: 0.5mm 2mm; }
  .lbl { color: #444; }
  .small { font-size: 7pt; color: #555; }
  .headwrap { border-bottom: 1.2mm solid <?= $e($accent) ?>; padding-bottom: 2mm; }
  .head { width: 100%; }
  .head td { vertical-align: middle; }
  .head img { max-height: 16mm; max-width: 60mm; }
  .head .slogan { text-align: center; font-size: 10.5pt; color: <?= $e($accent) ?>; }
  .head .contacts { text-align: right; font-size: 9pt; color: <?= $e($accent) ?>; line-height: 1.3; }
  .title { width: 100%; margin: 3mm 0 0.5mm; }
  .title td { font-size: 19pt; font-weight: 300; color: <?= $e($accent) ?>; line-height: 1.1; }
  .title .no { text-align: right; }
  .parties { width: 100%; }
  .parties .name { color: <?= $e($accent) ?>; margin-top: 1mm; }
  .ids { margin-top: 2.5mm; }
  .ids td { padding: 0 3mm 0 0; }
  .ids td.lbl { width: 14mm; }
  .pay { width: 100%; margin-top: 3.5mm; }
  .pay .inner td { padding: 0.15mm 2mm 0.15mm 0; }
  .pay .inner td.lbl { white-space: nowrap; }
  .qr img { width: 27mm; height: 27mm; display: block; margin-left: auto; }
  .qr .cap { font-size: 6pt; font-weight: bold; text-align: center; letter-spacing: 0.3pt; }
  .date { text-align: center; padding: 0.6mm 0 2.2mm; }
  .note { font-style: italic; margin: 3mm 0 0.8mm; }
  .items { width: 100%; }
  .items th { background: <?= $e($accent) ?>; color: #fff; text-align: left; padding: 0.6mm 1.2mm; white-space: nowrap; }
  .items td { border: 0.2mm solid <?= $e($accent) ?>; padding: 0.4mm 1.2mm; }
  .items .r { text-align: right; white-space: nowrap; }
  .items .c { text-align: center; }
  .items .sku { font-size: 7pt; color: #555; }
  .bottom { width: 100%; margin-top: 3.5mm; page-break-inside: avoid; }
  .recap th, .recap td { padding: 0.3mm 2.5mm 0.3mm 0; text-align: right; }
  .recap th { color: <?= $e($accent) ?>; }
  .texts { font-size: 7.5pt; color: #333; margin-top: 2mm; }
  .totals { width: 100%; }
  .totals td { background: <?= $e($accent) ?>; color: #fff; padding: 1.2mm 2mm; text-align: right; border: 0.4mm solid #fff; font-size: 10.5pt; font-weight: 300; }
  .totals tr.sum td { font-weight: bold; font-size: 11.5pt; }
  .paid { text-align: right; margin-top: 1mm; font-weight: bold; color: <?= $e($accent) ?>; }
  .sign { width: 100%; margin-top: 12mm; page-break-inside: avoid; }
  .sign td { text-align: right; }
  .sign img { max-width: 62mm; max-height: 26mm; margin-top: 1mm; }
  .foot { position: fixed; left: 0; right: 0; bottom: -13mm; height: 11mm; border-top: 0.8mm solid <?= $e($accent) ?>; padding-top: 1.5mm; font-size: 7.8pt; }
  .foot table { width: 100%; }
  .foot img { max-height: 7mm; max-width: 24mm; }
  .foot .c { text-align: center; }
  .draft { position: fixed; top: 95mm; left: 0; right: 0; text-align: center; font-size: 70pt; color: #e05050; opacity: 0.18; transform: rotate(-25deg); font-weight: bold; }
  .tools { display: none; }
  @media screen {
    body { background: #e9ecef; }
    .tools { display: block; text-align: center; margin: 4mm; font-family: system-ui, sans-serif; }
    .tools a, .tools button { font: inherit; font-size: 10pt; padding: 1.5mm 4mm; margin: 0 1mm; cursor: pointer; }
    /* an A4 sheet with the PDF's margins; the footer at its bottom like in the PDF (6 mm from the edge) */
    .sheet { box-sizing: border-box; width: 210mm; min-height: 297mm; margin: 0 auto 8mm; background: #fff; padding: 10mm 12mm 22mm; position: relative; box-shadow: 0 2px 12px rgba(0,0,0,.15); }
    .foot { position: absolute; left: 12mm; right: 12mm; bottom: 6mm; height: auto; }
    .draft { position: absolute; }
  }
</style>
</head>
<body>
<?php if (!$forPdf): ?><div class="tools"><button onclick="window.print()">Tlačiť</button></div><?php endif ?>
<div class="sheet">
  <?php if ($draft): ?><div class="draft">KONCEPT</div><?php endif ?>

  <div class="headwrap"><table class="head"><tr>
    <td style="width: 38%"><?php if ($headLogo): ?><img src="<?= $e($headLogo) ?>" alt="<?= $e($shop['name'] ?? $supplier['name']) ?>"><?php else: ?><b style="color: <?= $e($accent) ?>; font-size: 13pt"><?= $e($shop['name'] ?? $supplier['name']) ?></b><?php endif ?></td>
    <td class="slogan" style="width: 28%"><?= $shop ? '' : $e($supplier['slogan'] ?? '') ?></td>
    <td class="contacts" style="width: 34%"><?= implode('<br>', array_map($e, $contacts)) ?></td>
  </tr></table></div>

  <table class="title"><tr>
    <td><?= $e($t($isCredit ? 'title_credit_note' : 'title_invoice')) ?></td>
    <td class="no"><?= $inv['number'] ? '#' . $e($inv['number']) : 'koncept' ?></td>
  </tr></table>
  <?php if ($isCredit && $original): ?>
    <p style="margin: 0 0 1.5mm"><?= $e($t('credit_note_reference', ['number' => $original['number'], 'date' => $d($original['issue_date']), 'reason' => $inv['correction_reason']])) ?></p>
  <?php endif ?>

  <table class="parties"><tr>
    <td style="width: 40%">
      <div class="bar">Dodávateľ</div>
      <div class="name"><?= $e($supplier['name']) ?></div>
      <?= $addr($supplier) ?>
      <?= $idRows(['IČO' => $supplier['ico'], 'DIČ' => $supplier['dic'], 'IČ DPH' => $payer ? $supplier['icdph'] : '']) ?>
      <?php if (!$payer && $t('non_vat_payer') !== ''): ?><?= $e($t('non_vat_payer')) ?><?php endif ?>
      <?php if (!empty($supplier['registration'])): ?><div class="small"><?= $e($supplier['registration']) ?></div><?php endif ?>
    </td>
    <td style="width: 20%"></td>
    <td style="width: 40%">
      <div class="bar">Odberateľ</div>
      <div class="name"><?= $e($inv['buyer_name']) ?></div>
      <?= $addr($inv, 'buyer_') ?>
      <?= $idRows(['IČO' => $inv['buyer_ico'], 'DIČ' => $inv['buyer_dic'], 'IČ DPH' => $inv['buyer_icdph'],
          'Tel.' => $inv['buyer_phone'] ?? '', 'E-mail' => $inv['buyer_email'] ?? '']) ?>
    </td>
  </tr></table>

  <table class="pay"><tr>
    <td style="width: 64%">
      <div class="bar">Platobné údaje</div>
      <table style="width: 100%"><tr>
        <td>
          <table class="inner">
            <?php if ($inv['iban'] || $inv['payment_method'] === 'transfer'): ?>
              <tr><td class="lbl">Názov účtu:</td><td><b><?= $e($inv['bank_account_name'] ?: $supplier['name']) ?></b></td></tr>
              <?php if ($account !== ''): ?><tr><td class="lbl">Číslo účtu:</td><td><b><?= $e($account) ?></b></td></tr><?php endif ?>
              <?php if ($bankName !== ''): ?><tr><td class="lbl">Banka:</td><td><?= $e($bankName) ?></td></tr><?php endif ?>
              <tr><td class="lbl">IBAN:</td><td style="white-space: nowrap"><b><?= $e(trim(chunk_split((string)$inv['iban'], 4, ' '))) ?></b></td></tr>
              <?php if ($inv['bic']): ?><tr><td class="lbl">SWIFT (BIC):</td><td><?= $e($inv['bic']) ?></td></tr><?php endif ?>
            <?php endif ?>
            <tr><td class="lbl">Variabilný symbol:</td><td><b><?= $e($inv['variable_symbol'] ?: '—') ?></b></td></tr>
            <tr><td class="lbl">Forma úhrady:</td><td><b><?= $e(Invoices::PAYMENT_METHODS[$inv['payment_method']] ?? $inv['payment_method']) ?></b></td></tr>
            <?php if ($inv['order_reference']): ?><tr><td class="lbl">Číslo objednávky:</td><td><?= $e($inv['order_reference']) ?></td></tr><?php endif ?>
          </table>
        </td>
        <?php if ($qr): ?><td class="qr" style="width: 29mm; padding-top: 1mm"><img src="<?= $e($qr) ?>" alt="PAY by square"><div class="cap">PAY by square</div></td><?php endif ?>
      </tr></table>
    </td>
    <td style="width: 4%"></td>
    <td style="width: 32%">
      <div class="bar">Dátum vystavenia:</div><div class="date"><?= $e($d($inv['issue_date'])) ?></div>
      <div class="bar">Dátum dodania:</div><div class="date"><?= $e($d($inv['delivery_date'])) ?></div>
      <div class="bar">Dátum splatnosti:</div><div class="date"><b><?= $e($d($inv['due_date'])) ?></b></div>
    </td>
  </tr></table>

  <div class="note"><?= $e(trim($t('prices_currency', ['currency' => $cur]) . ' ' . ($payer ? '' : $t('non_vat_payer')))) ?>
    <?php foreach ($catTexts as $txt): ?><br><b><?= $e($txt) ?></b><?php endforeach ?>
    <?php if ($inv['note']): ?><br><?= nl2br($e($inv['note'])) ?><?php endif ?></div>
  <table class="items">
    <thead><tr><th style="width: 5mm">#</th><th>Názov položky</th><th class="r">Počet MJ</th><th class="r">Cena/MJ</th><th class="c">DPH</th>
      <th class="r">Celkom</th><th class="r">DPH celk.</th><th class="r">Cena celkom s DPH</th></tr></thead>
    <tbody>
    <?php foreach ($inv['lines'] as $i => $l): ?>
      <tr><td><?= $i + 1 ?></td>
        <td><?= $e($l['name']) ?><?php if (($l['sku'] ?? '') !== ''): ?><div class="sku">Kód: <?= $e($l['sku']) ?></div><?php endif ?></td>
        <td class="r"><?= $e($qty($l['quantity'])) ?> <?= $e($l['unit']) ?></td>
        <td class="r"><?= $e($price($l['unit_price'])) ?></td>
        <td class="c"><?= !$payer ? 'N' : ($l['vat_category'] === 'S' ? $e($n($l['vat_rate'], 0)) . ' %' : $e($l['vat_category'])) ?></td>
        <td class="r"><?= $e($n($l['net'])) ?></td><td class="r"><?= $e($n($payer ? $l['vat'] : 0)) ?></td>
        <td class="r"><?= $e($n($payer ? $l['gross'] : $l['net'])) ?></td></tr>
    <?php endforeach ?>
    </tbody>
  </table>

  <table class="bottom"><tr>
    <td style="width: 52%">
      <?php if ($payer): ?>
        <table class="recap">
          <tr><th>Sadzba DPH</th><th>Základ</th><th>DPH</th></tr>
          <?php foreach ($inv['breakdown'] as $b): ?>
            <tr><td><?= $b['category'] === 'S' ? $e($n($b['rate'], 0)) . ' %' : $e($b['category']) ?></td><td><?= $e($m($b['net'])) ?></td><td><?= $e($m($b['vat'])) ?></td></tr>
          <?php endforeach ?>
        </table>
      <?php endif ?>
      <?php if ($inv['order_reference'] && !$isCredit): ?>
        <div class="texts">
          <?php if ($t('delivery_note') !== ''): ?><?= $e($t('delivery_note')) ?><br><?php endif ?>
          <?php if ($shop && $t('thanks_shop', ['shop' => (preg_replace('/^www\./', '', $domain($shop['web'])) ?: $shop['name'])]) !== ''): ?>
            <?= $e($t('thanks_shop', ['shop' => (preg_replace('/^www\./', '', $domain($shop['web'])) ?: $shop['name'])])) ?>
          <?php endif ?>
        </div>
      <?php endif ?>
    </td>
    <td style="width: 6%"></td>
    <td style="width: 42%">
      <table class="totals">
        <tr><td>Celková suma bez DPH:</td><td><?= $e($m($inv['total_net'])) ?></td></tr>
        <tr><td>DPH:</td><td><?= $e($m($payer ? $inv['total_vat'] : 0)) ?></td></tr>
        <tr class="sum"><td><?= (float)$inv['total'] < 0 ? 'Celkom na vrátenie:' : 'Celkom k úhrade:' ?></td><td><?= $e($m($inv['total'])) ?></td></tr>
      </table>
      <?php if (in_array($inv['payment_method'], ['card', 'cash'], true) && (float)$inv['total'] > 0 && $t('paid') !== ''): ?>
        <div class="paid"><?= $e($t('paid')) ?></div>
      <?php endif ?>
    </td>
  </tr></table>

  <table class="sign"><tr><td>
    <b>Vystavil:</b> <?= $e($inv['issued_by_name']) ?>
    <?php if ($stamp): ?><br><img src="<?= $e($stamp) ?>" alt=""><?php endif ?>
  </td></tr></table>

  <div class="foot"><table><tr>
    <td style="width: 22%"><?php if ($logo): ?><img src="<?= $e($logo) ?>" alt=""><?php endif ?></td>
    <td class="c" style="width: 56%"><?= $e($supplier['name'] . ', ' . $supplier['street'] . ', ' . trim($supplier['zip'] . ' ' . $supplier['city'])) ?><br><?= implode(' &nbsp;|&nbsp; ', array_map($e, $footContacts)) ?></td>
    <td style="width: 22%"></td>
  </tr></table></div>
</div>
</body>
</html>
