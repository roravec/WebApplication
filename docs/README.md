# ERMA webové služby — rozcestník

Tento repozitár (WebApplication) je **centrum pre všetky webové služby ERMA** (majiteľ 20. 11. 2026): tu je prehľad,
plán nasadenia a údržby a odkazy na podrobnosti v ostatných repozitároch. Pri každej novej službe alebo zmene na
hostingu sa doplní táto stránka.

## Služby

| Služba | Adresa | Kód | Dokumentácia | Stav |
|---|---|---|---|---|
| Dočasná stránka ERMA | erma.sk | `C:\Users\orras\git\Web_ERMA\app` (vlastný repozitár — zatiaľ nie je v gite) | — | hotová, čaká na nasadenie (A) |
| **Zamestnanecká sekcia (panel)**: objednávky všetkých e-shopov, fakturácia, sklad, balík pre účtovníka | erma.sk/panel | `src/applications/panel` | `fakturacia-specifikacia.md`, `sklad-specifikacia.md`, `omega-export.md`, `legislativa.md` | hotová na dev, čaká na nasadenie (A) |
| Klub HBK Pravotice + StadiumControl (administrácia zápasov, časomiera, JSON API) | hbkpravotice.sk | `StadiumControl\WebServer\apps\hbkpravotice` (+ statické stránky v koreni `WebServer\webapp\*.html`) | jeho `README.md`, `../HANDOVER.md` bod 22 | prenesené, čaká na nasadenie (A) |
| Bánovecká hokejbalová liga | bnhl.sk | `StadiumControl\WebServer\apps\bnhl` | `../HANDOVER.md` bod 22 | prenesené, čaká na nasadenie (A) |
| **E-shopy** Howies Hockey, AmerickeSPZ, CarPlates, ERMA (PrestaShop multistore) | howieshockey.sk, americkespz.sk, carplates.shop, erma.sk/store | kód: `~/webstore/shop.git` na domácom serveri (klon `C:\Users\orras\git\shop`); vlastné moduly, nástroje: `C:\Users\orras\git\Web_store` | Web_store `docs/upgrade-plan.md`, `module-replacements.md`, `pravne-nalezitosti.md`, `angartheme.md`, `HANDOVER.md` | beží 1.7.6.7; 8.2.9 nacvičené (C) |
| Zavlažovanie (web) | aqua12.erma.sk (overiť) | `C:\Users\orras\git\Web_AquaErma` | jeho README | mimo tohto plánu — overiť, kde beží |
| Zariadenia ESP32 (LED tabuľa, zavlažovanie) | `/fw` (firmvér), `/api/device_sync.php` — bez https | firmvér: `SW_LedControlBoard`, `SW_WaterIrrigationController_esp32`; `device_sync.php` je len na FTP | LedBoard: `WEB_LedBoard`, `Prj_LedBoard` | beží, nemeniť |
| Staré nástroje na hostingu | `admin/`, `sklad/`, `beta/`, `psc/`, `albums/` | len na FTP | — | používajú `molesk`; rozhodnúť: preniesť alebo zrušiť |
| Staré jadro (dnes obsluhuje erma.sk, hbkpravotice.sk, bnhl.sk) | koreňový `index.php`, `core/`, `WebApp_*` | z repozitára StadiumControl odstránené 20. 11. 2026 (je v histórii gitu); na hostingu do fázy D | — | nahradí ho fáza A, zmaže sa vo fáze D |

**Kde je kód:** framework, panel, rozcestník, nástroje na nasadenie = tento repozitár. Každá ďalšia webová aplikácia
má vlastný repozitár; `../apps.json` hovorí, kde ktorá je, a `python dev/apps.py sync` (vývojový server) /
`python dev/apps.py package NAZOV` (balík na nasadenie) ich poskladá do `applications/<meno>`. Na hostingu je to
rovnako ako predtým — všetko v `applications/`.

## Hosting a databázy

- Zdieľaný hosting, len FTP, nginx pred Apache, PHP 7.3 (kvôli e-shopu). Jeden FTP priečinok = koreň webu všetkých
  domén. Koreňový `.htaccess` je spoločný — nikdy ho neprepisovať. Lokálna snímka FTP: `C:\Users\orras\git\ftp`
  (štruktúra spoľahlivá, obsah starý — nie je to záloha).
- **`ermaskretail` = ostrá databáza**, **`molesk` = testovacia** (najprv sa nasadzuje na ňu, potom sa preklopí) —
  podrobne `nasadenie-a-udrzba.md`, „Databázy“.
- Heslá sú len v `config/config.php` frameworku a `app/config/parameters.php` e-shopu na hostingu, nikdy v git.

## Plán a údržba

- **Poradie nasadenia, zálohy, preklopenie databáz, údržba: `nasadenie-a-udrzba.md`.**
- Aktuálny stav práce a ďalšie úlohy: `../HANDOVER.md` (framework, panel, weby), Web_store `HANDOVER.md` (e-shopy).
- Čo čaká na majiteľa: Web_store `TODO-majitel.md`.
- Pravidlá pre prácu (aj pre Clauda): `../CLAUDE.md`, Web_store `CLAUDE.md`.

## Vývojové prostredie (domáci server 192.168.0.11, len v LAN)

| Čo | Kde |
|---|---|
| framework, panel, weby | http://192.168.0.11:8090/ (`/panel`, login admin/admin), hbkpravotice / bnhl: `http://hbkpravotice.sk.192-168-0-11.sslip.io:8090/`, `http://bnhl.sk.192-168-0-11.sslip.io:8090/`; testy `dev/` (PHP 7.3 aj 8.4) |
| e-shopy — kópie | `~/webstore/devN`: dev1 = dnešný ostrý stav (1.7.6, :8081), dev5 = 8.2.9 (:8085), dev4 = 9.2.1 (:8084); dev2, dev3 = staršie skúšky (vypnuté) |
| e-maily z kópií | Mailpit http://192.168.0.11:8025 (nič neodíde von) |
| zálohy z hostingu | `~/webstore/backups/` |

Súbor `hosts` sa nikdy needituje — kópie majú adresy cez sslip.io.
