# Panel — architektúra a plán do budúcna (návrh 21. 11. 2026)

Majiteľ 21. 11. 2026: „nepáči sa mi, ako je to rozhádzané po paneli … poďme si to dať dokopy aj s plánom do budúcna, nech
nerobíme špagety.“ Cieľ: **jeden firemný nástroj** pre firmu, ktorá **vyvíja elektroniku, predáva cez e-shopy a možno bude
prenajímať** — úlohy (ako Asana: kanban, timeline), projekty podľa V-modelu (s API na výsledky testov), dokumenty
(zdieľanie a spoločné písanie), súčiastky a kusovníky, nákup, výroba, sériové čísla, firmvér, reklamácie a servis,
ponuky a zákazky, výkazy práce, prenájom, majetok a kalibrácie, automatizácie, mobil so skenovaním, prepojenie
s gitom (commity pri úlohách, ako v codeBeameri), **zákazková výroba (3D tlač, gravírovanie) s podkladmi od zákazníka
v jednom kontajneri** — a „poriadna správa práv pre všetko“. Všetko **dôkladne popísané** („aby v tom vedel robiť aj
idiot“ — kapitola 7).

Tento dokument určuje: (1) pravidlá, (2) celý obraz a spoločné kmeňové dáta, (3) navigáciu, (4) jadro, (5) práva,
(6) moduly, (7) dokumentáciu pre používateľov, (8) poradie prác, (9) rozhodnutia a otvorené otázky, (10) zásobník
nápadov. Stav: **návrh** — majiteľ ho odsúhlasuje po fázach.

## 1. Pravidlá (aby to neboli špagety)

1. **Ľavé menu = denná práca, všetko nastavenie na jednom mieste („Nastavenia“).**
2. **Každá vec má jeden domov.** Záznam sa môže *zobraziť* na viacerých miestach (úloha pri objednávke, reklamácia pri
   sériovom čísle), ale upravuje sa na jednom.
3. **Kmeňové dáta sú len raz** (kapitola 2): jeden adresár kontaktov, jeden katalóg položiek, jeden register kusov
   (sériových čísel), jedno číslovanie dokladov. Moduly ich používajú, nekopírujú.
4. **Moduly sa nevolajú navzájom naprieč; spájajú ich služby jadra** (prepojenia, udalosti, aktivita, súbory …). Úlohy
   nevedia nič o faktúrach — vedia len „tento záznam odkazuje na záznam typu X“. Výroba nevolá e-shop priamo — vyvolá
   udalosť „hotové kusy“ a sklad / e-shop na ňu reaguje.
5. **Modul sa do panela zapojí sám** (trasy, menu, oprávnenia, záložky nastavení, typy záznamov, udalosti, widgety
   prehľadu) — nový modul = nový priečinok, nie úpravy na desiatich miestach.
6. **Všetko patrí firme** (systém nie je len pre ERMA Group) — rozhodnuté 21. 11. 2026 aj pre projekty a úlohy.
7. **Práva sú súčasťou každej veci od začiatku** — jedna kontrola pre obrazovky, akcie, zoznamy, exporty aj API
   (kapitola 5); čo nie je výslovne dovolené, je zakázané.
8. **Čo určuje zákon, sú dáta s platnosťou od–do** (docs/legislativa.md) — aj pre nové veci: lehoty reklamácií,
   zálohové faktúry a doklady k prijatej platbe, texty zmlúv o prenájme, záruky.
9. **Obmedzenia hostingu platia pre všetko:** len FTP a PHP (žiadne websockety ani dlho bežiace procesy), časový
   limit PHP, obmedzené miesto → živé veci cez krátke dotazovanie, dlhé práce po krokoch, plánované úlohy cez cron
   adresu (ako dnes mesačný balík), veľké súbory s limitom (otázka 3).
10. **Žiadna funkcia bez návodu** (majiteľ 21. 11. 2026: „všetko bude musieť byť dôkladne popísané, aby v tom vedel robiť
    aj idiot“): funkcia je hotová, až keď má návod v Pomocníkovi, zrozumiteľné texty na obrazovke a chybové hlášky,
    ktoré povedia, čo urobiť (kapitola 7).
11. **Prepojiť, nie nahradiť.** Účtovníctvo ostáva v Omege, mzdy u účtovníčky, návrh DPS v KiCade, príprava tlače
    v sliceri, e-mailový server a chat tam, kde sú. Panel ich spája (importy, odkazy, väzby), nekopíruje ich — inak
    by sa z neho stal nekonečný projekt s neúnosnou údržbou.

## 2. Celý obraz

```
           ┌──────────── chyby z testov a reklamácií → úlohy ─────────────┐
           ▼                                                               │
  VÝVOJ ───────────► PRODUKT ──────► NÁKUP ──► VÝROBA ──► SKLAD ──► PREDAJ ──► ZÁKAZNÍK ──► SERVIS
  projekty, úlohy,   revízie,        objednávky výrobné    vlastné   e-shopy,    kusy v      reklamácie,
  V-model, testy,    kusovník (BOM), dodávateľom príkazy,  sklady,   ponuky,     teréne,     opravy,
  dokumenty          firmvér,        → príjem   sériové    šarže,    zákazky,    firmvér,    záruky
                     dokumentácia               čísla,     FIFO      prenájom    záruka
                                                výstupné
                                                testy
  ─────────────────────────────── FINANCIE: faktúry, náklady, výkazy práce, výsledky (marža produktu, zákazky,
                                  prenájmu, výroby) ─────────────────────────────────────────────────────────────
```

**Kmeňové dáta (každé len raz, všetky moduly ich zdieľajú):**

| Kmeňové dáta | Čo to je | Kto ich používa |
|---|---|---|
| **Kontakty** | firmy a osoby: odberatelia, dodávatelia, nájomcovia, extern spolupracovníci, kontaktné osoby firiem | faktúry, náklady, ponuky, nákup, servis, prenájom, projekty (zákazník projektu), CRM |
| **Položky** (katalóg) | všetko, čo kupujeme, vyrábame, skladujeme, predávame alebo prenajímame: súčiastka, materiál, **produkt** (vlastný výrobok s revíziami), tovar, služba, typ prenájmu | sklad, nákup, výroba, ponuky, faktúry, prenájom; prepojenie na produkty v e-shopoch (sklad e-shopu ostáva v e-shope — rozhodnutie 19. 11.) |
| **Kusy** (sériové čísla) | každý fyzický kus so sériovým číslom: vyrobené zariadenie, prenajímaná vec, merací prístroj, náradie. Vlastník (my / zákazník), miesto, stav, revízia HW, verzia firmvéru, záruka | výroba, predaj, servis, prenájom, majetok a kalibrácie, register zariadení v teréne |
| **Doklady a číslovanie** | jeden mechanizmus číselných radov (dnešný `Numbering`) a PDF šablón v štýle firmy (dnešný vzhľad faktúry) | faktúry, zálohové faktúry, ponuky, objednávky dodávateľom, výrobné príkazy, reklamačné protokoly, zmluvy a protokoly prenájmu, štítky |
| **Kalendár** | termíny z celého systému: úlohy, rezervácie prenájmu, dovolenky, kalibrácie, lehoty reklamácií, splatnosti | Prehľad, timeline, Moje úlohy, kapacita ľudí |

## 3. Navigácia (cieľový stav)

