# Nasadenie a údržba (plán, 20. 11. 2026)

Čo všetko ide na hosting, v akom poradí, kto čo robí a ako sa to potom udržiava. Podrobnosti k e-shopu sú
v `C:\Users\orras\git\Web_store\docs\upgrade-plan.md` (časť „Production procedure“).

## Ako je to dnes na hostingu

- Jeden FTP priečinok = koreň webu pre všetky domény. Koreňový `index.php` je **najstaršie jadro** (`core/`,
  `WebApp_*`): obsluhuje erma.sk, hbkpravotice.sk, bnhl.sk. E-shopy sú v `/store/` (PrestaShop 1.7.6.7).
- Na hostingu je aj staršia kópia frameworku (`applications/`, `config/`, `interfaces/`, `entities/` z 23. 3. 2026;
  `core_new/` už na hostingu nie je), ktorú nič nevolá (overené zo zálohy 20. 11. 2026: koreňový `index.php` =
  `require "core/Application.php"`, `.htaccess` zhodný so snímkou).
- **Na tom istom hostingu sú aj subdomény s VLASTNÝM priečinkom** (nie sú v koreni FTP, záloha koreňa ich nemá):
  `aqua.erma.sk` (zavlažovanie, repozitár `Web_AquaErma`, aj `api/device_sync.php` pre ESP32) a
  `mobileservice.erma.sk` (LCS hub pre staré telefóny, repozitár `DB2020_revival`, beží na PHP 8 — hosting teda vie
  nastaviť verziu PHP pre doménu). Fáza A ich nemení.
- Koreňový `.htaccess` je spoločný (presmerovanie na https, e-shopy do `/store/`, zariadenia ESP32 `/fw` a
  `/api/device_sync.php` bez https, všetko ostatné na `/index.php`) — **nikdy sa neprepisuje**, pre nový framework
  netreba v ňom nič meniť.
- PHP **8.5** od 20. 11. 2026 (predtým 7.3 kvôli starému e-shopu) — nastavuje sa pre každú doménu zvlášť (aj aliasy);
  databázy: ostré `ermaskretail`, testovacia `molesk` (pozri nižšie). Staré nástroje `admin/`, `sklad/`, `beta/` na 8.5 neoverené.

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

- **`ermaskretail` NIE JE prázdna** (zistené zo zálohy 20. 11. 2026, 42 tabuliek): `aqua_*` (zavlažovanie — zapisuje
  sa doň neustále), `lcs_*` (LCS hub `mobileservice.erma.sk` + agent na domácom serveri), `branchmanager_*`
  (školský projekt, už sa nepoužíva — zmazať vo fáze D). Preto sa do nej **len pridávajú vlastné tabuľky** (`panel_*`,
  `hbkpravotice_*`, `ps_*` — žiadna sa s týmito prefixmi nebije); nikdy „zmazať všetko a nahrať“, nikdy import celej
  zálohy cez ňu. Kopírovanie pri A2 musí brať len vymenované prefixy.
- Prečo e-shop nejde cez `molesk`: v `molesk` už sú tabuľky starého e-shopu `ps_*`; nový by potreboval iný prefix a
  potom ešte jedno sťahovanie (premenovanie) — zbytočné riziko. Test e-shopu je `/store_new/` pred prepnutím.
- Kým sú panel a e-shop v rôznych databázach (A2 pred C, alebo naopak), panel číta e-shop **cez Webservice** (vie to,
  odskúšané na dev): v administrácii e-shopu zapnúť Webservice a vytvoriť kľúč pre panel. Keď sú v tej istej
  (`ermaskretail`), v paneli sa spojenie označí „táto databáza“ (rýchlejšie čítanie).
- Staré nástroje na hostingu (`admin/`, `sklad/`, `beta/`, `psc/`, `albums/`) bežia na `molesk`; o každom sa
  rozhodne: preniesť do frameworku (→ `ermaskretail`) alebo zrušiť. (`device_sync.php` pre zariadenia nie je v koreni
  FTP — je na `aqua.erma.sk`, zdroj v `Web_AquaErma/WaterIrrigationWeb/api/`; pravidlo v koreňovom `.htaccess` pre
  `/api/device_sync.php` je pozostatok, nechať ho.)
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
2. ~~V `config.php` doplniť prístup do databázy `molesk` a SMTP~~ — **hotové 20. 11.** (na žiadosť majiteľa doplnil
   Claude skriptom bez zobrazenia hesiel): databáza = rovnaký prístup ako `config/DatabaseConfig.php`, `Config.php` aj
   `store/.../parameters.php` na hostingu (všetky tri sa zhodujú); SMTP = `info@erma.sk` cez `email.active24.com:465`
   SSL, ten istý účet, ktorým dnes posiela e-shop AmerickeSPZ. Iná schránka pre faktúry (napr. `faktury@erma.sk`) sa
   dá neskôr zmeniť v `config.php`.
