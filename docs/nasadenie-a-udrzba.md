# Nasadenie a údržba (plán, 20. 11. 2026)

Čo všetko ide na hosting, v akom poradí, kto čo robí a ako sa to potom udržiava. Podrobnosti k e-shopu sú
v `C:\Users\orras\git\Web_store\docs\upgrade-plan.md` (časť „Production procedure“).

## Ako je to dnes na hostingu

- Jeden FTP priečinok = koreň webu pre všetky domény. Koreňový `index.php` je **najstaršie jadro** (`core/`,
  `WebApp_*`): obsluhuje erma.sk, hbkpravotice.sk, bnhl.sk. E-shopy sú v `/store/` (PrestaShop 1.7.6.7).
- Na hostingu je aj staršia kópia frameworku (`core_new/`, `applications/`, `config/`), ktorú nič nevolá.
- Koreňový `.htaccess` je spoločný (presmerovanie na https, e-shopy do `/store/`, zariadenia ESP32 `/fw` a
  `/api/device_sync.php` bez https, všetko ostatné na `/index.php`) — **nikdy sa neprepisuje**, pre nový framework
  netreba v ňom nič meniť.
- PHP 7.3 (kvôli e-shopu), databáza `molesk` spoločná pre všetko (aj staré nástroje `admin/`, `sklad/`, `beta/`).

## Databázy (majiteľ 20. 11. 2026)

- **`ermaskretail` = ostrá databáza** pre všetko, čo je v plnej prevádzke.
- **`molesk` = testovacia databáza.** Nasadzuje sa najprv na ňu (ako dnes), a keď je všetko overené, **preklopí sa** do
  `ermaskretail`. Potom ostáva na testovanie (kópia ostrých dát na hostingu) — nemaže sa.

  | Čo | Tabuľky | Nasadenie | Do `ermaskretail` |
  |---|---|---|---|
  | panel (zamestnanecká sekcia) | `panel_*` | fáza A v `molesk` (tabuľky vzniknú samy) | **fáza A2 — preklopenie** |
  | hbkpravotice.sk + bnhl.sk | `hbkpravotice_*` (23 tabuliek, ~1,5 MB) | fáza A v `molesk` (dnešné tabuľky, nič sa nekopíruje) | **fáza A2 — preklopenie** |
  | erma.sk (dočasná stránka) | — | databázu nepoužíva | — |
  | e-shopy (PrestaShop 8.2.9) | `ps_*` | **fáza C rovno do `ermaskretail`** — nový e-shop je aj tak nová kópia a pred prepnutím sa testuje na `/store_new/` | v rámci C |

- Prečo e-shop nejde cez `molesk`: v `molesk` už sú tabuľky starého e-shopu `ps_*`; nový by potreboval iný prefix a
  potom ešte jedno sťahovanie (premenovanie) — zbytočné riziko. Test e-shopu je `/store_new/` pred prepnutím.
- Kým sú panel a e-shop v rôznych databázach (A2 pred C, alebo naopak), panel číta e-shop **cez Webservice** (vie to,
  odskúšané na dev): v administrácii e-shopu zapnúť Webservice a vytvoriť kľúč pre panel. Keď sú v tej istej
  (`ermaskretail`), v paneli sa spojenie označí „táto databáza“ (rýchlejšie čítanie).
- Staré nástroje na hostingu (`admin/`, `sklad/`, `beta/`, `psc/`, `albums/`, `/api/device_sync.php` pre zariadenia —
  ten nie je v žiadnom repozitári, treba ho stiahnuť z FTP) bežia na `molesk`; o každom sa rozhodne: preniesť do
  frameworku (→ `ermaskretail`) alebo zrušiť.
- Prístupy do databáz (používateľ + heslo) sú len v `config.php` frameworku a v `parameters.php` e-shopu.

## Čo sa nasadzuje

| Časť | Kde je hotová | Závisí od |
|---|---|---|
| A. Framework + erma.sk (dočasná stránka) + **zamestnanecká sekcia** (objednávky, fakturácia, sklad, balík pre účtovníka) + **hbkpravotice.sk, bnhl.sk** | WebApplication `src/`, overené na dev | nič — môže ísť prvé |
| B. Právne úpravy e-shopov (texty, odstúpenie, oznámenie o záruke …) | Web_store `legal/`, `shop-modules/erma_legal` | voliteľne hneď na 1.7.6, inak s C |
| C. **Aktualizácia e-shopu na PrestaShop 8.2.9** (nová databáza, `/store_new/`, prepnutie) | Web_store `devserver/upgrade-run.sh`, skúška dev5 | **A musí bežať skôr** — pri aktualizácii sa odstráni starý modul faktúr (`add_faktura`), faktúry potom vystavuje len panel |

## Fáza A — framework a weby (jeden večer, prepnutie trvá sekundy)

