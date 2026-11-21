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
| **E-shopy** Howies Hockey, AmerickeSPZ, CarPlates, ERMA (PrestaShop multistore) | howieshockey.sk, americkespz.sk, carplates.shop, erma.sk/store | kód: `~/webstore/shop.git` na domácom serveri (klon `C:\Users\orras\git\shop`); vlastné moduly, nástroje: `C:\Users\orras\git\Web_store` | Web_store `docs/upgrade-plan.md`, `module-replacements.md`, `pravne-nalezitosti.md`, `angartheme.md`, `HANDOVER.md` | beží **PrestaShop 9.2.0** (od 20. 11. 2026, PHP 8.5, `ermaskretail`) |
| Zavlažovanie (web + API pre ESP32 + mobilná appka) | aqua.erma.sk (vlastný priečinok na hostingu, nie v koreni FTP; `aqua12.erma.sk` v `.htaccess` je pozostatok) | `C:\Users\orras\git\Web_AquaErma` (`WaterIrrigationWeb/`, `api/device_sync.php`; `aquaerma_app/`) | jeho README, `WaterIrrigationWeb/DEVICE_COMMUNICATION.md` | beží (tabuľky `aqua_*` v `ermaskretail`), mimo tohto plánu |
| LCS hub pre staré telefóny (Sony Ericsson) | mobileservice.erma.sk (vlastný priečinok, PHP 8) + agent/messenger na domácom serveri (`~/lcs-*`) | `C:\Users\orras\git\DB2020_revival` | jeho README, CLAUDE.md | beží (tabuľky `lcs_*` v `ermaskretail`), mimo tohto plánu |
| branchmanager — školský projekt (majiteľ 20. 11. 2026) | — | — | — | **nepoužíva sa**; zostali tabuľky `branchmanager_*` v `ermaskretail` + `branchmanager_users` v `molesk` — dajú sa zmazať pri upratovaní (fáza D), sú v zálohe z 20. 11. |
| Zariadenia ESP32 (LED tabuľa, zavlažovanie) | `/fw` v koreni (firmvér, bez https); zavlažovanie komunikuje s aqua.erma.sk | firmvér: `SW_LedControlBoard`, `SW_WaterIrrigationController_esp32` | LedBoard: `WEB_LedBoard`, `Prj_LedBoard` | beží, nemeniť |
| Staré nástroje na hostingu | `admin/`, `sklad/`, `beta/`, `psc/`, `albums/` | len na FTP | — | používajú `molesk`; rozhodnúť: preniesť alebo zrušiť |
| Staré jadro (dnes obsluhuje erma.sk, hbkpravotice.sk, bnhl.sk) | koreňový `index.php`, `core/`, `WebApp_*` | z repozitára StadiumControl odstránené 20. 11. 2026 (je v histórii gitu); na hostingu do fázy D | — | nahradí ho fáza A, zmaže sa vo fáze D |

**Kde je kód:** framework, panel, rozcestník, nástroje na nasadenie = tento repozitár. Každá ďalšia webová aplikácia
má vlastný repozitár; `../apps.json` hovorí, kde ktorá je, a `python dev/apps.py sync` (vývojový server) /
`python dev/apps.py package NAZOV` (balík na nasadenie) ich poskladá do `applications/<meno>`. Na hostingu je to
rovnako ako predtým — všetko v `applications/`.

## Hosting a databázy

- Zdieľaný hosting, len FTP, nginx pred Apache, PHP 8.5 (od 20. 11. 2026, PrestaShop 9). Jeden FTP priečinok = koreň webu všetkých
  domén. Koreňový `.htaccess` je spoločný — nikdy ho neprepisovať. Lokálna snímka FTP: `C:\Users\orras\git\ftp`
  (štruktúra spoľahlivá, obsah starý — nie je to záloha).
- **`ermaskretail` = ostrá databáza**, **`molesk` = testovacia** (najprv sa nasadzuje na ňu, potom sa preklopí) —
  podrobne `nasadenie-a-udrzba.md`, „Databázy“. `ermaskretail` už používajú aj aqua a LCS (+ staré tabuľky školského projektu branchmanager) → do nej sa
  len pridávajú tabuľky s vlastným prefixom, nikdy sa celá neprepisuje.
- Subdomény `aqua.erma.sk` a `mobileservice.erma.sk` majú na hostingu vlastné priečinky (nie sú v koreni FTP).
- Heslá sú len v `config/config.php` frameworku a `app/config/parameters.php` e-shopu na hostingu, nikdy v git.

## Plán a údržba

- **Poradie nasadenia, zálohy, preklopenie databáz, údržba: `nasadenie-a-udrzba.md`.**
- Aktuálny stav práce a ďalšie úlohy: `../HANDOVER.md` (framework, panel, weby), Web_store `HANDOVER.md` (e-shopy).
- Panel — štruktúra, budúce moduly (úlohy, projekty podľa V-modelu, dokumenty, API) a práva: `panel-architektura.md`.
- Návody pre používateľov panela (Pomocník): `../src/applications/panel/help/` · príručka frameworku pre vývojárov:
  `framework/README.md`.
- Čo čaká na majiteľa: Web_store `TODO-majitel.md`.
- Pravidlá pre prácu (aj pre Clauda): `../CLAUDE.md`, Web_store `CLAUDE.md`.

## Vývojové prostredie (domáci server 192.168.0.11, len v LAN)

| Čo | Kde |
|---|---|
| framework, panel, weby | http://192.168.0.11:8090/ (`/panel`, login admin/admin), hbkpravotice / bnhl: `http://hbkpravotice.sk.192-168-0-11.sslip.io:8090/`, `http://bnhl.sk.192-168-0-11.sslip.io:8090/`; testy `dev/` (PHP 8.5 ako hosting) |
| e-shopy — kópie | `~/webstore/devN`: dev4 = 9.2.1 (:8084, najbližšie ostrému 9.2.0 — testovať tu); dev1 = stav pred aktualizáciou (1.7.6, :8081), dev5 = 8.2.9 (:8085) — už len história; dev2, dev3 = staršie skúšky (vypnuté) |
| e-maily z kópií | Mailpit http://192.168.0.11:8025 (nič neodíde von) |
| zálohy z hostingu | `/mnt/storage/erma/backups/` (zrkadlené disky RAID1 2× 1 TB; `~/webstore/backups` je odkaz naň) |

Súbor `hosts` sa nikdy needituje — kópie majú adresy cez sslip.io.