3. ~~Zistiť, čo je `aqua12.erma.sk`~~ — zistené 20. 11.: dnes ukazuje len hlášku starého jadra „Web application was
   not configured“, skutočné zavlažovanie je `aqua.erma.sk` (vlastný priečinok). Po fáze A ukáže stránku 404 — nič sa
   nestratí. (Rovnako `erma.sk` bez www dnes vracia chybu 500 — opraví to fáza A.)

**Pred každým nasadením (A aj C) — celá záloha:**
- `Web_store/tools/ps-pack.php`: nastaviť v ňom `SECRET` a do `$EXTRA_DATABASES` doplniť prístup do
  **`ermaskretail`** (riadok so vzorom je v skripte; heslo sa na stránke nikdy neukáže a skript sa do archívu nebalí).
  Nahrať do **koreňa FTP** (nie do `store/`) — zabalí všetky súbory všetkých webov aj e-shopu (bez cache) a
  **všetky databázy**: tie, ktorých prístup nájde na serveri (`molesk` zo `store/`, starého jadra `Config.php`,
  `config/DatabaseConfig.php`, po nasadení aj z `config/config.php`), + `ermaskretail` z `$EXTRA_DATABASES`.
  Keď už `config/config.php` panelu ukazuje na `ermaskretail` (po fáze A2), `$EXTRA_DATABASES` netreba.
- Otvoriť `https://erma.sk/ps-pack.php?key=…` → skontrolovať tabuľku **Databases** (musia tam byť `molesk` aj
  `ermaskretail`) → „Dump databases“, „Pack files“ → stiahnuť 3 súbory (`database-molesk.sql.gz`,
  `database-ermaskretail.sql.gz`, `files.tar.gz`) → „Delete everything“ (zmaže archívy aj skript z hostingu). Na
  hostingu musí byť voľné miesto aspoň na archív (e-shop sám mal 2,2 GB); ak nie je, v skripte je voľba bez obrázkov
  produktov. Ak jedna databáza zlyhá (napr. zlé heslo), ostatné sa aj tak uložia a pri úlohe je napísané, ktorá a prečo.
- Súbory uložiť na domáci server (`~/webstore/backups/` = odkaz na `/mnt/storage/erma/backups/`, zrkadlené disky
  RAID1 — pri poruche jedného disku sa záloha nestratí; ako `database-<meno>-<dátum>.sql.gz` a
  `ftp-<dátum>.tar.gz` — celé FTP; `files-<dátum>.tar.gz` je len e-shop, keď ps-pack beží v `store/`;
  `devserver/setup-copy.sh` vie oba) — sú v nich osobné údaje zákazníkov, nedávať do cloudu.
- Záloha koreňa **neobsahuje** priečinky subdomén `aqua.erma.sk` a `mobileservice.erma.sk` (ich dáta sú v
  `ermaskretail`, tá v zálohe je; kód je v repozitároch `Web_AquaErma`, `DB2020_revival`). Fáza A sa ich nedotýka.