Prepnutie je výmena jedného súboru (`index.php`), návrat späť tiež — preto je to bezpečné.

**Pripraví Claude (bez teba):**
1. Balík (`python dev/apps.py package NAZOV` — poskladá framework, panel a aplikácie z ich repozitárov podľa
   `apps.json`): `framework/`, `applications/` (erma, panel, hbkpravotice, bnhl — bez `demo`), `vendor/` (knižnice:
   PDF, QR, e-mail), `bootstrap.php`, prázdny `var/`, statické stránky hbkpravotice do koreňa
   (`livestream.html`, `hbkpravoticetimer.html` …, načítavajú teraz `/applications/hbkpravotice/…`), nový `index.php`
   pod menom `index_wa.php` (~60 MB). Bez `.htaccess` v koreni. **Hotové: `C:\Users\orras\ERMA-nasadenie\A-20261120\www`.**
2. `config.php` pre hosting s vyplnenými doménami a aplikáciami a s **prázdnymi** heslami (databáza, SMTP) a
   vygenerovaným `jwtSecret` (`dev/make-prod-config.py` — len pri prvej inštalácii; ďalšie balíky `config.php` nemajú,
   na hostingu ostáva ten pôvodný). **Hotové, v balíku `config/config.php`.**
3. Kontrolný zoznam po prepnutí + skript `dev/crawl-check.py`, ktorý prejde verejné stránky.

**Urobíš ty (raz, kedykoľvek vopred):**
1. Schránka na hostingu na odosielanie faktúr (napr. `faktury@erma.sk`) — jej SMTP údaje.
2. V `config.php` doplniť prístup do databázy **`molesk`** (to isté ako v dnešnom `config/DatabaseConfig.php`)
   a SMTP heslo. Heslá neposielaj Claudovi, zapíš ich priamo do súboru.
3. Zistiť, čo je `aqua12.erma.sk` (je v `.htaccess`, ale nie v konfigurácii starého jadra) — ak beží z koreňa,
   povedz, doplníme ho.

**Pred každým nasadením (A aj C) — celá záloha:**
- `Web_store/tools/ps-pack.php` (nastaviť v ňom `SECRET`) nahrať do **koreňa FTP** (nie do `store/`) — zabalí
  všetky súbory všetkých webov aj e-shopu (bez cache) a celú databázu `molesk` (prístup číta zo `store/`).
- Otvoriť `https://erma.sk/ps-pack.php?key=…` → „Dump database“, „Pack files“ → stiahnuť 2 súbory → „Delete
  everything“ (zmaže archívy aj skript z hostingu). Na hostingu musí byť voľné miesto aspoň na archív
  (e-shop sám mal 2,2 GB); ak nie je, v skripte je voľba bez obrázkov produktov.
- Súbory uložiť na domáci server (`~/webstore/backups/`) — sú v nich osobné údaje zákazníkov, nedávať do cloudu.
- Claude zálohu skontroluje (archív sa dá otvoriť, databáza obsahuje všetky tabuľky).

**Večer prepnutia (ty cez FTP, ~30 min, väčšinu času trvá nahrávanie):**
1. Nahrať balík do koreňa (priečinky `framework/`, `vendor/`, `var/` sú nové; `applications/` prepíše
   nepoužívanú starú kópiu; `config/config.php` pribudne). Starý web medzitým stále beží — `index.php` sa ešte nemenil.
2. Premenovať `index.php` → `index_oldcore.php`, potom `index_wa.php` → `index.php`. **Toto je prepnutie.**
   Databáza je stále `molesk` — hbkpravotice/bnhl pracujú s tými istými tabuľkami ako doteraz.
3. Hneď skontrolovať (Claude z domáceho servera cez verejné adresy + ty prehliadačom): erma.sk, hbkpravotice.sk
   (aj StadiumControl prihlásenie), bnhl.sk, erma.sk/panel (prihlásenie, objednávky, koncept faktúry), zariadenia
   (`/fw`, časomiera).
4. Ak niečo nejde: premenovať späť (`index_oldcore.php` → `index.php`) = starý stav za pár sekúnd.
5. Prvé prihlásenie do panelu: vytvoriť zamestnancov, vyplniť firmu (ERMA Group, číselné rady — prvé číslo faktúry,
   e-mail účtovníka, „posielať automaticky“), napojiť e-shop cez Webservice (kľúč z administrácie e-shopu).

Po prepnutí: používatelia hbkpravotice s „Remember me“ sa raz prihlásia znova (nový podpis prihlásenia).

## Fáza A2 — preklopenie na `ermaskretail` (keď A beží bez problémov, ~15 min)

