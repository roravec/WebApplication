<?php /** @var array $r resolved record; string $url short link; string $qr data URI — printable label, no layout */ ?>
<!doctype html>
<html lang="sk">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Štítok — <?= $e($r['label'] . ' ' . $r['title']) ?></title>
  <style>
    body { font-family: Arial, sans-serif; margin: 1rem; color: #000; background: #fff; }
    .label { display: inline-flex; gap: 4mm; align-items: center; border: 1px dashed #999; padding: 3mm; width: 90mm; box-sizing: border-box; }
    .label img { width: 28mm; height: 28mm; image-rendering: pixelated; }
    .label b { display: block; font-size: 12pt; }
    .label small { font-size: 7pt; word-break: break-all; }
    .hint { color: #555; font-size: 10pt; margin-bottom: 1rem; }
    @media print { .hint { display: none; } .label { border: 0; } body { margin: 0; } }
  </style>
</head>
<body>
  <p class="hint">Vytlačte (Ctrl+P) na tlačiarni štítkov alebo na papier a nalepte. QR kód otvorí tento záznam v paneli —
    po prihlásení a len tomu, kto k nemu má prístup. <button onclick="window.print()">Tlačiť</button></p>
  <div class="label">
    <img src="<?= $e($qr) ?>" alt="QR kód">
    <div><small><?= $e($r['label']) ?></small><b><?= $e($r['title']) ?></b><small><?= $e($url) ?></small></div>
  </div>
</body>
</html>