```
Prehľad              – moje úlohy, Treba urobiť (lehoty, schvaľovanie, chýbajúci materiál, kalibrácie …), čísla firmy
PREDAJ
  Objednávky         – e-shopy: zoznam · Expedícia (vrátenia sú pri objednávke)
  Ponuky a zákazky   – cenové ponuky · ZÁKAZKY (kontajner: 3D tlač, gravírovanie, vývoj na zakázku, väčšie dodávky)
                       · príležitosti (kanban)
  E-shopy            – produkty, ceny, akcie, sklad v e-shope
  Kontakty           – firmy a osoby (dnešní Odberatelia + dodávatelia z Nákladov)
  Pošta              – e-mailové schránky firmy, správy priradené k objednávkam, zákazkám, reklamáciám, kontaktom
  Obsah produktov    – popisy, fotky, parametre raz pre všetky e-shopy, feedy (Heureka, Google)
FINANCIE
  Faktúry            – faktúry, dobropisy, zálohové · Úhrady (výpis, Fio) · Pre účtovníka (balík, Omega)
  Náklady            – prijaté doklady · opakované
  Výsledky           – výsledok firmy, peniaze a predpoveď cash-flow, marže (produktov, zákaziek, výroby, prenájmu)
  Platby             – platby dodávateľom (príkazy do Fio), platobné odkazy GoPay pri faktúrach a zákazkách
VÝROBA
  Produkty           – produkty a revízie: kusovník (BOM), firmvér, dokumentácia (datasheet, návod, vyhlásenie o zhode)
  Súčiastky          – katalóg súčiastok, ceny a dostupnosť u distribútorov, koniec výroby, náhrady
  Nákup              – objednávky dodávateľom · čo treba objednať
  Výroba             – výrobné príkazy · výstupné testy · FRONTA STROJOV (3D tlačiarne, laser)
  Sklad              – sklady, doklady, šarže (súčiastky aj hotové výrobky)
  Zariadenia         – register kusov (sériové čísla): vyrobené, u zákazníkov, v teréne (firmvér)
SERVIS
  Reklamácie a servis– reklamácie (lehoty zo zákona), opravy, záruky
PRENÁJOM             (keď príde na rad)
  Rezervácie         – kalendár dostupnosti, rezervácie
  Prenájmy           – zmluvy, odovzdanie / vrátenie (protokoly s fotkami), kaucie
PRÁCA
  Moje úlohy         – všetko moje naprieč projektmi
  Projekty           – Prehľad · Úlohy (zoznam / kanban / timeline) · Dokumenty · Výkazy
                       · pri V-modeli: Požiadavky · Návrh · Testy · Matica · Vydania
  Dokumenty          – zápisník mimo projektov (postupy, porady, návody)
  Kalendár           – termíny, dovolenky, rezervácie, kalibrácie; Výkazy práce
FIRMA
  Majetok            – náradie, meracie prístroje (kalibrácie), vybavenie — tiež kusy z registra
  Povinnosti         – register povinností a termínov firmy (dane, výkazy, zmluvy, domény, certifikáty, licencie,
                       osvedčenia ľudí, žiadosti podľa GDPR) s pripomienkami
Nastavenia           (záložky, len kto má právo)
  Firmy · E-shopy (pripojenia, STAVY OBJEDNÁVOK, firma ↔ e-shop) · Účtovníctvo · Náklady objednávok · Legislatíva ·
  Projekty (šablóny, stĺpce, úrovne V-modelu) · Výroba (číslovanie sériových čísel, distribútori) · Prenájom (cenníky,
  podmienky) · Automatizácie · Zamestnanci a roly · Integrácie (API kľúče)
Hore na každej stránke: hľadať · skenovať (QR / čiarový kód) · zvonček · „+ Nové“ · **? Pomocník** (návod k tejto stránke)
```
Skupina sa v menu ukáže len tomu, kto má k nej právo a len keď je modul zapnutý (Prenájom sa dá vypnúť, kým ho firma
nepoužíva). Staré adresy presmerujú na nové.

## 4. Jadro: čo zdieľajú všetky moduly

| Služba | Čo robí |
|---|---|
| **Odkaz na záznam** (`Ref`) | jednotný identifikátor `typ:id` (napr. `invoice:15`, `order:0:2481`, `task:7`, `unit:LB-C-0042`) — názov, adresa, kontrola prístupu; každý modul registruje svoje typy |
| **QR odkaz** | každý záznam má krátku adresu `/panel/r/<ref>` a QR kód → štítok na krabičke, zariadení, prenajatej veci; skenovanie z mobilu otvorí kartu |
| **Prepojenia** (`link`) | ľubovoľný záznam ↔ ľubovoľný záznam s druhom vzťahu (súvisí, blokuje, overuje, vzniklo z, nahrádza …) |
| **Udalosti** | modul ohlási „čo sa stalo“ (objednávka zaplatená, kusy vyrobené, test zlyhal, lehota sa blíži); na udalosti reaguje aktivita, notifikácie, automatizácie a iné moduly — bez priamych väzieb |
| **Aktivita** | história každého záznamu + kanál „čo sa deje“ (dnešný `audit` sa rozšíri, ostane čitateľný) |
| **Komentáre** + @zmienky | pri každom zázname (dnešné interné poznámky objednávky sa stanú komentármi) |
| **Notifikácie** | zvonček v paneli (polling) + e-mail (hneď / denné zhrnutie podľa nastavenia človeka) |
| **Súbory** | prílohy k čomukoľvek, verzie, SHA-256, náhľady (obrázky, PDF), fotky z mobilu, zdieľanie odkazom — **veľké súbory mimo hostingu** (4.1) |
| **Číslovanie + PDF šablóny** | dnešný `Numbering` a vzhľad faktúry zovšeobecnené pre všetky doklady modulov |
| **Kalendár** | termíny z modulov na jednom mieste (kapitola 2) |
| **Plánovač** | opakované a časové veci (pripomienky lehôt, kalibrácie, opakované úlohy, mesačný balík) — cez cron adresu |
| **Vyhľadávanie** | jedno pole hore naprieč všetkým, podľa práv |
| **Práva** | jedna služba `Access` — kapitola 5 |
| **API** | `/panel/api/v1/…`, JSON, kľúč s rozsahom práv, audit volaní, limity; pre testovacie prípravky, CI, zariadenia, web (rezervácie) |
| **Integrácie** | spoločný spôsob pre externé služby (PrestaShop, Fio, distribútori súčiastok, Packeta …): zašifrované kľúče, limity volaní, medzipamäť, stav pripojenia |

### 4.1 Úložisko súborov (veľké súbory mimo zdieľaného hostingu)
Majiteľ 21. 11. 2026: „zdieľanie väčších dokumentov — je nezmysel to mať na shared hostingu.“ Súhlas: hosting má málo
miesta, PHP má limit veľkosti nahrávania a času, a každý bajt by šiel cez PHP.

**Rozhodnutie (majiteľ 21. 11. 2026): veľké súbory budú vo firemnom Nextcloude** — beží na domácom serveri, je dostupný
z internetu (`https://home.ermagroup.net`, Nextcloud 35.0.3 za Cloudflare, overené 21. 11.), na RAID diskoch.
Panel súbory eviduje a stráži práva, dáta ležia v Nextcloude. Pravidlo 11 („prepojiť, nie nahradiť“) v praxi.