1. Záloha (ako vždy). Chvíľu nikto nevystavuje faktúry a nehrá sa zápas (StadiumControl zapisuje počas zápasu).
2. **Kopírovanie tabuliek** `panel_*` a `hbkpravotice_*` z `molesk` do `ermaskretail` — funkcia v paneli (pozri
   „Údržba e-shopov a databáz v paneli“ nižšie) alebo jednorazový skript; po skopírovaní porovná počty riadkov a
   kontrolné súčty každej tabuľky.
3. V `config.php` zmeniť databázu na `ermaskretail` → od tej chvíle všetko beží v ostrej databáze.
4. Kontrola ako pri A. Návrat späť = v `config.php` znova `molesk` (tam je všetko do okamihu kopírovania).
5. `molesk` ostáva testovacia (kópia dát z dňa preklopenia).

## Fáza B — právne úpravy e-shopov (voliteľne pred C)

Postup v Web_store `docs/pravne-nalezitosti.md` („Nasadenie“): nahrať modul `erma_legal`, `odstupenie.php`,
spustiť `legal-apply.php` cez tajnú adresu. Funguje aj na dnešnom 1.7.6. Ak nie teraz, urobí ich automaticky
aktualizácia (fáza C). Oznámenie o zákonnej záruke je povinné od 27. 9. 2026.

## Fáza C — e-shop na PrestaShop 8.2.9 (večer, e-shopy 2–4 h v údržbe)

Celý postup je dohodnutý a nacvičený (Web_store `docs/upgrade-plan.md`, „Production procedure“ A/B/C):
databáza **`ermaskretail`** (nie nová prázdna), aktualizácia na domácom serveri, nahratie do `/store_new/`, kontrola na
`https://<doména>/store_new/`, prepnutie priečinkov, návrat späť kedykoľvek do zmazania `/store_old/`.

Ešte chýba (pripraví Claude): nástroje `store-package.sh`, `store-import.php`, `store-switch.php` a ich nácvik.

**Aby sa nestratila ani jedna objednávka** (podrobne Web_store `docs/upgrade-plan.md`, „Keeping the data current“):
- nový e-shop beží na kópii databázy z jedného okamihu → **údržba všetkých e-shopov sa zapne PRED zálohou databázy**
  a počas nej sa v starom e-shope nič nemení (ani z panelu);
- `store-switch.php` pred prepnutím porovná starú a novú databázu a **prepnutie odmietne**, ak sa starý e-shop po
  záložení zmenil;
- súbory e-shopu sa nahrajú **deň vopred**; v deň prepnutia sa berie len databáza → údržba ~1 h;
- obrázky a súbory zákazníkov (`img/`, `upload/`, `download/`) sa pri prepnutí presunú, nekopírujú;
- platby GoPay z toho večera sa po prepnutí porovnajú so stavmi objednávok; cron úlohy starého modulu sa vypnú.

Po prepnutí: ak je už panel v `ermaskretail` (A2), prepnúť v ňom spojenie s e-shopom na „táto databáza“; vypnúť faktúry
v PrestaShope (`PS_INVOICE` 0), prvú zásielku Packeta skontrolovať v klientskej sekcii Packety, prvú platbu GoPay
skontrolovať v GoPay.

## Fáza D — upratanie (2–4 týždne po C, keď je všetko v poriadku)

- Zmazať z hostingu: `index_oldcore.php`, `core/`, `WebApp_*`, `core_new/`, staré `config/*Config*.php` (je v nich
  heslo k databáze), `/store_old/`.
- `molesk` ostáva testovacia databáza; staré tabuľky starého e-shopu v nej môžu ostať ako testovacie dáta.
  O starých nástrojoch (`admin/`, `sklad/`, `beta/` …) rozhodnúť: preniesť do frameworku alebo zrušiť.
- **Zmeniť heslo k databáze `molesk`** (bolo v repozitári StadiumControl a v starých konfiguráciách) a nové zapísať
  tam, kde ju ešte niečo používa (testovacie nastavenia, staré nástroje).
- Zmazať originály v StadiumControl (`WebApp_hbkpravotice`, `WebApp_bnhl`, `core/`, `Config.php`) — commit ty.

## Neskôr

- **PHP 8.1 na hostingu** (framework aj hbkpravotice/bnhl sú na to pripravené, overené na 8.4): pred tým oficiálne
  moduly GoPay a Packeta (starý GoPay na PHP 8 nefunguje), potom sa odinštaluje `erma_compat`.
- **PrestaShop 9** (nácvik dev4 hotový): až po PHP 8.1.
- E-faktúry od 1. 1. 2027 (platitelia DPH): pre ERMA Group dnes len prijímanie — rieši KROS cez účtovníčku.

## Údržba e-shopov a databáz v paneli (návrh, majiteľ 20. 11. 2026: „čo najjednoduchšie aj do budúcna“)

