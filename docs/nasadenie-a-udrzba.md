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
- PHP 7.3 (kvôli e-shopu), databáza `molesk` spoločná pre všetko.

## Čo sa nasadzuje

| Časť | Kde je hotová | Závisí od |
|---|---|---|
| A. Framework + erma.sk (dočasná stránka) + **zamestnanecká sekcia** (objednávky, fakturácia, sklad, balík pre účtovníka) + **hbkpravotice.sk, bnhl.sk** | WebApplication `src/`, overené na dev | nič — môže ísť prvé |
| B. Právne úpravy e-shopov (texty, odstúpenie, oznámenie o záruke …) | Web_store `legal/`, `shop-modules/erma_legal` | voliteľne hneď na 1.7.6, inak s C |
| C. **Aktualizácia e-shopu na PrestaShop 8.2.9** (nová databáza, `/store_new/`, prepnutie) | Web_store `devserver/upgrade-run.sh`, skúška dev5 | **A musí bežať skôr** — pri aktualizácii sa odstráni starý modul faktúr (`add_faktura`), faktúry potom vystavuje len panel |

## Fáza A — framework a weby (jeden večer, prepnutie trvá sekundy)

Prepnutie je výmena jedného súboru (`index.php`), návrat späť tiež — preto je to bezpečné.

**Pripraví Claude (bez teba):**
1. Balík z dev servera: `framework/`, `applications/` (erma, panel, hbkpravotice, bnhl — bez `demo`), `vendor/`
   (knižnice: PDF, QR, e-mail), `bootstrap.php`, prázdny `var/`, nový `index.php` pod menom `index_wa.php`
   (~60 MB). Bez `config.php` a bez `.htaccess` v koreni.
2. `config.php` pre hosting s vyplnenými doménami a aplikáciami a s **prázdnymi** heslami (databáza, SMTP) a
   vygenerovaným `jwtSecret`.
3. Kontrolný zoznam po prepnutí + skript `dev/crawl-check.py`, ktorý prejde verejné stránky.

**Urobíš ty (raz, kedykoľvek vopred):**
1. Schránka na hostingu na odosielanie faktúr (napr. `faktury@erma.sk`) — jej SMTP údaje.
2. V `config.php` doplniť heslo k databáze (to isté ako v dnešnom `config/DatabaseConfig.php`) a SMTP heslo.
   Heslá neposielaj Claudovi, zapíš ich priamo do súboru.
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
3. Hneď skontrolovať (Claude z domáceho servera cez verejné adresy + ty prehliadačom): erma.sk, hbkpravotice.sk
   (aj StadiumControl prihlásenie), bnhl.sk, erma.sk/panel (prihlásenie, objednávky, koncept faktúry), zariadenia
   (`/fw`, časomiera).
4. Ak niečo nejde: premenovať späť (`index_oldcore.php` → `index.php`) = starý stav za pár sekúnd.
5. Prvé prihlásenie do panelu: vytvoriť zamestnancov, vyplniť firmu (ERMA Group, číselné rady — prvé číslo faktúry,
   e-mail účtovníka, „posielať automaticky“), napojiť e-shopy.

Po prepnutí: používatelia hbkpravotice s „Remember me“ sa raz prihlásia znova (nový podpis prihlásenia).

## Fáza B — právne úpravy e-shopov (voliteľne pred C)

Postup v Web_store `docs/pravne-nalezitosti.md` („Nasadenie“): nahrať modul `erma_legal`, `odstupenie.php`,
spustiť `legal-apply.php` cez tajnú adresu. Funguje aj na dnešnom 1.7.6. Ak nie teraz, urobí ich automaticky
aktualizácia (fáza C). Oznámenie o zákonnej záruke je povinné od 27. 9. 2026.

## Fáza C — e-shop na PrestaShop 8.2.9 (večer, e-shopy 2–4 h v údržbe)

Celý postup je dohodnutý a nacvičený (Web_store `docs/upgrade-plan.md`, „Production procedure“ A/B/C):
nová databáza, aktualizácia na domácom serveri, nahratie do `/store_new/`, kontrola na
`https://<doména>/store_new/`, prepnutie priečinkov, návrat späť kedykoľvek do zmazania `/store_old/`.

Ešte chýba (pripraví Claude): nástroje `store-package.sh`, `store-import.php`, `store-switch.php` a ich nácvik.

Po prepnutí: v paneli napojiť nový e-shop (nová databáza → spojenie cez Webservice, panel to vie), vypnúť faktúry
v PrestaShope (`PS_INVOICE` 0), prvú zásielku Packeta skontrolovať v klientskej sekcii Packety, prvú platbu GoPay
skontrolovať v GoPay.

## Fáza D — upratanie (2–4 týždne po C, keď je všetko v poriadku)

- Zmazať z hostingu: `index_oldcore.php`, `core/`, `WebApp_*`, `core_new/`, staré `config/*Config*.php` (je v nich
  heslo k databáze), `/store_old/`, staré tabuľky `ps_*` starého e-shopu.
- **Zmeniť heslo k databáze** (bolo v repozitári StadiumControl a v starých konfiguráciách) a nové zapísať do
  `config.php` frameworku a `parameters.php` e-shopu.
- Zmazať originály v StadiumControl (`WebApp_hbkpravotice`, `WebApp_bnhl`, `core/`, `Config.php`) — commit ty.

## Neskôr

- **PHP 8.1 na hostingu** (framework aj hbkpravotice/bnhl sú na to pripravené, overené na 8.4): pred tým oficiálne
  moduly GoPay a Packeta (starý GoPay na PHP 8 nefunguje), potom sa odinštaluje `erma_compat`.
- **PrestaShop 9** (nácvik dev4 hotový): až po PHP 8.1.
- E-faktúry od 1. 1. 2027 (platitelia DPH): pre ERMA Group dnes len prijímanie — rieši KROS cez účtovníčku.

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