- **Štruktúra**: panel má v Nextcloude vlastného technického používateľa (napr. `erma-panel`, heslo aplikácie
  uložené zašifrované ako kľúče e-shopov) a vlastný strom priečinkov: `Panel/<firma>/<modul>/<záznam>/` (napr.
  `Panel/ERMA Group/Zakazky/Z-2026-0142/`). Ku každému záznamu jeho priečinok — vytvorí sa sám, keď treba.
- **Nahrávanie veľkých súborov ide rovno do Nextcloudu, nie cez hosting**: zamestnanec klikne „Otvoriť priečinok“
  (Nextcloud, nahrávanie po častiach, aj desktopová aplikácia na synchronizáciu); **zákazník** dostane od panela
  **odkaz na nahratie** (Nextcloud „file drop“ — vidí len nahrávanie do priečinka svojej zákazky, s platnosťou
  a heslom); **API** (výsledky testov, firmvéry z CI / testovacích prípravkov) si od panela vypýta odkaz na nahratie
  a súbor pošle priamo do Nextcloudu. Cez hosting idú len malé správy, nie dáta.
- **Limit Cloudflare: 100 MB v jednej požiadavke** → všetko väčšie sa nahráva **po častiach** (Nextcloud web
  a aplikácie to robia samy; panel a API použijú chunked upload Nextcloudu).
- **Zobrazenie a zdieľanie**: pri zázname panel ukáže zoznam súborov z jeho priečinka (WebDAV), náhľady, verzie
  (Nextcloud verzie uchováva sám); **zdieľací odkaz** pre ľudí mimo firmy vytvorí panel cez Nextcloud (platnosť,
  heslo, len čítanie / aj nahrávanie, zrušenie) a zapíše ho do histórie záznamu.
- **Práva**: zdrojom pravdy sú práva panela; priečinky `Panel/…` vlastní technický používateľ, ľudia sa k súborom
  dostanú cez panel alebo cez zdieľanie, ktoré panel nastaví (tímom firmy skupinou v Nextcloude). Externí len cez
  odkazy z panela.
- **Office dokumenty**: ak v Nextcloude beží Nextcloud Office (Collabora) alebo OnlyOffice, Word / Excel súbory sa dajú
  upravovať naraz viacerými ľuďmi priamo tam — panel na ne len odkazuje (dopĺňa Dokumenty 6.2, ktoré sú pre text
  a poznámky v paneli).
- **Malé veci ostávajú ako dnes** (archív PDF faktúr, doklady nákladov — zákonná úschova, musia byť dostupné aj
  keď je domáci server vypnutý). Úložisko je za jedným „adaptérom“: keby Nextcloud nestačil (rýchlosť domácej linky,
  dostupnosť), dá sa prejsť na S3 úložisko (Hetzner Object Storage, Cloudflare R2, Backblaze B2) bez zmeny modulov.
- **Zálohy — RAID nie je záloha**: kópia dát Nextcloudu ešte na iné miesto mimo domu (napr. Hetzner Storage Box
  alebo Backblaze B2, šifrovane, raz denne) → 3 kópie, 2 miesta — **TODO na neskôr** (majiteľ: „keď sa rozrastieme“). A dohľad: keď Nextcloud nebeží, panel to povie
  (a pri zázname ukáže „súbory sú dočasne nedostupné“ namiesto chyby).
- **Bezpečnosť**: Nextcloud je vystavený do internetu → aktualizácie, dvojfaktorové prihlásenie pre ľudí, ochrana
  proti hádaniu hesiel (Nextcloud ju má), technický používateľ panela len s heslom aplikácie a len k `Panel/…`.
  Nastavenia Nextcloudu mení len majiteľ alebo s jeho súhlasom (je to jeho služba na domácom serveri).

V kóde: `src/Core/` (jadro) a `src/Modules/<Modul>/` (logika, controllery, pohľady, migrácie, testy) + rozhranie
`Module`. Dnešný kód sa presúva postupne, bez zmeny správania (testy prechádzajú po každom kroku).

## 5. Správa práv (pre všetko)

**Tri vrstvy — akcia je dovolená, len ak ju dovolia všetky tri:**
1. **Čo smie robiť** (oprávnenie modul + akcia): napr. `orders.edit`, `invoices.issue`, `invoices.unissue`,
   `purchase.approve`, `production.release`, `units.edit`, `service.resolve`, `rental.handover`, `reports.margins`,
   `settings.users`, `tests.results.write` … Každý modul svoje oprávnenia **registruje sám**.
2. **Kde** (rozsah): **firma**; pri predaji aj **e-shop**; pri práci **projekt** (rola v projekte: vlastník / člen /
   len čítanie / externý); pri skladoch **sklad** (skladník len svojho skladu).
3. **Ktorý záznam** (zdieľanie): súkromné (autor), projekt, firma, vybraní ľudia.

**Roly** = pomenované sady oprávnení (upraviteľné): *Majiteľ / správca*, *Fakturácia a účtovníctvo*, *Expedícia*,
*Sklad a nákup*, *Výroba*, *Vývoj*, *Testovanie*, *Servis*, *Prenájom*, *Externý spolupracovník*, *Len čítanie*.
Človek má jednu alebo viac rolí + výnimky; rola v projekte je samostatná.

**Pravidlá:** kontrola vždy na serveri (`Access::can(osoba, oprávnenie, záznam/rozsah)`), zoznamy filtrované už
v databáze; vyhľadávanie, prepojenia, notifikácie, exporty a QR odkazy ukážu len povolené. Citlivé veci majú vlastné
oprávnenia (marže a nákupné ceny, výsledky firmy, mzdy, nastavenia, API kľúče, vrátenie vystavenej faktúry, mazanie).
**API kľúč** má rozsah a nikdy viac práv než jeho autor; dá sa zrušiť; volania sa zapisujú. Zmeny práv sa zapisujú;
prehľad „kto čo vidí“. Testy práv pre každý modul. Prechod z dnešných práv automaticky, bez straty / zisku prístupu.
**Externí** (rozhodnuté) vidia len projekty, kam ich pozvú — nikdy ceny, marže ani iné projekty.

## 6. Moduly

Pri každom: čo je jeho **domov**, čo z jadra / kmeňových dát používa, na čom stojí.

### 6.1 Úlohy a projekty (ako Asana) — PRÁCA
- **Projekt** (firmy): názov, kľúč (`LED`), členovia s rolami, zákazník (kontakt), typ *bežný* / *V-model* / *zákazka*
  — jeden druh projektu s rozšíreniami, nie tri systémy.
- **Úloha**: popis, riešiteľ, sledujúci, začiatok a termín, priorita, štítky, stĺpec, podúlohy, kontrolný zoznam,
  závislosti, prílohy, komentáre, prepojenia na čokoľvek. Osobné úlohy bez projektu. Opakované úlohy, šablóny projektov.
- **Pohľady**: zoznam, **kanban**, **timeline** (Gantt so závislosťami, posun ťahaním, dovolenky z kalendára),
  kalendár, **Moje úlohy**.
- **Výkazy práce**: hodiny pri úlohe (stopky alebo ručne), týždenný výkaz, fakturovateľné / nie; vstup pre ziskovosť
  zákaziek a náklady výroby. **Dovolenky** (žiadosť → schválenie) a štátne sviatky (dáta v Legislatíve) v kalendári.

