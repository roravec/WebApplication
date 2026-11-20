# Konfigurácia Štatistík

## Aktuálne Nastavenie (Sezóna 2025/2026)

Aplikácia zobrazuje štatistiky pre dve časti sezóny:

- **Základná časť**: Season ID = 12
- **Play-off**: Season ID = 13

### Sezóny sú Definované v poli `$Seasons` v WebApp.php

```php
private static $Seasons = array(
    12 => array('name' => 'Základná časť 2025/2026', 'type' => 'base', 'label' => 'Základná časť'),
    13 => array('name' => 'Play-off 2025/2026', 'type' => 'playoff', 'label' => 'Play-off'),
    // Budúca sezóna - odkomentuj keď bude dostupná
    // 14 => array('name' => 'Základná časť 2026/2027', 'type' => 'base', 'label' => 'Základná časť'),
    // 15 => array('name' => 'Play-off 2026/2027', 'type' => 'playoff', 'label' => 'Play-off'),
);
```

## Ako Pridať Novú Sezónu

### Kroky:

1. Otvor súbor `WebApp.php`
2. Nájdi pole `$Seasons` (okolo riadku 35-43)
3. Pridaj nový riadok:

```php
14 => array('name' => 'Základná časť 2026/2027', 'type' => 'base', 'label' => 'Základná časť'),
```

**Príklad - Pridanie 3 sezón:**
```php
private static $Seasons = array(
    12 => array('name' => 'Základná časť 2025/2026', 'type' => 'base', 'label' => 'Základná časť'),
    13 => array('name' => 'Play-off 2025/2026', 'type' => 'playoff', 'label' => 'Play-off'),
    14 => array('name' => 'Základná časť 2026/2027', 'type' => 'base', 'label' => 'Základná časť'),
    15 => array('name' => 'Play-off 2026/2027', 'type' => 'playoff', 'label' => 'Play-off'),
    16 => array('name' => 'Základná časť 2027/2028', 'type' => 'base', 'label' => 'Základná časť'),
    17 => array('name' => 'Play-off 2027/2028', 'type' => 'playoff', 'label' => 'Play-off'),
);
```

## Globálny Selector Sezón

Môžeš umiestnť globálny selector sezón na akúkoľvek stránku. Máš 3 možnosti:

### 1. Tlačidlá (Default)
```php
<?php echo $this->app->CreateSeasonSelector('buttons'); ?>
```
Výsledok: Farebné tlačidlá s aktívnou sezónou v zelenej

### 2. Select Box (Dropdown)
```php
<?php echo $this->app->CreateSeasonSelector('select'); ?>
```
Výsledok: HTML `<select>` element

### 3. Minimálny Formát
```php
<?php echo $this->app->CreateSeasonSelector('minimal'); ?>
```
Výsledok: Malé linky s oddeľovačmi

## Ako Funguje Aplikácia

### Stránky s Prekladom:
- `/statistiky` - Štatistiky hráčov
- `/tabulka` - Tabuľka tímov

### URL Parametre:
- `?season=12` - Základná časť 2025/2026
- `?season=13` - Play-off 2025/2026
- `?season=14` - Základná časť 2026/2027 (keď sa pridá)

### Backend Fungovanie:
- Aplikácia **naladí všetky sezóny** naraz do keša
- Vracia iba **aktuálnu sezónu** podľa parametra
- Bez JavaScriptu - čisté PHP a HTML linky

## Zobrazovanie Historických Štatistík

Ak máš staré sezóny v poli, budú sa automaticky zobrazovať ako záložky. 

**Príklad:**
- Ak máš ID 12, 13 a 14, 15, budú sa zobrazovať 4 tlačidlá
- Používateľ si môže vybrať ľubovoľnú sezónu z histórie

## Caching Štatistík

Aplikácia cachuje dáta pre **všetky sezóny** naraz pri prvom naloadovaní:

```php
// Naladí sa automaticky:
// - ID 12: GetPlayerStats(12)
// - ID 13: GetPlayerStats(13)
// - atď.
```

Takže preklikávanie medzi sezónami je **rýchle** - bez dodatočných API volaní.

## Technické Detaily

### Premenné sú Dynamicky Cachované:
```php
// Interné cachovanie:
// $PlayerStats_12, $PlayerStats_13, etc.
// $TeamStats_12, $TeamStats_13, etc.
```

### Metódy:
- `GetCurrentSeasonId()` - Vrátí aktuálne vybrané ID
- `GetCurrentSeasonType()` - Vrátí typ ('base' alebo 'playoff')
- `GetCurrentSeasonLabel()` - Vrátí label ("Základná časť" alebo "Play-off")
- `CreateSeasonSelector($type)` - Vygeneruje HTML selector

Všetko je **automatické** - pri pridaní novej sezóny do poľa sa všetko ostatné funguje samo!