- **Hotové 20. 11. 2026 (pred fázou A):** `molesk` 377 tabuliek, `ermaskretail` 42 tabuliek, celé FTP 3,2 GB
  (70 594 položiek, e-shop bez cache) — na domácom serveri `~/webstore/backups/*-2026-11-20-*`, kontrolné súčty
  sedia; kópia aj v `C:\Users\orras\git\Web_store\backups\`.
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
   **Stav 20. 11. 2026 večer:** prepnuté; erma.sk (aj bez www — chyba 500 starého jadra zmizla), hbkpravotice.sk,
   StadiumControl, bnhl.sk, statické stránky, `/fw`, e-shopy, aqua — OK; `/config`, `/vendor`, `/framework` → 403.
   **Panel dal 500:** prvá migrácia čítala tabuľku používateľov `panel_client`, ktorú nikto nevytvoril (na dev ju robil
   `dev/seed.php`, testy ju mali vždy pripravenú). Oprava: migrácia `000_framework_tables.sql` (tabuľky frameworku
   client/token/log/text) + test na úplne prázdnej databáze + skúška presne tejto situácie na dev serveri (polovične
   prebehnutá 001 → oprava → všetko dobehne). Nahrať 4 súbory panela: `App.php`, `sql/000_framework_tables.sql`,
   `src/Controller/SetupController.php`, `views/setup.php` (v balíku sú už opravené).
5. **Prvý správca panela:** jednorazová stránka `/panel/setup?key=…` — funguje len kým v paneli nie je žiadny účet a len
   s kľúčom odvodeným z `jwtSecret` (`SetupController::key()`, adresu vypočíta Claude z `config.php`). Majiteľ si tam
   zvolí e-mail a heslo sám; potom stránka navždy vráti 404. Ostatných zamestnancov pridáva správca v paneli.
   **Prihlasovacie meno = e-mail** (majiteľ 20. 11. 2026) — v paneli všade, uložený malými písmenami.
6. Prvé prihlásenie do panelu: vytvoriť zamestnancov, vyplniť firmu (ERMA Group, číselné rady — prvé číslo faktúry,
   e-mail účtovníka, „posielať automaticky“), napojiť e-shop cez Webservice (kľúč z administrácie e-shopu).

Po prepnutí: používatelia hbkpravotice s „Remember me“ sa raz prihlásia znova (nový podpis prihlásenia).

## Fáza A2 — preklopenie na `ermaskretail` (keď A beží bez problémov, ~15 min)

**Od 20. 11. 2026 sa robí spolu s fázou C** — tlačidlami „Import A2“ a „Prepnúť“ v `erma-deploy.php` (nižšie). Body
pod týmto odsekom opisujú, čo sa pri tom deje.

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

## Fáza C — e-shop na PrestaShop 8.2.9 + A2 v ten istý večer (údržba ≈ 1 h)

**Rozhodnutie 20. 11. 2026 (majiteľ: „poďme na to už“): C a A2 naraz** — jeden nástroj presunie e-shop aj tabuľky
panela a hbkpravotice do `ermaskretail`; panel potom číta e-shop vo svojej databáze, žiadne medzikroky cez Webservice.

**Nástroje — hotové a nacvičené 20. 11. 2026** (Web_store `devserver/`, podrobne `docs/upgrade-plan.md` „A′“):
produkčná kópia bez internetu (`prod-copy.sh`) → aktualizácia (`upgrade-run.sh`, ~4 min) → balík
(`store-package.sh`, ~206 MB: jeden archív súborov + databáza po častiach + A2) → **`erma-deploy.php`** na hostingu
(jedna stránka s tlačidlami: kontrola · rozbaliť /store_new/ · import e-shopu · import A2 · overiť · prepnúť ·
zapnúť e-shopy · vrátiť späť · zmazať /store_old/ · zmazať nástroj). Generálka na **simulácii hostingu**
(`hostsim.sh`: celé FTP zo zálohy, HTTPS, molesk + ermaskretail) prešla celá vrátane poistky, návratu späť a
opätovného prepnutia; panel, StadiumControl a obrázky produktov po prepnutí OK.

**Postup ostrého večera krok po kroku s cestami a adresami: [`spustenie-eshopu.md`](spustenie-eshopu.md).** Skrátene
(technicky: Web_store `docs/upgrade-plan.md`, „C. Go-live day“):
1. Ty: starý e-shop do údržby (všetky obchody, tvoja IP povolená); panel a StadiumControl v tú chvíľu nepoužívať.
2. Ty: ps-pack do koreňa → len **Dump databases** → stiahnuť do `Web_store\backups\` → Delete everything.
3. Claude (~15 min): aktualizácia na domácom serveri → balík `ERMA-nasadenie\C-<dátum>\` s doplneným prístupom.
4. Ty: FTP nahrať `erma-deploy.php` + priečinok `_deploy_…` do koreňa (binárne).
5. Ty: `https://www.erma.sk/erma-deploy.php?key=…` → Rozbaliť → Import e-shopu → Import A2 → Overiť.
6. Kontrola `https://howieshockey.sk/store_new/` atď. (ty prehliadačom, Claude verejné stránky).
7. Ty: **Prepnúť** → kontrola → **Zapnúť e-shopy** (ERMA obchod ostáva v údržbe). Problém → **Vrátiť späť**.
8. Hosting: vypnúť cron `cron_howies_activate.php`, `cron_usspz_del.php`; erma_stockactive „synchronizovať“; platby
   GoPay toho večera porovnať. Faktúry PrestaShopu sú vypnuté samé (`PS_INVOICE` 0 v aktualizácii).
9. Po pár dňoch: **Zmazať /store_old/** a **Zmazať nástroj**.

**Aby sa nestratila ani jedna objednávka** (podrobne Web_store `docs/upgrade-plan.md`, „Keeping the data current“):
- nový e-shop beží na kópii databázy z jedného okamihu → **údržba všetkých e-shopov sa zapne PRED zálohou databázy**
  a počas nej sa v starom e-shope nič nemení (ani z panelu);
- `erma-deploy.php` pred prepnutím porovná starú a novú databázu (objednávky, zákazníci, košíky, správy, platby;
  tabuľky panela a hbkpravotice obsahom) a **prepnutie odmietne**, ak sa po záložení niečo zmenilo — nacvičené;
- súbory e-shopu sú zo zálohy 20. 11. (v e-shope sa odvtedy nemenia moduly / šablóna / kód); v deň prepnutia sa
  berie len databáza → údržba ~1 h;
- obrázky a súbory zákazníkov (`img/`, `upload/`, `download/`) sa pri prepnutí presunú, nekopírujú;
- platby GoPay z toho večera sa po prepnutí porovnajú so stavmi objednávok; cron úlohy starého modulu sa vypnú.

Po prepnutí: panel číta e-shop vo svojej databáze sám (overené v simulácii); prvú zásielku Packeta skontrolovať v
klientskej sekcii Packety, prvú platbu GoPay skontrolovať v GoPay.

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
| každý mesiac | záloha: `tools/ps-pack.php` na hostingu (obe databázy + všetky súbory) → stiahnuť na domáci server; **faktúry sú len v databáze a musia sa uchovať 10 rokov** | ty spustíš, Claude skontroluje a uloží |
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