- **Financie projektu** (každý projekt — bežný, vývoj, V-model, zákazka; majiteľ 21. 11. 2026: „trackovanie financií
  v projekte“): **rozpočet** po kategóriách (práca, materiál a súčiastky, služby a subdodávky, cestovné, iné) a
  voliteľne po etapách; **skutočnosť** sa skladá sama z toho, čo už v paneli je — hodiny z výkazov × nákladová sadzba
  človeka, prijaté doklady (Náklady) a nákupné objednávky priradené k projektu, materiál vydaný zo skladu na projekt,
  náklady výroby; **výnosy** — faktúry a zálohové faktúry priradené k projektu (pri zákazke aj fakturačné míľniky);
  **prehľad**: rozpočet vs. skutočnosť vs. odhad do konca, marža, čerpanie v čase (graf), upozornenie pri prekročení
  (napr. 80 % a 100 % rozpočtu) → notifikácia a „Treba urobiť“. Vlastný vývoj (produkt bez zákazníka) = náklady vývoja
  produktu, ktoré sa neskôr ukážu pri marži produktu (či ich účtovať ako majetok, rozhodne účtovníčka).
  Sadzby ľudí (nákladová / fakturačná) a čísla financií vidí len ten, kto má oprávnenie `projects.finance`.
- **Správa rozpočtu** (majiteľ 21. 11. 2026: „budget na projekt treba spravovať“):
  - **Zostavenie**: položky rozpočtu (kategória, etapa, popis, množstvo × cena — napr. 120 h vývoja FW × sadzba,
    50 ks DPS, certifikácia CE), aj zo šablóny projektu alebo z cenovej ponuky (zákazka); hodiny rozpočtu sa dajú
    rozdeliť na úlohy (odhad úlohy → plán vs. skutočne odpracované).
  - **Schválenie a verzie**: rozpočet má stav (návrh → schválený); **každá zmena schváleného rozpočtu je zmena
    s dôvodom** (navýšenie, presun medzi kategóriami / etapami, rezerva), kto ju navrhol a kto schválil, história
    verzií — vždy vidno pôvodný rozpočet, aktuálny a prečo sa menil.
  - **Viazané prostriedky**: objednané, ale ešte nezaplatené (nákupné objednávky, potvrdené subdodávky) sa rátajú
    ako čerpané → „voľný rozpočet“ = rozpočet − skutočnosť − viazané.
  - **Kontrola výdavkov**: pri priradení nákladu / objednávky k projektu panel ukáže zostatok kategórie; nad zostatok
    (alebo nad limit) treba schválenie oprávnenou osobou (`projects.budget.approve`) — nastaviteľné na projekte.
  - **Rezerva** projektu a jej čerpanie, **odhad do konca** (zostávajúce hodiny úloh × sadzby + plánované nákupy),
    upozornenia pri 80 % / 100 % kategórie aj celku, prehľad rozpočtov všetkých projektov firmy (portfólio).
- **Číslo úlohy** = kľúč projektu + poradové číslo: `LED-123` (osobné úlohy `U-45`). Používa sa všade: v komentároch,
  dokumentoch, **v správach commitov** (6.11), na štítkoch.

### 6.2 Dokumenty (zápisník) — PRÁCA
- Formátovaný text, tabuľky, obrázky, odkazy na záznamy (`@LED-REQ-12`, `#objednávka`), priečinky, patrí projektu
  alebo firme; komentáre k textu; **história verzií**; export PDF / Word.
- Najprv „jeden píše, ostatní vidia“ (automatické ukladanie, zámok, zmeny do pár sekúnd) — rozhodnuté; neskôr živé
  písanie naraz (CRDT, napr. Yjs cez krátke dotazovanie — ide aj na zdieľanom hostingu).

### 6.3 V-model a testy — PRÁCA (projekt typu V-model)
```
  Požiadavky zákazníka / produktu  ─────────────────────────  Akceptačné testy (validácia)
     Systémové požiadavky  ──────────────────────────────  Systémové testy
        Architektúra (HW / FW / mechanika)  ─────────────  Integračné testy
           Detailný návrh (modul)  ──────────────────────  Testy modulov (unit)
                          Implementácia (kód, DPS, firmvér)
```
- Položky: požiadavka, prvok návrhu, testovací prípad — ID (`LED-REQ-12`), text, stav, verzia, história. Úrovne
  nastaviteľné. Interná disciplína, bez formálnych podpisov (rozhodnuté).
- **Trasovateľnosť** (prepojenia z jadra) a **matica** (čo nemá test, čo padlo, čo nie je overené — za projekt / vydanie).
- **Testovacie behy** = spoločná služba pre V-model **aj výrobu** (výstupné testy kusov): beh = čo sa testovalo
  (verzia FW / HW, sériové číslo), prostredie, **použitý merací prístroj** (kontrola platnej kalibrácie), výsledky po
  prípadoch, prílohy (logy, merania). **API**: JUnit XML a JSON. Chyba → úloha jedným klikom.
- **Vydania / baseline**: zmrazený stav požiadaviek a testov k verzii produktu / firmvéru.

### 6.4 Kontakty a obchod (ponuky, zákazky, CRM) — PREDAJ
- **Kontakty**: zlúčenie dnešných odberateľov (faktúry) a dodávateľov (náklady) bez straty väzieb; firma + osoby;
  IČO z registra (RPO je už v paneli); história (faktúry, objednávky, reklamácie, prenájmy, projekty) na jednom mieste.
- **Príležitosti** (kanban: dopyt → ponuka → rokovanie → vyhraté / prehraté).
- **Cenové ponuky**: položky z katalógu alebo voľné, platnosť, PDF vo vzhľade firmy, odoslanie e-mailom, verzie
  ponuky; prijatá ponuka → **zákazka** (projekt typu zákazka) a/alebo **zálohová faktúra**.
- **Zálohové faktúry** a **doklad k prijatej platbe** (platiteľ DPH) + odpočet zálohy na konečnej faktúre — nové
  typy dokladov vo fakturácii, pravidlá zo zákona o DPH do Legislatívy (docs/legislativa.md).
- **Zákazka** — jeden kontajner na všetko, čo k zákazke patrí (podrobne 6.12): rozpočet, hodiny (výkazy), náklady
  (prepojené prijaté doklady), fakturované → ziskovosť priebežne.

### 6.5 Produkty, súčiastky a nákup — VÝROBA
- **Katalóg položiek** (kmeňové dáta) rozšíri dnešné skladové položky: typ (súčiastka, materiál, produkt, tovar,
  služba, typ prenájmu), jednotky, väzba na produkty e-shopov.
- **Súčiastky**: výrobca a objednávacie číslo (MPN), puzdro, parametre, ponuky distribútorov (ceny podľa množstva,
  skladom, dodacia lehota) cez ich API — najprv TME a Mouser (kľúč API), potom DigiKey, prípadne agregátor (Nexar);
  stav výroby súčiastky (aktívna / neodporúča sa / koniec) a **náhrady**; upozornenie, keď sa súčiastka v kusovníku
  končí.
- **Produkt a revízie** (A, B, C …): stav (vývoj / výroba / koniec), **kusovník (BOM) importovaný z KiCadu**
  (export kusovníka; párovanie podľa MPN v poliach schémy, nespárované sa doplnia ručne a zapamätajú), porovnanie
  kusovníkov dvoch revízií, **cena kusu** (súčiastky podľa FIFO / aktuálnej ceny + práca + DPS a osadenie),
  dokumentácia revízie (datasheet, návod, vyhlásenie o zhode CE, výsledky testov), prepojenie na projekt (V-model)
  a na produkty v e-shopoch → skutočná marža.