Panel beží na tom istom hostingu ako e-shopy, ale mimo priečinka e-shopu — preto ho aktualizácia e-shopu nezhodí
a môže ju riadiť. Hosting nemá príkazový riadok a PHP má časový limit → každá dlhá práca ide po krokoch s
priebehom na obrazovke (ako pri `ps-pack.php`). Len pre správcu, každý krok v histórii, pred každým potvrdenie.
Návrh stránky **Údržba** (správca):

1. **Zálohy** — tlačidlo (a automaticky raz za mesiac): databáza po tabuľkách + súbory (bez cache) do archívu na
   hostingu; domáci server si ich sám stiahne cez chránenú adresu a uloží (mimo hostingu). Nahradí ručný `ps-pack.php`.
   Prehľad: posledná záloha, veľkosť, voľné miesto na hostingu.
2. **Kopírovanie / presun databázy** — tabuľky (podľa prefixu, napr. `panel_`, `hbkpravotice_`, `ps_`) z jednej
   databázy do druhej (`molesk` ↔ `ermaskretail`), s porovnaním počtu riadkov a kontrolných súčtov. Tým sa urobí
   preklopenie A2 a neskôr aj obnova testovacej `molesk` z ostrých dát („skopíruj ostré dáta do testu“).
3. **Aktualizácia e-shopu — vždy „vedľa“** (ako dohodnutý postup C, len z panela):
   *Pripraviť kópiu* (súbory → `/store_new/`, tabuľky → nový prefix) → *aktualizovať kópiu* (oficiálny „Update
   Assistant“ PrestaShopu v administrácii kópie) → *skontrolovať* (`/store_new/`, panel spustí kontrolu stránok) →
   *Prepnúť* (údržba, kontrola, že sa starý e-shop nezmenil, presun obrázkov, výmena priečinkov) → *Vrátiť späť*
   alebo po pár dňoch *Upratať*. Starý e-shop je až do upratania nedotknutý.
4. **Stav** — verzia PrestaShopu a PHP, posledná dostupná verzia PrestaShopu, údržba zapnutá/vypnutá.

Hranica: veľké zmeny ako dnešný prechod 1.7.6 → 8.2.9 (odstraňovanie starých modulov, úprava témy, nové moduly)
sa aj naďalej najprv nacvičia na domácom serveri — tam beží celá sada testov. Bežné opravné verzie (8.2.x) a
neskôr aj 9.x pôjdu cez panel. Nástroje `store-import.php` / `store-switch.php`, ktoré ešte treba urobiť, sa tým
stanú funkciami panela namiesto samostatných skriptov — práca navyše teda takmer nie je.

## Údržba do budúcna

**Pravidlá:**
- Každá zmena najprv na domácom serveri (kópia e-shopu `devN`, dev panel na :8090), testy, až potom hosting.
  Ostrý e-shop sa nikdy needituje priamo.
- Kód je v git (WebApplication, e-shop `~/webstore/shop.git`), **commity robíš ty**. Claude po každej zmene povie, čo
  sa zmenilo. Heslá sú len v `config.php` / `parameters.php` na hostingu, nikdy v git.
- Nasadenie zmeny = balík z dev servera + nahratie cez FTP + kontrola; staré súbory sa pred prepísaním odložia
  (návrat späť). Databázové zmeny panelu sa spustia samy pri prvej návšteve (migrácie).

**Pravidelne:**

| Kedy | Čo | Kto |
|---|---|---|
| každý mesiac | záloha: `tools/ps-pack.php` na hostingu (celá databáza + súbory) → stiahnuť na domáci server; **faktúry sú len v databáze a musia sa uchovať 10 rokov** | ty spustíš, Claude skontroluje a uloží |
| každý mesiac | balík pre účtovníka odíde sám; skontrolovať, že prišiel | automaticky / účtovníčka |
| každý mesiac | pozrieť logy (`var/log/` frameworku, logy PrestaShopu) | Claude z FTP kópie |
| pri vydaní opravy PrestaShopu 8.2.x (bezpečnostné) | nácvik na kópii (`upgrade-run.sh`), potom hosting | Claude pripraví, ty nahráš |
| raz za štvrťrok | aktualizácie knižníc (dompdf, PHPMailer, QR — `composer audit`), kontrola zmien zákonov (`docs/legislativa.md`, Web_store `docs/pravne-nalezitosti.md`) | Claude |
| 1. 1. a pri zmene zákona | sadzby DPH a zákonné texty — mení sa v paneli (Legislatíva), nie v kóde | ty / Claude |

**Čo nikdy nemeniť bez prípravy:**
- `jwtSecret` v `config.php` — šifrujú sa ním prístupy do banky (Fio token) a podpisujú prihlásenia; po zmene treba
  token Fio zadať znova a všetci sa prihlásia znova.
- Koreňový `.htaccess` — spoločný pre e-shopy a zariadenia.
- Vystavené faktúry a ich archívne PDF — systém ich nedovolí meniť; opravuje sa dobropisom.
