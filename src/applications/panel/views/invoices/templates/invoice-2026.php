<?php use Panel\Invoicing\Invoices; use Panel\Invoicing\Legislation;
/**
 * Print template "invoice-2026" — A4 invoice / credit note after the owner's design (docs/fakturacia-specifikacia.md ch. 4),
 * compact layout (2026-11-19: dates in one box, IDs on one line, "Vystavil" + stamp beside the totals).
 * The same HTML is shown in the browser and rendered to PDF by dompdf → only tables, floats and position:fixed (no grid/flex).
 * DO NOT EDIT once invoices were issued with it in production: copy to a new file and switch Templates::CURRENT.
 * Legal wording comes from $legal (Legislation texts valid on the issue date, frozen in invoice.legal_json when issued).
 * @var array $inv, $supplier, $legal; array|null $original; string $title, $logo, $stamp, $qr (data URIs or ''),
 *      $qrError, $fontBase; bool $forPdf
 */
$cur = $inv['currency'];
$payer = (bool)$inv['vat_payer'];
$accent = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($supplier['accent_color'] ?? '')) ? $supplier['accent_color'] : '#f74040';
$m = function ($v) use ($f, $cur) { return $f->money($v, $cur); };
$n = function ($v, int $dec = 2) use ($f) { return $f->number($v, $dec); };
$qty = function ($v) { $s = number_format((float)$v, 3, ',', ' '); return rtrim(rtrim($s, '0'), ','); };
// unit price: all its decimals (up to 4), at least 2 — so quantity × price matches the line total
$price = function ($v) use ($n) { $s = $n($v, 4); return preg_replace('/(,\d\d\d*?)0+$/', '$1', $s); };
$isCredit = $inv['doc_type'] === 'credit_note';
$draft = $inv['status'] === 'draft';
$t = function (string $code, array $vars = []) use ($legal) { return Legislation::fill((string)($legal[$code] ?? ''), $vars); };
$catTexts = [];
foreach ($inv['lines'] as $l) {
    if ($payer && ($l['vat_category'] ?? 'S') !== 'S' && $t('vat_' . $l['vat_category']) !== '') {
        $catTexts[$l['vat_category']] = $t('vat_' . $l['vat_category']);
    }
}
// address: street / zip city / country (country only when not Slovakia)
$addr = function (array $p, string $pre = '') use ($e) {
    $c = strtoupper((string)($p[$pre . 'country'] ?? 'SK'));
    $names = ['CZ' => 'Česko', 'AT' => 'Rakúsko', 'HU' => 'Maďarsko', 'PL' => 'Poľsko', 'DE' => 'Nemecko'];
    return $e($p[$pre . 'street']) . '<br>' . $e(trim($p[$pre . 'zip'] . ' ' . $p[$pre . 'city']))
        . ($c !== 'SK' && $c !== '' ? ', ' . $e($names[$c] ?? $c) : '');
};
$ids = function (array $pairs) use ($e) {
    $out = [];
    foreach ($pairs as $label => $v) {
        if ((string)$v !== '') {
            $out[] = '<span class="lbl">' . $e($label) . ':</span> ' . $e($v);
        }
    }
    return implode(' &nbsp; ', $out);
};
$contacts = array_filter([$supplier['web'] ?? '', $supplier['email'] ?? '', $supplier['phone'] ?? '']);
$font = function (string $file) use ($fontBase, $e) { return $e(rtrim($fontBase, '/') . '/' . $file); };
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
  body { margin: 0; color: #222; font-family: 'Poppins', 'DejaVu Sans', sans-serif; font-size: 8.5pt; line-height: 1.3; }
  table { border-collapse: collapse; }
  td, th { vertical-align: top; padding: 0; }
  b, th, .name { font-weight: bold; }
  .a { color: <?= $e($accent) ?>; }
  .bar { background: <?= $e($accent) ?>; color: #fff; font-weight: bold; text-align: center; padding: 0.4mm 2mm; }
  .lbl { color: #555; }
  .small { font-size: 7pt; color: #555; }
  .headwrap { border-bottom: 1.2mm solid <?= $e($accent) ?>; padding-bottom: 2mm; }
  .head { width: 100%; }
  .head td { vertical-align: middle; }
  .head img { max-height: 13mm; max-width: 55mm; }
  .head .slogan { text-align: center; font-size: 10pt; color: <?= $e($accent) ?>; }
  .head .contacts { text-align: right; font-size: 8.5pt; color: <?= $e($accent) ?>; line-height: 1.25; }
  .title { width: 100%; margin: 2.5mm 0 1.5mm; }
  .title td { font-size: 17pt; font-weight: 300; color: <?= $e($accent) ?>; line-height: 1.1; }
  .title .no { text-align: right; }
  .parties { width: 100%; }
  .parties .name { color: <?= $e($accent) ?>; margin-top: 1mm; }
  .pay { width: 100%; margin-top: 3mm; }
  .pay .inner td { padding: 0.15mm 2mm 0.15mm 0; }
  .pay .inner td.lbl, .dates td { white-space: nowrap; }
  .qr { width: 30mm; text-align: center; }
  .qr img { width: 30mm; height: 30mm; display: block; }
  .qr .cap { font-size: 6.5pt; font-weight: bold; letter-spacing: 0.3pt; border: 0.3mm solid #222; border-top: 0; padding: 0.2mm 0; }
  .dates td { padding: 0.4mm 1mm; border-bottom: 0.2mm solid #ddd; }
  .dates .v { text-align: right; }
  .note { font-style: italic; margin: 2.5mm 0 1mm; }
  .items { width: 100%; }
  .items th { background: <?= $e($accent) ?>; color: #fff; text-align: left; padding: 0.6mm 1.2mm; }
  .items td { border: 0.2mm solid <?= $e($accent) ?>; padding: 0.4mm 1.2mm; }
  .items .r { text-align: right; white-space: nowrap; }
  .bottom { width: 100%; margin-top: 3mm; page-break-inside: avoid; }
  .recap th, .recap td { padding: 0.3mm 2.5mm 0.3mm 0; text-align: right; }
  .recap th { color: <?= $e($accent) ?>; }
  .sign img { max-width: 55mm; max-height: 24mm; margin-top: 1mm; }
  .totals { width: 100%; }
  .totals td { background: <?= $e($accent) ?>; color: #fff; padding: 1mm 2mm; text-align: right; border: 0.4mm solid #fff; font-size: 10pt; font-weight: 300; }
  .totals tr.sum td { font-weight: bold; font-size: 11.5pt; }
  .paid { text-align: right; margin-top: 1mm; font-weight: bold; color: <?= $e($accent) ?>; }
  .foot { position: fixed; left: 0; right: 0; bottom: -13mm; height: 11mm; border-top: 0.8mm solid <?= $e($accent) ?>; padding-top: 1.5mm; font-size: 7.5pt; }
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
    <td style="width: 38%"><?php if ($logo): ?><img src="<?= $e($logo) ?>" alt="<?= $e($supplier['name']) ?>"><?php else: ?><b class="a"><?= $e($supplier['name']) ?></b><?php endif ?></td>
    <td class="slogan" style="width: 28%"><?= $e($supplier['slogan'] ?? '') ?></td>
    <td class="contacts" style="width: 34%"><?= implode('<br>', array_map($e, $contacts)) ?></td>
  </tr></table></div>

  <table class="title"><tr>
    <td><?= $e($t($isCredit ? 'title_credit_note' : 'title_invoice')) ?></td>
    <td class="no"><?= $inv['number'] ? '#' . $e($inv['number']) : 'koncept' ?></td>
  </tr></table>
  <?php if ($isCredit && $original): ?>
    <p style="margin: 0 0 1.5mm"><?= $e($t('credit_note_reference', ['number' => $original['number'], 'date' => $f->date($original['issue_date']), 'reason' => $inv['correction_reason']])) ?></p>
  <?php endif ?>

  <table class="parties"><tr>
    <td style="width: 48%">
      <div class="bar">Dodávateľ</div>
      <div class="name"><?= $e($supplier['name']) ?></div>
      <?= $addr($supplier) ?><br>
      <?= $ids(['IČO' => $supplier['ico'], 'DIČ' => $supplier['dic'], 'IČ DPH' => $payer ? $supplier['icdph'] : '']) ?>
      <?php if (!$payer && $t('non_vat_payer') !== ''): ?><br><?= $e($t('non_vat_payer')) ?><?php endif ?>
      <?php if (!empty($supplier['registration'])): ?><div class="small"><?= $e($supplier['registration']) ?></div><?php endif ?>
    </td>
    <td style="width: 4%"></td>
    <td style="width: 48%">
      <div class="bar">Odberateľ</div>
      <div class="name"><?= $e($inv['buyer_name']) ?></div>
      <?= $addr($inv, 'buyer_') ?>
      <?php $bIds = $ids(['IČO' => $inv['buyer_ico'], 'DIČ' => $inv['buyer_dic'], 'IČ DPH' => $inv['buyer_icdph']]); ?>
      <?php if ($bIds !== ''): ?><br><?= $bIds ?><?php endif ?>
    </td>
  </tr></table>

  <table class="pay"><tr>
    <td style="width: <?= $qr ? '48%' : '68%' ?>">
      <div class="bar">Platobné údaje</div>
      <table class="inner">
        <?php if ($inv['iban'] || $inv['payment_method'] === 'transfer'): ?>
          <tr><td class="lbl">Názov účtu:</td><td><b><?= $e($inv['bank_account_name'] ?: $supplier['name']) ?></b></td></tr>
          <tr><td class="lbl">IBAN:</td><td style="white-space: nowrap"><b><?= $e(trim(chunk_split((string)$inv['iban'], 4, ' '))) ?></b></td></tr>
          <?php if ($inv['bic']): ?><tr><td class="lbl">BIC (SWIFT):</td><td><?= $e($inv['bic']) ?></td></tr><?php endif ?>
        <?php endif ?>
        <tr><td class="lbl">Variabilný symbol:</td><td><b><?= $e($inv['variable_symbol'] ?: '—') ?></b></td></tr>
        <tr><td class="lbl">Forma úhrady:</td><td><b><?= $e(Invoices::PAYMENT_METHODS[$inv['payment_method']] ?? $inv['payment_method']) ?></b></td></tr>
        <?php if ($inv['order_reference']): ?><tr><td class="lbl">Objednávka:</td><td><?= $e($inv['order_reference']) ?></td></tr><?php endif ?>
      </table>
    </td>
    <?php if ($qr): ?>
      <td style="width: 4%"></td>
      <td class="qr"><img src="<?= $e($qr) ?>" alt="PAY by square"><div class="cap">PAY by square</div></td>
    <?php endif ?>
    <td style="width: 3%"></td>
    <td style="width: 26%">
      <table class="dates" style="width: 100%">
        <tr><td colspan="2" class="bar">Dátumy</td></tr>
        <tr><td class="lbl">Vystavenia:</td><td class="v"><?= $e($f->date($inv['issue_date'])) ?></td></tr>
        <tr><td class="lbl">Dodania:</td><td class="v"><?= $e($f->date($inv['delivery_date'])) ?></td></tr>
        <tr><td class="lbl">Splatnosti:</td><td class="v"><b><?= $e($f->date($inv['due_date'])) ?></b></td></tr>
      </table>
    </td>
  </tr></table>

  <div class="note"><?= $e(trim($t('prices_currency', ['currency' => $cur]) . ' ' . ($payer ? '' : $t('non_vat_payer')))) ?>
    <?php foreach ($catTexts as $txt): ?><br><b><?= $e($txt) ?></b><?php endforeach ?>
    <?php if ($inv['note']): ?><br><?= nl2br($e($inv['note'])) ?><?php endif ?></div>
  <table class="items">
    <thead><tr><th style="width: 5mm">#</th><th>Názov položky</th><th class="r">Počet MJ</th><th class="r">Cena/MJ</th>
      <?php if ($payer): ?><th class="r">DPH</th><th class="r">Celkom</th><th class="r">DPH celk.</th><th class="r">S DPH</th><?php else: ?><th class="r">Celkom</th><?php endif ?></tr></thead>
    <tbody>
    <?php foreach ($inv['lines'] as $i => $l): ?>
      <tr><td><?= $i + 1 ?></td><td><?= $e($l['name']) ?></td>
        <td class="r"><?= $e($qty($l['quantity'])) ?> <?= $e($l['unit']) ?></td>
        <td class="r"><?= $e($price($l['unit_price'])) ?></td>
        <?php if ($payer): ?>
          <td class="r"><?= $l['vat_category'] === 'S' ? $e($n($l['vat_rate'], 0)) . ' %' : $e($l['vat_category']) ?></td>
          <td class="r"><?= $e($n($l['net'])) ?></td><td class="r"><?= $e($n($l['vat'])) ?></td><td class="r"><?= $e($n($l['gross'])) ?></td>
        <?php else: ?>
          <td class="r"><?= $e($n($l['net'])) ?></td>
        <?php endif ?></tr>
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
      <div class="sign" style="margin-top: <?= $payer ? '2mm' : '0' ?>">
        <b>Vystavil:</b> <?= $e($inv['issued_by_name']) ?>
        <?php if ($stamp): ?><br><img src="<?= $e($stamp) ?>" alt=""><?php endif ?>
      </div>
    </td>
    <td style="width: 6%"></td>
    <td style="width: 42%">
      <table class="totals">
        <?php if ($payer): ?>
          <tr><td>Celková suma bez DPH:</td><td><?= $e($m($inv['total_net'])) ?></td></tr>
          <tr><td>DPH:</td><td><?= $e($m($inv['total_vat'])) ?></td></tr>
        <?php endif ?>
        <tr class="sum"><td><?= (float)$inv['total'] < 0 ? 'Celkom na vrátenie:' : 'Celkom k úhrade:' ?></td><td><?= $e($m($inv['total'])) ?></td></tr>
      </table>
      <?php if (in_array($inv['payment_method'], ['card', 'cash'], true) && (float)$inv['total'] > 0 && $t('paid') !== ''): ?>
        <div class="paid"><?= $e($t('paid')) ?></div>
      <?php endif ?>
    </td>
  </tr></table>

  <div class="foot"><table><tr>
    <td style="width: 22%"><?php if ($logo): ?><img src="<?= $e($logo) ?>" alt=""><?php endif ?></td>
    <td class="c" style="width: 56%"><?= $e($supplier['name'] . ', ' . $supplier['street'] . ', ' . trim($supplier['zip'] . ' ' . $supplier['city'])) ?><br><?= implode(' &nbsp;|&nbsp; ', array_map($e, $contacts)) ?></td>
    <td style="width: 22%"></td>
  </tr></table></div>
</div>
</body>
</html>