- **Nákup**: „čo treba objednať“ (plánované výroby × kusovník − sklad − už objednané, min. zásoby), objednávka
  dodávateľovi (PDF / e-mail), schválenie nad limit, príjem po častiach → dnešná príjemka a šarže, prijatá faktúra →
  dnešné Náklady (spárovanie s objednávkou).

### 6.6 Výroba, kusy a majetok — VÝROBA / FIRMA
- **Výrobný príkaz** („50 ks LedBoard rev. C“): vlastná výroba alebo u dodávateľa (osadenie DPS = objednávka
  dodávateľovi s dodaným materiálom), rezervácia materiálu, výdaj zo skladu (FIFO ceny — už je), postup (stavy),
  **sériové čísla** podľa radu (napr. `LB-C-2026-0042`) a **štítky s QR**, **výstupný test každého kusu** (testovacie
  behy cez API z testovacieho prípravku), hotové kusy na vlastný sklad alebo rovno do skladu e-shopu (Webservice, ako
  vrátenia), skutočná cena kusu (materiál + práca z výkazov) → Výsledky.
- **Register kusov**: celý život kusu — výroba, testy, predaj (objednávka / faktúra / zákazník), záruka do, firmvér,
  servis, vrátenie. Hľadanie podľa sériového čísla alebo naskenovaním.
- **Firmvér**: vydania (verzia, zmeny, súbory, kompatibilné revízie HW, väzba na vydanie V-modelu a testy), kanály
  (test / stabilný), **zariadenia v teréne** — dnešné zariadenia ESP32 už hlásia stav (`/api/device_sync`) a sťahujú
  firmvér (`/fw`); register ukáže verzie v teréne a umožní riadené aktualizácie.
- **Majetok**: náradie, meracie prístroje, vybavenie, prenajímané veci = kusy vo vlastníctve firmy; zodpovedná osoba,
  miesto, nákup (prijatý doklad), **plán kalibrácií / revízií / údržby** s pripomienkami a certifikátmi; prístroj
  po termíne kalibrácie sa v testovacích behoch označí.

### 6.7 Reklamácie a servis — SERVIS
- Reklamácia: zákazník, objednávka / faktúra, kus (sériové číslo) alebo produkt, popis, fotky, dátum uplatnenia,
  **lehota na vybavenie podľa zákona** (dáta v Legislatíve s platnosťou), potvrdenie o uplatnení a protokol (PDF),
  e-maily zákazníkovi v štýle e-shopu, stav (prijatá → diagnostika → oprava / výmena / vrátenie peňazí / zamietnutá →
  vybavená), pripomienky pred koncom lehoty.
- Väzby: výmena = výdaj kusu zo skladu, vrátenie peňazí = dnešný dobropis, oprava = práca (výkaz) + súčiastky,
  opakujúca sa chyba → úloha vo vývoji. Platený servis mimo záruky → ponuka → faktúra.
- Jednoduchá verzia (reklamácie e-shopov bez sériových čísel) môže ísť **skôr** ako výroba — je to zákonná povinnosť
  a e-shopy ju potrebujú už dnes.

### 6.8 Prenájom — PRENÁJOM (keď príde na rad)
- Prenajímané veci = kusy majetku s príznakom „na prenájom“, zoskupené do **typov prenájmu** s cenníkom (deň,
  víkend, týždeň, mesiac), kauciou a časom na prípravu medzi prenájmami.
- **Kalendár dostupnosti** a **rezervácie** (dopyt → potvrdená → vydaná → vrátená → uzavretá / zrušená); dvojitá
  rezervácia toho istého kusu sa nedá (kontrola v databáze).
- **Zmluva** (PDF z textov v Legislatíve), **kaucia** (prijatá / vrátená / započítaná), **odovzdávací a preberací
  protokol** z mobilu (fotky, stav, kontrolný zoznam, podpis prstom), škody → faktúra, zálohová faktúra alebo faktúra
  pri vydaní, pravidelná fakturácia pri dlhodobom prenájme, údržba medzi prenájmami (plán z majetku).
- Neskôr verejná rezervácia na webe (erma.sk) cez API.

### 6.9 Automatizácie — Nastavenia
- Pravidlá **„keď → ak → urob“** nad udalosťami z jadra: keď je objednávka zaplatená → úloha Expedícia; keď zásoba
  súčiastky klesne pod minimum → položka do „treba objednať“ + úloha; keď sa blíži koniec lehoty reklamácie → upozornenie;
  keď test zlyhá → úloha vývoju; keď sa blíži kalibrácia → úloha.
- Pravidlo beží s právami svojho autora, zapisuje sa do aktivity, ochrana proti zacykleniu.

### 6.10 Mobil a skenovanie — všade
- Panel funguje na telefóne (dnes čiastočne); **skenovanie QR / čiarových kódov kamerou** (sklad, výroba, expedícia,
  servis, prenájom, majetok); **tlač štítkov** (PDF pre tlačiareň štítkov) pre krabičky súčiastok, zariadenia,
  prenajímané veci; fotky z mobilu priamo k záznamu. Neskôr inštalovateľná aplikácia (PWA).

### 6.11 Git a vývojové nástroje — PRÁCA (ako codeBeamer)
Majiteľ 21. 11. 2026: „keď spravím commit, nech sa ten commit ukáže v rámci tasku — tak to máme v codeBeameri v robote.“
- **Commit pri úlohe**: v správe commitu je číslo úlohy (`LED-123: oprava časovača`, aj viac čísel). Pri úlohe sa
  ukáže zoznam commitov (repozitár, vetva, autor, čas, správa, odkaz do repozitára), úloha dostane záznam do aktivity
  a sledujúci notifikáciu. To isté funguje pre **požiadavky a testy V-modelu** (`LED-REQ-12` v commite → trasovateľnosť
  až po kód) a pre **reklamácie / chyby**.
- **Vetvy a pull / merge requesty**: vetva `LED-123-casovac` sa ukáže pri úlohe aj so stavom PR (otvorený, schválený,
  zlúčený) a výsledkom CI.
- **Príkazy v commite** (voliteľné, ako „smart commits“): `fixes LED-123` → úloha do stĺpca Hotovo / Na kontrolu,
  `LED-123 #time 2h` → výkaz práce. Pravidlá prechodov nastaviteľné v projekte.
- **Tagy a vydania**: tag `v1.2.0` v repozitári firmvéru → **vydanie firmvéru** (6.6) so zoznamom úloh a commitov od
  posledného vydania = automatické poznámky k vydaniu; väzba na vydanie V-modelu a výsledky testov.
- **Ako sa commity dostanú do panela** (podľa toho, kde sú repozitáre — otázka 9):
  - GitHub / GitLab / Gitea / Bitbucket: **webhook** pri push → `POST /panel/api/v1/git/<poskytovateľ>` (overenie
    podpisom / tajným kľúčom; repozitár sa pripojí v Nastavenia → Integrácie).
  - Vlastné repozitáre na domácom serveri (holé `.git`, napr. `~/webstore/shop.git`): skript **post-receive** pošle
    to isté cez API (malý skript v repozitári WebApplication, `dev/git-hook-post-receive`).
  - Lokálne (aj Eclipse / EGit): voliteľný hook **commit-msg**, ktorý pri commite skontroluje, či číslo úlohy existuje
    (a ponúkne zoznam mojich otvorených úloh). Hlavná väzba ide cez push (server), nie cez počítač vývojára.
