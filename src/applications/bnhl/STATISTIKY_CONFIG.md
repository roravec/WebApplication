# Konfigurácia Štatistík - Základná Časť a Play-off

## Aktuálna Konfigurácia (2025/2026 Sezóna)

V súbore `WebApp.php` sú definované nasledujúce ID sezón:

```php
public static $StatsSeasonId = 12;      // Základná časť
public static $PlayoffSeasonId = 13;    // Play-off
```

## Ako Funguje

### Štatistiky Hráčov (`/statistiky`)
- Stránka zobrazuje dve záložky: **Základná časť** a **Play-off**
- Každá záložka obsahuje štatistiky pre danú fázu sezóny
- ID=12 je základná časť, ID=13 je playoff

### Tabuľka Tímov (`/tabulka`)
- Tiež zobrazuje dve záložky s tabuľkou pre obe fázy
- Tímy sú automaticky zoradené podľa bodov

### Úvodná Stránka (`/prehlad`)
- Zobrazuje malé tabuľky pre obe fázy vedľa seba
- Ak niektorá fáza nemá dostupné štatistiky, zobrazí sa len dostupná

## Príprava na Budúcu Sezónu

### Scenár 1: Úplne Nová Sezóna (Napr. 2026/2027)

Ak chcete začať s novou sezónou a skryť/archivovať starú:

1. **V súbore `WebApp.php` zmeňte ID:**

```php
// STARÁ SEZÓNA (2025/2026) - ARCHÍV
private static $StatsSeasonId_Archive = 12;      // Základná časť
private static $PlayoffSeasonId_Archive = 13;    // Play-off

// NOVÁ SEZÓNA (2026/2027) - AKTUÁLNA
public static $StatsSeasonId = 14;      // Základná časť - NOVÉ ID
public static $PlayoffSeasonId = 15;    // Play-off - NOVÉ ID
```

2. **Vytvorte novú stránku pre archívne štatistiky**

Môžete vytvoriť novú page `archiv-statistiky`:

```php
// V BuildPageContent v WebApp.php pridajte:
case "archiv-statistiky": return $this->BuildPage_ArchiveStats();

// A nová metóda:
public function BuildPage_ArchiveStats()
{
    $body = new Module_Body();
    $body->fullPage = false;
    $body->content = $this->CreateArchiveStats();
    return $body->GetHTML();
}
```

3. **Vytvorte metódu na načítavanie archívnych štatistík**

```php
public function GetPlayerStatsArchiveArr()
{
    $archive = array();
    // Načítajte ID=12 (stará sezóna)
    $json = StadiumControlApi::GetPlayerStats(12);
    // Parsujte a vraťte
    return $archive;
}
```

### Scenár 2: Paralelný Beh Sezón

Ak chcete zobraziť štatistiky z viacerých sezón súčasne:

1. **Rozšírte metódy na vrátenie viacerých sezón:**

```php
public function GetAllPlayersStatsWithSeason()
{
    $allStats = array();
    
    // Aktuálna sezóna
    $stats2025 = StadiumControlApi::GetPlayerStats(12);
    $allStats['2025-základná'] = $stats2025;
    
    $stats2025playoff = StadiumControlApi::GetPlayerStats(13);
    $allStats['2025-playoff'] = $stats2025playoff;
    
    // Historické štatistiky
    $stats2024 = StadiumControlApi::GetPlayerStats(10); // Ak existujú
    $allStats['2024-základná'] = $stats2024;
    
    return $allStats;
}
```

2. **Vytvorte nový Tab modul pre viacero sezón:**

Vytvorte `StatisticsMultiYear` modul, ktorý bude zobrazovať tab pre každú sezónu.

## Dátové Vzory (Sample IDs)

```
ID=10: 2023/2024 - Základná časť
ID=11: 2023/2024 - Play-off
ID=12: 2024/2025 - Základná časť (AKTUÁLNA)
ID=13: 2024/2025 - Play-off (AKTUÁLNA)
ID=14: 2025/2026 - Základná časť (BUDÚCA)
ID=15: 2025/2026 - Play-off (BUDÚCA)
```

## Zmena pre Ďalší Rok

**Krok 1**: V `WebApp.php` aktualizujte:
```php
public static $StatsSeasonId = 14;      // Nový ID pre budúcu sezónu
public static $PlayoffSeasonId = 15;    // Nový ID pre playoff budúcej sezóny
```

**Krok 2**: Reštartujte aplikáciu

**Krok 3**: Staré štatistiky sú peštrene dostupné na archívnej stránke (ak ste ju vytvorili)

## Metadata Štatistík

Môžete si všimnúť, že Module_Stats a Module_TeamsTable majú teraz vlastnosť `SeasonLabel`:

```php
$stats->SeasonLabel = "Základná časť";  // Zobrazí sa: "Štatistiky hráčov - Základná časť"
$table->SeasonLabel = "Play-off";       // Zobrazí sa: "Tabuľka - Play-off"
```

To umožňuje jasné rozlíšenie medzi fázami sezóny.

## URL Prístup

- Štatistiky: `https://yourdomain.com/statistiky`
- Tabuľka: `https://yourdomain.com/tabulka`
- Úvodná stránka: `https://yourdomain.com/prehlad`

## FAQ

**Otázka**: Ako sa zobrazia staré štatistiky?
**Odpoveď**: Vytvorte nový endpoint v `BuildPageContent()` a staré ID priamo v metóde.

**Otázka**: Môžem mať viac ako 2 fázy v sezóne?
**Odpoveď**: Áno. Pridajte viac statických premenných a métod, podobne ako `$PlayoffSeasonId`.

**Otázka**: Ako sa tie štatistiky načítavajú z API?
**Odpoveď**: V `StadiumControlApi.php` metóda `GetPlayerStats($seasonId)` a `GetTeamsStats($seasonId)` robí API volania s ID.