- Panel kód neklonuje ani neukladá — drží len zoznam commitov (hash, správa, autor, čas, vetva, odkaz) pri záznamoch.
  Autor commitu sa spáruje s človekom v paneli podľa e-mailu.

### 6.12 Zákazková výroba (3D tlač, gravírovanie …) — jeden kontajner na zákazku — PREDAJ / VÝROBA
Majiteľ 21. 11. 2026: „robíme aj 3D printing a do budúcna gravírovanie; keď prijímame objednávku, dostaneme podklady
od zákazníka — bolo by dobré toto handlovať v jednom kontajneri.“

**Zákazka = kontajner.** Všetko k jednej zákazke je na jednom mieste, s jedným číslom (napr. `Z-2026-0142`):
- **Odkiaľ prišla**: objednávka z e-shopu (prepojená; ak produkt v e-shope má pole „nahrajte súbor“, súbory od
  zákazníka panel **načíta sám** z objednávky), cenová ponuka, e-mail alebo telefonát (zadaná ručne).
- **Zákazník** (kontakt) a **typ zákazky** — 3D tlač, gravírovanie, vývoj, iné. Typ určuje **parametre**, ktoré sa
  vypĺňajú (3D tlač: materiál, farba, výplň, vrstva, počet kusov, rozmer; gravírovanie: predmet, materiál, text,
  písmo, rozmer a umiestnenie, logo) — typy a ich polia sa nastavujú v Nastaveniach, nový druh služby nepotrebuje
  programovanie.
- **Podklady od zákazníka** (súbory s verziami: STL / 3MF / STEP, SVG / PDF / obrázky, texty): nahrá ich zamestnanec,
  prídu z e-shopu, alebo **zákazník cez bezpečný odkaz** (stránka na nahratie bez prihlásenia, len pre túto zákazku,
  s platnosťou a limitom veľkosti). Kontrola podkladov (rozmery, chýbajúce súbory) so zoznamom, čo treba od zákazníka.
- **Náhľad na schválenie** (korektúra): náhľad / vizualizácia / fotka vzorky → zákazník dostane odkaz a **schváli
  alebo napíše pripomienky** (zapíše sa kto a kedy schválil — dôležité pri reklamácii „toto som nechcel“).
  Výroba sa dá spustiť až po schválení (ak to typ zákazky vyžaduje).
- **Výroba na strojoch**: stroje (3D tlačiarne, laser) sú kusy majetku; **fronta strojov** s odhadom času, plán
  v kalendári; odhad z podkladov (čas tlače a gramy materiálu z výstupu slicera, ak sa dajú prečítať); spotreba
  materiálu (struny po gramoch, platne) sa odpíše zo skladu; voliteľne neskôr stav tlačiarní priamo (podľa typu —
  otázka 10).
- **Cena**: kalkulačka podľa pravidiel (materiál + čas stroja + príprava + dokončovanie + marža) → cenová ponuka alebo
  rovno cena v zákazke; skutočné náklady vs. cena → ziskovosť.
- **Úlohy a komunikácia**: úlohy zákazky (príprava, tlač, dokončenie, balenie) na kanbane, komentáre, e-maily so
  zákazníkom zapísané pri zákazke.
- **Stav zákazky** (kanban): prijatá → podklady skontrolované → náhľad schválený → vo výrobe → hotová → odoslaná /
  vyzdvihnutá → uzavretá; s väzbou na faktúru (aj zálohovú), dopravu a prípadnú reklamáciu (podklady + schválený
  náhľad sú poruke).
- Zákazka vývoja elektroniky je ten istý kontajner (typ „vývoj“) — rozpočet, výkazy, projekt / V-model.

### 6.13 Pošta (e-mailové schránky v paneli) — PREDAJ
- Panel číta schránky firmy (`info@`, `objednavky@` …; dnes Active24) cez IMAP každých pár minút (cron). Pozor:
  PHP 8.4+ už nemá rozšírenie `imap` v základe → knižnica v čistom PHP (spojenie cez socket), nič netreba na hostingu.
- Každá správa sa **sama priradí**: podľa čísla objednávky / zákazky / reklamácie v predmete alebo texte a podľa
  adresy odosielateľa (kontakt). Nepriradené idú do „Na roztriedenie“ (priradenie jedným klikom, panel sa to naučí
  pre ďalšie správy od toho istého odosielateľa).
- Pri objednávke, zákazke, reklamácii a kontakte je **celá komunikácia** (vlákna podľa Message-ID), prílohy sa uložia
  ako súbory záznamu (podklady 3D tlače z e-mailu rovno do zákazky).
- **Odpoveď z panela** (SMTP ako dnes, v štýle firmy / e-shopu, šablóny odpovedí), kópia v odoslanej pošte schránky.
- Práva: kto vidí ktorú schránku; zákaznícka pošta len tým, čo smú vidieť daný záznam. E-maily e-shopu
  (potvrdenia, zmeny stavov) a panela (faktúry, správy) už pri objednávke sú — pribudne prijatá pošta.

### 6.14 Platby — FINANCIE
- **Platobný odkaz GoPay** pri faktúre, zálohovej faktúre a zákazke (v e-maile aj v PDF popri PAY by square):
  zákazník zaplatí kartou, GoPay pošle oznámenie → úhrada sa zapíše sama. Prístup GoPay sa nastaví pri firme
  (rovnaké účty ako e-shopy, oficiálny modul `prestashopgopay` od 20. 11.).
- **Platby dodávateľom cez Fio**: v Nákladoch označíš neuhradené doklady → panel pripraví platobné príkazy a pošle ich
  cez Fio API (token s právom „zadávanie príkazov“ — iný ako dnešný na čítanie); **každý príkaz musí potvrdiť
  oprávnená osoba v internetbankingu / aplikácii Fio** (tak to Fio vyžaduje, panel peniaze sám nepošle). Po zaúčtovaní
  v banke ich spáruje dnešný import výpisu.
- **Predpoveď cash-flow** (Výsledky → Peniaze): stav účtov + splatné pohľadávky (s pravdepodobnosťou podľa histórie
  zákazníka) − záväzky − opakované náklady − plánované nákupy (fáza nákupu) na 1–3 mesiace dopredu, upozornenie pri
  poklese pod hranicu.

### 6.15 Povinnosti a termíny firmy — FIRMA
- **Register povinností**: čo, do kedy, ako často, kto zodpovedá, pripomienky (týždeň / deň vopred), splnené kým
  a kedy, príloha (podané priznanie, nová zmluva). Na kalendári a v „Treba urobiť“.
- **Zákonné termíny ako dáta v Legislatíve** (s platnosťou od–do, pravidlo 8): daň z príjmov, DPH a kontrolný výkaz
  (keď budete platitelia), súhrnný výkaz, **výkazy obalov a elektro / batérií pre organizáciu zodpovednosti výrobcov**
  (ak sa vás týkajú — overiť s účtovníčkou; množstvá vie panel spočítať z predaja), lehoty pri reklamáciách a GDPR.
- **Žiadosti podľa GDPR** (prístup, oprava, výmaz): evidencia so zákonnou lehotou, export údajov zákazníka z celého
  panela; výmaz s výnimkou dokladov, ktoré sa musia uchovať (faktúry 10 rokov).
- **Firemné termíny**: zmluvy (nájom, dodávatelia, zákazníci), poistky, **domény a SSL certifikáty** (SSL panel
  skontroluje sám), licencie a predplatné softvéru, **osvedčenia ľudí** (elektrotechnik podľa vyhlášky 508/2009, BOZP,
  školenia) — s pripomienkou pred koncom platnosti.

### 6.16 Obsah produktov (raz pre všetky e-shopy) — PREDAJ
- Popis, krátky popis, parametre, fotky, kategórie, SEO texty **na jednom mieste** pri položke katalógu → odoslanie do
  e-shopov cez Webservice (bezpečný zápis produktu už panel vie; fotky cez `images`). Rozdiely na obchod (iný názov,
  cena, kategória) ako výnimky.
- **Jazyky** (SK, CZ, EN … podľa obchodov), preklad voliteľne s AI (zásobník nápadov), stav „preložené / chýba“.
- **Feedy** pre Heureku a Google Merchant (verejná adresa feedu) — ak ich dnes robí modul v e-shope, ostanú tam,
  kým sa obsah nespravuje v paneli.
- Väzba na výrobu: nový produkt / revízia → návrh produktu v e-shope (fotky, parametre, dokumentácia z revízie).

## 7. Dokumentácia pre používateľov (aby v tom vedel robiť aj idiot)

Majiteľ 21. 11. 2026: „všetko bude musieť byť dôkladne popísané!“ Platí pre existujúce aj všetky nové funkcie:
- **Pomocník v paneli**: na každej stránke tlačidlo **?** → návod presne k tejto stránke (čo tu je, čo sa tu robí,
  krok za krokom, čo znamenajú polia, časté chyby a čo s nimi). Plus **„Ako na to“** — návody k úlohám naprieč
  stránkami (vystaviť faktúru z objednávky, pridať zľavu, vrátiť omylom vystavenú faktúru, poslať balík účtovníčke,
  napojiť e-shop, Fio, prijať zákazku 3D tlače …) a **slovník** pojmov (VS, dobropis, šarža, BOM, V-model …).
- **Prvé kroky**: pre nového človeka zoznam, čo si nastaviť a vyskúšať; pre správcu nastavenie firmy krok za krokom.
- **Na obrazovke**: každé pole s vysvetlivkou, prázdne zoznamy povedia, čo urobiť („Zatiaľ žiadna zákazka — vytvorte
  ju tlačidlom …“), potvrdenia pred nevratnými krokmi, chybové hlášky vždy s tým, **čo urobiť** (ako dnes pri Fio,
  objednávkach, faktúrach).
- **Návody pre ľudí mimo firmy**: účtovníčka (import do Omegy), zákazník (nahratie podkladov, schválenie náhľadu),
  externý spolupracovník (práca v projekte), testovacie prípravky a CI (API).
- **Jednoduchá slovenčina**, krátke vety, snímky obrazoviek, príklady. Návody sú súbory v repozitári
  (`src/applications/panel/help/…` — idú na hosting spolu s panelom), panel ich zobrazuje — menia sa spolu s kódom,
  takže nikdy nezastarajú.
- **Pravidlo pre vývoj**: zmena funkcie = zmena návodu v tom istom kroku; kontrola pri každej fáze („je v Pomocníkovi
  všetko, čo sa zmenilo?“). Technická dokumentácia (pre vývoj) ostáva v `docs/` a HANDOVER.

**Dokumentácia pre vývojárov** (majiteľ 21. 11. 2026: „je WebApplication dôkladne zdokumentované, aby tvorcovia ďalších
appiek vedeli, čo a ako použiť správne?“ — dnes čiastočne: README má základ, chýba úplný popis a postupy):
- **Príručka frameworku** (`docs/framework/`, fáza 0): každá trieda a jej použitie s príkladmi (Router a vzory adries,
  Request, Response, View a layouty, Session a flash správy, CSRF, prihlásenie používateľov a API tokeny / JWT,
  Database a transakcie, Logger, texty z databázy, konfigurácia), **nová aplikácia krok za krokom** (priečinok,
  `apps.json`, konfigurácia, migrácie, testy, nasadenie), **bezpečnostný kontrolný zoznam** (SQL parametre,
  escapovanie, CSRF, práva, nahrávanie súborov, tajné kľúče), konvencie (UTF-8, PHP 8.5, štýl kódu), časté chyby.
  Ukážková aplikácia `demo` ostáva živým príkladom pokrytým testami.
- **Príručka modulu panela** (fáza 1, s rozhraním `Module`): ako pridať modul (trasy, menu, oprávnenia, záložky
  nastavení, typy záznamov, udalosti, migrácie, návod v Pomocníkovi, testy práv) — vzorový modul.
- Pravidlo: zmena verejného API frameworku = zmena príručky v tom istom kroku.

## 8. Poradie prác

Veľkosť: **S** malá · **M** stredná · **L** veľká. Po jadre sú moduly do veľkej miery nezávislé — poradie od fázy 2
sa dá meniť podľa toho, čo firma potrebuje skôr.

| Fáza | Čo | Veľkosť | Stojí na |
|---|---|---|---|
| **0** ✅ | **HOTOVÉ 21. 11. 2026** — Preusporiadanie: nové menu, Nastavenia so záložkami, E-shopy samostatne, stavy objednávok do Nastavenia → E-shopy; rozhranie `Module`, presun dnešných častí — **bez zmeny funkcií**; **Pomocník** (tlačidlo ?, návody) + návody ku všetkému, čo panel už vie; **príručka frameworku pre vývojárov** | M | — |
| **1** | Jadro: **práva (roly, rozsahy, zdieľanie, `Access`)**, Ref + QR odkazy, prepojenia, udalosti, aktivita, komentáre, notifikácie, súbory, číslovanie + PDF šablóny, kalendár, plánovač, vyhľadávanie, API kľúče, integrácie | L | 0 |
| **2** | Úlohy a projekty: zoznam, kanban, Moje úlohy, komentáre, prílohy, prepojenia, notifikácie; výkazy práce; **commity pri úlohách** (webhook / post-receive, čísla `LED-123`) | L | 1 |
| **3** | Timeline + závislosti, kalendár (dovolenky, sviatky), opakované úlohy, šablóny projektov; **register povinností a termínov** (stavia len na kalendári a plánovači); **financie projektu — správa rozpočtu (zostavenie, schválenie, zmeny s dôvodom, viazané prostriedky, kontrola výdavkov) a skutočnosť** (hodiny, priradené náklady) | M | 2 |
| **4** | Dokumenty (jeden píše, ostatní vidia; verzie, komentáre) | M | 1 |
| **5** | V-model: položky, trasovateľnosť (až po commity), matica, **testovacie behy + API**, vydania; vetvy / PR a príkazy v commitoch | L | 2, 4 |
| **6** | **Financie projektu — výnosy a marža** (faktúry, zálohy, míľniky pri projekte); Kontakty (zlúčenie), príležitosti, cenové ponuky, **zákazky ako kontajner (3D tlač, gravírovanie: podklady od zákazníka, odkaz na nahratie, schválenie náhľadu, kalkulačka ceny, fronta strojov)**, zálohové faktúry; **jednoduché reklamácie e-shopov** | L | 1 (+ 2 pre úlohy zákazky) |
| **7** | **Pošta** (schránky, priradenie správ, odpovede z panela) + **Platby** (GoPay odkaz, príkazy do Fio, predpoveď cash-flow) | L | 1, 6 (kontakty, zákazky) |
| **8** | Katalóg položiek, súčiastky (distribútori), produkty a revízie, **BOM z KiCadu**, nákup | L | 1, 6 (dodávatelia) |
| **9** | Výroba: výrobné príkazy, **sériové čísla a register kusov**, štítky, výstupné testy, náklady výroby; majetok a kalibrácie | L | 5 (testy), 8 |
| **10** | Servis naplno (podľa sériových čísel, opravy, záruky); firmvér a zariadenia v teréne | M | 9 |
| **11** | **Obsah produktov** (raz pre všetky e-shopy, jazyky, feedy) | M | 8 (katalóg) |
| **12** | Prenájom | L | 6, 9 (kusy, majetok) |
| **13** | Automatizácie; mobil (skenovanie, štítky, PWA) — základ QR je už vo fáze 1 | M | 1 + moduly |
| **14** | Živé spoločné písanie, verejná rezervácia na webe, rozšírené vyhľadávanie; vybrané položky zo zásobníka (kap. 10) | M | 4, 12 |

Každá fáza: návrh obrazoviek na odsúhlasenie → kód s testami (aj testy práv) → **návody v Pomocníkovi** → overenie
na dev → nasadenie → technická dokumentácia tu a v HANDOVER. Nové zákonné pravidlá → docs/legislativa.md.

## 9. Rozhodnutia a otvorené otázky

**Rozhodnuté (majiteľ 21. 11. 2026):**
- **Veľké súbory v Nextcloude** na domácom serveri (`home.ermagroup.net`), nie na hostingu (4.1). Domáca linka má
  **upload 150 Mbit/s** (dá sa navýšiť) — stačí (súbor 100 MB sa zákazníkovi stiahne za pár sekúnd).
- **Office v Nextcloude nie je nutný** — panel naň len odkazuje, ak tam je; poznámky a texty sú v Dokumentoch panela
  (6.2). Či beží, sa zistí pri fáze 1 (Nextcloud → Aplikácie → „Nextcloud Office“).
- **Kópia mimo domu: zatiaľ nie — TODO, keď firma narastie** (majiteľ 21. 11. 2026). Do vtedy sú súbory len na RAID
  doma (chráni pred poruchou disku, nie pred požiarom, krádežou ani omylom zmazaním). Zapíše sa do registra
  povinností (6.15) ako pripomienka.
- Pridať moduly **Pošta, Platby, Povinnosti a termíny, Obsah produktov**; ostatné nápady do zásobníka (kap. 10);
  AI asistent čaká na rozhodnutie o údajoch zákazníkov.
- Projekty a úlohy **patria firme**.
- Úlohy a projekty budú používať **aj ľudia zvonka** (len pozvané projekty; práva s tým rátajú od začiatku).
- V-model = **interná disciplína**, bez formálnych podpisov a schvaľovania podľa normy (dá sa doplniť neskôr).
- Dokumenty: najprv **„jeden píše, ostatní vidia“**, živé písanie neskôr.
- Do plánu patrí aj: súčiastky a BOM, nákup, výroba, sériové čísla, firmvér, reklamácie a servis, ponuky a zákazky,
  výkazy práce, prenájom, majetok a kalibrácie, automatizácie, mobil so skenovaním („pridaj to do plánu a premysli to“).

**Otvorené** (netreba hneď — každá otázka sa týka až svojej fázy):
1. **V-model — aké projekty a úrovne?** Elektronika + firmvér (LedBoard, StadiumControl …)? (fáza 5)
2. **Výsledky testov odkiaľ** — testovacie prípravky (aký výstup?), CI (GitHub Actions …), ručne? (fázy 5, 9)
3. **Nextcloud** (4.1): rádovo koľko dát (GB / TB) a aké najväčšie súbory? (fáza 1)
4. **Roly**: sedí zoznam v kapitole 5? Kto dnes v paneli pracuje a čo má robiť? (fáza 1)
5. **Poradie fáz 2–14**: čo firma potrebuje najskôr — úlohy, ponuky a reklamácie, alebo súčiastky a výroba?
6. **Výroba**: vyrábate sami, alebo dávate osádzať DPS (napr. JLCPCB)? Aké množstvá? Koľko produktov a revízií? (fázy 8–9)
7. **Distribútori súčiastok**: od koho nakupujete (TME, Mouser, DigiKey, LCSC …)? (fáza 8)
8. **Prenájom**: čo by sa prenajímalo (zariadenia, technika na podujatia …) a komu — firmám, ľuďom? (fáza 12)
9. **Git**: kde sú repozitáre — GitHub, GitLab, vlastné na domácom serveri, len lokálne? Pracujete s vetvami a pull
   requestmi? Má commit bez čísla úlohy prejsť, alebo ho odmietnuť? (fáza 2)
10. **3D tlač a gravírovanie**: aké tlačiarne a laser (výrobca / typ, softvér — PrusaSlicer, Bambu Studio, LightBurn …)?
    Ako dnes prichádzajú objednávky a podklady — e-shop (s nahratím súboru?), e-mail, osobne? Aké veľké súbory? (fáza 6)
11. **Pomocník**: stačia návody v paneli, alebo treba aj tlačenú / PDF príručku? Kto ich bude čítať (zamestnanci,
    brigádnici …)? (fáza 0)
12. **Pošta**: ktoré schránky (info@, objednávky …) a kto ich má vidieť? (fáza 7)
13. **Platby**: platby dodávateľom cez Fio chceš (príkazy aj tak potvrdzuješ v banke)? Platobný odkaz GoPay pri faktúrach
    aj pre zákazníkov mimo e-shopu? (fáza 7)
14. **Obsah produktov**: v akých jazykoch predávate a robí dnes feedy pre Heureku / Google modul v e-shope? (fáza 11)

## 10. Zásobník nápadov (nezaradené — rozhodne majiteľ)

- **AI asistent (Claude)** — čítanie prijatých faktúr z PDF / fotky (vyplní dodávateľa, sumy, DPH, VS, splatnosť),
  návrhy odpovedí zákazníkom, otázky na dáta v rozsahu práv („koľko LedBoardov sme predali v októbri“), zhrnutia
  zákaziek a reklamácií, texty a preklady produktov. **Čaká na rozhodnutie**, či smú údaje zákazníkov ísť do externej
  služby; platí sa za použitie.
- **Zákaznícky portál** — zákazník na jednom mieste: zákazky, podklady, schvaľovanie náhľadov, reklamácie, faktúry,
  prenájmy (rozšírenie bezpečných odkazov z 6.12).
- **Doprava pre všetko** — Packeta, Slovenská pošta, kuriér: štítky a sledovanie aj pre zákazky, reklamácie a prenájom;
  stav objednávky sa posunie podľa sledovania.
- **Žiadosť o recenziu** po doručení (Heureka, Google).
- **Výrobné podklady z KiCadu** — BOM + súradnice súčiastok (CPL) pre osadzovanie (napr. JLCPCB) z revízie produktu.
- **Technická dokumentácia pre CE** pri každej revízii — vyhlásenie o zhode, RoHS / REACH od dodávateľov súčiastok,
  výsledky testov pokope.
- **Cievky struny** — QR na cievke, zostatok v gramoch; fotka hotového dielu automaticky k zákazke.
- **Dohľad nad službami** — bežia e-shopy, hbkpravotice, aqua, zariadenia; koniec SSL a domén, miesto na hostingu,
  prebehla záloha — upozornenie skôr, než si to všimne zákazník.
